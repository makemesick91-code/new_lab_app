<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use Database\Seeders\BranchSeeder;

/**
 * Sprint 23 Phase 23.10.6 — Merge Odontogram Selected Results into
 * Cetak Rekam Medis (combined medical record print).
 *
 * The combined visit print bundle (rme.visits.print) is the main printed
 * medical record output and must contain the odontogram selected-results
 * table so the user does not need to open the separate Cetak Odontogram.
 * Base commit: e48c645 (Phase 23.10.5 cashier branch scoping + clinical summary).
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

function mainRmeBranchIdForMerge(): int
{
    return Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id;
}

function visitWithOdontogram(array $teeth, array $odontogramAttrs = []): ClinicVisit
{
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchIdForMerge()]);

    Odontogram::factory()->create(array_merge([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => $teeth],
    ], $odontogramAttrs));

    return $visit;
}

// --- 1: odontogram section present in the combined medical record print ---

it('medical record print includes an Odontogram section when odontogram exists', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogram(['11' => ['status' => 'caries']]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertViewIs('rme.visits.print')
        ->assertSee('Odontogram');
});

// --- 2: selected results subsection title ---

it('medical record print includes Hasil Odontogram yang Dipilih', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogram(['11' => ['status' => 'caries']]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih');
});

// --- 3: selected tooth / area ---

it('medical record print shows the selected tooth/area', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogram(['36' => ['status' => 'missing']]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('36');
});

// --- 4: selected odontogram condition (mapped label) ---

it('medical record print shows the selected odontogram condition label', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogram(['11' => ['status' => 'caries']]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Karies');
});

// --- 5: per-row DIAGNOSA detail (additional_condition) ---

it('medical record print shows per-row DIAGNOSA detail', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogram([
        '11' => ['status' => 'caries', 'additional_condition' => 'TandaKlinisRow11'],
    ]);

    // Hotfix Sprint 60.3 — Daengtisia columns; additional_condition renders in DIAGNOSA.
    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('DIAGNOSA')
        ->assertSee('TandaKlinisRow11');
});

// --- 6: per-row PERAWATAN (additional_note) + legacy note preserved ---

it('medical record print shows per-row PERAWATAN and preserves legacy note', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogram([
        '11' => ['status' => 'caries', 'note' => 'CatatanGigiRow11', 'additional_note' => 'CatatanTambahanRow11'],
    ]);

    // additional_note renders in PERAWATAN; legacy note is preserved in DIAGNOSA detail.
    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('PERAWATAN')
        ->assertSee('CatatanGigiRow11')
        ->assertSee('CatatanTambahanRow11');
});

// --- 7: never leak raw payload JSON ---

it('medical record print does not show raw tooth_map_payload JSON', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogram([
        '11' => ['status' => 'caries', 'additional_condition' => 'X', 'additional_note' => 'Y'],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertDontSee('tooth_map_payload')
        ->assertDontSee('"status":');
});

// --- 8: no odontogram handled safely ---

it('medical record print handles a visit without odontogram safely', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchIdForMerge()]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Belum ada data odontogram.');
});

// --- 9: legacy payload without per-row additional keys renders safely ---

it('medical record print handles old payload without additional keys safely', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogram(['11' => ['status' => 'caries']], [
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih')
        ->assertSee('Karies')
        ->assertSee('—');
});

// --- 10: the separate odontogram print still works ---

it('separate odontogram print still works alongside the merged medical record print', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchIdForMerge()]);
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

// --- 11: combined output keeps patient + clinical summary sections (Phase 23.10.5 safety) ---

it('medical record print keeps patient and clinical summary sections intact', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = visitWithOdontogram(['11' => ['status' => 'caries']]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Data Pasien')
        ->assertSee('Rekam Medis')
        ->assertSee('Odontogram');
});
