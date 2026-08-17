<?php

use App\Models\AccountInvitation;
use App\Models\Site;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('owner can list subscribers with site counts', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org, ['name' => 'Alice', 'email' => 'alice@example.com']);
    $subB = makeSubscriberIn($org, ['name' => 'Bob', 'email' => 'bob@example.com']);

    Site::factory()->count(2)->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id]);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);

    $response = $this->actingAs($owner)
        ->getJson('/api/dashboard/accounts')
        ->assertStatus(200);

    $data = collect($response->json('data'))->keyBy('email');
    expect($data['alice@example.com']['site_count'])->toBe(2);
    expect($data['bob@example.com']['site_count'])->toBe(1);
});

test('subscriber cannot access account management (403)', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);

    $this->actingAs($sub)->getJson('/api/dashboard/accounts')->assertStatus(403);
    $this->actingAs($sub)->postJson('/api/dashboard/accounts', [
        'name' => 'X', 'email' => 'x@example.com',
    ])->assertStatus(403);
});

test('owner creates a subscriber and receives a setup url; no password is set yet', function () {
    [$org, $owner] = makeUserWithOrg();

    $response = $this->actingAs($owner)->postJson('/api/dashboard/accounts', [
        'name' => 'Carol',
        'email' => 'carol@example.com',
    ])->assertStatus(201);

    $setupUrl = $response->json('setup_url');
    expect($setupUrl)->toContain('/account-setup/');

    $user = User::where('email', 'carol@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->platform_role)->toBe(User::ROLE_SUBSCRIBER);
    expect($user->isActive())->toBeTrue();

    // A single invitation exists, unused.
    expect(AccountInvitation::where('user_id', $user->id)->whereNull('used_at')->count())->toBe(1);
});

test('creating a subscriber with a duplicate email fails validation', function () {
    [$org, $owner] = makeUserWithOrg();
    makeSubscriberIn($org, ['email' => 'dupe@example.com']);

    $this->actingAs($owner)->postJson('/api/dashboard/accounts', [
        'name' => 'Dupe',
        'email' => 'dupe@example.com',
    ])->assertStatus(422);
});

test('full invitation flow: create, setup password, then login', function () {
    [$org, $owner] = makeUserWithOrg();

    // Owner creates the subscriber; capture the raw token from the setup URL.
    $create = $this->actingAs($owner)->postJson('/api/dashboard/accounts', [
        'name' => 'Dave',
        'email' => 'dave@example.com',
    ])->assertStatus(201);

    $setupUrl = $create->json('setup_url');
    $rawToken = basename(parse_url($setupUrl, PHP_URL_PATH));

    // The token validates (unauthenticated).
    $this->getJson("/api/account-setup/{$rawToken}")
        ->assertStatus(200)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('email', 'dave@example.com');

    // Setting the password consumes the token.
    $this->postJson("/api/account-setup/{$rawToken}", [
        'password' => 'a-fresh-strong-password',
        'password_confirmation' => 'a-fresh-strong-password',
    ])->assertStatus(200);

    // The same token can no longer be used.
    $this->getJson("/api/account-setup/{$rawToken}")->assertStatus(404);

    // The subscriber can now log in with the chosen password.
    $this->postJson('/api/login', [
        'email' => 'dave@example.com',
        'password' => 'a-fresh-strong-password',
    ])->assertStatus(200)
        ->assertJsonPath('user.email', 'dave@example.com');
});

test('setup rejects a password shorter than the minimum', function () {
    [$org, $owner] = makeUserWithOrg();
    $create = $this->actingAs($owner)->postJson('/api/dashboard/accounts', [
        'name' => 'Erin', 'email' => 'erin@example.com',
    ])->assertStatus(201);
    $rawToken = basename(parse_url($create->json('setup_url'), PHP_URL_PATH));

    $this->postJson("/api/account-setup/{$rawToken}", [
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422);
});

test('resend setup invalidates the previous link and issues a new one', function () {
    [$org, $owner] = makeUserWithOrg();
    $create = $this->actingAs($owner)->postJson('/api/dashboard/accounts', [
        'name' => 'Fay', 'email' => 'fay@example.com',
    ])->assertStatus(201);
    $oldToken = basename(parse_url($create->json('setup_url'), PHP_URL_PATH));

    $user = User::where('email', 'fay@example.com')->first();

    $resend = $this->actingAs($owner)
        ->postJson("/api/dashboard/accounts/{$user->uuid}/resend-setup")
        ->assertStatus(200);
    $newToken = basename(parse_url($resend->json('setup_url'), PHP_URL_PATH));

    expect($newToken)->not->toBe($oldToken);
    // Old link no longer works; new one does.
    $this->getJson("/api/account-setup/{$oldToken}")->assertStatus(404);
    $this->getJson("/api/account-setup/{$newToken}")->assertStatus(200);
});

test('deactivated subscriber cannot log in', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org, [
        'email' => 'gary@example.com',
        'is_active' => false,
    ]);

    $this->postJson('/api/login', [
        'email' => 'gary@example.com',
        'password' => 'correct-horse-battery',
    ])->assertStatus(422);
});

test('owner can deactivate then reactivate a subscriber', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org, ['email' => 'holly@example.com']);

    $this->actingAs($owner)
        ->postJson("/api/dashboard/accounts/{$sub->uuid}/deactivate")
        ->assertStatus(200)
        ->assertJsonPath('is_active', false);
    expect($sub->fresh()->isActive())->toBeFalse();

    $this->actingAs($owner)
        ->postJson("/api/dashboard/accounts/{$sub->uuid}/activate")
        ->assertStatus(200)
        ->assertJsonPath('is_active', true);
    expect($sub->fresh()->isActive())->toBeTrue();
});

test('owner can list a specific subscribers sites', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org, ['email' => 'ian@example.com']);
    Site::factory()->count(2)->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id]);

    $this->actingAs($owner)
        ->getJson("/api/dashboard/accounts/{$sub->uuid}/sites")
        ->assertStatus(200)
        ->assertJsonCount(2, 'data');
});
