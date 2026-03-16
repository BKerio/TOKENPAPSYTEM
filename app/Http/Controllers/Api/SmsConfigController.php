<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsConfig;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class SmsConfigController extends Controller
{
    /**
     * Get the vendor's SMS configuration.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();
        $config = $vendor->smsConfig;

        if (!$config) {
            return response()->json([
                'status' => 200,
                'sms_config' => [],
            ]);
        }

        $configArray = $config->toArray();
        if (isset($configArray['api_key'])) {
            $configArray['api_key'] = 'is_set';
        }

        return response()->json([
            'status' => 200,
            'sms_config' => $configArray,
        ]);
    }

    /**
     * Update the vendor's SMS configuration.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'provider' => 'sometimes|nullable|string|max:255',
            'api_url' => 'sometimes|nullable|string|url|max:500',
            'api_key' => 'sometimes|nullable|string|max:500',
            'partner_id' => 'sometimes|nullable|string|max:255',
            'shortcode' => 'sometimes|nullable|string|max:255',
            'enabled' => 'sometimes|nullable|boolean',
        ]);

        $configData = array_filter($validated, function($value) {
            return $value !== null && $value !== '';
        });

        if (isset($configData['api_key'])) {
            $configData['api_key'] = Crypt::encryptString($configData['api_key']);
        }

        $config = $vendor->smsConfig;
        if ($config) {
            $config->update($configData);
        } else {
            $configData['vendor_id'] = $vendor->id;
            SmsConfig::create($configData);
        }

        return response()->json([
            'status' => 200,
            'message' => 'SMS configuration updated successfully',
        ]);
    }
}
