<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a user and organization when none exist', function () {
    $this->artisan('marqira:create-admin', [
        '--name' => 'Ada Admin',
        '--email' => 'ada@marqira.com',
        '--password' => 'a-very-strong-password',
    ])->assertSuccessful();

    expect(Organization::where('slug', 'marqira')->exists())->toBeTrue();
    expect(User::where('email', 'ada@marqira.com')->exists())->toBeTrue();
});

it('assigns the owner membership', function () {
    $this->artisan('marqira:create-admin', [
        '--name' => 'Ada Admin',
        '--email' => 'ada@marqira.com',
        '--password' => 'a-very-strong-password',
    ])->assertSuccessful();

    $user = User::where('email', 'ada@marqira.com')->firstOrFail();
    $org = Organization::where('slug', 'marqira')->firstOrFail();

    $membership = OrganizationMembership::where('user_id', $user->id)
        ->where('organization_id', $org->id)
        ->firstOrFail();

    expect($membership->role)->toBe('owner');
});

it('never stores the password in plaintext', function () {
    $password = 'a-very-strong-password';

    $this->artisan('marqira:create-admin', [
        '--name' => 'Ada Admin',
        '--email' => 'ada@marqira.com',
        '--password' => $password,
    ])->assertSuccessful();

    $user = User::where('email', 'ada@marqira.com')->firstOrFail();

    expect($user->password)->not->toBe($password);
    expect(Hash::check($password, $user->password))->toBeTrue();
});

it('records an audit log entry', function () {
    $this->artisan('marqira:create-admin', [
        '--name' => 'Ada Admin',
        '--email' => 'ada@marqira.com',
        '--password' => 'a-very-strong-password',
    ])->assertSuccessful();

    expect(AuditLog::where('event', 'admin_created')->exists())->toBeTrue();
});

it('fails gracefully on an invalid email', function () {
    $this->artisan('marqira:create-admin', [
        '--name' => 'Ada Admin',
        '--email' => 'not-an-email',
        '--password' => 'a-very-strong-password',
    ])->assertFailed();

    expect(User::count())->toBe(0);
});

it('fails when the password is too short', function () {
    $this->artisan('marqira:create-admin', [
        '--name' => 'Ada Admin',
        '--email' => 'ada@marqira.com',
        '--password' => 'short',
    ])->assertFailed();

    expect(User::count())->toBe(0);
});
