<?php

/**
 * Sprint 23 Phase 23.10 — RME Pilot Data Entry Hardening.
 *
 * Business rule: "Klinik" = "Cabang RME". After Phase 23.9.x made the visit list
 * multi-branch, this phase hardens the rest of the pilot data-entry flow so it
 * never falls back to the single BranchContext branch (MAIN, which is not
 * RME-enabled):
 *   - new + existing patient visit creation,
 *   - visit list / detail visibility,
 *   - doctor odontogram + medical record,
 *   - cashier billing + payment + lab candidate generation,
 * all scoped to the active RME-enabled branch set. clinic_id may be null and must
 * never break a supported RME page. Existing patient.branch_id is never rewritten
 * automatically; legacy patients (branch_id null) remain usable.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
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
    $this->main = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $this->atg3 = Branch::factory()->create(['code' => 'ATG3', 'name' => 'Cabang Antang', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->ldk2 = Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->tkm1 = Branch::factory()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->inv9 = Branch::factory()->create(['code' => 'INV9', 'name' => 'Cabang Gudang', 'is_active' => true, 'is_rme_enabled' => false, 'is_inventory_enabled' => true]);

    $this->doctor = Doctor::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->atg3);

    $this->admin = userWith(['view_clinic_visits', 'manage_clinic_visits', 'manage patients']);
    $this->cashier = userWith(['manage_rme_billing']);
});

afterEach(fn () => Carbon::setTestNow());

/** A registered visit at a branch, with clinic_id forced null (legacy RME). */
function pilotVisit(Branch $branch, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'clinic_id' => null,
    ], $overrides));
}

/** A finalized cashier_pending visit (+ final medical record) at a branch. */
function pilotFinalizedVisit(Branch $branch): array
{
    $visit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $branch->id,
        'clinic_id' => null,
    ]);
    $record = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return [$visit, $record];
}

// ─── A. New patient ──────────────────────────────────────────────────────────

it('creates a new patient visit at an active RME branch', function () {
    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Megasanti',
                'branch_id' => $this->atg3->id,
                'registered_at' => '2026-06-13',
                'manual_rm_number' => '14023',
            ],
        ])
        ->assertRedirect();

    $patient = Patient::firstWhere('name', 'Megasanti');
    expect($patient)->not->toBeNull()
        ->and($patient->branch_id)->toBe($this->atg3->id);

    $visit = ClinicVisit::where('patient_id', $patient->id)->first();
    expect($visit)->not->toBeNull()
        ->and($visit->branch_id)->toBe($this->atg3->id);
});

it('composes the new patient RM as DG-{branch_code}-{year}-{manual}', function () {
    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Megasanti',
                'branch_id' => $this->atg3->id,
                'registered_at' => '2026-06-13',
                'manual_rm_number' => '14023',
            ],
        ])
        ->assertRedirect();

    expect(Patient::firstWhere('name', 'Megasanti')->medical_record_number)
        ->toBe('DG-ATG3-2026-14023');
});

it('preserves leading zeros in the manual RM number', function () {
    rmeMakeDoctorOnline($this->doctor, $this->tkm1);

    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Nol',
                'branch_id' => $this->tkm1->id,
                'registered_at' => '2026-06-13',
                'manual_rm_number' => '0007',
            ],
        ])
        ->assertRedirect();

    expect(Patient::firstWhere('name', 'Pasien Nol')->medical_record_number)
        ->toBe('DG-TKM1-2026-0007');
});

it('rejects MAIN as a new patient branch (not RME-enabled)', function () {
    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Tolak MAIN',
                'branch_id' => $this->main->id,
                'registered_at' => '2026-06-13',
                'manual_rm_number' => '0001',
            ],
        ])
        ->assertSessionHasErrors('new_patient.branch_id');

    expect(Patient::where('name', 'Tolak MAIN')->exists())->toBeFalse();
});

it('shows a new patient visit in the all-RME visit list', function () {
    $this->actingAs($this->admin)->post(route('rme.visits.store'), [
        'patient_mode' => 'new',
        'doctor_id' => $this->doctor->id,
        'initial_treatment_id' => $this->treatment->id,
        'new_patient' => [
            'name' => 'Megasanti',
            'branch_id' => $this->atg3->id,
            'registered_at' => '2026-06-13',
            'manual_rm_number' => '14023',
        ],
    ]);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Megasanti')
        ->assertSee('DG-ATG3-2026-14023');
});

