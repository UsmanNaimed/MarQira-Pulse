<?php

use App\Http\Controllers\Api\Auth\AccountSetupController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Dashboard\AccountController;
use App\Http\Controllers\Api\Dashboard\ApiTokenController;
use App\Http\Controllers\Api\Dashboard\AuditLogController;
use App\Http\Controllers\Api\Dashboard\EnrollmentTokenController;
use App\Http\Controllers\Api\Dashboard\OverviewController;
use App\Http\Controllers\Api\Dashboard\SettingsController;
use App\Http\Controllers\Api\Dashboard\SiteController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\SiteOriginController;
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
// Public account setup (invited Subscriber chooses their own password)
// ---------------------------------------------------------------------------
// Unauthenticated but protected by a single-use, expiring, hashed token. Rate
// limited to blunt token guessing.
Route::middleware(['throttle:login'])->group(function () {
    Route::get('/account-setup/{token}', [AccountSetupController::class, 'show']);
    Route::post('/account-setup/{token}', [AccountSetupController::class, 'store']);
});

// ---------------------------------------------------------------------------
// Plugin: HMAC-authenticated routes
// ---------------------------------------------------------------------------
Route::middleware(['hmac.auth'])->prefix('v1')->group(function () {
    Route::post('/heartbeat', [HeartbeatController::class, 'receive']);
    Route::get('/config/allowed-ips', [ConfigController::class, 'allowedIps'])->name('config.allowed-ips');
    
    // Increment 5: WordPress data collection endpoints
    Route::post('/sites/users', [App\Http\Controllers\Api\V1\SiteDataController::class, 'receiveUsers']);
    Route::post('/sites/posts', [App\Http\Controllers\Api\V1\SiteDataController::class, 'receivePosts']);
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
        Route::get('/sites/{uuid}/users', [SiteController::class, 'users']);
        Route::get('/sites/{uuid}/posts', [SiteController::class, 'posts']);
        
        // Phase 6: Origin IP management
        Route::get('/sites/{uuid}/origin/history', [SiteOriginController::class, 'history']);
        Route::post('/sites/{uuid}/origin/verify', [SiteOriginController::class, 'verify']);
        Route::patch('/sites/{uuid}/origin/confidence', [SiteOriginController::class, 'updateConfidence']);
        
        // Remove Website (soft-revoke; connector self-disconnects). Owner may
        // remove any site, Subscriber only their own — enforced by SitePolicy.
        Route::delete('/sites/{uuid}', [SiteController::class, 'destroy']);

        // Account management (platform Owner only). The `owner` middleware
        // returns 403 for Subscribers before any handler runs.
        Route::middleware('owner')->group(function () {
            Route::get('/accounts', [AccountController::class, 'index']);
            Route::post('/accounts', [AccountController::class, 'store']);
            Route::get('/accounts/{uuid}/sites', [AccountController::class, 'sites']);
            Route::post('/accounts/{uuid}/activate', [AccountController::class, 'activate']);
            Route::post('/accounts/{uuid}/deactivate', [AccountController::class, 'deactivate']);
            Route::post('/accounts/{uuid}/resend-setup', [AccountController::class, 'resendSetup']);
        });

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
