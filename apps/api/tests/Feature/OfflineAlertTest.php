<?php

use App\Mail\SiteOfflineAlert;
use App\Mail\SiteRecoveryAlert;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Encryption\SecretEncryptor;
use App\Services\Hmac\HmacService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Build an organization, an active owner user with a valid email, and a site
 * owned by that user. The global alert address is left unset so recipient
 * resolution is driven purely by the owner unless a test overrides it.
 */
function makeAlertableSite(array $siteAttrs = []): array
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
        'last_heartbeat_at' => now()->subMinutes(60),
        'last_seen_at' => now()->subMinutes(60),
    ], $siteAttrs));

    return [$org, $owner, $site];
}

beforeEach(function () {
    config(['marqira.alerts.enabled' => true]);
    config(['marqira.alerts.email' => null]);
    config(['marqira.alerts.offline_repeat_minutes' => 60]);
    config(['marqira.heartbeat.offline_threshold_minutes' => 30]);

    // Active verification: these alerting tests focus on the alert state machine,
    // not the multi-run confirmation logic (that lives in
    // Monitoring/ActiveUptimeVerificationTest). Use single-run thresholds so a
    // stale site that fails ONE confirmed probe transitions immediately, and
    // default every outbound probe to a connection failure ("site down") so a
    // stale site is treated as a genuine — verified — outage unless a test says
    // otherwise. No probe ever touches the real network.
    config(['marqira.heartbeat.active_check.enabled' => true]);
    config(['marqira.heartbeat.active_check.failure_threshold' => 1]);
    config(['marqira.heartbeat.active_check.recovery_threshold' => 1]);
    config(['marqira.heartbeat.active_check.retries' => 0]);
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to host'));
});

test('stale site is marked offline and an offline alert is queued to the owner', function () {
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'last_heartbeat_at' => now()->subMinutes(90),
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_OFFLINE);
    expect($site->offline_since)->not->toBeNull();
    expect($site->offline_alert_count)->toBe(1);
    expect($site->last_offline_alert_at)->not->toBeNull();

    Mail::assertQueued(SiteOfflineAlert::class, function ($mail) use ($owner) {
        return $mail->hasTo($owner->email);
    });
});

test('a site that has never sent a heartbeat is marked offline but no email is sent', function () {
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'last_heartbeat_at' => null,
        'last_seen_at' => null,
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_OFFLINE);
    expect($site->offline_alert_count)->toBe(0);

    Mail::assertNothingQueued();
});

test('an already-offline site within the repeat window gets no repeat alert', function () {
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'status' => Site::STATUS_OFFLINE,
        'last_heartbeat_at' => now()->subMinutes(120),
        'offline_since' => now()->subMinutes(90),
        'offline_alert_count' => 1,
        'last_offline_alert_at' => now()->subMinutes(10), // within 60m window
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    Mail::assertNothingQueued();

    $site->refresh();
    expect($site->offline_alert_count)->toBe(1);
});

test('an already-offline site past the repeat window gets a repeat alert', function () {
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'status' => Site::STATUS_OFFLINE,
        'last_heartbeat_at' => now()->subMinutes(200),
        'offline_since' => now()->subMinutes(180),
        'offline_alert_count' => 1,
        'last_offline_alert_at' => now()->subMinutes(90), // past 60m window
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    Mail::assertQueued(SiteOfflineAlert::class, 1);

    $site->refresh();
    expect($site->offline_alert_count)->toBe(2);
});

test('no alerts are queued when alerting is disabled', function () {
    Mail::fake();
    config(['marqira.alerts.enabled' => false]);

    [$org, $owner, $site] = makeAlertableSite([
        'last_heartbeat_at' => now()->subMinutes(90),
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    // Site is still transitioned to offline for accurate status, but no email.
    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_OFFLINE);

    Mail::assertNothingQueued();
});

test('a revoked site is never marked offline nor alerted', function () {
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'last_heartbeat_at' => now()->subMinutes(90),
        'revoked_at' => now()->subDay(),
        'status' => Site::STATUS_REVOKED,
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_REVOKED);

    Mail::assertNothingQueued();
});

