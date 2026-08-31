<?php

/**
 * Sprint 61.0 — Patient Data Completeness Audit & RM Gap Review.
 *
 * Read-only audit of patient record quality (RME → Audit Data Pasien). Verifies
 * scoring, KTP masking (full value never exposed), branch filtering/isolation,
 * duplicate-risk flagging, access control, and CSV export privacy.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientDataCompletenessService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'TLK1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->service = app(PatientDataCompletenessService::class);
});

/** A patient with every tracked field present (score 100). */
function completePatient(Branch $branch, array $overrides = []): Patient
{
    return Patient::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TLK1-2026-0001',
        'name' => 'Pasien Lengkap',
        'gender' => 'Male',
        'date_of_birth' => '1990-01-01',
        'phone' => '081111111111',
        'whatsapp_number' => '081111111111',
        'email' => 'lengkap@example.com',
        'address' => 'Jl. Lengkap No. 1',
        'occupation' => 'Karyawan',
        'ktp_number' => '7371010101900001',
        'is_active' => true,
    ], $overrides));
}

// --- Access control ------------------------------------------------------------

it('returns 200 for a patient manager (FO/Admin)', function () {
    $this->actingAs(userWith(['manage patients']))
        ->get(route('rme.patients.audit'))
        ->assertOk()
        ->assertViewIs('rme.patients.audit.index');
});

it('returns 200 for an RME patient report viewer (Owner)', function () {
    $this->actingAs(userWith(['view_rme_patient_reports']))
        ->get(route('rme.patients.audit'))
        ->assertOk();
});

it('forbids a doctor without patient/report permission', function () {
    $this->actingAs(userWith(['view_clinic_visits', 'manage_clinic_visits']))
        ->get(route('rme.patients.audit'))
        ->assertForbidden();
});

// --- Scoring -------------------------------------------------------------------

it('scores a fully populated patient as complete (100%)', function () {
    $eval = $this->service->evaluate(completePatient($this->branch));

    expect($eval['score'])->toBe(100)
        ->and($eval['complete'])->toBeTrue()
        ->and($eval['critical_complete'])->toBeTrue()
        ->and($eval['missing_fields'])->toBe([]);
});

it('flags an incomplete patient with its missing fields', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'medical_record_number' => 'DG-TLK1-2026-0002',
        'name' => 'Pasien Kurang',
        'gender' => 'Female',
        'date_of_birth' => null,
        'phone' => null,
        'whatsapp_number' => null,
        'email' => null,
        'address' => '',
        'occupation' => null,
        'ktp_number' => null,
    ]);

    $eval = $this->service->evaluate($patient);

    expect($eval['critical_complete'])->toBeFalse()
        ->and($eval['status'])->toBe(PatientDataCompletenessService::STATUS_MISSING_CRITICAL)
        ->and($eval['missing_fields'])->toHaveKeys(['date_of_birth', 'contact', 'address'])
        ->and($eval['score'])->toBeLessThan(100);
});

// --- KTP privacy ---------------------------------------------------------------

it('masks the KTP number and never returns the full value', function () {
    expect($this->service->maskKtp('7371010101900001'))->toBe('****0001')
        ->and($this->service->maskKtp(null))->toBeNull()
        ->and($this->service->maskKtp('12'))->toBe('**');
});

it('never renders the full KTP on the audit page', function () {
    completePatient($this->branch, ['ktp_number' => '7371019999000123']);

    $response = $this->actingAs(userWith(['manage patients']))
        ->get(route('rme.patients.audit'))
        ->assertOk();

    $response->assertDontSee('7371019999000123');
    $response->assertSee('****0123');
});

// --- Branch filter / isolation -------------------------------------------------

it('filters the audit table by branch', function () {
    $other = Branch::factory()->create(['code' => 'ATG1', 'name' => 'Cabang Antang', 'is_active' => true, 'is_rme_enabled' => true]);
    completePatient($this->branch, ['name' => 'Pasien TKM']);
    completePatient($other, ['name' => 'Pasien ATG', 'medical_record_number' => 'DG-ATG1-2026-0001', 'ktp_number' => '7371010101900002']);

    $this->actingAs(userWith(['manage patients']))
        ->get(route('rme.patients.audit', ['branch_id' => $this->branch->id]))
        ->assertOk()
        ->assertSee('Pasien TKM')
        ->assertDontSee('Pasien ATG');
});

it('only offers active RME-enabled branches in the filter (MAIN excluded when not RME)', function () {
    $nonRme = Branch::factory()->create(['code' => 'WHX1', 'name' => 'Gudang', 'is_active' => true, 'is_rme_enabled' => false]);

    $response = $this->actingAs(userWith(['manage patients']))
        ->get(route('rme.patients.audit'))
        ->assertOk();

    $branches = $response->viewData('branches');
    expect($branches->pluck('id'))->toContain($this->branch->id)
        ->and($branches->pluck('id'))->not->toContain($nonRme->id);
});

// --- Duplicate risk ------------------------------------------------------------

it('flags duplicate risk for patients sharing the same phone', function () {
    completePatient($this->branch, ['name' => 'Pasien A', 'phone' => '085200000001', 'whatsapp_number' => null, 'medical_record_number' => 'DG-TLK1-2026-1001', 'ktp_number' => '7371010101900011']);
    completePatient($this->branch, ['name' => 'Pasien B', 'phone' => '0852-0000-0001', 'whatsapp_number' => null, 'medical_record_number' => 'DG-TLK1-2026-1002', 'ktp_number' => '7371010101900012']);

    $risks = $this->service->detectDuplicateRisks(Patient::all());

    expect($risks)->toHaveCount(2)
        ->and(collect($risks)->flatten()->unique()->values()->all())->toContain('No. HP/WA sama');
});

it('flags duplicate risk for same normalized name + birth date', function () {
    completePatient($this->branch, ['name' => 'Budi Santoso', 'date_of_birth' => '1985-05-05', 'phone' => '081000000001', 'whatsapp_number' => null, 'medical_record_number' => 'DG-TLK1-2026-2001', 'ktp_number' => '7371010101900021']);
    completePatient($this->branch, ['name' => 'budi  santoso', 'date_of_birth' => '1985-05-05', 'phone' => '081000000002', 'whatsapp_number' => null, 'medical_record_number' => 'DG-TLK1-2026-2002', 'ktp_number' => '7371010101900022']);

    $risks = $this->service->detectDuplicateRisks(Patient::all());

    expect($risks)->toHaveCount(2)
        ->and(collect($risks)->flatten()->all())->toContain('Nama + tanggal lahir sama');
});

it('does not flag a unique patient', function () {
    completePatient($this->branch, ['name' => 'Unik', 'phone' => '081999999999', 'whatsapp_number' => null, 'ktp_number' => '7371010101900099']);

    expect($this->service->detectDuplicateRisks(Patient::all()))->toBe([]);
});

// --- CSV export ----------------------------------------------------------------

it('exports CSV without the full KTP and with the expected headers', function () {
    completePatient($this->branch, ['name' => 'Pasien Export', 'ktp_number' => '7371017777000456']);

    $response = $this->actingAs(userWith(['manage patients']))
        ->get(route('rme.patients.audit.export'))
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('rm_number,patient_name,completeness_score')
        ->and($csv)->toContain('Pasien Export')
        ->and($csv)->not->toContain('7371017777000456')
        ->and($csv)->not->toContain('ktp');
});
