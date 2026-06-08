<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NcbaPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NcbaWebhookController extends Controller
{
    public function __construct(
        protected NcbaPaymentService $ncbaPaymentService
    ) {
    }

    /**
     * Handle NCBA Paybill webhook notification.
     *
     * NCBA must POST to: {APP_URL}/api/notifications/ncba
     *
     * Expected payload:
     * {
     *   "TransType": "Pay Bill",
     *   "TransID": "UDR7J243QA",
     *   "TransAmount": "40.0",
     *   "BusinessShortCode": "880100",
     *   "BillRefNumber": "56TYR56",
     *   "Narrative": "47500162848",
     *   "Mobile": "254722127450",
     *   "name": "SAYED KOMAIL",
     *   "Username": "millicom",
     *   "Password": "...",
     *   "Hash": "..."
     * }
     *
     * Account format: TillNumber#MeterNumber
     * NCBA often sends till in BillRefNumber and meter in Narrative.
     */
    public function handle(Request $request)
    {
        Log::info('NCBA Webhook received', [
            'ip'      => $request->ip(),
            'payload' => $request->all(),
        ]);

        $data = $request->all();

        $expectedUsername = config('services.ncba.username');
        $expectedPassword = config('services.ncba.password');
        $expectedHash     = config('services.ncba.hash');

        if (
            ($data['Username'] ?? null) !== $expectedUsername ||
            ($data['Password'] ?? null) !== $expectedPassword ||
            ($data['Hash'] ?? null)     !== $expectedHash
        ) {
            Log::warning('NCBA Webhook authentication failed', ['ip' => $request->ip()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }

        $transID     = $data['TransID'] ?? null;
        $transAmount = $data['TransAmount'] ?? null;
        $mobile      = $data['Mobile'] ?? null;

        if (empty($transID) || empty($transAmount) || empty($mobile)) {
            Log::warning('NCBA Webhook missing required fields', ['data' => $data]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Missing required fields',
            ], 422);
        }

        if ((float) $transAmount <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid transaction amount',
            ], 422);
        }

        if ($this->ncbaPaymentService->isDuplicate($transID)) {
            Log::info('NCBA Webhook duplicate TransID ignored', ['TransID' => $transID]);

            return response()->json(['status' => 'duplicate_ignored']);
        }

        try {
            $result = $this->ncbaPaymentService->processPayment($data);

            Log::info('NCBA Payment processed', $result);

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment processed',
                'token_generated' => $result['token_generated'] ?? false,
                'sms_sent' => $result['sms_sent'] ?? false,
            ]);
        } catch (\Throwable $e) {
            Log::error('NCBA: Failed to process payment', [
                'TransID' => $transID,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to process transaction',
            ], 500);
        }
    }
}
