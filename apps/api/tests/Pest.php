<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
| Unit tests get the base TestCase. Feature tests additionally use
| RefreshDatabase, running migrations against the in-memory SQLite connection
| configured in phpunit.xml so the suite never needs a live database.
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
*/

/**
 * Create an organization plus a user who is a member of it (owner by default),
 * returning [Organization, User]. Used across the dashboard feature tests.
 */
function makeUserWithOrg(array $userAttrs = [], string $role = 'owner'): array
{
    $org = \App\Models\Organization::factory()->create();

    // Mirror the organization membership role onto the platform role so the
    // Owner/Subscriber authorization layer behaves consistently in tests: an
    // organization "owner" is a platform Owner (sees every site), anything else
    // is a Subscriber (sees only sites they own). Explicit platform_role in
    // $userAttrs always wins.
    $defaults = [
        'password' => \Illuminate\Support\Facades\Hash::make('correct-horse-battery'),
        'platform_role' => $role === 'owner'
            ? \App\Models\User::ROLE_OWNER
            : \App\Models\User::ROLE_SUBSCRIBER,
    ];

    $user = \App\Models\User::factory()->create(array_merge($defaults, $userAttrs));

    \App\Models\OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return [$org, $user];
}

/**
 * Add a Subscriber (platform_role = subscriber, org membership "member") to an
 * existing organization and return the user. Used by the ownership-isolation
 * and account-management feature tests.
 */
function makeSubscriberIn(\App\Models\Organization $org, array $attrs = []): \App\Models\User
{
    $user = \App\Models\User::factory()->create(array_merge([
        'password' => \Illuminate\Support\Facades\Hash::make('correct-horse-battery'),
        'platform_role' => \App\Models\User::ROLE_SUBSCRIBER,
        'is_active' => true,
    ], $attrs));

    \App\Models\OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => 'member',
    ]);

    return $user;
}
