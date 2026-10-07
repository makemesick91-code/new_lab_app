<?php

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientSelectorSearchService;
use App\Modules\Patient\Services\PatientService;
use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Services\PatientDuplicateDetectionService;
use App\Modules\PatientMerge\Services\PatientMergeGuard;
use App\Modules\PatientMerge\Services\PatientMergeReversalService;
use App\Modules\PatientMerge\Services\PatientMergeSelectionService;
use App\Modules\PatientMerge\Services\PatientRmAliasService;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Validation\ValidationException;

/*
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — what happens AFTER a merge
 * (old RM alias search, no new activity on the source), candidate detection,
 * registration duplicate prevention and the reversal review.
 */

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    seedAccessControl();
    $this->requester = pmUser('Supervisor RME');
    $this->approver = pmUser('Supervisor RME');
});

function pmMergedPair(User $requester, User $approver): array
{
    $source = pmPatient(['name' => 'Ahmad Fauzi']);
    $canonical = pmPatient(['name' => 'Ahmad Fauzi']);
    $case = pmMerge(pmSubmittedCase($source, $canonical, $requester, $canonical), $approver);

    return [$source->fresh(), $canonical->fresh(), $case];
}

it('finds the canonical patient when an old Nomor RM is typed (alias)', function (): void {
    [$source, $canonical] = pmMergedPair($this->requester, $this->approver);

    expect(app(PatientRmAliasService::class)->resolveCanonical($source->medical_record_number)?->id)->toBe($canonical->id)
        ->and(app(PatientRmAliasService::class)->resolveCanonical($canonical->medical_record_number))->toBeNull();

    // New Visit selector: the old RM returns the canonical patient, never the merged row.
    $ids = collect(app(PatientSelectorSearchService::class)->search($this->approver, $source->medical_record_number)['results'])->pluck('id');
    expect($ids)->toContain($canonical->id)->not->toContain($source->id);

    // Manual selection search behaves the same and says why.
    $options = collect(app(PatientMergeSelectionService::class)->search($this->approver, $source->medical_record_number));
    expect($options->pluck('id'))->toContain($canonical->id)->not->toContain($source->id)
        ->and($options->firstWhere('id', $canonical->id)['via_alias'])->toBe($source->medical_record_number);
});

it('keeps alias resolution inside the searcher branch scope', function (): void {
    $other = pmBranch('ATG3', 'Cabang Antang');
    $source = pmPatient([], $other);
    $canonical = pmPatient([], $other);
    pmMerge(pmSubmittedCase($source, $canonical, $this->requester, $canonical), $this->approver);

    $operator = pmUser('Admin Klinik');
    rmeMakeAdminClinicActive($operator, pmBranch('LDK2', 'Cabang Landak'));

    expect(app(PatientMergeSelectionService::class)->search($operator, $source->medical_record_number))->toBe([]);
});

it('refuses a new visit, an identity edit or a deletion on a merged patient (service level)', function (): void {
    [$source] = pmMergedPair($this->requester, $this->approver);

    expect(fn () => app(PatientService::class)->update($source, ['name' => 'Nama Baru']))->toThrow(ValidationException::class);
    expect(fn () => app(PatientService::class)->delete($source))->toThrow(ValidationException::class);
    expect(fn () => app(PatientService::class)->activate($source))->toThrow(ValidationException::class);

    expect(fn () => app(ClinicVisitService::class)->create([
        'patient_mode' => 'existing',
        'patient_id' => $source->id,
        'branch_id' => $source->branch_id,
        'doctor_id' => $source->doctor_id,
        'clinic_id' => null,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'created_by' => $this->approver->id,
    ]))->toThrow(ValidationException::class);

    expect(ClinicVisit::query()->where('patient_id', $source->id)->count())->toBe(0);
});

it('excludes merged patients from the New Visit selector and its submit-time re-authorization', function (): void {
    [$source] = pmMergedPair($this->requester, $this->approver);

    expect(app(PatientSelectorSearchService::class)->isSelectable($this->approver, $source->id))->toBeFalse();
});

it('guards the merged state with a lock-aware check', function (): void {
    [$source, $canonical] = pmMergedPair($this->requester, $this->approver);
    $guard = app(PatientMergeGuard::class);

    expect(fn () => $guard->assertNotMergedLocked($source->id))->toThrow(ValidationException::class);
    $guard->assertNotMergedLocked($canonical->id);
    expect(true)->toBeTrue();
});

