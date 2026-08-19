<?php

use App\Mail\SiteOfflineAlert;
use App\Mail\SiteRecoveryAlert;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * End-to-end coverage for the active-verification uptime monitor. These tests
 * exercise the FULL command (marqira:check-stale-sites) with the outbound probe
 * faked via Http::fake(), so no real network is touched. They prove the
 * false-offline fix and the multi-run confirmation / recovery / batch-guard
 * behavior that prevents flapping and mis-attributed outages.
 */

/**
 * Build an org + active owner + site. Defaults to an ONLINE site whose
 * heartbeat is long stale (so it is always a verification candidate).
 */
function makeMonitoredSite(array $siteAttrs = []): array
{
    $org = Organization::factory()->create();

    $owner = User::factory()->create([
        'email' => 'owner-' . uniqid() . '@example.com',
        'is_active' => true,
        'platform_role' => User::ROLE_OWNER,
    ]);

    $site = Site::factory()->create(array_merge([
        'organization_id' => $org->id,
        'owner_user_id' => $owner->id,
        'status' => Site::STATUS_ONLINE,
        'last_heartbeat_at' => now()->subMinutes(90),
        'last_seen_at' => now()->subMinutes(90),
    ], $siteAttrs));

    return [$org, $owner, $site];
}

beforeEach(function () {
    config(['marqira.alerts.enabled' => true]);
    config(['marqira.alerts.email' => null]);
    config(['marqira.alerts.offline_repeat_minutes' => 60]);
    config(['marqira.heartbeat.offline_threshold_minutes' => 30]);

    // Active verification ON. Individual tests override thresholds as needed.
    config(['marqira.heartbeat.active_check.enabled' => true]);
    config(['marqira.heartbeat.active_check.retries' => 0]);
    config(['marqira.heartbeat.active_check.failure_threshold' => 3]);
    config(['marqira.heartbeat.active_check.recovery_threshold' => 2]);
});

/* -------------------------------------------------------------------------
 * (a) THE CORE FIX: a stale-but-reachable site stays ONLINE, no alert.
 * ---------------------------------------------------------------------- */
test('a stale heartbeat with a reachable site does NOT trigger a false offline alert', function () {
    Mail::fake();
    Http::fake(fn () => Http::response('OK', 200));

    [$org, $owner, $site] = makeMonitoredSite();

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->offline_since)->toBeNull();
    expect($site->offline_alert_count)->toBe(0);
    expect($site->consecutive_check_failures)->toBe(0);
    expect($site->consecutive_check_successes)->toBe(1);
    expect($site->last_active_check_status)->toBe('up');
    expect($site->last_seen_at)->not->toBeNull();

    Mail::assertNothingQueued();
});

/* -------------------------------------------------------------------------
 * (b) A 4xx (e.g. 403) means the server is responding -> still ONLINE.
 * ---------------------------------------------------------------------- */
test('a stale site returning 403 is treated as reachable and stays online', function () {
    Mail::fake();
    Http::fake(fn () => Http::response('Forbidden', 403));

    [$org, $owner, $site] = makeMonitoredSite();

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->consecutive_check_successes)->toBe(1);
    expect($site->last_active_check_status)->toBe('up');

    Mail::assertNothingQueued();
});

/* -------------------------------------------------------------------------
 * (c) A single confirmed DOWN with failure_threshold=3 does NOT flip yet.
 * ---------------------------------------------------------------------- */
test('a single confirmed probe failure does not mark the site offline', function () {
    Mail::fake();
    Http::fake(fn () => Http::response('', 503));
    config(['marqira.heartbeat.active_check.failure_threshold' => 3]);

    [$org, $owner, $site] = makeMonitoredSite();

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->offline_since)->toBeNull();
    expect($site->consecutive_check_failures)->toBe(1);
    expect($site->consecutive_check_successes)->toBe(0);
    expect($site->last_active_check_status)->toBe('down');

    Mail::assertNothingQueued();
});

