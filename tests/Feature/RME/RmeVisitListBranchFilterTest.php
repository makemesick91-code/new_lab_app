<?php

/**
 * Sprint 23 Phase 23.9.3 — RME Visit List Branch Filter Fix.
 *
 * Business rule: "Klinik" = "Cabang RME". Daftar Kunjungan RME must list visits
 * across ALL active RME-enabled branches by default (mst_branches where
 * is_active = true AND is_rme_enabled = true), NOT a single BranchContext
 * fallback branch. An optional Cabang RME filter narrows the list to one RME
 * branch. MAIN (is_rme_enabled = false) never appears. clinic_id is never used
 * for scoping, and users.branch_id is never consulted.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Carbon::setTestNow(Carbon::parse('2026-06-13 09:00:00'));

    // MAIN is the technical fallback only — not RME-enabled, never operational.
    Branch::where('code', Branch::MAIN_CODE)->update([
        'is_rme_enabled' => false,
        'is_inventory_enabled' => false,
    ]);

    $this->atg3 = Branch::factory()->create([
        'code' => 'ATG3', 'name' => 'Cabang Antang',
        'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->ldk2 = Branch::factory()->create([
        'code' => 'LDK2', 'name' => 'Cabang Landak',
        'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->tkm1 = Branch::factory()->create([
        'code' => 'TKM1', 'name' => 'Cabang Telkomas',
        'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->inventoryOnly = Branch::factory()->create([
        'code' => 'INV9', 'name' => 'Cabang Gudang',
        'is_active' => true, 'is_rme_enabled' => false, 'is_inventory_enabled' => true,
    ]);

    $this->doctor = Doctor::factory()->create();

    $this->admin = userWith(['view_clinic_visits', 'manage_clinic_visits']);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Create a visit at a given branch with a named patient.
 */
function visitAt(Branch $branch, string $patientName, array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'name' => $patientName,
    ]);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
    ], $overrides));
}

// --- Default scope -------------------------------------------------------------

it('shows visits from all active RME branches by default', function () {
    visitAt($this->atg3, 'Pasien Antang');
    visitAt($this->ldk2, 'Pasien Landak');
    visitAt($this->tkm1, 'Pasien Telkomas');

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien Antang')
        ->assertSee('Pasien Landak')
        ->assertSee('Pasien Telkomas');
});

it('shows an ATG3 visit even when the BranchContext fallback is TKM1', function () {
    // Force the generic fallback away from ATG3 — list scope must not depend on it.
    $this->mock(BranchContext::class, function ($mock) {
        $mock->shouldReceive('id')->andReturn($this->tkm1->id);
        $mock->shouldReceive('requireId')->andReturn($this->tkm1->id);
    });

    visitAt($this->atg3, 'Megasanti Antang');

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Megasanti Antang');
});

it('shows an ATG3 visit even when the BranchContext fallback is MAIN', function () {
    $main = Branch::where('code', Branch::MAIN_CODE)->first();

    $this->mock(BranchContext::class, function ($mock) use ($main) {
        $mock->shouldReceive('id')->andReturn($main->id);
        $mock->shouldReceive('requireId')->andReturn($main->id);
    });

    visitAt($this->atg3, 'Megasanti Main Fallback');

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Megasanti Main Fallback');
});

it('does not require clinic_id to list a visit', function () {
    $visit = visitAt($this->atg3, 'Pasien Tanpa Klinik', ['clinic_id' => null]);

    expect($visit->clinic_id)->toBeNull();

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien Tanpa Klinik');
});

// --- Filter options ------------------------------------------------------------

it('does not show MAIN as a Cabang RME filter option', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('ATG3 — Cabang Antang')
        ->assertDontSee('MAIN —');
});

it('does not show an inventory-only branch as a Cabang RME filter option', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertDontSee('INV9 — Cabang Gudang');
});

// --- Filter behaviour ----------------------------------------------------------

it('filtering by ATG3 shows only ATG3 visits', function () {
    visitAt($this->atg3, 'Pasien Antang');
    visitAt($this->tkm1, 'Pasien Telkomas');

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index', ['branch_id' => $this->atg3->id]))
        ->assertOk()
        ->assertSee('Pasien Antang')
        ->assertDontSee('Pasien Telkomas');
});