// ─── B. Existing patient ─────────────────────────────────────────────────────

it('uses the selected Cabang RME as the visit branch for an existing patient', function () {
    rmeMakeDoctorOnline($this->doctor, $this->atg3);
    $patient = Patient::factory()->create(['branch_id' => $this->tkm1->id, 'medical_record_number' => 'DG-TKM1-2026-0001']);

    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'patient_id' => $patient->id,
            'branch_id' => $this->atg3->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    expect(ClinicVisit::where('patient_id', $patient->id)->first()->branch_id)->toBe($this->atg3->id);
});

it('allows a legacy patient (branch_id null) to create a visit under a selected branch', function () {
    rmeMakeDoctorOnline($this->doctor, $this->ldk2);
    $patient = Patient::factory()->create(['branch_id' => null, 'clinic_id' => null, 'medical_record_number' => 'RM-LEGACY-001']);

    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'patient_id' => $patient->id,
            'branch_id' => $this->ldk2->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    expect(ClinicVisit::where('patient_id', $patient->id)->first()->branch_id)->toBe($this->ldk2->id);
});

it('does not rewrite patient.branch_id when an existing patient creates a visit elsewhere', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->tkm1->id]);

    $this->actingAs($this->admin)->post(route('rme.visits.store'), [
        'patient_mode' => 'existing',
        'patient_id' => $patient->id,
        'branch_id' => $this->atg3->id,
        'doctor_id' => $this->doctor->id,
        'initial_treatment_id' => $this->treatment->id,
    ]);

    expect($patient->fresh()->branch_id)->toBe($this->tkm1->id);
});

it('lists an existing patient visit in the all-RME scope and the selected branch filter only', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->tkm1->id, 'name' => 'Pasien Lama']);
    pilotVisit($this->atg3, ['patient_id' => $patient->id]);

    // All-RME default scope.
    $this->actingAs($this->admin)->get(route('rme.visits.index'))->assertOk()->assertSee('Pasien Lama');
    // Selected branch filter (the visit's branch).
    $this->actingAs($this->admin)->get(route('rme.visits.index', ['branch_id' => $this->atg3->id]))->assertOk()->assertSee('Pasien Lama');
    // A different branch filter must not show it.
    $this->actingAs($this->admin)->get(route('rme.visits.index', ['branch_id' => $this->tkm1->id]))->assertOk()->assertDontSee('Pasien Lama');
});

// ─── C. Null clinic_id hardening ─────────────────────────────────────────────

it('opens the visit detail when clinic_id is null', function () {
    $visit = pilotVisit($this->atg3);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('ATG3');
});

it('shows the branch label in the visit list when clinic_id is null', function () {
    pilotVisit($this->atg3);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('ATG3');
});

it('opens the odontogram page for a clinic_id-null visit', function () {
    $visit = pilotVisit($this->atg3, ['status' => ClinicVisit::STATUS_IN_PROGRESS]);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();
});

it('opens the medical record page for a clinic_id-null visit', function () {
    $visit = pilotVisit($this->atg3, ['status' => ClinicVisit::STATUS_IN_PROGRESS]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->atg3->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();
});

// ─── D/E. Doctor + cashier ───────────────────────────────────────────────────

it('moves a clinic_id-null visit through the manually supported status transitions', function () {
    $visit = pilotVisit($this->atg3);

    // cashier_pending is reached via medical-record finalization, not a manual
    // transition, so the manual path runs registered → waiting → in_progress.
    foreach (['waiting', 'in_progress'] as $next) {
        $this->actingAs($this->admin)
            ->post(route('rme.visits.transition', $visit), ['status' => $next])
            ->assertRedirect();
        $visit->refresh();
        expect($visit->status)->toBe($next);
    }
});

it('opens the cashier billing create page for a clinic_id-null RME-branch visit (no MAIN fallback)', function () {
    [$visit] = pilotFinalizedVisit($this->atg3);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.create', $visit))
        ->assertOk()
        ->assertSee($this->treatment->name);
});

it('lists an RME-branch cashier_pending visit in the cashier queue (no MAIN fallback)', function () {
    [$visit] = pilotFinalizedVisit($this->atg3);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertSee($visit->visit_number)
        ->assertSee('ATG3');
});

it('creates an invoice and full payment for an RME-branch visit end to end', function () {
    [$visit] = pilotFinalizedVisit($this->atg3);

    $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [[
            'treatment_id' => $this->treatment->id,
            'description' => 'Tindakan',
            'qty' => 1,
            'unit_price' => 250000,
            'discount' => 0,
        ]],
    ]);

    expect($invoice->branch_id)->toBe($this->atg3->id)
        ->and($invoice->grand_total)->toEqual(250000.0);

    $payment = app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, [
        'amount' => 250000,
        'paid_at' => now()->toDateTimeString(),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ]);

    expect($payment->branch_id)->toBe($this->atg3->id)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

