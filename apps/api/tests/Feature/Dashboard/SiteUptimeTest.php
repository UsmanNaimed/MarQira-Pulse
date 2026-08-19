<?php

use App\Models\Site;
use App\Models\SiteHeartbeat;
use App\Services\SiteUptime;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Per-site 24-hour uptime — measured from real heartbeats at hourly resolution
// over a rolling 24-hour window.
// ---------------------------------------------------------------------------

function makeHeartbeatAt(Site $site, \Illuminate\Support\Carbon $at): void
{
    SiteHeartbeat::create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'received_at' => $at->copy(),
        'created_at' => $at->copy(),
    ]);
}

test('a brand-new site with no elapsed hour reports null uptime and an empty trend', function () {
    [$org] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => now(), // no whole hour has elapsed yet
    ]);

    expect(SiteUptime::averagePct($site))->toBeNull();
    expect(SiteUptime::trend($site))->toBe([]);
});

test('full hourly coverage across the window reports 100% uptime', function () {
    [$org] = makeUserWithOrg();
    // Enrolled well before the window so all 24 hour-buckets are expected.
    $enrolled = now()->subDays(2)->startOfHour();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => $enrolled,
    ]);

    // One heartbeat every hour across the last day — every expected hour-bucket
    // in the rolling 24h window is covered.
    for ($c = now()->subHours(26)->startOfHour(); $c->lte(now()); $c->addHour()) {
        makeHeartbeatAt($site, $c);
    }

    expect(SiteUptime::averagePct($site))->toBe(100.0);

    $trend = SiteUptime::trend($site);
    // Exactly the 24 fully-elapsed hours in the window.
    expect(count($trend))->toBe(24);
    foreach ($trend as $pct) {
        expect($pct)->toBe(100.0);
    }
});

test('partial coverage yields a proportional percentage', function () {
    [$org] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => now()->subDays(2)->startOfHour(),
    ]);

    // Cover exactly 12 of the 24 expected hours in the window.
    $windowEnd = now()->startOfHour();
    for ($h = 1; $h <= 12; $h++) {
        makeHeartbeatAt($site, $windowEnd->copy()->subHours($h)->addMinutes(5));
    }

    expect(SiteUptime::averagePct($site))->toBe(50.0); // 12 of 24 hours
});

test('hours before enrolment are null and omitted from the trend and average', function () {
    [$org] = makeUserWithOrg();
    // Enrolled 6 hours ago → only ~6 hour-buckets are expected, not 24.
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => now()->subHours(6)->startOfHour(),
    ]);

    $series = SiteUptime::hourlySeries($site);

    // The early hours of the 24h window predate enrolment → null.
    $nullHours = collect($series)->filter(fn ($d) => $d['uptime_pct'] === null);
    expect($nullHours->count())->toBeGreaterThan(0);

    // The trend never contains those null hours.
    expect(count(SiteUptime::trend($site)))->toBe(count($series) - $nullHours->count());
});

test('the site list resource exposes the 24-hour uptime headline and trend', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => now()->subDays(2)->startOfHour(),
    ]);
    for ($c = now()->subHours(26)->startOfHour(); $c->lte(now()); $c->addHour()) {
        makeHeartbeatAt($site, $c);
    }

    $this->actingAs($user)
        ->getJson('/api/dashboard/sites')
        ->assertStatus(200)
        ->assertJsonPath('data.0.uptime_24h_pct', fn ($v) => (float) $v === 100.0)
        ->assertJsonStructure(['data' => [['uptime_24h_pct', 'uptime_trend_24h']]]);
});

test('a never-reported site exposes null uptime and an empty trend in the list', function () {
    [$org, $user] = makeUserWithOrg();
    Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/dashboard/sites')
        ->assertStatus(200)
        ->assertJsonPath('data.0.uptime_24h_pct', null)
        ->assertJsonPath('data.0.uptime_trend_24h', []);
});

// ---------------------------------------------------------------------------
// "Clear 24 Hours Uptime" — moves the measurement floor without deleting data.
// ---------------------------------------------------------------------------

test('a reset floor makes a fully-covered site read null until an hour elapses', function () {
    [$org] = makeUserWithOrg();
    $enrolled = now()->subDays(2)->startOfHour();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => $enrolled,
    ]);
    for ($c = now()->subHours(26)->startOfHour(); $c->lte(now()); $c->addHour()) {
        makeHeartbeatAt($site, $c);
    }

    // Before the reset the site reads 100%.
    expect(SiteUptime::averagePct($site))->toBe(100.0);

    // Stamp the floor at "now" — no full clock hour has elapsed since.
    $site->update(['uptime_reset_at' => now()]);
    $site->refresh();

    expect(SiteUptime::averagePct($site))->toBeNull();
    expect(SiteUptime::trend($site))->toBe([]);
});

test('POST /sites/reset-uptime stamps every visible site and returns the count', function () {
    [$org, $user] = makeUserWithOrg();
    $enrolled = now()->subDays(2)->startOfHour();
    $a = Site::factory()->create(['organization_id' => $org->id, 'enrolled_at' => $enrolled]);
    $b = Site::factory()->create(['organization_id' => $org->id, 'enrolled_at' => $enrolled]);
    foreach ([$a, $b] as $s) {
        for ($c = now()->subHours(26)->startOfHour(); $c->lte(now()); $c->addHour()) {
            makeHeartbeatAt($s, $c);
        }
    }

    $this->actingAs($user)
        ->postJson('/api/dashboard/sites/reset-uptime')
        ->assertStatus(200)
        ->assertJsonPath('reset', 2);

    expect($a->fresh()->uptime_reset_at)->not->toBeNull();
    expect($b->fresh()->uptime_reset_at)->not->toBeNull();

    // Heartbeats are preserved (audit-safe) — only the measurement floor moved.
    expect(SiteHeartbeat::where('site_id', $a->id)->count())->toBeGreaterThan(0);
    expect(SiteUptime::averagePct($a->fresh()))->toBeNull();
});

test('a subscriber reset only affects their own sites, never other accounts', function () {
    [$org, $owner] = makeUserWithOrg();
    [, $subscriber] = makeUserWithOrg([], 'subscriber');
    // Put the subscriber in the same organization.
    \App\Models\OrganizationMembership::where('user_id', $subscriber->id)->update(['organization_id' => $org->id]);

    $mine = Site::factory()->create([
        'organization_id' => $org->id,
        'owner_user_id' => $subscriber->id,
    ]);
    $theirs = Site::factory()->create([
        'organization_id' => $org->id,
        'owner_user_id' => $owner->id,
    ]);

    $this->actingAs($subscriber)
        ->postJson('/api/dashboard/sites/reset-uptime')
        ->assertStatus(200)
        ->assertJsonPath('reset', 1);

    expect($mine->fresh()->uptime_reset_at)->not->toBeNull();
    expect($theirs->fresh()->uptime_reset_at)->toBeNull();
});
