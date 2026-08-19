<?php

use App\Models\Site;
use App\Models\SiteHeartbeat;
use App\Services\SiteUptime;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Per-site 7-day uptime — measured from real heartbeats at hourly resolution.
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

test('a brand-new site with no elapsed time reports null uptime and an empty trend', function () {
    [$org] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => now(), // no whole hour has elapsed yet
    ]);

    expect(SiteUptime::averagePct($site, 7))->toBeNull();
    expect(SiteUptime::trend($site, 7))->toBe([]);
});

test('full hourly coverage since enrolment reports 100% uptime', function () {
    [$org] = makeUserWithOrg();
    $enrolled = now()->subDays(2)->startOfHour();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => $enrolled,
    ]);

    // One heartbeat every hour from enrolment through now — every expected
    // hour-bucket is covered.
    for ($c = $enrolled->copy(); $c->lte(now()); $c->addHour()) {
        makeHeartbeatAt($site, $c);
    }

    expect(SiteUptime::averagePct($site, 7))->toBe(100.0);

    $trend = SiteUptime::trend($site, 7);
    // The site existed for (parts of) 3 calendar days: today + the two prior.
    expect(count($trend))->toBe(3);
    foreach ($trend as $pct) {
        expect($pct)->toBe(100.0);
    }
});

test('partial coverage of a full day yields a proportional percentage', function () {
    [$org] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => now()->subDays(2)->startOfDay(),
    ]);

    // Yesterday is a fully-elapsed 24-hour day. Cover exactly 12 of its hours.
    $yesterday = now()->subDay()->startOfDay();
    for ($h = 0; $h < 12; $h++) {
        makeHeartbeatAt($site, $yesterday->copy()->addHours($h));
    }

    $series = SiteUptime::dailySeries($site, 7);
    $day = collect($series)->firstWhere('date', $yesterday->format('Y-m-d'));

    expect($day['uptime_pct'])->toBe(50.0); // 12 of 24 hours
});

test('days before enrolment are null and omitted from the trend and average', function () {
    [$org] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => now()->subDay()->startOfDay(),
    ]);

    $series = SiteUptime::dailySeries($site, 7);

    // The first few days in a 7-day window predate a 1-day-old site → null.
    $nullDays = collect($series)->filter(fn ($d) => $d['uptime_pct'] === null);
    expect($nullDays->count())->toBeGreaterThan(0);

    // The trend never contains those null days.
    expect(count(SiteUptime::trend($site, 7)))->toBe(count($series) - $nullDays->count());
});

test('the site list resource exposes the 7-day uptime headline and trend', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => now()->subDays(2)->startOfHour(),
    ]);
    for ($c = now()->subDays(2)->startOfHour(); $c->lte(now()); $c->addHour()) {
        makeHeartbeatAt($site, $c);
    }

    $this->actingAs($user)
        ->getJson('/api/dashboard/sites')
        ->assertStatus(200)
        ->assertJsonPath('data.0.uptime_7d_pct', fn ($v) => (float) $v === 100.0)
        ->assertJsonStructure(['data' => [['uptime_7d_pct', 'uptime_trend_7d']]]);
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
        ->assertJsonPath('data.0.uptime_7d_pct', null)
        ->assertJsonPath('data.0.uptime_trend_7d', []);
});

// ---------------------------------------------------------------------------
// "Clear 7-Day Uptime" — moves the measurement floor without deleting data.
// ---------------------------------------------------------------------------

test('a reset floor makes a fully-covered site read null until an hour elapses', function () {
    [$org] = makeUserWithOrg();
    $enrolled = now()->subDays(2)->startOfHour();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'enrolled_at' => $enrolled,
    ]);
    for ($c = $enrolled->copy(); $c->lte(now()); $c->addHour()) {
        makeHeartbeatAt($site, $c);
    }

    // Before the reset the site reads 100%.
    expect(SiteUptime::averagePct($site, 7))->toBe(100.0);

    // Stamp the floor at "now" — no full clock hour has elapsed since.
    $site->update(['uptime_reset_at' => now()]);
    $site->refresh();

    expect(SiteUptime::averagePct($site, 7))->toBeNull();
    expect(SiteUptime::trend($site, 7))->toBe([]);
});

test('POST /sites/reset-uptime stamps every visible site and returns the count', function () {
    [$org, $user] = makeUserWithOrg();
    $enrolled = now()->subDays(2)->startOfHour();
    $a = Site::factory()->create(['organization_id' => $org->id, 'enrolled_at' => $enrolled]);
    $b = Site::factory()->create(['organization_id' => $org->id, 'enrolled_at' => $enrolled]);
    foreach ([$a, $b] as $s) {
        for ($c = $enrolled->copy(); $c->lte(now()); $c->addHour()) {
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
    expect(SiteUptime::averagePct($a->fresh(), 7))->toBeNull();
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
