<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Alerts\OfflineAlertService;
use App\Services\Monitoring\HealthCheckResult;
use App\Services\Monitoring\SiteHealthChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Detect and transition site uptime state, driving offline/recovery alerting.
 *
 * PRIMARY LIVENESS ENFORCER (reliability contract)
 * ------------------------------------------------
 * This command is the platform's guarantee that every active site is verified
 * alive on OUR cadence — `marqira.heartbeat.probe_interval_minutes` (default 3)
 * — rather than whenever the customer's WP-Cron happens to fire a heartbeat.
 * Heartbeats are push-based and only run on traffic, so on idle/low-traffic
 * sites they arrive at irregular 10-50 minute gaps; relying on them alone let
 * `last_seen_at` drift far past the interval we advertise. Every run (scheduled
 * every minute) this command actively HTTP-probes every site whose liveness has
 * NOT been confirmed within the contract window (see dueCandidates), so
 * `last_seen_at` reflects a VERIFIED liveness event that can never silently fall
 * more than one window + one tick behind for a reachable site.
 *
 * ROOT-CAUSE FIX (false-offline alerts)
 * -------------------------------------
 * A stale heartbeat does NOT prove a site is down — it may simply be idle, on
 * free-tier hosting, have cron disabled, have a connector/firewall problem, or
 * our own worker may have hiccuped. Declaring "offline" from that silence alone
 * produced false alerts that damaged trust. So a due site is never marked
 * offline on silence; it is INDEPENDENTLY VERIFIED with an active HTTP(S) probe
 * (SiteHealthChecker) and transitioned only on confirmed evidence:
 *
 *   - Probe UP        -> the website is genuinely reachable; keep it ONLINE (or
 *                        confirm recovery). Quiet != offline.
 *   - Probe DOWN      -> increment a per-site failure counter; only after
 *                        `failure_threshold` CONSECUTIVE confirmed failures is
 *                        the site marked OFFLINE and the initial alert sent.
 *   - Probe INCONCLUSIVE (no URL / local error) -> never changes state.
 *   - Batch guard     -> if a large share of probed sites fail with
 *                        network-level errors in one run, that indicates a
 *                        monitoring-side problem; the run makes NO offline
 *                        transitions.
 *
 * Recovery: an offline site returns ONLINE either on any real heartbeat
 * (handled in HeartbeatController) or after `recovery_threshold` consecutive
 * successful probes here — preventing flapping between states.
 *
 * Runs every minute; repeat alerts remain timestamp-driven and atomically
 * claimed, so short repeat intervals are honored without ever double-sending.
 * Setting `marqira.heartbeat.active_check.enabled` to false restores the legacy
 * "stale => immediately offline" behavior.
 */
class CheckStaleSitesCommand extends Command
{
    protected $signature = 'marqira:check-stale-sites';

    protected $description = 'Verify stale sites with an active probe and transition uptime state';

    public function handle(OfflineAlertService $alerts, SiteHealthChecker $checker): int
    {
        $activeCheckEnabled = (bool) config('marqira.heartbeat.active_check.enabled', true);

        if ($activeCheckEnabled) {
            // PRIMARY liveness enforcement. Verify every site that has not been
            // confirmed alive within the reliability-contract window
            // (probe_interval_minutes) — regardless of whether its heartbeat is
            // merely quiet or long dead. This is what makes `last_seen_at`
            // reflect verified liveness on OUR cadence instead of drifting with
            // the customer's WP-Cron.
            $probeIntervalMinutes = max(1, (int) config('marqira.heartbeat.probe_interval_minutes', 3));
            $threshold = now()->subMinutes($probeIntervalMinutes);
            $newlyOffline = $this->verifyStaleSites($threshold, $probeIntervalMinutes, $alerts, $checker);
        } else {
            // LEGACY fallback (active verification disabled): infer offline from
            // heartbeat silence alone, using the conservative 30-minute gate.
            $thresholdMinutes = (int) config('marqira.heartbeat.offline_threshold_minutes', 30);
            $threshold = now()->subMinutes($thresholdMinutes);
            $newlyOffline = $this->markStaleSitesOffline($threshold, $thresholdMinutes, $alerts);
        }

        $repeated = $this->sendRepeatAlerts($alerts);

        $this->info("Transitioned {$newlyOffline} site(s) offline; sent {$repeated} repeat alert(s).");

        return self::SUCCESS;
    }

