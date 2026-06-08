<?php

namespace App\Services;

use App\Models\SystemConfig;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected Client $httpClient;

    public function __construct(?Client $client = null)
    {
        $this->httpClient = $client ?: new Client([
            'timeout' => 15,
            'allow_redirects' => true,
        ]);
    }

    public function sendSms(string $phoneNumber, string $message, ?array $vendorConfig = null): bool
    {
        try {
            $smsEnabled = $vendorConfig['enabled'] ?? SystemConfig::getValue('sms_enabled', true);
            if ($smsEnabled === false || $smsEnabled === 'false' || $smsEnabled === '0') {
                Log::warning('SMS service is disabled');
                return false;
            }

            $msisdn = $this->normalizeMsisdn($phoneNumber);
            $config = $this->resolveConfig($vendorConfig);

            if (!$config['api_key'] || !$config['partner_id'] || !$config['shortcode'] || !$config['api_url']) {
                Log::error('SMS configuration is incomplete', [
                    'api_key_set'    => (bool) $config['api_key'],
                    'partner_id_set' => (bool) $config['partner_id'],
                    'shortcode_set'  => (bool) $config['shortcode'],
                    'api_url_set'    => (bool) $config['api_url'],
                    'provider'       => $config['provider'],
                ]);
                return false;
            }

            $payload = [
                'apikey'    => $config['api_key'],
                'partnerID' => (string) $config['partner_id'],
                'message'   => $message,
                'shortcode' => $config['shortcode'],
                'mobile'    => $msisdn,
            ];

            $requestOptions = [
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
            ];

            if ($this->usesJsonPayload($config)) {
                $requestOptions['json'] = $payload;
            } else {
                $requestOptions['form_params'] = array_merge($payload, ['msisdn' => $msisdn]);
            }

            $response = $this->httpClient->post($config['api_url'], $requestOptions);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $bodyArray = json_decode($body, true);
            $success = $this->responseIndicatesSuccess($statusCode, $bodyArray);

            Log::info('SMS provider response', [
                'status'   => $statusCode,
                'provider' => $config['provider'],
                'success'  => $success,
                'body'     => $body,
            ]);

            if (!$success) {
                Log::error('SMS provider rejected message', [
                    'status' => $statusCode,
                    'body'   => $body,
                    'phone'  => $msisdn,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::error('SMS send failed: ' . $e->getMessage());
            return false;
        }
    }

    protected function resolveConfig(?array $vendorConfig): array
    {
        $vendorConfig = $this->normalizeVendorConfig($vendorConfig);

        $provider = $vendorConfig['provider']
            ?? SystemConfig::getValue('sms_provider', 'advanta');

        $apiKey = $this->decryptIfSet($vendorConfig['api_key'] ?? null)
            ?? SystemConfig::getValue('sms_api_key');
        if (!$apiKey || str_contains((string) $apiKey, 'CHANGE_ME')) {
            $apiKey = env('ADVANTA_API_KEY') ?? env('SMS_API_KEY');
        }

        $partnerId = $vendorConfig['partner_id']
            ?? SystemConfig::getValue('sms_partner_id');
        if (!$partnerId || $partnerId === '4889') {
            $partnerId = env('ADVANTA_PARTNER_ID') ?? env('SMS_PARTNER_ID') ?? $partnerId;
        }

        $shortcode = $vendorConfig['shortcode']
            ?? SystemConfig::getValue('sms_shortcode');
        if (!$shortcode || $shortcode === 'P.C.E.A_SGM') {
            $shortcode = env('ADVANTA_SHORTCODE') ?? env('SMS_SHORTCODE') ?? $shortcode;
        }

        $apiUrl = $vendorConfig['api_url']
            ?? SystemConfig::getValue('sms_api_url');
        if (!$apiUrl || str_contains((string) $apiUrl, 'bulksms.fornax-technologies.com')) {
            $apiUrl = env('ADVANTA_SMS_URL') ?? env('SMS_API_URL') ?? $apiUrl;
        }

        if (!$apiUrl || str_contains((string) $apiUrl, 'example.com')) {
            $apiUrl = 'https://quicksms.advantasms.com/api/services/sendsms/';
        }

        $apiUrl = $this->normalizeApiUrl((string) $apiUrl);

        if ($provider === 'fornax' && str_contains($apiUrl, 'advantasms.com')) {
            $provider = 'advanta';
        }

        return [
            'provider'   => $provider,
            'api_key'    => $apiKey,
            'partner_id' => $partnerId,
            'shortcode'  => $shortcode,
            'api_url'    => $apiUrl,
        ];
    }

    protected function normalizeVendorConfig(?array $vendorConfig): array
    {
        if (!$vendorConfig) {
            return [];
        }

        // smsConfig->toArray() includes metadata fields — keep only SMS settings.
        return array_filter([
            'provider'   => $vendorConfig['provider'] ?? null,
            'api_url'    => $vendorConfig['api_url'] ?? null,
            'api_key'    => $vendorConfig['api_key'] ?? null,
            'partner_id' => $vendorConfig['partner_id'] ?? null,
            'shortcode'  => $vendorConfig['shortcode'] ?? null,
            'enabled'    => $vendorConfig['enabled'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function normalizeApiUrl(string $apiUrl): string
    {
        $apiUrl = trim($apiUrl);

        if (str_contains($apiUrl, 'advantasms.com') && !str_ends_with($apiUrl, '/')) {
            return $apiUrl . '/';
        }

        return $apiUrl;
    }

    protected function usesJsonPayload(array $config): bool
    {
        return str_contains($config['api_url'], 'advantasms.com')
            || ($config['provider'] ?? '') === 'advanta';
    }

    protected function responseIndicatesSuccess(int $statusCode, mixed $bodyArray): bool
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        if (!is_array($bodyArray)) {
            return true;
        }

        if (isset($bodyArray['success'])) {
            return (bool) $bodyArray['success'];
        }

        if (($bodyArray['status'] ?? null) === 'success') {
            return true;
        }

        if (isset($bodyArray['responses'][0]['response-code'])) {
            return (int) $bodyArray['responses'][0]['response-code'] === 200;
        }

        if (isset($bodyArray['responses'][0]['response-description'])) {
            return stripos((string) $bodyArray['responses'][0]['response-description'], 'success') !== false;
        }

        if (isset($bodyArray['response-code'])) {
            return (int) $bodyArray['response-code'] === 200;
        }

        return true;
    }

    protected function normalizeMsisdn(string $phoneNumber): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber);

        if (str_starts_with($digits, '0')) {
            return '254' . substr($digits, 1);
        }

        if (str_starts_with($digits, '254')) {
            return $digits;
        }

        if (str_starts_with($digits, '7') && strlen($digits) === 9) {
            return '254' . $digits;
        }

        return $digits;
    }

    private function decryptIfSet(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($value);
        } catch (\Exception $e) {
            return $value;
        }
    }
}
