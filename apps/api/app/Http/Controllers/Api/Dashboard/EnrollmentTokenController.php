<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentTokenResource;
use App\Models\AuditLog;
use App\Models\EnrollmentToken;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dashboard management of enrollment tokens (connection codes).
 *
 * Tenant-scoped via TenantContext and attributed to the authenticated user.
 */
class EnrollmentTokenController extends Controller
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/enrollment-tokens
     */
    public function index(): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();

        $tokens = EnrollmentToken::query()
            ->where('organization_id', $orgId)
            ->with('usedBySite:id,uuid,domain')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => EnrollmentTokenResource::collection($tokens),
        ]);
    }

    /**
     * POST /api/dashboard/enrollment-tokens
     *
     * Generates a single-use connection code. The raw code is returned exactly
     * once; only its SHA-256 hash is stored.
     */
    public function store(Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();

        // Per-org hourly rate limit.
        $maxPerHour = (int) config('marqira.enrollment_token.max_per_org_per_hour', 10);
        $recent = EnrollmentToken::query()
            ->where('organization_id', $orgId)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($recent >= $maxPerHour) {
            return response()->json([
                'error' => "Rate limit exceeded. Maximum {$maxPerHour} connection codes per hour.",
            ], 429);
        }

        $rawToken = 'MQ-CONNECT-'.strtoupper(Str::random(16));
        $expiryMinutes = (int) config('marqira.enrollment_token.expiry_minutes', 30);

        $token = EnrollmentToken::create([
            'organization_id' => $orgId,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes($expiryMinutes),
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record([
            'organization_id' => $orgId,
            'actor_id' => $request->user()->id,
            'actor_type' => 'user',
            'event' => 'enrollment_token.created',
            'subject_type' => 'enrollment_token',
            'subject_uuid' => $token->uuid,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'token' => $rawToken, // shown once — never stored or returned again
            'expires_at' => $token->expires_at->toIso8601String(),
            'expires_in_minutes' => $expiryMinutes,
        ], 201);
    }
}
