<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Services\ActiveEncounterResolver;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Support\FdiToothNumbers;
use App\Modules\RmeAmendment\Interfaces\RmeAmendmentRepositoryInterface;
use App\Modules\RmeAmendment\Interfaces\RmeRecordVersionRepositoryInterface;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §6) — the supervised
 * amendment of a final sheet or of a finalized odontogram: request, cancel,
 * reject, and the reads behind the supervisor queue, the detail page and the
 * record page's "Amandemen" block. Approve-and-apply is M3.3 PR5.
 *
 * Who (owner decisions of 2026-11-17, plan defaults 1, 2 and 6):
 * - Requester: an account holding `request_rme_amendments` that is the doctor of
 *   record (the sheet's `doctor_id`, or the chart visit's doctor, →
 *   `mst_doctors.user_id`) or the record's finalizer (`finalized_by`). Identity,
 *   not role: a Super Admin who is neither is refused.
 * - Checker: an account holding `approve_rme_amendments` (Supervisor RME; Super
 *   Admin through Gate::before) whose branch scope covers the amendment, that is
 *   not the requester and not the doctor of record — re-decided here inside the
 *   transaction, so it binds Super Admin (CG-06, CG-07); the PostgreSQL CHECK
 *   `decided_by <> requested_by` is the backstop.
 *
 * Every write locks the subject's visit (FOR NO KEY UPDATE), then the subject
 * row, then the amendment (rule 240 §1.4 extended), and re-decides on those rows.
 * The proposal never touches the record: the sheet and the chart stay locked
 * (rule 240 §1) until PR5 applies an approved amendment. Audit rows carry ids and
 * the reason's length only (CG-11); the reason and the proposal stay on the row.
 */
final class RmeAmendmentService
{
    public const PERMISSION_REQUEST = 'request_rme_amendments';

    public const PERMISSION_APPROVE = 'approve_rme_amendments';

    public const AUDIT_ENTITY = 'trx_rme_amendments';

    public const AUDIT_REQUESTED = 'RME_AMENDMENT_REQUESTED';

    public const AUDIT_CANCELLED = 'RME_AMENDMENT_CANCELLED';

    public const AUDIT_REJECTED = 'RME_AMENDMENT_REJECTED';

    /** The sheet's own text an amendment may change (rule 240 §1.1). */
    public const SHEET_FIELDS = [
        'subjective' => 'Subjektif',
        'objective' => 'Objektif',
        'assessment' => 'Asesmen',
        'plan' => 'Rencana',
        'notes' => 'Catatan',
    ];

    public const SHEET_FIELD_MAX = 10000;

    public const DIAGNOSIS_ADD_MAX = 10;

    public const MESSAGE_NOT_FINAL = 'Hanya rekam medis atau odontogram yang sudah final yang dapat diamandemen.';

    public const MESSAGE_NO_REQUEST_PERMISSION = 'Akun Anda tidak berwenang mengajukan amandemen rekam medis.';

    public const MESSAGE_NOT_REQUESTER = 'Amandemen hanya dapat diajukan oleh dokter penanggung jawab rekam medis ini atau oleh yang memfinalisasinya.';

    public const MESSAGE_PENDING_EXISTS = 'Rekam medis ini sudah memiliki permintaan amandemen yang menunggu keputusan.';

    public const MESSAGE_REASON = 'Alasan amandemen wajib diisi, 10–1000 karakter.';

    public const MESSAGE_DECISION_REASON = 'Alasan penolakan wajib diisi, 10–1000 karakter.';

    public const MESSAGE_NO_CHANGE = 'Usulan amandemen tidak mengubah apa pun pada rekam medis ini.';

    public const MESSAGE_NOT_PENDING = 'Permintaan amandemen ini sudah tidak menunggu keputusan.';

    public const MESSAGE_CANCEL_NOT_REQUESTER = 'Hanya pengaju yang dapat membatalkan permintaan amandemen ini.';

    public const MESSAGE_NO_APPROVE_PERMISSION = 'Akun Anda tidak berwenang memutuskan amandemen rekam medis.';

    public const MESSAGE_SELF_DECISION = 'Pengaju tidak dapat memutuskan permintaan amandemennya sendiri.';

    public const MESSAGE_DOCTOR_OF_RECORD = 'Dokter penanggung jawab rekam medis ini tidak dapat memutuskan amandemennya.';

