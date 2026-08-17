<?php

use App\Models\PluginRelease;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Phase 7: Plugin Update Server
// ---------------------------------------------------------------------------

test('update-check returns no update when no active release exists', function () {
    $this->getJson('/api/v1/plugin/update-check?version=1.0.0')
        ->assertStatus(200)
        ->assertJsonPath('update_available', false)
        ->assertJsonPath('message', 'No active release available');
});

test('update-check returns no update when current version is up to date', function () {
    PluginRelease::create([
        'version' => '1.2.0',
        'download_url' => 'https://example.com/plugin-1.2.0.zip',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->getJson('/api/v1/plugin/update-check?version=1.2.0')
        ->assertStatus(200)
        ->assertJsonPath('update_available', false)
        ->assertJsonPath('latest_version', '1.2.0');
});

test('update-check returns update when newer version is available', function () {
    $release = PluginRelease::create([
        'version' => '1.3.0',
        'changelog' => 'New features and bug fixes',
        'download_url' => 'https://example.com/plugin-1.3.0.zip',
        'file_hash' => hash('sha256', 'test'),
        'file_size' => 1024000,
        'requires_wp' => '6.0',
        'requires_php' => '7.4',
        'tested_up_to' => '6.4',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/plugin/update-check?version=1.2.0')
        ->assertStatus(200)
        ->assertJsonPath('update_available', true)
        ->assertJsonPath('version', '1.3.0')
        ->assertJsonPath('download_url', 'https://example.com/plugin-1.3.0.zip')
        ->assertJsonPath('requires_wp', '6.0')
        ->assertJsonPath('requires_php', '7.4')
        ->assertJsonPath('tested_up_to', '6.4');
});

test('update-check returns 400 when version parameter is missing', function () {
    $this->getJson('/api/v1/plugin/update-check')
        ->assertStatus(400)
        ->assertJsonPath('error', 'Missing version parameter');
});

test('plugin info endpoint returns active release information', function () {
    PluginRelease::create([
        'version' => '1.3.0',
        'changelog' => '## Version 1.3.0\n\n- New feature\n- Bug fixes',
        'download_url' => 'https://example.com/plugin-1.3.0.zip',
        'requires_wp' => '6.0',
        'requires_php' => '7.4',
        'tested_up_to' => '6.4',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->getJson('/api/v1/plugin/info')
        ->assertStatus(200)
        ->assertJsonPath('name', 'MarQira Connector')
        ->assertJsonPath('version', '1.3.0')
        ->assertJsonPath('requires', '6.0')
        ->assertJsonPath('requires_php', '7.4');
});

test('plugin info returns 404 when no active release exists', function () {
    $this->getJson('/api/v1/plugin/info')
        ->assertStatus(404)
        ->assertJsonPath('error', 'No active release available');
});

test('download endpoint redirects to active release URL', function () {
    PluginRelease::create([
        'version' => '1.3.0',
        'download_url' => 'https://example.com/plugin-1.3.0.zip',
        'is_active' => true,
        'released_at' => now(),
    ]);

    $this->getJson('/api/v1/plugin/download')
        ->assertStatus(302)
        ->assertRedirect('https://example.com/plugin-1.3.0.zip');
});

test('download endpoint returns 404 when no active release exists', function () {
    $this->getJson('/api/v1/plugin/download')
        ->assertStatus(404)
        ->assertJsonPath('error', 'No active release available');
});
