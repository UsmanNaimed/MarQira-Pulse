<?php

use App\Models\ApiToken;
use App\Models\Site;
use App\Models\SiteVisitorMetric;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * §12/§13 — External automation API. A bearer API token authenticates AS its
 * owning user and may only ever reach that user's authorized websites and
 * analytics. Cross-tenant / cross-subscriber access (even via a manipulated
 * UUID) must 404, and abilities / revocation / expiry / IP allowlist must be
 * enforced server-side.
 */

/**
 * Create an API token bound to a user and return [ApiToken, rawToken].
 *
 * @return array{0: ApiToken, 1: string}
 */
function makeApiToken(\App\Models\Organization $org, \App\Models\User $user, array $overrides = []): array
{
    $raw = 'mq_live_' . bin2hex(random_bytes(20));

    $token = ApiToken::create(array_merge([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'created_by' => $user->id,
        'name' => 'test token',
        'token_hash' => hash('sha256', $raw),
        'abilities' => ['sites:read'],
        'allowed_ips' => [],
    ], $overrides));

    return [$token, $raw];
}

function bearer(string $raw): array
{
    return ['Authorization' => 'Bearer ' . $raw];
}

test('token lists only its owning subscribers sites', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $aSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id, 'domain' => 'a.example.com']);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id, 'domain' => 'b.example.com']);

    [, $raw] = makeApiToken($org, $subA);

    $res = $this->withHeaders(bearer($raw))
        ->getJson('/api/v1/external/sites')
        ->assertStatus(200);

    expect($res->json('data'))->toHaveCount(1);
    $res->assertJsonPath('data.0.domain', 'a.example.com');
});

test('owner token sees every site in the organization', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);

    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id]);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id]);

    [, $raw] = makeApiToken($org, $owner);

    $res = $this->withHeaders(bearer($raw))
        ->getJson('/api/v1/external/sites')
        ->assertStatus(200);

    expect($res->json('data'))->toHaveCount(2);
});

test('token cannot read another subscribers site by uuid (404)', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $theirs = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);

    [, $raw] = makeApiToken($org, $subA);

    $this->withHeaders(bearer($raw))
        ->getJson("/api/v1/external/sites/{$theirs->uuid}")
        ->assertStatus(404);
});

test('token cannot read another subscribers visitor analytics (404)', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $theirs = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);
    SiteVisitorMetric::create([
        'site_id' => $theirs->id,
        'organization_id' => $org->id,
        'date' => now()->toDateString(),
        'unique_visitors' => 42,
        'pageviews' => 100,
        'recorded_at' => now(),
    ]);

    [, $raw] = makeApiToken($org, $subA);

    $this->withHeaders(bearer($raw))
        ->getJson("/api/v1/external/sites/{$theirs->uuid}/visitors")
        ->assertStatus(404);
});

test('token can read its own site visitor analytics', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);

    $site = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id]);
    SiteVisitorMetric::create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'date' => now()->toDateString(),
        'unique_visitors' => 12,
        'pageviews' => 30,
        'recorded_at' => now(),
    ]);

    [, $raw] = makeApiToken($org, $sub);

    $this->withHeaders(bearer($raw))
        ->getJson("/api/v1/external/sites/{$site->uuid}/visitors")
        ->assertStatus(200)
        ->assertJsonPath('total_visitors', 12);
});

test('missing token is rejected with 401', function () {
    $this->getJson('/api/v1/external/sites')->assertStatus(401);
});

test('invalid token is rejected with 401', function () {
    $this->withHeaders(bearer('mq_live_notarealtoken'))
        ->getJson('/api/v1/external/sites')
        ->assertStatus(401);
});

test('revoked token is rejected with 401', function () {
    [$org, $owner] = makeUserWithOrg();
    [$token, $raw] = makeApiToken($org, $owner, ['revoked_at' => now()]);

    $this->withHeaders(bearer($raw))
        ->getJson('/api/v1/external/sites')
        ->assertStatus(401);
});

test('expired token is rejected with 401', function () {
    [$org, $owner] = makeUserWithOrg();
    [$token, $raw] = makeApiToken($org, $owner, ['expires_at' => now()->subDay()]);

    $this->withHeaders(bearer($raw))
        ->getJson('/api/v1/external/sites')
        ->assertStatus(401);
});

test('token without the required ability is rejected with 403', function () {
    [$org, $owner] = makeUserWithOrg();
    [, $raw] = makeApiToken($org, $owner, ['abilities' => ['sites:status']]);

    $this->withHeaders(bearer($raw))
        ->getJson('/api/v1/external/sites')
        ->assertStatus(403);
});

test('token updates last_used_at on a successful request', function () {
    [$org, $owner] = makeUserWithOrg();
    [$token, $raw] = makeApiToken($org, $owner);

    expect($token->last_used_at)->toBeNull();

    $this->withHeaders(bearer($raw))
        ->getJson('/api/v1/external/sites')
        ->assertStatus(200);

    expect($token->fresh()->last_used_at)->not->toBeNull();
});