    /**
     * All non-revoked sites DUE for active verification this run — i.e. whose
     * liveness has NOT been confirmed within the reliability-contract window
     * ($threshold = now - probe_interval_minutes).
     *
     * A site is due when BOTH:
     *   (1) its last heartbeat is older than the window (or never seen), AND
     *   (2) we have not already actively verified it within the window
     *       (last_active_check_at older than the window / never checked) —
     *       UNLESS it is currently mid-outage or mid-recovery, in which case we
     *       probe on every run to confirm the failure / recovery quickly.
     *
     * Clause (1) means a site with a FRESH heartbeat is skipped: it is already
     * verified alive and carries richer telemetry, so re-probing would be
     * wasteful. Clause (2) self-throttles a healthy-but-quiet site to at most one
     * probe per window (the scheduler ticks every minute), which is what keeps
     * `last_seen_at` refreshed on our cadence without hammering the site — while
     * the offline/recovery carve-out preserves fast, every-minute confirmation
     * once a site starts failing.
     */
    private function dueCandidates($threshold)
    {
        return Site::query()
            ->active()
            ->where(function ($query) use ($threshold) {
                $query->where('last_heartbeat_at', '<', $threshold)
                    ->orWhereNull('last_heartbeat_at');
            })
            ->where(function ($query) use ($threshold) {
                $query->where('last_active_check_at', '<', $threshold)
                    ->orWhereNull('last_active_check_at')
                    ->orWhere('status', Site::STATUS_OFFLINE)
                    ->orWhere('consecutive_check_failures', '>', 0);
            })
            ->get();
    }

    /**
     * Active-verification path (default). Probe every DUE candidate, apply the
     * batch worker-network guard, then transition each site based on CONFIRMED
     * evidence.
     *
     * @return int Number of sites newly marked offline this run.
     */
    private function verifyStaleSites($threshold, int $thresholdMinutes, OfflineAlertService $alerts, SiteHealthChecker $checker): int
    {
        $candidates = $this->dueCandidates($threshold);

        if ($candidates->isEmpty()) {
            return 0;
        }

        // Phase 1 — probe every candidate and remember the verdict.
        $results = [];
        $networkFailures = 0;
        foreach ($candidates as $site) {
            $result = $checker->check($site);
            $results[] = [$site, $result];
            if ($result->isNetworkFailure()) {
                $networkFailures++;
            }
        }

        // Batch worker-network guard: if connectivity-level failures dominate the
        // run, we are almost certainly looking at a monitoring-side/network
        // problem rather than many independent sites going down at once. Record
        // the observation but make NO offline transitions and do not advance
        // failure counters (so a blip cannot accumulate toward an outage).
        $total = count($results);
        $minSites = (int) config('marqira.heartbeat.active_check.batch_guard_min_sites', 3);
        $ratio = (float) config('marqira.heartbeat.active_check.batch_guard_failure_ratio', 0.75);

        if ($total >= $minSites && $networkFailures >= $minSites
            && ($networkFailures / $total) >= $ratio) {
            Log::warning('Active uptime run skipped: monitoring-side network problem suspected', [
                'candidates' => $total,
                'network_failures' => $networkFailures,
                'ratio' => round($networkFailures / $total, 2),
            ]);

            foreach ($results as [$site, $result]) {
                $this->recordProbe($site, HealthCheckResult::INCONCLUSIVE, 'batch_network_guard', $result->httpCode, $result->latencyMs, false);
            }

            return 0;
        }

        // Phase 2 — apply per-site transitions from confirmed evidence.
        $failureThreshold = max(1, (int) config('marqira.heartbeat.active_check.failure_threshold', 3));
        $recoveryThreshold = max(1, (int) config('marqira.heartbeat.active_check.recovery_threshold', 2));

        $newlyOffline = 0;
        foreach ($results as [$site, $result]) {
            if ($result->isInconclusive()) {
                // Cannot trust this probe — record it, change nothing.
                $this->recordProbe($site, HealthCheckResult::INCONCLUSIVE, $result->reason(), $result->httpCode, $result->latencyMs, false);
                continue;
            }

            if ($result->isUp()) {
                $this->applyUp($site, $result, $alerts, $recoveryThreshold);
                continue;
            }

            // DOWN (confirmed candidate).
            if ($this->applyDown($site, $result, $alerts, $failureThreshold, $thresholdMinutes)) {
                $newlyOffline++;
            }
        }

        return $newlyOffline;
    }

