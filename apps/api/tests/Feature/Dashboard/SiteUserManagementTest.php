<?php

use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Connector\ConnectorClient;
use Mockery\MockInterface;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase C: WordPress user management (dashboard -> connector proxy)
|--------------------------------------------------------------------------
| The dashboard endpoints authorize the caller, guard privilege escalation,
| enforce connector capability, then relay to the connector over signed HMAC.
| The connector itself is unreachable from CI, so ConnectorClient is mocked
| and we assert the authorization / guard / relay behaviour of the API layer.
*/

/** A site whose connector supports user management (>= 1.2.12). */
function manageableSite($org, array $attrs = []): Site
{
    return Site::factory()->create(array_merge([
        'organization_id' => $org->id,
        'plugin_version'  => '1.2.12',
        'status'          => Site::STATUS_ONLINE,
    ], $attrs));
}

/** Bind a ConnectorClient test double, letting the caller set expectations. */
function mockConnector(Closure $expectations): void
{
    test()->mock(ConnectorClient::class, function (MockInterface $mock) use ($expectations) {
        $expectations($mock);
    });
}

function ok(array $json = ['success' => true], int $status = 200): array
{
    return ['ok' => true, 'status' => $status, 'json' => $json, 'error' => null];
}

function fail(string $error = 'unreachable', int $status = 502, array $json = []): array
{
    return ['ok' => false, 'status' => $status, 'json' => $json, 'error' => $error];
}

// ---------------------------------------------------------------------------
// List / filter / search
// ---------------------------------------------------------------------------

test('it lists WordPress users and forwards search + role filters', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    mockConnector(function ($mock) use ($site) {
        $mock->shouldReceive('userAction')
            ->once()
            ->withArgs(function ($s, $action, $payload) use ($site) {
                return $s->id === $site->id
                    && $action === 'list'
                    && $payload['search'] === 'jane'
                    && $payload['role'] === 'editor';
            })
            ->andReturn(ok([
                'success' => true,
                'data'    => [['id' => 5, 'username' => 'jane', 'roles' => ['editor']]],
                'meta'    => ['total' => 1, 'per_page' => 25, 'current_page' => 1, 'last_page' => 1],
            ]));
    });

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/wp-users?search=jane&role=editor")
        ->assertStatus(200)
        ->assertJsonPath('data.0.username', 'jane');
});

test('it lists available roles', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->withArgs(fn ($s, $action) => $action === 'roles')
            ->andReturn(ok(['data' => [['slug' => 'editor', 'name' => 'Editor']]]));
    });

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/wp-roles")
        ->assertStatus(200)
        ->assertJsonPath('data.0.slug', 'editor');
});

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

test('an owner can create a subscriber', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->withArgs(fn ($s, $action, $p) => $action === 'create' && $p['role'] === 'subscriber')
            ->andReturn(ok(['success' => true, 'data' => ['id' => 9, 'username' => 'newbie']]));
    });

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/wp-users", [
            'username' => 'newbie',
            'email'    => 'newbie@example.com',
            'role'     => 'subscriber',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.username', 'newbie');

    expect(AuditLog::where('event', 'site.wp_user_created')->count())->toBe(1);
});

test('an owner can create an administrator', function () {
    [$org, $user] = makeUserWithOrg(); // owner => elevated
    $site = manageableSite($org);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->withArgs(fn ($s, $action, $p) => $p['role'] === 'administrator')
            ->andReturn(ok(['success' => true, 'data' => ['id' => 2]]));
    });

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/wp-users", [
            'username' => 'boss',
            'email'    => 'boss@example.com',
            'role'     => 'administrator',
        ])
        ->assertStatus(200);
});

test('a subscriber cannot create an administrator (privilege escalation blocked)', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);
    $site = manageableSite($org, ['owner_user_id' => $sub->id]);

    // The connector must never be called if the guard trips first.
    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->never();
    });

    $this->actingAs($sub)
        ->postJson("/api/dashboard/sites/{$site->uuid}/wp-users", [
            'username' => 'sneaky',
            'email'    => 'sneaky@example.com',
            'role'     => 'administrator',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error', 'forbidden_role');
});

test('a subscriber can still create a non-admin user on their own site', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);
    $site = manageableSite($org, ['owner_user_id' => $sub->id]);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->withArgs(fn ($s, $action, $p) => $p['role'] === 'editor')
            ->andReturn(ok(['success' => true, 'data' => ['id' => 11]]));
    });

    $this->actingAs($sub)
        ->postJson("/api/dashboard/sites/{$site->uuid}/wp-users", [
            'username' => 'editorial',
            'email'    => 'ed@example.com',
            'role'     => 'editor',
        ])
        ->assertStatus(200);
});

