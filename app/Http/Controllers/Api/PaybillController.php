<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\TokenTransaction;
use App\Services\NcbaPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaybillController extends Controller
{
    public function __construct(
        protected NcbaPaymentService $ncbaPaymentService
    ) {
    }

    /**
     * Claim a manual NCBA paybill payment using the M-Pesa receipt code.
     * Used when NCBA/Millicom has not yet forwarded the webhook to TokenPap.
     */
    public function claim(Request $request)
    {
        $validated = $request->validate([
            'mpesa_receipt' => 'required|string|min:8|max:20',
            'phone'         => 'required|string',
            'amount'        => 'required|numeric|min:1',
            'meter_number'  => 'required|string|min:5',
        ]);

        $receipt = strtoupper(trim($validated['mpesa_receipt']));
        $phone   = $this->normalizePhone($validated['phone']);
        $amount  = (float) $validated['amount'];
        $meter   = Meter::where('meter_number', trim($validated['meter_number']))->first();

        if (!$meter) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Meter not found. Check the meter number.',
            ], 404);
        }

        $till = trim((string) ($meter->vendor?->account_id ?? ''));
        if ($till === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Vendor till number is not configured for this meter.',
            ], 422);
        }

        if ($this->ncbaPaymentService->isDuplicate($receipt)) {
            $payment = Payment::where('mpesa_receipt_number', $receipt)->first();
            $tokens  = [];

            if ($payment) {
                $tokenTx = TokenTransaction::where('payment_id', $payment->id)
                    ->where('status', 'success')
                    ->first();
                $tokens = $tokenTx?->tokens ?? [];
            }

            return response()->json([
                'status'          => 'duplicate',
                'message'         => 'This M-Pesa receipt was already processed.',
                'tokens'          => $tokens,
                'mpesa_receipt'   => $receipt,
            ]);
        }

        Log::info('Paybill claim requested', [
            'receipt' => $receipt,
            'phone'   => $phone,
            'amount'  => $amount,
            'meter'   => $meter->meter_number,
            'till'    => $till,
        ]);

        try {
            $result = $this->ncbaPaymentService->processPayment([
                'TransID'       => $receipt,
                'TransAmount'   => $amount,
                'BillRefNumber' => $till,
                'Narrative'     => $meter->meter_number,
                'Mobile'        => $phone,
                'name'          => 'Customer',
            ], 'PAYBILL-CLAIM');

            if (!$result['meter_found']) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Could not match payment to meter.',
                ], 422);
            }

            if (!$result['token_generated']) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $result['error'] ?? 'Token generation failed. Contact support with your M-Pesa receipt.',
                ], 500);
            }

            return response()->json([
                'status'        => 'success',
                'message'       => 'Token generated successfully.',
                'tokens'        => $result['tokens'] ?? [],
                'sms_sent'      => $result['sms_sent'] ?? false,
                'mpesa_receipt' => $receipt,
                'meter_number'  => $meter->meter_number,
            ]);
        } catch (\Throwable $e) {
            Log::error('Paybill claim failed', [
                'receipt' => $receipt,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to process receipt. Please try again or contact support.',
            ], 500);
        }
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        $phone = ltrim($phone, '+');

        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }

        return $phone;
    }
}
