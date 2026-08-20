<?php

use App\Models\Organization;
use App\Models\Site;
use App\Services\Encryption\SecretEncryptor;
use App\Services\Hmac\HmacService;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Redis::flushDB();

    $this->org = Organization::factory()->create();
    $this->site = Site::factory()->create([
        'organization_id' => $this->org->id,
        'plugin_version' => '1.2.2',
    ]);

    $secret = base64_encode(random_bytes(32));
    $encryptor = app(SecretEncryptor::class);

    $this->site->update([
        'site_secret_encrypted' => $encryptor->encrypt($secret),
        'site_secret_kid' => $encryptor->keyId(),
    ]);

    $this->siteSecret = $secret;
});

function signedRequest(string $method, string $path, array $payload, $site, string $secret)
{
    $hmacService = new HmacService();
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode($payload);

    $canonical = $hmacService->buildCanonicalData($method, $path, [], $timestamp, $nonce, $body);
    $signature = $hmacService->generateSignature($canonical, $secret);

    $headers = [
        'X-MarQira-Site' => $site->uuid,
        'X-MarQira-Timestamp' => $timestamp,
        'X-MarQira-Nonce' => $nonce,
        'X-MarQira-Kid' => $site->site_secret_kid,
        'X-MarQira-Signature' => $signature,
    ];

    return $method === 'POST'
        ? test()->postJson($path, $payload, $headers)
        : test()->getJson($path, $headers);
}

function beat($site, $secret, array $overrides = [])
{
    $payload = array_merge([
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'wp_version' => '6.4.2',
        'php_version' => '8.2.0',
        'plugin_version' => '1.2.2',
    ], $overrides);

    return signedRequest('POST', '/api/v1/heartbeat', $payload, $site, $secret);
}

// ---------------------------------------------------------------------------
// Heartbeat delivers a pending update command and marks it dispatched
// ---------------------------------------------------------------------------

test('a pending command is delivered in the heartbeat response and marked dispatched', function () {
    $this->site->update([
        'update_command_status' => 'pending',
        'update_command_target_version' => '1.2.3',
        'update_command_requested_at' => now(),
    ]);

    $response = beat($this->site, $this->siteSecret, ['plugin_version' => '1.2.2']);

    $response->assertStatus(200)
        ->assertJsonPath('commands.0.type', 'update_plugin')
        ->assertJsonPath('commands.0.target_version', '1.2.3');

    $this->site->refresh();
    expect($this->site->update_command_status)->toBe('dispatched');
    expect($this->site->update_command_dispatched_at)->not->toBeNull();
});

test('a pending core-update command is delivered as update_core with no target_version', function () {
    $this->site->update([
        'update_command_status' => 'pending',
        'update_command_type' => 'core',
        'update_command_target_version' => null,
        'update_command_requested_at' => now(),
    ]);

    $response = beat($this->site, $this->siteSecret, ['plugin_version' => '1.2.3']);

    $response->assertStatus(200)
        ->assertJsonPath('commands.0.type', 'update_core')
        ->assertJsonMissingPath('commands.0.target_version');

    $this->site->refresh();
    expect($this->site->update_command_status)->toBe('dispatched');
});

test('a pending all-plugins command is delivered as update_all_plugins', function () {
    $this->site->update([
        'update_command_status' => 'pending',
        'update_command_type' => 'plugins',
        'update_command_target_version' => null,
        'update_command_requested_at' => now(),
    ]);

    beat($this->site, $this->siteSecret, ['plugin_version' => '1.2.3'])
        ->assertStatus(200)
        ->assertJsonPath('commands.0.type', 'update_all_plugins');

    expect($this->site->fresh()->update_command_status)->toBe('dispatched');
});

test('a pending command resolves to completed with no command when site already at target', function () {
    $this->site->update([
        'update_command_status' => 'pending',
        'update_command_target_version' => '1.2.2',
        'update_command_requested_at' => now(),
    ]);

    // Site reports it is already running the target version.
    $response = beat($this->site, $this->siteSecret, ['plugin_version' => '1.2.2']);

    $response->assertStatus(200)
        ->assertJsonMissingPath('commands');

    $this->site->refresh();
    expect($this->site->update_command_status)->toBe('completed');
});

test('heartbeat with no queued command returns no commands key', function () {
    $response = beat($this->site, $this->siteSecret);

    $response->assertStatus(200)
        ->assertJsonMissingPath('commands');
});

