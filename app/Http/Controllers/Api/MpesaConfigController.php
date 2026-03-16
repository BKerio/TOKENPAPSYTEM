<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MpesaConfig;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class MpesaConfigController extends Controller
{
    /**
     * Get the vendor's MPESA configuration.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();
        $config = $vendor->mpesaConfig;

        if (!$config) {
            return response()->json([
                'status' => 200,
                'mpesa_config' => [],
            ]);
        }

        $configArray = $config->toArray();
        foreach (['consumer_key', 'consumer_secret', 'passkey'] as $k) {
            if (isset($configArray[$k])) {
                $configArray[$k] = 'is_set';
            }
        }

        return response()->json([
            'status' => 200,
            'mpesa_config' => $configArray,
        ]);
    }

    /**
     * Update the vendor's MPESA configuration.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'consumer_key' => 'sometimes|nullable|string|max:500',
            'consumer_secret' => 'sometimes|nullable|string|max:500',
            'passkey' => 'sometimes|nullable|string|max:500',
            'shortcode' => 'sometimes|nullable|string|max:255',
            'till_no' => 'sometimes|nullable|string|max:255',
            'env' => 'sometimes|nullable|string|in:sandbox,live',
            'callback_url' => 'sometimes|nullable|string|url|max:500',
            'transaction_type' => 'sometimes|nullable|string|in:CustomerPayBillOnline,CustomerBuyGoodsOnline',
        ]);

        $configData = array_filter($request->only([
            'consumer_key', 'consumer_secret', 'passkey', 'shortcode', 
            'till_no', 'env', 'callback_url', 'transaction_type'
        ]), function($value) {
            return $value !== null && $value !== '' && $value !== 'is_set';
        });

        foreach (['consumer_key', 'consumer_secret', 'passkey'] as $key) {
            if (isset($configData[$key]) && $configData[$key] !== 'is_set') {
                $configData[$key] = Crypt::encryptString($configData[$key]);
            }
        }

        $config = $vendor->mpesaConfig;
        if ($config) {
            $config->update($configData);
        } else {
            $configData['vendor_id'] = $vendor->id;
            MpesaConfig::create($configData);
        }

        return response()->json([
            'status' => 200,
            'message' => 'MPESA configuration updated successfully',
        ]);
    }
}
