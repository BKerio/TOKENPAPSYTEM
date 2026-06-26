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
     *   "BillRefNumber": "218262",
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
        $data = $this->parsePayload($request);

        Log::info('NCBA Webhook received', [
            'ip'           => $request->ip(),
            'method'       => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'user_agent'   => $request->userAgent(),
            'payload'      => $data,
        ]);

        $expectedUsername = config('services.ncba.username');
        $expectedPassword = config('services.ncba.password');
        $expectedHash     = config('services.ncba.hash');

        if (
            ($data['Username'] ?? null) !== $expectedUsername ||
            ($data['Password'] ?? null) !== $expectedPassword ||
            ($data['Hash'] ?? null)     !== $expectedHash
        ) {
            Log::warning('NCBA Webhook authentication failed', [
                'ip'              => $request->ip(),
                'username_match'  => ($data['Username'] ?? null) === $expectedUsername,
                'password_match'  => ($data['Password'] ?? null) === $expectedPassword,
                'hash_match'      => ($data['Hash'] ?? null) === $expectedHash,
                'received_keys'   => array_keys($data),
            ]);

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

    /**
     * NCBA may send JSON, form-urlencoded, or mixed field casing.
     */
    private function parsePayload(Request $request): array
    {
        $data = $request->all();

        if (!empty($data)) {
            return $this->normalizePayloadKeys($data);
        }

        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return [];
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $this->normalizePayloadKeys($json);
        }

        parse_str($raw, $form);
        if (is_array($form) && !empty($form)) {
            return $this->normalizePayloadKeys($form);
        }

        Log::warning('NCBA Webhook unparsed body', [
            'raw' => substr($raw, 0, 2000),
        ]);

        return [];
    }

    private function normalizePayloadKeys(array $data): array
    {
        $map = [
            'transid'          => 'TransID',
            'transamount'      => 'TransAmount',
            'billrefnumber'    => 'BillRefNumber',
            'narrative'        => 'Narrative',
            'mobile'           => 'Mobile',
            'username'         => 'Username',
            'password'         => 'Password',
            'hash'             => 'Hash',
            'businessshortcode'=> 'BusinessShortCode',
            'transtime'        => 'TransTime',
            'name'             => 'name',
        ];

        $normalized = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            $normalized[$map[$lower] ?? $key] = $value;
        }

        return $normalized;
    }
}
