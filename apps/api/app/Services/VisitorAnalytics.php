<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteVisitorMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Visitor analytics service.
 *
 * Phase 8 — Computes visitor trends, growth percentages, and period totals
 * from daily aggregated visitor metrics.
 */
class VisitorAnalytics
{
    /**
     * Get 7-day visitor trend for a site (for sparkline/mini-chart).
     *
     * Returns an array of daily visitor counts for the last 7 days (oldest
     * first), with 0 for days with no data.
     *
     * @param Site $site
     * @return array [int, int, ...] (7 elements)
     */
    public static function get7DayTrend(Site $site): array
    {
        $endDate = Carbon::today();
        $startDate = $endDate->copy()->subDays(6);

        $metrics = SiteVisitorMetric::query()
            ->where('site_id', $site->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->pluck('unique_visitors', 'date')
            ->toArray();

        // Fill in missing days with 0.
        $trend = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $startDate->copy()->addDays($i)->toDateString();
            $trend[] = $metrics[$date] ?? 0;
        }

        return $trend;
    }

    /**
     * Get total visitors for a site over the last N days.
     *
     * @param Site $site
     * @param int $days
     * @return int
     */
    public static function getTotalVisitors(Site $site, int $days = 30): int
    {
        $startDate = Carbon::today()->subDays($days - 1);

        return (int) SiteVisitorMetric::query()
            ->where('site_id', $site->id)
            ->where('date', '>=', $startDate)
            ->sum('unique_visitors');
    }

    /**
     * Get visitor growth percentage for a site (compares last 7d vs previous 7d).
     *
     * Returns a percentage (positive = growth, negative = decline).
     * Returns 0 if no data for either period.
     *
     * @param Site $site
     * @return float
     */
    public static function getGrowthPercentage(Site $site): float
    {
        $today = Carbon::today();

        // Last 7 days.
        $currentStart = $today->copy()->subDays(6);
        $current = (int) SiteVisitorMetric::query()
            ->where('site_id', $site->id)
            ->whereBetween('date', [$currentStart, $today])
            ->sum('unique_visitors');

        // Previous 7 days.
        $previousEnd = $currentStart->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays(6);
        $previous = (int) SiteVisitorMetric::query()
            ->where('site_id', $site->id)
            ->whereBetween('date', [$previousStart, $previousEnd])
            ->sum('unique_visitors');

        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Get daily visitor metrics for a site over the last N days (for charts).
     *
     * Returns an array of ['date' => 'YYYY-MM-DD', 'visitors' => int, 'pageviews' => int].
     *
     * @param Site $site
     * @param int $days
     * @return array
     */
    public static function getDailyMetrics(Site $site, int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);

        return SiteVisitorMetric::query()
            ->where('site_id', $site->id)
            ->where('date', '>=', $startDate)
            ->orderBy('date')
            ->get(['date', 'unique_visitors as visitors', 'pageviews'])
            ->map(fn($m) => [
                'date' => $m->date->toDateString(),
                'visitors' => $m->visitors,
                'pageviews' => $m->pageviews,
            ])
            ->toArray();
    }

    /**
     * Get organization-wide visitor total for the last N days.
     *
     * @param int $organizationId
     * @param int $days
     * @return int
     */
    public static function getOrganizationTotal(int $organizationId, int $days = 7): int
    {
        $startDate = Carbon::today()->subDays($days - 1);

        return (int) SiteVisitorMetric::query()
            ->where('organization_id', $organizationId)
            ->where('date', '>=', $startDate)
            ->sum('unique_visitors');
    }

    /**
     * Get top sites by visitor count for an organization (last 7 days).
     *
     * Returns an array of ['site_id' => int, 'domain' => string, 'visitors' => int].
     *
     * @param int $organizationId
     * @param int $limit
     * @return array
     */
    public static function getTopSites(int $organizationId, int $limit = 5): array
    {
        $startDate = Carbon::today()->subDays(6);

        return DB::table('site_visitor_metrics')
            ->join('sites', 'site_visitor_metrics.site_id', '=', 'sites.id')
            ->where('site_visitor_metrics.organization_id', $organizationId)
            ->where('site_visitor_metrics.date', '>=', $startDate)
            ->select('sites.id as site_id', 'sites.domain', DB::raw('SUM(site_visitor_metrics.unique_visitors) as visitors'))
            ->groupBy('sites.id', 'sites.domain')
            ->orderByDesc('visitors')
            ->limit($limit)
            ->get()
            ->map(fn($row) => [
                'site_id' => $row->site_id,
                'domain' => $row->domain,
                'visitors' => (int) $row->visitors,
            ])
            ->toArray();
    }
}
