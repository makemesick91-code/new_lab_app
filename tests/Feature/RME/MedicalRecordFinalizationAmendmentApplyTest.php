<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR5 — approve and apply an amendment of a final sheet
|--------------------------------------------------------------------------
|
| Rule 240 §7. Approving is applying, in ONE transaction, so the checker approves
| exactly what is applied: visit → sheet → amendment are locked; the approver is
| re-decided (permission, scope, not the requester, the doctor of record or the
| finalizer — binding Super Admin); the amendment is pending and its base is
| still the latest version; the proposal is re-validated against the locked
| sheet under today's rules (incl. the pilot_enforced primary-diagnosis gate);
| the change is written to the sheet, which stays final and locked; the next
| version (kind `amendment`) is chained to the latest one; the amendment becomes
| `applied`; the audit row carries ids and lengths only; the requester is
| notified after commit. The version hash covers `snapshot_schema_version`.
|
| Selected by the existing `MedicalRecordFinalization` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `m3ap`.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\DiagnosisRolloutSetting;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Interfaces\RmeAmendmentRepositoryInterface;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Modules\RmeAmendment\Services\RmeRecordVersionService;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    $this->supervisor = userInRole('Supervisor RME');
});

function m3apService(): RmeAmendmentService
{
    return app(RmeAmendmentService::class);
}

/** A final sheet of a finished visit (finalized before rule 240: no version yet). */
function m3apSheet(?User $finalizer = null): MedicalRecord
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'clinic_room_id' => test()->room->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => test()->doctor->id,
        'subjective' => 'm3ap keluhan nyeri',
        'assessment' => 'm3ap pulpitis',
        'plan' => 'm3ap rencana awal',
    ]);
    $sheet->forceFill([
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now()->subDay(),
        'finalized_by' => ($finalizer ?? test()->doctorUser)->id,
    ])->save();

    return $sheet->fresh();
}

function m3apDiagnosis(MedicalRecord $sheet, string $code, string $role): MedicalRecordDiagnosis
{
    return MedicalRecordDiagnosis::create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $sheet->clinic_visit_id,
        'branch_id' => $sheet->branch_id,
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create(['code' => $code, 'status' => ClinicalDiagnosis::STATUS_ACTIVE])->id,
        'diagnosis_role' => $role,
        'diagnosed_at' => now(),
    ]);
}

function m3apRequest(MedicalRecord $sheet, ?array $proposal = null, ?User $by = null): RmeAmendment
{
    return m3apService()->request($sheet, $by ?? test()->doctorUser, 'm3ap koreksi diagnosis kerja', $proposal ?? ['fields' => ['assessment' => 'm3ap pulpitis ireversibel']]);
}

function m3apRefusal(callable $act): string
{
    try {
        $act();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->implode(' | ');
    }

    test()->fail('Expected a refusal, the action succeeded.');
}

/** @return Collection<int, RmeRecordVersion> */
function m3apVersions(MedicalRecord $sheet)
{
    return RmeRecordVersion::query()->where('subject_type', RmeRecordVersion::SUBJECT_MEDICAL_RECORD)->where('subject_id', $sheet->id)->orderBy('version')->get();
}

// ─── Approve = apply ─────────────────────────────────────────────────────────

