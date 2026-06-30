<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class StressSeedFoundationCommand extends Command
{
    protected $signature = 'stress:seed-foundation';

    protected $description = 'Seed stress-test foundation data. Only runs in APP_ENV=stress.';

    public function handle(): int
    {
        if (! app()->environment('stress')) {
            $this->error('This command only runs in APP_ENV=stress.');
            return self::FAILURE;
        }

        $now = now();

        $this->info('Seeding stress foundation data...');

        DB::transaction(function () use ($now) {
            $branchId = $this->seedBranch($now);

            $this->seedRooms($branchId, $now);
            $this->seedRolesAndPermissions();
            $this->seedUsers($now);
            $this->seedDoctors($branchId, $now);
            $this->seedTreatmentMaster($branchId, $now);
            $this->seedPaymentMethods($now);
        });

        $this->info('Stress foundation data seeded successfully.');

        $this->table(
            ['table', 'count'],
            [
                ['mst_branches', DB::table('mst_branches')->count()],
                ['mst_clinic_rooms', DB::table('mst_clinic_rooms')->count()],
                ['users', DB::table('users')->count()],
                ['roles', DB::table('roles')->count()],
                ['permissions', DB::table('permissions')->count()],
                ['mst_doctors', DB::table('mst_doctors')->count()],
                ['mst_treatment_categories', DB::table('mst_treatment_categories')->count()],
                ['mst_treatments', DB::table('mst_treatments')->count()],
                ['mst_tariffs', DB::table('mst_tariffs')->count()],
                ['mst_payment_methods', DB::table('mst_payment_methods')->count()],
            ]
        );

        return self::SUCCESS;
    }

    private function seedBranch($now): int
    {
        DB::table('mst_branches')->updateOrInsert(
            ['code' => 'TST'],
            [
                'name' => 'Stress Test Branch',
                'address' => 'Alamat Dummy Stress Test',
                'phone' => '080000000001',
                'is_active' => true,
                'is_rme_enabled' => true,
                'is_inventory_enabled' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        return (int) DB::table('mst_branches')->where('code', 'TST')->value('id');
    }

    private function seedRooms(int $branchId, $now): void
    {
        $rooms = [
            ['code' => 'TST-RM-01', 'name' => 'Stress Ruang Periksa 01', 'type' => 'doctor'],
            ['code' => 'TST-RM-02', 'name' => 'Stress Ruang Periksa 02', 'type' => 'doctor'],
            ['code' => 'TST-RM-03', 'name' => 'Stress Ruang Periksa 03', 'type' => 'doctor'],
            ['code' => 'TST-RM-04', 'name' => 'Stress Ruang Periksa 04', 'type' => 'doctor'],
            ['code' => 'TST-RM-05', 'name' => 'Stress Ruang Periksa 05', 'type' => 'doctor'],
            ['code' => 'TST-TR-01', 'name' => 'Stress Ruang Tindakan 01', 'type' => 'treatment'],
            ['code' => 'TST-TR-02', 'name' => 'Stress Ruang Tindakan 02', 'type' => 'treatment'],
            ['code' => 'TST-TR-03', 'name' => 'Stress Ruang Tindakan 03', 'type' => 'treatment'],
            ['code' => 'TST-CS-01', 'name' => 'Stress Ruang Konsultasi 01', 'type' => 'consultation'],
            ['code' => 'TST-CS-02', 'name' => 'Stress Ruang Konsultasi 02', 'type' => 'consultation'],
        ];

        foreach ($rooms as $room) {
            DB::table('mst_clinic_rooms')->updateOrInsert(
                [
                    'branch_id' => $branchId,
                    'code' => $room['code'],
                ],
                [
                    'name' => $room['name'],
                    'type' => $room['type'],
                    'status' => 'active',
                    'description' => 'Dummy room for stress testing only.',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function seedRolesAndPermissions(): void
    {
        $roles = [
            'Super Admin',
            'Admin Klinik',
            'Perawat',
            'Doctor',
            'Kasir',
            'Admin Lab',
            'Technician',
            'QC',
            'Delivery',
            'Admin Warehouse',
            'Owner',
            'Supervisor RME',
        ];

        $permissions = [
            'view dashboard',
            'view_owner_dashboard',
            'view_branch_dashboard',

            'view_clinic_visits',
            'manage_clinic_visits',
            'manage_medical_records',
            'manage_odontograms',
            'manage_rme_billing',
            'view_treatment_worklist',
            'view_rme_patient_reports',
            'view_rme_payment_reports',

            'view_lab_orders',
            'manage_lab_orders',

            'view_inventory',
            'manage_inventory',
            'export_report',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach ($roles as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($roleName === 'Super Admin') {
                $role->syncPermissions($permissions);
                continue;
            }

            if ($roleName === 'Owner') {
                $role->syncPermissions([
                    'view dashboard',
                    'view_owner_dashboard',
                    'view_rme_patient_reports',
                    'view_rme_payment_reports',
                    'export_report',
                ]);
                continue;
            }

            if ($roleName === 'Supervisor RME') {
                $role->syncPermissions([
                    'view dashboard',
                    'view_clinic_visits',
                    'manage_clinic_visits',
                    'manage_medical_records',
                    'manage_odontograms',
                    'view_treatment_worklist',
                    'view_rme_patient_reports',
                    'view_rme_payment_reports',
                ]);
                continue;
            }

            if ($roleName === 'Admin Klinik') {
                $role->syncPermissions([
                    'view dashboard',
                    'view_clinic_visits',
                    'manage_clinic_visits',
                    'view_treatment_worklist',
                    'view_rme_patient_reports',
                ]);
                continue;
            }

            if ($roleName === 'Perawat') {
                $role->syncPermissions([
                    'view dashboard',
                    'view_clinic_visits',
                    'manage_clinic_visits',
                ]);
                continue;
            }

            if ($roleName === 'Doctor') {
                $role->syncPermissions([
                    'view dashboard',
                    'view_clinic_visits',
                    'manage_medical_records',
                    'manage_odontograms',
                    'view_treatment_worklist',
                ]);
                continue;
            }

            if ($roleName === 'Kasir') {
                $role->syncPermissions([
                    'view dashboard',
                    'view_clinic_visits',
                    'manage_rme_billing',
                    'view_rme_payment_reports',
                ]);
                continue;
            }

            if ($roleName === 'Admin Lab') {
                $role->syncPermissions([
                    'view dashboard',
                    'view_lab_orders',
                    'manage_lab_orders',
                ]);
                continue;
            }

            if (in_array($roleName, ['Technician', 'QC', 'Delivery'], true)) {
                $role->syncPermissions([
                    'view dashboard',
                    'view_lab_orders',
                ]);
                continue;
            }

            if ($roleName === 'Admin Warehouse') {
                $role->syncPermissions([
                    'view dashboard',
                    'view_inventory',
                    'manage_inventory',
                    'export_report',
                ]);
            }
        }
    }

    private function seedUsers($now): void
    {
        $password = Hash::make('Password123!');

        $groups = [
            'Admin Klinik' => ['prefix' => 'admin', 'count' => 10, 'name' => 'Stress Admin Klinik'],
            'Perawat' => ['prefix' => 'nurse', 'count' => 15, 'name' => 'Stress Perawat'],
            'Doctor' => ['prefix' => 'doctor', 'count' => 25, 'name' => 'Stress Doctor'],
            'Kasir' => ['prefix' => 'cashier', 'count' => 15, 'name' => 'Stress Kasir'],
            'Admin Lab' => ['prefix' => 'lab', 'count' => 10, 'name' => 'Stress Admin Lab'],
            'Technician' => ['prefix' => 'technician', 'count' => 4, 'name' => 'Stress Technician'],
            'QC' => ['prefix' => 'qc', 'count' => 3, 'name' => 'Stress QC'],
            'Delivery' => ['prefix' => 'delivery', 'count' => 3, 'name' => 'Stress Delivery'],
            'Admin Warehouse' => ['prefix' => 'warehouse', 'count' => 10, 'name' => 'Stress Warehouse'],
            'Owner' => ['prefix' => 'owner', 'count' => 3, 'name' => 'Stress Owner'],
            'Supervisor RME' => ['prefix' => 'supervisor', 'count' => 2, 'name' => 'Stress Supervisor RME'],
        ];

        foreach ($groups as $roleName => $group) {
            for ($i = 1; $i <= $group['count']; $i++) {
                $email = sprintf('stress.%s%03d@daengtisia.test', $group['prefix'], $i);

                DB::table('users')->updateOrInsert(
                    ['email' => $email],
                    [
                        'name' => sprintf('%s %03d', $group['name'], $i),
                        'password' => $password,
                        'email_verified_at' => $now,
                        'phone' => '0800' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $userId = (int) DB::table('users')->where('email', $email)->value('id');

                $roleId = (int) DB::table('roles')
                    ->where('name', $roleName)
                    ->where('guard_name', 'web')
                    ->value('id');

                DB::table('model_has_roles')->updateOrInsert(
                    [
                        'role_id' => $roleId,
                        'model_type' => 'App\\Models\\User',
                        'model_id' => $userId,
                    ],
                    []
                );
            }
        }
    }

    private function seedDoctors(int $branchId, $now): void
    {
        $doctorUsers = DB::table('users')
            ->where('email', 'like', 'stress.doctor%@daengtisia.test')
            ->orderBy('email')
            ->limit(25)
            ->get();

        foreach ($doctorUsers as $index => $user) {
            $number = $index + 1;

            DB::table('mst_doctors')->updateOrInsert(
                ['code' => sprintf('TST-DOC-%03d', $number)],
                [
                    'clinic_id' => null,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'is_active' => true,
                    'user_id' => $user->id,
                    'branch_id' => $branchId,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $doctorId = (int) DB::table('mst_doctors')
                ->where('code', sprintf('TST-DOC-%03d', $number))
                ->value('id');

            DB::table('mst_doctor_branches')->updateOrInsert(
                [
                    'doctor_id' => $doctorId,
                    'branch_id' => $branchId,
                ],
                [
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function seedTreatmentMaster(int $branchId, $now): void
    {
        $categories = [
            ['code' => 'TST-KONS', 'name' => 'Stress Konsultasi', 'sort_order' => 1],
            ['code' => 'TST-KONSERV', 'name' => 'Stress Konservasi', 'sort_order' => 2],
            ['code' => 'TST-PROSTHO', 'name' => 'Stress Prosthodonti', 'sort_order' => 3],
            ['code' => 'TST-BEDAH', 'name' => 'Stress Bedah Mulut', 'sort_order' => 4],
        ];

        foreach ($categories as $category) {
            DB::table('mst_treatment_categories')->updateOrInsert(
                ['code' => $category['code']],
                [
                    'name' => $category['name'],
                    'description' => 'Dummy category for stress testing only.',
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $categoryIds = DB::table('mst_treatment_categories')
            ->whereIn('code', array_column($categories, 'code'))
            ->pluck('id', 'code');

        $treatments = [
            ['cat' => 'TST-KONS', 'code' => 'TST-CHECKUP', 'name' => 'Stress Pemeriksaan Gigi', 'price' => 75000, 'lab' => false],
            ['cat' => 'TST-KONS', 'code' => 'TST-CONSULT', 'name' => 'Stress Konsultasi Dokter', 'price' => 100000, 'lab' => false],
            ['cat' => 'TST-KONSERV', 'code' => 'TST-FILLING', 'name' => 'Stress Tambal Gigi', 'price' => 250000, 'lab' => false],
            ['cat' => 'TST-KONSERV', 'code' => 'TST-SCALING', 'name' => 'Stress Scaling', 'price' => 300000, 'lab' => false],
            ['cat' => 'TST-KONSERV', 'code' => 'TST-RCT', 'name' => 'Stress Perawatan Saluran Akar', 'price' => 750000, 'lab' => false],
            ['cat' => 'TST-PROSTHO', 'code' => 'TST-CROWN', 'name' => 'Stress Crown', 'price' => 1500000, 'lab' => true],
            ['cat' => 'TST-PROSTHO', 'code' => 'TST-DENTURE', 'name' => 'Stress Gigi Tiruan', 'price' => 2000000, 'lab' => true],
            ['cat' => 'TST-BEDAH', 'code' => 'TST-EXTRACT', 'name' => 'Stress Cabut Gigi', 'price' => 350000, 'lab' => false],
            ['cat' => 'TST-BEDAH', 'code' => 'TST-ODONTECT', 'name' => 'Stress Odontektomi', 'price' => 2500000, 'lab' => false],
            ['cat' => 'TST-PROSTHO', 'code' => 'TST-BRIDGE', 'name' => 'Stress Bridge', 'price' => 3000000, 'lab' => true],
        ];

        foreach ($treatments as $treatment) {
            DB::table('mst_treatments')->updateOrInsert(
                ['code' => $treatment['code']],
                [
                    'treatment_category_id' => $categoryIds[$treatment['cat']],
                    'name' => $treatment['name'],
                    'description' => 'Dummy treatment for stress testing only.',
                    'default_duration_minutes' => 30,
                    'requires_doctor' => true,
                    'requires_room' => true,
                    'requires_lab' => $treatment['lab'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $treatmentId = (int) DB::table('mst_treatments')
                ->where('code', $treatment['code'])
                ->value('id');

            DB::table('mst_tariffs')->updateOrInsert(
                [
                    'branch_id' => $branchId,
                    'treatment_id' => $treatmentId,
                ],
                [
                    'price' => $treatment['price'],
                    'effective_date' => '2026-01-01',
                    'is_active' => true,
                    'notes' => 'Dummy tariff for stress testing only.',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function seedPaymentMethods($now): void
    {
        $methods = [
            ['code' => 'TST-CASH', 'name' => 'Stress Cash', 'type' => 'cash', 'sort_order' => 1],
            ['code' => 'TST-DEBIT', 'name' => 'Stress Debit', 'type' => 'card', 'sort_order' => 2],
            ['code' => 'TST-QRIS', 'name' => 'Stress QRIS', 'type' => 'qris', 'sort_order' => 3],
            ['code' => 'TST-TRANSFER', 'name' => 'Stress Transfer', 'type' => 'bank_transfer', 'sort_order' => 4],
        ];

        foreach ($methods as $method) {
            DB::table('mst_payment_methods')->updateOrInsert(
                ['code' => $method['code']],
                [
                    'name' => $method['name'],
                    'type' => $method['type'],
                    'description' => 'Dummy payment method for stress testing only.',
                    'sort_order' => $method['sort_order'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

}