test('the global alert address also receives offline alerts', function () {
    Mail::fake();
    config(['marqira.alerts.email' => 'ops@example.com']);

    [$org, $owner, $site] = makeAlertableSite([
        'last_heartbeat_at' => now()->subMinutes(90),
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    Mail::assertQueued(SiteOfflineAlert::class, function ($mail) use ($owner) {
        return $mail->hasTo($owner->email) && $mail->hasTo('ops@example.com');
    });
});

test('a recovering site queues a recovery alert on heartbeat and resets tracking', function () {
    Redis::flushDB();
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'status' => Site::STATUS_OFFLINE,
        'offline_since' => now()->subMinutes(90),
        'offline_alert_count' => 2,
        'last_offline_alert_at' => now()->subMinutes(10),
    ]);

    // Give the site a usable HMAC secret.
    $secret = base64_encode(random_bytes(32));
    $encryptor = app(SecretEncryptor::class);
    $site->update([
        'site_secret_encrypted' => $encryptor->encrypt($secret),
        'site_secret_kid' => $encryptor->keyId(),
    ]);

    $hmac = new HmacService();
    $path = '/api/v1/heartbeat';
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode(['domain' => $site->domain]);

    $canonical = $hmac->buildCanonicalData('POST', $path, [], $timestamp, $nonce, $body);
    $signature = $hmac->generateSignature($canonical, $secret);

    $response = $this->postJson($path, json_decode($body, true), [
        'X-MarQira-Site' => $site->uuid,
        'X-MarQira-Timestamp' => $timestamp,
        'X-MarQira-Nonce' => $nonce,
        'X-MarQira-Kid' => $site->site_secret_kid,
        'X-MarQira-Signature' => $signature,
    ]);

    $response->assertStatus(200);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_ONLINE);
    expect($site->offline_since)->toBeNull();
    expect($site->offline_alert_count)->toBe(0);
    expect($site->last_offline_alert_at)->toBeNull();

    Mail::assertQueued(SiteRecoveryAlert::class, function ($mail) use ($owner) {
        return $mail->hasTo($owner->email);
    });
});

/*
|--------------------------------------------------------------------------
| Fix 2 regression: every-minute scheduler must honor the repeat interval
| and never double-send, even when the command runs many times per interval.
|--------------------------------------------------------------------------
*/

test('running the monitor twice within the repeat window sends only one repeat alert', function () {
    // Simulates the every-minute scheduler firing twice inside a 60m window.
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'status' => Site::STATUS_OFFLINE,
        'last_heartbeat_at' => now()->subMinutes(200),
        'offline_since' => now()->subMinutes(180),
        'offline_alert_count' => 1,
        'last_offline_alert_at' => now()->subMinutes(90), // past 60m window
    ]);

    // First run: repeat is due -> exactly one email, count -> 2.
    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);
    // Second run immediately after: last_offline_alert_at is now ~0m old, so
    // the repeat is no longer due and nothing else must be sent.
    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    Mail::assertQueued(SiteOfflineAlert::class, 1);

    $site->refresh();
    expect($site->offline_alert_count)->toBe(2);
});

test('a newly-stale site marked offline by one run is not re-alerted by the very next run', function () {
    // Every-minute cadence: the run that flips a site offline sends the initial
    // alert; the next run one "minute" later must not send a duplicate because
    // the repeat interval has not elapsed.
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'last_heartbeat_at' => now()->subMinutes(90),
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);
    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    // Exactly one email total (the initial), no duplicate.
    Mail::assertQueued(SiteOfflineAlert::class, 1);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_OFFLINE);
    expect($site->offline_alert_count)->toBe(1);
});

