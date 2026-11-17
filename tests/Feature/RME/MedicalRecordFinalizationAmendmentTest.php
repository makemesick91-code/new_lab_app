<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR4 — supervised amendment of a final sheet
|--------------------------------------------------------------------------
|
| Rule 240 §6 (owner decision 2026-11-17, defaults 1, 2, 3, 5, 6). A final sheet is
| corrected only through a supervised amendment: the doctor of record or the
| finalizer REQUESTS it with a written reason and the proposed change (SOAP
| fields, notes, structured diagnoses), against the latest version of the sheet
| (a baseline version 1 is written for a sheet finalized before rule 240); the
| requester may CANCEL it while pending; an approver (Supervisor RME) who is
| neither the requester nor the doctor of record REJECTS it with a written
| reason. Approve-and-apply is M3.3 PR5. One pending amendment per sheet. Each
| rule is decided in the service inside the transaction, so it binds Super
| Admin; PostgreSQL backs maker ≠ checker with a CHECK and "one pending" with a
| partial unique index. The sheet itself stays locked while a request is
| pending. Audit rows carry ids and the reason's length only.
|
| Selected by the existing `MedicalRecordFinalization` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `m33a` is unique because the critical gate loads every suite into one
| process.
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
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use App\Modules\RmeAmendment\Interfaces\RmeAmendmentRepositoryInterface;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\RmeAmendment\Repositories\RmeAmendmentRepository;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Modules\RmeAmendment\Services\RmeRecordVersionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

// The patient-merge regression borrows the PatientMerge suite's helpers; a parallel
// worker loads only this file, so require them here (ParallelTestHelperResolutionContractTest).
require_once __DIR__.'/../PatientMerge/helpers.php';

beforeEach(function () {
    seedAccessControl();

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    $this->supervisor = userInRole('Supervisor RME');
});

function m33aService(): RmeAmendmentService
{
    return app(RmeAmendmentService::class);
}

/** A sheet of a finished visit, finalized by `$finalizer` (the doctor of record by default) — no version yet. */
function m33aSheet(?User $finalizer = null, string $status = MedicalRecord::STATUS_FINAL): MedicalRecord
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
        'subjective' => "m33a keluhan nyeri\ngigi 36",
        'objective' => 'm33a karies profunda',
        'assessment' => 'm33a pulpitis',
        'plan' => 'm33a perawatan saluran akar',
        'notes' => null,
    ]);

    if ($status === MedicalRecord::STATUS_FINAL) {
        $sheet->forceFill([
            'status' => MedicalRecord::STATUS_FINAL,
            'finalized_at' => now()->subDay(),
            'finalized_by' => ($finalizer ?? test()->doctorUser)->id,
        ])->save();
    }

    return $sheet->fresh();
}

function m33aDiagnosis(MedicalRecord $sheet, string $code, string $role, string $status = ClinicalDiagnosis::STATUS_ACTIVE): MedicalRecordDiagnosis
{
    return MedicalRecordDiagnosis::create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $sheet->clinic_visit_id,
        'branch_id' => $sheet->branch_id,
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create(['code' => $code, 'status' => $status])->id,
        'diagnosis_role' => $role,
        'diagnosed_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function m33aProposal(array $fields = ['assessment' => 'm33a pulpitis ireversibel'], array $diagnoses = []): array
{
    return array_filter(['fields' => $fields, 'diagnoses' => $diagnoses], fn ($part) => $part !== []);
}

function m33aReason(): string
{
    return 'm33a salah ketik diagnosis kerja pada lembar final';
}

function m33aRequest(MedicalRecord $sheet, ?User $by = null, ?array $proposal = null): RmeAmendment
{
    return m33aService()->request($sheet, $by ?? test()->doctorUser, m33aReason(), $proposal ?? m33aProposal());
}

function m33aRefusal(callable $act): string
{
    try {
        $act();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->implode(' | ');
    }

    test()->fail('Expected a refusal, the action succeeded.');
}

/** Another doctor who has treated the patient (so the record is within their read scope). */
function m33aTreatingDoctor(): User
{
    $user = userInRole('Doctor');
    $doctor = Doctor::factory()->create(['user_id' => $user->id, 'branch_id' => test()->branch->id]);
    ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => test()->room->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);

    return $user->fresh();
}

