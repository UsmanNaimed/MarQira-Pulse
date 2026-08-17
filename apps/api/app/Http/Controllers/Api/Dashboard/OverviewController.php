<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Overview / dashboard summary cards.
 *
 * All counts are scoped to the current tenant via TenantContext (set by the
 * "tenant" middleware). TenantContext fails closed, so a missing context throws
 * rather than leaking cross-tenant data.
 */
class OverviewController extends Controller
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/overview
     */
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();
        $user = $request->user();

        // Owner sees every site on the platform; Subscriber sees only owned
        // sites. Revoked sites never count toward the active cards.
        $base = fn () => Site::query()
            ->where('organization_id', $orgId)
            ->visibleTo($user)
            ->active();

        $total = $base()->count();
        $online = $base()->where('status', 'online')->count();
        $offline = $base()->where('status', 'offline')->count();

        // "Needs attention": we cannot reliably reach the origin — either no
        // origin IP has been determined yet, or it has not been verified. This
        // is the platform's core value (see §16), so we surface it prominently.
        $needsAttention = $base()
            ->where(function ($q) {
                $q->whereNull('origin_ip')
                    ->orWhere('origin_ip_verified', false);
            })
            ->count();

        // "Updates available": sites running an older connector than the current
        // release. Until Phase 7 ships a release registry, the latest version is
        // read from config and may be null (→ 0 updates available).
        $latestPluginVersion = config('marqira.plugin.latest_version');
        $updatesAvailable = 0;

        if (! empty($latestPluginVersion)) {
            $updatesAvailable = $base()
                ->whereNotNull('plugin_version')
                ->get(['plugin_version'])
                ->filter(fn ($site) => version_compare($site->plugin_version, $latestPluginVersion, '<'))
                ->count();
        }

        return response()->json([
            'cards' => [
                'total' => $total,
                'online' => $online,
                'offline' => $offline,
                'needs_attention' => $needsAttention,
                'updates_available' => $updatesAvailable,
            ],
            'latest_plugin_version' => $latestPluginVersion,
        ]);
    }
}
