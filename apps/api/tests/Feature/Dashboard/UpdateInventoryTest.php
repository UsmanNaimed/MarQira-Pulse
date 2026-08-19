<?php

use App\Models\Site;
use App\Models\SiteHeartbeat;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Increment: update inventory, maintenance enforcement, theme updates,
// website limits, tenant isolation on origin endpoints, account management.
// ---------------------------------------------------------------------------

// --- Maintenance button enforcement (§1) ----------------------------------

test('request-update queues a theme update when themes are available on 1.2.4+', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'plugin_version' => '1.2.4',
        'theme_updates_count' => 2,
    ]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update", ['type' => 'themes'])
        ->assertStatus(200)
        ->assertJsonPath('data.command.type', 'themes');

    expect($site->fresh()->update_command_type)->toBe('themes');
});

test('request-update rejects a theme update when all themes are up to date', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'plugin_version' => '1.2.4',
        'theme_updates_count' => 0,
    ]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update", ['type' => 'themes'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'All themes are up to date.');

    expect($site->fresh()->update_command_status)->toBeNull();
});

test('request-update rejects a theme update for connectors older than 1.2.4', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'plugin_version' => '1.2.3',
        'theme_updates_count' => 2,
    ]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update", ['type' => 'themes'])
        ->assertStatus(422);

    expect($site->fresh()->update_command_status)->toBeNull();
});

test('request-update rejects a core update when WordPress is up to date', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'plugin_version' => '1.2.3',
        'core_update_available' => false,
    ]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update", ['type' => 'core'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'WordPress is up to date.');
});

test('request-update rejects an all-plugins update when plugins are up to date', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'plugin_version' => '1.2.3',
        'plugin_updates_count' => 0,
    ]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/request-update", ['type' => 'plugins'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'All plugins are up to date.');
});

// --- update-status inventory flags (§1/§13) --------------------------------

test('update-status exposes per-type can_update flags from the inventory', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'plugin_version' => '1.2.4',
        'core_update_available' => true,
        'plugin_updates_count' => 2,
        'theme_updates_count' => 0,
    ]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.can_update_core', true)
        ->assertJsonPath('data.can_update_plugins', true)
        ->assertJsonPath('data.can_update_themes', false)
        ->assertJsonPath('data.themes_update_supported', true);
});

// --- Detailed per-item inventory (§13, connector 1.2.8+) -------------------

test('update-status exposes the detailed per-item inventory from the latest heartbeat', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'plugin_version' => '1.2.8',
    ]);

    SiteHeartbeat::create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'received_at' => now(),
        'created_at' => now(),
        'payload' => ['updates' => ['items' => [
            'core' => ['current' => '6.5.2', 'new' => '6.5.3'],
            'plugins' => [
                ['name' => 'WooCommerce', 'slug' => 'woocommerce/woocommerce.php', 'current' => '8.6.1', 'new' => '8.7.1'],
                ['name' => 'Elementor', 'slug' => 'elementor/elementor.php', 'current' => '3.21', 'new' => null],
            ],
            'themes' => [
                ['name' => 'Astra', 'stylesheet' => 'astra', 'current' => '4.5.2', 'new' => '4.6.0', 'active' => true],
            ],
        ]]],
    ]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.update_items.core.current', '6.5.2')
        ->assertJsonPath('data.update_items.core.new', '6.5.3')
        ->assertJsonPath('data.update_items.plugins.0.name', 'WooCommerce')
        ->assertJsonPath('data.update_items.plugins.0.new', '8.7.1')
        ->assertJsonPath('data.update_items.plugins.1.new', null)
        ->assertJsonPath('data.update_items.themes.0.active', true);
});

test('update-status reads the inventory from the most recent heartbeat only', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id, 'plugin_version' => '1.2.8']);

    // Older beat with a stale inventory…
    SiteHeartbeat::create([
        'site_id' => $site->id, 'organization_id' => $org->id,
        'received_at' => now()->subHour(), 'created_at' => now()->subHour(),
        'payload' => ['updates' => ['items' => ['core' => ['current' => '6.5.0', 'new' => '6.5.1'], 'plugins' => [], 'themes' => []]]],
    ]);
    // …superseded by a newer beat.
    SiteHeartbeat::create([
        'site_id' => $site->id, 'organization_id' => $org->id,
        'received_at' => now(), 'created_at' => now(),
        'payload' => ['updates' => ['items' => ['core' => ['current' => '6.5.3', 'new' => null], 'plugins' => [], 'themes' => []]]],
    ]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.update_items.core.current', '6.5.3')
        ->assertJsonPath('data.update_items.core.new', null);
});

test('update-status update_items is null for connectors that only sent counts', function () {
    [$org, $user] = makeUserWithOrg();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    SiteHeartbeat::create([
        'site_id' => $site->id, 'organization_id' => $org->id,
        'received_at' => now(), 'created_at' => now(),
        'payload' => ['updates' => ['core' => false, 'plugins' => 0, 'themes' => 0]],
    ]);

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/update-status")
        ->assertStatus(200)
        ->assertJsonPath('data.update_items', null);
});

// --- Overview "updates available" fields (§3) ------------------------------

test('site list exposes update-inventory summary fields', function () {
    [$org, $user] = makeUserWithOrg();
    Site::factory()->create([
        'organization_id' => $org->id,
        'domain' => 'updated.example.com',
        'core_update_available' => true,
        'plugin_updates_count' => 4,
        'theme_updates_count' => 0,
    ]);

    $this->actingAs($user)
        ->getJson('/api/dashboard/sites')
        ->assertStatus(200)
        ->assertJsonPath('data.0.has_updates', true)
        ->assertJsonPath('data.0.core_updates_available', true)
        ->assertJsonPath('data.0.plugin_updates_available', 4)
        ->assertJsonPath('data.0.theme_updates_available', 0);
});

