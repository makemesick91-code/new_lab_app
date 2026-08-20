<?php

// Sprint 64.0 — Patient-Centric RM Workspace with Swipeable Sheets.
// One pasien = one buku RM; one buku RM = many sheets (one per visit). Any
// visit's RM URL resolves+redirects to the patient's canonical workspace while
// preserving visit data, counts, gates, and privacy.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);
    $this->manager = userWith(['manage_clinic_visits', 'complete_rme_examination']);
    $this->viewer = userWith(['view_clinic_visits']);
});

/** Create a visit (default registered, roomed) for a patient. */
function rmwsVisit(Branch $branch, Patient $patient, string $date, string $status = ClinicVisit::STATUS_REGISTERED): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'visit_date' => $date,
        'status' => $status,
    ]);
}

/** Attach a draft medical record (sheet) to a visit. */
function rmwsSheet(Branch $branch, ClinicVisit $visit): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
}

// 1 — Visit 1 opens the canonical RM workspace.
it('opens the canonical workspace for the first visit', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    rmwsSheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertSee('Buku RM Pasien');
});

// 2 + 3 — Visit 2 redirects to the canonical workspace carrying source_visit_id.
it('redirects a later visit to the canonical workspace with source_visit_id', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString());
    rmwsSheet($this->branch, $visit1);
    rmwsSheet($this->branch, $visit2);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit2))
        ->assertRedirect(route('rme.visits.medical-record.show', [$visit1, 'source_visit_id' => $visit2->id]));
});

// 4 — A source_visit_id from another patient is ignored (IDOR boundary).
it('ignores a source_visit_id belonging to another patient', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    rmwsSheet($this->branch, $visit1);

    $other = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $otherVisit = rmwsVisit($this->branch, $other, now()->subDays(3)->toDateString());
    rmwsSheet($this->branch, $otherVisit);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', [$visit1, 'source_visit_id' => $otherVisit->id]))
        ->assertOk()
        ->assertDontSee('membuka RM pasien dari Kunjungan #'.$otherVisit->visit_number);
});

// 5 + 6 — Add sheet from Visit 2 creates a record linked to Visit 2 with audit columns.
it('creates a new sheet linked to the source visit with workspace audit columns', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString());
    rmwsSheet($this->branch, $visit1); // canonical anchor

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.store', $visit2))
        ->assertRedirect();

    $sheet = MedicalRecord::where('clinic_visit_id', $visit2->id)->firstOrFail();

    expect($sheet->source_visit_id)->toBe($visit2->id)
        ->and($sheet->canonical_visit_id)->toBe($visit1->id)
        ->and($sheet->sheet_number)->toBe(2);
});

// 7 — A legacy sheet (null workspace columns) is lazily stamped on update, no duplicate.
it('lazily stamps workspace columns on an existing sheet without duplication', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    $sheet = rmwsSheet($this->branch, $visit1);

    // Factory bypasses the service, so the workspace columns start null (legacy).
    expect($sheet->canonical_visit_id)->toBeNull();

    $this->actingAs($this->manager)
        ->patch(route('rme.visits.medical-record.update', [$visit1, $sheet]), ['notes' => 'revisi'])
        ->assertRedirect();

    $sheet->refresh();

    expect($sheet->source_visit_id)->toBe($visit1->id)
        ->and($sheet->canonical_visit_id)->toBe($visit1->id)
        ->and($sheet->sheet_number)->toBe(1)
        ->and(MedicalRecord::where('clinic_visit_id', $visit1->id)->count())->toBe(1);
});

// 8 — Creating a sheet does not change the visit count.
it('does not change the visit count when adding a sheet', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString());
    rmwsSheet($this->branch, $visit1);

    $visitsBefore = ClinicVisit::count();

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.store', $visit2));

    expect(ClinicVisit::count())->toBe($visitsBefore);
});

// 9 — Visit reporting counts visits, not sheets.
it('counts visits not sheets for a patient', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString());
    rmwsSheet($this->branch, $visit1);
    rmwsSheet($this->branch, $visit2);

    // Two visits, two sheets — visit-level reporting must remain two.
    expect(ClinicVisit::where('patient_id', $patient->id)->count())->toBe(2)
        ->and(MedicalRecord::where('patient_id', $patient->id)->count())->toBe(2);
});

// 10 — Swipe / tabs / prev-next controls are rendered.
it('renders sheet swipe, tabs and prev-next controls', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString());
    rmwsSheet($this->branch, $visit1);
    rmwsSheet($this->branch, $visit2);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertSee('Buku RM Pasien')
        ->assertSee('Lembar Berikutnya')
        ->assertSee('Lembar Sebelumnya')
        ->assertSee('Lembar 1')
        ->assertSee('Lembar 2')
        ->assertSee('touchend', false);
});

// 11 — Refresh persistence keys + active-sheet URL param exist.
it('exposes refresh persistence keys and the sheet url param', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString());
    rmwsSheet($this->branch, $visit1);
    rmwsSheet($this->branch, $visit2);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertSee('rmws:', false)
        ->assertSee('sessionStorage', false)
        ->assertSee('sheet=', false);
});

// 12 — Authorization: no-perm user blocked; viewer cannot create sheets.
it('blocks an unauthorized user from the workspace', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    rmwsSheet($this->branch, $visit1);

    $this->actingAs(userWith([]))
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertForbidden();
});

it('forbids a view-only user from creating a sheet', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    rmwsSheet($this->branch, $visit1);
    $visit2 = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString());

    // Viewer can read the workspace but the add-sheet control is hidden...
    $this->actingAs($this->viewer)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertDontSee('Tambah Lembar RM');

    // ...and the create endpoint is forbidden server-side.
    $this->actingAs($this->viewer)
        ->post(route('rme.visits.medical-record.store', $visit2))
        ->assertForbidden();
});

