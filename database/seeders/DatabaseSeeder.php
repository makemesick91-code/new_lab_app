<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database (Sprint 0 — Foundation).
     *
     * Order matters: permissions, then roles (which attach permissions),
     * then the default admin user (which is assigned a role).
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            AdminUserSeeder::class,
            MasterDataSeeder::class,
            LabOrderSeeder::class,
            ProductionSeeder::class,
            QualityControlSeeder::class,
            DeliverySeeder::class,
            InvoiceSeeder::class,
            PaymentSeeder::class,
        ]);
    }
}
