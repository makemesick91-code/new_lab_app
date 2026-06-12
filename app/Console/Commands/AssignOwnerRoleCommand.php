<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Sprint 23 Phase 23.3 — safe Owner pilot enablement.
 *
 * Assigns the existing "Owner" role to an existing user (by email) without
 * creating users or touching passwords. Intended for granting an already
 * provisioned pilot account access to the Owner Dashboard.
 */
class AssignOwnerRoleCommand extends Command
{
    protected $signature = 'pilot:assign-owner
                            {email : Email of an EXISTING user to grant the Owner role}';

    protected $description = 'Assign the Owner role to an existing user (no user creation, no password handling)';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        $user = User::where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error("No user found with email: {$email}. Create the user first, then re-run this command.");

            return self::FAILURE;
        }

        $role = Role::where('name', 'Owner')->where('guard_name', 'web')->first();

        if (! $role instanceof Role) {
            $this->error('Owner role not found. Run "php artisan db:seed --class=RoleSeeder" first.');

            return self::FAILURE;
        }

        if ($user->hasRole('Owner')) {
            $this->info("User {$email} already has the Owner role. Nothing to do.");

            return self::SUCCESS;
        }

        $user->assignRole('Owner');

        $this->info("Owner role assigned to {$email}.");

        return self::SUCCESS;
    }
}