it('filtering by TKM1 shows only TKM1 visits', function () {
    visitAt($this->atg3, 'Pasien Antang');
    visitAt($this->tkm1, 'Pasien Telkomas');

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index', ['branch_id' => $this->tkm1->id]))
        ->assertOk()
        ->assertSee('Pasien Telkomas')
        ->assertDontSee('Pasien Antang');
});

it('ignores a non-RME branch filter and shows all RME visits', function () {
    visitAt($this->atg3, 'Pasien Antang');
    visitAt($this->tkm1, 'Pasien Telkomas');

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index', ['branch_id' => $this->inventoryOnly->id]))
        ->assertOk()
        ->assertSee('Pasien Antang')
        ->assertSee('Pasien Telkomas');
});

// --- Other filters still work across all RME branches --------------------------

it('search still works across all RME branches', function () {
    visitAt($this->atg3, 'Megasanti Unik');
    visitAt($this->tkm1, 'Pasien Lain');

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index', ['search' => 'Megasanti']))
        ->assertOk()
        ->assertSee('Megasanti Unik')
        ->assertDontSee('Pasien Lain');
});

it('status filter still works across all RME branches', function () {
    visitAt($this->atg3, 'Antang Waiting', ['status' => ClinicVisit::STATUS_WAITING]);
    visitAt($this->tkm1, 'Telkomas Registered', ['status' => ClinicVisit::STATUS_REGISTERED]);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index', ['status' => ClinicVisit::STATUS_WAITING]))
        ->assertOk()
        ->assertSee('Antang Waiting')
        ->assertDontSee('Telkomas Registered');
});

it('date filter still works across all RME branches', function () {
    visitAt($this->atg3, 'Hari Ini Antang', ['visit_date' => '2026-06-13']);
    visitAt($this->tkm1, 'Kemarin Telkomas', ['visit_date' => '2026-06-12']);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index', ['visit_date' => '2026-06-13']))
        ->assertOk()
        ->assertSee('Hari Ini Antang')
        ->assertDontSee('Kemarin Telkomas');
});

// --- Dashboard counts ----------------------------------------------------------

it('visit counts match the all-RME default scope', function () {
    visitAt($this->atg3, 'A', ['status' => ClinicVisit::STATUS_WAITING]);
    visitAt($this->ldk2, 'B', ['status' => ClinicVisit::STATUS_WAITING]);
    visitAt($this->tkm1, 'C', ['status' => ClinicVisit::STATUS_IN_PROGRESS]);

    $service = app(ClinicVisitService::class);

    expect($service->visitsTodayCount())->toBe(3)
        ->and($service->waitingCount())->toBe(2)
        ->and($service->inProgressCount())->toBe(1);
});

it('visit counts match the selected branch filter', function () {
    visitAt($this->atg3, 'A', ['status' => ClinicVisit::STATUS_WAITING]);
    visitAt($this->atg3, 'B', ['status' => ClinicVisit::STATUS_IN_PROGRESS]);
    visitAt($this->tkm1, 'C', ['status' => ClinicVisit::STATUS_WAITING]);

    $service = app(ClinicVisitService::class);

    expect($service->visitsTodayCount($this->atg3->id))->toBe(2)
        ->and($service->waitingCount($this->atg3->id))->toBe(1)
        ->and($service->inProgressCount($this->atg3->id))->toBe(1);
});

// --- Show / policy -------------------------------------------------------------

it('lets a user open an ATG3 visit with view_clinic_visits permission', function () {
    $visit = visitAt($this->atg3, 'Pasien Show Antang');

    $this->actingAs($this->admin)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Pasien Show Antang');
});

// --- Existing + new patient visits ---------------------------------------------

it('shows both existing-patient and new-patient visits in the list', function () {
    // Existing patient registered earlier at ATG3.
    $existing = Patient::factory()->create([
        'branch_id' => $this->atg3->id,
        'name' => 'Pasien Lama ATG3',
    ]);
    ClinicVisit::factory()->create([
        'branch_id' => $this->atg3->id,
        'patient_id' => $existing->id,
        'doctor_id' => $this->doctor->id,
    ]);

    // New patient created with this visit at ATG3.
    visitAt($this->atg3, 'Pasien Baru ATG3');

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien Lama ATG3')
        ->assertSee('Pasien Baru ATG3');
});