    public function __construct(
        private readonly RmeAmendmentRepositoryInterface $amendments,
        private readonly RmeRecordVersionRepositoryInterface $versions,
        private readonly RmeRecordVersionService $versionService,
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
        private readonly OdontogramRepositoryInterface $odontograms,
        private readonly RmeWorkingBranchScope $branchScope,
        private readonly ActiveEncounterResolver $encounters,
        private readonly AuditLogService $auditLog,
    ) {}

    // ─── Writes ─────────────────────────────────────────────────────────────

    /**
     * File a pending amendment of a final sheet or a finalized chart, against its
     * latest version (a record finalized before rule 240 first gets its baseline
     * version 1). Nothing in the record changes.
     *
     * @param  array<string, mixed>  $proposed  sheet: `fields` (changed SOAP/notes text)
     *                                          and `diagnoses` (`add`, `remove`);
     *                                          chart: `summary_notes`, `additional_conditions`,
     *                                          `tooth_map_payload` (absent = unchanged)
     */
    public function request(MedicalRecord|Odontogram $subject, User $requester, string $reason, array $proposed): RmeAmendment
    {
        $reason = $this->boundedReason($reason, 'reason', self::MESSAGE_REASON);
        $this->assertRequester($subject, $requester);

        return DB::transaction(function () use ($subject, $requester, $reason, $proposed): RmeAmendment {
            $locked = $this->lockSubject($subject);

            // Re-decided on the locked row, binding Super Admin.
            $this->assertRequester($locked, $requester);
            [$type, $id] = $this->subjectKey($locked);

            if ($this->amendments->pendingFor($type, $id) !== null) {
                throw $this->refusal('amendment', self::MESSAGE_PENDING_EXISTS);
            }

            $content = $locked instanceof Odontogram
                ? $this->chartProposal($locked, $proposed)
                : $this->sheetProposal($locked, $proposed);

            $base = $this->versions->latestFor($type, $id)
                ?? $this->versionService->ensureBaseline($locked, (int) $requester->getKey(), $this->liveEncounterId($locked));

            try {
                // A savepoint: on PostgreSQL a unique violation aborts the
                // transaction, so it is rolled back to here and refused cleanly.
                $amendment = DB::transaction(fn (): RmeAmendment => $this->amendments->create([
                    'subject_type' => $type,
                    'subject_id' => $id,
                    'branch_id' => (int) $locked->branch_id,
                    'clinic_visit_id' => (int) $locked->clinic_visit_id,
                    'patient_id' => $this->patientId($locked),
                    'base_version_id' => (int) $base->getKey(),
                    'base_version' => (int) $base->version,
                    'status' => RmeAmendment::STATUS_PENDING,
                    'reason' => $reason,
                    'proposed_content' => $content,
                    'requested_by' => (int) $requester->getKey(),
                ]));
            } catch (QueryException $e) {
                if (! $this->isPendingUniqueViolation($e)) {
                    throw $e;
                }

                throw $this->refusal('amendment', self::MESSAGE_PENDING_EXISTS);
            }

            $this->auditLog->log(self::AUDIT_ENTITY, (int) $amendment->getKey(), self::AUDIT_REQUESTED, null, [
                'subject_type' => $type,
                'subject_id' => $id,
                'branch_id' => (int) $amendment->branch_id,
                'base_version' => (int) $amendment->base_version,
                'base_version_id' => (int) $amendment->base_version_id,
                'status' => RmeAmendment::STATUS_PENDING,
                'reason_length' => mb_strlen($reason),
            ], $requester);

            return $amendment;
        });
    }

