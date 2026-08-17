<?php

use App\Models\AuditLog;
use App\Models\PluginRelease;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Phase 7: Plugin Release Management (Owner only)
// ---------------------------------------------------------------------------

test('owner can list all plugin releases', function () {
    [$org, $owner] = makeUserWithOrg();

    PluginRelease::create([
        'version' => '1.0.0',
        'download_url' => 'https://example.com/v1.0.0.zip',
        'is_active' => false,
        'released_at' => now()->subDays(7),
    ]);

    PluginRelease::create([
        'version' => '1.1.0',
        'download_url' => 'https://example.com/v1.1.0.zip',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->actingAs($owner)
        ->getJson('/api/dashboard/plugin-releases')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.version', '1.1.0')
        ->assertJsonPath('data.0.is_active', true);
});

test('owner can create a new plugin release', function () {
    [$org, $owner] = makeUserWithOrg();

    $this->actingAs($owner)
        ->postJson('/api/dashboard/plugin-releases', [
            'version' => '1.2.0',
            'changelog' => 'Bug fixes and improvements',
            'download_url' => 'https://example.com/plugin-1.2.0.zip',
            'file_hash' => hash('sha256', 'test'),
            'file_size' => 2048000,
            'requires_wp' => '6.0',
            'requires_php' => '7.4',
            'tested_up_to' => '6.4',
            'is_active' => true,
        ])
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.version', '1.2.0');

    expect(PluginRelease::where('version', '1.2.0')->count())->toBe(1);
    expect(AuditLog::where('event', 'plugin_release.created')->count())->toBe(1);
});

test('creating a new active release deactivates previous releases', function () {
    [$org, $owner] = makeUserWithOrg();

    $oldRelease = PluginRelease::create([
        'version' => '1.0.0',
        'download_url' => 'https://example.com/v1.0.0.zip',
        'is_active' => true,
        'released_at' => now()->subDays(7),
    ]);

    $this->actingAs($owner)
        ->postJson('/api/dashboard/plugin-releases', [
            'version' => '1.1.0',
            'download_url' => 'https://example.com/v1.1.0.zip',
            'is_active' => true,
        ])
        ->assertStatus(201);

    $oldRelease->refresh();
    expect($oldRelease->is_active)->toBeFalse();
});

test('owner can activate a plugin release', function () {
    [$org, $owner] = makeUserWithOrg();

    $oldRelease = PluginRelease::create([
        'version' => '1.0.0',
        'download_url' => 'https://example.com/v1.0.0.zip',
        'is_active' => true,
        'released_at' => now()->subDays(7),
    ]);

    $newRelease = PluginRelease::create([
        'version' => '1.1.0',
        'download_url' => 'https://example.com/v1.1.0.zip',
        'is_active' => false,
        'released_at' => now(),
    ]);

    $this->actingAs($owner)
        ->postJson("/api/dashboard/plugin-releases/{$newRelease->id}/activate")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $newRelease->refresh();
    $oldRelease->refresh();

    expect($newRelease->is_active)->toBeTrue();
    expect($oldRelease->is_active)->toBeFalse();
    expect(AuditLog::where('event', 'plugin_release.activated')->count())->toBe(1);
});

test('owner cannot delete an active release', function () {
    [$org, $owner] = makeUserWithOrg();

    $release = PluginRelease::create([
        'version' => '1.0.0',
        'download_url' => 'https://example.com/v1.0.0.zip',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->actingAs($owner)
        ->deleteJson("/api/dashboard/plugin-releases/{$release->id}")
        ->assertStatus(422)
        ->assertJsonPath('error', 'Cannot delete the active release');

    expect(PluginRelease::where('id', $release->id)->exists())->toBeTrue();
});

test('owner can delete an inactive release', function () {
    [$org, $owner] = makeUserWithOrg();

    $release = PluginRelease::create([
        'version' => '1.0.0',
        'download_url' => 'https://example.com/v1.0.0.zip',
        'is_active' => false,
        'released_at' => now(),
    ]);

    $this->actingAs($owner)
        ->deleteJson("/api/dashboard/plugin-releases/{$release->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(PluginRelease::where('id', $release->id)->exists())->toBeFalse();
    expect(AuditLog::where('event', 'plugin_release.deleted')->count())->toBe(1);
});

test('version must be unique when creating release', function () {
    [$org, $owner] = makeUserWithOrg();

    PluginRelease::create([
        'version' => '1.0.0',
        'download_url' => 'https://example.com/v1.0.0.zip',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->actingAs($owner)
        ->postJson('/api/dashboard/plugin-releases', [
            'version' => '1.0.0',
            'download_url' => 'https://example.com/v1.0.0.zip',
        ])
        ->assertStatus(422);
});
