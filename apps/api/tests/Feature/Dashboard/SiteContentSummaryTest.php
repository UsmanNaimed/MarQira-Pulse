<?php

use App\Models\Site;
use App\Models\SitePost;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Content tab: append-only snapshots must be deduplicated to the latest
// snapshot per wp_post_id, so the total count reflects real posts (not the
// inflated raw snapshot row count).
// ---------------------------------------------------------------------------

function snapshot(Site $site, int $wpId, string $status, string $title, int $ageMinutes = 0, ?string $permalink = null): SitePost
{
    return SitePost::create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'snapshot_at' => now()->subMinutes($ageMinutes),
        'wp_post_id' => $wpId,
        'post_type' => 'post',
        'post_status' => $status,
        'post_title' => $title,
        'post_date' => now()->subDays($wpId),
        'guid' => "https://example.com/?p={$wpId}",
        'permalink' => $permalink ?? "https://example.com/{$wpId}",
    ]);
}

test('content summary deduplicates repeated snapshots to real post counts', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    // 3 distinct posts, but each captured multiple times over successive runs.
    foreach (range(1, 5) as $run) {
        snapshot($site, 1, 'publish', 'Hello world', 5 - $run);
        snapshot($site, 2, 'draft', 'Draft idea', 5 - $run);
        snapshot($site, 3, 'future', 'Scheduled post', 5 - $run);
    }

    // 15 raw rows, but only 3 real posts.
    expect(SitePost::where('site_id', $site->id)->count())->toBe(15);

    $response = $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/posts")
        ->assertStatus(200);

    // Deduplicated total, not the raw 15.
    $response->assertJsonPath('summary.total', 3)
        ->assertJsonPath('summary.published', 1)
        ->assertJsonPath('summary.draft', 1)
        ->assertJsonPath('summary.scheduled', 1)
        ->assertJsonPath('meta.total', 3);

    // The listing itself is deduplicated too.
    expect($response->json('data'))->toHaveCount(3);
});

test('content listing returns the latest snapshot per post with its permalink', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    // Older snapshot: draft with an internal preview permalink.
    snapshot($site, 42, 'draft', 'Work in progress', 60, 'https://example.com/?p=42');
    // Newer snapshot of the same post: now published with a public permalink.
    snapshot($site, 42, 'publish', 'Now live', 1, 'https://example.com/now-live');

    $response = $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/posts")
        ->assertStatus(200);

    $response->assertJsonPath('summary.total', 1)
        ->assertJsonPath('summary.published', 1)
        ->assertJsonPath('data.0.post_status', 'publish')
        ->assertJsonPath('data.0.permalink', 'https://example.com/now-live');
});
