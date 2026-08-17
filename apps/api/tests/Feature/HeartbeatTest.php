<?php

use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteHeartbeat;
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

function sendAuthenticatedHeartbeat($site, $secret, $payload = [])
{
    $hmacService = new HmacService();
    
    $defaultPayload = [
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'wp_version' => '6.4.2',
        'php_version' => '8.2.0',
        'plugin_version' => '1.1.0',
    ];
    
    $payload = array_merge($defaultPayload, $payload);
    
    $path = '/api/v1/heartbeat';
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $body = json_encode($payload);
    
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

test('authenticated heartbeat creates heartbeat record', function () {
    $response = sendAuthenticatedHeartbeat($this->site, $this->siteSecret);
    
    $response->assertStatus(200)
        ->assertJson(['success' => true]);
    
    expect(SiteHeartbeat::where('site_id', $this->site->id)->exists())->toBeTrue();
});

test('heartbeat updates site last_heartbeat_at', function () {
    $before = $this->site->last_heartbeat_at;
    
    sendAuthenticatedHeartbeat($this->site, $this->siteSecret);
    
    $this->site->refresh();
    
    expect($this->site->last_heartbeat_at)->not->toBeNull();
    expect($this->site->last_heartbeat_at)->not->toBe($before);
});

test('heartbeat sets site status to online', function () {
    $this->site->update(['status' => 'offline']);
    
    sendAuthenticatedHeartbeat($this->site, $this->siteSecret);
    
    $this->site->refresh();
    
    expect($this->site->status)->toBe('online');
});

test('unauthenticated heartbeat returns 400', function () {
    $response = $this->postJson('/api/v1/heartbeat', [
        'domain' => 'example.com',
    ]);
    
    $response->assertStatus(400);
});

test('heartbeat stores the update inventory when the connector reports it', function () {
    sendAuthenticatedHeartbeat($this->site, $this->siteSecret, [
        'plugin_version' => '1.2.4',
        'updates' => ['core' => true, 'plugins' => 3, 'themes' => 2],
    ]);

    $this->site->refresh();

    expect((bool) $this->site->core_update_available)->toBeTrue();
    expect((int) $this->site->plugin_updates_count)->toBe(3);
    expect((int) $this->site->theme_updates_count)->toBe(2);
    expect($this->site->updates_checked_at)->not->toBeNull();
});

test('heartbeat without an updates block leaves the stored inventory untouched', function () {
    $this->site->update([
        'core_update_available' => true,
        'plugin_updates_count' => 5,
        'theme_updates_count' => 1,
    ]);

    sendAuthenticatedHeartbeat($this->site, $this->siteSecret, ['plugin_version' => '1.2.3']);

    $this->site->refresh();

    // Older connectors omit the block; we must not zero out prior data.
    expect((bool) $this->site->core_update_available)->toBeTrue();
    expect((int) $this->site->plugin_updates_count)->toBe(5);
    expect((int) $this->site->theme_updates_count)->toBe(1);
});
