<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // NCBA often registers /notifications/ncba (without /api prefix)
            Route::post('/notifications/ncba', [\App\Http\Controllers\Api\NcbaWebhookController::class, 'handle']);
            Route::get('/notifications/ncba', function () {
                return response()->json([
                    'status'  => 'ok',
                    'message' => 'TokenPap NCBA webhook endpoint is reachable',
                    'note'    => 'Payment notifications must be sent via POST',
                ]);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            \Log::error('Validation Failed', [
                'url' => $request->fullUrl(),
                'errors' => $e->errors(),
                'input' => $request->all(),
            ]);
        });
    })->create();
