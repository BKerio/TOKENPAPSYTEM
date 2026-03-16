<?php

namespace App\Services;

use App\Models\SystemConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;

class MpesaService
{
    private string $baseUrl;
    private ?string $consumerKey;
    private ?string $consumerSecret;
    private ?string $shortcode;   // Paybill (for password generation)
    private ?string $tillno;      // BuyGoods Till Number
    private ?string $passkey;
    private ?string $callbackUrl;

    public function __construct()
    {
        $env = SystemConfig::getValue('mpesa_env', 'sandbox');

        $this->baseUrl = $env === 'live'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';

        $this->consumerKey    = SystemConfig::getValue('mpesa_consumer_key');
        $this->consumerSecret = SystemConfig::getValue('mpesa_consumer_secret');
        $this->shortcode      = SystemConfig::getValue('mpesa_shortcode');
        $this->tillno         = SystemConfig::getValue('mpesa_till_no');
        $this->passkey        = SystemConfig::getValue('mpesa_passkey');
        $this->callbackUrl    = SystemConfig::getValue('mpesa_callback_url');
    }

    private function getAccessToken(): ?string
    {
        $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->get($this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');

        return $response->json()['access_token'] ?? null;
    }

    public function stkPush(string $phone, float $amount, string $reference = 'Payment'): array
    {
        // Convert M-Pesa number to 2547XXXXXXXX format
        $phone = preg_replace('/^0/', '254', $phone);
        $phone = ltrim($phone, '+');

        $timestamp = now()->format('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);

        Log::info('Generating Global M-Pesa Access Token', ['baseUrl' => $this->baseUrl]);
        $responseToken = Http::withBasicAuth(trim($this->consumerKey), trim($this->consumerSecret))
            ->get($this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');

        if ($responseToken->failed()) {
            Log::error('Failed to generate Global M-Pesa Access Token', [
                'status' => $responseToken->status(),
                'response' => $responseToken->json()
            ]);
            return $responseToken->json() ?: ['errorMessage' => 'Failed to generate global access token'];
        }

        $tokenData = $responseToken->json();
        $token = $tokenData['access_token'] ?? null;

        $transactionType = SystemConfig::getValue('mpesa_transaction_type', 'CustomerBuyGoodsOnline');
        $partyB = ($transactionType === 'CustomerPayBillOnline') ? $this->shortcode : $this->tillno;

        $reference = substr($reference, 0, 12);
        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $transactionType,
            'Amount'            => (int) $amount, // Round to int for safety
            'PartyA'            => $phone,
            'PartyB'            => $partyB,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $this->callbackUrl,
            'AccountReference'  => $reference,
            'TransactionDesc'   => $reference,
        ];

        Log::info('Sending Global STK Push Process Request', ['url' => $this->baseUrl . '/mpesa/stkpush/v1/processrequest']);
        $response = Http::withToken($token)
            ->post($this->baseUrl . '/mpesa/stkpush/v1/processrequest', $payload);

        if ($response->failed()) {
            Log::error('Global M-Pesa STK Push Process Request failed', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
        }

        return $response->json() ?: ['errorMessage' => 'Empty or invalid response from M-Pesa API', 'status' => $response->status()];
    }

    /**
     * Vendor-specific STK push using per-vendor configuration.
     */
    public function stkPushWithConfig(array $config, string $phone, float $amount, string $reference = 'Payment'): array
    {
        $env = $config['env'] ?? 'sandbox';

        $baseUrl = $env === 'live'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';

        $consumerKey    = $this->decryptIfSet($config['consumer_key'] ?? null);
        $consumerSecret = $this->decryptIfSet($config['consumer_secret'] ?? null);
        $shortcode      = $config['shortcode'] ?? null;
        $tillno         = $config['till_no'] ?? null;
        $passkey        = $this->decryptIfSet($config['passkey'] ?? null);
        $callbackUrl    = $config['callback_url'] ?? null;

        // Convert M-Pesa number to 2547XXXXXXXX format
        $phone = preg_replace('/^0/', '254', $phone);
        $phone = ltrim($phone, '+');

        $timestamp = now()->format('YmdHis');
        $password  = base64_encode($shortcode . $passkey . $timestamp);

        Log::info('Generating M-Pesa Access Token', ['baseUrl' => $baseUrl]);
        $responseToken = Http::withBasicAuth(trim($consumerKey), trim($consumerSecret))
            ->get($baseUrl . '/oauth/v1/generate?grant_type=client_credentials');

        if ($responseToken->failed()) {
            Log::error('Failed to generate M-Pesa Access Token', [
                'status' => $responseToken->status(),
                'response' => $responseToken->json()
            ]);
            return $responseToken->json() ?: ['errorMessage' => 'Failed to generate access token'];
        }

        $tokenData = $responseToken->json();
        $token = $tokenData['access_token'] ?? null;

        $transactionType = $config['transaction_type'] ?? SystemConfig::getValue('mpesa_transaction_type', 'CustomerBuyGoodsOnline');
        $partyB = ($transactionType === 'CustomerPayBillOnline') ? $shortcode : $tillno;

        $reference = substr($reference, 0, 12);
        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $transactionType,
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $partyB,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => $reference,
            'TransactionDesc'   => $reference,
        ];

        Log::info('Sending STK Push Process Request', ['url' => $baseUrl . '/mpesa/stkpush/v1/processrequest']);
        $response = Http::withToken($token)
            ->post($baseUrl . '/mpesa/stkpush/v1/processrequest', $payload);

        if ($response->failed()) {
            Log::error('M-Pesa STK Push Process Request failed', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
        }

        return $response->json() ?: ['errorMessage' => 'Empty or invalid response from M-Pesa API', 'status' => $response->status()];
    }

    private function decryptIfSet(?string $value): ?string
    {
        if ($value) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($value);
            } catch (\Exception $e) {
                // Return as is if decryption fails (e.g. not encrypted yet)
                return $value;
            }
        }
        return null;
    }
}

