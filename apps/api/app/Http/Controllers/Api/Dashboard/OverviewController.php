<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Concerns\ScopesToAccount;
use App\Http\Controllers\Controller;
use App\Models\PluginRelease;
use App\Services\FleetAnalytics;
use App\Services\TenantContext;
use App\Services\VisitorAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Overview / dashboard summary cards.
 *
 * All counts are scoped to the current tenant AND the viewer's authorized
 * websites via the shared ScopesToAccount trait (visibleTo + optional
 * owner-selected account). TenantContext fails closed, so a missing context
 * throws rather than leaking cross-tenant data. Every card — including the
 * visitor total — is derived from the SAME scoped site set, so no card can leak
 * another account's data (see §8/§9/§10).
 */
class OverviewController extends Controller
{
    use ScopesToAccount;

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/overview
     */
    public function index(Request $request): JsonResponse
    {
        // Single scoped set of authorized site ids drives every card. Cloning
        // the builder per aggregate keeps each count independent.
        $base = fn () => (clone $this->scopedSitesQuery($request));

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

        // "Updates available": sites with any pending core/plugin/theme update
        // as of their last reported inventory (§13) — the same source the
        // Websites list and Updates tab use.
        $updatesAvailable = $base()
            ->where(function ($q) {
                $q->where('core_update_available', true)
                    ->orWhere('plugin_updates_count', '>', 0)
                    ->orWhere('theme_updates_count', '>', 0);
            })
            ->count();

        // Phase 8 — Visitor analytics: 7-day total scoped to EXACTLY the
        // authorized sites above (never organization-wide). This is the fix for
        // the cross-account visitor overlap (§8).
        $siteIds = $base()->pluck('id')->all();
        $visitors7d = VisitorAnalytics::getTotalForSiteIds($siteIds, 7);

        // Redesign trends — all derived from the SAME scoped site set, so no
        // trend can leak another account's data. Every value is real (or an
        // honest null when there is no data yet).
        $sitesForUptime = $base()->get(['id', 'enrolled_at', 'created_at']);
        $uptime7d = FleetAnalytics::uptime(
            $sitesForUptime->pluck('id')->all(),
            $sitesForUptime,
            7
        );

        // New sites enrolled since the start of the current month. Falls back
        // to created_at for legacy rows without an explicit enrolled_at.
        $monthStart = Carbon::now()->startOfMonth();
        $sitesAddedThisMonth = $base()
            ->where(function ($q) use ($monthStart) {
                $q->where('enrolled_at', '>=', $monthStart)
                    ->orWhere(function ($q2) use ($monthStart) {
                        $q2->whereNull('enrolled_at')
                            ->where('created_at', '>=', $monthStart);
                    });
            })
            ->count();

        $trends = [
            'sites_added_this_month' => $sitesAddedThisMonth,
            'uptime_7d_pct' => $uptime7d['average'],
            'updates_breakdown' => [
                'core' => $base()->where('core_update_available', true)->count(),
                'plugins' => $base()->where('plugin_updates_count', '>', 0)->count(),
                'themes' => $base()->where('theme_updates_count', '>', 0)->count(),
            ],
        ];

        // Latest connector version now comes from the published release registry
        // (Phase 7 shipped), falling back to config only if nothing is published.
        $activeRelease = PluginRelease::getActive();
        $latestPluginVersion = $activeRelease?->version
            ?? config('marqira.plugin.latest_version');

        return response()->json([
            'cards' => [
                'total' => $total,
                'online' => $online,
                'offline' => $offline,
                'needs_attention' => $needsAttention,
                'updates_available' => $updatesAvailable,
                'visitors_7d' => $visitors7d,
            ],
            'trends' => $trends,
            'latest_plugin_version' => $latestPluginVersion,
            // Download surface for the "Download Latest Plugin" action (§11).
            // Null when no release has been published yet.
            'latest_plugin_download_url' => $activeRelease?->download_url,
        ]);
    }
}
