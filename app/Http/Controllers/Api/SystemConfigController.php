<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use App\Models\Vendor;
use App\Services\SmsService;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SystemConfigController extends Controller
{
    protected SmsService $smsService;
    protected MpesaService $mpesaService;

    public function __construct(SmsService $smsService, MpesaService $mpesaService)
    {
        $this->smsService = $smsService;
        $this->mpesaService = $mpesaService;
    }
    /**
     * Get all system configurations (optionally filtered by category/search).
     */
    public function index(Request $request)
    {
        $query = SystemConfig::query();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('key', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $configs = $query->orderBy('category')
            ->orderBy('key')
            ->get();

        $configs = $configs->map(function ($config) {
            $data = $config->toArray();

            if ($config->is_encrypted && !empty($config->value)) {
                $value = $config->value;
                $data['value'] = str_repeat('*', min(strlen($value), 20)) . (strlen($value) > 20 ? '...' : '');
                $data['is_masked'] = true;
            } else {
                $data['is_masked'] = false;
            }

            return $data;
        });

        return response()->json([
            'status' => 200,
            'configs' => $configs,
        ]);
    }

    /**
     * Get configuration by category.
     */
    public function getByCategory(string $category)
    {
        $configs = SystemConfig::getByCategory($category);

        $configs = $configs->map(function ($config) {
            $data = $config->toArray();

            if ($config->is_encrypted && !empty($config->value)) {
                $value = $config->value;
                $data['value'] = str_repeat('*', min(strlen($value), 20)) . (strlen($value) > 20 ? '...' : '');
                $data['is_masked'] = true;
            } else {
                $data['is_masked'] = false;
            }

            return $data;
        });

        return response()->json([
            'status' => 200,
            'configs' => $configs,
        ]);
    }

    /**
     * Get a single configuration by key.
     */
    public function show(string $key)
    {
        $config = SystemConfig::where('key', $key)->first();

        if (!$config) {
            return response()->json([
                'status' => 404,
                'message' => 'Configuration not found',
            ], 404);
        }

        $data = $config->toArray();

        if ($config->is_encrypted && !empty($config->value)) {
            $value = $config->value;
            $data['value'] = str_repeat('*', min(strlen($value), 20)) . (strlen($value) > 20 ? '...' : '');
            $data['is_masked'] = true;
        } else {
            $data['is_masked'] = false;
        }

        return response()->json([
            'status' => 200,
            'config' => $data,
        ]);
    }

    /**
     * Update a single configuration by key.
     */
    public function update(Request $request, string $key)
    {
        $config = SystemConfig::where('key', $key)->first();

        if (!$config) {
            return response()->json([
                'status' => 404,
                'message' => 'Configuration not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->errors(),
            ], 422);
        }

        $value = $request->value;

        if ($config->type === 'boolean') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        $config->value = $value;

        if ($request->has('description')) {
            $config->description = $request->description;
        }

        $config->save();

        $data = $config->toArray();

        if ($config->is_encrypted && !empty($config->value)) {
            $value = $config->value;
            $data['value'] = str_repeat('*', min(strlen($value), 20)) . (strlen($value) > 20 ? '...' : '');
            $data['is_masked'] = true;
        } else {
            $data['is_masked'] = false;
        }

        return response()->json([
            'status' => 200,
            'message' => 'Configuration updated successfully',
            'config' => $data,
        ]);
    }

    /**
     * Bulk update multiple configurations.
     */
    public function bulkUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'configs' => 'required|array',
            'configs.*.key' => 'required|string',
            'configs.*.value' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->errors(),
            ], 422);
        }

        $updated = [];

        foreach ($request->configs as $configData) {
            $config = SystemConfig::where('key', $configData['key'])->first();

            if ($config) {
                $value = $configData['value'];

                if ($config->type === 'boolean') {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                }

                $config->value = $value;

                if (isset($configData['description'])) {
                    $config->description = $configData['description'];
                }

                $config->save();
                $updated[] = $config->key;
            }
        }

        return response()->json([
            'status' => 200,
            'message' => 'Configurations updated successfully',
            'updated' => $updated,
        ]);
    }

    /**
     * Create a new configuration entry.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string|unique:system_configs,key',
            'value' => 'required',
            'type' => 'required|string|in:string,number,integer,float,boolean,json',
            'category' => 'required|string',
            'description' => 'nullable|string',
            'is_encrypted' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->errors(),
            ], 422);
        }

        $config = SystemConfig::create([
            'key' => $request->key,
            'value' => $request->value,
            'type' => $request->type,
            'category' => $request->category,
            'description' => $request->description,
            'is_encrypted' => $request->is_encrypted ?? false,
        ]);

        return response()->json([
            'status' => 201,
            'message' => 'Configuration created successfully',
            'config' => $config,
        ], 201);
    }

    /**
     * Delete a configuration by key.
     */
    public function destroy(string $key)
    {
        $config = SystemConfig::where('key', $key)->first();

        if (!$config) {
            return response()->json([
                'status' => 404,
                'message' => 'Configuration not found',
            ], 404);
        }

        $criticalKeys = ['sms_api_url', 'sms_api_key', 'sms_provider'];

        if (in_array($key, $criticalKeys, true)) {
            return response()->json([
                'status' => 403,
                'message' => 'Cannot delete critical configuration',
            ], 403);
        }

        $config->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Configuration deleted successfully',
        ]);
    }

    /**
     * Send a test SMS, using per-vendor config when a vendor is logged in.
     */
    public function testSms(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'message' => 'required|string|max:480',
            'vendor_email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $vendorConfig = null;

        // 1. If a vendor_email is provided, prefer that
        if ($request->filled('vendor_email')) {
            $vendor = Vendor::whereHas('user', function ($q) use ($request) {
                $q->where('email', $request->input('vendor_email'));
            })->first();

            if ($vendor && $vendor->sms_config) {
                $vendorConfig = $vendor->sms_config;
            }
        }

        // 2. Otherwise, if the authenticated user is a vendor, use their config
        if (!$vendorConfig && $user && $user->role === 'vendor') {
            $vendor = Vendor::where('user_id', $user->id)->first();
            if ($vendor && $vendor->smsConfig) {
                $vendorConfig = $vendor->smsConfig->toArray();
            }
        }

        $success = $this->smsService->sendSms(
            $request->input('phone'),
            $request->input('message'),
            $vendorConfig
        );

        return response()->json([
            'status' => $success ? 200 : 500,
            'message' => $success ? 'Test SMS sent successfully' : 'Failed to send test SMS',
        ], $success ? 200 : 500);
    }

    /**
     * Initiate a test M-Pesa STK push, using per-vendor config when a vendor is logged in.
     */
    public function testMpesa(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'vendor_email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $response = null;
        $vendorConfig = null;

        // 1. If a vendor_email is provided, prefer that
        if ($request->filled('vendor_email')) {
            $vendor = Vendor::whereHas('user', function ($q) use ($request) {
                $q->where('email', $request->input('vendor_email'));
            })->first();

            if ($vendor && $vendor->mpesa_config) {
                $vendorConfig = $vendor->mpesa_config;
            }
        }

        // 2. Otherwise, if authenticated user is vendor, use their config
        if (!$vendorConfig && $user && $user->role === 'vendor') {
            $vendor = Vendor::where('user_id', $user->id)->first();
            if ($vendor && $vendor->mpesaConfig) {
                $vendorConfig = $vendor->mpesaConfig->toArray();
            }
        }

        if ($vendorConfig) {
            $response = $this->mpesaService->stkPushWithConfig(
                $vendorConfig,
                $request->input('phone'),
                (float) $request->input('amount'),
                'TestPayment'
            );
        } else {
            // Fallback to global config
            $response = $this->mpesaService->stkPush(
                $request->input('phone'),
                (float) $request->input('amount'),
                'TestPayment'
            );
        }

        if (isset($response['errorCode']) || isset($response['errorMessage']) || (isset($response['ResponseCode']) && $response['ResponseCode'] !== '0')) {
            return response()->json([
                'status' => 400,
                'message' => $response['errorMessage'] ?? ($response['customerMessage'] ?? 'M-Pesa API Error'),
                'response' => $response,
            ], 400);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Test STK push request sent',
            'response' => $response,
        ]);
    }
}

