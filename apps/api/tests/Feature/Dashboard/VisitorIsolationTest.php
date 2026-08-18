<?php

use App\Models\Site;
use App\Models\SiteVisitorMetric;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * §8/§9/§10 regression: visitor totals on the overview must be scoped to the
 * websites the viewer is authorized to see, NOT aggregated org-wide. Previously
 * OverviewController summed visitors by organization_id, so a Subscriber (and
 * the Owner viewing a single account) saw everyone's visitors.
 */

function seedVisitors(Site $site, int $visitors): void
{
    SiteVisitorMetric::create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'date' => now()->toDateString(),
        'unique_visitors' => $visitors,
        'pageviews' => $visitors * 3,
        'recorded_at' => now(),
    ]);
}

test('subscriber overview visitor total excludes other users visitors', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $ownerSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id]);
    $aSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id]);
    $bSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);

    seedVisitors($ownerSite, 100);
    seedVisitors($aSite, 7);
    seedVisitors($bSite, 50);

    // Subscriber A must see ONLY their 7 visitors, not the org-wide 157.
    $this->actingAs($subA)
        ->getJson('/api/dashboard/overview')
        ->assertStatus(200)
        ->assertJsonPath('cards.visitors_7d', 7);

    // Subscriber B sees only their 50.
    $this->actingAs($subB)
        ->getJson('/api/dashboard/overview')
        ->assertStatus(200)
        ->assertJsonPath('cards.visitors_7d', 50);
});

test('owner overview visitor total aggregates all sites by default', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);

    $ownerSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id]);
    $aSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id]);

    seedVisitors($ownerSite, 100);
    seedVisitors($aSite, 7);

    $this->actingAs($owner)
        ->getJson('/api/dashboard/overview')
        ->assertStatus(200)
        ->assertJsonPath('cards.visitors_7d', 107);
});

test('owner overview scoped to one account shows only that account visitors', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);

    $ownerSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id]);
    $aSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id]);

    seedVisitors($ownerSite, 100);
    seedVisitors($aSite, 7);

    // Owner explicitly narrows the view to Subscriber A's account.
    $this->actingAs($owner)
        ->getJson('/api/dashboard/overview?account=' . $subA->uuid)
        ->assertStatus(200)
        ->assertJsonPath('cards.visitors_7d', 7)
        ->assertJsonPath('cards.total', 1);
});

test('subscriber cannot widen scope with an account param', function () {
    [$org, $owner] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $aSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subA->id]);
    $bSite = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);

    seedVisitors($aSite, 7);
    seedVisitors($bSite, 50);

    // Subscriber A tries to view Subscriber B's account — the param is ignored
    // entirely and A still only sees their own scope.
    $this->actingAs($subA)
        ->getJson('/api/dashboard/overview?account=' . $subB->uuid)
        ->assertStatus(200)
        ->assertJsonPath('cards.visitors_7d', 7)
        ->assertJsonPath('cards.total', 1);
});
