<?php

use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteHeartbeat;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| marqira:prune-old-heartbeats
|--------------------------------------------------------------------------
| Heartbeats are append-only telemetry. The prune command keeps the table
| bounded by deleting rows older than the configured retention window.
*/

function makeHeartbeat(Site $site, $receivedAt): SiteHeartbeat
{
    return SiteHeartbeat::create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'received_at' => $receivedAt,
        'wp_version' => '6.4.2',
        'php_version' => '8.2.0',
        'plugin_version' => '1.1.1',
    ]);
}

test('prunes heartbeats older than the retention window', function () {
    $org = Organization::factory()->create();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    $retentionDays = (int) config('marqira.log.heartbeat_retention_days', 30);

    $old = makeHeartbeat($site, now()->subDays($retentionDays + 5));
    $recent = makeHeartbeat($site, now()->subDays(1));

    $this->artisan('marqira:prune-old-heartbeats')->assertExitCode(0);

    expect(SiteHeartbeat::whereKey($old->id)->exists())->toBeFalse();
    expect(SiteHeartbeat::whereKey($recent->id)->exists())->toBeTrue();
});

test('keeps all heartbeats when none are older than retention', function () {
    $org = Organization::factory()->create();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    makeHeartbeat($site, now()->subDays(1));
    makeHeartbeat($site, now()->subHours(2));

    $this->artisan('marqira:prune-old-heartbeats')->assertExitCode(0);

    expect(SiteHeartbeat::count())->toBe(2);
});
