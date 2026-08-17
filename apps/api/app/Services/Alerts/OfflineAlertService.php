<?php

namespace App\Services\Alerts;

use App\Mail\SiteOfflineAlert;
use App\Mail\SiteRecoveryAlert;
use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Central point for offline / recovery alert delivery.
 *
 * Used by both the scheduler (CheckStaleSitesCommand — initial and repeated
 * offline alerts) and the heartbeat path (recovery alerts) so recipient
 * resolution, the "enabled" gate and audit logging live in exactly one place.
 *
 * Emails are queued (the mailables are ShouldQueue) so neither the scheduler run
 * nor the heartbeat request blocks on SMTP.
 */
class OfflineAlertService
{
    /**
     * Whether alerting is enabled platform-wide.
     */
    public function enabled(): bool
    {
        return (bool) config('marqira.alerts.enabled', true);
    }

    /**
     * Resolve the alert recipients for a site: its owner (when active with a
     * valid email) plus the platform-wide alert address (when configured).
     *
     * @return string[] De-duplicated list of valid email addresses.
     */
    public function recipients(Site $site): array
    {
        $emails = [];

        $owner = $site->owner; // BelongsTo User, may be null.
        if ($owner !== null && $owner->isActive()
            && is_string($owner->email)
            && filter_var($owner->email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $owner->email;
        }

        $global = config('marqira.alerts.email');
        if (is_string($global) && filter_var($global, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $global;
        }

        return array_values(array_unique($emails));
    }

    /**
     * Send an offline alert (initial or repeat) and advance the site's alert
     * tracking. Returns true when an email was queued.
     *
     * Sites that have never sent a heartbeat are skipped: a freshly enrolled
     * site that simply hasn't beaten yet should not trigger a false "offline"
     * email.
     */
    public function sendOfflineAlert(Site $site): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if ($site->last_heartbeat_at === null) {
            return false;
        }

        $recipients = $this->recipients($site);
        if (empty($recipients)) {
            Log::warning('Offline alert skipped: no recipients', ['site_uuid' => $site->uuid]);
            return false;
        }

        $alertNumber = ((int) $site->offline_alert_count) + 1;

        Mail::to($recipients)->queue(new SiteOfflineAlert($site, $alertNumber));

        $site->forceFill([
            'last_offline_alert_at' => now(),
            'offline_alert_count' => $alertNumber,
        ])->save();

        AuditLog::record([
            'organization_id' => $site->organization_id,
            'actor_type' => 'system',
            'event' => 'site_offline_alert_sent',
            'subject_type' => 'site',
            'subject_id' => $site->id,
            'subject_uuid' => $site->uuid,
            'metadata' => [
                'domain' => $site->domain,
                'alert_number' => $alertNumber,
                'recipient_count' => count($recipients),
            ],
        ]);

        return true;
    }

    /**
     * Send a repeat offline alert only if it is genuinely due, using an atomic
     * DB claim so concurrent scheduler runs can never double-send.
     *
     * The scheduler runs every minute (and may briefly overlap despite
     * withoutOverlapping, e.g. across a restart). Rather than read-then-write —
     * which two processes could both pass before either writes — we perform a
     * single conditional UPDATE that advances `last_offline_alert_at` and
     * `offline_alert_count` ONLY while the row still matches the "due" criteria
     * (still offline, not revoked, already alerted once, last alert older than
     * the cutoff). Exactly one racing process gets `affected === 1` and wins the
     * right to send; the loser sees `0` and returns without emailing.
     *
     * @param  \Carbon\CarbonInterface  $cutoff  now() minus the repeat interval.
     * @return bool True when this call claimed the slot and queued an email.
     */
    public function sendRepeatAlertIfDue(Site $site, $cutoff): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $alertNumber = ((int) $site->offline_alert_count) + 1;

        // Atomic claim: only one process can transition this row past the
        // cutoff. The WHERE mirrors the selection criteria so a row that was
        // already re-alerted by a concurrent run (its last_offline_alert_at now
        // >= cutoff) will not match here.
        $claimed = Site::query()
            ->whereKey($site->getKey())
            ->whereNull('revoked_at')
            ->where('status', Site::STATUS_OFFLINE)
            ->where('offline_alert_count', '>', 0)
            ->where('last_offline_alert_at', '<', $cutoff)
            ->update([
                'last_offline_alert_at' => now(),
                'offline_alert_count' => $alertNumber,
            ]);

        if ($claimed !== 1) {
            return false;
        }

        // We own the slot. Resolve recipients and queue the mail. Keep the row's
        // in-memory copy consistent with what we just persisted.
        $recipients = $this->recipients($site);
        if (empty($recipients)) {
            Log::warning('Offline repeat alert skipped: no recipients', ['site_uuid' => $site->uuid]);
            return false;
        }

        $site->forceFill([
            'last_offline_alert_at' => now(),
            'offline_alert_count' => $alertNumber,
        ]);

        Mail::to($recipients)->queue(new SiteOfflineAlert($site, $alertNumber));

        AuditLog::record([
            'organization_id' => $site->organization_id,
            'actor_type' => 'system',
            'event' => 'site_offline_alert_sent',
            'subject_type' => 'site',
            'subject_id' => $site->id,
            'subject_uuid' => $site->uuid,
            'metadata' => [
                'domain' => $site->domain,
                'alert_number' => $alertNumber,
                'recipient_count' => count($recipients),
                'repeat' => true,
            ],
        ]);

        return true;
    }

    /**
     * Send a single recovery alert for a site that has come back online.
     *
     * @param \Carbon\CarbonInterface|null $offlineSince When the offline episode began.
     * @param int                          $alertsSent   Offline alerts sent during the episode.
     */
    public function sendRecoveryAlert(Site $site, $offlineSince, int $alertsSent): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $recipients = $this->recipients($site);
        if (empty($recipients)) {
            Log::warning('Recovery alert skipped: no recipients', ['site_uuid' => $site->uuid]);
            return false;
        }

        Mail::to($recipients)->queue(new SiteRecoveryAlert($site, $offlineSince, $alertsSent));

        AuditLog::record([
            'organization_id' => $site->organization_id,
            'actor_type' => 'system',
            'event' => 'site_recovery_alert_sent',
            'subject_type' => 'site',
            'subject_id' => $site->id,
            'subject_uuid' => $site->uuid,
            'metadata' => [
                'domain' => $site->domain,
                'alerts_during_outage' => $alertsSent,
            ],
        ]);

        return true;
    }
}