function m33aSuperAdmin(): User
{
    return userInRole('Super Admin');
}

/** @return list<AuditLog> */
function m33aAudits(string $action): array
{
    return AuditLog::query()->where('entity_type', RmeAmendmentService::AUDIT_ENTITY)->where('action', $action)->get()->all();
}

// ─── Request ─────────────────────────────────────────────────────────────────

it('lets the doctor of record request an amendment: pending, only the changed fields, a baseline version as base, the sheet untouched', function () {
    $sheet = m33aSheet();

    // An unchanged textarea comes back with CRLF line endings; that is not a change.
    $amendment = m33aRequest($sheet, proposal: m33aProposal([
        'subjective' => "m33a keluhan nyeri\r\ngigi 36",
        'objective' => 'm33a karies profunda',
        'assessment' => 'm33a pulpitis ireversibel',
    ]));

    expect($amendment->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and($amendment->subject_type)->toBe(RmeRecordVersion::SUBJECT_MEDICAL_RECORD)
        ->and((int) $amendment->subject_id)->toBe((int) $sheet->id)
        ->and((int) $amendment->branch_id)->toBe((int) $sheet->branch_id)
        ->and((int) $amendment->requested_by)->toBe((int) $this->doctorUser->id)
        ->and($amendment->decided_by)->toBeNull()
        ->and($amendment->proposed_content)->toBe(['fields' => ['assessment' => 'm33a pulpitis ireversibel']]);

    // Finalized before rule 240 → its baseline version 1 is the base.
    $versions = RmeRecordVersion::query()->where('subject_type', RmeRecordVersion::SUBJECT_MEDICAL_RECORD)->where('subject_id', $sheet->id)->get();
    expect($versions)->toHaveCount(1)
        ->and($versions->first()->kind)->toBe(RmeRecordVersion::KIND_BASELINE)
        ->and((int) $amendment->base_version)->toBe(1)
        ->and((int) $amendment->base_version_id)->toBe((int) $versions->first()->id);

    // A request changes nothing in the record.
    $fresh = $sheet->fresh();
    expect($fresh->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($fresh->assessment)->toBe('m33a pulpitis');
});

it('takes the latest existing version as the base and writes no baseline when version 1 exists', function () {
    $sheet = m33aSheet();
    $v1 = DB::transaction(fn () => app(RmeRecordVersionService::class)->recordFinal($sheet, (int) $this->doctorUser->id));

    $amendment = m33aRequest($sheet);

    expect((int) $amendment->base_version)->toBe(1)
        ->and((int) $amendment->base_version_id)->toBe((int) $v1->id)
        ->and(RmeRecordVersion::query()->where('subject_id', $sheet->id)->where('subject_type', RmeRecordVersion::SUBJECT_MEDICAL_RECORD)->count())->toBe(1);
});

it('lets the finalizer request too, and refuses another doctor and a Super Admin who is neither', function () {
    $finalizerUser = m33aTreatingDoctor();
    $sheet = m33aSheet($finalizerUser);

    // Another doctor who treated the same patient is still neither.
    $other = m33aTreatingDoctor();

    expect(m33aRefusal(fn () => m33aRequest($sheet, $other)))->toContain(RmeAmendmentService::MESSAGE_NOT_REQUESTER)
        ->and(m33aRefusal(fn () => m33aRequest($sheet, m33aSuperAdmin())))->toContain(RmeAmendmentService::MESSAGE_NOT_REQUESTER)
        ->and(RmeAmendment::query()->count())->toBe(0);

    $byFinalizer = m33aRequest($sheet, $finalizerUser);
    expect((int) $byFinalizer->requested_by)->toBe((int) $finalizerUser->id);
});

it('lets a Super Admin who finalized the sheet request, by identity and not by bypass', function () {
    $superAdmin = m33aSuperAdmin();
    $sheet = m33aSheet($superAdmin);

    expect((int) m33aRequest($sheet, $superAdmin)->requested_by)->toBe((int) $superAdmin->id);
});

it('refuses an account without the request permission even when it finalized the sheet', function () {
    $sheet = m33aSheet($this->supervisor);

    expect(m33aRefusal(fn () => m33aRequest($sheet, $this->supervisor)))->toContain(RmeAmendmentService::MESSAGE_NO_REQUEST_PERMISSION)
        ->and(RmeAmendment::query()->count())->toBe(0);
});

it('refuses to amend a draft sheet and writes nothing', function () {
    $sheet = m33aSheet(status: MedicalRecord::STATUS_DRAFT);

    expect(m33aRefusal(fn () => m33aRequest($sheet)))->toContain(RmeAmendmentService::MESSAGE_NOT_FINAL)
        ->and(RmeAmendment::query()->count())->toBe(0)
        ->and(RmeRecordVersion::query()->count())->toBe(0);
});

it('bounds the reason to 10–1000 characters', function () {
    $sheet = m33aSheet();

    expect(m33aRefusal(fn () => m33aService()->request($sheet, $this->doctorUser, '  sembilan ', m33aProposal())))->toContain(RmeAmendmentService::MESSAGE_REASON)
        ->and(m33aRefusal(fn () => m33aService()->request($sheet, $this->doctorUser, str_repeat('a', 1001), m33aProposal())))->toContain(RmeAmendmentService::MESSAGE_REASON)
        ->and(RmeAmendment::query()->count())->toBe(0);

    $amendment = m33aService()->request($sheet, $this->doctorUser, str_repeat('b', 10), m33aProposal());
    expect($amendment->reason)->toBe(str_repeat('b', 10));
});

it('refuses a proposal that changes nothing, names a diagnosis of another sheet, an inactive master, or a second primary', function () {
    $sheet = m33aSheet();
    $primary = m33aDiagnosis($sheet, 'K04.0', MedicalRecordDiagnosis::ROLE_PRIMARY);
    $foreign = m33aDiagnosis(m33aSheet(), 'K05.1', MedicalRecordDiagnosis::ROLE_PRIMARY);
    $inactive = ClinicalDiagnosis::factory()->create(['code' => 'K99.9', 'status' => ClinicalDiagnosis::STATUS_DEPRECATED]);
    $active = ClinicalDiagnosis::factory()->create(['code' => 'K02.1', 'status' => ClinicalDiagnosis::STATUS_ACTIVE]);

    expect(m33aRefusal(fn () => m33aRequest($sheet, proposal: ['fields' => ['assessment' => 'm33a pulpitis']])))->toContain(RmeAmendmentService::MESSAGE_NO_CHANGE)
        ->and(m33aRefusal(fn () => m33aRequest($sheet, proposal: ['diagnoses' => ['remove' => [$foreign->id]]])))->toContain(RmeAmendmentService::MESSAGE_DIAGNOSIS_NOT_ON_SHEET)
        ->and(m33aRefusal(fn () => m33aRequest($sheet, proposal: ['diagnoses' => ['add' => [['clinical_diagnosis_id' => $inactive->id, 'diagnosis_role' => 'secondary']]]])))->toContain(RmeAmendmentService::MESSAGE_MASTER_INACTIVE)
        ->and(m33aRefusal(fn () => m33aRequest($sheet, proposal: ['diagnoses' => ['add' => [['clinical_diagnosis_id' => $active->id, 'diagnosis_role' => 'primary']]]])))->toContain(RmeAmendmentService::MESSAGE_SECOND_PRIMARY)
        ->and(m33aRefusal(fn () => m33aRequest($sheet, proposal: ['fields' => ['patient_id' => '1']])))->toContain(RmeAmendmentService::MESSAGE_UNKNOWN_PART)
        ->and(RmeAmendment::query()->count())->toBe(0);

    // Replacing the primary is one proposal: remove the old one, add the new one.
    $amendment = m33aRequest($sheet, proposal: ['diagnoses' => [
        'remove' => [$primary->id],
        'add' => [['clinical_diagnosis_id' => $active->id, 'diagnosis_role' => 'primary', 'notes' => 'm33a koreksi']],
    ]]);

    expect($amendment->fresh()->proposed_content)->toBe(['diagnoses' => [
        'add' => [['clinical_diagnosis_id' => (int) $active->id, 'diagnosis_role' => 'primary', 'notes' => 'm33a koreksi']],
        'remove' => [(int) $primary->id],
    ]]);

    // The checker sees the version's diagnoses beside the proposal.
    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSeeInOrder(['Diusulkan dihapus', 'K04.0', 'Diusulkan ditambah', 'K02.1'])
        ->assertSee('data-amendment-reject', false);
});

it('keeps one pending amendment per sheet, and a cancelled one frees the slot', function () {
    $sheet = m33aSheet();
    $first = m33aRequest($sheet);

    expect(m33aRefusal(fn () => m33aRequest($sheet)))->toContain(RmeAmendmentService::MESSAGE_PENDING_EXISTS)
        ->and(RmeAmendment::query()->count())->toBe(1);

    m33aService()->cancel($first, $this->doctorUser);

    $second = m33aRequest($sheet);
    expect($second->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and((int) $second->base_version)->toBe(1);
});

it('turns the partial unique index into a clean refusal inside a savepoint when the pending check is raced', function () {
    $sheet = m33aSheet();
    m33aRequest($sheet);

    // The race: the in-transaction check saw no pending row, the index still did.
    app()->bind(RmeAmendmentRepositoryInterface::class, fn () => new class extends RmeAmendmentRepository
    {
        public function pendingFor(string $subjectType, int $subjectId): ?RmeAmendment
        {
            return null;
        }
    });

    expect(m33aRefusal(fn () => m33aRequest($sheet)))->toContain(RmeAmendmentService::MESSAGE_PENDING_EXISTS);

    // The connection is still usable after the refusal (the savepoint was rolled back).
    expect(RmeAmendment::query()->where('status', RmeAmendment::STATUS_PENDING)->count())->toBe(1)
        ->and(m33aAudits(RmeAmendmentService::AUDIT_REQUESTED))->toHaveCount(1);
});

// ─── Cancel ──────────────────────────────────────────────────────────────────

it('lets only the requester cancel, only while pending, Super Admin included', function () {
    $sheet = m33aSheet();
    $amendment = m33aRequest($sheet);
    $other = userInRole('Doctor');

    foreach ([$other, $this->supervisor, m33aSuperAdmin()] as $actor) {
        expect(m33aRefusal(fn () => m33aService()->cancel($amendment, $actor)))->toContain(RmeAmendmentService::MESSAGE_CANCEL_NOT_REQUESTER);
    }

    $cancelled = m33aService()->cancel($amendment, $this->doctorUser);
    expect($cancelled->status)->toBe(RmeAmendment::STATUS_CANCELLED)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->decided_by)->toBeNull();

    expect(m33aRefusal(fn () => m33aService()->cancel($amendment->fresh(), $this->doctorUser)))->toContain(RmeAmendmentService::MESSAGE_NOT_PENDING);

    $audit = m33aAudits(RmeAmendmentService::AUDIT_CANCELLED);
    expect($audit)->toHaveCount(1)
        ->and((int) $audit[0]->entity_id)->toBe((int) $amendment->id)
        ->and((int) $audit[0]->performed_by)->toBe((int) $this->doctorUser->id);
});

// ─── Reject ──────────────────────────────────────────────────────────────────

it('lets a Supervisor RME reject with a written reason', function () {
    $sheet = m33aSheet();
    $amendment = m33aRequest($sheet);

    expect(m33aRefusal(fn () => m33aService()->reject($amendment, $this->supervisor, 'pendek')))->toContain(RmeAmendmentService::MESSAGE_DECISION_REASON);

    $rejected = m33aService()->reject($amendment, $this->supervisor, 'm33a usulan tidak sesuai catatan klinis');

    expect($rejected->status)->toBe(RmeAmendment::STATUS_REJECTED)
        ->and((int) $rejected->decided_by)->toBe((int) $this->supervisor->id)
        ->and($rejected->decided_at)->not->toBeNull()
        ->and($rejected->decision_reason)->toBe('m33a usulan tidak sesuai catatan klinis')
        ->and($sheet->fresh()->assessment)->toBe('m33a pulpitis');

    expect(m33aRefusal(fn () => m33aService()->reject($rejected, $this->supervisor, 'm33a keputusan kedua kali')))->toContain(RmeAmendmentService::MESSAGE_NOT_PENDING)
        ->and(m33aRefusal(fn () => m33aService()->cancel($rejected, $this->doctorUser)))->toContain(RmeAmendmentService::MESSAGE_NOT_PENDING);
});

it('never lets the requester or the doctor of record decide, even holding the approve permission', function () {
    // The finalizer (another doctor) requests; the doctor of record is also a Supervisor RME.
    $finalizer = m33aTreatingDoctor();
    $sheet = m33aSheet($finalizer);
    $amendment = m33aRequest($sheet, $finalizer);

    $finalizer->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);
    $this->doctorUser->assignRole(Role::findByName('Supervisor RME', 'web'));

    expect(m33aRefusal(fn () => m33aService()->reject($amendment, $finalizer->fresh(), 'm33a menolak usulan sendiri')))->toContain(RmeAmendmentService::MESSAGE_SELF_DECISION)
        ->and(m33aRefusal(fn () => m33aService()->reject($amendment, $this->doctorUser->fresh(), 'm33a dokter penanggung jawab')))->toContain(RmeAmendmentService::MESSAGE_DOCTOR_OF_RECORD)
        ->and(m33aRefusal(fn () => m33aService()->reject($amendment, userInRole('Doctor'), 'm33a dokter lain tanpa izin')))->toContain(RmeAmendmentService::MESSAGE_NO_APPROVE_PERMISSION)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);
});