test('creating a user validates email and required fields', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    $this->actingAs($user)
        ->postJson("/api/dashboard/sites/{$site->uuid}/wp-users", [
            'username' => 'x',
            'email'    => 'not-an-email',
            'role'     => 'subscriber',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

// ---------------------------------------------------------------------------
// Update / password / role
// ---------------------------------------------------------------------------

test('it updates a user profile and forwards only provided fields', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->withArgs(function ($s, $action, $p) {
                return $action === 'update'
                    && $p['id'] === 42
                    && $p['display_name'] === 'Renamed'
                    && ! array_key_exists('password', $p);
            })
            ->andReturn(ok(['success' => true, 'data' => ['id' => 42]]));
    });

    $this->actingAs($user)
        ->putJson("/api/dashboard/sites/{$site->uuid}/wp-users/42", [
            'display_name' => 'Renamed',
        ])
        ->assertStatus(200);
});

test('a blank password is dropped so it never overwrites the existing one', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->withArgs(fn ($s, $action, $p) => ! array_key_exists('password', $p))
            ->andReturn(ok(['success' => true, 'data' => ['id' => 42]]));
    });

    $this->actingAs($user)
        ->putJson("/api/dashboard/sites/{$site->uuid}/wp-users/42", [
            'email'    => 'keep@example.com',
            'password' => '',
        ])
        ->assertStatus(200);
});

test('a subscriber cannot promote a user to administrator via update', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);
    $site = manageableSite($org, ['owner_user_id' => $sub->id]);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->never();
    });

    $this->actingAs($sub)
        ->putJson("/api/dashboard/sites/{$site->uuid}/wp-users/7", [
            'role' => 'administrator',
        ])
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Delete + reassignment
// ---------------------------------------------------------------------------

test('it deletes a user with content reassignment', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->withArgs(function ($s, $action, $p) {
                return $action === 'delete' && $p['id'] === 8 && $p['reassign_to'] === 3;
            })
            ->andReturn(ok(['success' => true]));
    });

    $this->actingAs($user)
        ->deleteJson("/api/dashboard/sites/{$site->uuid}/wp-users/8?reassign_to=3")
        ->assertStatus(200);

    expect(AuditLog::where('event', 'site.wp_user_deleted')->count())->toBe(1);
});

test('it forwards a force delete when no reassignment is chosen', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->withArgs(fn ($s, $action, $p) => $action === 'delete' && ($p['force_delete'] ?? false) === true)
            ->andReturn(ok(['success' => true]));
    });

    $this->actingAs($user)
        ->deleteJson("/api/dashboard/sites/{$site->uuid}/wp-users/8?force_delete=1")
        ->assertStatus(200);
});

test('it lists reassignment candidates excluding the target', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->withArgs(fn ($s, $action, $p) => $action === 'reassign-candidates' && (int) $p['exclude'] === 8)
            ->andReturn(ok(['data' => [['id' => 3, 'display_name' => 'Keep Er']]]));
    });

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/wp-users/reassign-candidates?exclude=8")
        ->assertStatus(200)
        ->assertJsonPath('data.0.id', 3);
});

// ---------------------------------------------------------------------------
// Connector capability + revocation guards
// ---------------------------------------------------------------------------

test('an old connector is rejected with a clear upgrade message', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org, ['plugin_version' => '1.2.9']);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->never();
    });

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/wp-users")
        ->assertStatus(422)
        ->assertJsonPath('error', 'connector_unsupported');
});

test('a revoked site cannot be managed', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org, ['status' => Site::STATUS_REVOKED, 'revoked_at' => now()]);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->never();
    });

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/wp-users")
        ->assertStatus(409)
        ->assertJsonPath('error', 'site_revoked');
});

test('a connector failure is relayed with its status and message', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->once()
            ->andReturn(fail('The website could not be reached.', 502));
    });

    $this->actingAs($user)
        ->getJson("/api/dashboard/sites/{$site->uuid}/wp-users")
        ->assertStatus(502)
        ->assertJsonPath('message', 'The website could not be reached.');
});

// ---------------------------------------------------------------------------
// Authorization / IDOR
// ---------------------------------------------------------------------------

