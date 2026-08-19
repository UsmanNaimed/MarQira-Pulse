<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-site availability ("uptime") analytics over a rolling 24-hour window,
 * derived entirely from the heartbeats a site has actually sent — no
 * fabricated numbers.
 *
 * Uptime is measured with HOURLY resolution: the last 24 fully-elapsed clock
 * hours are each an "hour bucket", and a bucket "counts" when the site
 * delivered at least one heartbeat during it. Uptime% = covered buckets /
 * expected buckets. A couple of missed hours reads as ~92%, a full-day outage
 * as 0%.
 *
 * Expected buckets are bounded by reality:
 *   - only fully-elapsed clock hours count (the in-progress current hour is
 *     excluded so a site is never unfairly scored for an unfinished hour);
 *   - hours before the site was enrolled (or before a manual uptime reset) are
 *     null — the site was not being measured yet — and are omitted from the
 *     trend and the average.
 *
 * When a site has never reported (or every expected hour is still null), the
 * headline percentage is null so the dashboard can show an honest "no data
 * yet" state.
 */
class SiteUptime
{
    /**
     * Hourly uptime series for a single site over the last N hours (oldest
     * first). Each entry is 100.0 (the hour had a heartbeat), 0.0 (it did not),
     * or null (the site was not being measured during that hour).
     *
     * @return list<array{hour:string,uptime_pct:?float}>
     */
    public static function hourlySeries(Site $site, int $hours = 24): array
    {
        $now = Carbon::now();

        // Only fully-elapsed clock hours are "expected". The exclusive end of
        // the window is the start of the current (in-progress) hour.
        $windowEnd = $now->copy()->startOfHour();
        $windowStart = $windowEnd->copy()->subHours($hours);

        // Effective measurement floor: the later of enrolment/creation and any
        // manual "Clear Uptime" reset. Hours before this are null.
        $enrolled = $site->enrolled_at ?? $site->created_at;
        $floor = $enrolled !== null ? Carbon::parse($enrolled) : null;
        if ($site->uptime_reset_at !== null) {
            $reset = Carbon::parse($site->uptime_reset_at);
            if ($floor === null || $reset->gt($floor)) {
                $floor = $reset;
            }
        }

        // Count distinct heartbeat hour-buckets in the window in one query.
        // Driver-aware bucketing so the same logic works on sqlite (tests) and
        // pgsql (prod).
        $driver = DB::connection()->getDriverName();
        $hourExpr = $driver === 'pgsql'
            ? "to_char(received_at, 'YYYY-MM-DD HH24')"
            : "strftime('%Y-%m-%d %H', received_at)";

        $covered = DB::table('site_heartbeats')
            ->where('site_id', $site->id)
            ->where('received_at', '>=', $windowStart)
            ->where('received_at', '<', $windowEnd)
            ->select(DB::raw("$hourExpr as h"))
            ->distinct()
            ->pluck('h')
            ->flip()
            ->all();

        $series = [];
        for ($h = $windowStart->copy(); $h->lt($windowEnd); $h->addHour()) {
            $key = $h->format('Y-m-d H');

            // The hour started before the site was being measured → unknown.
            if ($floor !== null && $floor->gt($h)) {
                $series[] = ['hour' => $key, 'uptime_pct' => null];
                continue;
            }

            $series[] = [
                'hour' => $key,
                'uptime_pct' => isset($covered[$key]) ? 100.0 : 0.0,
            ];
        }

        return $series;
    }

    /**
     * Headline 24-hour uptime for a site: the share of measured hours that had
     * a heartbeat, or null when there is nothing to report yet.
     */
    public static function averagePct(Site $site, int $hours = 24): ?float
    {
        $valid = array_values(array_filter(
            self::hourlySeries($site, $hours),
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
     * Compact trend for the list sparkline: the per-hour percentages for the
     * hours the site was being measured (oldest first), null hours omitted.
     * Returns [] when the site has never reported, so the UI renders no line
     * rather than a fake one.
     *
     * @return list<float>
     */
    public static function trend(Site $site, int $hours = 24): array
    {
        return array_values(array_map(
            fn ($p) => $p['uptime_pct'],
            array_filter(
                self::hourlySeries($site, $hours),
                fn ($p) => $p['uptime_pct'] !== null,
            ),
        ));
    }
}
