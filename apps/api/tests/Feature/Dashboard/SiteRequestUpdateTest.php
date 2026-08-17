<?php

use App\Models\PluginRelease;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Phase 7: Remote "update this site now" — dashboard request endpoint
// ---------------------------------------------------------------------------

function activeRelease(string $version = '1.2.2'): PluginRelease
{
    return PluginRelease::create([
        'version' => $version,
        'download_url' => "https://downloads.marqira.com/marqira-connector-{$version}.zip",
        'is_active' => true,
        'released_at' => now(),
    ]);
}

test('request-update queues a pending command when an update is available', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => '1.2.2']);
    activeRelease('1.2.3');

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update")
        ->assertStatus(200)
        ->assertJsonPath('data.command.status', 'pending')
        ->assertJsonPath('data.command.target_version', '1.2.3')
        ->assertJsonPath('data.remote_update_supported', true);

    $site->refresh();
    expect($site->update_command_status)->toBe('pending');
    expect($site->update_command_target_version)->toBe('1.2.3');
    expect($site->update_command_requested_by)->toBe($user->id);
});

test('request-update reports remote_update_supported=false for old connectors but still queues', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => '1.2.0']);
    activeRelease('1.2.2');

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update")
        ->assertStatus(200)
        ->assertJsonPath('data.remote_update_supported', false)
        ->assertJsonPath('data.command.status', 'pending');
});

test('request-update rejects when the site is already up to date', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => '1.2.2']);
    activeRelease('1.2.2');

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update")
        ->assertStatus(422);

    expect($site->fresh()->update_command_status)->toBeNull();
});

test('request-update rejects when there is no active release', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => '1.2.0']);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update")
        ->assertStatus(422);
});

test('request-update returns 409 when a command is already in flight', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'plugin_version' => '1.2.2',
        'update_command_status' => 'dispatched',
        'update_command_target_version' => '1.2.3',
    ]);
    activeRelease('1.2.3');

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update")
        ->assertStatus(409);
});

test('request-update is tenant-scoped (404 across tenants)', function () {
    [$org, $user] = makeUserWithOrg();
    [$otherOrg] = makeUserWithOrg();
    $foreignSite = Site::factory()->create(['organization_id' => $otherOrg->id, 'plugin_version' => '1.2.0']);
    activeRelease('1.2.2');

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$foreignSite->uuid}/request-update")
        ->assertStatus(404);
});

test('update-status exposes the command block and remote_update_supported', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => '1.2.2']);
    activeRelease('1.2.3');

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.remote_update_supported', true)
        ->assertJsonPath('data.command.status', null);
});
