<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Account & organization settings for the current tenant/user.
 */
class SettingsController extends Controller
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/settings
     */
    public function show(Request $request): JsonResponse
    {
        $org = $this->tenantContext->organization();

        return response()->json([
            'user' => new UserResource($request->user()),
            'organization' => [
                'uuid' => $org->uuid,
                'name' => $org->name,
                'slug' => $org->slug,
                'created_at' => $org->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * PATCH /api/dashboard/settings/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->fill($validated);
        $user->save();

        AuditLog::record([
            'organization_id' => $this->tenantContext->organizationId(),
            'actor_id' => $user->id,
            'actor_type' => 'user',
            'event' => 'user.profile_updated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * PATCH /api/dashboard/settings/password
     *
     * Requires the current password. Never logs any password value.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = $validated['password']; // hashed via cast
        $user->save();

        AuditLog::record([
            'organization_id' => $this->tenantContext->organizationId(),
            'actor_id' => $user->id,
            'actor_type' => 'user',
            'event' => 'user.password_changed',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Password updated.']);
    }
}