    /**
     * Handle a site whose probe succeeded: reset the failure counter, keep it
     * ONLINE, and — if it was OFFLINE — confirm recovery once enough consecutive
     * successes have accrued.
     */
    private function applyUp(Site $site, HealthCheckResult $result, OfflineAlertService $alerts, int $recoveryThreshold): void
    {
        $successes = ((int) $site->consecutive_check_successes) + 1;

        $base = [
            'consecutive_check_failures' => 0,
            'consecutive_check_successes' => $successes,
            'last_active_check_at' => now(),
            'last_active_check_status' => HealthCheckResult::UP,
            'last_active_check_reason' => $result->reason(),
            'last_active_check_http_code' => $result->httpCode,
            'last_active_check_latency_ms' => $result->latencyMs,
        ];

        if ($site->status === Site::STATUS_OFFLINE) {
            if ($successes < $recoveryThreshold) {
                // Still confirming recovery — record progress, stay offline.
                Site::query()->whereKey($site->getKey())->update($base);
                return;
            }

            // Enough consecutive successes: atomically flip back ONLINE.
            $offlineSince = $site->offline_since;
            $alertsSent = (int) $site->offline_alert_count;

            $claimed = Site::query()
                ->whereKey($site->getKey())
                ->whereNull('revoked_at')
                ->where('status', Site::STATUS_OFFLINE)
                ->update(array_merge($base, [
                    'status' => Site::STATUS_ONLINE,
                    'last_seen_at' => now(),
                    'offline_since' => null,
                    'last_offline_alert_at' => null,
                    'offline_alert_count' => 0,
                ]));

            if ($claimed !== 1) {
                return; // Lost a race — another run already recovered it.
            }

            $site->refresh();

            AuditLog::record([
                'organization_id' => $site->organization_id,
                'actor_type' => 'system',
                'event' => 'site_recovered_by_probe',
                'subject_type' => 'site',
                'subject_id' => $site->id,
                'subject_uuid' => $site->uuid,
                'metadata' => [
                    'domain' => $site->domain,
                    'consecutive_successes' => $successes,
                    'http_code' => $result->httpCode,
                    'latency_ms' => $result->latencyMs,
                ],
            ]);

            // Only email recovery when we had actually warned about the outage.
            if ($alertsSent > 0) {
                try {
                    $alerts->sendRecoveryAlert($site, $offlineSince, $alertsSent);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return;
        }

        // Online / unknown and reachable: keep it ONLINE and refresh last-seen.
        // This is the core false-positive fix — a quiet-but-reachable site is
        // NOT flipped offline.
        Site::query()
            ->whereKey($site->getKey())
            ->whereNull('revoked_at')
            ->update(array_merge($base, [
                'status' => Site::STATUS_ONLINE,
                'last_seen_at' => now(),
            ]));
    }

    /**
     * Handle a site whose probe failed: advance the failure counter and only
     * declare OFFLINE (with the initial alert) once the confirmation threshold
     * is reached.
     *
     * @return bool True when this call newly marked the site offline.
     */
    private function applyDown(Site $site, HealthCheckResult $result, OfflineAlertService $alerts, int $failureThreshold, int $thresholdMinutes): bool
    {
        $failures = ((int) $site->consecutive_check_failures) + 1;

        $base = [
            'consecutive_check_failures' => $failures,
            'consecutive_check_successes' => 0,
            'last_active_check_at' => now(),
            'last_active_check_status' => HealthCheckResult::DOWN,
            'last_active_check_reason' => $result->reason(),
            'last_active_check_http_code' => $result->httpCode,
            'last_active_check_latency_ms' => $result->latencyMs,
        ];

        // Already offline: just record the continued failure.
        if ($site->status === Site::STATUS_OFFLINE) {
            Site::query()->whereKey($site->getKey())->update($base);
            return false;
        }

        // Not yet at the confirmation threshold: record the failure, do NOT flip.
        if ($failures < $failureThreshold) {
            Site::query()->whereKey($site->getKey())->update($base);
            return false;
        }

        // Confirmed outage: atomically claim the offline transition so overlapping
        // runs cannot double-alert.
        $claimed = Site::query()
            ->whereKey($site->getKey())
            ->whereNull('revoked_at')
            ->where('status', '!=', Site::STATUS_OFFLINE)
            ->update(array_merge($base, [
                'status' => Site::STATUS_OFFLINE,
                'offline_since' => now(),
                'last_offline_alert_at' => null,
                'offline_alert_count' => 0,
            ]));

        if ($claimed !== 1) {
            return false; // Lost the race.
        }

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
                'verified' => true,
                'consecutive_failures' => $failures,
                'probe_reason' => $result->reason(),
                'probe_http_code' => $result->httpCode,
            ],
        ]);

        // Initial offline alert (skipped for never-seen sites and when alerting
        // is disabled or no recipients resolve).
        $alerts->sendOfflineAlert($site);

        return true;
    }

    /**
     * Persist probe metadata without changing uptime state (used for
     * inconclusive probes and guarded runs).
     */
    private function recordProbe(Site $site, string $status, string $reason, ?int $httpCode, ?int $latencyMs, bool $touchCounters): void
    {
        $data = [
            'last_active_check_at' => now(),
            'last_active_check_status' => $status,
            'last_active_check_reason' => $reason,
            'last_active_check_http_code' => $httpCode,
            'last_active_check_latency_ms' => $latencyMs,
        ];

        Site::query()->whereKey($site->getKey())->update($data);
    }

    /**
     * LEGACY path (active verification disabled): mark not-yet-offline sites with
     * a stale heartbeat as offline, record the transition, and fire the initial
     * offline alert. Revoked sites are excluded.
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
                continue;
            }

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
                    'verified' => false,
                ],
            ]);

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
            if ($alerts->sendRepeatAlertIfDue($site, $cutoff)) {
                $sent++;
            }
        }

        return $sent;
    }
}
