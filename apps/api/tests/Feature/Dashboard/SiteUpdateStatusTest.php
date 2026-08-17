<?php

use App\Models\PluginRelease;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Phase 7: Per-site update status (dashboard "Updates" tab)
// ---------------------------------------------------------------------------

test('update-status reports no active release when none is published', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => '1.2.0']);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.has_active_release', false)
        ->assertJsonPath('data.update_available', false)
        ->assertJsonPath('data.current_version', '1.2.0')
        ->assertJsonPath('data.latest_version', null);
});

test('update-status reports up to date when site matches active release', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => '1.2.1']);

    PluginRelease::create([
        'version' => '1.2.1',
        'download_url' => 'https://downloads.marqira.com/marqira-connector-1.2.1.zip',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.has_active_release', true)
        ->assertJsonPath('data.update_available', false)
        ->assertJsonPath('data.is_up_to_date', true)
        ->assertJsonPath('data.current_version', '1.2.1')
        ->assertJsonPath('data.latest_version', '1.2.1');
});

test('update-status reports update available when active release is newer', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => '1.2.0']);

    PluginRelease::create([
        'version' => '1.2.1',
        'changelog' => 'Phase 7 updater release',
        'download_url' => 'https://downloads.marqira.com/marqira-connector-1.2.1.zip',
        'file_hash' => str_repeat('a', 64),
        'file_size' => 50485,
        'requires_wp' => '5.6',
        'requires_php' => '7.4',
        'tested_up_to' => '6.4',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.has_active_release', true)
        ->assertJsonPath('data.update_available', true)
        ->assertJsonPath('data.is_up_to_date', false)
        ->assertJsonPath('data.current_version', '1.2.0')
        ->assertJsonPath('data.latest_version', '1.2.1')
        ->assertJsonPath('data.release.version', '1.2.1')
        ->assertJsonPath('data.release.file_size', 50485)
        ->assertJsonPath('data.release.download_url', 'https://downloads.marqira.com/marqira-connector-1.2.1.zip');
});

test('update-status treats a site with no reported version as needing an update', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => null]);

    PluginRelease::create([
        'version' => '1.2.1',
        'download_url' => 'https://downloads.marqira.com/marqira-connector-1.2.1.zip',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.update_available', true)
        ->assertJsonPath('data.is_up_to_date', false)
        ->assertJsonPath('data.current_version', null);
});

test('update-status is tenant-scoped (404 across tenants)', function () {
    [$org, $user] = makeUserWithOrg();
    [$otherOrg] = makeUserWithOrg();
    $foreignSite = Site::factory()->create(['organization_id' => $otherOrg->id, 'plugin_version' => '1.2.0']);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$foreignSite->uuid}/update-status")
        ->assertStatus(404);
});
