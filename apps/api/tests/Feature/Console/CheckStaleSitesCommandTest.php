<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('marks sites offline when heartbeat stale', function () {
    $org = Organization::factory()->create();
    
    $staleSite = Site::factory()->create([
        'organization_id' => $org->id,
        'status' => 'online',
        'last_heartbeat_at' => now()->subMinutes(35), // Stale
    ]);
    
    $this->artisan('marqira:check-stale-sites')
        ->assertExitCode(0);
    
    $staleSite->refresh();
    
    expect($staleSite->status)->toBe('offline');
});

test('does not mark recently active sites offline', function () {
    $org = Organization::factory()->create();
    
    $activeSite = Site::factory()->create([
        'organization_id' => $org->id,
        'status' => 'online',
        'last_heartbeat_at' => now()->subMinutes(10), // Recent
    ]);
    
    $this->artisan('marqira:check-stale-sites')
        ->assertExitCode(0);
    
    $activeSite->refresh();
    
    expect($activeSite->status)->toBe('online');
});

test('logs audit entry for each marked site', function () {
    $org = Organization::factory()->create();
    
    $staleSite = Site::factory()->create([
        'organization_id' => $org->id,
        'status' => 'online',
        'last_heartbeat_at' => now()->subMinutes(35),
    ]);
    
    $this->artisan('marqira:check-stale-sites');
    
    expect(AuditLog::where('event', 'site_marked_offline')
        ->where('subject_id', $staleSite->id)
        ->exists())->toBeTrue();
});
