<?php

namespace App\Http\Controllers\Api\External;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use App\Services\TenantContext;
use App\Services\VisitorAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * External automation API (§12/§13) — read-only website access for API-token
 * clients such as n8n.
 *
 * Authentication + tenant context are established by the `token.auth`
 * middleware, and abilities are enforced per-route by `token.ability`. The
 * request's user is the token's OWNING user, so EVERY query here is scoped with
 * `Site::visibleTo($user)` exactly like the dashboard. Consequences:
 *
 *   - A subscriber's token only ever sees that subscriber's websites.
 *   - Manipulating a UUID to point at another tenant's / another subscriber's
 *     site yields a 404 (never that site's data, and never a 403 that would
 *     confirm the site exists).
 *   - The Owner's token sees all websites in the organization, matching the
 *     Owner's dashboard scope.
 */
class SiteController extends Controller
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/v1/external/sites
     *
     * List the websites this token is authorized to see.
     */
    public function index(Request $request): JsonResponse
    {
        $sites = $this->baseQuery($request)
            ->with('owner:id,uuid,name,email')
            ->orderByDesc('last_heartbeat_at')
            ->get();

        return response()->json([
            'data' => SiteResource::collection($sites),
        ]);
    }

    /**
     * GET /api/v1/external/sites/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        return response()->json([
            'data' => (new SiteResource($site->loadMissing('owner:id,uuid,name,email'))),
        ]);
    }

    /**
     * GET /api/v1/external/sites/{uuid}/visitors
     *
     * Visitor analytics for a single site the token is authorized to see.
     */
    public function visitors(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        $days = (int) $request->query('days', 30);
        $days = max(7, min($days, 90));

        return response()->json([
            'daily_metrics' => VisitorAnalytics::getDailyMetrics($site, $days),
            'total_visitors' => VisitorAnalytics::getTotalVisitors($site, $days),
            'growth' => VisitorAnalytics::getGrowthPercentage($site),
        ]);
    }

    /**
     * Base tenant + visibility scoped query. The tenant is the token's
     * organization; visibility is the token user's authorized scope.
     */
    private function baseQuery(Request $request)
    {
        return Site::query()
            ->where('organization_id', $this->tenantContext->organizationId())
            ->visibleTo($request->user())
            ->active();
    }

    /**
     * Resolve a site within the token's tenant AND visibility scope, or 404.
     * The `visibleTo` clause is what prevents cross-tenant / cross-subscriber
     * access via a manipulated UUID.
     */
    private function findSiteOrFail(Request $request, string $uuid): Site
    {
        return $this->baseQuery($request)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
