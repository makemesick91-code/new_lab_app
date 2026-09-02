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
            BranchSeeder::class,
            RmeBranchSeeder::class,
            PermissionSeeder::class,
            // SATUSEHAT-4A — ICD-10 dental clinical master reference (idempotent).
            ClinicalDiagnosisSeeder::class,
            RoleSeeder::class,
            AdminUserSeeder::class,
            MasterDataSeeder::class,
            TreatmentCategorySeeder::class,
            TreatmentSeeder::class,
            PaymentMethodSeeder::class,
            WaReminderTemplateSeeder::class,
            ClinicRoomSeeder::class,
            // REVISION-SUNU-ADD-ROOM-A-B-1 — named-branch room registry
            // (Cabang Sunu). Runs after ClinicRoomSeeder and long after
            // RmeBranchSeeder, which owns the branch rows it resolves.
            RmeBranchRoomSeeder::class,
            TariffSeeder::class,
            InventorySeeder::class,
            LabOrderSeeder::class,
            ProductionSeeder::class,
            QualityControlSeeder::class,
            DeliverySeeder::class,
            InvoiceSeeder::class,
            PaymentSeeder::class,
        ]);
    }
}