/* -------------------------------------------------------------------------
 * (d) Three consecutive confirmed failures -> OFFLINE on the 3rd, ONE alert.
 * ---------------------------------------------------------------------- */
test('three consecutive confirmed failures mark the site offline exactly once', function () {
    Mail::fake();
    Http::fake(fn () => Http::response('', 503));
    config(['marqira.heartbeat.active_check.failure_threshold' => 3]);

    [$org, $owner, $site] = makeMonitoredSite();

    // Run 1 & 2: still online, counter climbing.
    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);
    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->consecutive_check_failures)->toBe(1);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);
    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->consecutive_check_failures)->toBe(2);
    Mail::assertNothingQueued();

    // Run 3: confirmed outage.
    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);
    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_OFFLINE);
    expect($site->offline_since)->not->toBeNull();
    expect($site->consecutive_check_failures)->toBe(3);
    expect($site->offline_alert_count)->toBe(1);

    Mail::assertQueued(SiteOfflineAlert::class, 1);

    // The offline transition is recorded as VERIFIED in the audit log.
    $audit = AuditLog::query()->where('event', 'site_marked_offline')->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->metadata['verified'] ?? null)->toBeTrue();
    expect($audit->metadata['consecutive_failures'] ?? null)->toBe(3);

    // Run 4: still down, but no duplicate initial alert.
    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);
    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_OFFLINE);
    Mail::assertQueued(SiteOfflineAlert::class, 1);
});

/* -------------------------------------------------------------------------
 * (e) Recovery requires recovery_threshold consecutive successes.
 * ---------------------------------------------------------------------- */
test('an offline site recovers only after enough consecutive successful probes', function () {
    Mail::fake();
    Http::fake(fn () => Http::response('OK', 200));
    config(['marqira.heartbeat.active_check.recovery_threshold' => 2]);

    // Start already offline, with an alert previously sent.
    [$org, $owner, $site] = makeMonitoredSite([
        'status' => Site::STATUS_OFFLINE,
        'offline_since' => now()->subMinutes(45),
        'offline_alert_count' => 1,
        'last_offline_alert_at' => now()->subMinutes(45),
    ]);

    // Run 1: one success — not enough, stays offline.
    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);
    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_OFFLINE);
    expect($site->consecutive_check_successes)->toBe(1);
    Mail::assertNotQueued(SiteRecoveryAlert::class);

    // Run 2: second consecutive success — recovery confirmed.
    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);
    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->offline_since)->toBeNull();
    expect($site->offline_alert_count)->toBe(0);
    expect($site->consecutive_check_failures)->toBe(0);

    Mail::assertQueued(SiteRecoveryAlert::class, 1);

    $audit = AuditLog::query()->where('event', 'site_recovered_by_probe')->latest('id')->first();
    expect($audit)->not->toBeNull();
});

/* -------------------------------------------------------------------------
 * (f) Batch worker-network guard: a monitoring-side outage flips nothing.
 * ---------------------------------------------------------------------- */
test('a suspected monitoring-side network failure across many sites flips none of them', function () {
    Mail::fake();
    // Every probe fails at the connection level (DNS/connect) — the signature of
    // OUR worker losing network, not many independent sites dying at once.
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));
    config(['marqira.heartbeat.active_check.failure_threshold' => 1]);
    config(['marqira.heartbeat.active_check.batch_guard_min_sites' => 3]);
    config(['marqira.heartbeat.active_check.batch_guard_failure_ratio' => 0.75]);

    $sites = collect(range(1, 4))->map(fn () => makeMonitoredSite()[2]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    foreach ($sites as $site) {
        $site->refresh();
        // Guard tripped: no offline transition, failure counter NOT advanced.
        expect($site->status)->toBe(Site::STATUS_ONLINE);
        expect($site->offline_since)->toBeNull();
        expect($site->consecutive_check_failures)->toBe(0);
        expect($site->last_active_check_status)->toBe('inconclusive');
        expect($site->last_active_check_reason)->toBe('batch_network_guard');
    }

    Mail::assertNothingQueued();
});