    /** Withdraw a pending amendment — its requester only (identity, binds Super Admin). */
    public function cancel(RmeAmendment $amendment, User $actor): RmeAmendment
    {
        return DB::transaction(function () use ($amendment, $actor): RmeAmendment {
            [$locked] = $this->lockForDecision($amendment);

            if (! $locked->isPending()) {
                throw $this->refusal('amendment', self::MESSAGE_NOT_PENDING);
            }

            if ((int) $locked->requested_by !== (int) $actor->getKey()) {
                throw $this->refusal('amendment', self::MESSAGE_CANCEL_NOT_REQUESTER);
            }

            $locked = $this->amendments->transition($locked, [
                'status' => RmeAmendment::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            $this->auditLog->log(self::AUDIT_ENTITY, (int) $locked->getKey(), self::AUDIT_CANCELLED, ['status' => RmeAmendment::STATUS_PENDING], [
                'subject_type' => $locked->subject_type,
                'subject_id' => (int) $locked->subject_id,
                'branch_id' => (int) $locked->branch_id,
                'status' => RmeAmendment::STATUS_CANCELLED,
            ], $actor);

            return $locked;
        });
    }

    /**
     * Refuse a pending amendment with a written reason. Account authority first,
     * then scope (out of scope is "not found"), then the state and separation of
     * duties — all on the locked rows, binding Super Admin.
     */
    public function reject(RmeAmendment $amendment, User $approver, string $decisionReason): RmeAmendment
    {
        $decisionReason = $this->boundedReason($decisionReason, 'decision_reason', self::MESSAGE_DECISION_REASON);

        return DB::transaction(function () use ($amendment, $approver, $decisionReason): RmeAmendment {
            if (! $approver->can(self::PERMISSION_APPROVE)) {
                throw $this->refusal('amendment', self::MESSAGE_NO_APPROVE_PERMISSION);
            }

            [$locked, $subject] = $this->lockForDecision($amendment);

            if (! $this->inApproverScope($approver, $locked, $subject)) {
                throw (new ModelNotFoundException)->setModel(RmeAmendment::class, [(int) $locked->getKey()]);
            }

            $refusal = $this->decisionRefusal($locked, $subject, $approver);
            if ($refusal !== null) {
                throw $this->refusal('amendment', $refusal);
            }

            $locked = $this->amendments->transition($locked, [
                'status' => RmeAmendment::STATUS_REJECTED,
                'decided_by' => (int) $approver->getKey(),
                'decided_at' => now(),
                'decision_reason' => $decisionReason,
            ]);

            $this->auditLog->log(self::AUDIT_ENTITY, (int) $locked->getKey(), self::AUDIT_REJECTED, ['status' => RmeAmendment::STATUS_PENDING], [
                'subject_type' => $locked->subject_type,
                'subject_id' => (int) $locked->subject_id,
                'branch_id' => (int) $locked->branch_id,
                'status' => RmeAmendment::STATUS_REJECTED,
                'requested_by' => (int) $locked->requested_by,
                'decision_reason_length' => mb_strlen($decisionReason),
            ], $approver);

            return $locked;
        });
    }

    // ─── Authority (the policy and the pages ask the same predicates) ────────

    /** Why this account may not request an amendment of the subject, or null. */
    public function requestRefusal(MedicalRecord|Odontogram $subject, ?User $user): ?string
    {
        if (! $subject->exists || ! $this->isFinal($subject)) {
            return self::MESSAGE_NOT_FINAL;
        }

        if ($user === null || ! $user->can(self::PERMISSION_REQUEST)) {
            return self::MESSAGE_NO_REQUEST_PERMISSION;
        }

        $userId = (int) $user->getKey();
        if ($userId !== $this->doctorOfRecordUserId($subject) && $userId !== (int) ($subject->finalized_by ?? 0)) {
            return self::MESSAGE_NOT_REQUESTER;
        }

        // The requester reads the record like anyone else (rule 240 §5.7).
        if (! Gate::forUser($user)->allows('view', $subject)) {
            return self::MESSAGE_NOT_REQUESTER;
        }

        return null;
    }

    /** For the request form: refuses (403) unless the server would accept a request now. */
    public function assertMayRequest(MedicalRecord|Odontogram $subject, User $user): void
    {
        $refusal = $this->requestRefusal($subject, $user)
            ?? ($this->amendments->pendingFor(...$this->subjectKey($subject)) !== null ? self::MESSAGE_PENDING_EXISTS : null);

        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }
    }

    /** May this account read the amendment (its requester, or an approver in scope)? */
    public function mayView(User $user, RmeAmendment $amendment, MedicalRecord|Odontogram|null $subject = null): bool
    {
        $subject ??= $this->amendments->findSubject($amendment->subject_type, (int) $amendment->subject_id);

        if ($subject === null) {
            return false;
        }

        $isRequester = (int) $amendment->requested_by === (int) $user->getKey();
        $isApprover = $user->can(self::PERMISSION_APPROVE) && $this->branchScope->allows($user, (int) $amendment->branch_id);

        return ($isRequester || $isApprover) && Gate::forUser($user)->allows('view', $subject);
    }

    /** @return array{can_cancel: bool, can_reject: bool} */
    public function actionsFor(RmeAmendment $amendment, User $user, MedicalRecord|Odontogram $subject): array
    {
        return [
            'can_cancel' => $amendment->isPending() && (int) $amendment->requested_by === (int) $user->getKey(),
            'can_reject' => $user->can(self::PERMISSION_APPROVE)
                && $this->inApproverScope($user, $amendment, $subject)
                && $this->decisionRefusal($amendment, $subject, $user) === null,
        ];
    }

    // ─── Reads ──────────────────────────────────────────────────────────────

    public function find(int $id): ?RmeAmendment
    {
        return $this->amendments->find($id);
    }

    public function subjectOf(RmeAmendment $amendment): MedicalRecord|Odontogram|null
    {
        return $this->amendments->findSubject($amendment->subject_type, (int) $amendment->subject_id);
    }

    /** The supervisor queue: the approver's list scope (CG-05). */
    public function queueFor(User $user, ?string $status, int $perPage = 20): LengthAwarePaginator
    {
        return $this->amendments->paginateQueue($this->branchScope->resolve($user), $status, $perPage);
    }

    /** @return array<string, int> */
    public function statusCountsFor(User $user): array
    {
        return $this->amendments->statusCounts($this->branchScope->resolve($user));
    }

    /**
     * The "Amandemen" block of a final record's page, or null for a draft. The
     * request offer mirrors the service (rule 135): shown only when request()
     * would accept this account now.
     *
     * @return array{subject_type: string, subject_id: int, pending: ?RmeAmendment, recent: Collection<int, RmeAmendment>, can_request: bool, viewable: list<int>}|null
     */
    public function panelFor(MedicalRecord|Odontogram $subject, ?User $user): ?array
    {
        if (! $subject->exists || ! $this->isFinal($subject)) {
            return null;
        }

        [$type, $id] = $this->subjectKey($subject);
        $recent = $this->amendments->recentFor($type, $id, 5);
        // One pending at a time, filed after every earlier one closed: the newest.
        $pending = $recent->first(fn (RmeAmendment $amendment): bool => $amendment->isPending());

        return [
            'subject_type' => $type,
            'subject_id' => $id,
            'pending' => $pending,
            'recent' => $recent,
            'can_request' => $pending === null && $this->requestRefusal($subject, $user) === null,
            'viewable' => $user === null ? [] : $recent
                ->filter(fn (RmeAmendment $amendment): bool => $this->mayView($user, $amendment, $subject))
                ->map(fn (RmeAmendment $amendment): int => (int) $amendment->getKey())
                ->values()
                ->all(),
        ];
    }

    /**
     * What the request form starts from: the record's current content.
     *
     * @return array<string, mixed>
     */
    public function requestFormFor(MedicalRecord|Odontogram $subject): array
    {
        if ($subject instanceof Odontogram) {
            return [
                'summary_notes' => $subject->summary_notes,
                'additional_conditions' => $subject->additional_conditions,
                'teeth' => (array) (($subject->tooth_map_payload ?? [])['teeth'] ?? []),
            ];
        }

        $diagnoses = $this->amendments->sheetDiagnoses((int) $subject->getKey());
        $masters = $this->amendments->clinicalDiagnoses($diagnoses->pluck('clinical_diagnosis_id')->map(fn ($v) => (int) $v)->all());

        return [
            'fields' => collect(self::SHEET_FIELDS)->map(fn (string $label, string $field): array => [
                'label' => $label,
                'value' => $subject->{$field},
            ])->all(),
            'diagnoses' => $diagnoses->map(fn (MedicalRecordDiagnosis $diagnosis): array => [
                'id' => (int) $diagnosis->getKey(),
                'label' => $this->diagnosisLabel($masters->get((int) $diagnosis->clinical_diagnosis_id)),
                'role' => $diagnosis->diagnosis_role,
            ])->values()->all(),
        ];
    }

    /**
     * The detail page: the version the request was made against beside the
     * proposal, and what this account may do.
     *
     * @return array<string, mixed>
     */
    public function detailFor(RmeAmendment $amendment, User $user): array
    {
        $amendment->loadMissing(['branch:id,name', 'patient:id,name,medical_record_number', 'requester:id,name', 'decider:id,name', 'baseVersion']);
        $subject = $this->subjectOf($amendment);
        $before = $amendment->baseVersion?->snapshot ?? [];
        $proposed = $amendment->proposed_content ?? [];
        $latest = $this->versions->latestFor($amendment->subject_type, (int) $amendment->subject_id);

        $detail = [
            'amendment' => $amendment,
            'subject' => $subject,
            'latest_version' => $latest?->version,
            'actions' => $subject !== null ? $this->actionsFor($amendment, $user, $subject) : ['can_cancel' => false, 'can_reject' => false],
        ];

        if ($amendment->subject_type === RmeRecordVersion::SUBJECT_ODONTOGRAM) {
            return $detail + [
                'chart_before' => $this->chartView($before),
                'chart_after' => $this->chartView($proposed),
            ];
        }

        $beforeDiagnoses = collect($before['diagnoses'] ?? [])->keyBy('id');
        $added = $proposed['diagnoses']['add'] ?? [];
        $masters = $this->amendments->clinicalDiagnoses(array_map(fn (array $row): int => (int) $row['clinical_diagnosis_id'], $added));

        return $detail + [
            'field_rows' => collect($proposed['fields'] ?? [])->map(fn (?string $after, string $field): array => [
                'label' => self::SHEET_FIELDS[$field] ?? $field,
                'before' => $before['fields'][$field] ?? null,
                'after' => $after,
            ])->values()->all(),
            'diagnoses_before' => $beforeDiagnoses->map(fn (array $row): string => $this->snapshotDiagnosisLabel($row))->values()->all(),
            'diagnoses_removed' => collect($proposed['diagnoses']['remove'] ?? [])
                ->map(fn (int $id): string => $beforeDiagnoses->has($id) ? $this->snapshotDiagnosisLabel($beforeDiagnoses->get($id)) : 'Diagnosis #'.$id)
                ->all(),
            'diagnoses_added' => array_map(fn (array $row): string => $this->diagnosisLabel($masters->get((int) $row['clinical_diagnosis_id']))
                .' ('.$this->roleLabel((string) $row['diagnosis_role']).')'
                .(($row['notes'] ?? null) !== null ? ' — '.$row['notes'] : ''), $added),
        ];
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    private function assertRequester(MedicalRecord|Odontogram $subject, User $user): void
    {
        $refusal = $this->requestRefusal($subject, $user);

        if ($refusal !== null) {
            throw $this->refusal('amendment', $refusal);
        }
    }

    /** Why this account may not decide the amendment now (state, permission, separation of duties), or null. */
    public function decisionRefusal(RmeAmendment $amendment, MedicalRecord|Odontogram $subject, User $user): ?string
    {
        if (! $amendment->isPending()) {
            return self::MESSAGE_NOT_PENDING;
        }

        if (! $user->can(self::PERMISSION_APPROVE)) {
            return self::MESSAGE_NO_APPROVE_PERMISSION;
        }

        if ((int) $amendment->requested_by === (int) $user->getKey()) {
            return self::MESSAGE_SELF_DECISION;
        }

        if ($this->doctorOfRecordUserId($subject) === (int) $user->getKey()) {
            return self::MESSAGE_DOCTOR_OF_RECORD;
        }

        return null;
    }

    private function inApproverScope(User $user, RmeAmendment $amendment, MedicalRecord|Odontogram $subject): bool
    {
        return $this->branchScope->allows($user, (int) $amendment->branch_id)
            && Gate::forUser($user)->allows('view', $subject);
    }

    /**
     * Rule 240 §1.4 extended: the subject's visit (FOR NO KEY UPDATE), then the
     * subject row; returns the subject as it is now.
     */
    private function lockSubject(MedicalRecord|Odontogram $subject): MedicalRecord|Odontogram
    {
        $this->visits->lockForStatusDecision((int) $subject->clinic_visit_id);

        $locked = $subject instanceof Odontogram
            ? $this->odontograms->findByClinicVisitForUpdate((int) $subject->clinic_visit_id)
            : $this->medicalRecords->lockForHandwriting((int) $subject->getKey());

        if ($locked === null || (int) $locked->getKey() !== (int) $subject->getKey()) {
            throw (new ModelNotFoundException)->setModel($subject::class, [$subject->getKey()]);
        }

        return $locked;
    }

    /** @return array{0: RmeAmendment, 1: MedicalRecord|Odontogram} visit → subject → amendment */
    private function lockForDecision(RmeAmendment $amendment): array
    {
        $subject = $this->subjectOf($amendment);

        if ($subject === null) {
            throw (new ModelNotFoundException)->setModel(RmeAmendment::class, [(int) $amendment->getKey()]);
        }

        $lockedSubject = $this->lockSubject($subject);
        $locked = $this->amendments->findForUpdate((int) $amendment->getKey());

        if ($locked === null) {
            throw (new ModelNotFoundException)->setModel(RmeAmendment::class, [(int) $amendment->getKey()]);
        }

        return [$locked, $lockedSubject];
    }

    /** @return array<string, mixed> only what changes, never an empty proposal */
    private function sheetProposal(MedicalRecord $sheet, array $proposed): array
    {
        $this->assertKeys($proposed, ['fields', 'diagnoses'], 'proposed');
        $fields = $this->arrayPart($proposed, 'fields');
        $this->assertKeys($fields, array_keys(self::SHEET_FIELDS), 'proposed.fields');

        $changed = [];
        foreach (array_keys(self::SHEET_FIELDS) as $field) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }

            if ($fields[$field] !== null && ! is_string($fields[$field])) {
                throw $this->refusal("proposed.fields.$field", 'Isi kolom yang diusulkan tidak valid.');
            }

            $value = $this->text($fields[$field]);
            if ($value !== null && mb_strlen($value) > self::SHEET_FIELD_MAX) {
                throw $this->refusal("proposed.fields.$field", 'Isi kolom yang diusulkan terlalu panjang (maksimal '.self::SHEET_FIELD_MAX.' karakter).');
            }

            if ($value !== $this->text($sheet->{$field})) {
                $changed[$field] = $value;
            }
        }

        $diagnoses = $this->diagnosisProposal($sheet, $this->arrayPart($proposed, 'diagnoses'));
        $content = array_filter(['fields' => $changed, 'diagnoses' => $diagnoses], fn (array $part): bool => $part !== []);

        if ($content === []) {
            throw $this->refusal('proposed', self::MESSAGE_NO_CHANGE);
        }

        return $content;
    }

