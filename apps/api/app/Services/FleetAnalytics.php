<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fleet-level analytics derived entirely from data the platform already
 * collects — no fabricated/sample numbers.
 *
 * "Uptime" for the fleet is defined as availability: on each day, what share of
 * the sites that were enrolled by that day actually reported at least one
 * heartbeat (site_heartbeats). A site that stops phoning home (offline / plugin
 * removed / origin unreachable) drops out of that day's numerator, which is
 * exactly what an operator wants to see. When there are no heartbeats at all
 * (e.g. a brand-new tenant), every day's percentage is null and `has_data` is
 * false so the dashboard shows an honest "no data yet" state instead of a flat
 * fake line.
 *
 * All inputs are the ALREADY tenant + account scoped site set (ids + enrolment
 * timestamps), so this service can never widen the caller's authorized scope.
 */
class FleetAnalytics
{
    /**
     * Daily availability series for the given scoped sites.
     *
     * @param  int[]  $siteIds  authorized site ids (already scoped)
     * @param  Collection  $sites  the same sites with enrolled_at / created_at
     * @return array{series: list<array{date:string,uptime_pct:?float,reporting:int,expected:int}>, has_data: bool, average: ?float}
     */
    public static function uptime(array $siteIds, Collection $sites, int $range): array
    {
        $today = Carbon::now()->startOfDay();

        /** @var list<Carbon> $days */
        $days = [];
        for ($i = $range - 1; $i >= 0; $i--) {
            $days[] = $today->copy()->subDays($i);
        }

        $reportingByDay = [];
        if (! empty($siteIds)) {
            $start = $today->copy()->subDays($range - 1);

            // Driver-aware day bucketing (tests run on sqlite, prod on pgsql).
            $driver = DB::connection()->getDriverName();
            $dateExpr = $driver === 'pgsql'
                ? "to_char(received_at, 'YYYY-MM-DD')"
                : "strftime('%Y-%m-%d', received_at)";

            $reportingByDay = DB::table('site_heartbeats')
                ->select(DB::raw("$dateExpr as d"), DB::raw('COUNT(DISTINCT site_id) as c'))
                ->whereIn('site_id', $siteIds)
                ->where('received_at', '>=', $start)
                ->groupBy('d')
                ->pluck('c', 'd')
                ->all();
        }

        $series = [];
        $hasData = false;
        foreach ($days as $day) {
            $key = $day->format('Y-m-d');
            $reporting = (int) ($reportingByDay[$key] ?? 0);

            // How many sites were enrolled on/before this day — the denominator.
            $expected = $sites->filter(function ($s) use ($day) {
                $enrolled = $s->enrolled_at ?? $s->created_at;

                return $enrolled !== null && Carbon::parse($enrolled)->startOfDay()->lte($day);
            })->count();

            $pct = $expected > 0 ? round(min($reporting, $expected) / $expected * 100, 1) : null;
            if ($reporting > 0) {
                $hasData = true;
            }

            $series[] = [
                'date' => $key,
                'uptime_pct' => $pct,
                'reporting' => $reporting,
                'expected' => $expected,
            ];
        }

        $valid = array_values(array_filter($series, fn ($p) => $p['uptime_pct'] !== null));
        $average = count($valid) > 0
            ? round(array_sum(array_map(fn ($p) => $p['uptime_pct'], $valid)) / count($valid), 1)
            : null;

        return [
            'series' => $series,
            'has_data' => $hasData,
            'average' => $average,
        ];
    }
}
