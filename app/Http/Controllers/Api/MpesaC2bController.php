<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NcbaPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaC2bController extends Controller
{
    public function __construct(
        protected NcbaPaymentService $paybillService
    ) {
    }

    /**
     * Safaricom C2B validation URL — accept all paybill payments for token vending.
     */
    public function validation(Request $request)
    {
        $data = $request->all();

        Log::info('M-Pesa C2B validation received', [
            'ip'      => $request->ip(),
            'payload' => $data,
        ]);

        return response()->json([
            'ResultCode' => '0',
            'ResultDesc' => 'Accepted',
        ]);
    }

    /**
     * Safaricom C2B confirmation URL — auto-vend token + SMS on paybill payment.
     */
    public function confirm(Request $request)
    {
        $data = $request->all();

        Log::info('M-Pesa C2B confirmation received', [
            'ip'      => $request->ip(),
            'payload' => $data,
        ]);

        $transID = $data['TransID'] ?? null;
        $amount  = $data['TransAmount'] ?? null;
        $mobile  = $data['MSISDN'] ?? $data['Mobile'] ?? null;

        if (!$transID || !$amount || !$mobile) {
            Log::warning('M-Pesa C2B confirmation missing required fields', ['data' => $data]);

            return response()->json([
                'ResultCode' => 'C2B00016',
                'ResultDesc' => 'Missing required fields',
            ]);
        }

        if ($this->paybillService->isDuplicate($transID)) {
            Log::info('M-Pesa C2B duplicate TransID ignored', ['TransID' => $transID]);

            return response()->json([
                'ResultCode' => '0',
                'ResultDesc' => 'Duplicate ignored',
            ]);
        }

        try {
            $normalized = $this->paybillService->normalizeC2bPayload($data);
            $result = $this->paybillService->processPayment($normalized, 'C2B');

            Log::info('M-Pesa C2B payment processed', $result);
        } catch (\Throwable $e) {
            Log::error('M-Pesa C2B processing failed', [
                'TransID' => $transID,
                'error'   => $e->getMessage(),
            ]);
        }

        return response()->json([
            'ResultCode' => '0',
            'ResultDesc' => 'Success',
        ]);
    }
}