// ─── F. Lab candidate ────────────────────────────────────────────────────────

it('generates a lab candidate carrying the RME branch context after payment', function () {
    [$visit] = pilotFinalizedVisit($this->atg3);
    $labTreatment = Treatment::factory()->requiresLab()->create();

    $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [[
            'treatment_id' => $labTreatment->id,
            'description' => 'Crown',
            'qty' => 1,
            'unit_price' => 500000,
            'discount' => 0,
        ]],
    ]);

    app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, [
        'amount' => 500000,
        'paid_at' => now()->toDateTimeString(),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ]);

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->first();
    expect($candidate)->not->toBeNull()
        ->and($candidate->branch_id)->toBe($this->atg3->id)
        ->and($candidate->clinic_visit_id)->toBe($visit->id);
});

// ─── G. Regression ───────────────────────────────────────────────────────────

it('keeps a legacy patient with clinic_id readable in the create form', function () {
    Patient::factory()->create([
        'branch_id' => null,
        'clinic_id' => Clinic::factory()->create()->id,
        'medical_record_number' => 'RM-LEGACY-XYZ',
        'name' => 'Pasien Warisan',
    ]);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('Pasien Warisan')
        ->assertSee('Legacy / belum ada cabang pasien');
});

it('still allows global lab order creation with a clinic_id (legacy lab behavior intact)', function () {
    $labUser = userWith(['create_lab_orders']);

    $this->actingAs($labUser)
        ->post(route('lab-orders.store'), labOrderPayload())
        ->assertRedirect();
});

it('allows viewing a visit in an active RME branch', function () {
    $visit = pilotVisit($this->atg3);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.show', $visit))
        ->assertOk();
});

it('forbids viewing a visit in a non-RME branch', function () {
    $visit = pilotVisit($this->inv9);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.show', $visit))
        ->assertForbidden();
});

it('hides and forbids a MAIN branch visit from the RME flow', function () {
    $mainVisit = ClinicVisit::factory()->create(['branch_id' => $this->main->id, 'clinic_id' => null]);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertDontSee($mainVisit->visit_number);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.show', $mainVisit))
        ->assertForbidden();
});

// ─── Patient helpers + legacy preview (no backfill) ──────────────────────────

it('labels a patient branch, falling back to a legacy marker', function () {
    $assigned = Patient::factory()->create(['branch_id' => $this->atg3->id]);
    $legacy = Patient::factory()->create(['branch_id' => null]);

    expect($assigned->branchLabel())->toBe('ATG3 — Cabang Antang')
        ->and($legacy->branchLabel())->toBe('Legacy / belum ada cabang pasien')
        ->and($legacy->isLegacyWithoutBranch())->toBeTrue();
});

it('previews legacy patients without a branch without mutating them', function () {
    $legacy = Patient::factory()->create(['branch_id' => null, 'name' => 'Tanpa Cabang']);
    Patient::factory()->create(['branch_id' => $this->atg3->id, 'name' => 'Punya Cabang']);

    $preview = app(PatientService::class)->legacyWithoutBranch();

    expect($preview)->toHaveCount(1)
        ->and($preview->first()->name)->toBe('Tanpa Cabang')
        // Read-only: branch_id is untouched.
        ->and($legacy->fresh()->branch_id)->toBeNull();
});

it('does not consult the BranchContext fallback for the visit list scope', function () {
    // Even if the fallback resolves to MAIN, RME visits at RME branches are shown.
    expect(app(BranchContext::class)->id())->toBe($this->main->id);

    pilotVisit($this->atg3, ['patient_id' => Patient::factory()->create(['name' => 'Scope Proof'])->id]);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Scope Proof');
});
