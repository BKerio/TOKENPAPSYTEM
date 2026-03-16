<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Models\Otp;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * Obtain the user information from Google.
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // Search for customer by email
            $customer = Customer::where('email', $googleUser->getEmail())->first();

            if (!$customer) {
                // Determine if we should create a new customer or error out
                // For now, let's assume they must be pre-onboarded
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
                return redirect($frontendUrl . '/login?error=' . urlencode('Your Google account is not associated with any onboarded customer profile.'));
            }

            // Create token
            $token = $customer->createToken('customer-google-token')->plainTextToken;

            // Redirect back to frontend with token
            // We'll use a placeholder URL, but ideally this would be configurable
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
            return redirect($frontendUrl . '/login?token=' . $token . '&user=' . urlencode(json_encode([
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'role' => 'customer',
            ])));

        } catch (\Exception $e) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
            return redirect($frontendUrl . '/login?error=' . urlencode('Failed to authenticate with Google: ' . $e->getMessage()));
        }
    }
    /**
     * Admin login
     */
    public function login(Request $request)
    {
        \Log::info('Login attempt START', ['data' => $request->all()]);

        // $request->validate([
        //     'identifier' => 'required',
        //     'password' => 'required',
        // ]);

        $identifier = $request->identifier;
        $user = null;
        $isAdmin = false;

        // 1. Check if it's an Admin
        $admin = \App\Models\Admin::where('email', $identifier)->orWhere('username', $identifier)->first();
        if ($admin && Hash::check($request->password, $admin->password)) {
            $user = $admin;
            $isAdmin = true;
        }

        // 2. If not admin, check if it's a User (Vendor)
        if (!$user) {
            $vendorUser = User::where('email', $identifier)->first();
            
            // Check by account_id if email not found
            if (!$vendorUser) {
                $vendor = \App\Models\Vendor::where('account_id', $identifier)->first();
                if ($vendor) {
                    $vendorUser = $vendor->user;
                }
            }

            if ($vendorUser && Hash::check($request->password, $vendorUser->password)) {
                $user = $vendorUser;
            }
        }

        // Output error if neither matched
        if (!$user) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.'
            ], 401);
        }

        // Create token (Sanctum handles morphs automatically based on the model instance)
        $tokenName = $isAdmin ? 'admin-token' : 'auth-token';
        $token = $user->createToken($tokenName)->plainTextToken;

        $vendorType = null;
        if ($user->role === 'vendor') {
            $vendorRecord = \App\Models\Vendor::where('user_id', $user->id)->first();
            if ($vendorRecord) {
                $vendorType = $vendorRecord->vendor_type ?? null;
            }
        }

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'role' => $user->role,
                'vendor_type' => $vendorType,
            ],
            'status' => 200 // Added for compatibility with user's frontend code
        ]);
    }

    /**
     * Admin logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get current authenticated user
     */
    public function me(Request $request)
    {
        $user = $request->user();
        \Log::info('Entering me() method', ['user' => $user ? $user->toArray() : 'null']);

        $vendorType = null;
        if ($user->role === 'vendor') {
            $vendorRecord = \App\Models\Vendor::where('user_id', $user->id)->first();
            if ($vendorRecord) {
                $vendorType = $vendorRecord->vendor_type ?? null;
            }
        }

        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'phone' => $user->phone,
                'role' => $user->role,
                'profile_image' => $user->profile_image,
                'bio' => $user->bio,
                'vendor_type' => $vendorType,
            ]
        ];

        if ($user->role === 'customer') {
            $user->load(['meter', 'vendor']);
            $data['user']['meter'] = $user->meter;
            $data['user']['vendor'] = $user->vendor;
            
            $transactions = \App\Models\TokenTransaction::where('meter_id', $user->meter_id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();
            $data['user']['recent_transactions'] = $transactions;
        }

        return response()->json($data);
    }

    /**
     * Get account details
     */
    public function getAccount(Request $request)
    {
        $user = $request->user();
        \Log::info('Entering getAccount() method', ['user' => $user ? $user->toArray() : 'null']);
        
        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'phone' => $user->phone,
            'role' => $user->role,
            'profile_image' => $user->profile_image,
            'bio' => $user->bio,
        ];

        if ($user->role === 'customer') {
            $user->load(['meter', 'vendor']);
            $data['meter'] = $user->meter;
            $data['vendor'] = $user->vendor;
            
            $transactions = \App\Models\TokenTransaction::where('meter_id', $user->meter_id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();
            $data['recent_transactions'] = $transactions;
        }

        return response()->json($data);
    }

    /**
     * Update account details
     */
    public function updateAccount(Request $request)
    {
        $user = $request->user();
        $tableName = $user->getTable(); // either 'admins' or 'users'

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|string|max:255|unique:' . $tableName . ',username,' . $user->id . ',_id',
            'email' => 'sometimes|email|unique:' . $tableName . ',email,' . $user->id . ',_id',
            'bio' => 'nullable|string',
            'profile_image' => 'nullable|string',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Account updated successfully',
            'user' => $user
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json([
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Send OTP to customer
     */
    public function sendCustomerOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required',
        ]);

        $phone = $request->phone;
        $customer = Customer::where('phone', $phone)->first();

        if (!$customer) {
            return response()->json([
                'message' => 'Customer with this phone number not found.'
            ], 404);
        }

        // Generate 6-digit OTP
        $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store/Update OTP
        Otp::updateOrCreate(
            ['phone' => $phone],
            [
                'otp' => $otpCode,
                'expires_at' => Carbon::now()->addMinutes(5)
            ]
        );

        // Send SMS
        $smsService = new SmsService();
        $message = "Your Token Utility System verification code is: {$otpCode}. Valid for 5 minutes.";
        
        // Try to get vendor's SMS config
        $vendorConfig = null;
        if ($customer->vendor_id) {
            $vendor = \App\Models\Vendor::find($customer->vendor_id);
            if ($vendor) {
                $vendorConfig = \App\Models\SmsConfig::where('vendor_id', $vendor->id)->first();
            }
        }

        $sent = $smsService->sendSms($phone, $message, $vendorConfig ? $vendorConfig->toArray() : null);

        if (!$sent) {
            return response()->json([
                'message' => 'Failed to send OTP. Please try again later.'
            ], 500);
        }

        return response()->json([
            'message' => 'OTP sent successfully.'
        ]);
    }

    /**
     * Login customer via OTP
     */
    public function customerLoginOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'otp' => 'required|size:6',
        ]);

        $phone = $request->phone;
        $otpCode = $request->otp;

        $otpRecord = Otp::where('phone', $phone)
            ->where('otp', $otpCode)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'message' => 'Invalid or expired OTP.'
            ], 401);
        }

        $customer = Customer::where('phone', $phone)->first();
        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found.'
            ], 404);
        }

        // Create token
        $token = $customer->createToken('customer-token')->plainTextToken;

        // Delete used OTP
        $otpRecord->delete();

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'role' => 'customer',
            ],
            'status' => 200
        ]);
    }
}
