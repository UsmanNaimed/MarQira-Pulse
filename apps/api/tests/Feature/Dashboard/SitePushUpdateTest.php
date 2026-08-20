<?php

use App\Models\PluginRelease;
use App\Models\Site;
use App\Services\Connector\ConnectorClient;
use App\Services\Encryption\SecretEncryptor;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Phase A: API -> site push delivery, granular status, stale recovery, dedup
// ---------------------------------------------------------------------------

/**
 * Create a site whose HMAC secret can be decrypted and signed with, so the
 * push path in ConnectorClient runs for real (against a faked HTTP endpoint).
 */
function pushableSite($org, string $pluginVersion = '1.2.10'): Site
{
    $encryptor = app(SecretEncryptor::class);
    $secret = base64_encode(random_bytes(32));

    return Site::factory()->create([
        'organization_id'       => $org->id,
        'plugin_version'        => $pluginVersion,
        'home_url'              => 'https://push-test.example',
        'site_url'              => 'https://push-test.example',
        'site_secret_encrypted' => $encryptor->encrypt($secret),
        'site_secret_kid'       => $encryptor->keyId(),
    ]);
}

function pushActiveRelease(string $version = '1.2.11'): PluginRelease
{
    return PluginRelease::create([
        'version'      => $version,
        'download_url' => "https://downloads.marqira.com/marqira-connector-{$version}.zip",
        'is_active'    => true,
        'released_at'  => now(),
    ]);
}

test('a push-capable connector accepts the command and it becomes queued', function () {
    [$org, $user] = makeUserWithOrg();
    $site = pushableSite($org, '1.2.10');
    pushActiveRelease('1.2.11');

    Http::fake([
        '*/wp-json/marqira/v1/execute-update' => Http::response([
            'accepted' => true,
            'state'    => 'queued',
        ], 202),
    ]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update")
        ->assertStatus(200)
        ->assertJsonPath('data.command.status', 'queued')
        ->assertJsonPath('data.command.in_flight', true);

    $site->refresh();
    expect($site->update_command_status)->toBe('queued');
    expect($site->update_command_dispatched_at)->not->toBeNull();
    expect($site->update_command_id)->not->toBeNull();

    // The push was signed with all five HMAC headers.
    Http::assertSent(function ($request) use ($site) {
        return $request->hasHeader('X-MarQira-Site', $site->uuid)
            && $request->hasHeader('X-MarQira-Signature')
            && $request->hasHeader('X-MarQira-Nonce')
            && $request->hasHeader('X-MarQira-Timestamp')
            && $request->hasHeader('X-MarQira-Kid');
    });
});

test('a failed push falls back to pending for heartbeat delivery', function () {
    [$org, $user] = makeUserWithOrg();
    $site = pushableSite($org, '1.2.10');
    pushActiveRelease('1.2.11');

    // Both the pretty-permalink URL and the ?rest_route fallback are unreachable.
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update")
        ->assertStatus(200)
        ->assertJsonPath('data.command.status', 'pending');

    $site->refresh();
    expect($site->update_command_status)->toBe('pending');
    // A command id is still assigned so the heartbeat channel can correlate it.
    expect($site->update_command_id)->not->toBeNull();
    expect($site->update_command_message)->toContain('next heartbeat');
});

test('older connectors never attempt a push and stay pending', function () {
    [$org, $user] = makeUserWithOrg();
    $site = pushableSite($org, '1.2.4'); // below PUSH_UPDATE_MIN_VERSION
    pushActiveRelease('1.2.11');

    Http::fake();

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update")
        ->assertStatus(200)
        ->assertJsonPath('data.command.status', 'pending');

    Http::assertNothingSent();
});

test('a stale in-flight command is auto-failed so the UI never hangs', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id'              => $org->id,
        'plugin_version'               => '1.2.10',
        'update_command_status'        => 'installing',
        'update_command_type'          => 'plugin',
        'update_command_requested_at'  => now()->subMinutes(60),
        'update_command_dispatched_at' => now()->subMinutes(59),
    ]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.command.status', 'failed');

    $site->refresh();
    expect($site->update_command_status)->toBe('failed');
    expect($site->update_command_completed_at)->not->toBeNull();
    expect($site->update_command_message)->toContain('timed out');
});

test('a fresh in-flight command is not reconciled as stale', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id'              => $org->id,
        'plugin_version'               => '1.2.10',
        'update_command_status'        => 'downloading',
        'update_command_type'          => 'plugin',
        'update_command_requested_at'  => now()->subMinutes(2),
        'update_command_dispatched_at' => now()->subMinutes(2),
    ]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.command.status', 'downloading');
});

test('a stale command no longer blocks a new request-update', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id'              => $org->id,
        'plugin_version'               => '1.2.4', // no push, stays pending
        'update_command_status'        => 'dispatched',
        'update_command_type'          => 'plugin',
        'update_command_requested_at'  => now()->subMinutes(60),
        'update_command_dispatched_at' => now()->subMinutes(59),
    ]);
    pushActiveRelease('1.2.11');

    // Would 409 if the stale command still counted as in flight.
    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update")
        ->assertStatus(200)
        ->assertJsonPath('data.command.status', 'pending');
});
