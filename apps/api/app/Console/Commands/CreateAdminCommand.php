<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CreateAdminCommand extends Command
{
    protected $signature = 'marqira:create-admin
                            {--name= : Administrator full name}
                            {--email= : Administrator email address}
                            {--password= : Administrator password (min 12 chars)}';

    protected $description = 'Create the first MarQira Pulse administrator';

    public function handle(): int
    {
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');

        $interactive = $name === null && $email === null && $password === null;

        if ($name === null) {
            $name = (string) $this->ask('Administrator name');
        }

        if ($email === null) {
            $email = (string) $this->ask('Administrator email');
        }

        if ($password === null) {
            $password = (string) $this->secret('Administrator password (min 12 chars)');

            if ($interactive) {
                $confirm = (string) $this->secret('Confirm password');
                if ($password !== $confirm) {
                    $this->error('Passwords do not match.');
                    return self::FAILURE;
                }
            }
        }

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");
            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $this->warn('One or more users already exist — creating an additional administrator.');
        }

        try {
            DB::transaction(function () use ($name, $email, $password) {
                $organization = Organization::query()->orderBy('id')->first();

                if ($organization === null) {
                    $organization = Organization::create([
                        'name' => 'MarQira',
                        'slug' => 'marqira',
                    ]);
                }

                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'platform_role' => User::ROLE_OWNER,
                    'is_active' => true,
                ]);

                OrganizationMembership::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                ]);

                AuditLog::record([
                    'organization_id' => $organization->id,
                    'actor_id' => $user->id,
                    'actor_type' => 'system',
                    'event' => 'admin_created',
                    'subject_type' => User::class,
                    'subject_id' => $user->id,
                    'subject_uuid' => $user->uuid,
                    'metadata' => [
                        'email' => $user->email,
                        'organization' => $organization->slug,
                        'role' => 'owner',
                    ],
                ]);
            });
        } catch (Throwable $e) {
            $this->error('Failed to create administrator: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info("Admin created: {$name} <{$email}>");

        return self::SUCCESS;
    }
}
