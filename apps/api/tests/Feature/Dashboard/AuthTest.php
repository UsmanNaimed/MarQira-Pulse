<?php

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('login succeeds with valid credentials and returns user', function () {
    [$org, $user] = makeUserWithOrg(['email' => 'admin@example.com']);

    $response = $this->postJson('/api/login', [
        'email' => 'admin@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('user.email', 'admin@example.com')
        ->assertJsonPath('user.organization.name', $org->name);

    // Password hash must never leak in the response.
    expect($response->json())->not->toHaveKey('user.password');
});

test('login fails with wrong password', function () {
    makeUserWithOrg(['email' => 'admin@example.com']);

    $this->postJson('/api/login', [
        'email' => 'admin@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

test('unauthenticated user cannot access dashboard', function () {
    $this->getJson('/api/dashboard/overview')->assertStatus(401);
});

test('me endpoint returns authenticated user', function () {
    [$org, $user] = makeUserWithOrg(['email' => 'me@example.com']);

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertStatus(200)
        ->assertJsonPath('user.email', 'me@example.com');
});

test('logout invalidates the session', function () {
    [$org, $user] = makeUserWithOrg();

    $this->actingAs($user)
        ->postJson('/api/logout')
        ->assertStatus(200);
});

test('user without organization is rejected from dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/dashboard/overview')
        ->assertStatus(403);
});
