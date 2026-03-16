<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Meter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Models\SmsConfig;
use App\Models\MpesaConfig;

class VendorController extends Controller
{
    /**
     * Display a listing of vendors.
     */
    public function index(Request $request)
    {
        $query = Vendor::with('user');

        if ($request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                  ->orWhere('account_id', 'like', "%{$search}%")
                  ->orWhereHas('user', function($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $vendors = $query->paginate($request->per_page ?? 10);

        return response()->json($vendors);
    }

    /**
     * Store a newly created vendor.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'business_name' => 'required|string|max:255',
            'address' => 'required|string',
            'account_id' => 'required|string|unique:vendors,account_id',
            'paybill' => 'required|string',
            'vendor_type' => 'required|string|in:Individual,Company',
            'bank_name' => 'required|string',
        ]);

        try {
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'vendor',
            ]);

            $vendor = Vendor::create([
                'user_id' => $user->id,
                'business_name' => $validated['business_name'],
                'address' => $validated['address'],
                'account_id' => $validated['account_id'],
                'paybill' => $validated['paybill'],
                'vendor_type' => $validated['vendor_type'],
                'bank_name' => $validated['bank_name'],
                'status' => 'active',
            ]);

            return response()->json([
                'message' => 'Vendor created successfully',
                'vendor' => $vendor->load('user'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified vendor.
     */
    public function update(Request $request, $id)
    {
        $vendor = Vendor::findOrFail($id);
        $user = $vendor->user;

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'username' => 'sometimes|nullable|string|unique:users,username,' . $vendor->user_id . ',_id',
            'email' => 'sometimes|nullable|email|unique:users,email,' . $vendor->user_id . ',_id',
            'password' => 'nullable|min:8',
            'business_name' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string',
            'account_id' => 'sometimes|nullable|string|unique:vendors,account_id,' . $vendor->id . ',_id',
            'paybill' => 'sometimes|nullable|string',
            'vendor_type' => 'sometimes|nullable|string|in:Individual,Company',
            'bank_name' => 'sometimes|nullable|string',
            'status' => 'sometimes|nullable|string|in:active,suspended',
        ]);

        try {
            if ($user) {
                $userData = [];
                if (isset($validated['name'])) $userData['name'] = $validated['name'];
                if (isset($validated['username'])) $userData['username'] = $validated['username'];
                if (isset($validated['email'])) $userData['email'] = $validated['email'];
                if (!empty($validated['password'])) $userData['password'] = Hash::make($validated['password']);

                if (!empty($userData)) {
                    $user->update($userData);
                }
            }

            $vendorData = [];
            if (isset($validated['business_name'])) $vendorData['business_name'] = $validated['business_name'];
            if (isset($validated['address'])) $vendorData['address'] = $validated['address'];
            if (isset($validated['account_id'])) $vendorData['account_id'] = $validated['account_id'];
            if (isset($validated['paybill'])) $vendorData['paybill'] = $validated['paybill'];
            if (isset($validated['vendor_type'])) $vendorData['vendor_type'] = $validated['vendor_type'];
            if (isset($validated['bank_name'])) $vendorData['bank_name'] = $validated['bank_name'];
            if (isset($validated['status'])) $vendorData['status'] = $validated['status'];

            if (!empty($vendorData)) {
                $vendor->update($vendorData);
            }

            return response()->json([
                'message' => 'Vendor updated successfully',
                'vendor' => $vendor->fresh('user'),
                'user_found' => (bool)$user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified vendor.
     */
    public function destroy($id)
    {
        $vendor = Vendor::findOrFail($id);
        $user = $vendor->user;

        try {
            $vendor->delete();
            if ($user) {
                $user->delete();
            }

            return response()->json(['message' => 'Vendor deleted successfully']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the authenticated vendor's SMS and Mpesa configuration.
     */
    public function getConfig(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthorized',
            ], 401);
        }

        // Check if user is a vendor
        if ($user->role !== 'vendor') {
            return response()->json([
                'status' => 403,
                'message' => 'Access denied. Vendor role required.',
            ], 403);
        }

        $vendor = Vendor::where('user_id', $user->id)->first();

        if (!$vendor) {
            return response()->json([
                'status' => 404,
                'message' => 'Vendor profile not found for this user.',
                'sms_config' => [],
                'mpesa_config' => [],
            ], 404);
        }

        $smsConfig = $vendor->smsConfig;
        $smsConfigArray = $smsConfig ? $smsConfig->toArray() : [];
        if (isset($smsConfigArray['api_key'])) {
            $smsConfigArray['api_key'] = 'is_set';
        }

        $mpesaConfig = $vendor->mpesaConfig;
        $mpesaConfigArray = $mpesaConfig ? $mpesaConfig->toArray() : [];
        foreach (['consumer_key', 'consumer_secret', 'passkey'] as $k) {
            if (isset($mpesaConfigArray[$k])) {
                $mpesaConfigArray[$k] = 'is_set';
            }
        }

        return response()->json([
            'status' => 200,
            'sms_config' => $smsConfigArray,
            'mpesa_config' => $mpesaConfigArray,
        ]);
    }

    /**
     * Update the authenticated vendor's SMS and Mpesa configuration.
     */
    public function updateConfig(Request $request)
    {
        $user = $request->user();
        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();

        $data = $request->validate([
            'sms_config' => 'sometimes|array',
            'sms_config.provider' => 'sometimes|nullable|string|max:255',
            'sms_config.api_url' => 'sometimes|nullable|string|url|max:500',
            'sms_config.api_key' => 'sometimes|nullable|string|max:500',
            'sms_config.partner_id' => 'sometimes|nullable|string|max:255',
            'sms_config.shortcode' => 'sometimes|nullable|string|max:255',
            'sms_config.enabled' => 'sometimes|nullable|boolean',
            
            'mpesa_config' => 'sometimes|array',
            'mpesa_config.consumer_key' => 'sometimes|nullable|string|max:500',
            'mpesa_config.consumer_secret' => 'sometimes|nullable|string|max:500',
            'mpesa_config.passkey' => 'sometimes|nullable|string|max:500',
            'mpesa_config.shortcode' => 'sometimes|nullable|string|max:255',
            'mpesa_config.till_no' => 'sometimes|nullable|string|max:255',
            'mpesa_config.env' => 'sometimes|nullable|string|in:sandbox,live',
            'mpesa_config.callback_url' => 'sometimes|nullable|string|url|max:500',
            'mpesa_config.transaction_type' => 'sometimes|nullable|string|in:CustomerPayBillOnline,CustomerBuyGoodsOnline',
        ]);

        if (isset($data['sms_config'])) {
            $smsData = array_filter($data['sms_config'], function($value) {
                return $value !== null && $value !== '';
            });
            if (isset($smsData['api_key'])) {
                $smsData['api_key'] = \Illuminate\Support\Facades\Crypt::encryptString($smsData['api_key']);
            }
            
            $smsConfig = $vendor->smsConfig;
            if ($smsConfig) {
                $smsConfig->update($smsData);
            } else {
                $smsData['vendor_id'] = $vendor->id;
                SmsConfig::create($smsData);
            }
        }

        if (isset($data['mpesa_config'])) {
            $mpesaData = array_filter($data['mpesa_config'], function($value) {
                return $value !== null && $value !== '';
            });
            foreach (['consumer_key', 'consumer_secret', 'passkey'] as $key) {
                if (isset($mpesaData[$key])) {
                    $mpesaData[$key] = \Illuminate\Support\Facades\Crypt::encryptString($mpesaData[$key]);
                }
            }
            
            $mpesaConfig = $vendor->mpesaConfig;
            if ($mpesaConfig) {
                $mpesaConfig->update($mpesaData);
            } else {
                $mpesaData['vendor_id'] = $vendor->id;
                MpesaConfig::create($mpesaData);
            }
        }

        return response()->json([
            'status' => 200,
            'message' => 'Vendor configuration updated successfully',
        ]);
    }

    /**
     * Get the authenticated vendor's full profile (for dashboard and branding).
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'vendor') {
            return response()->json(['status' => 403, 'message' => 'Vendor access only.'], 403);
        }

        $vendor = Vendor::with('user')->where('user_id', $user->id)->first();
        if (!$vendor) {
            return response()->json(['status' => 404, 'message' => 'Vendor profile not found.'], 404);
        }

        $meterCount = Meter::where('vendor_id', $vendor->id)->count();
        $activeMeters = Meter::where('vendor_id', $vendor->id)->where('status', 'active')->count();

        $logoUrl = $vendor->logo_url;
        if ($logoUrl && strpos($logoUrl, 'http') !== 0) {
            $logoUrl = Storage::disk('public')->url(str_replace('/storage/', '', $logoUrl));
        }

        return response()->json([
            'status' => 200,
            'vendor' => [
                'id' => $vendor->id,
                'user_id' => $vendor->user_id,
                'business_name' => $vendor->business_name,
                'address' => $vendor->address,
                'account_id' => $vendor->account_id,
                'paybill' => $vendor->paybill,
                'vendor_type' => $vendor->vendor_type,
                'bank_name' => $vendor->bank_name,
                'status' => $vendor->status,
                'logo_url' => $logoUrl,
                'dashboard_settings' => $vendor->dashboard_settings ?? [],
                'user' => $vendor->user ? [
                    'id' => $vendor->user->id,
                    'name' => $vendor->user->name,
                    'email' => $vendor->user->email,
                    'username' => $vendor->user->username,
                ] : null,
            ],
            'stats' => [
                'total_meters' => $meterCount,
                'active_meters' => $activeMeters,
            ],
        ]);
    }

    /**
     * Update the authenticated vendor's profile (business info, branding, dashboard settings).
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'vendor') {
            return response()->json(['status' => 403, 'message' => 'Vendor access only.'], 403);
        }

        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();

        $data = $request->validate([
            'business_name' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string',
            'vendor_type' => 'sometimes|nullable|string|max:255|in:Individual,Company',
            'bank_name' => 'sometimes|nullable|string|max:255',
            'dashboard_settings' => 'sometimes|nullable|array',
            'dashboard_settings.primary_color' => 'sometimes|nullable|string|max:50',
            'dashboard_settings.tagline' => 'sometimes|nullable|string|max:255',
            'dashboard_settings.show_logo_in_sidebar' => 'sometimes|nullable|boolean',
        ]);

        if (isset($data['business_name'])) {
            $vendor->business_name = $data['business_name'];
        }
        if (array_key_exists('address', $data)) {
            $vendor->address = $data['address'];
        }
        if (isset($data['vendor_type'])) {
            $vendor->vendor_type = $data['vendor_type'];
        }
        if (isset($data['bank_name'])) {
            $vendor->bank_name = $data['bank_name'];
        }
        if (isset($data['dashboard_settings'])) {
            $vendor->dashboard_settings = array_merge($vendor->dashboard_settings ?? [], $data['dashboard_settings']);
        }

        $vendor->save();

        $logoUrl = $vendor->logo_url;
        if ($logoUrl && strpos($logoUrl, 'http') !== 0) {
            $logoUrl = Storage::disk('public')->url(str_replace('/storage/', '', $logoUrl));
        }

        return response()->json([
            'status' => 200,
            'message' => 'Profile updated successfully.',
            'vendor' => [
                'id' => $vendor->id,
                'business_name' => $vendor->business_name,
                'logo_url' => $logoUrl,
                'dashboard_settings' => $vendor->dashboard_settings ?? [],
            ],
        ]);
    }

    /**
     * Upload or replace the authenticated vendor's logo (base64 or file).
     */
    public function uploadLogo(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'vendor') {
            return response()->json(['status' => 403, 'message' => 'Vendor access only.'], 403);
        }

        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();

        $request->validate([
            'logo' => 'required', // base64 data URL or file
        ]);

        $logoUrl = null;

        // Support base64 data URL (e.g. from frontend canvas/file read)
        if ($request->has('logo') && is_string($request->logo)) {
            $data = $request->logo;
            if (preg_match('/^data:image\/(\w+);base64,/', $data, $matches)) {
                $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
                $content = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $data));
                if ($content !== false) {
                    $path = 'vendor-logos/' . $vendor->id . '_' . uniqid() . '.' . $ext;
                    Storage::disk('public')->put($path, $content);
                    $logoUrl = Storage::url($path);
                }
            }
        }

        // Support file upload
        if (!$logoUrl && $request->hasFile('logo')) {
            $file = $request->file('logo');
            $request->validate(['logo' => 'image|max:2048']);
            $path = $file->store('vendor-logos', 'public');
            $logoUrl = Storage::url($path);
        }

        if (!$logoUrl) {
            return response()->json([
                'status' => 422,
                'message' => 'Invalid logo. Provide a base64 image or file upload.',
            ], 422);
        }

        // Remove old logo file if exists (optional, to save space)
        if ($vendor->logo_url) {
            $oldPath = str_replace('/storage/', '', parse_url($vendor->logo_url, PHP_URL_PATH));
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        // Store relative path in DB (e.g., 'vendor-logos/filename.jpg')
        $vendor->logo_url = $path;
        $vendor->save();

        $fullLogoUrl = Storage::disk('public')->url($path);

        return response()->json([
            'status' => 200,
            'message' => 'Logo updated successfully.',
            'logo_url' => $fullLogoUrl,
        ]);
    }
}
