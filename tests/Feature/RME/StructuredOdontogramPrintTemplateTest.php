<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramPrintFormatter;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;

/**
 * Sprint 63.1 — Structured Odontogram Print Template Conversion.
 *
 * Renders already-saved structured odontogram data (tooth_map_payload) into the
 * Daengtisia print/PDF layout: FDI visual diagram, D/M/F/DMF-T summary, legend,
 * and the GIGI | DIAGNOSA | PERAWATAN | DOKTER table with continuation.
 * Presentation-only: no data mutation, no migration, no KTP/NIK.
 *
 * AMENDED by FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-05: the formatter and the
 * shared partial are unchanged, but they are now consumed ONLY by the standalone
 * odontogram print. The combined visit print/PDF no longer composes the
 * odontogram — see MedicalRecordPrintOdontogramSeparationTest for that contract.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

function structuredBranchId(): int
{
    return Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id;
}

function structuredOdontogram(array $teeth, array $attrs = []): Odontogram
{
    $visit = ClinicVisit::factory()->create(['branch_id' => structuredBranchId()]);

    return Odontogram::factory()->create(array_merge([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => $teeth],
    ], $attrs));
}

// --- 1: formatter maps payload into the FDI visual grid ---

it('formatter maps tooth_map_payload into the visual grid with status css classes', function () {
    $odontogram = structuredOdontogram([
        '11' => ['status' => 'caries'],
        '36' => ['status' => 'missing'],
    ]);

    $out = app(OdontogramPrintFormatter::class)->format($odontogram);

    // 11 lives in upper_permanent (last cell of the right side), 36 in lower_permanent left.
    $upperRight = collect($out['jaw_rows']['upper_permanent']['right']);
    $lowerLeft = collect($out['jaw_rows']['lower_permanent']['left']);

    expect($upperRight->firstWhere('number', 11)['css'])->toBe('odo-t-caries')
        ->and($lowerLeft->firstWhere('number', 36)['css'])->toBe('odo-t-missing')
        // Untouched tooth degrades to the default cell — never blank, never crashes.
        ->and($upperRight->firstWhere('number', 18)['css'])->toBe('odo-t-default');
});

// --- 2: DMF-T equals the model source of truth ---

it('formatter DMF-T equals Odontogram::dmftCounts()', function () {
    $odontogram = structuredOdontogram([
        '11' => ['status' => 'caries'],     // D
        '12' => ['status' => 'missing'],    // M
        '13' => ['status' => 'filling'],    // F
        '14' => ['status' => 'crown'],      // F
        '15' => ['status' => 'root_treated'], // F
        '16' => ['status' => 'normal'],     // excluded
    ]);

    $out = app(OdontogramPrintFormatter::class)->format($odontogram);

    expect($out['dmft'])->toBe($odontogram->dmftCounts())
        ->and($out['dmft']['D'])->toBe(1)
        ->and($out['dmft']['M'])->toBe(1)
        ->and($out['dmft']['F'])->toBe(3)
        ->and($out['dmft']['DMFT'])->toBe(5);
});

// --- 3 + 4: unknown status does not crash and does not inflate DMF-T ---

it('unknown status renders the default cell and is excluded from DMF-T', function () {
    $odontogram = structuredOdontogram([
        '11' => ['status' => 'caries'],
        '21' => ['status' => 'totally_unknown_status'],
    ]);

    $out = app(OdontogramPrintFormatter::class)->format($odontogram);

    $upperLeft = collect($out['jaw_rows']['upper_permanent']['left']);

    expect($upperLeft->firstWhere('number', 21)['css'])->toBe('odo-t-default')
        // Only the caries tooth counts; the unknown status is ignored.
        ->and($out['dmft']['DMFT'])->toBe(1);
});

// --- 5: one row per touched tooth, ascending FDI ---

it('formatter builds one table row per touched tooth in ascending FDI order', function () {
    $odontogram = structuredOdontogram([
        '36' => ['status' => 'missing', 'additional_note' => 'Rencana implan'],
        '11' => ['status' => 'caries', 'additional_condition' => 'Profunda', 'dokter' => 'drg. A'],
        '21' => ['status' => 'normal'],
    ]);

    $out = app(OdontogramPrintFormatter::class)->format($odontogram);
    $rows = $out['table_rows'];

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'gigi'))->toBe(['11', '21', '36'])
        ->and($rows[0]['diagnosa'])->toContain('Karies')
        ->and($rows[0]['diagnosa'])->toContain('Profunda')
        ->and($rows[0]['dokter'])->toBe('drg. A')
        ->and($rows[2]['perawatan'])->toBe('Rencana implan');
});

// --- 6: empty odontogram renders a safe empty state ---

it('empty odontogram renders the safe empty state without error', function () {
    $manager = userWith(['manage_clinic_visits']);
    $odontogram = structuredOdontogram([]);

    $out = app(OdontogramPrintFormatter::class)->format($odontogram);
    expect($out['has_rows'])->toBeFalse()
        ->and($out['dmft']['DMFT'])->toBe(0);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('Belum ada kondisi odontogram yang dipilih.');
});

// --- 7: standalone print includes visual, DMF-T, legend, and table ---

it('standalone odontogram print includes the visual diagram, DMF-T, legend, and table', function () {
    $manager = userWith(['manage_clinic_visits']);
    $odontogram = structuredOdontogram([
        '11' => ['status' => 'caries', 'additional_note' => 'Tambal komposit'],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee('ODONTOGRAM GIGI PASIEN')   // title
        ->assertSee('KANAN / RIGHT')            // visual diagram side label
        ->assertSee('Jumlah DMF-T')             // DMF-T summary
        ->assertSee('D = Decay (Karies)')       // legend
        ->assertSee('Hasil Odontogram yang Dipilih') // table
        ->assertSee('Tambal komposit');
});

// --- 8: the combined visit print does NOT compose the odontogram (FIX-05) ---

it('combined visit print excludes the visual diagram, DMF-T, legend, and table', function () {
    $manager = userWith(['manage_clinic_visits']);
    $odontogram = structuredOdontogram([
        '11' => ['status' => 'caries', 'additional_condition' => 'BundleDiag', 'additional_note' => 'BundleCare'],
    ]);

    // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-05 — this assertion is the exact
    // inverse of the Sprint 63.1 contract, and deliberately so: the structured
    // odontogram template is now owned solely by the standalone odontogram print
    // (proven by test 6 above, which is unchanged).
    $this->actingAs($manager)
        ->get(route('rme.visits.print', $odontogram->clinicVisit))
        ->assertOk()
        ->assertDontSee('KANAN / RIGHT')
        ->assertDontSee('Jumlah DMF-T')
        ->assertDontSee('D = Decay (Karies)')
        ->assertDontSee('Hasil Odontogram yang Dipilih')
        ->assertDontSee('BundleDiag')
        ->assertDontSee('BundleCare');
});

// --- 9: dompdf route still renders for a visit that has an odontogram ---

it('visit PDF (dompdf) still renders for a visit that has an odontogram', function () {
    $manager = userWith(['manage_clinic_visits']);
    $odontogram = structuredOdontogram([
        '11' => ['status' => 'caries'],
        '36' => ['status' => 'missing'],
        '24' => ['status' => 'filling'],
    ]);

    $response = $this->actingAs($manager)
        ->get(route('rme.visits.pdf', $odontogram->clinicVisit));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

// --- 10: long table supports continuation header repeat ---

it('long odontogram table renders all rows with a repeating header for continuation', function () {
    $manager = userWith(['manage_clinic_visits']);

    $teeth = [];
    foreach ([18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28, 31, 32, 33, 34] as $fdi) {
        $teeth[(string) $fdi] = ['status' => 'caries'];
    }
    $odontogram = structuredOdontogram($teeth);

    $response = $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        // table-header-group powers continuation; repeat-title row carries the heading.
        ->assertSee('table-header-group', false);

    foreach (array_keys($teeth) as $fdi) {
        $response->assertSee($fdi);
    }
});

// --- 11: KTP / NIK is never rendered ---

it('structured odontogram print never renders the patient KTP/NIK', function () {
    $manager = userWith(['manage_clinic_visits']);

    $patient = Patient::factory()->create(['ktp_number' => '7371091234560001']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => structuredBranchId(),
        'patient_id' => $patient->id,
    ]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $this->actingAs($manager)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertOk()
        ->assertSee($patient->medical_record_number)
        ->assertDontSee('7371091234560001');

    $this->actingAs($manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertDontSee('7371091234560001');
});

// --- 12: unauthorized user cannot print ---

it('a user without RME view permission cannot print the structured odontogram', function () {
    $stranger = userWith([]);
    $odontogram = structuredOdontogram(['11' => ['status' => 'caries']]);

    $this->actingAs($stranger)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertForbidden();
});
