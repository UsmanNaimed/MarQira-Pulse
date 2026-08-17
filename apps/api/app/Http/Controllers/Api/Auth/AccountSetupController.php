<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Public account setup: an invited Subscriber follows a single-use, expiring
 * link to choose their own password (§3 / §22). No password is ever emailed.
 *
 * These routes are unauthenticated (the caller has no account password yet) but
 * are protected by the opaque, hashed, single-use token.
 */
class AccountSetupController extends Controller
{
    /**
     * GET /api/account-setup/{token}
     *
     * Validate a setup token without consuming it, so the frontend can decide
     * whether to render the "choose your password" form.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->resolveValid($token);

        if (! $invitation) {
            return response()->json([
                'valid' => false,
                'error' => 'This setup link is invalid or has expired.',
            ], 404);
        }

        return response()->json([
            'valid' => true,
            'name' => $invitation->user->name,
            'email' => $invitation->user->email,
        ]);
    }

    /**
     * POST /api/account-setup/{token}
     *
     * Consume the token and set the account password. Confirmed password,
     * minimum 12 characters (matches admin-creation policy).
     */
    public function store(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $invitation = $this->resolveValid($token);

        if (! $invitation) {
            return response()->json([
                'valid' => false,
                'error' => 'This setup link is invalid or has expired.',
            ], 404);
        }

        DB::transaction(function () use ($invitation, $request) {
            $user = $invitation->user;
            $user->update([
                'password' => Hash::make($request->input('password')),
                'is_active' => true,
            ]);

            $invitation->update(['used_at' => now()]);

            AuditLog::record([
                'organization_id' => $user->primaryOrganization()?->id,
                'actor_id' => $user->id,
                'actor_type' => 'user',
                'event' => 'account.setup_completed',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'subject_uuid' => $user->uuid,
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'message' => 'Password set. You can now sign in.',
        ]);
    }

    /**
     * Resolve a still-valid invitation by raw token, or null.
     */
    private function resolveValid(string $token): ?AccountInvitation
    {
        $invitation = AccountInvitation::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation || ! $invitation->isValid() || $invitation->user === null) {
            return null;
        }

        return $invitation;
    }
}
