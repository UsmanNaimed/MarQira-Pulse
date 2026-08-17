<?php

use App\Models\EnrollmentToken;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Issue a fresh enrollment token for an org, optionally attributed to a creator.
 */
function makeEnrollmentToken(Organization $org, ?User $createdBy = null): array
{
    $raw = 'MQ-CONNECT-' . strtoupper(Str::random(16));
    $token = EnrollmentToken::create([
        'organization_id' => $org->id,
        'token_hash' => hash('sha256', $raw),
        'expires_at' => now()->addMinutes(30),
        'created_by' => $createdBy?->id,
    ]);

    return [$raw, $token];
}

function makeUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'password' => Hash::make('correct-horse-battery'),
        'platform_role' => User::ROLE_SUBSCRIBER,
        'is_active' => true,
    ], $attrs));
}

test('enrollment sets owner_user_id from the token creator and normalizes the domain', function () {
    $org = Organization::factory()->create();
    $creator = makeUser();
    [$raw] = makeEnrollmentToken($org, $creator);

    $this->postJson('/api/v1/enrollment', [
        'token' => $raw,
        'domain' => 'WWW.Example.COM',
        'home_url' => 'https://www.example.com',
        'site_url' => 'https://www.example.com',
        'plugin_version' => '1.2.0',
    ])->assertStatus(201);

    $site = Site::first();
    expect($site->owner_user_id)->toBe($creator->id);
    expect($site->domain_normalized)->toBe('example.com');
});

test('re-enrolling the same domain reuses the row, rotates the secret and keeps the uuid', function () {
    $org = Organization::factory()->create();
    $creator = makeUser();

    [$raw1] = makeEnrollmentToken($org, $creator);
    $first = $this->postJson('/api/v1/enrollment', [
        'token' => $raw1,
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'plugin_version' => '1.2.0',
    ])->assertStatus(201);

    $uuid1 = $first->json('site_uuid');
    $secret1 = $first->json('site_secret');

    // Second enrollment, same owner, same domain (with www + scheme noise).
    [$raw2] = makeEnrollmentToken($org, $creator);
    $second = $this->postJson('/api/v1/enrollment', [
        'token' => $raw2,
        'domain' => 'https://www.example.com/',
        'home_url' => 'https://www.example.com',
        'site_url' => 'https://www.example.com',
        'plugin_version' => '1.2.0',
    ])->assertStatus(200); // 200 = reuse, not 201

    // Exactly one active site row for this domain.
    expect(Site::whereNull('revoked_at')->count())->toBe(1);
    // Same uuid preserved.
    expect($second->json('site_uuid'))->toBe($uuid1);
    // Secret rotated.
    expect($second->json('site_secret'))->not->toBe($secret1);
});

test('a revoked site does not block a fresh enrollment of the same domain', function () {
    $org = Organization::factory()->create();
    $creator = makeUser();

    Site::factory()->create([
        'organization_id' => $org->id,
        'owner_user_id' => $creator->id,
        'domain' => 'example.com',
        'domain_normalized' => 'example.com',
        'status' => Site::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    [$raw] = makeEnrollmentToken($org, $creator);
    $this->postJson('/api/v1/enrollment', [
        'token' => $raw,
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'plugin_version' => '1.2.0',
    ])->assertStatus(201); // new active row

    expect(Site::whereNull('revoked_at')->where('domain_normalized', 'example.com')->count())->toBe(1);
    expect(Site::count())->toBe(2); // revoked + new
});

test('enrolling a domain owned by another subscriber is rejected (409)', function () {
    $org = Organization::factory()->create();
    $ownerA = makeUser();
    $ownerB = makeUser();

    Site::factory()->create([
        'organization_id' => $org->id,
        'owner_user_id' => $ownerA->id,
        'domain' => 'example.com',
        'domain_normalized' => 'example.com',
        'status' => 'online',
    ]);

    [$raw] = makeEnrollmentToken($org, $ownerB);
    $this->postJson('/api/v1/enrollment', [
        'token' => $raw,
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'plugin_version' => '1.2.0',
    ])->assertStatus(409)
        ->assertJsonPath('error', 'site_already_enrolled');

    // No duplicate created.
    expect(Site::where('domain_normalized', 'example.com')->count())->toBe(1);
});