it('binds Super Admin: never on their own request, otherwise through Gate::before', function () {
    $superAdmin = m33aSuperAdmin();
    $own = m33aRequest(m33aSheet($superAdmin), $superAdmin);

    expect(m33aRefusal(fn () => m33aService()->reject($own, $superAdmin, 'm33a super admin menolak sendiri')))->toContain(RmeAmendmentService::MESSAGE_SELF_DECISION);

    $theirs = m33aRequest(m33aSheet());
    $rejected = m33aService()->reject($theirs, $superAdmin, 'm33a super admin menolak usulan dokter');
    expect($rejected->status)->toBe(RmeAmendment::STATUS_REJECTED);
});

// ─── The lock still holds; audit carries ids only ────────────────────────────

it('keeps the sheet locked while an amendment is pending', function () {
    $sheet = m33aSheet();
    m33aRequest($sheet);

    // A live, consented encounter of the patient, so the authoring authority
    // passes and the lock itself answers.
    rmeActiveConsentedEncounter(ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => $this->room->id,
    ]));
    $this->actingAs(rmeClinicalAuthor(['view_clinic_visits', 'manage_clinic_visits']));
    expect(m33aRefusal(fn () => app(MedicalRecordService::class)->updateDraft($sheet->fresh(), ['assessment' => 'm33a langsung diubah'])))->toContain(MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED)
        ->and($sheet->fresh()->assessment)->toBe('m33a pulpitis');
});

