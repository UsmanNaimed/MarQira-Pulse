<?php

use App\Models\ApiToken;
use App\Models\EnrollmentToken;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Overview
// ---------------------------------------------------------------------------
test('overview returns tenant-scoped counts', function () {
    [$org, $user] = makeUserWithOrg();
    [$otherOrg] = makeUserWithOrg();

    Site::factory()->create(['organization_id' => $org->id, 'status' => 'online', 'origin_ip' => '1.2.3.4', 'origin_ip_verified' => true]);
    Site::factory()->create(['organization_id' => $org->id, 'status' => 'offline', 'origin_ip' => null]);
    Site::factory()->create(['organization_id' => $org->id, 'status' => 'unknown', 'origin_ip' => '5.6.7.8', 'origin_ip_verified' => false]);
    // Another tenant's site must not be counted.
    Site::factory()->create(['organization_id' => $otherOrg->id, 'status' => 'online']);

    $this->actingAs($user)
        ->getJson('/api/dashboard/overview')
        ->assertStatus(200)
        ->assertJsonPath('cards.total', 3)
        ->assertJsonPath('cards.online', 1)
        ->assertJsonPath('cards.offline', 1)
        ->assertJsonPath('cards.needs_attention', 2);
});

// ---------------------------------------------------------------------------
// Sites list
// ---------------------------------------------------------------------------
test('sites list is tenant-scoped, searchable, filterable and paginated', function () {
    [$org, $user] = makeUserWithOrg();
    [$otherOrg] = makeUserWithOrg();

    Site::factory()->create(['organization_id' => $org->id, 'domain' => 'alpha.example.com', 'status' => 'online']);
    Site::factory()->create(['organization_id' => $org->id, 'domain' => 'beta.example.com', 'status' => 'offline']);
    Site::factory()->create(['organization_id' => $otherOrg->id, 'domain' => 'alpha.other.com', 'status' => 'online']);

    // Tenant scoping: only 2 of the tenant's sites are returned.
    $this->actingAs($user)
        ->getJson('/api/dashboard/sites')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 2);

    // Search by domain.
    $this->actingAs($user)
        ->getJson('/api/dashboard/sites?q=alpha')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.domain', 'alpha.example.com');

    // Status filter.
    $this->actingAs($user)
        ->getJson('/api/dashboard/sites?status=offline')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.domain', 'beta.example.com');

    // Pagination meta present.
    $this->actingAs($user)
        ->getJson('/api/dashboard/sites?per_page=5')
        ->assertStatus(200)
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.current_page', 1);
});

test('site detail is reachable within tenant and 404 across tenants', function () {
    [$org, $user] = makeUserWithOrg();
    [$otherOrg] = makeUserWithOrg();

    $mySite = Site::factory()->create(['organization_id' => $org->id]);
    $foreignSite = Site::factory()->create(['organization_id' => $otherOrg->id]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$mySite->uuid}")
        ->assertStatus(200)
        ->assertJsonPath('data.uuid', $mySite->uuid);

    // Cross-tenant access must not leak — 404.
    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$foreignSite->uuid}")
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// API tokens
// ---------------------------------------------------------------------------
test('api token is created, shown once, hashed at rest and revocable', function () {
    [$org, $user] = makeUserWithOrg();

    $create = $this->actingAs($user)->postJson('/api/dashboard/api-tokens', [
        'name' => 'n8n integration',
        'abilities' => ['sites:read'],
        'allowed_ips' => ['10.0.0.0/8'],
    ]);

    $create->assertStatus(201)
        ->assertJsonPath('api_token.name', 'n8n integration');

    $raw = $create->json('token');
    expect($raw)->toStartWith('mq_live_');

    // Stored only as a hash — never the raw token.
    $token = ApiToken::first();
    expect($token->token_hash)->toBe(hash('sha256', $raw));
    expect($token->token_hash)->not->toBe($raw);

    // Listing never returns the raw token.
    $list = $this->actingAs($user)->getJson('/api/dashboard/api-tokens');
    $list->assertStatus(200);
    expect(json_encode($list->json()))->not->toContain($raw);

    // Revoke.
    $this->actingAs($user)
        ->deleteJson("/api/dashboard/api-tokens/{$token->uuid}")
        ->assertStatus(200);

    expect(ApiToken::first()->revoked_at)->not->toBeNull();
});

test('api token rejects invalid ability and invalid cidr', function () {
    [$org, $user] = makeUserWithOrg();

    $this->actingAs($user)->postJson('/api/dashboard/api-tokens', [
        'name' => 'bad',
        'abilities' => ['sites:delete-everything'],
    ])->assertStatus(422);

    $this->actingAs($user)->postJson('/api/dashboard/api-tokens', [
        'name' => 'bad cidr',
        'abilities' => ['sites:read'],
        'allowed_ips' => ['999.999.999.999/33'],
    ])->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Enrollment tokens (connection codes)
// ---------------------------------------------------------------------------
test('enrollment token is generated and shown once', function () {
    [$org, $user] = makeUserWithOrg();

    $response = $this->actingAs($user)->postJson('/api/dashboard/enrollment-tokens');

    $response->assertStatus(201);
    $raw = $response->json('token');
    expect($raw)->toStartWith('MQ-CONNECT-');

    // Stored as hash only.
    $token = EnrollmentToken::first();
    expect($token->token_hash)->toBe(hash('sha256', $raw));
});

// ---------------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------------
test('audit log records dashboard actions and is tenant-scoped', function () {
    [$org, $user] = makeUserWithOrg();

    // Generate an auditable action.
    $this->actingAs($user)->postJson('/api/dashboard/enrollment-tokens')->assertStatus(201);

    $this->actingAs($user)
        ->getJson('/api/dashboard/audit-logs')
        ->assertStatus(200)
        ->assertJsonPath('data.0.event', 'enrollment_token.created');
});

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------
test('settings show returns user and organization', function () {
    [$org, $user] = makeUserWithOrg(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->getJson('/api/dashboard/settings')
        ->assertStatus(200)
        ->assertJsonPath('user.email', 'owner@example.com')
        ->assertJsonPath('organization.name', $org->name);
});

test('password change requires correct current password and minimum length', function () {
    [$org, $user] = makeUserWithOrg();

    // Wrong current password.
    $this->actingAs($user)->patchJson('/api/dashboard/settings/password', [
        'current_password' => 'nope',
        'password' => 'a-brand-new-strong-password',
        'password_confirmation' => 'a-brand-new-strong-password',
    ])->assertStatus(422);

    // Too short.
    $this->actingAs($user)->patchJson('/api/dashboard/settings/password', [
        'current_password' => 'correct-horse-battery',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422);

    // Valid change.
    $this->actingAs($user)->patchJson('/api/dashboard/settings/password', [
        'current_password' => 'correct-horse-battery',
        'password' => 'a-brand-new-strong-password',
        'password_confirmation' => 'a-brand-new-strong-password',
    ])->assertStatus(200);

    expect(\Illuminate\Support\Facades\Hash::check('a-brand-new-strong-password', $user->fresh()->password))->toBeTrue();
});