test('with a 2-minute repeat interval a site last alerted 3 minutes ago is re-alerted', function () {
    // Proves the short (2-minute) test cadence is actually honored now that the
    // scheduler runs every minute.
    Mail::fake();
    config(['marqira.alerts.offline_repeat_minutes' => 2]);

    [$org, $owner, $site] = makeAlertableSite([
        'status' => Site::STATUS_OFFLINE,
        'last_heartbeat_at' => now()->subMinutes(30),
        'offline_since' => now()->subMinutes(20),
        'offline_alert_count' => 3,
        'last_offline_alert_at' => now()->subMinutes(3), // older than 2m -> due
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    Mail::assertQueued(SiteOfflineAlert::class, 1);

    $site->refresh();
    expect($site->offline_alert_count)->toBe(4);
});

test('with a 2-minute repeat interval a site last alerted 1 minute ago is not re-alerted', function () {
    // The complementary case: within the 2-minute window -> no email, even
    // though the monitor runs every minute.
    Mail::fake();
    config(['marqira.alerts.offline_repeat_minutes' => 2]);

    [$org, $owner, $site] = makeAlertableSite([
        'status' => Site::STATUS_OFFLINE,
        'last_heartbeat_at' => now()->subMinutes(30),
        'offline_since' => now()->subMinutes(20),
        'offline_alert_count' => 3,
        'last_offline_alert_at' => now()->subMinutes(1), // within 2m -> not due
    ]);

    $this->artisan('marqira:check-stale-sites')->assertExitCode(0);

    Mail::assertNothingQueued();

    $site->refresh();
    expect($site->offline_alert_count)->toBe(3);
});

test('sendRepeatAlertIfDue is atomic: a second concurrent claim after the first does not double-send', function () {
    // Directly exercises the atomic claim used to protect against overlapping
    // scheduler processes. The first call claims the slot and sends; a second
    // call with the SAME cutoff finds the row already advanced and sends nothing.
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'status' => Site::STATUS_OFFLINE,
        'last_heartbeat_at' => now()->subMinutes(200),
        'offline_since' => now()->subMinutes(180),
        'offline_alert_count' => 1,
        'last_offline_alert_at' => now()->subMinutes(90),
    ]);

    $service = app(\App\Services\Alerts\OfflineAlertService::class);
    $cutoff = now()->subMinutes(60);

    $first = $service->sendRepeatAlertIfDue($site->fresh(), $cutoff);
    $second = $service->sendRepeatAlertIfDue($site->fresh(), $cutoff);

    expect($first)->toBeTrue();
    expect($second)->toBeFalse();
    Mail::assertQueued(SiteOfflineAlert::class, 1);

    $site->refresh();
    expect($site->offline_alert_count)->toBe(2);
});

test('a site that was online (no prior offline alerts) sends no recovery alert', function () {
    Redis::flushDB();
    Mail::fake();

    [$org, $owner, $site] = makeAlertableSite([
        'status' => Site::STATUS_ONLINE,
        'offline_alert_count' => 0,
    ]);

    $secret = base64_encode(random_bytes(32));
    $encryptor = app(SecretEncryptor::class);
    $site->update([
        'site_secret_encrypted' => $encryptor->encrypt($secret),
        'site_secret_kid' => $encryptor->keyId(),
    ]);

    $hmac = new HmacService();
    $path = '/api/v1/heartbeat';
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode(['domain' => $site->domain]);

    $canonical = $hmac->buildCanonicalData('POST', $path, [], $timestamp, $nonce, $body);
    $signature = $hmac->generateSignature($canonical, $secret);

    $response = $this->postJson($path, json_decode($body, true), [
        'X-MarQira-Site' => $site->uuid,
        'X-MarQira-Timestamp' => $timestamp,
        'X-MarQira-Nonce' => $nonce,
        'X-MarQira-Kid' => $site->site_secret_kid,
        'X-MarQira-Signature' => $signature,
    ]);

    $response->assertStatus(200);

    Mail::assertNotQueued(SiteRecoveryAlert::class);
});
