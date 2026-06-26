<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\Payment;
use App\Models\TokenTransaction;
use Illuminate\Support\Facades\Log;

class NcbaPaymentService
{
    public function __construct(
        protected PrismTokenService $prismTokenService,
        protected PaymentSmsService $paymentSmsService
    ) {
    }

    /**
     * Process a paybill payment: store record, vend token, send SMS.
     */
    public function processPayment(array $data, string $channel = 'NCBA'): array
    {
        $transID     = $data['TransID'];
        $transAmount = (float) $data['TransAmount'];
        $billRef     = $data['BillRefNumber'] ?? null;
        $narrative   = $data['Narrative'] ?? null;
        $mobile      = (string) ($data['Mobile'] ?? $data['MSISDN'] ?? '');
        $customerName = $data['name'] ?? $data['FirstName'] ?? 'Customer';

        $resolved = $this->resolveMeterFromAccountRef($billRef, $narrative);
        $meter    = $resolved['meter'];
        $meterRef = $resolved['accountRef'];

        Log::info("{$channel}: Resolved account reference", [
            'bill_ref'     => $billRef,
            'narrative'    => $narrative,
            'till_number'  => $resolved['tillNumber'],
            'meter_number' => $resolved['meterNumber'],
            'account_ref'  => $meterRef,
            'meter_found'  => (bool) $meter,
        ]);

        $payment = Payment::create([
            'merchant_request_id'  => null,
            'checkout_request_id'  => $channel . '-' . $transID,
            'account_reference'    => $meterRef ?? $billRef,
            'phone'                => $mobile,
            'amount'               => $transAmount,
            'mpesa_receipt_number' => $transID,
            'result_code'          => '0',
            'result_desc'          => $channel . ' Paybill Payment',
            'status'               => 'confirmed',
        ]);

        $result = [
            'payment_id' => $payment->id,
            'meter_found' => (bool) $meter,
            'token_generated' => false,
            'sms_sent' => false,
        ];

        if (!$meter) {
            Log::warning('NCBA: Meter not found for payment', [
                'BillRefNumber' => $billRef,
                'Narrative'     => $narrative,
                'payment_id'    => $payment->id,
            ]);

            $result['sms_sent'] = $this->paymentSmsService->sendPaymentConfirmation($payment);
            return $result;
        }

        try {
            Log::info("{$channel}: Vending token", [
                'meter_id' => $meter->id,
                'amount'   => $transAmount,
                'phone'    => $mobile,
            ]);

            $generatedTokens = $this->prismTokenService->issueCreditToken($meter, $transAmount);
            $tokenStrings = $this->extractTokenStrings($generatedTokens);

            TokenTransaction::create([
                'meter_id'    => $meter->id,
                'vendor_id'   => $meter->vendor_id ?? null,
                'customer_id' => $meter->customers()->first()->id ?? null,
                'payment_id'  => $payment->id,
                'amount'      => $transAmount,
                'tokens'      => $tokenStrings,
                'status'      => 'success',
                'description' => $channel . ' Paybill payment generated ' . count($tokenStrings) . ' token(s).',
            ]);

            $result['token_generated'] = true;
            $result['tokens'] = $tokenStrings;
            $result['sms_sent'] = $this->paymentSmsService->sendTokenMessage($payment, $meter, $tokenStrings);

            Log::info("{$channel}: Token SMS " . ($result['sms_sent'] ? 'sent' : 'FAILED'), [
                'phone'      => $mobile,
                'transID'    => $transID,
                'payment_id' => $payment->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('NCBA: Token generation failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);

            TokenTransaction::create([
                'meter_id'    => $meter->id,
                'vendor_id'   => $meter->vendor_id ?? null,
                'amount'      => $transAmount,
                'status'      => 'failed',
                'description' => 'NCBA Prism Error: ' . $e->getMessage(),
            ]);

            $result['error'] = $e->getMessage();
            $result['sms_sent'] = $this->paymentSmsService->sendPaymentConfirmation($payment);
        } finally {
            $this->prismTokenService->disconnect();
        }

        return $result;
    }

    public function normalizeC2bPayload(array $data): array
    {
        $billRef = $data['BillRefNumber'] ?? null;
        $narrative = null;

        if ($billRef && str_contains($billRef, '#')) {
            [$till, $meter] = array_pad(explode('#', $billRef, 2), 2, null);
            $billRef = trim((string) $till);
            $narrative = trim((string) $meter);
        }

        return [
            'TransID'        => $data['TransID'] ?? null,
            'TransAmount'    => $data['TransAmount'] ?? null,
            'BillRefNumber'  => $billRef,
            'Narrative'      => $narrative,
            'Mobile'         => $data['MSISDN'] ?? $data['Mobile'] ?? null,
            'FirstName'      => $data['FirstName'] ?? 'Customer',
            'BusinessShortCode' => $data['BusinessShortCode'] ?? null,
        ];
    }

    public function isDuplicate(string $transID): bool
    {
        return Payment::where('mpesa_receipt_number', $transID)->exists();
    }

    public function resolveMeterFromAccountRef(?string $billRefNumber, ?string $narrative): array
    {
        $billRefNumber = $billRefNumber ? trim($billRefNumber) : null;
        $narrative     = $narrative ? trim($narrative) : null;

        $tillNumber  = null;
        $meterNumber = null;

        foreach ([$billRefNumber, $narrative] as $ref) {
            if ($ref && str_contains($ref, '#')) {
                [$tillNumber, $meterNumber] = array_pad(explode('#', $ref, 2), 2, null);
                $tillNumber  = $tillNumber ? trim($tillNumber) : null;
                $meterNumber = $meterNumber ? trim($meterNumber) : null;
                break;
            }
        }

        if (!$meterNumber && $billRefNumber && $narrative) {
            $tillNumber  = $billRefNumber;
            $meterNumber = $narrative;
        } elseif (!$meterNumber && $billRefNumber) {
            $meterNumber = $billRefNumber;
        } elseif (!$meterNumber && $narrative) {
            $meterNumber = $narrative;
        }

        $meter = null;
        if ($meterNumber) {
            $meter = Meter::where('meter_number', $meterNumber)->first();

            if ($meter && $tillNumber && $meter->vendor) {
                $vendorTill = trim((string) ($meter->vendor->account_id ?? ''));
                if ($vendorTill !== '' && $vendorTill !== $tillNumber) {
                    Log::warning('NCBA: Till number does not match meter vendor', [
                        'expected_till' => $vendorTill,
                        'received_till' => $tillNumber,
                        'meter_number'  => $meterNumber,
                    ]);
                }
            }
        }

        $accountRef = ($tillNumber && $meterNumber)
            ? "{$tillNumber}#{$meterNumber}"
            : ($meterNumber ?? $billRefNumber ?? $narrative);

        return [
            'meter'       => $meter,
            'tillNumber'  => $tillNumber,
            'meterNumber' => $meterNumber,
            'accountRef'  => $accountRef,
        ];
    }

    private function extractTokenStrings(array $generatedTokens): array
    {
        $tokenStrings = [];
        foreach ($generatedTokens as $token) {
            if (isset($token->tokenDec)) {
                $tokenStrings[] = $token->tokenDec;
            } elseif (isset($token->tokenHex)) {
                $tokenStrings[] = $token->tokenHex;
            }
        }

        return $tokenStrings;
    }
}
