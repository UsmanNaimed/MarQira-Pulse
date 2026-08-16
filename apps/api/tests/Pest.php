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
    $user = \App\Models\User::factory()->create(array_merge([
        'password' => \Illuminate\Support\Facades\Hash::make('correct-horse-battery'),
    ], $userAttrs));

    \App\Models\OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return [$org, $user];
}