it('applies exactly the proposal, keeps the sheet final, and chains version 2 to the base', function () {
    $sheet = m3apSheet();
    $old = m3apDiagnosis($sheet, 'K04.0', MedicalRecordDiagnosis::ROLE_PRIMARY);
    $new = ClinicalDiagnosis::factory()->create(['code' => 'K04.1', 'status' => ClinicalDiagnosis::STATUS_ACTIVE]);
    $amendment = m3apRequest($sheet, [
        'fields' => ['assessment' => 'm3ap pulpitis ireversibel', 'plan' => null],
        'diagnoses' => ['remove' => [$old->id], 'add' => [['clinical_diagnosis_id' => $new->id, 'diagnosis_role' => 'primary', 'notes' => 'm3ap koreksi']]],
    ]);

    $applied = m3apService()->approve($amendment, $this->supervisor, 'm3ap sesuai catatan klinis');
    $fresh = $sheet->fresh();
    $versions = m3apVersions($sheet);

    expect($applied->status)->toBe(RmeAmendment::STATUS_APPLIED)
        ->and((int) $applied->decided_by)->toBe((int) $this->supervisor->id)
        ->and($applied->decided_at)->not->toBeNull()
        ->and($applied->decision_reason)->toBe('m3ap sesuai catatan klinis')
        ->and($fresh->assessment)->toBe('m3ap pulpitis ireversibel')
        ->and($fresh->plan)->toBeNull()
        ->and($fresh->subjective)->toBe('m3ap keluhan nyeri')
        ->and($fresh->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($fresh->finalized_by)->toBe($sheet->finalized_by)
        ->and(MedicalRecordDiagnosis::query()->find($old->id))->toBeNull()
        ->and(MedicalRecordDiagnosis::withTrashed()->find($old->id)?->trashed())->toBeTrue()
        ->and(MedicalRecordDiagnosis::query()->where('medical_record_id', $sheet->id)->sole()->clinical_diagnosis_id)->toBe($new->id);

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->kind)->toBe(RmeRecordVersion::KIND_BASELINE)
        ->and($versions[1]->kind)->toBe(RmeRecordVersion::KIND_AMENDMENT)
        ->and($versions[1]->previous_hash)->toBe($versions[0]->hash)
        ->and($versions[1]->snapshot['fields']['assessment'])->toBe('m3ap pulpitis ireversibel')
        ->and((int) $applied->applied_version_id)->toBe((int) $versions[1]->id)
        ->and((int) $applied->applied_version)->toBe(2)
        ->and(app(RmeRecordVersionService::class)->verifyChain($fresh))->toBeTrue()
        ->and(app(RmeRecordVersionService::class)->amendmentCount($fresh))->toBe(1);
});

it('keeps the sheet locked for a normal edit after the amendment is applied', function () {
    $sheet = m3apSheet();
    m3apService()->approve(m3apRequest($sheet), $this->supervisor);

    // A live, consented encounter of the patient, so the authoring authority
    // passes and the lock itself answers.
    rmeActiveConsentedEncounter(ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => $this->room->id,
    ]));
    $this->actingAs(rmeClinicalAuthor(['view_clinic_visits', 'manage_clinic_visits']));
    expect(m3apRefusal(fn () => app(MedicalRecordService::class)->updateDraft($sheet->fresh(), ['assessment' => 'm3ap langsung diubah'])))->toContain(MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED)
        ->and($sheet->fresh()->assessment)->toBe('m3ap pulpitis ireversibel');
});

it('refuses a stale base: a version written after the request makes the requester file again', function () {
    $sheet = m3apSheet();
    $amendment = m3apRequest($sheet);
    $v1 = m3apVersions($sheet)->sole();
    $service = app(RmeRecordVersionService::class);
    $snapshot = $v1->snapshotJson();
    DB::table('trx_rme_record_versions')->insert([
        'subject_type' => RmeRecordVersion::SUBJECT_MEDICAL_RECORD,
        'subject_id' => $sheet->id,
        'version' => 2,
        'kind' => RmeRecordVersion::KIND_AMENDMENT,
        'snapshot_schema_version' => RmeRecordVersionService::SNAPSHOT_SCHEMA_VERSION,
        'snapshot' => $snapshot,
        'previous_hash' => $v1->hash,
        'hash' => $service->hashFor(RmeRecordVersion::SUBJECT_MEDICAL_RECORD, $sheet->id, 2, RmeRecordVersion::KIND_AMENDMENT, $v1->hash, $snapshot, RmeRecordVersionService::SNAPSHOT_SCHEMA_VERSION),
        'created_at' => now(),
    ]);

    expect(m3apRefusal(fn () => m3apService()->approve($amendment, $this->supervisor)))->toContain(RmeAmendmentService::MESSAGE_STALE_BASE)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and($sheet->fresh()->assessment)->toBe('m3ap pulpitis')
        ->and(m3apVersions($sheet))->toHaveCount(2);
});