    /** @return array{add?: list<array{clinical_diagnosis_id: int, diagnosis_role: string, notes: ?string}>, remove?: list<int>} */
    private function diagnosisProposal(MedicalRecord $sheet, array $input): array
    {
        $this->assertKeys($input, ['add', 'remove'], 'proposed.diagnoses');
        $current = $this->amendments->sheetDiagnoses((int) $sheet->getKey());

        $remove = [];
        foreach ($this->arrayPart($input, 'remove') as $id) {
            if (! is_numeric($id) || ! $current->has((int) $id)) {
                throw $this->refusal('proposed.diagnoses.remove', 'Diagnosis yang akan dihapus bukan diagnosis rekam medis ini.');
            }
            $remove[(int) $id] = (int) $id;
        }
        $remove = array_values($remove);

        $rows = array_values(array_filter($this->arrayPart($input, 'add'), fn ($row): bool => is_array($row) && ($row['clinical_diagnosis_id'] ?? null) !== null && $row['clinical_diagnosis_id'] !== ''));
        if (count($rows) > self::DIAGNOSIS_ADD_MAX) {
            throw $this->refusal('proposed.diagnoses.add', 'Paling banyak '.self::DIAGNOSIS_ADD_MAX.' diagnosis baru per amandemen.');
        }

        $masters = $this->amendments->clinicalDiagnoses(array_map(fn (array $row): int => (int) $row['clinical_diagnosis_id'], $rows));
        $kept = $current->reject(fn (MedicalRecordDiagnosis $diagnosis): bool => in_array((int) $diagnosis->getKey(), $remove, true));
        $onSheet = $kept->map(fn (MedicalRecordDiagnosis $diagnosis): int => (int) $diagnosis->clinical_diagnosis_id)->all();
        $primaries = $kept->where('diagnosis_role', MedicalRecordDiagnosis::ROLE_PRIMARY)->count();

        $add = [];
        foreach ($rows as $row) {
            $masterId = (int) $row['clinical_diagnosis_id'];
            $master = $masters->get($masterId);
            if (! $master instanceof ClinicalDiagnosis || $master->status !== ClinicalDiagnosis::STATUS_ACTIVE) {
                throw $this->refusal('proposed.diagnoses.add', 'Diagnosis master tidak ditemukan atau tidak aktif.');
            }

            $role = (string) ($row['diagnosis_role'] ?? '');
            if (! in_array($role, MedicalRecordDiagnosis::ROLES, true)) {
                throw $this->refusal('proposed.diagnoses.add', 'Peran diagnosis tidak valid.');
            }

            if (in_array($masterId, $onSheet, true)) {
                throw $this->refusal('proposed.diagnoses.add', 'Diagnosis ini sudah tercatat pada rekam medis.');
            }

            $notes = $row['notes'] ?? null;
            if ($notes !== null && (! is_string($notes) || mb_strlen($notes) > 500)) {
                throw $this->refusal('proposed.diagnoses.add', 'Catatan diagnosis maksimal 500 karakter.');
            }

            $onSheet[] = $masterId;
            $primaries += $role === MedicalRecordDiagnosis::ROLE_PRIMARY ? 1 : 0;
            $add[] = ['clinical_diagnosis_id' => $masterId, 'diagnosis_role' => $role, 'notes' => $this->text($notes)];
        }

        if ($primaries > 1) {
            throw $this->refusal('proposed.diagnoses.add', 'Rekam medis hanya boleh memiliki satu diagnosis utama — hapus diagnosis utama sebelumnya dalam usulan yang sama.');
        }

        return array_filter(['add' => $add, 'remove' => $remove], fn (array $part): bool => $part !== []);
    }

