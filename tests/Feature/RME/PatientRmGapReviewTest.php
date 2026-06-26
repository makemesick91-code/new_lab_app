<?php

/**
 * Sprint 61.0 — Branch-level RM gap review.
 *
 * Inspects the finalized RM format DG-{KODE_CABANG}-{TAHUN}-{NOMOR}. Confirms the
 * review never crashes on malformed RM, detects missing numeric sequence numbers,
 * and reports "Tidak dapat dihitung" when a branch has too few parseable RMs.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientRmGapReviewService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->service = app(PatientRmGapReviewService::class);
});

function rmPatient(Branch $branch, ?string $rm): Patient
{
    return Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => $rm,
        'name' => 'Pasien '.($rm ?? 'null'),
    ]);
}

// --- Suffix parsing ------------------------------------------------------------

it('parses a valid DG suffix and rejects malformed values', function () {
    expect($this->service->parseSuffix('DG-TKM1-2026-0004'))->toBe(4)
        ->and($this->service->parseSuffix('DG-TKM1-2026-15'))->toBe(15)
        ->and($this->service->parseSuffix('INVALID'))->toBeNull()
        ->and($this->service->parseSuffix('MRN-ABCD1234'))->toBeNull()
        ->and($this->service->parseSuffix('DG-TKM1-2026-'))->toBeNull()
        ->and($this->service->parseSuffix(null))->toBeNull();
});

// --- Gap detection -------------------------------------------------------------

it('detects missing sequence numbers for a parseable RM sequence', function () {
    rmPatient($this->branch, 'DG-TKM1-2026-0001');
    rmPatient($this->branch, 'DG-TKM1-2026-0002');
    rmPatient($this->branch, 'DG-TKM1-2026-0004');
    rmPatient($this->branch, 'DG-TKM1-2026-0007');

    $summary = collect($this->service->review())->firstWhere('branch_id', $this->branch->id);

    expect($summary['parseable'])->toBeTrue()
        ->and($summary['min'])->toBe(1)
        ->and($summary['max'])->toBe(7)
        ->and($summary['parseable_count'])->toBe(4)
        ->and($summary['missing_count'])->toBe(3)
        ->and($summary['missing_sample'])->toBe([3, 5, 6]);
});

it('does not crash on malformed or null RM values', function () {
    rmPatient($this->branch, 'DG-TKM1-2026-0001');
    rmPatient($this->branch, 'DG-TKM1-2026-0003');
    rmPatient($this->branch, 'RANDOM-RM-VALUE');
    rmPatient($this->branch, null);

    $summary = collect($this->service->review())->firstWhere('branch_id', $this->branch->id);

    expect($summary['parseable'])->toBeTrue()
        ->and($summary['unparseable_count'])->toBe(2)
        ->and($summary['missing_count'])->toBe(1)
        ->and($summary['missing_sample'])->toBe([2]);
});

it('reports "Tidak dapat dihitung" when fewer than two RMs are parseable', function () {
    rmPatient($this->branch, 'DG-TKM1-2026-0001');
    rmPatient($this->branch, 'NOT-A-DG-RM');

    $summary = collect($this->service->review())->firstWhere('branch_id', $this->branch->id);

    expect($summary['parseable'])->toBeFalse()
        ->and($summary['note'])->toContain('Tidak dapat dihitung');
});

it('renders the RM gap section on the audit page without error', function () {
    rmPatient($this->branch, 'DG-TKM1-2026-0001');
    rmPatient($this->branch, 'DG-TKM1-2026-0003');
    rmPatient($this->branch, 'BROKEN');

    $this->actingAs(userWith(['manage patients']))
        ->get(route('rme.patients.audit'))
        ->assertOk()
        ->assertSee('Tinjauan Loncatan No. RM per Cabang');
});
