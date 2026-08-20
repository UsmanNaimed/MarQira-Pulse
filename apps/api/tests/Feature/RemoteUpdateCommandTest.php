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


// ---------------------------------------------------------------------------
// Phase B: critical-error protection & automatic recovery payload on ack
// ---------------------------------------------------------------------------

test('ack persists a recovery report describing an automatic rollback', function () {
    $this->site->update([
        'update_command_status' => 'installing',
        'update_command_type' => 'plugin',
        'update_command_dispatched_at' => now(),
    ]);

    $recovery = [
        'action_id' => 'cmd-abc-123',
        'type' => 'update_plugin',
        'rolled_back' => true,
        'recovered' => true,
        'reason' => null,
        'detail' => 'Update reverted after a critical error; previous version restored.',
        'health' => [
            'healthy' => true,
            'checks' => [
                ['name' => 'wp_bootstrap', 'status' => 'up'],
                ['name' => 'rest_endpoint', 'status' => 'up'],
            ],
            'summary' => 'Site healthy after rollback.',
        ],
    ];

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'rolled_back',
        'message' => 'Update reverted after a critical error.',
        'recovery' => $recovery,
    ], $this->site, $this->siteSecret)->assertStatus(200);

    $this->site->refresh();
    expect($this->site->update_command_status)->toBe('rolled_back');
    // The recovery report is cast to an array and stored verbatim.
    expect($this->site->update_command_recovery)->toBeArray();
    expect($this->site->update_command_recovery['rolled_back'])->toBeTrue();
    expect($this->site->update_command_recovery['recovered'])->toBeTrue();
    expect($this->site->update_command_recovery['health']['healthy'])->toBeTrue();
});

test('ack records a pre-existing-critical recovery report without blaming the update', function () {
    $this->site->update([
        'update_command_status' => 'dispatched',
        'update_command_type' => 'plugin',
        'update_command_dispatched_at' => now(),
    ]);

    $recovery = [
        'action_id' => 'cmd-xyz-777',
        'type' => 'update_plugin',
        'rolled_back' => false,
        'recovered' => false,
        'reason' => 'pre_existing_critical',
        'detail' => 'The site was already in a critical state before this update; no changes were made.',
    ];

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'failed',
        'message' => 'Site was already broken before the update ran.',
        'recovery' => $recovery,
    ], $this->site, $this->siteSecret)->assertStatus(200);

    $this->site->refresh();
    expect($this->site->update_command_status)->toBe('failed');
    expect($this->site->update_command_recovery['reason'])->toBe('pre_existing_critical');
    expect($this->site->update_command_recovery['rolled_back'])->toBeFalse();
});

test('ack without a recovery key leaves the stored recovery report untouched as null', function () {
    $this->site->update([
        'update_command_status' => 'dispatched',
        'update_command_type' => 'plugin',
        'update_command_target_version' => '1.2.3',
        'update_command_dispatched_at' => now(),
        'update_command_recovery' => null,
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'completed',
        'version' => '1.2.3',
    ], $this->site, $this->siteSecret)->assertStatus(200);

    expect($this->site->fresh()->update_command_recovery)->toBeNull();
});

test('ack rejects a non-array recovery payload', function () {
    $this->site->update([
        'update_command_status' => 'dispatched',
        'update_command_type' => 'plugin',
        'update_command_dispatched_at' => now(),
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'rolled_back',
        'recovery' => 'not-an-array',
    ], $this->site, $this->siteSecret)->assertStatus(422);
});


// ---------------------------------------------------------------------------
// Update-count refresh: a completed update clears the pending counts at once
// (so the dashboard shows 0 pending immediately, not after the next heartbeat)
// ---------------------------------------------------------------------------

test('completing an all-plugins update zeroes the pending plugin count immediately', function () {
    $this->site->update([
        'update_command_status' => 'installing',
        'update_command_type' => 'plugins',
        'update_command_dispatched_at' => now(),
        'plugin_updates_count' => 7,
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'completed',
        'message' => 'All plugins updated.',
    ], $this->site, $this->siteSecret)->assertStatus(200);

    expect((int) $this->site->fresh()->plugin_updates_count)->toBe(0);
});

test('completing an all-themes update zeroes the pending theme count immediately', function () {
    $this->site->update([
        'update_command_status' => 'installing',
        'update_command_type' => 'themes',
        'update_command_dispatched_at' => now(),
        'theme_updates_count' => 3,
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'completed',
    ], $this->site, $this->siteSecret)->assertStatus(200);

    expect((int) $this->site->fresh()->theme_updates_count)->toBe(0);
});

test('completing a core update clears the core-update-available flag immediately', function () {
    $this->site->update([
        'update_command_status' => 'installing',
        'update_command_type' => 'core',
        'update_command_dispatched_at' => now(),
        'core_update_available' => true,
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'completed',
    ], $this->site, $this->siteSecret)->assertStatus(200);

    expect((bool) $this->site->fresh()->core_update_available)->toBeFalse();
});

test('completing a single plugin (self) update decrements the pending plugin count', function () {
    $this->site->update([
        'update_command_status' => 'installing',
        'update_command_type' => 'plugin',
        'update_command_dispatched_at' => now(),
        'plugin_updates_count' => 4,
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'completed',
        'version' => '1.2.12',
    ], $this->site, $this->siteSecret)->assertStatus(200);

    expect((int) $this->site->fresh()->plugin_updates_count)->toBe(3);
});

test('a failed update does not alter the pending update counts', function () {
    $this->site->update([
        'update_command_status' => 'installing',
        'update_command_type' => 'plugins',
        'update_command_dispatched_at' => now(),
        'plugin_updates_count' => 5,
    ]);

    signedRequest('POST', '/api/v1/update-command/ack', [
        'status' => 'failed',
        'message' => 'Download failed.',
    ], $this->site, $this->siteSecret)->assertStatus(200);

    expect((int) $this->site->fresh()->plugin_updates_count)->toBe(5);
});
