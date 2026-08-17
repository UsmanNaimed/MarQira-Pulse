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
 * Runs every 5 minutes via scheduler.
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
            // Start of this offline episode; reset alert counters so each
            // episode gets its own initial + repeated alerts.
            $site->forceFill([
                'status' => Site::STATUS_OFFLINE,
                'offline_since' => now(),
                'last_offline_alert_at' => null,
                'offline_alert_count' => 0,
            ])->save();

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
            if ($alerts->sendOfflineAlert($site)) {
                $sent++;
            }
        }

        return $sent;
    }
}
