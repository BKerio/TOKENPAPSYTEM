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

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/admin/login', [AuthController::class, 'login']);
Route::post('/customer/send-otp', [AuthController::class, 'sendCustomerOtp']);
Route::post('/customer/login-otp', [AuthController::class, 'customerLoginOtp']);
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/mpesa/stkpush', [MpesaController::class, 'stkPush']);
Route::post('/mpesa/callback', [MpesaController::class, 'callback']);

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
    Route::apiResource('admin/meters', MeterController::class);
    Route::apiResource('admin/customers', CustomerController::class);

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
