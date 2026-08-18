<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiTokenResource;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Management of API tokens used by external automation (e.g. n8n — Phase 8).
 *
 * Security (see §22):
 *  - Raw tokens are CSPRNG-generated, shown exactly once, and stored only as a
 *    SHA-256 hash.
 *  - Tokens carry abilities and an optional CIDR allowlist, and support expiry
 *    and revocation. Enforcement of these happens in the Phase 8 auth guard;
 *    this controller manages their lifecycle.
 */
class ApiTokenController extends Controller
{
    /**
     * Abilities a token may be granted. Kept as a small, explicit whitelist.
     *
     * @var array<int, string>
     */
    private const ABILITIES = [
        'sites:read',
        'sites:read-origin',
        'sites:status',
    ];

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/api-tokens
     */
    public function index(): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();

        $tokens = ApiToken::query()
            ->where('organization_id', $orgId)
            ->with(['createdByUser:id,uuid,name', 'user:id,uuid,name,email'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => ApiTokenResource::collection($tokens),
            'available_abilities' => self::ABILITIES,
        ]);
    }

    /**
     * POST /api/dashboard/api-tokens
     */
    public function store(Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(self::ABILITIES)],
            'allowed_ips' => ['sometimes', 'array'],
            'allowed_ips.*' => ['string', 'max:64'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        // Validate each allowed IP / CIDR entry.
        $allowedIps = [];
        foreach ($validated['allowed_ips'] ?? [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (! $this->isValidIpOrCidr($entry)) {
                return response()->json([
                    'error' => "Invalid IP or CIDR: {$entry}",
                ], 422);
            }
            $allowedIps[] = $entry;
        }

        // Generate a CSPRNG raw token: mq_live_<40 hex chars>.
        $prefix = (string) config('marqira.api_token.prefix', 'mq_live_');
        $rawToken = $prefix.bin2hex(random_bytes(20));

        $token = ApiToken::create([
            'organization_id' => $orgId,
            // Bind the token to the creating user. This is the tenant boundary the
            // external API guard enforces (see §12/§13): the token can only ever
            // reach sites/analytics visible to THIS user, never the whole org.
            'user_id' => $request->user()->id,
            'created_by' => $request->user()->id,
            'name' => $validated['name'],
            'token_hash' => hash('sha256', $rawToken),
            'abilities' => array_values(array_unique($validated['abilities'])),
            'allowed_ips' => $allowedIps,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        AuditLog::record([
            'organization_id' => $orgId,
            'actor_id' => $request->user()->id,
            'actor_type' => 'user',
            'event' => 'api_token.created',
            'subject_type' => 'api_token',
            'subject_uuid' => $token->uuid,
            'ip_address' => $request->ip(),
            'metadata' => ['name' => $token->name, 'abilities' => $token->abilities],
        ]);

        return response()->json([
            'token' => $rawToken, // shown once — store it securely now
            'api_token' => new ApiTokenResource($token),
        ], 201);
    }

    /**
     * DELETE /api/dashboard/api-tokens/{uuid}
     *
     * Revokes (soft-disables) a token. We keep the row for audit purposes.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();

        $token = ApiToken::query()
            ->where('organization_id', $orgId)
            ->where('uuid', $uuid)
            ->firstOrFail();

        if (! $token->revoked_at) {
            $token->revoked_at = now();
            $token->save();

            AuditLog::record([
                'organization_id' => $orgId,
                'actor_id' => $request->user()->id,
                'actor_type' => 'user',
                'event' => 'api_token.revoked',
                'subject_type' => 'api_token',
                'subject_uuid' => $token->uuid,
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json([
            'data' => new ApiTokenResource($token->fresh()),
        ]);
    }

    /**
     * Validate an IPv4/IPv6 address or CIDR range.
     */
    private function isValidIpOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (! str_contains($value, '/')) {
            return false;
        }

        [$ip, $prefix] = explode('/', $value, 2);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (! ctype_digit($prefix)) {
            return false;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;

        return (int) $prefix >= 0 && (int) $prefix <= $max;
    }
}
