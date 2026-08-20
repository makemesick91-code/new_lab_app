<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 — FIX-02, FIX-03 and FIX-04.
 *
 * FIX-02  Medical Record page information hierarchy:
 *           Informasi Kunjungan -> RME Tulisan Tangan -> Dokumen RME Pasien.
 * FIX-03  "Finalisasi" moved to the top of the page (MOVED, not duplicated).
 * FIX-04  "Cetak RME" moved here from Detail Kunjungan, reusing the existing
 *         print route and its existing server-side authorisation.
 *
 * These are placement/authorisation contracts. No RME finalization semantics,
 * handwriting requirement, room gate or print backend behaviour is changed by
 * this sprint, and the assertions below deliberately re-prove that.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

function mrPageVisit(array $recordAttrs = []): ClinicVisit
{
    $visit = ClinicVisit::factory()->create(['branch_id' => test()->branch->id]);

    MedicalRecord::factory()->create(array_merge([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ], $recordAttrs));

    return $visit;
}

function mrPageWithHandwriting(array $recordAttrs = []): ClinicVisit
{
    Storage::fake('public');

    $visit = mrPageVisit($recordAttrs);
    $record = MedicalRecord::where('clinic_visit_id', $visit->id)->firstOrFail();

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
    ]);

    return $visit;
}

// ── FIX-02: information hierarchy ───────────────────────────────────────────

it('orders the medical record page as visit info, handwritten RME, then documents', function () {
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = mrPageVisit();

    $html = $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    $visitInfo = strpos($html, 'Informasi Kunjungan');
    $handwriting = strpos($html, 'RME Tulisan Tangan');
    $documents = strpos($html, 'Dokumen RME Pasien');

    expect($visitInfo)->not->toBeFalse()
        ->and($handwriting)->not->toBeFalse()
        ->and($documents)->not->toBeFalse();

    expect($visitInfo)->toBeLessThan($handwriting);
    expect($handwriting)->toBeLessThan($documents);
});

it('keeps the patient RME documents rail on the page after moving it down', function () {
    // FIX-02 is placement only — the rail must still be rendered, not dropped.
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = mrPageVisit();

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Dokumen RME Pasien');
});

// ── FIX-03: Finalisasi relocation ───────────────────────────────────────────

it('renders Finalisasi above the handwritten RME workspace', function () {
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = mrPageWithHandwriting(['status' => MedicalRecord::STATUS_DRAFT]);

    $html = $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    $finalize = strpos($html, 'Finalisasi');
    $handwriting = strpos($html, 'RME Tulisan Tangan');

    expect($finalize)->not->toBeFalse();
    expect($finalize)->toBeLessThan($handwriting);
});

it('renders exactly one Finalisasi control — moved, not duplicated', function () {
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = mrPageWithHandwriting(['status' => MedicalRecord::STATUS_DRAFT]);
    $record = MedicalRecord::where('clinic_visit_id', $visit->id)->firstOrFail();

    $html = $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, route('rme.visits.medical-record.finalize', [$visit, $record])))->toBe(1);
    // x-ui.button renders its label on its own line, so count the label itself.
    expect(substr_count($html, 'Finalisasi'))->toBe(1);
});

it('still requires handwriting before offering Finalisasi', function () {
    // The Sprint 20 rule is unchanged: no handwriting PNG, no finalization.
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = mrPageVisit(['status' => MedicalRecord::STATUS_DRAFT]);
    $record = MedicalRecord::where('clinic_visit_id', $visit->id)->firstOrFail();

    $html = $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('RME belum dapat difinalkan karena catatan tulis tangan dokter belum tersedia.')
        ->getContent();

    // The submit form is absent; only the disabled affordance is rendered.
    expect($html)->not->toContain(route('rme.visits.medical-record.finalize', [$visit, $record]));
});

it('does not offer Finalisasi to a user who cannot finalize', function () {
    $viewer = userWith(['view_clinic_visits']);
    $visit = mrPageWithHandwriting(['status' => MedicalRecord::STATUS_DRAFT]);
    $record = MedicalRecord::where('clinic_visit_id', $visit->id)->firstOrFail();

    $html = $this->actingAs($viewer)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(route('rme.visits.medical-record.finalize', [$visit, $record]));
    expect($html)->not->toContain('>Finalisasi');
});

it('shows the finalized state message instead of the action once final', function () {
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = mrPageWithHandwriting([
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Rekam Medis ini telah difinalkan.');
});

// ── FIX-04: Cetak RME relocation ────────────────────────────────────────────

it('renders Cetak RME on the medical record page for an authorized user', function () {
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = mrPageVisit();

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Cetak RME')
        ->assertSee(route('rme.visits.print', $visit));
});

it('renders Cetak RME above the handwritten RME workspace', function () {
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = mrPageVisit();

    $html = $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    expect(strpos($html, 'Cetak RME'))->toBeLessThan(strpos($html, 'RME Tulisan Tangan'));
});

it('reuses the existing print route rather than a new endpoint', function () {
    // FIX-04 relocates a button. It must not introduce a second print backend.
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $visit = mrPageVisit();

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertViewIs('rme.visits.print');
});

it('denies the medical record page to a user without RME clinic-visit authority', function () {
    // Relocating the button changes no authorisation: a user who holds no
    // clinic-visit ability cannot reach the page that now carries Cetak RME.
    $outsider = userWith(['manage_rme_billing']);
    $visit = mrPageVisit();

    $this->actingAs($outsider)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertForbidden();
});

it('still denies the print route itself to an unauthorized user', function () {
    // The server-side gate is the boundary, not the button. Moving the button
    // must not become a way to reach the print backend.
    $outsider = userWith(['manage_rme_billing']);
    $visit = mrPageVisit();

    $this->actingAs($outsider)
        ->get(route('rme.visits.print', $visit))
        ->assertForbidden();
});
