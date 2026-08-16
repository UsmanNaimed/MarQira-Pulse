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
    $this->site = Site::factory()->create(['organization_id' => $this->org->id]);
    
    $secret = base64_encode(random_bytes(32));
    $encryptor = app(SecretEncryptor::class);
    
    $this->site->update([
        'site_secret_encrypted' => $encryptor->encrypt($secret),
        'site_secret_kid' => $encryptor->keyId(),
    ]);
    
    $this->siteSecret = $secret;
});

function sendAuthenticatedGet($site, $secret, $path)
{
    $hmacService = new HmacService();
    
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    
    $canonical = $hmacService->buildCanonicalData('GET', $path, [], $timestamp, $nonce, '');
    $signature = $hmacService->generateSignature($canonical, $secret);

    // The plugin issues a GET with a genuinely empty body (SHA-256 of "").
    // Laravel's getJson() test helper would inject a "[]" body, breaking the
    // signature, so send the request with an explicitly empty raw body.
    $headers = [
        'X-MarQira-Site' => $site->uuid,
        'X-MarQira-Timestamp' => $timestamp,
        'X-MarQira-Nonce' => $nonce,
        'X-MarQira-Kid' => $site->site_secret_kid,
        'X-MarQira-Signature' => $signature,
        'Accept' => 'application/json',
    ];

    $server = [];
    foreach ($headers as $key => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
    }

    return test()->call('GET', $path, [], [], [], $server, '');
}

test('allowed IPs endpoint returns IP list', function () {
    $response = sendAuthenticatedGet($this->site, $this->siteSecret, '/api/v1/config/allowed-ips');
    
    $response->assertStatus(200)
        ->assertJsonStructure(['allowed_ips'])
        ->assertJsonFragment(['allowed_ips' => ['187.77.136.105']]);
});

test('cloudflare ranges endpoint returns ranges', function () {
    $response = sendAuthenticatedGet($this->site, $this->siteSecret, '/api/v1/config/cloudflare-ranges');
    
    $response->assertStatus(200)
        ->assertJsonStructure(['ipv4', 'ipv6']);
    
    expect($response->json('ipv4'))->toBeArray();
    expect($response->json('ipv6'))->toBeArray();
});

test('config endpoints require HMAC auth', function () {
    $response = $this->getJson('/api/v1/config/allowed-ips');
    
    $response->assertStatus(400); // Missing headers
});
