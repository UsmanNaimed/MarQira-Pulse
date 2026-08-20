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
        'status' => Site::STATUS_ONLINE,
    ]);

    $secret = base64_encode(random_bytes(32));
    $encryptor = app(SecretEncryptor::class);
    $this->site->update([
        'site_secret_encrypted' => $encryptor->encrypt($secret),
        'site_secret_kid' => $encryptor->keyId(),
    ]);
    $this->siteSecret = $secret;
});

function signedStatusRequest(array $payload, $site, string $secret)
{
    $hmacService = new HmacService();
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode($payload);
    $path = '/api/v1/site-status';

    $canonical = $hmacService->buildCanonicalData('POST', $path, [], $timestamp, $nonce, $body);
    $signature = $hmacService->generateSignature($canonical, $secret);

    return test()->postJson($path, $payload, [
        'X-MarQira-Site' => $site->uuid,
        'X-MarQira-Timestamp' => $timestamp,
        'X-MarQira-Nonce' => $nonce,
        'X-MarQira-Kid' => $site->site_secret_kid,
        'X-MarQira-Signature' => $signature,
    ]);
}

test('an offline signal from the connector marks the site offline immediately', function () {
    signedStatusRequest([
        'state' => 'offline',
        'reason' => 'connector_deactivated',
    ], $this->site, $this->siteSecret)
        ->assertStatus(200)
        ->assertJsonPath('status', Site::STATUS_OFFLINE);

    $this->site->refresh();
    expect($this->site->status)->toBe(Site::STATUS_OFFLINE);
    expect($this->site->offline_since)->not->toBeNull();
});

test('an online signal from the connector marks the site online and clears offline tracking', function () {
    $this->site->update([
        'status' => Site::STATUS_OFFLINE,
        'offline_since' => now()->subHour(),
        'offline_alert_count' => 2,
    ]);

    signedStatusRequest([
        'state' => 'online',
        'reason' => 'connector_activated',
    ], $this->site, $this->siteSecret)
        ->assertStatus(200)
        ->assertJsonPath('status', Site::STATUS_ONLINE);

    $this->site->refresh();
    expect($this->site->status)->toBe(Site::STATUS_ONLINE);
    expect($this->site->offline_since)->toBeNull();
    expect((int) $this->site->offline_alert_count)->toBe(0);
});

test('site-status validates the state value', function () {
    signedStatusRequest([
        'state' => 'bogus',
    ], $this->site, $this->siteSecret)->assertStatus(422);
});

test('a revoked site cannot use lifecycle status signals (rejected at auth)', function () {
    // Revoked credentials are dead: the HMAC middleware rejects the request
    // (403) before it ever reaches the controller, so a revoked site can never
    // signal itself back online.
    $this->site->update([
        'status' => Site::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    signedStatusRequest([
        'state' => 'online',
    ], $this->site, $this->siteSecret)
        ->assertStatus(403);

    expect($this->site->fresh()->status)->toBe(Site::STATUS_REVOKED);
});