it('never lets the requester, the doctor of record or the finalizer approve; Super Admin only on another\'s request', function () {
    // A Supervisor RME finalized; the doctor of record requests.
    $amendment = m3apRequest(m3apSheet($this->supervisor));
    $this->doctorUser->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);

    expect(m3apRefusal(fn () => m3apService()->approve($amendment, $this->doctorUser->fresh())))->toContain(RmeAmendmentService::MESSAGE_SELF_DECISION)
        ->and(m3apRefusal(fn () => m3apService()->approve($amendment, $this->supervisor)))->toContain(RmeAmendmentService::MESSAGE_FINALIZER)
        ->and(m3apRefusal(fn () => m3apService()->approve($amendment, userInRole('Doctor'))))->toContain(RmeAmendmentService::MESSAGE_NO_APPROVE_PERMISSION);

    // The finalizer requests; the doctor of record (also a Supervisor RME) may not approve.
    $finalizerSheet = m3apSheet(userInRole('Super Admin'));
    $superAdmin = User::query()->findOrFail($finalizerSheet->finalized_by);
    $own = m3apRequest($finalizerSheet, by: $superAdmin);
    expect(m3apRefusal(fn () => m3apService()->approve($own, $superAdmin)))->toContain(RmeAmendmentService::MESSAGE_SELF_DECISION);

    $theirs = m3apRequest(m3apSheet());
    expect(m3apService()->approve($theirs, userInRole('Super Admin'))->status)->toBe(RmeAmendment::STATUS_APPLIED)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);
});

it('re-validates the proposal under today\'s rules when applying (LOW-3 gate, inactive master)', function () {
    $sheet = m3apSheet();
    $primary = m3apDiagnosis($sheet, 'K04.2', MedicalRecordDiagnosis::ROLE_PRIMARY);
    $amendment = m3apRequest($sheet, ['diagnoses' => ['remove' => [$primary->id]]]);

    // The branch became pilot_enforced after the request: removing the last primary is refused at apply.
    DiagnosisRolloutSetting::create(['branch_id' => $this->branch->id, 'mode' => DiagnosisRolloutSetting::MODE_PILOT_ENFORCED, 'reason' => 'm3ap pilot cabang', 'configured_by' => $this->supervisor->id]);
    expect(m3apRefusal(fn () => m3apService()->approve($amendment, $this->supervisor)))->toContain(RmeAmendmentService::MESSAGE_PRIMARY_REQUIRED)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and(MedicalRecordDiagnosis::query()->find($primary->id))->not->toBeNull();

    // A proposed master deprecated after the request is refused too.
    m3apService()->cancel($amendment->fresh(), $this->doctorUser);
    $master = ClinicalDiagnosis::factory()->create(['code' => 'K05.0', 'status' => ClinicalDiagnosis::STATUS_ACTIVE]);
    $second = m3apRequest($sheet->fresh(), ['diagnoses' => ['add' => [['clinical_diagnosis_id' => $master->id, 'diagnosis_role' => 'secondary']]]]);
    $master->forceFill(['status' => ClinicalDiagnosis::STATUS_DEPRECATED])->save();
    expect(m3apRefusal(fn () => m3apService()->approve($second, $this->supervisor)))->toContain(RmeAmendmentService::MESSAGE_MASTER_INACTIVE)
        ->and($second->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);
});

it('decides on the locked row: a stale copy cannot approve twice, after a cancel or after a reject', function () {
    $sheet = m3apSheet();
    $amendment = m3apRequest($sheet);
    $staleCopy = RmeAmendment::query()->findOrFail($amendment->id);

    m3apService()->approve($amendment, $this->supervisor);
    expect(m3apRefusal(fn () => m3apService()->approve($staleCopy, userInRole('Supervisor RME'))))->toContain(RmeAmendmentService::MESSAGE_NOT_PENDING)
        ->and(m3apVersions($sheet))->toHaveCount(2);

    $cancelled = m3apRequest($sheet->fresh(), ['fields' => ['plan' => 'm3ap rencana kedua']]);
    $copy = RmeAmendment::query()->findOrFail($cancelled->id);
    m3apService()->cancel($cancelled, $this->doctorUser);
    expect(m3apRefusal(fn () => m3apService()->approve($copy, $this->supervisor)))->toContain(RmeAmendmentService::MESSAGE_NOT_PENDING);

    $rejected = m3apRequest($sheet->fresh(), ['fields' => ['plan' => 'm3ap rencana ketiga']]);
    $copy = RmeAmendment::query()->findOrFail($rejected->id);
    m3apService()->reject($rejected, $this->supervisor, 'm3ap tidak sesuai catatan');
    expect(m3apRefusal(fn () => m3apService()->approve($copy, userInRole('Supervisor RME'))))->toContain(RmeAmendmentService::MESSAGE_NOT_PENDING)
        ->and($sheet->fresh()->plan)->toBe('m3ap rencana awal')
        ->and(m3apVersions($sheet))->toHaveCount(2);
});

