<?php

use App\Models\AuditLog;
use App\Models\OriginIpHistory;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Phase 6: Manual origin IP verification
//
// Regression guard for the bug where SiteOriginController called
// AuditLog::record() with NAMED arguments (organization_id: ...) while the
// method signature is record(array $attributes). That threw an
// ArgumentCountError which surfaced to the dashboard as a generic
// "Verification failed" 500.
// ---------------------------------------------------------------------------

test('owner can verify a site origin ip and it persists', function () {
    [$org, $user] = makeUserWithOrg();

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'origin_ip' => '216.245.210.122',
        'origin_ip_source' => 'dns_a_only',
        'origin_ip_confidence' => 'low',
        'origin_ip_verified' => false,
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/origin/verify", [
            'origin_ip' => '216.245.210.122',
            'notes' => 'Confirmed via cPanel Shared IP Address',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.confidence', 'high');

    // Site row updated.
    $site->refresh();
    expect($site->origin_ip)->toBe('216.245.210.122');
    expect($site->origin_ip_verified)->toBeTrue();
    expect($site->origin_ip_confidence)->toBe('high');
    expect($site->origin_ip_verified_by)->toBe($user->id);

    // History entry written.
    expect(OriginIpHistory::where('site_id', $site->id)
        ->where('event_type', 'verified')->count())->toBe(1);

    // Audit log written (this is the call that previously threw).
    expect(AuditLog::where('event', 'site.origin_verified')
        ->where('subject_id', $site->id)->count())->toBe(1);
});

test('verify rejects an invalid ip with 422', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/origin/verify", [
            'origin_ip' => 'not-an-ip',
        ])
        ->assertStatus(422);
});

test('verify is 404 across tenants', function () {
    [$org, $user] = makeUserWithOrg();
    [$otherOrg] = makeUserWithOrg();
    $foreignSite = Site::factory()->create(['organization_id' => $otherOrg->id]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$foreignSite->uuid}/origin/verify", [
            'origin_ip' => '10.0.0.1',
        ])
        ->assertStatus(404);
});

test('owner can change origin confidence', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'origin_ip' => '216.245.210.122',
        'origin_ip_confidence' => 'low',
    ]);

    $this->actingAs($user)
        ->patchJson("/api/dashboard/sites/{$site->uuid}/origin/confidence", [
            'confidence' => 'medium',
            'notes' => 'manual adjust',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.confidence', 'medium');

    expect(AuditLog::where('event', 'site.origin_confidence_changed')
        ->where('subject_id', $site->id)->count())->toBe(1);
});