it('writes audit rows with ids and the reason length only — never the reason or the proposed content', function () {
    $sheet = m33aSheet();
    $amendment = m33aRequest($sheet);
    m33aService()->reject($amendment, $this->supervisor, 'm33a rahasia alasan penolakan');

    $requested = m33aAudits(RmeAmendmentService::AUDIT_REQUESTED)[0];
    $rejected = m33aAudits(RmeAmendmentService::AUDIT_REJECTED)[0];

    // Rule 240 §6.11 — `visit_ptm_state` is the amended visit's PTM as a code, no personal data.
    expect(array_keys($requested->new_values))->toEqualCanonicalizing(['subject_type', 'subject_id', 'branch_id', 'base_version', 'base_version_id', 'status', 'reason_length', 'visit_ptm_state'])
        ->and($requested->new_values['reason_length'])->toBe(mb_strlen(m33aReason()))
        ->and($requested->new_values['visit_ptm_state'])->toBeIn(['executed', 'patient_only', 'none', 'voided'])
        ->and(array_keys($rejected->new_values))->toEqualCanonicalizing(['subject_type', 'subject_id', 'branch_id', 'status', 'requested_by', 'decision_reason_length']);

    $all = json_encode(AuditLog::query()->where('entity_type', RmeAmendmentService::AUDIT_ENTITY)->get()->toArray());
    expect($all)->not->toContain('m33a salah ketik')
        ->and($all)->not->toContain('m33a rahasia')
        ->and($all)->not->toContain('ireversibel');
});