it('audits RME_AMENDMENT_APPLIED with ids and lengths only', function () {
    $sheet = m3apSheet();
    $amendment = m3apService()->approve(m3apRequest($sheet), $this->supervisor, 'm3ap rahasia catatan persetujuan');

    $audit = AuditLog::query()->where('entity_type', RmeAmendmentService::AUDIT_ENTITY)->where('action', RmeAmendmentService::AUDIT_APPLIED)->sole();
    expect((int) $audit->entity_id)->toBe((int) $amendment->id)
        ->and(array_keys($audit->new_values))->toEqualCanonicalizing(['subject_type', 'subject_id', 'branch_id', 'status', 'requested_by', 'base_version', 'applied_version', 'applied_version_id', 'decision_reason_length'])
        ->and(json_encode($audit->toArray()))->not->toContain('m3ap rahasia')
        ->and(json_encode($audit->toArray()))->not->toContain('ireversibel');
});

it('notifies the approvers on a request and the requester on a reject and an apply, after commit, ids only', function () {
    $sheet = m3apSheet();
    $amendment = m3apRequest($sheet);

    $toSupervisor = $this->supervisor->fresh()->notifications;
    expect($toSupervisor)->toHaveCount(1)
        ->and(array_keys($toSupervisor->first()->data))->toEqualCanonicalizing(['title', 'message', 'url', 'rme_amendment_id'])
        ->and((int) $toSupervisor->first()->data['rme_amendment_id'])->toBe((int) $amendment->id)
        ->and($this->doctorUser->fresh()->notifications)->toHaveCount(0);

    m3apService()->reject($amendment, $this->supervisor, 'm3ap rahasia alasan tolak');
    $second = m3apRequest($sheet->fresh(), ['fields' => ['plan' => 'm3ap rencana kedua']]);
    m3apService()->approve($second, $this->supervisor);

    $toRequester = $this->doctorUser->fresh()->notifications;
    expect($toRequester)->toHaveCount(2)
        ->and($toRequester->pluck('data.rme_amendment_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe([(int) $amendment->id, (int) $second->id])
        ->and(json_encode($toRequester->pluck('data')))->not->toContain('m3ap rahasia')
        ->and(json_encode($toRequester->pluck('data')))->not->toContain('m3ap koreksi');
});

it('sends no notification when the request is refused', function () {
    $sheet = m3apSheet();
    m3apRequest($sheet);

    expect(fn () => m3apRequest($sheet->fresh()))->toThrow(ValidationException::class)
        ->and($this->supervisor->fresh()->notifications)->toHaveCount(1);
});

// ─── Hash envelope ───────────────────────────────────────────────────────────

it('covers snapshot_schema_version in the version hash', function () {
    $service = app(RmeRecordVersionService::class);
    $snapshot = '{"fields":{}}';

    expect($service->hashFor(RmeRecordVersion::SUBJECT_MEDICAL_RECORD, 1, 1, RmeRecordVersion::KIND_FINAL, null, $snapshot, 1))
        ->not->toBe($service->hashFor(RmeRecordVersion::SUBJECT_MEDICAL_RECORD, 1, 1, RmeRecordVersion::KIND_FINAL, null, $snapshot, 2));

    // A row whose stored schema version is not the one its hash covers fails the chain.
    $sheet = m3apSheet();
    $hash = $service->hashFor(RmeRecordVersion::SUBJECT_MEDICAL_RECORD, $sheet->id, 1, RmeRecordVersion::KIND_BASELINE, null, $snapshot, 1);
    DB::table('trx_rme_record_versions')->insert([
        'subject_type' => RmeRecordVersion::SUBJECT_MEDICAL_RECORD,
        'subject_id' => $sheet->id,
        'version' => 1,
        'kind' => RmeRecordVersion::KIND_BASELINE,
        'snapshot_schema_version' => 2,
        'snapshot' => $snapshot,
        'previous_hash' => null,
        'hash' => $hash,
        'created_at' => now(),
    ]);

    expect($service->verifyChain($sheet->fresh()))->toBeFalse();
});

// ─── Lock order (PostgreSQL) ─────────────────────────────────────────────────

it('locks the visit, then the sheet, then the amendment before applying (PostgreSQL)', function () {
    $sheet = m3apSheet();
    $amendment = m3apRequest($sheet);
    $sql = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$sql): void {
        $sql[] = strtolower($q->sql);
    });

    m3apService()->approve($amendment, $this->supervisor);

    $at = fn (callable $match): int|false => collect($sql)->search(fn ($s) => $match($s));
    $visit = $at(fn ($s) => str_contains($s, 'from "trx_clinic_visits"') && str_contains($s, 'for no key update'));
    $record = $at(fn ($s) => str_contains($s, 'from "trx_medical_records"') && str_contains($s, 'for no key update'));
    $row = $at(fn ($s) => str_contains($s, 'from "trx_rme_amendments"') && str_contains($s, 'for update'));
    $write = $at(fn ($s) => str_starts_with($s, 'update "trx_medical_records"'));

    // Each statement is found (search() answers false, never 0, when it is not).
    expect($visit)->toBeInt()
        ->and($record)->toBeInt()
        ->and($row)->toBeInt()
        ->and($write)->toBeInt()
        ->and($visit)->toBeLessThan($record)
        ->and($record)->toBeLessThan($row)
        ->and($row)->toBeLessThan($write);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL row locks');

// ─── Review fixes (M3.3 PR5 review) ──────────────────────────────────────────

/** An APPROVED SATUSEHAT candidate of the sheet's (completed) visit, pinned to today's source. */
function m3apApprovedCandidate(MedicalRecord $sheet): SatusehatCandidate
{
    $candidate = app(SatusehatCandidateService::class)->generateForVisit($sheet->clinicVisit()->firstOrFail());
    expect($candidate)->not->toBeNull();
    $candidate->forceFill([
        'review_status' => SatusehatCandidate::REVIEW_APPROVED,
        'approved_source_hash' => $candidate->source_hash,
        'approved_dental_source_hash' => $candidate->dental_source_hash,
        'revoked_at' => null,
    ])->save();

    return $candidate->fresh();
}

it('revokes an approved SATUSEHAT candidate after a diagnosis-only apply, as a diagnosis write does (MEDIUM)', function () {
    $sheet = m3apSheet();
    $candidate = m3apApprovedCandidate($sheet);
    $master = ClinicalDiagnosis::factory()->create(['code' => 'K02.9', 'status' => ClinicalDiagnosis::STATUS_ACTIVE]);

    m3apService()->approve(m3apRequest($sheet, ['diagnoses' => ['add' => [['clinical_diagnosis_id' => $master->id, 'diagnosis_role' => 'secondary']]]]), $this->supervisor);

    $fresh = $candidate->fresh();
    expect($fresh->readiness_status)->toBe(SatusehatCandidate::READINESS_SOURCE_CHANGED)
        ->and($fresh->review_status)->toBe(SatusehatCandidate::REVIEW_PENDING)
        ->and($fresh->revoked_at)->not->toBeNull()
        ->and($fresh->source_hash)->not->toBe($candidate->approved_source_hash);
});

it('revokes an approved SATUSEHAT candidate after a notes apply, as before (MEDIUM)', function () {
    $sheet = m3apSheet();
    $candidate = m3apApprovedCandidate($sheet);

    m3apService()->approve(m3apRequest($sheet, ['fields' => ['notes' => 'm3ap catatan dikoreksi']]), $this->supervisor);

    expect($candidate->fresh()->readiness_status)->toBe(SatusehatCandidate::READINESS_SOURCE_CHANGED)
        ->and($candidate->fresh()->revoked_at)->not->toBeNull();
});

it('answers a malformed diagnosis row with its refusal, never a server error (LOW)', function () {
    $sheet = m3apSheet();
    $onSheet = m3apDiagnosis($sheet, 'K04.7', MedicalRecordDiagnosis::ROLE_SECONDARY);
    $master = ClinicalDiagnosis::factory()->create(['code' => 'K04.8', 'status' => ClinicalDiagnosis::STATUS_ACTIVE]);
    $add = fn (array $row) => m3apRequest($sheet, ['diagnoses' => ['add' => [$row]]]);

    expect(m3apRefusal(fn () => $add(['clinical_diagnosis_id' => $master->id, 'diagnosis_role' => ['primary']])))->toContain(RmeAmendmentService::MESSAGE_DIAGNOSIS_ROLE)
        ->and(m3apRefusal(fn () => $add(['clinical_diagnosis_id' => $master->id, 'diagnosis_role' => 'secondary', 'notes' => ['x']])))->toContain(RmeAmendmentService::MESSAGE_DIAGNOSIS_ROW)
        ->and(m3apRefusal(fn () => $add(['clinical_diagnosis_id' => null, 'diagnosis_role' => 'secondary', 'notes' => ['x']])))->toContain(RmeAmendmentService::MESSAGE_DIAGNOSIS_ROW)
        // (int) '<id>abc' would name a real master; the digits-only check refuses it.
        ->and(m3apRefusal(fn () => $add(['clinical_diagnosis_id' => $master->id.'abc', 'diagnosis_role' => 'secondary'])))->toContain(RmeAmendmentService::MESSAGE_DIAGNOSIS_ROW)
        ->and(m3apRefusal(fn () => m3apRequest($sheet, ['diagnoses' => ['remove' => [[$onSheet->id]]]])))->toContain(RmeAmendmentService::MESSAGE_DIAGNOSIS_NOT_ON_SHEET)
        ->and(RmeAmendment::query()->count())->toBe(0);
});

it('does not count surrounding whitespace as a change (LOW)', function () {
    $sheet = m3apSheet();
    DB::table('trx_medical_records')->where('id', $sheet->id)->update(['plan' => "m3ap rencana awal\n"]);

    expect(m3apRefusal(fn () => m3apRequest($sheet->fresh(), ['fields' => ['assessment' => 'm3ap pulpitis   ', 'plan' => 'm3ap rencana awal']])))->toContain(RmeAmendmentService::MESSAGE_NO_CHANGE)
        ->and(RmeAmendment::query()->count())->toBe(0);
});

it('refuses at apply a proposal the record no longer matches, and a record that is no longer final', function () {
    $sheet = m3apSheet();
    $amendment = m3apRequest($sheet, ['fields' => ['assessment' => 'm3ap pulpitis ireversibel', 'plan' => 'm3ap rencana baru']]);

    // Out of band, one of the two changes is already on the record: what would be
    // applied is no longer what was approved.
    DB::table('trx_medical_records')->where('id', $sheet->id)->update(['plan' => 'm3ap rencana baru']);
    expect(m3apRefusal(fn () => m3apService()->approve($amendment, $this->supervisor)))->toContain(RmeAmendmentService::MESSAGE_PROPOSAL_CHANGED)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);

    DB::table('trx_medical_records')->where('id', $sheet->id)->update(['status' => MedicalRecord::STATUS_DRAFT]);
    expect(m3apRefusal(fn () => m3apService()->approve($amendment, $this->supervisor)))->toContain(RmeAmendmentService::MESSAGE_NOT_FINAL)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);
});

