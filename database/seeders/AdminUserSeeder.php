<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Default Super Admin account (DEVELOPMENT_SETUP §18).
     * For local & staging use only. Run after RoleSeeder.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@asiadentallab.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        if (! $admin->hasRole('Super Admin')) {
            $admin->assignRole('Super Admin');
        }
    }
}
