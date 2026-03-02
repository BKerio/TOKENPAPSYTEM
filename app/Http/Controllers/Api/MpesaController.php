<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Meter;
use App\Models\TokenTransaction;
use App\Services\MpesaService;
use App\Services\PaymentSmsService;
use App\Services\PrismTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MpesaController extends Controller
{
    protected MpesaService $mpesa;
    protected PaymentSmsService $paymentSmsService;
    protected PrismTokenService $prismTokenService;

    public function __construct(MpesaService $mpesa, PaymentSmsService $paymentSmsService, PrismTokenService $prismTokenService)
    {
        $this->mpesa = $mpesa;
        $this->paymentSmsService = $paymentSmsService;
        $this->prismTokenService = $prismTokenService;
    }

    /**
     * Initiate an M-Pesa STK push.
     */
    public function stkPush(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'reference' => 'nullable|string|max:50',
        ]);

        $reference = $validated['reference'] ?? 'Payment';

        Log::info('M-Pesa STK Push initiated', [
            'phone' => $validated['phone'],
            'amount' => $validated['amount'],
            'reference' => $reference,
        ]);

        $response = $this->mpesa->stkPush($validated['phone'], (float) $validated['amount'], $reference);

        if (isset($response['errorCode']) || isset($response['errorMessage']) || (isset($response['ResponseCode']) && $response['ResponseCode'] !== '0')) {
            return response()->json([
                'status' => 'error',
                'message' => $response['errorMessage'] ?? ($response['customerMessage'] ?? 'M-Pesa API Error'),
                'response' => $response,
            ], 400);
        }

        if (isset($response['CheckoutRequestID'])) {
            $this->storeAccountReference($response['CheckoutRequestID'], $reference);
        }

        return response()->json($response);
    }

    /**
     * Handle M-Pesa STK callback.
     */
    public function callback(Request $request)
    {
        $data = $request->all();

        $body = $data['Body']['stkCallback'] ?? [];
        $checkoutRequestId = $body['CheckoutRequestID'] ?? null;
        $merchantRequestId = $body['MerchantRequestID'] ?? null;
        $resultCode = (int) ($body['ResultCode'] ?? -1);
        $resultDesc = $body['ResultDesc'] ?? null;

        Log::info('M-Pesa Callback received', [
            'checkout_request_id' => $checkoutRequestId,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'body' => $body,
        ]);

        // Only process successful transactions (ResultCode = 0)
        if ($resultCode === 0) {
            $items = $body['CallbackMetadata']['Item'] ?? [];

            $amount = 0.0;
            $phone = '';
            $mpesaReceipt = null;
            $accountReference = null;

            foreach ($items as $item) {
                switch ($item['Name'] ?? '') {
                    case 'Amount':
                        $amount = (float) ($item['Value'] ?? 0);
                        break;
                    case 'MpesaReceiptNumber':
                        $mpesaReceipt = $item['Value'] ?? null;
                        break;
                    case 'PhoneNumber':
                        $phone = (string) ($item['Value'] ?? '');
                        break;
                    case 'AccountReference':
                        $accountReference = (string) ($item['Value'] ?? '');
                        break;
                }
            }

            if (!empty($mpesaReceipt) && $amount > 0) {
                try {
                    // Avoid duplicates
                    $existingPayment = Payment::where('checkout_request_id', $checkoutRequestId)
                        ->orWhere('mpesa_receipt_number', $mpesaReceipt)
                        ->first();

                    if ($existingPayment) {
                        Log::warning('Duplicate M-Pesa transaction detected', [
                            'checkout_request_id' => $checkoutRequestId,
                            'mpesa_receipt' => $mpesaReceipt,
                            'existing_payment_id' => $existingPayment->id,
                        ]);

                        return response()->json(['status' => 'duplicate_ignored']);
                    }

                    // If account reference not present in callback, try cache
                    if (empty($accountReference)) {
                        $accountReference = $this->getAccountReference($checkoutRequestId);

                        Log::info('Retrieved account reference from cache', [
                            'checkout_request_id' => $checkoutRequestId,
                            'account_reference' => $accountReference,
                        ]);
                    }

                    DB::beginTransaction();

                    $payment = Payment::create([
                        'merchant_request_id' => $merchantRequestId,
                        'checkout_request_id' => $checkoutRequestId,
                        'account_reference' => $accountReference,
                        'phone' => $phone,
                        'amount' => $amount,
                        'mpesa_receipt_number' => $mpesaReceipt,
                        'result_code' => (string) $resultCode,
                        'result_desc' => $resultDesc,
                        'status' => 'confirmed',
                    ]);

                    DB::commit();

                    Log::info('Payment saved successfully', [
                        'payment_id' => $payment->id,
                        'checkout_request_id' => $checkoutRequestId,
                        'mpesa_receipt' => $mpesaReceipt,
                        'amount' => $amount,
                        'account_reference' => $accountReference,
                    ]);

                    // Send confirmation SMS or Tokens
                    try {
                        $meter = Meter::where('meter_number', $accountReference)->first();
                        
                        if ($meter) {
                            Log::info('Vending token for M-Pesa payment', [
                                'meter_id' => $meter->id,
                                'amount' => $amount
                            ]);

                            try {
                                $generatedTokens = $this->prismTokenService->issueCreditToken($meter, $amount);
                                
                                $tokenStrings = [];
                                foreach ($generatedTokens as $token) {
                                    if (isset($token->tokenDec)) {
                                        $tokenStrings[] = $token->tokenDec;
                                    } elseif (isset($token->tokenHex)) {
                                        $tokenStrings[] = $token->tokenHex;
                                    }
                                }

                                TokenTransaction::create([
                                    'meter_id' => $meter->id,
                                    'vendor_id' => $meter->vendor_id ?? null,
                                    'customer_id' => $meter->customers()->first()->id ?? null,
                                    'amount' => $amount,
                                    'tokens' => $tokenStrings,
                                    'status' => 'success',
                                    'description' => 'M-Pesa payment generated ' . count($tokenStrings) . ' token(s).'
                                ]);

                                $smsSent = $this->paymentSmsService->sendTokenMessage($payment, $meter, $tokenStrings);
                                
                                if ($smsSent) {
                                    Log::info('Token SMS sent successfully via M-Pesa flow', ['payment_id' => $payment->id]);
                                }

                            } catch (\Exception $e) {
                                Log::error('Token generation failed in M-Pesa callback: ' . $e->getMessage(), [
                                    'payment_id' => $payment->id,
                                    'meter_id' => $meter->id
                                ]);
                                
                                TokenTransaction::create([
                                    'meter_id' => $meter->id,
                                    'vendor_id' => $meter->vendor_id ?? null,
                                    'amount' => $amount,
                                    'status' => 'failed',
                                    'description' => 'Prism/System Error: ' . $e->getMessage()
                                ]);

                                // Fallback to basic payment confirmation if vending fails
                                $this->paymentSmsService->sendPaymentConfirmation($payment);
                            }
                        } else {
                            // Basic payment confirmation if meter is not found
                            $smsSent = $this->paymentSmsService->sendPaymentConfirmation($payment);

                            if ($smsSent) {
                                Log::info('Payment confirmation SMS sent successfully', [
                                    'payment_id' => $payment->id,
                                ]);
                            } else {
                                Log::warning('Payment confirmation SMS failed to send', [
                                    'payment_id' => $payment->id,
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::error('Failed to process post-payment tasks', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    DB::rollBack();

                    Log::error('Failed to persist successful STK callback', [
                        'checkout_request_id' => $checkoutRequestId,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::warning('M-Pesa callback successful but missing receipt or amount', [
                    'checkout_request_id' => $checkoutRequestId,
                    'mpesa_receipt' => $mpesaReceipt,
                    'amount' => $amount,
                ]);
            }
        } else {
            // Handle failed transactions
            $failureReason = match ($resultCode) {
                1 => 'User cancelled the transaction',
                1032 => 'User cancelled the transaction',
                2001 => 'Wrong PIN entered',
                2002 => 'Insufficient funds',
                2003 => 'Transaction failed',
                default => 'Transaction failed with code: ' . $resultCode,
            };

            Log::info('M-Pesa transaction failed', [
                'checkout_request_id' => $checkoutRequestId,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'failure_reason' => $failureReason,
            ]);

            try {
                $accountReference = $this->getAccountReference($checkoutRequestId);

                Payment::updateOrCreate(
                    ['checkout_request_id' => $checkoutRequestId],
                    [
                        'merchant_request_id' => $merchantRequestId,
                        'account_reference' => $accountReference,
                        'phone' => '',
                        'amount' => 0,
                        'result_code' => (string) $resultCode,
                        'result_desc' => $resultDesc ?: $failureReason,
                        'status' => 'failed',
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('Failed to persist failed STK callback', [
                    'checkout_request_id' => $checkoutRequestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Success',
        ]);
    }

    /**
     * Store account reference temporarily for callback retrieval.
     */
    protected function storeAccountReference(string $checkoutRequestId, ?string $accountReference): void
    {
        try {
            if (!$accountReference) {
                return;
            }

            Cache::put(
                "mpesa_account_ref_{$checkoutRequestId}",
                $accountReference,
                now()->addHours(24)
            );

            Log::info('Account reference stored', [
                'checkout_request_id' => $checkoutRequestId,
                'account_reference' => $accountReference,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to store account reference', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retrieve account reference from temporary storage.
     */
    protected function getAccountReference(?string $checkoutRequestId): ?string
    {
        if (!$checkoutRequestId) {
            return null;
        }

        try {
            return Cache::get("mpesa_account_ref_{$checkoutRequestId}");
        } catch (\Throwable $e) {
            Log::error('Failed to retrieve account reference', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

