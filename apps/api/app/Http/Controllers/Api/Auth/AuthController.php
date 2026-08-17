<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard authentication (Sanctum SPA / stateful session cookie).
 *
 * Security posture (see §19):
 *  - No hardcoded credentials; the first admin is created via
 *    `php artisan marqira:create-admin`.
 *  - Laravel password hashing; secure, HTTP-only session cookies; CSRF handled
 *    by Sanctum's stateful middleware; login is rate limited (see the "login"
 *    limiter in AppServiceProvider). Passwords are never logged.
 */
class AuthController extends Controller
{
    /**
     * Log a user in and start a stateful session.
     *
     * POST /login
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = (bool) $request->boolean('remember');

        // Explicitly use the "web" (session) guard. Login is session-based, and
        // being explicit keeps it correct even if the default guard has been
        // switched to a token guard earlier in the request lifecycle. On success
        // Laravel sets the authenticated user on the session; we then regenerate
        // the session id to prevent session fixation.
        if (! Auth::guard('web')->attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $user = Auth::guard('web')->user();

        // Deactivated accounts are blocked from establishing a session. We log
        // them straight back out so no authenticated session is left behind.
        if (! $user->isActive()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Please contact your administrator.'],
            ]);
        }

        $request->session()->regenerate();

        // Track last successful login for the Owner's Users dashboard (§5).
        $user->forceFill(['last_login_at' => now()])->save();

        $organization = $user->primaryOrganization();

        // Record an audit entry (never store the password or any secret).
        if ($organization) {
            AuditLog::record([
                'organization_id' => $organization->id,
                'actor_id' => $user->id,
                'actor_type' => 'user',
                'event' => 'auth.login',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        }

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Return the currently authenticated user.
     *
     * GET /api/user
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    /**
     * Log the user out and invalidate the session.
     *
     * POST /logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $user?->primaryOrganization();

        if ($user && $organization) {
            AuditLog::record([
                'organization_id' => $organization->id,
                'actor_id' => $user->id,
                'actor_type' => 'user',
                'event' => 'auth.logout',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }
}
