<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Dashboard\ApiTokenController;
use App\Http\Controllers\Api\Dashboard\AuditLogController;
use App\Http\Controllers\Api\Dashboard\EnrollmentTokenController;
use App\Http\Controllers\Api\Dashboard\OverviewController;
use App\Http\Controllers\Api\Dashboard\SettingsController;
use App\Http\Controllers\Api\Dashboard\SiteController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Health check
// ---------------------------------------------------------------------------
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'MarQira Pulse API',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// ---------------------------------------------------------------------------
// Dashboard authentication (Sanctum SPA — stateful session cookie)
// ---------------------------------------------------------------------------
// Login is rate limited (5/min per email+IP, see the "login" limiter).
// The `web` middleware group provides the session store (StartSession) that
// Sanctum's SPA cookie authentication relies on for session regeneration.
Route::post('/login', [AuthController::class, 'login'])->middleware(['web', 'throttle:login']);

// ---------------------------------------------------------------------------
// Plugin: public enrollment endpoint (unauthenticated — uses enrollment token)
// ---------------------------------------------------------------------------
Route::post('/v1/enrollment', [EnrollmentController::class, 'enroll'])
    ->middleware(['throttle:enrollment']);

// ---------------------------------------------------------------------------
// Plugin: HMAC-authenticated routes
// ---------------------------------------------------------------------------
Route::middleware(['hmac.auth'])->prefix('v1')->group(function () {
    Route::post('/heartbeat', [HeartbeatController::class, 'receive']);
    Route::get('/config/allowed-ips', [ConfigController::class, 'allowedIps'])->name('config.allowed-ips');
    Route::get('/config/cloudflare-ranges', [ConfigController::class, 'cloudflareRanges'])->name('config.cloudflare-ranges');
});

// ---------------------------------------------------------------------------
// Dashboard API (authenticated session user + tenant context)
// ---------------------------------------------------------------------------
Route::middleware(['web', 'auth:sanctum'])->group(function () {
    // Current user + logout only require authentication (no tenant needed).
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Everything under /dashboard additionally requires a resolved tenant.
    Route::middleware('tenant')->prefix('dashboard')->group(function () {
        Route::get('/overview', [OverviewController::class, 'index']);

        // Websites
        Route::get('/sites', [SiteController::class, 'index']);
        Route::get('/sites/{uuid}', [SiteController::class, 'show']);
        Route::get('/sites/{uuid}/heartbeats', [SiteController::class, 'heartbeats']);

        // Connection codes (enrollment tokens)
        Route::get('/enrollment-tokens', [EnrollmentTokenController::class, 'index']);
        Route::post('/enrollment-tokens', [EnrollmentTokenController::class, 'store']);

        // API tokens (n8n automation access)
        Route::get('/api-tokens', [ApiTokenController::class, 'index']);
        Route::post('/api-tokens', [ApiTokenController::class, 'store']);
        Route::delete('/api-tokens/{uuid}', [ApiTokenController::class, 'destroy']);

        // Audit log
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        // Settings
        Route::get('/settings', [SettingsController::class, 'show']);
        Route::patch('/settings/profile', [SettingsController::class, 'updateProfile']);
        Route::patch('/settings/password', [SettingsController::class, 'updatePassword']);
    });
});
