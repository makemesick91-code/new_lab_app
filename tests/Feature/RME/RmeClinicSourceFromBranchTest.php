<?php

/**
 * Sprint 23 Phase 23.9.1 — RME Clinic Source from Branch Master.
 *
 * Business rule: "Klinik" = "Cabang RME". RME patient registration and RME
 * visit creation source their Klinik/Cabang from active RME-enabled branches
 * (mst_branches where is_active = true AND is_rme_enabled = true). The legacy
 * mst_clinics master is no longer used for new RME Klinik choices, and the
 * MAIN fallback branch (is_rme_enabled = false) stays hidden from the
 * operational dropdowns.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Treatment\Models\Treatment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Carbon::setTestNow(Carbon::parse('2026-06-13 09:00:00'));

    // MAIN is the technical fallback only — hidden from operational RME dropdowns.
    Branch::where('code', Branch::MAIN_CODE)->update([
        'is_rme_enabled' => false,
        'is_inventory_enabled' => false,
    ]);

    $this->rmeBranch = Branch::factory()->create([
        'code' => 'TKM1',
        'name' => 'Cabang Telkomas',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => false,
    ]);

    $this->inactiveBranch = Branch::factory()->create([
        'code' => 'INA9',
        'name' => 'Cabang Nonaktif',
        'is_active' => false,
        'is_rme_enabled' => true,
    ]);

    $this->inventoryOnlyBranch = Branch::factory()->create([
        'code' => 'INV9',
        'name' => 'Cabang Gudang',
        'is_active' => true,
        'is_rme_enabled' => false,
        'is_inventory_enabled' => true,
    ]);

    $this->clinic = Clinic::factory()->create(['name' => 'Legacy Klinik Lama']);
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->treatment = Treatment::factory()->create(['is_active' => true]);

    $this->admin = userWith(['manage patients', 'manage_clinic_visits', 'view_clinic_visits']);
});

afterEach(fn () => Carbon::setTestNow());

// --- Patient registration form -------------------------------------------------

it('shows active RME branches as Klinik/Cabang on the patient create form', function () {
    $this->actingAs($this->admin)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('TKM1 — Cabang Telkomas');
});

it('does not show MAIN fallback branch on the patient create form', function () {
    $this->actingAs($this->admin)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertDontSee('MAIN —');
});

it('does not show an inactive branch on the patient create form', function () {
    $this->actingAs($this->admin)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertDontSee('INA9 — Cabang Nonaktif');
});

it('does not show an inventory-only branch on the patient create form', function () {
    $this->actingAs($this->admin)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertDontSee('INV9 — Cabang Gudang');
});

it('rejects a non-RME branch on patient store', function () {
    $this->actingAs($this->admin)
        ->from(route('settings.patients.create'))
        ->post(route('settings.patients.store'), [
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'branch_id' => $this->inventoryOnlyBranch->id,
            'registered_at' => '2026-06-13',
            'manual_rm_number' => '0001',
            'name' => 'Pasien Salah Cabang',
        ])
        ->assertSessionHasErrors('branch_id');

    expect(Patient::where('name', 'Pasien Salah Cabang')->exists())->toBeFalse();
});

it('composes the RM number using the selected RME branch code on patient store', function () {
    $this->actingAs($this->admin)
        ->post(route('settings.patients.store'), [
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'branch_id' => $this->rmeBranch->id,
            'registered_at' => '2026-06-13',
            'manual_rm_number' => '0001',
            'name' => 'Pasien RME Telkomas',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('mst_patients', [
        'name' => 'Pasien RME Telkomas',
        'branch_id' => $this->rmeBranch->id,
        'medical_record_number' => 'RM DG-TKM1-2026-0001',
    ]);
});

// --- RME visit form ------------------------------------------------------------

it('shows RME branches as Klinik/Cabang on the visit create form', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('TKM1 — Cabang Telkomas');
});

it('does not render the legacy clinic master as Klinik options on the visit create form', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertDontSee('Legacy Klinik Lama');
});

it('keeps MAIN fallback branch hidden from the visit create form dropdown', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertDontSee('MAIN —');
});

it('stores patient branch_id from the selected RME branch in new-patient mode', function () {
    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Baru Telkomas',
                'branch_id' => $this->rmeBranch->id,
                'registered_at' => '2026-06-13',
                'manual_rm_number' => '0009',
            ],
        ])
        ->assertRedirect();

    $patient = Patient::firstWhere('name', 'Pasien Baru Telkomas');

    expect($patient)->not->toBeNull()
        ->and($patient->branch_id)->toBe($this->rmeBranch->id)
        ->and($patient->medical_record_number)->toBe('RM DG-TKM1-2026-0009');
});

it('stores visit branch_id from the selected RME branch in new-patient mode', function () {
    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Baru Visit',
                'branch_id' => $this->rmeBranch->id,
                'registered_at' => '2026-06-13',
                'manual_rm_number' => '0010',
            ],
        ])
        ->assertRedirect();

    $patient = Patient::firstWhere('name', 'Pasien Baru Visit');
    $visit = ClinicVisit::where('patient_id', $patient->id)->first();

    expect($visit)->not->toBeNull()
        ->and($visit->branch_id)->toBe($this->rmeBranch->id);
});

it('uses the selected RME branch for an existing-patient visit', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'medical_record_number' => 'RM DG-TKM1-2026-0001',
    ]);

    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'branch_id' => $this->rmeBranch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('trx_clinic_visits', [
        'patient_id' => $patient->id,
        'branch_id' => $this->rmeBranch->id,
    ]);
});

it('rejects a non-RME branch supplied as the visit Klinik/Cabang', function () {
    $patient = Patient::factory()->create();

    $this->actingAs($this->admin)
        ->from(route('rme.visits.create'))
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'branch_id' => $this->inventoryOnlyBranch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertSessionHasErrors('branch_id');
});

// --- Backward compatibility ----------------------------------------------------

it('keeps the legacy clinic master settings route working', function () {
    $clinicAdmin = userWith(['manage clinics']);

    $this->actingAs($clinicAdmin)
        ->get(route('settings.clinics.index'))
        ->assertOk();
});

it('does not enforce an RME branch on the global lab order list', function () {
    $labUser = userWith(['view_lab_orders']);

    $this->actingAs($labUser)
        ->get(route('lab-orders.index'))
        ->assertOk();
});

it('rejects new patient visit when visit branch differs from new patient branch', function () {
    $this->actingAs($this->admin);

    $otherRmeBranch = Branch::factory()->create([
        'code' => 'BD99',
        'name' => 'Cabang Beda Valid',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    $treatment = Treatment::factory()->create([
        'is_active' => true,
    ]);

    $this->post(route('rme.visits.store'), [
        'patient_mode' => 'new',
        'branch_id' => $this->rmeBranch->id,
        'doctor_id' => $this->doctor->id,
        'initial_treatment_id' => $treatment->id,
        'visit_date' => now()->toDateString(),
        'new_patient' => [
            'name' => 'Pasien Beda Cabang',
            'branch_id' => $otherRmeBranch->id,
            'registered_at' => now()->toDateString(),
            'manual_rm_number' => '0099',
        ],
    ])->assertSessionHasErrors('new_patient.branch_id');

    expect(Patient::where('name', 'Pasien Beda Cabang')->exists())->toBeFalse();
});
