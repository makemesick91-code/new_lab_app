<?php

/*
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — shared fixtures of the tariff authority
| suites. Every helper is prefixed `rta` so it can never collide with another
| suite's global Pest helper.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Support\TariffAuthorityConfig;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;

if (! function_exists('rtaSetup')) {
    /**
     * Two RME branches (RT01 with tariffs, RT02 without), two active treatments,
     * a cashier. Tariffs of RT01: Scaling 250.000, Tambal 300.000, in force from
     * 2026-01-01. No branch is strict until a test makes it so.
     */
    function rtaSetup(): void
    {
        test()->seed(BranchSeeder::class);
        seedAccessControl();

        $t = test();
        $t->clinic = Clinic::factory()->create();
        $t->branch = Branch::factory()->create(['code' => 'RT01', 'name' => 'Cabang Tarif', 'is_rme_enabled' => true, 'is_active' => true]);
        $t->otherBranch = Branch::factory()->create(['code' => 'RT02', 'name' => 'Cabang Tanpa Tarif', 'is_rme_enabled' => true, 'is_active' => true]);
        $t->doctor = Doctor::factory()->create();
        $t->scaling = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false, 'name' => 'Scaling', 'code' => 'TRT-RTSC']);
        $t->tambal = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false, 'name' => 'Tambal', 'code' => 'TRT-RTTB']);
        $t->patient = Patient::factory()->create(['branch_id' => $t->branch->id]);
        $t->cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);

        $t->scalingTariff = rtaTariff($t->branch, $t->scaling, '250000.00');
        $t->tambalTariff = rtaTariff($t->branch, $t->tambal, '300000.00');

        rtaStrict([]);
        rtaExceptions(false);
    }

    function rtaTariff(Branch $branch, Treatment $treatment, string $price, string $effective = '2026-01-01', bool $active = true): Tariff
    {
        return Tariff::query()->create([
            'branch_id' => $branch->id,
            'treatment_id' => $treatment->id,
            'price' => $price,
            'effective_date' => $effective,
            'is_active' => $active,
        ]);
    }

    /** @param list<string> $codes */
    function rtaStrict(array $codes): void
    {
        config()->set(TariffAuthorityConfig::KEY_STRICT_BRANCH_CODES, TariffAuthorityConfig::parseBranchCodes(implode(',', $codes)));
    }

    function rtaExceptions(bool $enabled): void
    {
        config()->set(TariffAuthorityConfig::KEY_EXCEPTIONS_ENABLED, $enabled);
    }

    function rtaVisit(?Branch $branch = null): ClinicVisit
    {
        $branch ??= test()->branch;

        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $branch->id,
            'clinic_id' => test()->clinic->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'visit_number' => 'VIS-RT-'.uniqid(),
            'visit_date' => clinicalToday()->toDateString(),
        ]);

        MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $branch->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
        ]);

        rmeSignedConsentFor($visit);

        return $visit;
    }

    /** @return array<string, mixed> */
    function rtaLine(?Treatment $treatment, mixed $price, mixed $discount = 0, int $qty = 1, array $extra = []): array
    {
        return array_merge([
            'treatment_id' => $treatment?->id,
            'description' => $treatment?->name ?? 'Tindakan manual',
            'qty' => $qty,
            'unit_price' => $price,
            'discount' => $discount,
        ], $extra);
    }

    /** @param list<array<string, mixed>> $lines */
    function rtaCreate(ClinicVisit $visit, array $lines, ?User $cashier = null): RmeInvoice
    {
        $cashier ??= test()->cashier;
        test()->actingAs($cashier);

        return app(RmeInvoiceService::class)->create($visit, $cashier, ['items' => $lines])->refresh();
    }
}
