<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use Database\Seeders\BranchSeeder;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-05 — print ownership separation.
 *
 * SUPERSEDES Sprint 23 Phase 23.10.6 (MedicalRecordPrintOdontogramMergeTest),
 * which required the combined visit print to embed the odontogram selected-results
 * table "so the user does not need to open the separate Cetak Odontogram".
 *
 * The product decision is now the opposite, and it is deliberate:
 *
 *   Cetak RME        -> medical record content only, NO odontogram
 *   Cetak Odontogram -> the odontogram, standalone and unchanged
 *
 * This is a print COMPOSITION change only. The odontogram model, table, clinical
 * workflow, editor UI and standalone print are all untouched — this file proves
 * both halves of that statement: the odontogram is absent from the RME print AND
 * still fully rendered by its own print route.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

function mainRmeBranchIdForSeparation(): int
{
    return Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id;
}

function visitWithOdontogramForSeparation(array $teeth, array $odontogramAttrs = []): ClinicVisit
{
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchIdForSeparation()]);

    Odontogram::factory()->create(array_merge([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => $teeth],
    ], $odontogramAttrs));

    return $visit;
}

// --- 1–5: the odontogram is absent from the RME print composition ---

it('rme print does not render the odontogram section when an odontogram exists', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogramForSeparation(['11' => ['status' => 'caries']]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertViewIs('rme.visits.print')
        ->assertDontSee('Hasil Odontogram yang Dipilih')
        ->assertDontSee('Kondisi Tambahan')
        ->assertDontSee('Catatan Odontogram');
});

it('rme print does not render odontogram tooth rows, conditions or DMF-T', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogramForSeparation([
        '11' => [
            'status' => 'caries',
            'additional_condition' => 'SEPARATION_CONDITION_MARKER',
            'additional_note' => 'SEPARATION_NOTE_MARKER',
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertDontSee('SEPARATION_CONDITION_MARKER')
        ->assertDontSee('SEPARATION_NOTE_MARKER')
        ->assertDontSee('DMF-T')
        ->assertDontSee('DIAGNOSA')
        ->assertDontSee('PERAWATAN');
});

it('rme print no longer renders the odontogram empty-state placeholder', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchIdForSeparation()]);

    // The section is gone entirely, so a visit without an odontogram no longer
    // advertises a missing odontogram on the medical record print.
    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertDontSee('Belum ada data odontogram.');
});

it('rme pdf export does not render the odontogram section either', function () {
    if (! pdfToTextAvailable()) {
        test()->markTestSkipped('poppler-utils (pdftotext) is not installed.');
    }

    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogramForSeparation([
        '11' => ['status' => 'caries', 'additional_note' => 'SEPARATION_PDF_MARKER'],
    ]);
    $visit->load('patient');

    /*
     * FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1 — pin the name instead of taking
     * whatever faker produced. A name long enough to overflow the fixed-width
     * "Nama Pasien" cell wraps in `pdftotext -layout`, and the old contiguous
     * comparison below failed on it even though the document was correct.
     */
    $visit->patient->forceFill(['name' => 'Alexandria Catherine Santoso'])->save();
    $visit->refresh()->load('patient');

    $response = $this->actingAs($manager)->get(route('rme.visits.pdf', $visit));
    $response->assertOk();

    $raw = pdfExtractText($response->getContent());

    // Section titles are CSS-uppercased, so compare case-insensitively.
    $text = strtoupper($raw);

    // A bare "does not contain" on a PDF is vacuous unless extraction demonstrably
    // works, so first prove the extractor really reads this document: the patient's
    // own name and the medical-record section must both come back. The name is
    // read out of its own layout cell, so a wrap does not break the proof.
    expect(pdfLayoutFieldValue($raw, 'Nama Pasien'))->toBe($visit->patient->name);
    expect($text)->toContain('DATA PASIEN');
    expect($text)->toContain('REKAM MEDIS');

    expect($text)->not->toContain('SEPARATION_PDF_MARKER');
    expect($text)->not->toContain(strtoupper('Hasil Odontogram yang Dipilih'));
});

it('rme print still renders its own medical record content', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogramForSeparation(['11' => ['status' => 'caries']]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Data Pasien')
        ->assertSee('Rekam Medis');
});

// --- 6–9: the standalone odontogram print is untouched ---

it('standalone odontogram print still renders the selected-results table', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchIdForSeparation()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries', 'additional_note' => 'SeparatePrintNote']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih')
        ->assertSee('SeparatePrintNote');
});

it('standalone odontogram print still renders the condition label and DMF-T', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchIdForSeparation()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Karies')
        ->assertSee('DMF-T');
});

it('standalone odontogram print still hides the raw payload', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchIdForSeparation()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries', 'additional_condition' => 'X']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertDontSee('tooth_map_payload')
        ->assertDontSee('"status":');
});

it('standalone odontogram print still renders a legacy payload without extra keys', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchIdForSeparation()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih')
        ->assertSee('Karies');
});

// --- 10: the RME print still refuses to leak the raw payload ---

it('rme print does not show raw tooth_map_payload JSON', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogramForSeparation([
        '11' => ['status' => 'caries', 'additional_condition' => 'X', 'additional_note' => 'Y'],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertDontSee('tooth_map_payload')
        ->assertDontSee('"status":');
});
