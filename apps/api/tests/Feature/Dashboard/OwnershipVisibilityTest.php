<?php

use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('owner sees every site in the organization', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id]);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => null]);

    $this->actingAs($owner)
        ->getJson('/api/dashboard/sites')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 3);
});

test('subscriber sees only their own sites', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $mine = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id, 'domain' => 'mine.example.com']);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id, 'domain' => 'theirs.example.com']);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => null, 'domain' => 'unowned.example.com']);

    $this->actingAs($subA)
        ->getJson('/api/dashboard/sites')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.domain', 'mine.example.com');
});

test('subscriber cannot view another subscribers site (404)', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $theirs = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);

    $this->actingAs($subA)
        ->getJson("/api/dashboard/sites/{$theirs->uuid}")
        ->assertStatus(404);
});

test('subscriber can remove their own site (soft revoke)', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);

    $site = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id]);

    $this->actingAs($sub)
        ->deleteJson("/api/dashboard/sites/{$site->uuid}")
        ->assertStatus(200);

    $site->refresh();
    expect($site->revoked_at)->not->toBeNull();
    expect($site->status)->toBe(Site::STATUS_REVOKED);
    expect($site->revoked_by)->toBe($sub->id);
});

test('subscriber cannot remove another subscribers site (404)', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $theirs = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);

    $this->actingAs($subA)
        ->deleteJson("/api/dashboard/sites/{$theirs->uuid}")
        ->assertStatus(404);

    expect($theirs->fresh()->revoked_at)->toBeNull();
});

test('owner can remove any site including a subscribers', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);

    $site = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id]);

    $this->actingAs($owner)
        ->deleteJson("/api/dashboard/sites/{$site->uuid}")
        ->assertStatus(200);

    expect($site->fresh()->revoked_at)->not->toBeNull();
});

test('revoked sites are hidden from the active list and overview', function () {
    [$org, $owner] = makeUserWithOrg();

    Site::factory()->create(['organization_id' => $org->id, 'status' => 'online']);
    Site::factory()->create([
        'organization_id' => $org->id,
        'status' => Site::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    $this->actingAs($owner)
        ->getJson('/api/dashboard/sites')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($owner)
        ->getJson('/api/dashboard/overview')
        ->assertStatus(200)
        ->assertJsonPath('cards.total', 1);
});
