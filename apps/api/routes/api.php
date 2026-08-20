<?php

use App\Http\Controllers\Api\Auth\AccountSetupController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Dashboard\AccountController;
use App\Http\Controllers\Api\Dashboard\ApiTokenController;
use App\Http\Controllers\Api\Dashboard\AuditLogController;
use App\Http\Controllers\Api\Dashboard\EnrollmentTokenController;
use App\Http\Controllers\Api\Dashboard\FleetController;
use App\Http\Controllers\Api\Dashboard\OverviewController;
use App\Http\Controllers\Api\Dashboard\PluginReleaseController;
use App\Http\Controllers\Api\Dashboard\SettingsController;
use App\Http\Controllers\Api\Dashboard\SiteController;
use App\Http\Controllers\Api\Dashboard\SiteUserController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\PluginUpdateController;
use App\Http\Controllers\Api\V1\SiteOriginController;
use App\Http\Controllers\Api\V1\UpdateCommandController;
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
// Phase 7: Plugin Update Server (public, throttled)
// ---------------------------------------------------------------------------
Route::middleware(['throttle:60,1'])->prefix('v1/plugin')->group(function () {
    Route::get('/update-check', [PluginUpdateController::class, 'check']);
    Route::get('/info', [PluginUpdateController::class, 'info']);
    Route::get('/download', [PluginUpdateController::class, 'download']);
    Route::get('/releases/{id}/download', [PluginUpdateController::class, 'downloadById']);
});

// ---------------------------------------------------------------------------
// External automation API (bearer API tokens — §12/§13)
// ---------------------------------------------------------------------------
// Read-only website access for API-token clients (e.g. n8n). The `token.auth`
// guard resolves the raw bearer token to its owning user + organization and
// establishes tenant context; every handler then scopes with visibleTo($user),
// so a token can only ever reach the websites/analytics its user is authorized
// for — a manipulated UUID yields a 404, never another tenant's data. Abilities
// are enforced per-route.
Route::middleware(['throttle:60,1', 'token.auth'])->prefix('v1/external')->group(function () {
    Route::get('/sites', [\App\Http\Controllers\Api\External\SiteController::class, 'index'])
        ->middleware('token.ability:sites:read');
    Route::get('/sites/{uuid}', [\App\Http\Controllers\Api\External\SiteController::class, 'show'])
        ->middleware('token.ability:sites:read');
    Route::get('/sites/{uuid}/visitors', [\App\Http\Controllers\Api\External\SiteController::class, 'visitors'])
        ->middleware('token.ability:sites:read');
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

    // Phase 7: remote update command acknowledgement (connector reports the
    // outcome of an "update this site now" command it received via heartbeat).
    Route::post('/update-command/ack', [UpdateCommandController::class, 'ack']);

    // Explicit connector lifecycle signal (online on activation / offline on
    // deactivation) so the dashboard reflects the connector's real state
    // immediately instead of waiting for the heartbeat-timeout watchdog.
    Route::post('/site-status', [App\Http\Controllers\Api\V1\SiteStatusController::class, 'update']);
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

        // Fleet-level analytics (redesign). Available to every scoped viewer;
        // the controller constrains results to the caller's authorized sites.
        Route::get('/fleet/uptime', [FleetController::class, 'uptime']);
        Route::get('/fleet/rollout', [FleetController::class, 'rollout']);

        // Websites
        Route::get('/sites', [SiteController::class, 'index']);
        // Literal segment — must precede the /sites/{uuid} routes so it is not
        // captured as a uuid.
        Route::post('/sites/reset-uptime', [SiteController::class, 'resetUptime']);
        Route::get('/sites/{uuid}', [SiteController::class, 'show']);
        Route::get('/sites/{uuid}/heartbeats', [SiteController::class, 'heartbeats']);
        Route::get('/sites/{uuid}/users', [SiteController::class, 'users']);
        Route::get('/sites/{uuid}/posts', [SiteController::class, 'posts']);
        Route::get('/sites/{uuid}/visitors', [SiteController::class, 'visitors']);
        Route::get('/sites/{uuid}/update-status', [SiteController::class, 'updateStatus']);
        Route::post('/sites/{uuid}/request-update', [SiteController::class, 'requestUpdate']);

        // Phase C: Full WordPress user management (live CRUD via signed
        // connector proxy). Bulk create across sites is a literal path and must
        // precede the /sites/{uuid} scoped routes so it is not captured as a
        // uuid segment.
        Route::post('/wp-users/bulk-create', [SiteUserController::class, 'bulkCreate']);
        Route::get('/sites/{uuid}/wp-roles', [SiteUserController::class, 'roles']);
        // Literal segment before {id} so it is not captured as a numeric id.
        Route::get('/sites/{uuid}/wp-users/reassign-candidates', [SiteUserController::class, 'reassignCandidates']);
        Route::get('/sites/{uuid}/wp-users', [SiteUserController::class, 'index']);
        Route::post('/sites/{uuid}/wp-users', [SiteUserController::class, 'store']);
        Route::get('/sites/{uuid}/wp-users/{id}', [SiteUserController::class, 'show'])->whereNumber('id');
        Route::match(['put', 'patch'], '/sites/{uuid}/wp-users/{id}', [SiteUserController::class, 'update'])->whereNumber('id');
        Route::delete('/sites/{uuid}/wp-users/{id}', [SiteUserController::class, 'destroy'])->whereNumber('id');

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
            Route::get('/accounts/{uuid}', [AccountController::class, 'show']);
            Route::patch('/accounts/{uuid}', [AccountController::class, 'update']);
            Route::get('/accounts/{uuid}/sites', [AccountController::class, 'sites']);
            Route::post('/accounts/{uuid}/activate', [AccountController::class, 'activate']);
            Route::post('/accounts/{uuid}/deactivate', [AccountController::class, 'deactivate']);
            Route::post('/accounts/{uuid}/resend-setup', [AccountController::class, 'resendSetup']);
            
            // Phase 7: Plugin release management
            Route::get('/plugin-releases', [PluginReleaseController::class, 'index']);
            Route::post('/plugin-releases', [PluginReleaseController::class, 'store']);
            Route::post('/plugin-releases/{id}/activate', [PluginReleaseController::class, 'activate']);
            Route::delete('/plugin-releases/{id}', [PluginReleaseController::class, 'destroy']);
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