    /** @return array{summary_notes: ?string, additional_conditions: ?string, tooth_map_payload: ?array<string, mixed>} the whole proposed chart */
    private function chartProposal(Odontogram $chart, array $proposed): array
    {
        $this->assertKeys($proposed, ['summary_notes', 'additional_conditions', 'tooth_map_payload'], 'proposed');

        if (is_string($proposed['tooth_map_payload'] ?? null)) {
            $decoded = json_decode($proposed['tooth_map_payload'], true);
            $proposed['tooth_map_payload'] = is_array($decoded) ? $decoded : $proposed['tooth_map_payload'];
        }

        // The rules of a chart save (UpdateOdontogramPlaceholderRequest), so an
        // amendment can never propose a chart the editor could not have saved.
        $statuses = 'in:normal,caries,missing,filling,crown,root_treated';
        $validator = Validator::make(['proposed' => $proposed], [
            'proposed.summary_notes' => ['nullable', 'string', 'max:5000'],
            'proposed.additional_conditions' => ['nullable', 'string', 'max:5000'],
            'proposed.tooth_map_payload' => ['nullable', 'array'],
            'proposed.tooth_map_payload.teeth' => ['nullable', 'array'],
            'proposed.tooth_map_payload.teeth.*' => ['nullable', 'array'],
            'proposed.tooth_map_payload.teeth.*.status' => ['nullable', 'string', $statuses],
            'proposed.tooth_map_payload.teeth.*.note' => ['nullable', 'string', 'max:1000'],
            'proposed.tooth_map_payload.teeth.*.conditions' => ['nullable', 'array'],
            'proposed.tooth_map_payload.teeth.*.conditions.*' => ['nullable', 'string', 'in:caries,missing,crown,root_treated,mobility,impaction,filling'],
            'proposed.tooth_map_payload.teeth.*.additional_condition' => ['nullable', 'string', 'max:1000'],
            'proposed.tooth_map_payload.teeth.*.additional_note' => ['nullable', 'string', 'max:1000'],
            'proposed.tooth_map_payload.teeth.*.dokter' => ['nullable', 'string', 'max:255'],
        ]);
        $validator->after(function ($v) use ($proposed): void {
            $teeth = $proposed['tooth_map_payload']['teeth'] ?? null;
            if (! is_array($teeth)) {
                return;
            }

            $valid = FdiToothNumbers::all();
            foreach ($teeth as $tooth => $entry) {
                if (! in_array((string) $tooth, $valid, true)) {
                    $v->errors()->add('proposed.tooth_map_payload.teeth', "Nomor gigi '{$tooth}' tidak valid. Gunakan nomor FDI.");
                }

                $conditions = is_array($entry) && is_array($entry['conditions'] ?? null) ? array_values(array_filter($entry['conditions'], fn ($c) => $c !== null)) : [];
                if (count($conditions) !== count(array_unique($conditions))) {
                    $v->errors()->add("proposed.tooth_map_payload.teeth.{$tooth}.conditions", "Gigi '{$tooth}' memiliki kondisi duplikat.");
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $current = [
            'summary_notes' => $this->text($chart->summary_notes),
            'additional_conditions' => $this->text($chart->additional_conditions),
            'tooth_map_payload' => $chart->tooth_map_payload,
        ];
        $after = [
            'summary_notes' => array_key_exists('summary_notes', $proposed) ? $this->text($proposed['summary_notes']) : $current['summary_notes'],
            'additional_conditions' => array_key_exists('additional_conditions', $proposed) ? $this->text($proposed['additional_conditions']) : $current['additional_conditions'],
            'tooth_map_payload' => array_key_exists('tooth_map_payload', $proposed) ? $proposed['tooth_map_payload'] : $current['tooth_map_payload'],
        ];

        if ($this->sorted($after) === $this->sorted($current)) {
            throw $this->refusal('proposed', self::MESSAGE_NO_CHANGE);
        }

        return $after;
    }

    /** @return array{summary_notes: ?string, additional_conditions: ?string, teeth: list<array<string, ?string>>} */
    private function chartView(array $chart): array
    {
        $teeth = (array) (($chart['tooth_map_payload'] ?? [])['teeth'] ?? []);
        uksort($teeth, fn ($a, $b): int => strnatcmp((string) $a, (string) $b));

        return [
            'summary_notes' => $chart['summary_notes'] ?? null,
            'additional_conditions' => $chart['additional_conditions'] ?? null,
            'teeth' => collect($teeth)->map(fn ($entry, $tooth): array => [
                'tooth' => (string) $tooth,
                'status' => is_array($entry) ? ($entry['status'] ?? null) : null,
                'additional_condition' => is_array($entry) ? ($entry['additional_condition'] ?? null) : null,
                'additional_note' => is_array($entry) ? ($entry['additional_note'] ?? null) : null,
                'dokter' => is_array($entry) ? ($entry['dokter'] ?? null) : null,
            ])->values()->all(),
        ];
    }

    private function doctorOfRecordUserId(MedicalRecord|Odontogram $subject): ?int
    {
        $doctorId = $subject instanceof Odontogram ? $subject->clinicVisit?->doctor_id : $subject->doctor_id;

        return $this->amendments->doctorUserId($doctorId !== null ? (int) $doctorId : null);
    }

    private function patientId(MedicalRecord|Odontogram $subject): int
    {
        return $subject instanceof Odontogram ? (int) $subject->clinicVisit?->patient_id : (int) $subject->patient_id;
    }

    /** The patient's live encounter, whose own unsealed pages the baseline does not count as locked (rule 240 §5.4). */
    private function liveEncounterId(MedicalRecord|Odontogram $subject): ?int
    {
        if ($subject instanceof Odontogram) {
            return null;
        }

        $encounter = $this->encounters->currentFor((int) $subject->patient_id);

        return $encounter !== null ? (int) $encounter->getKey() : null;
    }

    private function isFinal(MedicalRecord|Odontogram $subject): bool
    {
        return $subject instanceof Odontogram
            ? $subject->isFinalized()
            : $subject->status === MedicalRecord::STATUS_FINAL;
    }

    /** @return array{0: string, 1: int} */
    private function subjectKey(MedicalRecord|Odontogram $subject): array
    {
        return [
            $subject instanceof Odontogram ? RmeRecordVersion::SUBJECT_ODONTOGRAM : RmeRecordVersion::SUBJECT_MEDICAL_RECORD,
            (int) $subject->getKey(),
        ];
    }

    private function boundedReason(string $reason, string $key, string $message): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < RmeAmendment::REASON_MIN || $length > RmeAmendment::REASON_MAX) {
            throw $this->refusal($key, $message);
        }

        return $reason;
    }

    /** A textarea value as stored: LF line endings, empty is null. */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);

        return trim($value) === '' ? null : $value;
    }