it('redirects a merged patient edit page and RME workspace to the canonical patient', function (): void {
    [$source, $canonical] = pmMergedPair($this->requester, $this->approver);
    $admin = pmUser('Super Admin');
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
    $visit = ClinicVisit::factory()->create(['patient_id' => $canonical->id, 'branch_id' => $canonical->branch_id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    MedicalRecord::factory()->create(['clinic_visit_id' => $visit->id, 'patient_id' => $canonical->id, 'branch_id' => $visit->branch_id, 'doctor_id' => $visit->doctor_id, 'status' => MedicalRecord::STATUS_FINAL]);

    $this->actingAs($admin)->get(route('settings.patients.edit', $source))
        ->assertRedirect(route('settings.patients.edit', $canonical->id));
    $this->actingAs($admin)->get(route('rme.medical-records.patients.show', $source->id))
        ->assertRedirect(route('rme.medical-records.patients.show', $canonical->id));
});

it('detects candidates by shared birth date or phone with a similar name — never birth date alone', function (): void {
    $branch = pmBranch();
    $a = pmPatient(['name' => 'Nur Aisyah', 'date_of_birth' => '1991-03-04', 'phone' => '081311110000'], $branch);
    $b = pmPatient(['name' => 'Nur Aisya', 'date_of_birth' => '1991-03-04', 'phone' => '081322220000'], $branch);
    pmPatient(['name' => 'Bambang Pamungkas', 'date_of_birth' => '1991-03-04', 'phone' => '081333330000'], $branch);

    $pairs = app(PatientDuplicateDetectionService::class)->pairsFor([$branch->id]);

    expect($pairs)->toHaveCount(1)
        ->and($pairs[0]['key'])->toBe(min($a->id, $b->id).'-'.max($a->id, $b->id))
        ->and($pairs[0]['signals']['date_of_birth'])->toBe('match')
        ->and(json_encode($pairs->all()))->not->toContain('081311110000');
});

it('never auto-merges: detection creates no case and moves no data', function (): void {
    $branch = pmBranch();
    pmPatient(['name' => 'Rina', 'phone' => '081400000001'], $branch);
    pmPatient(['name' => 'Rina', 'phone' => '081400000001'], $branch);

    app(PatientDuplicateDetectionService::class)->pairsFor([$branch->id]);
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)->actingAs($this->approver)
        ->get(route('patient-merge.candidates.index'))->assertOk()->assertSee('Rina');

    expect(PatientMergeCase::query()->count())->toBe(0)
        ->and(Patient::query()->whereNotNull('merged_into_patient_id')->count())->toBe(0);
});

it('warns on a strong duplicate at registration and requires a reason to continue', function (): void {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
    $existing = pmPatient(['name' => 'Kartini Wulandari', 'date_of_birth' => '1985-07-07']);
    $admin = pmUser('Super Admin');
    $payload = [
        'clinic_id' => $existing->clinic_id,
        'doctor_id' => $existing->doctor_id,
        'name' => 'Kartini Wulandari',
        'date_of_birth' => '1985-07-07',
    ];

    $this->actingAs($admin)->post(route('settings.patients.store'), $payload)
        ->assertSessionHasErrors('duplicate_override_reason')
        ->assertSessionHas('duplicate_candidates');
    expect(Patient::query()->where('name', 'Kartini Wulandari')->count())->toBe(1);

    $this->actingAs($admin)->post(route('settings.patients.store'), $payload + ['duplicate_override_reason' => 'Pasien berbeda, kembar identik, sudah cek KTP.'])
        ->assertSessionHasNoErrors();
    expect(Patient::query()->where('name', 'Kartini Wulandari')->count())->toBe(2);
});

it('does not stop a genuinely different patient who shares a birthday', function (): void {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
    $existing = pmPatient(['name' => 'Kartini Wulandari', 'date_of_birth' => '1985-07-07']);

    $this->actingAs(pmUser('Super Admin'))->post(route('settings.patients.store'), [
        'clinic_id' => $existing->clinic_id,
        'doctor_id' => $existing->doctor_id,
        'name' => 'Yohanes Siregar',
        'date_of_birth' => '1985-07-07',
    ])->assertSessionHasNoErrors();
});

it('allows a safe reversal that restores both patients exactly', function (): void {
    $source = pmPatient(['name' => 'Dewi A', 'ktp_number' => '7371000000003333']);
    $canonical = pmPatient(['name' => 'Dewi B']);
    $visit = ClinicVisit::factory()->create(['patient_id' => $source->id, 'branch_id' => $source->branch_id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $case = pmMerge(pmSubmittedCase($source, $canonical, $this->requester, $canonical, [
        'name' => ['source' => 'patient_a'], 'ktp_number' => ['source' => 'patient_a'],
    ]), $this->approver);

    $reversals = app(PatientMergeReversalService::class);
    $case = $reversals->request($case, $this->approver, 'Ternyata dua orang berbeda (saudara).');
    expect($case->status)->toBe(PatientMergeStatus::REVERSAL_REQUIRED)
        ->and($case->reversal_assessment['mode'])->toBe(PatientMergeReversalService::MODE_SAFE);

    // The reversal requester cannot execute it.
    expect(fn () => $reversals->execute($case, $this->approver))->toThrow(ValidationException::class);

    $reversals->execute($case, $this->requester);

    $source->refresh();
    $canonical->refresh();
    expect($source->isMerged())->toBeFalse()
        ->and($source->ktp_number)->toBe('7371000000003333')
        ->and($source->name)->toBe('Dewi A')
        ->and($canonical->name)->toBe('Dewi B')
        ->and($canonical->ktp_number)->toBeNull()
        ->and($visit->fresh()->patient_id)->toBe($source->id)
        ->and($case->fresh()->status)->toBe(PatientMergeStatus::REVERSED)
        ->and(app(PatientRmAliasService::class)->resolveCanonical($source->medical_record_number))->toBeNull();
});

it('requires supervised reconciliation once the canonical patient has new activity', function (): void {
    [$source, $canonical, $case] = pmMergedPair($this->requester, $this->approver);
    $visit = ClinicVisit::factory()->create(['patient_id' => $canonical->id, 'branch_id' => $canonical->branch_id]);
    MedicalRecord::factory()->create(['clinic_visit_id' => $visit->id, 'patient_id' => $canonical->id, 'branch_id' => $visit->branch_id, 'doctor_id' => $visit->doctor_id]);

    $reversals = app(PatientMergeReversalService::class);
    $case = $reversals->request($case, $this->approver, 'Dugaan salah gabung, perlu dicek ulang.');

    expect($case->reversal_assessment['mode'])->toBe(PatientMergeReversalService::MODE_SUPERVISED);
    expect(fn () => $reversals->execute($case, $this->requester))->toThrow(PatientMergeBlockedException::class);
    expect($source->fresh()->isMerged())->toBeTrue();

    $reversals->dismiss($case, $this->requester);
    expect($case->fresh()->status)->toBe(PatientMergeStatus::COMPLETED);
});