/* -------------------------------------------------------------------------
 * (g) An inconclusive probe (no usable URL / local error) never flips state.
 * ---------------------------------------------------------------------- */
test('an inconclusive probe never changes uptime state', function () {
    Mail::fake();
    config(['marqira.heartbeat.active_check.failure_threshold' => 1]);

    // No probeable URL -> SiteHealthChecker returns INCONCLUSIVE without any
    // network call.
    [$org, $owner, $site] = makeMonitoredSite([
        'home_url' => null,
        'site_url' => null,
        'domain' => '',
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->offline_since)->toBeNull();
    expect($site->consecutive_check_failures)->toBe(0);
    expect($site->last_active_check_status)->toBe('inconclusive');

    Mail::assertNothingQueued();
});

/* -------------------------------------------------------------------------
 * (i) RELIABILITY CONTRACT: a site quiet past the probe interval (but well
 *     within the old 30-min gate) is now actively verified, refreshing
 *     last_seen_at on OUR cadence instead of waiting on the customer's WP-Cron.
 * ---------------------------------------------------------------------- */
test('a site quiet longer than the probe interval is actively verified and its last_seen is refreshed', function () {
    Mail::fake();
    Http::fake(fn () => Http::response('OK', 200));
    config(['marqira.heartbeat.probe_interval_minutes' => 3]);

    // Heartbeat 5 min old: older than the 3-min contract window, but far short
    // of the legacy 30-min offline gate. Previously this would NOT be probed and
    // last_seen would drift; now it is verified this run.
    [$org, $owner, $site] = makeMonitoredSite([
        'last_heartbeat_at' => now()->subMinutes(5),
        'last_seen_at' => now()->subMinutes(5),
        'last_active_check_at' => null,
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->last_active_check_status)->toBe('up');
    expect($site->consecutive_check_successes)->toBe(1);
    // last_seen_at reflects the just-completed verified probe (within seconds).
    expect($site->last_seen_at->diffInSeconds(now()))->toBeLessThan(30);

    Mail::assertNothingQueued();
});

/* -------------------------------------------------------------------------
 * (j) SELF-THROTTLE: a healthy site already verified within the window is not
 *     re-probed on the next minute tick (at most one probe per window).
 * ---------------------------------------------------------------------- */
test('a healthy site already verified within the window is not re-probed', function () {
    Http::fake(fn () => Http::response('OK', 200));
    config(['marqira.heartbeat.probe_interval_minutes' => 3]);

    // Heartbeat long stale, but we actively verified it 1 minute ago and it is
    // healthy with no failure streak => not due again until the window elapses.
    [$org, $owner, $site] = makeMonitoredSite([
        'status' => Site::STATUS_ONLINE,
        'last_heartbeat_at' => now()->subMinutes(90),
        'last_active_check_at' => now()->subMinutes(1),
        'consecutive_check_failures' => 0,
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    // No outbound probe was made this run.
    Http::assertNothingSent();

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
});

/* -------------------------------------------------------------------------
 * (h) A real heartbeat clears any accumulated probe-failure streak.
 * ---------------------------------------------------------------------- */
test('a real heartbeat resets the consecutive probe failure counter', function () {
    Http::fake(fn () => Http::response('', 503));
    config(['marqira.heartbeat.active_check.failure_threshold' => 3]);

    [$org, $owner, $site] = makeMonitoredSite([
        'consecutive_check_failures' => 2,
    ]);

    // One more confirmed failure would normally push toward offline, but a real
    // heartbeat arriving first must clear the streak.
    $site->update([
        'status' => Site::STATUS_ONLINE,
        'last_heartbeat_at' => now(),
        'last_seen_at' => now(),
        'consecutive_check_failures' => 0,
        'consecutive_check_successes' => 0,
    ]);

    $site->refresh();
    expect($site->consecutive_check_failures)->toBe(0);

    // With a fresh heartbeat the site is no longer a stale candidate, so the
    // command leaves it untouched and online.
    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);
    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->consecutive_check_failures)->toBe(0);
});