    /** @param list<string> $allowed */
    private function assertKeys(array $input, array $allowed, string $key): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw $this->refusal($key, 'Usulan amandemen memuat bagian yang tidak dikenal.');
        }
    }

    /** @return array<array-key, mixed> */
    private function arrayPart(array $input, string $key): array
    {
        $part = $input[$key] ?? [];

        if (! is_array($part)) {
            throw $this->refusal('proposed', 'Usulan amandemen tidak valid.');
        }

        return $part;
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $child): mixed => $this->sorted($child), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function diagnosisLabel(?ClinicalDiagnosis $master): string
    {
        return $master !== null ? trim($master->code.' — '.$master->display) : 'Diagnosis tidak dikenal';
    }

    /** @param array<string, mixed> $row */
    private function snapshotDiagnosisLabel(array $row): string
    {
        return trim(($row['code'] ?? '').' — '.($row['display'] ?? '')).' ('.$this->roleLabel((string) ($row['diagnosis_role'] ?? '')).')';
    }

    private function roleLabel(string $role): string
    {
        return $role === MedicalRecordDiagnosis::ROLE_PRIMARY ? 'utama' : 'sekunder';
    }

    private function refusal(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }

    private function isPendingUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return in_array((string) ($e->errorInfo[0] ?? $e->getCode()), ['23505', '23000'], true)
            && (str_contains($message, 'trx_rme_amd_one_pending_uq') || str_contains($message, 'trx_rme_amendments.subject_id'));
    }
}
