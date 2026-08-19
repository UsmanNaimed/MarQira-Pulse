<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Active verification is on by default. For these state-transition tests use
    // a single-run failure threshold and default every probe to "site down"
    // (connection refused) so a stale, unreachable site is confirmed offline in
    // one run. Multi-run confirmation, the false-positive fix, cold starts and
    // the batch guard are covered in Monitoring/ActiveUptimeVerificationTest.
    config(['marqira.heartbeat.active_check.enabled' => true]);
    config(['marqira.heartbeat.active_check.failure_threshold' => 1]);
    config(['marqira.heartbeat.active_check.retries' => 0]);
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to host'));
});

test('marks sites offline when heartbeat stale', function () {
    $org = Organization::factory()->create();
    
    $staleSite = Site::factory()->create([
        'organization_id' => $org->id,
        'status' => 'online',
        'last_heartbeat_at' => now()->subMinutes(35), // Stale
    ]);
    
    $this->artisan('marqira:check-stale-sites')
        ->assertExitCode(0);
    
    $staleSite->refresh();
    
    expect($staleSite->status)->toBe('offline');
});

test('does not probe or touch sites whose heartbeat is within the contract window', function () {
    // Under the reliability contract a site verified within
    // probe_interval_minutes (default 3) is NOT due for a probe, so a fresh
    // heartbeat keeps it online without any HTTP probe being made.
    $org = Organization::factory()->create();

    $activeSite = Site::factory()->create([
        'organization_id' => $org->id,
        'status' => 'online',
        'last_heartbeat_at' => now()->subMinutes(2), // Within the 3-minute window
    ]);

    $this->artisan('marqira:check-stale-sites')
        ->assertExitCode(0);

    $activeSite->refresh();

    expect($activeSite->status)->toBe('online');
    // Not due => never probed this run.
    expect($activeSite->last_active_check_at)->toBeNull();
});

test('logs audit entry for each marked site', function () {
    $org = Organization::factory()->create();
    
    $staleSite = Site::factory()->create([
        'organization_id' => $org->id,
        'status' => 'online',
        'last_heartbeat_at' => now()->subMinutes(35),
    ]);
    
    $this->artisan('marqira:check-stale-sites');
    
    expect(AuditLog::where('event', 'site_marked_offline')
        ->where('subject_id', $staleSite->id)
        ->exists())->toBeTrue();
});
