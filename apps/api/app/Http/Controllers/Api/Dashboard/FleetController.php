<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Concerns\ScopesToAccount;
use App\Http\Controllers\Controller;
use App\Models\PluginRelease;
use App\Services\FleetAnalytics;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fleet-level analytics for the redesigned dashboard.
 *
 * Every endpoint derives its numbers from data the platform ALREADY collects
 * (site_heartbeats for uptime, sites.plugin_version for connector rollout) and
 * is constrained to the viewer's authorized website set via ScopesToAccount —
 * so a Subscriber can only ever see their own sites and an Owner's optional
 * ?account=<uuid> filter narrows (never widens) the scope. No fabricated or
 * sample data is produced anywhere; empty tenants get honest "no data" shapes.
 */
class FleetController extends Controller
{
    use ScopesToAccount;

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/fleet/uptime?range=7|30|90
     *
     * Daily fleet availability: share of enrolled sites that reported at least
     * one heartbeat each day. Scoped to the authorized site set.
     */
    public function uptime(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => 'sometimes|integer|in:7,30,90',
        ]);
        $range = (int) ($validated['range'] ?? 7);

        // enrolled_at / created_at drive the per-day denominator inside the
        // service; id drives the heartbeat lookup. Both come from the SAME
        // scoped query so the service can never widen authorization.
        $sites = (clone $this->scopedSitesQuery($request))
            ->get(['id', 'enrolled_at', 'created_at']);
        $siteIds = $sites->pluck('id')->all();

        $result = FleetAnalytics::uptime($siteIds, $sites, $range);

        return response()->json([
            'range' => $range,
            'has_data' => $result['has_data'],
            'average_uptime_pct' => $result['average'],
            'series' => $result['series'],
        ]);
    }

    /**
     * GET /api/dashboard/fleet/rollout
     *
     * Connector version distribution across the authorized fleet — how many
     * sites run each plugin_version, which is the active/latest published
     * release, and how many are not reporting a version at all.
     */
    public function rollout(Request $request): JsonResponse
    {
        $sites = (clone $this->scopedSitesQuery($request))->get(['plugin_version']);

        $total = $sites->count();
        $notReporting = 0;
        $counts = [];
        foreach ($sites as $site) {
            $version = trim((string) ($site->plugin_version ?? ''));
            if ($version === '') {
                $notReporting++;

                continue;
            }
            $counts[$version] = ($counts[$version] ?? 0) + 1;
        }

        $activeVersion = PluginRelease::getActive()?->version;

        // Sort versions newest-first for a stable, human-friendly ordering.
        uksort($counts, fn ($a, $b) => version_compare($b, $a));

        $versions = [];
        foreach ($counts as $version => $count) {
            $versions[] = [
                'version' => $version,
                'count' => $count,
                'is_latest' => $activeVersion !== null && $version === $activeVersion,
            ];
        }

        $onLatest = 0;
        if ($activeVersion !== null) {
            $onLatest = $counts[$activeVersion] ?? 0;
        }

        return response()->json([
            'active_version' => $activeVersion,
            'total' => $total,
            'on_latest' => $onLatest,
            'not_reporting' => $notReporting,
            'versions' => $versions,
        ]);
    }
}
