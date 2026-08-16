<?php

use App\Http\Controllers\Api\Admin\EnrollmentTokenController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'MarQira Pulse API',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Public enrollment endpoint (unauthenticated — uses enrollment token)
Route::post('/v1/enrollment', [EnrollmentController::class, 'enroll'])
    ->middleware(['throttle:enrollment']);

// Plugin HMAC-authenticated routes
Route::middleware(['hmac.auth'])->prefix('v1')->group(function () {
    Route::post('/heartbeat', [HeartbeatController::class, 'receive']);
    Route::get('/config/allowed-ips', [ConfigController::class, 'allowedIps'])->name('config.allowed-ips');
    Route::get('/config/cloudflare-ranges', [ConfigController::class, 'cloudflareRanges'])->name('config.cloudflare-ranges');
});

// Admin routes (for dashboard — Phase 5 will add auth middleware)
Route::prefix('admin')->group(function () {
    Route::post('/enrollment-tokens', [EnrollmentTokenController::class, 'create']);
    Route::get('/enrollment-tokens', [EnrollmentTokenController::class, 'index']);
});
