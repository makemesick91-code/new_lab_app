<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Idempotent RME pilot smoke-test data (Sprint 22 Phase 22.2).
 *
 * Safe to run on local and VPS/pilot databases. Does not delete data or reset sequences.
 * Run explicitly: php artisan db:seed --class=RmeSmokeTestSeeder
 *
 * Prerequisites: PermissionSeeder and RoleSeeder should be run first.
 */
class RmeSmokeTestSeeder extends Seeder
{
    public const SMOKE_TEST_PASSWORD = 'SmokeTestPilot!';

    public const BRANCH_CODE = Branch::MAIN_CODE;

    public const CLINIC_CODE = 'CLN-SMOKE-TEST';

    public const DOCTOR_CODE = 'DOC-SMOKE-TEST';

    public const DOCTOR_NAME = 'DOKTER SMOKE TEST';

    public const PATIENT_NAME = 'PASIEN SMOKE TEST RME';

    public const PATIENT_MRN = 'MRN-SMOKE-TEST-RME';

    public const VISIT_NUMBER = 'VIS-SMOKE-TEST-RME';

    public const VISIT_NUMBER_CASHIER = 'VIS-SMOKE-CASHIER-RME';

    public const VISIT_LABEL = 'KUNJUNGAN SMOKE TEST RME';

    public const DOCTOR_USER_EMAIL = 'dokter.smoke@pilot-test.local';

    public const PERAWAT_USER_EMAIL = 'perawat.smoke@pilot-test.local';

    public const KASIR_USER_EMAIL = 'kasir.smoke@pilot-test.local';

    public const OWNER_USER_EMAIL = 'owner.smoke@pilot-test.local';

    public const DOCTOR_USER_NAME = 'DOKTER SMOKE TEST';

    public const PERAWAT_USER_NAME = 'PERAWAT SMOKE TEST';

    public const KASIR_USER_NAME = 'KASIR SMOKE TEST';

    public const OWNER_USER_NAME = 'OWNER SMOKE TEST';

