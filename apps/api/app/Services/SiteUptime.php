<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-site availability ("uptime") analytics, derived entirely from the
 * heartbeats a site has actually sent — no fabricated numbers.
 *
 * A single site's daily uptime is measured with HOURLY resolution: each day is
 * split into hour buckets, and a bucket "counts" when the site delivered at
 * least one heartbeat during it. Daily uptime = covered buckets / expected
 * buckets. This yields realistic percentages (a couple of missed hours reads as
 * ~92%, a full-day outage as 0%) instead of the coarse all-or-nothing a
 * once-per-day definition would give.
 *
 * Expected buckets are bounded by reality:
 *   - a fully-elapsed past day expects 24 hours;
 *   - the current (partial) day expects only the hours elapsed so far;
 *   - the enrolment day expects only the hours from enrolment onward;
 *   - days before the site was enrolled are null (the site did not exist yet)
 *     and are omitted from the trend and the average.
 *
 * When a site has never reported, every day is null and the headline percentage
 * is null so the dashboard can show an honest "no data yet" state.
 */
class SiteUptime
{
    /**
     * Daily uptime series for a single site (oldest first).
     *
     * @return list<array{date:string,uptime_pct:?float}>
     */
    public static function dailySeries(Site $site, int $days = 7): array
    {
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();

        $enrolled = $site->enrolled_at ?? $site->created_at;
        $enrolledAt = $enrolled !== null ? Carbon::parse($enrolled) : null;

        // A manual "Clear 7-Day Uptime" reset moves the measurement floor forward
        // without deleting heartbeats: treat uptime_reset_at like a later
        // enrolment so days/hours before it are unknown and the percentage
        // rebuilds fresh from that instant.
        if ($site->uptime_reset_at !== null) {
            $reset = Carbon::parse($site->uptime_reset_at);
            if ($enrolledAt === null || $reset->gt($enrolledAt)) {
                $enrolledAt = $reset;
            }
        }

        // Count distinct heartbeat hour-buckets per day in one query. Driver-aware
        // bucketing so the same logic works on sqlite (tests) and pgsql (prod).
        $windowStart = $today->copy()->subDays($days - 1);
        $driver = DB::connection()->getDriverName();
        $dayExpr = $driver === 'pgsql'
            ? "to_char(received_at, 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', received_at)";
        $hourExpr = $driver === 'pgsql'
            ? "to_char(received_at, 'YYYY-MM-DD HH24')"
            : "strftime('%Y-%m-%d %H', received_at)";

        $coveredByDay = DB::table('site_heartbeats')
            ->where('site_id', $site->id)
            ->where('received_at', '>=', $windowStart)
            ->select(DB::raw("$dayExpr as d"), DB::raw("COUNT(DISTINCT $hourExpr) as c"))
            ->groupBy('d')
            ->pluck('c', 'd')
            ->all();

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $today->copy()->subDays($i);
            $key = $day->format('Y-m-d');
            $dayEnd = $day->copy()->endOfDay();

            // The site did not exist for any part of this day → unknown.
            if ($enrolledAt !== null && $enrolledAt->gt($dayEnd)) {
                $series[] = ['date' => $key, 'uptime_pct' => null];
                continue;
            }

            // Expected hour buckets for this day, bounded by "now" and enrolment.
            $windowOpen = $day->copy();
            if ($enrolledAt !== null && $enrolledAt->gt($windowOpen)) {
                $windowOpen = $enrolledAt->copy();
            }
            // Only *fully-elapsed* clock hours are "expected". The in-progress
            // current hour is excluded so a site that just enrolled (or the
            // current hour hasn't finished yet) is never unfairly scored 0%.
            $windowClose = $day->copy()->addDay(); // exclusive end of day
            $hourFloorNow = $now->copy()->startOfHour();
            if ($hourFloorNow->lt($windowClose)) {
                $windowClose = $hourFloorNow;
            }

            // No fully-elapsed expected hour yet for this day → unknown, not 0%.
            if ($windowClose->lte($windowOpen)) {
                $series[] = ['date' => $key, 'uptime_pct' => null];
                continue;
            }

            // Carbon 3 returns a *signed* diff; use the absolute elapsed minutes.
            $expected = (int) ceil(abs($windowClose->diffInMinutes($windowOpen)) / 60);
            if ($expected < 1) {
                $series[] = ['date' => $key, 'uptime_pct' => null];
                continue;
            }

            $covered = (int) ($coveredByDay[$key] ?? 0);
            $pct = round(min($covered, $expected) / $expected * 100, 1);
            $series[] = ['date' => $key, 'uptime_pct' => $pct];
        }

        return $series;
    }

    /**
     * Headline N-day uptime for a site: the mean of the days it actually
     * existed, or null when there is nothing to report yet.
     */
    public static function averagePct(Site $site, int $days = 7): ?float
    {
        $valid = array_values(array_filter(
            self::dailySeries($site, $days),
            fn ($p) => $p['uptime_pct'] !== null,
        ));

        if (count($valid) === 0) {
            return null;
        }

        return round(
            array_sum(array_map(fn ($p) => $p['uptime_pct'], $valid)) / count($valid),
            1,
        );
    }

    /**
     * Compact trend for the list sparkline: the daily percentages for the days
     * the site existed (oldest first), null days omitted. Returns [] when the
     * site has never reported, so the UI renders no line rather than a fake one.
     *
     * @return list<float>
     */
    public static function trend(Site $site, int $days = 7): array
    {
        return array_values(array_map(
            fn ($p) => $p['uptime_pct'],
            array_filter(
                self::dailySeries($site, $days),
                fn ($p) => $p['uptime_pct'] !== null,
            ),
        ));
    }
}