it('records an added diagnosis as diagnosed by the requester and created by the approver', function () {
    $sheet = m3apSheet();
    $master = ClinicalDiagnosis::factory()->create(['code' => 'K02.5', 'status' => ClinicalDiagnosis::STATUS_ACTIVE]);
    m3apService()->approve(m3apRequest($sheet, ['diagnoses' => ['add' => [['clinical_diagnosis_id' => $master->id, 'diagnosis_role' => 'secondary']]]]), $this->supervisor);

    $added = MedicalRecordDiagnosis::query()->where('medical_record_id', $sheet->id)->sole();
    expect((int) $added->diagnosed_by)->toBe((int) $this->doctorUser->id)
        ->and((int) $added->created_by)->toBe((int) $this->supervisor->id);
});

it('writes the diagnosis part of an apply only inside a transaction and only on a final sheet (INFO)', function () {
    $repository = app(RmeAmendmentRepositoryInterface::class);
    $sheet = m3apSheet();
    $row = ['clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create(['status' => ClinicalDiagnosis::STATUS_ACTIVE])->id, 'diagnosis_role' => 'secondary', 'notes' => null];

    // Outside a transaction: RefreshDatabase wraps the test in one on the default
    // connection, so a second connection (none open) stands for such a caller —
    // the technique of the PR3 baseline test. The guard runs before any query.
    $default = config('database.default');
    config()->set('database.connections.m3ap_plain', config('database.connections.'.$default));
    config()->set('database.default', 'm3ap_plain');
    DB::purge('m3ap_plain');
    try {
        expect(DB::transactionLevel())->toBe(0)
            ->and(fn () => $repository->applySheetDiagnoses($sheet, [$row], [], $this->doctorUser->id, $this->supervisor->id))->toThrow(LogicException::class);
    } finally {
        config()->set('database.default', $default);
        DB::purge('m3ap_plain');
    }

    $draft = m3apSheet();
    $draft->forceFill(['status' => MedicalRecord::STATUS_DRAFT, 'finalized_at' => null, 'finalized_by' => null])->save();
    expect(fn () => DB::transaction(fn () => $repository->applySheetDiagnoses($draft->fresh(), [$row], [], $this->doctorUser->id, $this->supervisor->id)))->toThrow(LogicException::class)
        ->and(MedicalRecordDiagnosis::query()->count())->toBe(0);
});

it('still refreshes the candidate after a field apply when the sheet observer is switched off (MEDIUM)', function () {
    $sheet = m3apSheet();
    $candidate = m3apApprovedCandidate($sheet);
    config()->set('satusehat.candidate.auto_generate', false);

    m3apService()->approve(m3apRequest($sheet, ['fields' => ['notes' => 'm3ap catatan dikoreksi']]), $this->supervisor);

    expect($candidate->fresh()->readiness_status)->toBe(SatusehatCandidate::READINESS_SOURCE_CHANGED)
        ->and($candidate->fresh()->revoked_at)->not->toBeNull();
});

// ─── Re-review: the hash chain is verified where it matters ─────────────────

/** Tamper with the stored text of version 1 (bypassing the model and, on PostgreSQL, the immutability trigger). */
function m3apTamperVersionOne(MedicalRecord $sheet): void
{
    $pg = DB::connection()->getDriverName() === 'pgsql';
    if ($pg) {
        DB::statement('ALTER TABLE trx_rme_record_versions DISABLE TRIGGER USER');
    }

    try {
        DB::table('trx_rme_record_versions')
            ->where('subject_type', RmeRecordVersion::SUBJECT_MEDICAL_RECORD)
            ->where('subject_id', $sheet->id)
            ->where('version', 1)
            ->update(['snapshot' => json_encode(['tampered' => 'm3ap isi diubah diam-diam'])]);
    } finally {
        if ($pg) {
            DB::statement('ALTER TABLE trx_rme_record_versions ENABLE TRIGGER USER');
        }
    }
}

it('refuses to apply onto a broken version chain and writes nothing (re-review)', function () {
    $sheet = m3apSheet();
    $amendment = m3apRequest($sheet);
    $before = $sheet->fresh()->assessment;
    m3apTamperVersionOne($sheet);

    expect(m3apRefusal(fn () => m3apService()->approve($amendment, $this->supervisor)))->toContain(RmeAmendmentService::MESSAGE_CHAIN_BROKEN)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and($sheet->fresh()->assessment)->toBe($before)
        ->and(m3apVersions($sheet)->count())->toBe(1);
});

it('shows on the version page whether the chain verifies (re-review)', function () {
    $sheet = m3apSheet();
    m3apService()->approve(m3apRequest($sheet), $this->supervisor);

    $ok = m3apService()->versionDetailFor($this->supervisor, $sheet->fresh(), 2);
    expect($ok['chainVerified'])->toBeTrue();

    $this->actingAs($this->supervisor)
        ->get(route('rme.amendments.versions.sheet.show', [$sheet->id, 2]))
        ->assertOk()
        ->assertDontSee('data-version-chain-broken', false);

    m3apTamperVersionOne($sheet);

    expect(m3apService()->versionDetailFor($this->supervisor, $sheet->fresh(), 2)['chainVerified'])->toBeFalse();
    $this->actingAs($this->supervisor)
        ->get(route('rme.amendments.versions.sheet.show', [$sheet->id, 2]))
        ->assertOk()
        ->assertSee('data-version-chain-broken', false);
});

it('withholds "Setujui dan Terapkan" and warns on the detail page when the chain is broken (re-review 2)', function () {
    $sheet = m3apSheet();
    $amendment = m3apRequest($sheet);

    $page = fn () => $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $amendment))->assertOk();

    expect(m3apService()->detailFor($amendment, $this->supervisor)['actions'])
        ->toMatchArray(['can_approve' => true, 'chain_broken' => false]);
    $page()->assertSee('data-amendment-approve', false)
        ->assertDontSee('data-version-chain-broken', false);

    m3apTamperVersionOne($sheet);

    expect(m3apService()->detailFor($amendment->fresh(), $this->supervisor)['actions'])
        ->toMatchArray(['can_approve' => false, 'can_reject' => true, 'chain_broken' => true]);
    $page()->assertDontSee('data-amendment-approve', false)
        ->assertSee('data-version-chain-broken', false)
        ->assertSee('data-amendment-reject', false);
});
