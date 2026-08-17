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
        'status' => Site::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    $secret = base64_encode(random_bytes(32));
    $encryptor = app(SecretEncryptor::class);
    $this->site->update([
        'site_secret_encrypted' => $encryptor->encrypt($secret),
        'site_secret_kid' => $encryptor->keyId(),
    ]);
    $this->siteSecret = $secret;
});

test('a revoked site receives a deterministic site_revoked 403 on heartbeat', function () {
    $hmac = new HmacService();
    $path = '/api/v1/heartbeat';
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode(['domain' => 'example.com']);

    $canonical = $hmac->buildCanonicalData('POST', $path, [], $timestamp, $nonce, $body);
    $signature = $hmac->generateSignature($canonical, $this->siteSecret);

    $this->postJson($path, json_decode($body, true), [
        'X-MarQira-Site' => $this->site->uuid,
        'X-MarQira-Timestamp' => $timestamp,
        'X-MarQira-Nonce' => $nonce,
        'X-MarQira-Kid' => $this->site->site_secret_kid,
        'X-MarQira-Signature' => $signature,
    ])->assertStatus(403)
        ->assertJsonPath('error', 'site_revoked')
        ->assertJsonPath('site_revoked', true);
});
