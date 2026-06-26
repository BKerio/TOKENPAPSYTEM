<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\MeterController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\SystemMonitoringController;
use App\Http\Controllers\Api\MpesaController;
use App\Http\Controllers\Api\SystemConfigController;
use App\Http\Controllers\Api\VendingController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\MpesaConfigController;
use App\Http\Controllers\Api\SmsConfigController;
use App\Http\Controllers\Api\LandlordController;
use App\Http\Controllers\Api\VendorRegistrationController;
use App\Http\Controllers\Api\ContactEnquiryController;
use App\Http\Controllers\Api\NcbaWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/admin/login', [AuthController::class, 'login']);
// Admin/Vendor forgot password
Route::post('/admin/forgot-password/send-otp', [AuthController::class, 'sendVendorForgotPasswordOtp']);
Route::post('/admin/forgot-password/verify-otp', [AuthController::class, 'verifyVendorForgotPasswordOtp']);
Route::post('/admin/forgot-password/reset', [AuthController::class, 'resetVendorPassword']);

Route::post('/customer/send-otp', [AuthController::class, 'sendCustomerOtp']);
Route::post('/customer/login-otp', [AuthController::class, 'customerLoginOtp']);
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/mpesa/stkpush', [MpesaController::class, 'stkPush']);
Route::post('/paybill/claim', [\App\Http\Controllers\Api\PaybillController::class, 'claim']);
Route::post('/mpesa/callback', [MpesaController::class, 'callback']);
Route::post('/mpesa/c2b/validation', [\App\Http\Controllers\Api\MpesaC2bController::class, 'validation']);
Route::post('/mpesa/c2b/confirmation', [\App\Http\Controllers\Api\MpesaC2bController::class, 'confirm']);
Route::get('/mpesa/c2b/validation', fn () => response()->json(['status' => 'ok', 'endpoint' => 'C2B validation — POST required']));
Route::get('/mpesa/c2b/confirmation', fn () => response()->json(['status' => 'ok', 'endpoint' => 'C2B confirmation — POST required']));
Route::get('/mpesa/query/{checkoutRequestId}', [MpesaController::class, 'checkStatus']);
Route::post('/enquiries', [ContactEnquiryController::class, 'store']);
Route::post('/register/vendor', [VendorRegistrationController::class, 'register']);
Route::get('/tokens/search', [TokenController::class, 'searchPublic']);

// NCBA Paybill webhook (public — called by NCBA/TokenPap server)
Route::post('/notifications/ncba', [NcbaWebhookController::class, 'handle']);
Route::get('/notifications/ncba', function () {
    return response()->json([
        'status'  => 'ok',
        'message' => 'TokenPap NCBA webhook endpoint is reachable',
        'note'    => 'Payment notifications must be sent via POST',
    ]);
});

// Protected routes (allow both default user tokens and admin tokens)
Route::middleware('auth:sanctum')->group(function () {
    // Location routes
    Route::get('locations/counties', [LocationController::class, 'getCounties']);
    Route::get('locations/constituencies', [LocationController::class, 'getConstituencies']);
    Route::get('locations/wards', [LocationController::class, 'getWards']);
    // Auth routes
    Route::post('/admin/logout', [AuthController::class, 'logout']);
    Route::get('/admin/me', [AuthController::class, 'me']);
    Route::get('/admin/account', [AuthController::class, 'getAccount']);
    Route::put('/admin/account', [AuthController::class, 'updateAccount']);
    Route::put('/admin/account/password', [AuthController::class, 'changePassword']);
    
    // Vendor routes
    Route::apiResource('admin/vendors', VendorController::class);
    Route::get('admin/pending-vendors', [VendorRegistrationController::class, 'pending']);
    Route::post('admin/vendors/{vendor}/approve', [VendorRegistrationController::class, 'approve']);
    Route::post('admin/vendors/{vendor}/reject', [VendorRegistrationController::class, 'reject']);
    Route::apiResource('admin/landlords', LandlordController::class);
    Route::apiResource('admin/meters', MeterController::class);
    Route::apiResource('admin/customers', CustomerController::class);
    Route::apiResource('admin/enquiries', ContactEnquiryController::class)->except(['store']);

    // Token Vending
    Route::post('tokens/generate', [TokenController::class, 'generate']);
    
    // System Monitoring & Oversight
    Route::get('admin/vending-control', [SystemMonitoringController::class, 'getSystemStats']);
    Route::get('admin/vendors/{vendor}/oversight', [SystemMonitoringController::class, 'getVendorOversight']);

    // Authenticated vendor configuration (per-vendor Mpesa/SMS)
    Route::get('vendor/config', [VendorController::class, 'getConfig']);
    Route::put('vendor/config', [VendorController::class, 'updateConfig']);

    // New separate config routes
    Route::get('vendor/mpesa-config', [MpesaConfigController::class, 'show']);
    Route::put('vendor/mpesa-config', [MpesaConfigController::class, 'update']);
    Route::get('vendor/sms-config', [SmsConfigController::class, 'show']);
    Route::put('vendor/sms-config', [SmsConfigController::class, 'update']);

    // Vendor profile and branding
    Route::get('vendor/profile', [VendorController::class, 'getProfile']);
    Route::put('vendor/profile', [VendorController::class, 'updateProfile']);
    Route::post('vendor/logo', [VendorController::class, 'uploadLogo']);

    // Landlord profile (self)
    Route::get('landlord/profile', [LandlordController::class, 'getProfile']);
    
    // Landlord properties
    Route::apiResource('landlord/properties', PropertyController::class);

    // System Configuration (admin)
    Route::prefix('admin/system-config')->group(function () {
        Route::get('/', [SystemConfigController::class, 'index']);
        Route::get('/category/{category}', [SystemConfigController::class, 'getByCategory']);
        Route::get('/{key}', [SystemConfigController::class, 'show']);
        Route::put('/{key}', [SystemConfigController::class, 'update']);
        Route::post('/bulk-update', [SystemConfigController::class, 'bulkUpdate']);
        Route::post('/', [SystemConfigController::class, 'store']);
        Route::delete('/{key}', [SystemConfigController::class, 'destroy']);
        Route::post('/test-sms', [SystemConfigController::class, 'testSms']);
        Route::post('/test-mpesa', [SystemConfigController::class, 'testMpesa']);
    });

    // Vending Configuration (admin)
    Route::prefix('admin/vending-settings')->group(function () {
        Route::get('/', [VendingController::class, 'index']);
        Route::post('/bulk-update', [VendingController::class, 'bulkUpdate']);
    });
    
    // Placeholder routes for roles and permissions
    Route::get('admin/roles', function() { return response()->json(['roles' => ['admin', 'vendor', 'attendance_staff']]); });
    Route::get('admin/permissions', function() { return response()->json(['permissions' => ['vending.control', 'meter.manage', 'customer.manage']]); });

});
