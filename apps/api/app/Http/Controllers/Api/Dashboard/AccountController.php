<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Owner-only account management (§3 / §22).
 *
 * The platform Owner creates and manages Subscriber accounts. Subscribers are
 * scoped to the Owner's organization and can only ever see/act on the websites
 * they own. Every route in this controller sits behind the `owner` middleware,
 * so the Owner check is enforced before any handler runs; we still resolve the
 * tenant from TenantContext so accounts stay organization-scoped.
 *
 * Passwords are never generated-and-emailed. New Subscribers receive a
 * single-use, expiring setup link and choose their own password.
 */
class AccountController extends Controller
{
    /** How long an account setup / invitation link stays valid. */
    private const INVITATION_TTL_HOURS = 48;

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/accounts
     *
     * List the Subscriber accounts in this organization with a live count of
     * the websites each one owns.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();

        $memberUserIds = OrganizationMembership::query()
            ->where('organization_id', $orgId)
            ->pluck('user_id');

        $users = User::query()
            ->whereIn('id', $memberUserIds)
            ->where('platform_role', User::ROLE_SUBSCRIBER)
            ->orderBy('name')
            ->get();

        $siteCounts = Site::query()
            ->where('organization_id', $orgId)
            ->whereNull('revoked_at')
            ->whereIn('owner_user_id', $users->pluck('id'))
            ->selectRaw('owner_user_id, COUNT(*) as c')
            ->groupBy('owner_user_id')
            ->pluck('c', 'owner_user_id');

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'uuid' => $u->uuid,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->isActive(),
                'site_count' => (int) ($siteCounts[$u->id] ?? 0),
                'created_at' => $u->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * POST /api/dashboard/accounts
     *
     * Create a Subscriber account. The account starts active but with an
     * unusable random password; a single-use setup link (returned as
     * `setup_url`) lets the Subscriber choose their own password.
     */
    public function store(Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);

        $result = DB::transaction(function () use ($data, $orgId, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                // Unusable password until the invitee completes setup. A random
                // 48-byte value that is never shown to anyone.
                'password' => Hash::make(Str::random(64)),
                'platform_role' => User::ROLE_SUBSCRIBER,
                'is_active' => true,
            ]);

            OrganizationMembership::create([
                'organization_id' => $orgId,
                'user_id' => $user->id,
                'role' => 'member',
            ]);

            $setupUrl = $this->issueInvitation($user, $request->user()->id);

            AuditLog::record([
                'organization_id' => $orgId,
                'actor_id' => $request->user()->id,
                'actor_type' => 'user',
                'event' => 'account.created',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'subject_uuid' => $user->uuid,
                'ip_address' => $request->ip(),
                'metadata' => ['email' => $user->email],
            ]);

            return [$user, $setupUrl];
        });

        [$user, $setupUrl] = $result;

        return response()->json([
            'data' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => true,
                'site_count' => 0,
            ],
            // Returned so the Owner can share it now. Once email delivery is
            // wired up (Phase 3) the same link is also emailed to the invitee.
            'setup_url' => $setupUrl,
        ], 201);
    }

    /**
     * POST /api/dashboard/accounts/{uuid}/deactivate
     *
     * Block a Subscriber from logging in. Their sites keep being monitored; the
     * account simply cannot authenticate until reactivated.
     */
    public function deactivate(Request $request, string $uuid): JsonResponse
    {
        $user = $this->findSubscriberOrFail($uuid);

        $user->update(['is_active' => false]);

        AuditLog::record([
            'organization_id' => $this->tenantContext->organizationId(),
            'actor_id' => $request->user()->id,
            'actor_type' => 'user',
            'event' => 'account.deactivated',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'subject_uuid' => $user->uuid,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Account deactivated.', 'is_active' => false]);
    }

    /**
     * POST /api/dashboard/accounts/{uuid}/activate
     */
    public function activate(Request $request, string $uuid): JsonResponse
    {
        $user = $this->findSubscriberOrFail($uuid);

        $user->update(['is_active' => true]);

        AuditLog::record([
            'organization_id' => $this->tenantContext->organizationId(),
            'actor_id' => $request->user()->id,
            'actor_type' => 'user',
            'event' => 'account.activated',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'subject_uuid' => $user->uuid,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Account activated.', 'is_active' => true]);
    }

    /**
     * POST /api/dashboard/accounts/{uuid}/resend-setup
     *
     * Re-issue a fresh setup link (invalidating any prior unused ones).
     */
    public function resendSetup(Request $request, string $uuid): JsonResponse
    {
        $user = $this->findSubscriberOrFail($uuid);

        $setupUrl = DB::transaction(function () use ($user, $request) {
            // Invalidate outstanding invitations so only the newest link works.
            AccountInvitation::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            return $this->issueInvitation($user, $request->user()->id);
        });

        AuditLog::record([
            'organization_id' => $this->tenantContext->organizationId(),
            'actor_id' => $request->user()->id,
            'actor_type' => 'user',
            'event' => 'account.setup_resent',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'subject_uuid' => $user->uuid,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['setup_url' => $setupUrl]);
    }

    /**
     * GET /api/dashboard/accounts/{uuid}/sites
     *
     * List the websites owned by a given Subscriber.
     */
    public function sites(Request $request, string $uuid): JsonResponse
    {
        $user = $this->findSubscriberOrFail($uuid);

        $sites = Site::query()
            ->where('organization_id', $this->tenantContext->organizationId())
            ->where('owner_user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_heartbeat_at')
            ->get(['uuid', 'domain', 'status', 'last_heartbeat_at']);

        return response()->json([
            'data' => $sites->map(fn (Site $s) => [
                'uuid' => $s->uuid,
                'domain' => $s->domain,
                'status' => $s->status,
                'last_heartbeat_at' => $s->last_heartbeat_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Generate a single-use invitation and return the absolute setup URL. Only
     * the token hash is persisted; the raw token lives solely in the URL.
     */
    private function issueInvitation(User $user, int $createdBy): string
    {
        $rawToken = Str::random(64);

        AccountInvitation::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHours(self::INVITATION_TTL_HOURS),
            'created_by' => $createdBy,
        ]);

        return rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/account-setup/' . $rawToken;
    }

    /**
     * Resolve a Subscriber in the current organization by uuid, or 404. Never
     * matches the Owner's own account or users from another organization.
     */
    private function findSubscriberOrFail(string $uuid): User
    {
        $orgId = $this->tenantContext->organizationId();

        $memberUserIds = OrganizationMembership::query()
            ->where('organization_id', $orgId)
            ->pluck('user_id');

        return User::query()
            ->whereIn('id', $memberUserIds)
            ->where('platform_role', User::ROLE_SUBSCRIBER)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