    public function run(): void
    {
        $this->ensureRolesExist();

        $branch = Branch::firstOrCreate(
            ['code' => self::BRANCH_CODE],
            [
                'name' => 'Klinik Gigi Daengtisia Pusat',
                'address' => 'Makassar',
                'phone' => null,
                'is_active' => true,
                'is_rme_enabled' => true,
            ]
        );

        $branch->forceFill(['is_rme_enabled' => true, 'is_active' => true])->save();

        $clinic = Clinic::firstOrCreate(
            ['code' => self::CLINIC_CODE],
            [
                'name' => 'Klinik Smoke Test RME (Pilot)',
                'phone' => '080000000001',
                'email' => 'smoke-clinic@pilot-test.local',
                'address' => 'Alamat uji coba smoke test — bukan data pasien nyata',
                'city' => 'Makassar',
                'province' => 'Sulawesi Selatan',
                'postal_code' => '90000',
                'is_active' => true,
            ]
        );

        $doctor = Doctor::firstOrCreate(
            ['code' => self::DOCTOR_CODE],
            [
                'clinic_id' => $clinic->id,
                'name' => self::DOCTOR_NAME,
                'phone' => '080000000002',
                'email' => 'dokter.smoke.master@pilot-test.local',
                'is_active' => true,
            ]
        );

        $patient = Patient::firstOrCreate(
            ['medical_record_number' => self::PATIENT_MRN],
            [
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'name' => self::PATIENT_NAME,
                'gender' => 'Male',
                'date_of_birth' => '1990-01-01',
                'phone' => '080000000003',
                'address' => 'Alamat uji coba smoke test — bukan data pasien nyata',
                'is_active' => true,
            ]
        );

        $doctorUser = $this->seedUser(
            self::DOCTOR_USER_EMAIL,
            self::DOCTOR_USER_NAME,
            'Doctor'
        );

        $doctor->forceFill([
            'user_id' => $doctorUser->id,
            'branch_id' => $branch->id,
        ])->save();
        $doctor->branches()->syncWithoutDetaching([(int) $branch->id]);

        $smokeRoom = ClinicRoom::firstOrCreate(
            ['branch_id' => $branch->id, 'code' => 'RM-SMOKE'],
            [
                'name' => 'Ruangan Smoke Test',
                'type' => ClinicRoom::TYPE_TREATMENT_ROOM,
                'status' => ClinicRoom::STATUS_ACTIVE,
            ]
        );

        app(UserOnlineContextService::class)->startDoctorSession(
            $doctorUser,
            (int) $branch->id,
            (int) $smokeRoom->id,
        );

        $perawatUser = $this->seedUser(
            self::PERAWAT_USER_EMAIL,
            self::PERAWAT_USER_NAME,
            'Perawat'
        );

        // RME-BRANCH-SUN4 — Perawat now needs a branch-only online context before
        // any RME route, exactly like the Doctor session started above. Without it
        // the seeded smoke account is redirected to the context selector and cannot
        // exercise the visit routes it exists to smoke-test.
        app(UserOnlineContextService::class)->startPerawatSession(
            $perawatUser,
            (int) $branch->id,
        );

        $kasirUser = $this->seedUser(
            self::KASIR_USER_EMAIL,
            self::KASIR_USER_NAME,
            'Kasir'
        );

        $this->seedUser(
            self::OWNER_USER_EMAIL,
            self::OWNER_USER_NAME,
            'Owner'
        );

        $visitDate = Carbon::today()->toDateString();

        $clinicalVisit = ClinicVisit::firstOrCreate(
            ['visit_number' => self::VISIT_NUMBER],
            [
                'branch_id' => $branch->id,
                'clinic_id' => $clinic->id,
                'clinic_room_id' => $smokeRoom->id,
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'visit_date' => $visitDate,
                'queue_number' => 901,
                'status' => ClinicVisit::STATUS_IN_PROGRESS,
                'chief_complaint' => self::VISIT_LABEL.' — keluhan uji coba pilot',
                'check_in_at' => now(),
                'started_at' => now(),
                'created_by' => $perawatUser->id,
            ]
        );
        $clinicalVisit->forceFill(['clinic_room_id' => $smokeRoom->id])->save();

        MedicalRecord::firstOrCreate(
            ['clinic_visit_id' => $clinicalVisit->id],
            [
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'status' => MedicalRecord::STATUS_DRAFT,
                'notes' => 'Rekam medis smoke test — draft untuk uji dokter',
                'recorded_by' => $doctorUser->id,
            ]
        );

        Odontogram::firstOrCreate(
            ['clinic_visit_id' => $clinicalVisit->id],
            [
                'branch_id' => $branch->id,
                'status' => Odontogram::STATUS_DRAFT,
                'summary_notes' => 'Odontogram smoke test — draft untuk uji dokter',
                'created_by' => $doctorUser->id,
                'updated_by' => $doctorUser->id,
            ]
        );

        $cashierVisit = ClinicVisit::firstOrCreate(
            ['visit_number' => self::VISIT_NUMBER_CASHIER],
            [
                'branch_id' => $branch->id,
                'clinic_id' => $clinic->id,
                'clinic_room_id' => $smokeRoom->id,
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'visit_date' => $visitDate,
                'queue_number' => 902,
                'status' => ClinicVisit::STATUS_CASHIER_PENDING,
                'chief_complaint' => self::VISIT_LABEL.' — antrian kasir uji coba',
                'check_in_at' => now()->subHour(),
                'started_at' => now()->subHour(),
                'created_by' => $perawatUser->id,
            ]
        );
        $cashierVisit->forceFill(['clinic_room_id' => $smokeRoom->id])->save();

        MedicalRecord::firstOrCreate(
            ['clinic_visit_id' => $cashierVisit->id],
            [
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'status' => MedicalRecord::STATUS_FINAL,
                'notes' => 'Rekam medis smoke test — final untuk uji kasir',
                'recorded_by' => $doctorUser->id,
                'finalized_at' => now(),
                'finalized_by' => $doctorUser->id,
            ]
        );
    }

    private function ensureRolesExist(): void
    {
        $requiredRoles = ['Doctor', 'Perawat', 'Kasir', 'Owner'];

        foreach ($requiredRoles as $roleName) {
            if (! Role::where('name', $roleName)->where('guard_name', 'web')->exists()) {
                $this->call([
                    PermissionSeeder::class,
                    RoleSeeder::class,
                ]);

                return;
            }
        }
    }

    private function seedUser(string $email, string $name, string $roleName): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::SMOKE_TEST_PASSWORD),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole($roleName)) {
            $user->assignRole($roleName);
        }

        return $user;
    }
}