test('a user cannot manage a site in another organization (IDOR)', function () {
    [$orgA, $userA] = makeUserWithOrg();
    [$orgB, $userB] = makeUserWithOrg();
    $siteB = manageableSite($orgB);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->never();
    });

    $this->actingAs($userA)
        ->getJson("/api/dashboard/sites/{$siteB->uuid}/wp-users")
        ->assertStatus(404);
});

test('unauthenticated requests are rejected', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    $this->getJson("/api/dashboard/sites/{$site->uuid}/wp-users")
        ->assertStatus(401);
});

// ---------------------------------------------------------------------------
// Bulk create across multiple sites (§8, §9)
// ---------------------------------------------------------------------------

test('bulk create reports per-site results and skips unsupported/revoked sites', function () {
    [$org, $user] = makeUserWithOrg();
    $good1 = manageableSite($org, ['domain' => 'a.example.com']);
    $good2 = manageableSite($org, ['domain' => 'b.example.com']);
    $old   = manageableSite($org, ['domain' => 'c.example.com', 'plugin_version' => '1.2.9']);
    $dead  = manageableSite($org, ['domain' => 'd.example.com', 'status' => Site::STATUS_REVOKED, 'revoked_at' => now()]);

    mockConnector(function ($mock) {
        // Only the two capable sites reach the connector.
        $mock->shouldReceive('userAction')->twice()
            ->withArgs(fn ($s, $action, $p) => $action === 'create' && isset($p['idempotency_key']))
            ->andReturn(ok(['success' => true, 'data' => ['id' => 1]]));
    });

    $res = $this->actingAs($user)
        ->postJson('/api/dashboard/wp-users/bulk-create', [
            'username'     => 'multi',
            'email'        => 'multi@example.com',
            'default_role' => 'editor',
            'sites'        => [
                ['uuid' => $good1->uuid],
                ['uuid' => $good2->uuid, 'role' => 'author'],
                ['uuid' => $old->uuid],
                ['uuid' => $dead->uuid],
            ],
        ])
        ->assertStatus(200)
        ->assertJsonCount(4, 'results');

    $rows = collect($res->json('results'))->keyBy('uuid');
    expect($rows[$good1->uuid]['status'])->toBe('created');
    expect($rows[$good2->uuid]['status'])->toBe('created');
    expect($rows[$good2->uuid]['role'])->toBe('author');
    expect($rows[$old->uuid]['status'])->toBe('skipped');
    expect($rows[$dead->uuid]['status'])->toBe('skipped');

    expect(AuditLog::where('event', 'site.wp_user_bulk_created')->count())->toBe(1);
});

test('bulk create uses a deterministic idempotency key so retries never duplicate', function () {
    [$org, $user] = makeUserWithOrg();
    $site = manageableSite($org);

    $seenKeys = [];
    test()->mock(ConnectorClient::class, function (MockInterface $mock) use (&$seenKeys) {
        $mock->shouldReceive('userAction')->twice()
            ->withArgs(function ($s, $action, $p) use (&$seenKeys) {
                if ($action === 'create') {
                    $seenKeys[] = $p['idempotency_key'];
                }
                return $action === 'create';
            })
            ->andReturn(ok(['success' => true, 'data' => ['id' => 1]]));
    });

    $payload = [
        'operation_id' => 'op-fixed-123',
        'username'     => 'multi',
        'email'        => 'multi@example.com',
        'default_role' => 'editor',
        'sites'        => [['uuid' => $site->uuid]],
    ];

    $this->actingAs($user)->postJson('/api/dashboard/wp-users/bulk-create', $payload)->assertStatus(200);
    // Retry with the SAME operation id -> same idempotency key.
    $this->actingAs($user)->postJson('/api/dashboard/wp-users/bulk-create', $payload)->assertStatus(200);

    expect($seenKeys)->toHaveCount(2);
    expect($seenKeys[0])->toBe($seenKeys[1]);
});

test('a subscriber cannot bulk-create administrators', function () {
    [$org, $owner] = makeUserWithOrg();
    $sub = makeSubscriberIn($org);
    $site = manageableSite($org, ['owner_user_id' => $sub->id]);

    mockConnector(function ($mock) {
        $mock->shouldReceive('userAction')->never();
    });

    $res = $this->actingAs($sub)
        ->postJson('/api/dashboard/wp-users/bulk-create', [
            'username'     => 'multi',
            'email'        => 'multi@example.com',
            'default_role' => 'administrator',
            'sites'        => [['uuid' => $site->uuid]],
        ])
        ->assertStatus(200);

    expect($res->json('results.0.status'))->toBe('failed');
});
