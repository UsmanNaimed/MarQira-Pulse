<?php

use App\Models\Site;
use App\Models\SiteUser;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * §7 regression: WordPress users are stored as append-only snapshots. The
 * "Total Users" count and the returned rows must reflect DISTINCT users (the
 * latest snapshot per wp_user_id), not the number of raw snapshots.
 */
test('users endpoint deduplicates append-only snapshots to distinct users', function () {
    [$org, $owner] = makeUserWithOrg();

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'owner_user_id' => $owner->id,
    ]);

    // Five snapshots of the SAME WordPress user (wp_user_id = 1) — this is the
    // exact "shows 5 when there is only 1" bug.
    foreach (range(1, 5) as $i) {
        SiteUser::create([
            'site_id' => $site->id,
            'organization_id' => $org->id,
            'snapshot_at' => now()->subMinutes(5 - $i),
            'wp_user_id' => 1,
            'user_login' => 'admin',
            'user_email' => 'admin@example.com',
            'display_name' => 'Administrator',
            'roles' => ['administrator'],
        ]);
    }

    $res = $this->actingAs($owner)
        ->getJson("/api/dashboard/sites/{$site->uuid}/users")
        ->assertStatus(200);

    // One distinct user — not five snapshots.
    $res->assertJsonPath('meta.total', 1);
    expect($res->json('data'))->toHaveCount(1);
    $res->assertJsonPath('data.0.user_login', 'admin');
});

test('users endpoint counts each distinct wp user once across snapshots', function () {
    [$org, $owner] = makeUserWithOrg();

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'owner_user_id' => $owner->id,
    ]);

    // Two distinct users, each snapshotted three times.
    foreach ([1, 2] as $wpUserId) {
        foreach (range(1, 3) as $i) {
            SiteUser::create([
                'site_id' => $site->id,
                'organization_id' => $org->id,
                'snapshot_at' => now()->subMinutes(10 - $i),
                'wp_user_id' => $wpUserId,
                'user_login' => "user{$wpUserId}",
                'user_email' => "user{$wpUserId}@example.com",
                'display_name' => "User {$wpUserId}",
                'roles' => ['subscriber'],
            ]);
        }
    }

    $this->actingAs($owner)
        ->getJson("/api/dashboard/sites/{$site->uuid}/users")
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 2);
});
