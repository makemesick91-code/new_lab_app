<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;

/**
 * Sprint 63.1.1 — Odontogram Saved Result Table Preview.
 *
 * After saving an odontogram, the lower preview area must show BOTH the FDI
 * visual (Peta Gigi & Jumlah DMF-T) AND a read-only saved-result table with the
 * Daengtisia columns GIGI | DIAGNOSA | PERAWATAN | DOKTER. The table reuses the
 * Sprint 63.1 OdontogramPrintFormatter row-building logic so the screen read-back
 * matches the print/PDF. Presentation-only: no mutation, no migration, no KTP/NIK.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

function savedTablePreviewBranchId(): int
{
    return Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id;
}

// --- 1: visual preview still renders ---

it('odontogram show page renders the visual preview section', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => savedTablePreviewBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Peta Gigi (FDI) & Jumlah DMF-T')
        ->assertSee('KANAN / RIGHT')
        ->assertSee('Jumlah DMF-T');
});

// --- 2: saved result table section renders below/near the preview ---

it('odontogram show page renders the saved result table section', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => savedTablePreviewBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram Tersimpan');
});

// --- 3: the saved result table exposes the Daengtisia columns ---

it('saved result table includes the GIGI / DIAGNOSA / PERAWATAN / DOKTER headers', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => savedTablePreviewBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('GIGI')
        ->assertSee('DIAGNOSA')
        ->assertSee('PERAWATAN')
        ->assertSee('DOKTER');
});

// --- 4: saved teeth + per-row values appear in the table ---

it('saved teeth appear in the saved result table with diagnosis and treatment', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => savedTablePreviewBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => [
            'teeth' => [
                '17' => ['status' => 'caries'],
                '26' => ['status' => 'caries', 'additional_note' => 'PerawatanTersimpanRow', 'dokter' => 'drg. SavedTable'],
                '28' => ['status' => 'caries'],
            ],
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('17')
        ->assertSee('26')
        ->assertSee('28')
        ->assertSee('Karies')
        ->assertSee('PerawatanTersimpanRow')
        ->assertSee('drg. SavedTable');
});

// --- 5: KTP / NIK is never rendered on the show page ---

it('saved result table preview never renders the patient KTP/NIK', function () {
    $manager = userWith(['manage_clinic_visits']);
    $patient = Patient::factory()->create(['ktp_number' => '7371091234560001']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => savedTablePreviewBranchId(),
        'patient_id' => $patient->id,
    ]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram Tersimpan')
        ->assertDontSee('7371091234560001');
});

// --- 6: rendering the show page mutates nothing ---

it('rendering the saved result table preview does not mutate odontogram data', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => savedTablePreviewBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries', 'additional_note' => 'Imutable']]],
    ]);

    $before = $odontogram->only(['tooth_map_payload', 'status', 'updated_at']);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();

    $fresh = $odontogram->fresh();
    expect($fresh->tooth_map_payload)->toBe($before['tooth_map_payload'])
        ->and($fresh->status)->toBe($before['status'])
        ->and($fresh->updated_at->eq($before['updated_at']))->toBeTrue();
});

// --- 7: empty odontogram shows a safe empty state for the saved table ---

it('saved result table shows a safe empty state when nothing is selected', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => savedTablePreviewBranchId()]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram Tersimpan')
        ->assertSee('Tabel hasil tersimpan akan muncul otomatis');
});