// ─── PostgreSQL backstops ────────────────────────────────────────────────────

it('refuses decided_by = requested_by on a raw insert (PostgreSQL CHECK)', function () {
    $sheet = m33aSheet();
    $amendment = m33aRequest($sheet);
    $row = collect($amendment->getAttributes())->except(['id'])->all();
    $row['status'] = RmeAmendment::STATUS_REJECTED;
    $row['decided_by'] = $row['requested_by'];
    $row['decided_at'] = now();
    $row['decision_reason'] = 'm33a raw insert';

    expect(fn () => DB::table('trx_rme_amendments')->insert($row))
        ->toThrow(QueryException::class, 'trx_rme_amd_maker_checker_chk');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL CHECK constraint');

it('names every constraint of the table within 63 characters (PostgreSQL)', function () {
    $names = collect(DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'trx_rme_amendments'::regclass"))->pluck('conname')
        ->merge(collect(DB::select("SELECT indexname AS conname FROM pg_indexes WHERE tablename = 'trx_rme_amendments'"))->pluck('conname'));

    expect($names)->toContain('trx_rme_amd_one_pending_uq')
        ->and($names)->toContain('trx_rme_amd_maker_checker_chk')
        ->and($names)->toContain('trx_rme_amd_requested_by_fk')
        ->and($names->filter(fn ($n) => strlen($n) > 63))->toBeEmpty();
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL catalog');

it('locks the visit, then the sheet, then writes the amendment (PostgreSQL)', function () {
    $sheet = m33aSheet();
    $sql = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$sql): void {
        $sql[] = strtolower($q->sql);
    });

    m33aRequest($sheet);

    $at = fn (callable $match): int => (int) collect($sql)->search(fn ($s) => $match($s));
    $visit = $at(fn ($s) => str_contains($s, 'from "trx_clinic_visits"') && str_contains($s, 'for no key update'));
    $record = $at(fn ($s) => str_contains($s, 'from "trx_medical_records"') && str_contains($s, 'for no key update'));
    $insert = $at(fn ($s) => str_starts_with($s, 'insert into "trx_rme_amendments"'));

    expect($visit)->toBeGreaterThan(0)
        ->and($visit)->toBeLessThan($record)
        ->and($record)->toBeLessThan($insert);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL row locks');

// ─── Review fixes (M3.3 PR4 review) ──────────────────────────────────────────

it('never lets the finalizer decide either: a Supervisor RME or a Super Admin who finalized the sheet (HIGH-1)', function () {
    // A Supervisor RME finalized the doctor's sheet; the doctor of record requests.
    $amendment = m33aRequest(m33aSheet($this->supervisor));

    expect(m33aRefusal(fn () => m33aService()->reject($amendment, $this->supervisor, 'm33a pemfinal menolak koreksi')))->toContain(RmeAmendmentService::MESSAGE_FINALIZER)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);

    // The same for a Super Admin finalizer, whom Gate::before lets past every policy.
    $superAdmin = m33aSuperAdmin();
    $theirs = m33aRequest(m33aSheet($superAdmin));
    expect(m33aRefusal(fn () => m33aService()->reject($theirs, $superAdmin, 'm33a super admin pemfinal')))->toContain(RmeAmendmentService::MESSAGE_FINALIZER);

    // Another Supervisor RME — neither requester, doctor of record nor finalizer — may.
    expect(m33aService()->reject($amendment, userInRole('Supervisor RME'), 'm33a supervisor lain menolak')->status)->toBe(RmeAmendment::STATUS_REJECTED);
});

it('refuses unknown keys inside a diagnosis row (LOW-1)', function () {
    $sheet = m33aSheet();
    $active = ClinicalDiagnosis::factory()->create(['code' => 'K02.2', 'status' => ClinicalDiagnosis::STATUS_ACTIVE]);

    $onSheet = m33aDiagnosis($sheet, 'K02.3', MedicalRecordDiagnosis::ROLE_SECONDARY);

    expect(m33aRefusal(fn () => m33aRequest($sheet, proposal: ['diagnoses' => ['add' => [['clinical_diagnosis_id' => $active->id, 'diagnosis_role' => 'secondary', 'x_extra' => str_repeat('x', 1000)]]]])))->toContain(RmeAmendmentService::MESSAGE_UNKNOWN_PART)
        // An id of THIS sheet with trailing junk: (int) would accept it, the digits-only check does not.
        ->and(m33aRefusal(fn () => m33aRequest($sheet, proposal: ['diagnoses' => ['remove' => [$onSheet->id.'abc']]])))->toContain(RmeAmendmentService::MESSAGE_DIAGNOSIS_NOT_ON_SHEET)
        ->and(RmeAmendment::query()->count())->toBe(0);
});

it('refuses removing the last primary diagnosis on a pilot_enforced branch, as finalizing would (LOW-3)', function () {
    DiagnosisRolloutSetting::create(['branch_id' => $this->branch->id, 'mode' => DiagnosisRolloutSetting::MODE_PILOT_ENFORCED, 'reason' => 'm33a pilot cabang', 'configured_by' => $this->supervisor->id]);
    $sheet = m33aSheet();
    $primary = m33aDiagnosis($sheet, 'K04.1', MedicalRecordDiagnosis::ROLE_PRIMARY);
    $replacement = ClinicalDiagnosis::factory()->create(['code' => 'K04.2', 'status' => ClinicalDiagnosis::STATUS_ACTIVE]);

    expect(m33aRefusal(fn () => m33aRequest($sheet, proposal: ['diagnoses' => ['remove' => [$primary->id]]])))->toContain(RmeAmendmentService::MESSAGE_PRIMARY_REQUIRED)
        ->and(RmeAmendment::query()->count())->toBe(0);

    // Replacing it in the same proposal keeps the finalization gate satisfied.
    $amendment = m33aRequest($sheet, proposal: ['diagnoses' => [
        'remove' => [$primary->id],
        'add' => [['clinical_diagnosis_id' => $replacement->id, 'diagnosis_role' => 'primary']],
    ]]);
    expect($amendment->status)->toBe(RmeAmendment::STATUS_PENDING);
});

it('checks the field length only on a changed field (INFO)', function () {
    $sheet = m33aSheet();
    $sheet->forceFill(['notes' => str_repeat('n', RmeAmendmentService::SHEET_FIELD_MAX + 5)])->save();

    $amendment = m33aRequest($sheet->fresh(), proposal: ['fields' => [
        'notes' => str_repeat('n', RmeAmendmentService::SHEET_FIELD_MAX + 5),
        'assessment' => 'm33a pulpitis ireversibel',
    ]]);

    expect($amendment->proposed_content)->toBe(['fields' => ['assessment' => 'm33a pulpitis ireversibel']]);
});

it('keeps the status out of mass assignment and guards a decided row in the model (INFO, LOW-5)', function () {
    expect((new RmeAmendment(['status' => RmeAmendment::STATUS_APPLIED]))->status)->toBeNull();

    $amendment = m33aRequest(m33aSheet());
    expect(fn () => $amendment->fresh()->forceFill(['reason' => 'm33a alasan diganti diam-diam'])->save())->toThrow(LogicException::class);

    $rejected = m33aService()->reject($amendment, $this->supervisor, 'm33a usulan tidak sesuai');
    expect(fn () => $rejected->fresh()->forceFill(['status' => RmeAmendment::STATUS_PENDING])->save())->toThrow(LogicException::class)
        ->and(fn () => $rejected->fresh()->delete())->toThrow(LogicException::class)
        ->and($rejected->fresh()->status)->toBe(RmeAmendment::STATUS_REJECTED);
});

it('refuses raw edits of a request and any change of a decided row (PostgreSQL trigger, LOW-5)', function () {
    $amendment = m33aRequest(m33aSheet());

    expect(fn () => DB::transaction(fn () => DB::table('trx_rme_amendments')->where('id', $amendment->id)->update(['reason' => 'm33a alasan diganti diam-diam'])))
        ->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(fn () => DB::table('trx_rme_amendments')->where('id', $amendment->id)->update(['proposed_content' => '{"fields":{"plan":"x"}}'])))
        ->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(fn () => DB::table('trx_rme_amendments')->where('id', $amendment->id)->update(['requested_by' => $this->supervisor->id])))
        ->toThrow(QueryException::class, 'immutable');

    m33aService()->reject($amendment, $this->supervisor, 'm33a usulan tidak sesuai');

    expect(fn () => DB::transaction(fn () => DB::table('trx_rme_amendments')->where('id', $amendment->id)->update(['status' => 'pending', 'decided_by' => null, 'decided_at' => null, 'decision_reason' => null])))
        ->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(fn () => DB::table('trx_rme_amendments')->where('id', $amendment->id)->update(['decision_reason' => 'm33a alasan keputusan diubah'])))
        ->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::transaction(fn () => DB::table('trx_rme_amendments')->where('id', $amendment->id)->delete()))
        ->toThrow(QueryException::class, 'immutable')
        ->and($amendment->fresh()->decision_reason)->toBe('m33a usulan tidak sesuai');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL trigger');

it('refuses the shape of each status at the database (PostgreSQL CHECKs, LOW-5)', function () {
    $amendment = m33aRequest(m33aSheet());
    $row = collect($amendment->getAttributes())->except(['id'])->all();
    $insert = function (array $over) use ($row): void {
        DB::transaction(fn () => DB::table('trx_rme_amendments')->insert(array_merge($row, ['status' => 'cancelled'], $over)));
    };

    m33aService()->cancel($amendment, $this->doctorUser);

    expect(fn () => $insert(['cancelled_at' => now(), 'decided_at' => now()]))->toThrow(QueryException::class, 'trx_rme_amd_cancelled_chk')
        ->and(fn () => $insert(['cancelled_at' => now(), 'decision_reason' => 'm33a alasan']))->toThrow(QueryException::class, 'trx_rme_amd_cancelled_chk')
        ->and(fn () => $insert(['status' => 'rejected', 'cancelled_at' => now(), 'decided_by' => $this->supervisor->id, 'decided_at' => now(), 'decision_reason' => 'm33a alasan tolak']))->toThrow(QueryException::class, 'trx_rme_amd_decided_chk')
        ->and(fn () => $insert(['status' => 'pending', 'decision_reason' => 'm33a alasan tolak']))->toThrow(QueryException::class, 'trx_rme_amd_pending_chk');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL CHECK constraints');

// ─── Re-review fixes (M3.3 PR4 re-review) ────────────────────────────────────

it('answers "not found" before checking the reason when the account may not see the record (INFO)', function () {
    $sheet = m33aSheet();
    $stranger = userInRole('Doctor');
    Doctor::factory()->create(['user_id' => $stranger->id, 'branch_id' => $this->branch->id]);

    expect(fn () => m33aService()->request($sheet, $stranger, 'pendek', m33aProposal()))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses a TRUNCATE of the amendment table (PostgreSQL trigger)', function () {
    m33aRequest(m33aSheet());

    // Since M3.3 PR6 (rule 240 §8) `trx_medical_record_handwriting_pages.rme_amendment_id`
    // references this table, so a plain TRUNCATE is refused by PostgreSQL's foreign-key
    // rule before any trigger; CASCADE gets past that rule and must still be refused
    // by the trigger (as for the version table, rule 240 §6.1).
    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE trx_rme_amendments')))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE trx_rme_amendments CASCADE')))
        ->toThrow(QueryException::class, 'immutable')
        ->and(RmeAmendment::query()->count())->toBe(1);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL trigger');

// ─── Patient merge (M3.3 PR4, merge regression) ──────────────────────────────

it('lets a patient with an amendment be merged; the amendment follows the surviving patient through its visit', function () {
    $survivor = pmPatient(['name' => 'M33A Pasien Utama'], $this->branch);
    $duplicate = pmPatient(['name' => 'M33A Pasien Ganda'], $this->branch);
    $this->patient = $duplicate;
    $amendment = m33aRequest(m33aSheet());

    $merged = pmMerge(pmSubmittedCase($survivor, $duplicate, pmUser('Supervisor RME'), $survivor), pmUser('Supervisor RME'));

    $fresh = $amendment->fresh(['clinicVisit.patient']);
    expect($merged->status)->toBe(PatientMergeStatus::COMPLETED)
        ->and((int) $fresh->clinicVisit->patient_id)->toBe((int) $survivor->id)
        ->and($fresh->clinicVisit->patient->name)->toBe('M33A Pasien Utama')
        ->and(Schema::hasColumn('trx_rme_amendments', 'patient_id'))->toBeFalse();
});
