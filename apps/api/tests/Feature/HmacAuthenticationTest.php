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

test('valid HMAC signature allows request', function () {
    $hmacService = new HmacService();
    
    $path = '/api/v1/heartbeat';
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode(['domain' => 'example.com']);
    
    $canonical = $hmacService->buildCanonicalData('POST', $path, [], $timestamp, $nonce, $body);
    $signature = $hmacService->generateSignature($canonical, $this->siteSecret);
    
    $response = $this->postJson($path, json_decode($body, true), [
        'X-MarQira-Site' => $this->site->uuid,
        'X-MarQira-Timestamp' => $timestamp,
        'X-MarQira-Nonce' => $nonce,
        'X-MarQira-Kid' => $this->site->site_secret_kid,
        'X-MarQira-Signature' => $signature,
    ]);
    
    // Should not be 401 (auth failure)
    expect($response->status())->not->toBe(401);
});

test('invalid signature returns 401', function () {
    $response = $this->postJson('/api/v1/heartbeat', ['domain' => 'example.com'], [
        'X-MarQira-Site' => $this->site->uuid,
        'X-MarQira-Timestamp' => (string) time(),
        'X-MarQira-Nonce' => bin2hex(random_bytes(16)),
        'X-MarQira-Kid' => $this->site->site_secret_kid,
        'X-MarQira-Signature' => 'invalid-signature',
    ]);
    
    $response->assertStatus(401);
});

test('missing headers return 400', function () {
    $response = $this->postJson('/api/v1/heartbeat', ['domain' => 'example.com']);
    
    $response->assertStatus(400);
});

test('expired timestamp returns 401', function () {
    $hmacService = new HmacService();
    
    $path = '/api/v1/heartbeat';
    $timestamp = (string) (time() - 400); // 6+ minutes ago
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode(['domain' => 'example.com']);
    
    $canonical = $hmacService->buildCanonicalData('POST', $path, [], $timestamp, $nonce, $body);
    $signature = $hmacService->generateSignature($canonical, $this->siteSecret);
    
    $response = $this->postJson($path, json_decode($body, true), [
        'X-MarQira-Site' => $this->site->uuid,
        'X-MarQira-Timestamp' => $timestamp,
        'X-MarQira-Nonce' => $nonce,
        'X-MarQira-Kid' => $this->site->site_secret_kid,
        'X-MarQira-Signature' => $signature,
    ]);
    
    $response->assertStatus(401);
});

test('reused nonce returns 401', function () {
    $hmacService = new HmacService();
    
    $path = '/api/v1/heartbeat';
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode(['domain' => 'example.com', 'home_url' => 'https://example.com', 'site_url' => 'https://example.com']);
    
    $canonical = $hmacService->buildCanonicalData('POST', $path, [], $timestamp, $nonce, $body);
    $signature = $hmacService->generateSignature($canonical, $this->siteSecret);
    
    $headers = [
        'X-MarQira-Site' => $this->site->uuid,
        'X-MarQira-Timestamp' => $timestamp,
        'X-MarQira-Nonce' => $nonce,
        'X-MarQira-Kid' => $this->site->site_secret_kid,
        'X-MarQira-Signature' => $signature,
    ];
    
    // First request should succeed
    $this->postJson($path, json_decode($body, true), $headers);
    
    // Second request with same nonce should fail
    $response = $this->postJson($path, json_decode($body, true), $headers);
    
    $response->assertStatus(401)
        ->assertJsonFragment(['error' => 'Nonce already used']);
});