// 13 — Sheets in a non-RME branch are never exposed.
it('does not expose sheets from a non-RME branch', function () {
    $main = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $patient = Patient::factory()->create(['branch_id' => $main->id]);
    $mainVisit = rmwsVisit($main, $patient, now()->subDays(5)->toDateString());
    rmwsSheet($main, $mainVisit);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $mainVisit))
        ->assertNotFound();
});

// 14 — A cancelled first visit is not canonical; the next active visit is.
it('skips a cancelled first visit when choosing the canonical anchor', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $cancelled = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString(), ClinicVisit::STATUS_CANCELLED);
    $active = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString());
    rmwsSheet($this->branch, $cancelled);
    rmwsSheet($this->branch, $active);

    // The active visit is canonical — it renders directly.
    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $active))
        ->assertOk()
        ->assertSee('(batal)'); // the cancelled sheet is still listed, flagged.

    // The cancelled first visit redirects to the active canonical workspace.
    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $cancelled))
        ->assertRedirect(route('rme.visits.medical-record.show', [$active, 'source_visit_id' => $cancelled->id]));
});

// 15 — KTP/NIK is never rendered.
it('never renders the patient KTP number', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'ktp_number' => '1234567890123456',
    ]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    rmwsSheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertDontSee('1234567890123456');
});

// 16 — The within-sheet canvas page param still works.
it('still supports the rm_page canvas param on the active sheet', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    rmwsSheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', [$visit1, 'rm_page' => 1]))
        ->assertOk()
        ->assertSee('Halaman 1');
});

// 17 — The per-visit print route is unchanged.
it('keeps the per-visit print route working', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    rmwsSheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit1))
        ->assertOk();
});

// 18 — Finalize transitions ONLY the source sheet's own visit to cashier_pending.
it('finalize transitions only the active sheet visit to cashier_pending', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString(), ClinicVisit::STATUS_IN_PROGRESS);
    $visit2 = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString(), ClinicVisit::STATUS_IN_PROGRESS);
    $sheet1 = rmwsSheet($this->branch, $visit1);
    $sheet2 = rmwsSheet($this->branch, $visit2);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $sheet2->id,
        'clinic_visit_id' => $visit2->id,
        'branch_id' => $visit2->branch_id,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.finalize', [$visit2, $sheet2]))
        ->assertRedirect();

    expect($visit2->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and($visit1->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

// --- Sprint 64.0 zero-MR fix ---------------------------------------------

// 19 — A patient with visits but zero MRs renders the empty-state workspace,
// not a 404.
it('renders the empty-state workspace for a patient with zero medical records', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertSee('Belum ada halaman RM tulisan tangan untuk pasien ini.');
});

// 20 — The empty workspace shows the create button for an edit-capable user.
it('shows the create-first-sheet button to an edit-capable user', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertSee('Buat Halaman RM Pertama');
});

// 21 — Creating the first sheet from the empty workspace stamps the audit
// columns on the canonical visit (sheet_number 1).
it('creates the first sheet from the empty workspace with workspace audit columns', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.store', $visit1))
        ->assertRedirect();

    $sheet = MedicalRecord::where('clinic_visit_id', $visit1->id)->firstOrFail();

    expect($sheet->source_visit_id)->toBe($visit1->id)
        ->and($sheet->canonical_visit_id)->toBe($visit1->id)
        ->and($sheet->sheet_number)->toBe(1)
        ->and(MedicalRecord::where('patient_id', $patient->id)->count())->toBe(1);
});

// 22 — A view-only user sees the empty state but no create button, and the
// create endpoint is forbidden server-side.
it('shows the empty state without a create button to a view-only user', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.medical-record.show', $visit1))
        ->assertOk()
        ->assertSee('Belum ada halaman RM tulisan tangan untuk pasien ini.')
        ->assertDontSee('Buat Halaman RM Pertama');

    $this->actingAs($this->viewer)
        ->post(route('rme.visits.medical-record.store', $visit1))
        ->assertForbidden();
});

// 23 — Opening RM from visit 2 (zero MRs) redirects to canonical visit 1 with
// source_visit_id=visit2, and the first sheet is created on the canonical visit.
it('redirects a zero-MR later visit to canonical then creates the first sheet on canonical', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    $visit2 = rmwsVisit($this->branch, $patient, now()->subDays(2)->toDateString());

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit2))
        ->assertRedirect(route('rme.visits.medical-record.show', [$visit1, 'source_visit_id' => $visit2->id]));

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.store', $visit2))
        ->assertRedirect();

    $sheet = MedicalRecord::where('clinic_visit_id', $visit1->id)->firstOrFail();

    expect($sheet->source_visit_id)->toBe($visit2->id)
        ->and($sheet->canonical_visit_id)->toBe($visit1->id)
        ->and($sheet->sheet_number)->toBe(1)
        ->and(MedicalRecord::where('patient_id', $patient->id)->count())->toBe(1)
        ->and(MedicalRecord::where('clinic_visit_id', $visit2->id)->exists())->toBeFalse();
});

// 24 — Re-posting store for a visit that already has a sheet does not create a
// duplicate (UNIQUE(clinic_visit_id) preserved; idempotent activate).
it('does not create a duplicate sheet when the source visit already has one', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit1 = rmwsVisit($this->branch, $patient, now()->subDays(10)->toDateString());
    rmwsSheet($this->branch, $visit1);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.store', $visit1))
        ->assertRedirect();

    expect(MedicalRecord::where('clinic_visit_id', $visit1->id)->count())->toBe(1)
        ->and(MedicalRecord::where('patient_id', $patient->id)->count())->toBe(1);
});