// ---------------------------------------------------------------------------
// Connector ack endpoint resolves the command
// ---------------------------------------------------------------------------

test('ack marks a dispatched command completed and updates the reported version', function () {
    $this->site->update([
        'update_command_status' => 'dispatched',
        'update_command_target_version' => '1.2.3',
        'update_command_dispatched_at' => now(),
    ]);

    $response = signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'completed',
        'message' => 'Plugin updated successfully.',
        'version' => '1.2.3',
    ], $this->site, $this->siteSecret);

    $response->assertStatus(200)->assertJson(['success' => true]);

    $this->site->refresh();
    expect($this->site->update_command_status)->toBe('completed');
    expect($this->site->update_command_completed_at)->not->toBeNull();
    expect($this->site->plugin_version)->toBe('1.2.3');
    expect($this->site->update_command_message)->toBe('Plugin updated successfully.');
});

test('ack records a failure with its message', function () {
    $this->site->update([
        'update_command_status' => 'dispatched',
        'update_command_target_version' => '1.2.3',
        'update_command_dispatched_at' => now(),
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'failed',
        'message' => 'Package download failed.',
    ], $this->site, $this->siteSecret)->assertStatus(200);

    $this->site->refresh();
    expect($this->site->update_command_status)->toBe('failed');
    expect($this->site->update_command_message)->toBe('Package download failed.');
});

test('ack is ignored when there is no command in flight', function () {
    // No command queued.
    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'completed',
        'version' => '1.2.3',
    ], $this->site, $this->siteSecret)
        ->assertStatus(200)
        ->assertJsonPath('ignored', true);

    // plugin_version must NOT be overwritten by a stale ack.
    expect($this->site->fresh()->plugin_version)->toBe('1.2.2');
});

test('ack validates the status value', function () {
    $this->site->update([
        'update_command_status' => 'dispatched',
        'update_command_target_version' => '1.2.3',
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'bogus',
    ], $this->site, $this->siteSecret)->assertStatus(422);
});


// ---------------------------------------------------------------------------
// Phase A: granular progress states + command_id correlation on ack
// ---------------------------------------------------------------------------

test('ack accepts granular progress states without resolving the command', function () {
    $this->site->update([
        'update_command_status' => 'dispatched',
        'update_command_type' => 'plugin',
        'update_command_target_version' => '1.2.3',
        'update_command_dispatched_at' => now(),
    ]);

    foreach (['starting', 'downloading', 'installing', 'verifying'] as $state) {
        signedRequest('POST', '/api/v1/update-command/ack', [
            'status' => $state,
        ], $this->site, $this->siteSecret)->assertStatus(200);

        $this->site->refresh();
        expect($this->site->update_command_status)->toBe($state);
        // Progress states are NOT terminal, so completed_at stays null.
        expect($this->site->update_command_completed_at)->toBeNull();
    }
});

test('ack records a rolled_back terminal state', function () {
    $this->site->update([
        'update_command_status' => 'installing',
        'update_command_type' => 'plugin',
        'update_command_dispatched_at' => now(),
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'rolled_back',
        'message' => 'Update reverted after a critical error.',
    ], $this->site, $this->siteSecret)->assertStatus(200);

    $this->site->refresh();
    expect($this->site->update_command_status)->toBe('rolled_back');
    expect($this->site->update_command_completed_at)->not->toBeNull();
});

test('ack with a matching command_id is applied', function () {
    $this->site->update([
        'update_command_status' => 'dispatched',
        'update_command_type' => 'plugin',
        'update_command_id' => 'cmd-abc-123',
        'update_command_dispatched_at' => now(),
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'installing',
        'command_id' => 'cmd-abc-123',
    ], $this->site, $this->siteSecret)->assertStatus(200);

    expect($this->site->fresh()->update_command_status)->toBe('installing');
});

test('ack with a mismatched command_id is ignored (superseded command)', function () {
    $this->site->update([
        'update_command_status' => 'dispatched',
        'update_command_type' => 'plugin',
        'update_command_id' => 'cmd-new-999',
        'update_command_dispatched_at' => now(),
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'completed',
        'command_id' => 'cmd-old-111',
        'version' => '9.9.9',
    ], $this->site, $this->siteSecret)
        ->assertStatus(200)
        ->assertJsonPath('ignored', true);

    // The stale ack must not have advanced or resolved the current command.
    expect($this->site->fresh()->update_command_status)->toBe('dispatched');
});
