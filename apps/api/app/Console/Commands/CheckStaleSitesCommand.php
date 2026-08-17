<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Alerts\OfflineAlertService;
use Illuminate\Console\Command;

/**
 * Check for sites with stale heartbeats and mark them offline.
 *
 * Also drives offline alerting: an initial alert when a site first goes
 * offline, and repeated alerts every `marqira.alerts.offline_repeat_minutes`
 * while it stays offline. Recovery alerts are handled on the heartbeat path.
 *
 * Runs every minute via the scheduler. Frequent execution does NOT mean frequent
 * email: repeat alerts are timestamp-driven (only sent once
 * `marqira.alerts.offline_repeat_minutes` has elapsed) and use atomic DB claims,
 * so short repeat intervals are honored without ever double-sending.
 */
class CheckStaleSitesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marqira:check-stale-sites';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark sites offline if heartbeat is stale';

    /**
     * Execute the console command.
     */
    public function handle(OfflineAlertService $alerts): int
    {
        $thresholdMinutes = config('marqira.heartbeat.offline_threshold_minutes', 30);
        $threshold = now()->subMinutes($thresholdMinutes);

        $newlyOffline = $this->markStaleSitesOffline($threshold, $thresholdMinutes, $alerts);
        $repeated = $this->sendRepeatAlerts($alerts);

        $this->info("Marked {$newlyOffline} site(s) offline; sent {$repeated} repeat alert(s).");

        return self::SUCCESS;
    }

    /**
     * Mark not-yet-offline sites with a stale heartbeat as offline, record the
     * transition, and fire the initial offline alert.
     *
     * Revoked sites are excluded — their connector has been told to disconnect,
     * so going quiet is expected and must not raise an alert.
     *
     * @return int Number of sites newly marked offline.
     */
    private function markStaleSitesOffline($threshold, int $thresholdMinutes, OfflineAlertService $alerts): int
    {
        $staleSites = Site::query()
            ->active()
            ->where('status', '!=', Site::STATUS_OFFLINE)
            ->where(function ($query) use ($threshold) {
                $query->where('last_heartbeat_at', '<', $threshold)
                    ->orWhereNull('last_heartbeat_at');
            })
            ->get();

        $count = 0;

        foreach ($staleSites as $site) {
            // Atomically claim the offline transition. Because the scheduler now
            // runs every minute (and runs may briefly overlap), two processes
            // could both load the same not-yet-offline site; the conditional
            // UPDATE ensures only ONE actually flips it offline and therefore
            // only one fires the initial alert. The WHERE mirrors the selection
            // so a row already flipped by a concurrent run no longer matches.
            $claimed = Site::query()
                ->whereKey($site->getKey())
                ->whereNull('revoked_at')
                ->where('status', '!=', Site::STATUS_OFFLINE)
                ->update([
                    'status' => Site::STATUS_OFFLINE,
                    'offline_since' => now(),
                    'last_offline_alert_at' => null,
                    'offline_alert_count' => 0,
                ]);

            if ($claimed !== 1) {
                // Lost the race (another run already marked it offline) — skip.
                continue;
            }

            // Refresh the in-memory model to reflect the values we just wrote.
            $site->refresh();

            AuditLog::record([
                'organization_id' => $site->organization_id,
                'actor_type' => 'system',
                'event' => 'site_marked_offline',
                'subject_type' => 'site',
                'subject_id' => $site->id,
                'subject_uuid' => $site->uuid,
                'metadata' => [
                    'domain' => $site->domain,
                    'last_heartbeat_at' => $site->last_heartbeat_at?->toIso8601String(),
                    'threshold_minutes' => $thresholdMinutes,
                ],
            ]);

            // Initial offline alert (skipped for never-seen sites and when
            // alerting is disabled or no recipients resolve).
            $alerts->sendOfflineAlert($site);

            $count++;
        }

        return $count;
    }

    /**
     * Re-alert sites that are still offline and whose last alert is older than
     * the configured repeat interval.
     *
     * @return int Number of repeat alerts sent.
     */
    private function sendRepeatAlerts(OfflineAlertService $alerts): int
    {
        if (! $alerts->enabled()) {
            return 0;
        }

        $repeatMinutes = (int) config('marqira.alerts.offline_repeat_minutes', 60);
        if ($repeatMinutes <= 0) {
            return 0;
        }

        $cutoff = now()->subMinutes($repeatMinutes);

        $stillOffline = Site::query()
            ->active()
            ->where('status', Site::STATUS_OFFLINE)
            ->where('offline_alert_count', '>', 0)
            ->where('last_offline_alert_at', '<', $cutoff)
            ->get();

        $sent = 0;
        foreach ($stillOffline as $site) {
            // Atomic, timestamp-driven claim: even though we run every minute,
            // sendRepeatAlertIfDue only emails when the repeat interval has
            // actually elapsed, and its conditional UPDATE guarantees a single
            // email per interval even if runs overlap.
            if ($alerts->sendRepeatAlertIfDue($site, $cutoff)) {
                $sent++;
            }
        }

        return $sent;
    }
}
