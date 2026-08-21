<?php

// Sprint 59 — Editable RME & Table-Based Odontogram Workflow.
// Covers: prev/next visit navigation (RM + Odontogram), table-first odontogram
// editor controls, and manual (free-text) billing treatment input.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);
    $this->manager = userWith(['manage_clinic_visits']);
    $this->cashier = userWith(['manage_rme_billing']);
});

/**
 * Create $count visits for one patient with ascending visit dates, each carrying
 * a draft medical record so RM navigation has valid targets.
 *
 * @return array{0: Patient, 1: array<int, ClinicVisit>}
 */
function makePatientVisitSeries(Branch $branch, int $count = 3): array
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id]);
    $visits = [];

    for ($i = 0; $i < $count; $i++) {
        $visit = ClinicVisit::factory()->create([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'visit_date' => now()->subDays(($count - $i) * 5)->toDateString(),
        ]);
        MedicalRecord::factory()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $visit->doctor_id,
        ]);
        $visits[] = $visit;
    }

    return [$patient, $visits];
}

// ─── Navigation: Medical Record page ─────────────────────────────────────────
// Sprint 64.0 superseded the Sprint 59 per-visit prev/next arrows with the
// patient-centric RM workspace: any later visit redirects to the canonical
// (earliest) visit's workspace, where sheets are swiped instead of visits.

it('RM page redirects a later visit to the canonical patient workspace', function () {
    [$patient, $visits] = makePatientVisitSeries($this->branch);
    [$older, $middle, $newer] = $visits;

    // Later visits bounce to the earliest visit's workspace, carrying source.
    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $middle))
        ->assertRedirect(route('rme.visits.medical-record.show', [$older, 'source_visit_id' => $middle->id]));

    // The canonical visit renders the one-book workspace with every sheet.
    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $older))
        ->assertOk()
        ->assertSee('Buku RM Pasien')
        ->assertSee('Lembar 1')
        ->assertSee('Lembar 3');
});

it('RM workspace disables the previous-sheet control on the first sheet', function () {
    [$patient, $visits] = makePatientVisitSeries($this->branch);
    [$older] = $visits;

    // On the first sheet, "Lembar Sebelumnya" is disabled but "Berikutnya" works.
    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $older))
        ->assertOk()
        ->assertSee('aria-disabled="true"', false)
        ->assertSee('Lembar Berikutnya');
});

it('RM workspace never shows another patient sheets', function () {
    [$patientA, $visitsA] = makePatientVisitSeries($this->branch);
    [$patientB, $visitsB] = makePatientVisitSeries($this->branch);

    $response = $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visitsA[0]))
        ->assertOk();

    foreach ($visitsB as $visitB) {
        $response->assertDontSee($visitB->visit_number);
    }
});

// ─── Navigation: Odontogram page ─────────────────────────────────────────────

it('Odontogram page shows prev/next links to adjacent visits of the same patient', function () {
    [$patient, $visits] = makePatientVisitSeries($this->branch);
    [$older, $middle, $newer] = $visits;

    $this->actingAs($this->manager)
        ->get(route('rme.visits.odontogram.show', $middle))
        ->assertOk()
        ->assertSee('Kunjungan Sebelumnya')
        ->assertSee('Kunjungan Berikutnya')
        ->assertSee(route('rme.visits.odontogram.show', $older), false)
        ->assertSee(route('rme.visits.odontogram.show', $newer), false);
});

// ─── Table-first odontogram editor ───────────────────────────────────────────

it('odontogram page renders the table-only Daengtisia input for a manager (Hotfix Sprint 60.3)', function () {
    // CORRECTIVE-03 — the editor renders on a consented live encounter. The page
    // itself still opens without consent; only the input controls are withheld,
    // which RmeExamConsentCorrective3Test pins separately.
    $visit = rmeConsentedOdontogramVisit(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih')
        // Daengtisia columns drive all input (GIGI / DIAGNOSA / PERAWATAN / DOKTER).
        ->assertSee('GIGI')
        ->assertSee('DIAGNOSA')
        ->assertSee('PERAWATAN')
        ->assertSee('DOKTER')
        // Doctor adds rows per tooth number; status edits go through the editor.
        ->assertSee('Tambah Baris')
        ->assertSee('setStatus', false)
        // The FDI visual is a generated output section (shown once data is saved).
        ->assertSee('Peta Gigi (FDI)')
        // No interactive drawing/image input.
        ->assertDontSee('<canvas', false);
});

// ─── Manual billing treatment ────────────────────────────────────────────────

it('cashier can create an invoice with a manual treatment (no treatment_id)', function () {
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $this->branch->id]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.store', $visit), [
            'items' => [[
                'treatment_id' => null,
                'description' => 'Tindakan Manual Khusus',
                'qty' => 1,
                'unit_price' => 250000,
                'discount' => 0,
            ]],
        ])
        ->assertRedirect();

    $invoice = RmeInvoice::where('clinic_visit_id', $visit->id)->firstOrFail();
    $item = $invoice->items()->firstOrFail();

    expect($item->treatment_id)->toBeNull()
        ->and($item->description)->toBe('Tindakan Manual Khusus');

    // Manual treatment appears on the invoice detail page.
    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Tindakan Manual Khusus');
});

it('billing create page exposes the manual treatment hint and optional treatment dropdown', function () {
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $this->branch->id]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.create', $visit))
        ->assertOk()
        ->assertSee('Tindakan / Deskripsi')
        ->assertSee('Manual / Pilih Treatment');
});
