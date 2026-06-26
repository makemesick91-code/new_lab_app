<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use Database\Seeders\BranchSeeder;

/**
 * Sprint 23 Phase 23.10.2 — Odontogram Additional Conditions and Notes.
 *
 * Sprint 60.2 (table-only hotfix) update: the general "Catatan Umum Odontogram"
 * inputs (additional_conditions / summary_notes) are removed from the doctor's
 * show/input UI. The columns, validation, persistence and print output are kept
 * intact for backward compatibility, so the fields still save and still appear
 * on the printable odontogram.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

// MAIN branch is RME-enabled by default; used as the active RME branch here.

// --- 1 & 2: general "Catatan Umum Odontogram" inputs removed (table-only) ---

it('odontogram fill page no longer renders the general Catatan Umum inputs', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertDontSee('name="additional_conditions"', false)
        ->assertDontSee('name="summary_notes"', false)
        ->assertDontSee('Catatan Odontogram');
});

it('odontogram fill page renders the table-only input with two vertical tables', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih')
        ->assertSee('Rahang Atas')
        ->assertSee('Rahang Bawah');
});

// --- 3 & 4: saving persists both fields ---

it('saving odontogram persists additional conditions', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'additional_conditions' => 'Impaksi gigi 38, mobilitas gigi 31.',
        ])
        ->assertRedirect(route('rme.visits.odontogram.show', $visit));

    expect($odontogram->fresh()->additional_conditions)->toBe('Impaksi gigi 38, mobilitas gigi 31.');
});

it('saving odontogram persists odontogram notes', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => 'Catatan dokter terkait odontogram.',
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->summary_notes)->toBe('Catatan dokter terkait odontogram.');
});

it('additional_conditions over 5000 chars is rejected', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'additional_conditions' => str_repeat('x', 5001),
        ])
        ->assertSessionHasErrors('additional_conditions');
});

// --- 5 & 6: saved general fields stay available on print (removed from show) ---

it('saved additional conditions remain available on print', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'additional_conditions' => 'Kondisi jaringan lunak normal.',
    ]);

    // No longer shown on the table-only show page…
    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertDontSee('Kondisi jaringan lunak normal.');

    // …but persisted and still printed.
    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Kondisi jaringan lunak normal.');
});

it('saved odontogram notes remain available on print', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'summary_notes' => 'Catatan odontogram tersimpan.',
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertDontSee('Catatan odontogram tersimpan.');

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Catatan odontogram tersimpan.');
});

// --- 7: saved fields visible on print page ---

it('saved fields are visible on print page', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'additional_conditions' => 'Impaksi print test.',
        'summary_notes' => 'Catatan print test.',
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Impaksi print test.')
        ->assertSee('Catatan print test.');
});

// --- 8 & 9: finalization does not clear the fields ---

it('finalizing odontogram does not clear additional conditions', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'additional_conditions' => 'Karies luas gigi 16.',
    ]);

    $this->actingAs($manager)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertRedirect();

    $fresh = $odontogram->fresh();
    expect($fresh->status)->toBe(Odontogram::STATUS_FINALIZED)
        ->and($fresh->additional_conditions)->toBe('Karies luas gigi 16.');
});

it('finalizing odontogram does not clear odontogram notes', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'summary_notes' => 'Catatan dipertahankan setelah final.',
    ]);

    $this->actingAs($manager)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertRedirect();

    expect($odontogram->fresh()->summary_notes)->toBe('Catatan dipertahankan setelah final.');
});

// --- 10: finalized odontogram stays editable for a manager (Sprint 59) ---

it('finalized odontogram still exposes the editable table for a manager (Sprint 59)', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
        'additional_conditions' => 'Final kondisi tambahan.',
        'summary_notes' => 'Final catatan.',
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        // Sprint 59 — finalized odontograms remain editable, so the table editor renders…
        ->assertSee('Hasil Odontogram yang Dipilih')
        ->assertSee('setAdditional', false)
        // …Sprint 60.2 — but the removed general Catatan Umum input is gone.
        ->assertDontSee('name="additional_conditions"', false);
});

// --- 11: tooth-specific data still saves alongside new fields ---

it('saving tooth-specific data still works with additional fields', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'additional_conditions' => 'Kondisi umum.',
            'summary_notes' => 'Catatan umum.',
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'conditions' => ['caries'], 'note' => 'Gigi 11']],
            ]),
        ])
        ->assertRedirect();

    $fresh = $odontogram->fresh();
    expect($fresh->additional_conditions)->toBe('Kondisi umum.')
        ->and($fresh->summary_notes)->toBe('Catatan umum.')
        ->and($fresh->tooth_map_payload['teeth']['11']['status'])->toBe('caries');
});

// --- 12: clinic_id null visit odontogram opens and saves ---

it('clinic_id null visit odontogram still opens and saves additional fields', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id,
        'clinic_id' => null,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();

    $odontogram = Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail();

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'additional_conditions' => 'Kondisi tanpa clinic_id.',
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->additional_conditions)->toBe('Kondisi tanpa clinic_id.');
});

// --- 13: RME-enabled branch (ATG3-style) is allowed ---

it('additional RME branch visit odontogram is allowed', function () {
    $manager = userWith(['manage_clinic_visits']);
    $rmeBranch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);
    $visit = ClinicVisit::factory()->create(['branch_id' => $rmeBranch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Kondisi Tambahan');

    $odontogram = Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail();

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'additional_conditions' => 'Kondisi cabang RME.',
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->additional_conditions)->toBe('Kondisi cabang RME.');
});

// --- 14: non-RME branch visit remains forbidden ---

it('non-RME branch visit odontogram remains forbidden', function () {
    $manager = userWith(['manage_clinic_visits']);
    $nonRme = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = ClinicVisit::factory()->create(['branch_id' => $nonRme->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $nonRme->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'additional_conditions' => 'tidak boleh',
        ])
        ->assertForbidden();
});
