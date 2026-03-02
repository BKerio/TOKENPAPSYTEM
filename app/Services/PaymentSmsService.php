<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class PaymentSmsService
{
    protected SmsService $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Send a simple payment confirmation SMS.
     */
    public function sendPaymentConfirmation(Payment $payment): bool
    {
        try {
            $phoneNumber = $this->normalizePhoneNumber($payment->phone);

            if (!$phoneNumber) {
                Log::warning('Cannot send payment SMS: invalid phone number', [
                    'payment_id' => $payment->id ?? null,
                    'phone' => $payment->phone,
                ]);

                return false;
            }

            $message = $this->generatePaymentMessage($payment);

            $success = $this->smsService->sendSms($phoneNumber, $message);

            if ($success) {
                Log::info('Payment confirmation SMS sent successfully', [
                    'payment_id' => $payment->id ?? null,
                    'phone' => $phoneNumber,
                    'amount' => $payment->amount,
                ]);
            } else {
                Log::error('Failed to send payment confirmation SMS', [
                    'payment_id' => $payment->id ?? null,
                    'phone' => $phoneNumber,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::error('Payment SMS service error: ' . $e->getMessage(), [
                'payment_id' => $payment->id ?? null,
            ]);

            return false;
        }
    }

    /**
     * Send an SMS with generated tokens to the customer.
     */
    public function sendTokenMessage(Payment $payment, \App\Models\Meter $meter, array $tokens): bool
    {
        try {
            $phoneNumber = $this->normalizePhoneNumber($payment->phone);

            if (!$phoneNumber) {
                Log::warning('Cannot send token SMS: invalid phone number', [
                    'payment_id' => $payment->id ?? null,
                    'phone' => $payment->phone,
                ]);
                return false;
            }

            $message = $this->generateTokenMessage($payment, $meter, $tokens);
            $success = $this->smsService->sendSms($phoneNumber, $message);

            if ($success) {
                Log::info('Token SMS sent successfully', [
                    'payment_id' => $payment->id ?? null,
                    'phone' => $phoneNumber,
                ]);
            } else {
                Log::error('Failed to send token SMS', [
                    'payment_id' => $payment->id ?? null,
                    'phone' => $phoneNumber,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::error('Token SMS service error: ' . $e->getMessage(), [
                'payment_id' => $payment->id ?? null,
            ]);
            return false;
        }
    }

    /**
     * Build a generic payment confirmation message.
     */
    protected function generatePaymentMessage(Payment $payment): string
    {
        $amount = number_format((float) $payment->amount, 2);
        $receiptNumber = $payment->mpesa_receipt_number;
        $date = optional($payment->created_at)->format('d/m/Y H:i');
        $reference = $payment->account_reference ?: 'Payment';

        $message = "PAYMENT CONFIRMATION\n\n";
        $message .= "We have received your M-Pesa payment.\n\n";
        $message .= "AMOUNT: KES {$amount}\n";
        if ($reference) {
            $message .= "REFERENCE: {$reference}\n";
        }
        if ($receiptNumber) {
            $message .= "M-PESA RECEIPT: {$receiptNumber}\n";
        }
        if ($date) {
            $message .= "DATE: {$date}\n";
        }
        $message .= "\nThank you for your payment.";

        return $message;
    }

    /**
     * Build the token delivery SMS message.
     */
    protected function generateTokenMessage(Payment $payment, \App\Models\Meter $meter, array $tokens): string
    {
        $amount = number_format((float) $payment->amount, 2);
        
        $price = $meter->price_per_unit && $meter->price_per_unit > 0 ? $meter->price_per_unit : 1;
        $units = number_format($payment->amount / $price, 1);

        $message = "TOKEN PURCHASE SUCCESSFUL\n\n";
        $message .= "Meter: {$meter->meter_number}\n";
        $message .= "Amount: KES {$amount}\n";
        $message .= "Units: {$units} kWh\n\n";
        
        $message .= "TOKEN(S):\n";
        foreach ($tokens as $token) {
            // Format token grouping by 4 digits for readability
            $formattedToken = trim(chunk_split($token, 4, '-'), '-');
            $message .= "{$formattedToken}\n";
        }
        
        $message .= "\nThank you.";

        return $message;
    }

    /**
     * Normalize phone number for SMS.
     */
    protected function normalizePhoneNumber(string $phoneNumber): ?string
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

        return null;
    }
}

