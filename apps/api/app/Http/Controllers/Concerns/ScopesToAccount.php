<?php

namespace App\Http\Controllers\Concerns;

use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared tenant + account scoping for dashboard controllers (§8–§15).
 *
 * Every website query the dashboard runs must be constrained to:
 *   authenticated user  →  authorized websites  →  (optional) selected account
 *
 * This trait is the single place that assembles that constraint so the
 * authorization rule is not re-implemented (and drifted) per controller:
 *
 *  1. organization_id  — the tenant (from TenantContext).
 *  2. visibleTo($user) — the Subscriber sees only owned sites; the Owner sees
 *                        all. This is the enforced security boundary and lives
 *                        on the Site model.
 *  3. account filter   — OWNER-ONLY. Lets the Owner intentionally narrow the
 *                        view to one Subscriber's websites (?account=<uuid>).
 *                        A Subscriber's `account` param is ignored entirely —
 *                        they can never widen their scope this way.
 *
 * Requires the consuming controller to expose a `$this->tenantContext`.
 */
trait ScopesToAccount
{
    /**
     * Base, fully tenant + account scoped query for active (non-revoked) sites
     * the current viewer is authorized to see.
     */
    protected function scopedSitesQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = Site::query()
            ->where('organization_id', $this->tenantContext->organizationId())
            ->visibleTo($user)
            ->active();

        $accountUserId = $this->resolveAccountFilter($request, $user);
        if ($accountUserId !== null) {
            $query->where('owner_user_id', $accountUserId);
        }

        return $query;
    }

    /**
     * Resolve the OWNER-selected account filter to a user id, or null.
     *
     * Returns null (no narrowing — the viewer's full authorized scope) when:
     *  - the viewer is not the Owner (Subscribers cannot select accounts), or
     *  - no account was selected / "all" was chosen, or
     *  - the selected account uuid does not resolve to a Subscriber in this
     *    organization (fail closed — never trust a supplied uuid, see §13).
     */
    protected function resolveAccountFilter(Request $request, User $user): ?int
    {
        if (! $user->isOwner()) {
            return null;
        }

        $uuid = trim((string) $request->query('account', ''));
        if ($uuid === '' || $uuid === 'all') {
            return null;
        }

        $memberUserIds = OrganizationMembership::query()
            ->where('organization_id', $this->tenantContext->organizationId())
            ->pluck('user_id');

        $target = User::query()
            ->whereIn('id', $memberUserIds)
            ->where('uuid', $uuid)
            ->first();

        return $target?->id;
    }
}
