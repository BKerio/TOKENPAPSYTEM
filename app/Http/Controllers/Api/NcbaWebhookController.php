<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\TokenTransaction;
use App\Services\PaymentSmsService;
use App\Services\PrismTokenService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NcbaWebhookController extends Controller
{
    protected PrismTokenService $prismTokenService;
    protected PaymentSmsService $paymentSmsService;
    protected SmsService $smsService;

    public function __construct(
        PrismTokenService $prismTokenService,
        PaymentSmsService $paymentSmsService,
        SmsService $smsService
    ) {
        $this->prismTokenService = $prismTokenService;
        $this->paymentSmsService = $paymentSmsService;
        $this->smsService = $smsService;
    }

    /**
     * Handle NCBA Paybill webhook notification.
     *
     * Expected payload:
     * {
     *   "TransType": "Pay Bill",
     *   "TransID": "UDR7J243QA",
     *   "FTRef": "",
     *   "TransTime": "20260427103619",
     *   "TransAmount": "40.0",
     *   "BusinessShortCode": "880100",
     *   "BillRefNumber": "218262",
     *   "Narrative": "029350113064",
     *   "Mobile": "254722127450",
     *   "name": "SAYED KOMAIL",
     *   "Username": "millicom",
     *   "Password": "RcD1@621822",
     *   "Hash": "2Y1zsqD1KzsqIHkm"
     * }
     */
    public function handle(Request $request)
    {
        Log::info('NCBA Webhook received', [
            'ip'      => $request->ip(),
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
        ]);

        $data = $request->all();

        // ---------------------------------------------------------------
        // 1. Authenticate the payload credentials
        // ---------------------------------------------------------------
        $expectedUsername = config('services.ncba.username');
        $expectedPassword = config('services.ncba.password');
        $expectedHash     = config('services.ncba.hash');

        $incomingUsername = $data['Username'] ?? null;
        $incomingPassword = $data['Password'] ?? null;
        $incomingHash     = $data['Hash'] ?? null;

        if (
            $incomingUsername !== $expectedUsername ||
            $incomingPassword !== $expectedPassword ||
            $incomingHash     !== $expectedHash
        ) {
            Log::warning('NCBA Webhook authentication failed', [
                'username_match' => $incomingUsername === $expectedUsername,
                'ip'             => $request->ip(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }

        // ---------------------------------------------------------------
        // 2. Extract & validate payload fields
        // ---------------------------------------------------------------
        $transType        = $data['TransType']        ?? null;
        $transID          = $data['TransID']          ?? null;
        $transTime        = $data['TransTime']        ?? null;   // e.g. "20260427103619"
        $transAmount      = $data['TransAmount']      ?? null;
        $businessShortCode= $data['BusinessShortCode']?? null;
        $billRefNumber    = $data['BillRefNumber']    ?? null;   // meter number
        $narrative        = $data['Narrative']        ?? null;   // alternative meter ref
        $mobile           = $data['Mobile']           ?? null;   // 254xxxxxxxxx
        $customerName     = $data['name']             ?? 'Customer';

        if (empty($transID) || empty($transAmount) || empty($mobile)) {
            Log::warning('NCBA Webhook missing required fields', ['data' => $data]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing required fields',
            ], 422);
        }

        $amount = (float) $transAmount;
        if ($amount <= 0) {
            Log::warning('NCBA Webhook invalid amount', ['TransAmount' => $transAmount]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid transaction amount',
            ], 422);
        }

        // ---------------------------------------------------------------
        // 3. Duplicate transaction guard (check TransID)
        // ---------------------------------------------------------------
        $existing = Payment::where('mpesa_receipt_number', $transID)->first();
        if ($existing) {
            Log::info('NCBA Webhook duplicate TransID ignored', ['TransID' => $transID]);
            return response()->json(['status' => 'duplicate_ignored']);
        }

        // ---------------------------------------------------------------
        // 4. Resolve meter: try BillRefNumber first, then Narrative
        // ---------------------------------------------------------------
        $meter = null;
        $meterRef = null;

        if (!empty($billRefNumber)) {
            $meter    = Meter::where('meter_number', $billRefNumber)->first();
            $meterRef = $billRefNumber;
        }

        if (!$meter && !empty($narrative)) {
            $meter    = Meter::where('meter_number', $narrative)->first();
            $meterRef = $narrative;
        }

        // ---------------------------------------------------------------
        // 5. Store the payment transaction
        // ---------------------------------------------------------------
        try {
            $payment = Payment::create([
                'merchant_request_id'  => null,
                'checkout_request_id'  => 'NCBA-' . $transID,
                'account_reference'    => $meterRef ?? $billRefNumber,
                'phone'                => (string) $mobile,
                'amount'               => $amount,
                'mpesa_receipt_number' => $transID,
                'result_code'          => '0',
                'result_desc'          => 'NCBA Paybill Payment',
                'status'               => 'confirmed',
            ]);

            Log::info('NCBA Payment stored', [
                'payment_id' => $payment->id,
                'TransID'    => $transID,
                'amount'     => $amount,
                'meter_ref'  => $meterRef,
            ]);
        } catch (\Throwable $e) {
            Log::error('NCBA: Failed to store payment', [
                'TransID' => $transID,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to store transaction',
            ], 500);
        }

        // ---------------------------------------------------------------
        // 6. Issue tokens & send SMS
        // ---------------------------------------------------------------
        if ($meter) {
            try {
                Log::info('NCBA: Vending token', [
                    'meter_id' => $meter->id,
                    'amount'   => $amount,
                ]);

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
                    'meter_id'    => $meter->id,
                    'vendor_id'   => $meter->vendor_id ?? null,
                    'customer_id' => $meter->customers()->first()->id ?? null,
                    'payment_id'  => $payment->id,
                    'amount'      => $amount,
                    'tokens'      => $tokenStrings,
                    'status'      => 'success',
                    'description' => 'NCBA Paybill payment generated ' . count($tokenStrings) . ' token(s).',
                ]);

                // Send NCBA-formatted SMS
                $this->sendNcbaSms(
                    phone:         (string) $mobile,
                    customerName:  $customerName,
                    amount:        $amount,
                    billRefNumber: $billRefNumber,
                    narrative:     $narrative,
                    transTime:     $transTime,
                    transID:       $transID,
                    tokenStrings:  $tokenStrings,
                    meter:         $meter
                );

            } catch (\Throwable $e) {
                Log::error('NCBA: Token generation failed', [
                    'payment_id' => $payment->id,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]);

                // Record failed vending
                TokenTransaction::create([
                    'meter_id'    => $meter->id,
                    'vendor_id'   => $meter->vendor_id ?? null,
                    'amount'      => $amount,
                    'status'      => 'failed',
                    'description' => 'NCBA Prism Error: ' . $e->getMessage(),
                ]);

                // Fallback: send basic confirmation SMS
                $this->sendNcbaFallbackSms(
                    phone:         (string) $mobile,
                    customerName:  $customerName,
                    amount:        $amount,
                    billRefNumber: $billRefNumber,
                    narrative:     $narrative,
                    transTime:     $transTime,
                    transID:       $transID,
                    meter:         $meter
                );
            }
        } else {
            Log::warning('NCBA: Meter not found for payment', [
                'BillRefNumber' => $billRefNumber,
                'Narrative'     => $narrative,
                'payment_id'    => $payment->id,
            ]);

            // Meter not found – send a basic payment receipt SMS
            $this->sendNcbaFallbackSms(
                phone:         (string) $mobile,
                customerName:  $customerName,
                amount:        $amount,
                billRefNumber: $billRefNumber,
                narrative:     $narrative,
                transTime:     $transTime,
                transID:       $transID,
                meter:         null
            );
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Payment processed',
        ]);
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    /**
     * Send the NCBA-branded SMS with token(s) to the customer.
     * Includes the confirmation line matching the NCBA SMS format:
     * "Dear {name} your transaction of KES {amount} to MILLICOM TECHNOLOGIES
     *  {BillRefNumber} {Narrative} was successful on {date}. M-Pesa Ref: {TransID}.
     *  NCBA, Go for it."
     */
    private function sendNcbaSms(
        string  $phone,
        string  $customerName,
        float   $amount,
        ?string $billRefNumber,
        ?string $narrative,
        ?string $transTime,
        string  $transID,
        array   $tokenStrings,
        Meter   $meter
    ): void {
        try {
            $formattedDate  = $this->formatTransTime($transTime);
            $formattedAmount = number_format($amount, 2);

            // Build NCBA confirmation line
            $ncbaLine = "Dear {$customerName} your transaction of KES {$formattedAmount} to MILLICOM TECHNOLOGIES"
                      . ($billRefNumber ? " {$billRefNumber}" : '')
                      . ($narrative     ? " {$narrative}"     : '')
                      . " was successful on {$formattedDate}. M-Pesa Ref: {$transID}. NCBA, Go for it.";

            // Build token block
            $tokenBlock = '';
            foreach ($tokenStrings as $token) {
                $formatted    = trim(chunk_split($token, 4, '-'), '-');
                $tokenBlock  .= "\nToken: {$formatted}";
            }

            $price = ($meter->price_per_unit && $meter->price_per_unit > 0) ? $meter->price_per_unit : 1;
            $units = number_format($amount / $price, 1);

            $message = $ncbaLine
                     . "\n\nMeter: {$meter->meter_number}"
                     . $tokenBlock
                     . "\nUnits: {$units}"
                     . "\nAmt: KES {$formattedAmount}"
                     . "\n\nFor details dial *367*878#";

            // Use vendor SMS config if available
            $vendorConfig = null;
            if ($meter->vendor) {
                $vendorConfig = $meter->vendor->smsConfig
                    ? $meter->vendor->smsConfig->toArray()
                    : ($meter->vendor->sms_config ?: null);
            }

            $success = $this->smsService->sendSms($phone, $message, $vendorConfig);

            Log::info('NCBA Token SMS ' . ($success ? 'sent' : 'FAILED'), [
                'phone'   => $phone,
                'transID' => $transID,
            ]);
        } catch (\Throwable $e) {
            Log::error('NCBA: sendNcbaSms error: ' . $e->getMessage());
        }
    }

    /**
     * Fallback SMS when token vending fails or meter is not found.
     */
    private function sendNcbaFallbackSms(
        string  $phone,
        string  $customerName,
        float   $amount,
        ?string $billRefNumber,
        ?string $narrative,
        ?string $transTime,
        string  $transID,
        ?Meter  $meter
    ): void {
        try {
            $formattedDate   = $this->formatTransTime($transTime);
            $formattedAmount = number_format($amount, 2);

            $message = "Dear {$customerName} your transaction of KES {$formattedAmount} to MILLICOM TECHNOLOGIES"
                     . ($billRefNumber ? " {$billRefNumber}" : '')
                     . ($narrative     ? " {$narrative}"     : '')
                     . " was successful on {$formattedDate}. M-Pesa Ref: {$transID}. NCBA, Go for it."
                     . "\n\nYour token will be sent shortly. For help call support.";

            $vendorConfig = null;
            if ($meter && $meter->vendor) {
                $vendorConfig = $meter->vendor->smsConfig
                    ? $meter->vendor->smsConfig->toArray()
                    : ($meter->vendor->sms_config ?: null);
            }

            $this->smsService->sendSms($phone, $message, $vendorConfig);

            Log::info('NCBA Fallback SMS sent', [
                'phone'   => $phone,
                'transID' => $transID,
            ]);
        } catch (\Throwable $e) {
            Log::error('NCBA: sendNcbaFallbackSms error: ' . $e->getMessage());
        }
    }

    /**
     * Parse NCBA TransTime format "20260427103619" → "27/04/2026 10:36 AM"
     */
    private function formatTransTime(?string $transTime): string
    {
        if (!$transTime || strlen($transTime) < 14) {
            return now()->format('d/m/Y h:i A');
        }

        try {
            $dt = \DateTime::createFromFormat('YmdHis', $transTime);
            return $dt ? $dt->format('d/m/Y h:i A') : now()->format('d/m/Y h:i A');
        } catch (\Throwable) {
            return now()->format('d/m/Y h:i A');
        }
    }
}
