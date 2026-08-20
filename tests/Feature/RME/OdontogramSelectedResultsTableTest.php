<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use Database\Seeders\BranchSeeder;

/**
 * Sprint 23 Phase 23.10.4 — Odontogram Selected Results Table Notes Fix.
 *
 * Corrects Phase 23.10.2: instead of global "Kondisi Tambahan" / "Catatan
 * Odontogram" textareas, each selected odontogram result (a tooth carrying a
 * status) renders as a table row with per-row "Kondisi Tambahan"
 * (additional_condition) and "Catatan Tambahan" (additional_note). These live
 * inside tooth_map_payload.teeth.<num> and survive save + finalization.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

function mainRmeBranchId(): int
{
    return Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id;
}

// --- 1: section heading ---

it('odontogram edit page shows the selected results table section', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih');
});

// --- 2: empty state ---

it('shows empty-state when no odontogram condition is selected', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Belum ada kondisi odontogram yang dipilih.');
});

// --- 3, 4, 5: existing selected payload renders as table rows (read-only/finalized) ---

it('renders existing selected odontogram payload as table rows', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
        'tooth_map_payload' => [
            'teeth' => [
                '11' => ['status' => 'caries'],
                '36' => ['status' => 'missing'],
            ],
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih')
        ->assertSee('11')
        ->assertSee('36');
});

it('selected results table row shows the tooth area', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('11');
});

it('selected results table row shows the selected odontogram condition', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Karies');
});

// --- 6, 7: draft row shows per-row inputs ---

it('draft selected results table exposes a DIAGNOSA input column', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    // Hotfix Sprint 60.3 — Daengtisia columns; DIAGNOSA detail reuses additional_condition.
    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('DIAGNOSA')
        ->assertSee('additional_condition', false);
});

it('draft selected results table exposes a PERAWATAN input column', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    // Hotfix Sprint 60.3 — PERAWATAN column reuses additional_note.
    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('PERAWATAN')
        ->assertSee('additional_note', false);
});

// --- 8, 9: saving draft persists per-row values ---

it('saving draft persists per-row additional_condition', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'additional_condition' => 'Karies profunda']],
            ]),
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['additional_condition'])
        ->toBe('Karies profunda');
});

it('saving draft persists per-row additional_note', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'additional_note' => 'Rencana tambal komposit']],
            ]),
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['additional_note'])
        ->toBe('Rencana tambal komposit');
});

// --- 10, 11: saved per-row values displayed after save ---

it('saved per-row additional_condition is present after save', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'additional_condition' => 'KondisiTambahanTersimpan']],
            ]),
        ])
        ->assertRedirect();

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('KondisiTambahanTersimpan');
});

it('saved per-row additional_note is present after save', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'additional_note' => 'CatatanTambahanTersimpan']],
            ]),
        ])
        ->assertRedirect();

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('CatatanTambahanTersimpan');
});

// --- 12, 13: finalization preserves per-row values ---

it('finalization does not clear per-row additional_condition', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => [
            'teeth' => ['11' => ['status' => 'caries', 'additional_condition' => 'Pertahankan kondisi']],
        ],
    ]);

    $this->actingAs($manager)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertRedirect();

    $fresh = $odontogram->fresh();
    expect($fresh->status)->toBe(Odontogram::STATUS_FINALIZED)
        ->and($fresh->tooth_map_payload['teeth']['11']['additional_condition'])->toBe('Pertahankan kondisi');
});

it('finalization does not clear per-row additional_note', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => [
            'teeth' => ['11' => ['status' => 'caries', 'additional_note' => 'Pertahankan catatan']],
        ],
    ]);

    $this->actingAs($manager)
        ->post(route('rme.odontograms.finalize', $odontogram))
        ->assertRedirect();

    expect($odontogram->fresh()->tooth_map_payload['teeth']['11']['additional_note'])
        ->toBe('Pertahankan catatan');
});

// --- 14: finalized rows stay editable for a manager (Sprint 59) ---

it('finalized odontogram keeps rows editable for a manager (Sprint 59)', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
        'tooth_map_payload' => [
            'teeth' => ['11' => ['status' => 'caries', 'additional_condition' => 'FinalKondisiRow', 'additional_note' => 'FinalCatatanRow']],
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('FinalKondisiRow')
        ->assertSee('FinalCatatanRow')
        // Sprint 59 — finalized odontograms remain editable, so the table editor renders.
        ->assertSee('setAdditional', false);
});

// --- 15: print page shows the selected results table ---

it('print odontogram shows the selected results table with per-row notes', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => [
            'teeth' => ['11' => ['status' => 'caries', 'additional_condition' => 'PrintKondisiRow', 'additional_note' => 'PrintCatatanRow']],
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih')
        ->assertSee('PrintKondisiRow')
        ->assertSee('PrintCatatanRow');
});

// --- 16: visit print bundle shows the per-row notes ---

it('visit print bundle no longer shows the selected results per-row notes', function () {
    // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-05 — SUPERSEDED: the odontogram
    // selected-results table belongs to the standalone odontogram print only.
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => [
            'teeth' => ['11' => ['status' => 'caries', 'additional_condition' => 'BundleKondisiRow', 'additional_note' => 'BundleCatatanRow']],
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertDontSee('BundleKondisiRow')
        ->assertDontSee('BundleCatatanRow');
});

// --- 17: legacy payload without new keys renders safely ---

it('old payload without per-row keys still renders safely', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'finalized_by' => $manager->id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Karies')
        // Tooth 11 is present in the auto-generated FDI visual regardless of payload shape.
        ->assertSee('11');
});

// --- 18: clinic_id null visit still opens ---

it('clinic_id null visit odontogram still opens', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => mainRmeBranchId(),
        'clinic_id' => null,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('Hasil Odontogram yang Dipilih');
});

// --- 19: active RME branch visit is allowed ---

it('active RME branch visit can save per-row additional fields', function () {
    $manager = userWith(['manage_clinic_visits']);
    $rmeBranch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);
    $visit = ClinicVisit::factory()->create(['branch_id' => $rmeBranch->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $rmeBranch->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['21' => ['status' => 'crown', 'additional_condition' => 'Kondisi cabang RME']],
            ]),
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->tooth_map_payload['teeth']['21']['additional_condition'])
        ->toBe('Kondisi cabang RME');
});

// --- 20: non-RME branch remains forbidden ---

it('non-RME branch visit odontogram update remains forbidden', function () {
    $manager = userWith(['manage_clinic_visits']);
    $nonRme = Branch::factory()->create(['is_rme_enabled' => false]);
    $visit = ClinicVisit::factory()->create(['branch_id' => $nonRme->id]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $nonRme->id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => ['11' => ['status' => 'caries', 'additional_condition' => 'tidak boleh']],
            ]),
        ])
        ->assertForbidden();
});

// --- 21: tooth-grid status save still works ---

it('existing tooth-grid status save still works alongside per-row fields', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'tooth_map_payload' => json_encode([
                'teeth' => [
                    '11' => ['status' => 'caries', 'note' => 'Catatan gigi', 'conditions' => ['caries']],
                    '21' => ['status' => 'crown'],
                ],
            ]),
        ])
        ->assertRedirect();

    $fresh = $odontogram->fresh();
    expect($fresh->tooth_map_payload['teeth']['11']['status'])->toBe('caries')
        ->and($fresh->tooth_map_payload['teeth']['11']['note'])->toBe('Catatan gigi')
        ->and($fresh->tooth_map_payload['teeth']['21']['status'])->toBe('crown');
});

// --- 22: general summary_notes still saves (table-only UI hides it on show) ---

it('general summary_notes still saves and prints (removed from table-only show)', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.odontograms.update', $odontogram), [
            'summary_notes' => 'Catatan umum tersimpan.',
        ])
        ->assertRedirect();

    expect($odontogram->fresh()->summary_notes)->toBe('Catatan umum tersimpan.');

    // Sprint 60.2 — the general note is no longer shown on the table-only show page…
    $this->actingAs($manager)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertDontSee('Catatan umum tersimpan.');

    // …but it is preserved and still printed.
    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Catatan umum tersimpan.');
});

// --- 23: no raw JSON leaks into print output ---

it('no raw JSON payload appears in odontogram print output', function () {
    $manager = userWith(['manage_clinic_visits']);
    $visit = ClinicVisit::factory()->create(['branch_id' => mainRmeBranchId()]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => [
            'teeth' => ['11' => ['status' => 'caries', 'additional_condition' => 'NoJsonRow']],
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('NoJsonRow')
        ->assertDontSee('"additional_condition"', false)
        ->assertDontSee('tooth_map_payload', false);
});
