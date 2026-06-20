<?php

// Sprint 57 — Cross-Branch Nomor RM Lookup
// Verifies the deliberate, read-only, exact-match cross-branch RM lookup on the
// Kunjungan / Rekam Medis / Kasir pages, and that it never leaks sensitive data
// nor changes existing branch-scoped behaviour.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    // Authenticated user has no branch column/relation → current branch = MAIN.
    $this->mainBranch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'BR2', 'name' => 'Cabang Kedua', 'is_active' => true]);

    $this->service = app(CrossBranchPatientLookupService::class);
});

function lookupPatient(Branch $branch, array $overrides = []): Patient
{
    return Patient::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'medical_record_number' => 'RM-EXACT-001',
        'ktp_number' => '3201999988887777',
        'name' => 'Pasien Lookup',
    ], $overrides));
}

it('finds a patient registered in another branch by exact RM', function () {
    $this->actingAs(userWith(['view_clinic_visits']));
    lookupPatient($this->otherBranch);

    $result = $this->service->lookupByMedicalRecordNumberAcrossBranches('RM-EXACT-001');

    expect($result['searched'])->toBeTrue()
        ->and($result['results'])->toHaveCount(1)
        ->and($result['results'][0]['name'])->toBe('Pasien Lookup')
        ->and($result['results'][0]['branch_label'])->toContain('Cabang Kedua')
        ->and($result['results'][0]['is_current_branch'])->toBeFalse();
});

it('flags a patient in the current branch as current branch', function () {
    $this->actingAs(userWith(['view_clinic_visits']));
    lookupPatient($this->mainBranch);

    $result = $this->service->lookupByMedicalRecordNumberAcrossBranches('RM-EXACT-001');

    expect($result['results'])->toHaveCount(1)
        ->and($result['results'][0]['is_current_branch'])->toBeTrue();
});

it('never returns sensitive fields in the lookup payload', function () {
    $this->actingAs(userWith(['view_clinic_visits']));
    lookupPatient($this->otherBranch, ['phone' => '08123', 'whatsapp_number' => '08123', 'address' => 'Jl Rahasia']);

    $result = $this->service->lookupByMedicalRecordNumberAcrossBranches('RM-EXACT-001');
    $row = $result['results'][0];

    expect($row)->not->toHaveKeys(['ktp_number', 'whatsapp_number', 'phone', 'email', 'address']);
    foreach (['3201999988887777', '08123', 'Jl Rahasia'] as $secret) {
        expect(json_encode($row))->not->toContain($secret);
    }
});

it('matches exactly and does not match a substring of the RM', function () {
    $this->actingAs(userWith(['view_clinic_visits']));
    lookupPatient($this->otherBranch, ['medical_record_number' => 'RM-EXACT-001']);

    expect($this->service->lookupByMedicalRecordNumberAcrossBranches('RM-EXACT')['results'])->toHaveCount(0)
        ->and($this->service->lookupByMedicalRecordNumberAcrossBranches('EXACT-001')['results'])->toHaveCount(0);
});

it('returns a safe empty payload for blank input', function () {
    $this->actingAs(userWith(['view_clinic_visits']));

    $result = $this->service->lookupByMedicalRecordNumberAcrossBranches('   ');

    expect($result['searched'])->toBeFalse()
        ->and($result['results'])->toBeEmpty();
});

it('returns a safe empty result for an unknown RM', function () {
    $this->actingAs(userWith(['view_clinic_visits']));

    $result = $this->service->lookupByMedicalRecordNumberAcrossBranches('RM-DOES-NOT-EXIST');

    expect($result['searched'])->toBeTrue()
        ->and($result['results'])->toBeEmpty();
});

it('includes latest visit date when available', function () {
    $this->actingAs(userWith(['view_clinic_visits']));
    $patient = lookupPatient($this->otherBranch);
    ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => $this->otherBranch->id,
        'visit_date' => '2026-01-15',
    ]);

    $result = $this->service->lookupByMedicalRecordNumberAcrossBranches('RM-EXACT-001');

    expect($result['results'][0]['latest_visit_date'])->toBe('15 Jan 2026');
});

// ─── HTTP integration: each page renders the lookup, hides KTP ────────────────

it('Kunjungan page shows other-branch patient and hides KTP', function () {
    lookupPatient($this->otherBranch);

    $response = $this->actingAs(userWith(['view_clinic_visits', 'manage_clinic_visits']))
        ->get(route('rme.visits.index', ['rm_lookup' => 'RM-EXACT-001']));

    $response->assertOk()
        ->assertSee('Pasien Lookup')
        ->assertSee('Cabang Kedua')
        ->assertSee('Pasien ditemukan di cabang lain')
        ->assertDontSee('3201999988887777');
});

it('Rekam Medis page shows other-branch patient and hides KTP', function () {
    lookupPatient($this->otherBranch);

    $response = $this->actingAs(userWith(['view_clinic_visits', 'manage_clinic_visits']))
        ->get(route('rme.medical-records.index', ['rm_lookup' => 'RM-EXACT-001']));

    $response->assertOk()
        ->assertSee('Pasien Lookup')
        ->assertSee('Cabang Kedua')
        ->assertDontSee('3201999988887777');
});

it('Kasir page shows other-branch patient and hides KTP', function () {
    lookupPatient($this->otherBranch);

    $response = $this->actingAs(userWith(['manage_rme_billing']))
        ->get(route('rme.cashier.index', ['rm_lookup' => 'RM-EXACT-001']));

    $response->assertOk()
        ->assertSee('Pasien Lookup')
        ->assertSee('Cabang Kedua')
        ->assertDontSee('3201999988887777');
});

it('shows a safe empty state on the Kunjungan page for an unknown RM', function () {
    $response = $this->actingAs(userWith(['view_clinic_visits', 'manage_clinic_visits']))
        ->get(route('rme.visits.index', ['rm_lookup' => 'RM-NOPE']));

    $response->assertOk()->assertSee('Nomor RM tidak ditemukan di cabang mana pun.');
});
