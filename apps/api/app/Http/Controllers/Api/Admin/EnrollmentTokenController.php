<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentToken;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin controller for managing enrollment tokens.
 *
 * Used by the dashboard (Phase 5) to generate connection codes.
 */
class EnrollmentTokenController extends Controller
{
    /**
     * Generate a new enrollment token.
     *
     * POST /api/admin/enrollment-tokens
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        // For Phase 4: use first organization (single-tenant mode)
        // Phase 5 will add proper auth and multi-tenant support
        $organization = Organization::first();

        if (!$organization) {
            return response()->json([
                'error' => 'No organization found. Run php artisan marqira:create-admin first.',
            ], 500);
        }

        // Rate limit check: max 10 tokens per org per hour
        $oneHourAgo = now()->subHour();
        $recentTokens = EnrollmentToken::where('organization_id', $organization->id)
            ->where('created_at', '>', $oneHourAgo)
            ->count();

        $maxPerHour = config('marqira.enrollment_token.max_per_org_per_hour', 10);

        if ($recentTokens >= $maxPerHour) {
            return response()->json([
                'error' => 'Rate limit exceeded. Maximum ' . $maxPerHour . ' tokens per hour.',
            ], 429);
        }

        // Generate token: MQ-CONNECT-{16 uppercase alphanumeric}
        $rawToken = 'MQ-CONNECT-' . strtoupper(Str::random(16));
        $tokenHash = hash('sha256', $rawToken);

        $expiryMinutes = config('marqira.enrollment_token.expiry_minutes', 30);

        $enrollmentToken = EnrollmentToken::create([
            'organization_id' => $organization->id,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addMinutes($expiryMinutes),
            'created_by' => null, // Phase 5 will set authenticated user ID
        ]);

        return response()->json([
            'success' => true,
            'token' => $rawToken, // Only shown once — never stored or returned again
            'expires_at' => $enrollmentToken->expires_at->toIso8601String(),
            'expires_in_minutes' => $expiryMinutes,
        ], 201);
    }

    /**
     * List enrollment tokens for the organization.
     *
     * GET /api/admin/enrollment-tokens
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Phase 4: single org mode
        $organization = Organization::first();

        if (!$organization) {
            return response()->json(['tokens' => []], 200);
        }

        $tokens = EnrollmentToken::where('organization_id', $organization->id)
            ->with(['usedBySite:id,uuid,domain'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($token) {
                return [
                    'uuid' => $token->uuid,
                    'created_at' => $token->created_at->toIso8601String(),
                    'expires_at' => $token->expires_at->toIso8601String(),
                    'is_expired' => $token->isExpired(),
                    'is_used' => $token->isUsed(),
                    'used_at' => $token->used_at?->toIso8601String(),
                    'used_by_site' => $token->usedBySite ? [
                        'uuid' => $token->usedBySite->uuid,
                        'domain' => $token->usedBySite->domain,
                    ] : null,
                ];
            });

        return response()->json([
            'tokens' => $tokens,
        ], 200);
    }
}
