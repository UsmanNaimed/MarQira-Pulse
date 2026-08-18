<?php

use App\Models\Site;
use App\Models\SiteHeartbeat;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Fleet analytics (redesign) — uptime + connector rollout.
 *
 * These endpoints must obey the SAME tenant + account scoping as the rest of
 * the dashboard: a Subscriber only ever sees their own sites, and an Owner's
 * ?account=<uuid> filter narrows (never widens) the fleet. All numbers are
 * derived from real telemetry (site_heartbeats, sites.plugin_version); an
 * empty fleet yields an honest "no data" shape rather than a fake flat line.
 */

function seedHeartbeat(Site $site, ?string $receivedAt = null): void
{
    SiteHeartbeat::create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'received_at' => $receivedAt ?? now(),
        'plugin_version' => $site->plugin_version,
    ]);
}

test('fleet uptime returns an honest no-data shape for an empty fleet', function () {
    // A brand-new tenant with no enrolled sites: every day's denominator is
    // zero, so every percentage is null and has_data is false — the dashboard
    // shows "no data yet" instead of a fake flat line.
    [$org, $owner] = makeUserWithOrg();

    $this->actingAs($owner)
        ->getJson('/api/dashboard/fleet/uptime?range=7')
        ->assertStatus(200)
        ->assertJsonPath('range', 7)
        ->assertJsonPath('has_data', false)
        ->assertJsonPath('average_uptime_pct', null)
        ->assertJsonCount(7, 'series');
});

test('fleet uptime reports an honest zero for an enrolled site that never phoned home', function () {
    // A site enrolled today but silent is genuinely down: today's bucket is 0%,
    // has_data stays false (no heartbeat ever received).
    [$org, $owner] = makeUserWithOrg();
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id]);

    $res = $this->actingAs($owner)
        ->getJson('/api/dashboard/fleet/uptime?range=7')
        ->assertStatus(200)
        ->assertJsonPath('has_data', false);

    $series = $res->json('series');
    expect((float) end($series)['uptime_pct'])->toBe(0.0);
    expect(end($series)['expected'])->toBe(1);
});

test('fleet uptime computes availability from real heartbeats', function () {
    [$org, $owner] = makeUserWithOrg();
    $a = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id]);
    $b = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id]);

    // Both sites enrolled today (created_at fallback); only A reports today.
    seedHeartbeat($a);

    $res = $this->actingAs($owner)
        ->getJson('/api/dashboard/fleet/uptime?range=7')
        ->assertStatus(200)
        ->assertJsonPath('has_data', true);

    // Today's bucket: 1 of 2 enrolled sites reported => 50%.
    $series = $res->json('series');
    $todayPct = end($series)['uptime_pct'];
    expect((float) $todayPct)->toBe(50.0);
});

test('fleet uptime is isolated to the subscriber own sites', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $aSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id]);
    $bSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);

    // A reports, B does not.
    seedHeartbeat($aSite);

    // Subscriber A: 1 of 1 own site reporting => 100% today, isolated from B.
    $resA = $this->actingAs($subA)
        ->getJson('/api/dashboard/fleet/uptime?range=7')
        ->assertStatus(200)
        ->assertJsonPath('has_data', true);
    $seriesA = $resA->json('series');
    expect((float) end($seriesA)["uptime_pct"])->toBe(100.0);
    expect(end($seriesA)['expected'])->toBe(1);

    // Subscriber B: 1 own site, no heartbeat => 0% today, no leak of A's data.
    $resB = $this->actingAs($subB)
        ->getJson('/api/dashboard/fleet/uptime?range=7')
        ->assertStatus(200)
        ->assertJsonPath('has_data', false);
    $seriesB = $resB->json('series');
    expect(end($seriesB)['expected'])->toBe(1);
    expect(end($seriesB)['reporting'])->toBe(0);
});

test('owner fleet uptime narrows to a selected account', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);

    $ownerSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id]);
    $aSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id]);

    seedHeartbeat($ownerSite);
    seedHeartbeat($aSite);

    // Narrowed to Subscriber A: only A's single site counts.
    $res = $this->actingAs($owner)
        ->getJson('/api/dashboard/fleet/uptime?range=7&account=' . $subA->uuid)
        ->assertStatus(200);
    $series = $res->json('series');
    expect(end($series)['expected'])->toBe(1);
    expect(end($series)['reporting'])->toBe(1);
});

test('fleet uptime rejects an invalid range', function () {
    [$org, $owner] = makeUserWithOrg();

    $this->actingAs($owner)
        ->getJson('/api/dashboard/fleet/uptime?range=13')
        ->assertStatus(422);
});

test('fleet rollout groups the authorized fleet by connector version', function () {
    [$org, $owner] = makeUserWithOrg();
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id, 'plugin_version' => '1.2.0']);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id, 'plugin_version' => '1.2.0']);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id, 'plugin_version' => '1.1.0']);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id, 'plugin_version' => null]);

    $res = $this->actingAs($owner)
        ->getJson('/api/dashboard/fleet/rollout')
        ->assertStatus(200)
        ->assertJsonPath('total', 4)
        ->assertJsonPath('not_reporting', 1);

    $versions = collect($res->json('versions'));
    // Newest first.
    expect($versions->first()['version'])->toBe('1.2.0');
    expect($versions->firstWhere('version', '1.2.0')['count'])->toBe(2);
    expect($versions->firstWhere('version', '1.1.0')['count'])->toBe(1);
});

test('fleet rollout is isolated per subscriber', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id, 'plugin_version' => '1.2.0']);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id, 'plugin_version' => '1.1.0']);

    $this->actingAs($subA)
        ->getJson('/api/dashboard/fleet/rollout')
        ->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('versions.0.version', '1.2.0');
});

test('overview exposes real trend metrics scoped to the viewer', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);

    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id, 'core_update_available' => true]);

    $this->actingAs($subA)
        ->getJson('/api/dashboard/overview')
        ->assertStatus(200)
        ->assertJsonPath('trends.sites_added_this_month', 1)
        ->assertJsonPath('trends.updates_breakdown.core', 1)
        ->assertJsonStructure([
            'trends' => [
                'sites_added_this_month',
                'uptime_7d_pct',
                'updates_breakdown' => ['core', 'plugins', 'themes'],
            ],
        ]);
});
