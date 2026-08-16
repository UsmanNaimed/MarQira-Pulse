<?php

use App\Models\EnrollmentToken;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('enrollment with valid token succeeds', function () {
    $org = Organization::factory()->create();
    
    $rawToken = 'MQ-CONNECT-' . strtoupper(Str::random(16));
    $token = EnrollmentToken::create([
        'organization_id' => $org->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(30),
    ]);
    
    $response = $this->postJson('/api/v1/enrollment', [
        'token' => $rawToken,
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'plugin_version' => '1.1.0',
    ]);
    
    $response->assertStatus(201)
        ->assertJson(['success' => true])
        ->assertJsonStructure([
            'site_uuid',
            'site_secret',
            'kid',
            'api_url',
            'heartbeat_interval_seconds',
            'config',
        ]);
    
    expect(Site::where('domain', 'example.com')->exists())->toBeTrue();
});

test('enrollment with expired token fails', function () {
    $org = Organization::factory()->create();
    
    $rawToken = 'MQ-CONNECT-' . strtoupper(Str::random(16));
    EnrollmentToken::create([
        'organization_id' => $org->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->subMinutes(5), // Expired
    ]);
    
    $response = $this->postJson('/api/v1/enrollment', [
        'token' => $rawToken,
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'plugin_version' => '1.1.0',
    ]);
    
    $response->assertStatus(401)
        ->assertJson(['success' => false]);
});

test('enrollment with already-used token fails', function () {
    $org = Organization::factory()->create();
    $site = Site::factory()->create(['organization_id' => $org->id]);
    
    $rawToken = 'MQ-CONNECT-' . strtoupper(Str::random(16));
    EnrollmentToken::create([
        'organization_id' => $org->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(30),
        'used_at' => now(),
        'used_by_site_id' => $site->id,
    ]);
    
    $response = $this->postJson('/api/v1/enrollment', [
        'token' => $rawToken,
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'plugin_version' => '1.1.0',
    ]);
    
    $response->assertStatus(401)
        ->assertJson(['success' => false]);
});

test('enrollment marks token as used', function () {
    $org = Organization::factory()->create();
    
    $rawToken = 'MQ-CONNECT-' . strtoupper(Str::random(16));
    $token = EnrollmentToken::create([
        'organization_id' => $org->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(30),
    ]);
    
    $this->postJson('/api/v1/enrollment', [
        'token' => $rawToken,
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'plugin_version' => '1.1.0',
    ]);
    
    $token->refresh();
    
    expect($token->used_at)->not->toBeNull();
    expect($token->used_by_site_id)->not->toBeNull();
});