test('a site with no pending updates reports has_updates false', function () {
    [$org, $user] = makeUserWithOrg();
    Site::factory()->create([
        'organization_id' => $org->id,
        'core_update_available' => false,
        'plugin_updates_count' => 0,
        'theme_updates_count' => 0,
    ]);

    $this->actingAs($user)
        ->getJson('/api/dashboard/sites')
        ->assertStatus(200)
        ->assertJsonPath('data.0.has_updates', false);
});

// --- Website limits (§9/§10) -----------------------------------------------

test('subscriber at their website limit cannot generate a connection code', function () {
    [$org] = makeUserWithOrg();
    $sub = makeSubscriberIn($org, ['website_limit' => 1]);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id]);

    $this->actingAs($sub)
        ->postJson('/api/dashboard/enrollment-tokens')
        ->assertStatus(422)
        ->assertJsonPath('error', 'You have reached your website limit.');
});

test('subscriber under their website limit can generate a connection code', function () {
    [$org] = makeUserWithOrg();
    $sub = makeSubscriberIn($org, ['website_limit' => 5]);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id]);

    $this->actingAs($sub)
        ->postJson('/api/dashboard/enrollment-tokens')
        ->assertStatus(201)
        ->assertJsonStructure(['token', 'expires_at', 'expires_in_minutes']);
});

test('owner is never website-limited', function () {
    [$org, $owner] = makeUserWithOrg();
    Site::factory()->count(3)->create(['organization_id' => $org->id, 'owner_user_id' => $owner->id]);

    $this->actingAs($owner)
        ->postJson('/api/dashboard/enrollment-tokens')
        ->assertStatus(201);
});

test('user resource reports website-limit usage context', function () {
    [$org] = makeUserWithOrg();
    $sub = makeSubscriberIn($org, ['website_limit' => 2]);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id]);

    $this->actingAs($sub)
        ->getJson('/api/user')
        ->assertStatus(200)
        ->assertJsonPath('user.website_limit', 2)
        ->assertJsonPath('user.owned_sites_count', 1)
        ->assertJsonPath('user.website_limit_reached', false)
        ->assertJsonPath('user.is_owner', false);
});

// --- Tenant isolation on origin endpoints (§7/§11) -------------------------

test('subscriber cannot read another subscribers origin history (404)', function () {
    [$org] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);
    $theirs = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);

    $this->actingAs($subA)
        ->getJson("/api/dashboard/sites/{$theirs->uuid}/origin/history")
        ->assertStatus(404);
});

test('subscriber cannot verify another subscribers origin (404)', function () {
    [$org] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);
    $theirs = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $subB->id]);

    $this->actingAs($subA)
        ->postJson("/api/dashboard/sites/{$theirs->uuid}/origin/verify")
        ->assertStatus(404);
});

test('owner can read any subscribers origin history', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);
    $site = Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id]);

    $this->actingAs($owner)
        ->getJson("/api/dashboard/sites/{$site->uuid}/origin/history")
        ->assertStatus(200);
});

// --- Account management: show, update, search (§5) -------------------------

test('owner can create a subscriber with a website limit', function () {
    [$org, $owner] = makeUserWithOrg();

    $this->actingAs($owner)
        ->postJson('/api/dashboard/accounts', [
            'name' => 'Limited User',
            'email' => 'limited@example.com',
            'website_limit' => 5,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.website_limit', 5)
        ->assertJsonStructure(['data' => ['uuid', 'name', 'email'], 'setup_url']);
});

test('owner can view a subscriber detail with their owned sites', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org, ['name' => 'Detail User']);
    Site::factory()->create(['organization_id' => $org->id, 'owner_user_id' => $sub->id, 'domain' => 'owned.example.com']);

    $this->actingAs($owner)
        ->getJson("/api/dashboard/accounts/{$sub->uuid}")
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Detail User')
        ->assertJsonPath('data.site_count', 1)
        ->assertJsonPath('data.sites.0.domain', 'owned.example.com');
});

test('owner can update a subscriber name, email and website limit', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org, ['name' => 'Before', 'email' => 'before@example.com']);

    $this->actingAs($owner)
        ->patchJson("/api/dashboard/accounts/{$sub->uuid}", [
            'name' => 'After',
            'email' => 'after@example.com',
            'website_limit' => 10,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'After')
        ->assertJsonPath('data.email', 'after@example.com')
        ->assertJsonPath('data.website_limit', 10);

    $sub->refresh();
    expect($sub->name)->toBe('After');
    expect($sub->website_limit)->toBe(10);
});

test('owner can search subscribers by name or email', function () {
    [$org, $owner] = makeUserWithOrg();
    makeSubscriberIn($org, ['name' => 'Alice Anderson', 'email' => 'alice@example.com']);
    makeSubscriberIn($org, ['name' => 'Bob Baker', 'email' => 'bob@example.com']);

    $this->actingAs($owner)
        ->getJson('/api/dashboard/accounts?q=alice')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'alice@example.com');
});

test('a subscriber cannot reach the account update endpoint (403)', function () {
    [$org] = makeUserWithOrg();
    $subA = makeSubscriberIn($org);
    $subB = makeSubscriberIn($org);

    $this->actingAs($subA)
        ->patchJson("/api/dashboard/accounts/{$subB->uuid}", ['name' => 'Hacked'])
        ->assertStatus(403);
});
