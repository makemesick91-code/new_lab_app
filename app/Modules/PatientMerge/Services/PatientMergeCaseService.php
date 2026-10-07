<?php

namespace App\Modules\PatientMerge\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Interfaces\PatientMergeCaseRepositoryInterface;
use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Support\PatientIdentityMask;
use App\Modules\PatientMerge\Support\PatientMergeField;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — the merge REQUEST lifecycle:
 * create, reconcile identity field by field, choose the canonical patient,
 * submit for review, reject, cancel. The irreversible step lives in
 * {@see PatientMergeExecutionService}.
 *
 * Choosing the canonical patient and choosing the final identity are two
 * separate decisions on purpose: the surviving row can be Patient B while the
 * final name came from Patient A and the date of birth from a verified
 * document. Every field records where it came from.
 */
class PatientMergeCaseService
{
    public const AUDIT_ENTITY = 'patient_merge_case';

    public const MIN_REASON = 10;

    public function __construct(
        private readonly PatientMergeCaseRepositoryInterface $cases,
        private readonly PatientMergeOwnershipRepositoryInterface $ownership,
        private readonly PatientMergeScope $scope,
        private readonly PatientIdentityComparator $comparator,
        private readonly PatientMergeBlockerService $blockers,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Open a merge case for two manually chosen (or detected) patients.
     */
    public function create(User $actor, int $patientAId, int $patientBId, string $reason): PatientMergeCase
    {
        $reason = trim($reason);

        if ($patientAId === $patientBId) {
            throw ValidationException::withMessages(['patient_b_id' => 'Pasien A dan Pasien B tidak boleh pasien yang sama.']);
        }

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['request_reason' => 'Alasan pengajuan minimal '.self::MIN_REASON.' karakter.']);
        }

        return DB::transaction(function () use ($actor, $patientAId, $patientBId, $reason): PatientMergeCase {
            // Locking both rows serializes two operators opening a case on the
            // same patient at the same moment.
            $patients = $this->ownership->lockPatients([$patientAId, $patientBId]);
            $a = $patients->get($patientAId);
            $b = $patients->get($patientBId);

            $this->assertSelectable($actor, $a, 'patient_a_id');
            $this->assertSelectable($actor, $b, 'patient_b_id');

            $crossBranch = $this->scope->isCrossBranch($a, $b);

            if ($crossBranch && ! $this->scope->mayResolveCrossBranch($actor)) {
                throw ValidationException::withMessages(['patient_b_id' => 'Pasien berasal dari cabang berbeda. Penggabungan lintas cabang hanya dapat diajukan oleh peninjau (Supervisor RME).']);
            }

            $existing = $this->cases->openCaseInvolving([$a->id, $b->id]);

            if ($existing !== null) {
                throw ValidationException::withMessages(['patient_a_id' => 'Salah satu pasien sudah berada dalam pengajuan merge yang masih terbuka ('.$existing->case_number.').']);
            }

            $now = now();
            $case = $this->cases->create([
                'uuid' => (string) Str::uuid(),
                'case_number' => $this->cases->nextCaseNumber((int) $now->format('Y')),
                'patient_a_id' => $a->id,
                'patient_b_id' => $b->id,
                'status' => PatientMergeStatus::DRAFT,
                'cross_branch' => $crossBranch,
                'requested_by' => $actor->id,
                'requested_at' => $now,
                'request_reason' => $reason,
            ]);

            $this->seedResolutions($case, $a, $b, $actor);
            $this->refreshRisk($case, $a, $b);

            $this->audit->log(self::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_CASE_CREATED', null, [
                'case_number' => $case->case_number,
                'patient_a_id' => $a->id,
                'patient_b_id' => $b->id,
                'cross_branch' => $crossBranch,
                'reason_length' => mb_strlen($reason),
            ], $actor);

            return $case->fresh();
        });
    }

    /**
     * Record the operator's field-by-field reconciliation and canonical choice.
     *
     * @param  array<string, array{source?: ?string, manual_value?: mixed, reason?: ?string}>  $fields
     */
    public function resolve(PatientMergeCase $case, User $actor, array $fields, ?int $canonicalPatientId): PatientMergeCase
    {
        return DB::transaction(function () use ($case, $actor, $fields, $canonicalPatientId): PatientMergeCase {
            $case = $this->lockedCase($case);

            if (! $case->isDraft()) {
                throw ValidationException::withMessages(['case' => 'Hanya pengajuan berstatus Draf yang dapat diubah.']);
            }

            $this->assertRequester($case, $actor);

            $patients = $this->ownership->lockPatients([$case->patient_a_id, $case->patient_b_id]);
            $a = $patients->get($case->patient_a_id);
            $b = $patients->get($case->patient_b_id);
            $comparison = $this->comparator->compare($a, $b);
            $now = now();
            $rows = [];

            foreach ($fields as $field => $choice) {
                if (! array_key_exists($field, PatientMergeField::FIELDS)) {
                    continue;
                }

                $source = $choice['source'] ?? null;

                if ($source === null || $source === '') {
                    continue;
                }

                $rows[] = $this->resolutionRow($case, $field, (string) $source, $choice, $comparison[$field], $a, $b, $actor, $now);
            }

            $this->cases->replaceFieldResolutions($case, $rows);

            if ($canonicalPatientId !== null) {
                if (! in_array($canonicalPatientId, [$a->id, $b->id], true)) {
                    throw ValidationException::withMessages(['canonical_patient_id' => 'Pasien canonical harus salah satu dari Pasien A atau Pasien B.']);
                }

                $this->cases->write($case, [
                    'canonical_patient_id' => $canonicalPatientId,
                    'source_patient_id' => $canonicalPatientId === $a->id ? $b->id : $a->id,
                ]);
            }

            $this->audit->log(self::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_FIELDS_RESOLVED', null, [
                'fields' => array_map(fn (array $row): array => ['field' => $row['field'], 'source' => $row['source']], $rows),
                'canonical_patient_id' => $case->canonical_patient_id,
            ], $actor);

            return $case->fresh();
        });
    }

    public function submit(PatientMergeCase $case, User $actor): PatientMergeCase
    {
        return DB::transaction(function () use ($case, $actor): PatientMergeCase {
            $case = $this->lockedCase($case);
            $this->assertTransition($case, PatientMergeStatus::PENDING_REVIEW);
            $this->assertRequester($case, $actor);

            $patients = $this->ownership->lockPatients([$case->patient_a_id, $case->patient_b_id]);
            $a = $patients->get($case->patient_a_id);
            $b = $patients->get($case->patient_b_id);

            // A/B/matched values were captured when the field was resolved; a
            // patient edited since then would make the reviewer approve a stale
            // value. Re-derive them from the LOCKED rows now — a source that is
            // no longer valid (the side became empty, the match broke) throws
            // and the requester re-resolves that field.
            $this->refreshDerivedResolutions($case->load('fieldResolutions'), $a, $b, $actor);

            $blockers = $this->blockers->blockers($case->load('fieldResolutions'), $a, $b);

            if ($blockers !== []) {
                throw PatientMergeBlockedException::fromBlockers($blockers);
            }

            $this->refreshRisk($case, $a, $b);

            $this->cases->write($case, [
                'status' => PatientMergeStatus::PENDING_REVIEW,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'identity_fingerprint' => $this->comparator->fingerprint($a, $b),
            ]);

            $this->audit->log(self::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_SUBMITTED', null, [
                'case_number' => $case->case_number,
                'canonical_patient_id' => $case->canonical_patient_id,
                'risk_level' => $case->risk_level,
            ], $actor);

            return $case->fresh();
        });
    }

    /** The requester pulls a submitted case back to Draft to change it. */
    public function withdraw(PatientMergeCase $case, User $actor): PatientMergeCase
    {
        return DB::transaction(function () use ($case, $actor): PatientMergeCase {
            $case = $this->lockedCase($case);
            $this->assertTransition($case, PatientMergeStatus::DRAFT);

            $this->assertRequester($case, $actor);
            $this->cases->write($case, ['status' => PatientMergeStatus::DRAFT, 'submitted_by' => null, 'submitted_at' => null, 'identity_fingerprint' => null]);
            $this->audit->log(self::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_WITHDRAWN', null, ['case_number' => $case->case_number], $actor);

            return $case->fresh();
        });
    }

    public function reject(PatientMergeCase $case, User $reviewer, string $reason): PatientMergeCase
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['review_note' => 'Alasan penolakan minimal '.self::MIN_REASON.' karakter.']);
        }

        return DB::transaction(function () use ($case, $reviewer, $reason): PatientMergeCase {
            $case = $this->lockedCase($case);
            $this->assertTransition($case, PatientMergeStatus::REJECTED);
            $this->assertNotRequester($case->load('fieldResolutions'), $reviewer);

            $this->cases->write($case, [
                'status' => PatientMergeStatus::REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ]);

            $this->audit->log(self::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_REJECTED', null, [
                'case_number' => $case->case_number,
                'reason_length' => mb_strlen($reason),
            ], $reviewer);

            return $case->fresh();
        });
    }

    public function cancel(PatientMergeCase $case, User $actor, string $reason): PatientMergeCase
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['cancel_reason' => 'Alasan pembatalan minimal '.self::MIN_REASON.' karakter.']);
        }

        return DB::transaction(function () use ($case, $actor, $reason): PatientMergeCase {
            $case = $this->lockedCase($case);
            $this->assertTransition($case, PatientMergeStatus::CANCELLED);

            $this->cases->write($case, [
                'status' => PatientMergeStatus::CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $this->audit->log(self::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_CANCELLED', null, [
                'case_number' => $case->case_number,
                'reason_length' => mb_strlen($reason),
            ], $actor);

            return $case->fresh();
        });
    }

    /**
     * What the reviewer approves: final identity with provenance, the records
     * that will be consolidated, the aliases that will be created, the risk
     * flags and every blocker. Read-only.
     *
     * @return array<string, mixed>
     */
    public function preview(PatientMergeCase $case): array
    {
        $case->loadMissing(['patientA.branch', 'patientB.branch', 'fieldResolutions']);
        $a = $case->patientA;
        $b = $case->patientB;
        $comparison = $this->comparator->compare($a, $b);
        $resolutions = $case->fieldResolutions->keyBy('field');
        $countsA = $this->ownership->countsFor($a->id);
        $countsB = $this->ownership->countsFor($b->id);

        $identity = [];

        foreach (PatientMergeField::fields() as $field) {
            $resolution = $resolutions[$field] ?? null;
            $identity[$field] = $comparison[$field] + [
                'source' => $resolution?->source,
                'source_label' => $resolution?->source ? (PatientMergeField::SOURCE_LABELS[$resolution->source] ?? $resolution->source) : null,
                'final_display' => $resolution?->final_value_display,
                'manual_reason' => $resolution?->manual_reason,
                'resolved' => (bool) $resolution?->isResolved(),
            ];
        }

        $consolidated = [];

        foreach (PatientOwnedRecordRegistry::PREVIEW_GROUPS as $group => $label) {
            $consolidated[$group] = [
                'label' => $label,
                'a' => $countsA[$group] ?? 0,
                'b' => $countsB[$group] ?? 0,
                'total' => ($countsA[$group] ?? 0) + ($countsB[$group] ?? 0),
            ];
        }

        $canonical = match ($case->canonical_patient_id) {
            $a->id => $a,
            $b->id => $b,
            default => null,
        };
        $source = $canonical === null ? null : ($canonical->id === $a->id ? $b : $a);

        return [
            'identity' => $identity,
            'consolidated' => $consolidated,
            'canonical' => $canonical,
            'source' => $source,
            'aliases' => $source?->medical_record_number ? [$source->medical_record_number] : [],
            'blockers' => $this->blockers->blockers($case, $a, $b),
            'risk_flags' => $this->blockers->riskFlags($a, $b, $countsA, $countsB, (bool) $case->cross_branch),
        ];
    }

    private function seedResolutions(PatientMergeCase $case, Patient $a, Patient $b, User $actor): void
    {
        $comparison = $this->comparator->compare($a, $b);
        $now = now();
        $rows = [];

        foreach ($comparison as $field => $row) {
            $source = match (true) {
                $row['status'] === PatientMergeField::MATCH => PatientMergeField::SOURCE_MATCHED,
                ! $row['a_present'] && ! $row['b_present'] => PatientMergeField::SOURCE_EMPTY,
                // One side empty and the field is not identity-critical: the
                // value that exists is carried. A critical field with one side
                // missing still needs a human decision.
                $row['status'] === PatientMergeField::MISSING && ! $row['critical'] => $row['a_present'] ? PatientMergeField::SOURCE_A : PatientMergeField::SOURCE_B,
                default => null,
            };

            $rows[] = $source === null
                ? ['merge_case_id' => $case->id, 'field' => $field, 'comparison_status' => $row['status'], 'source' => null, 'final_value_encrypted' => null, 'final_value_display' => null, 'manual_reason' => null, 'resolved_by' => null, 'resolved_at' => null]
                : $this->resolutionRow($case, $field, $source, [], $row, $a, $b, $actor, $now);
        }

        $this->cases->replaceFieldResolutions($case, $rows);
    }

    /**
     * @param  array{source?: ?string, manual_value?: mixed, reason?: ?string}  $choice
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    private function resolutionRow(PatientMergeCase $case, string $field, string $source, array $choice, array $comparison, Patient $a, Patient $b, User $actor, Carbon $now): array
    {
        $label = PatientMergeField::label($field);
        $reason = null;

        $value = match ($source) {
            PatientMergeField::SOURCE_A => $comparison['a_present'] ? $this->raw($a, $field) : $this->invalid($field, "{$label} pada Pasien A kosong."),
            PatientMergeField::SOURCE_B => $comparison['b_present'] ? $this->raw($b, $field) : $this->invalid($field, "{$label} pada Pasien B kosong."),
            PatientMergeField::SOURCE_MATCHED => $comparison['status'] === PatientMergeField::MATCH ? $this->raw($a, $field) : $this->invalid($field, "{$label} pada kedua pasien tidak sama."),
            PatientMergeField::SOURCE_EMPTY => (! $comparison['a_present'] && ! $comparison['b_present']) || ! PatientMergeField::isCritical($field)
                ? null
                : $this->invalid($field, "{$label} adalah data identitas utama dan tidak dapat dikosongkan."),
            PatientMergeField::SOURCE_MANUAL => $this->manualValue($field, $choice['manual_value'] ?? null),
            default => $this->invalid($field, "Sumber nilai {$label} tidak dikenal."),
        };

        if ($source === PatientMergeField::SOURCE_MANUAL) {
            $reason = trim((string) ($choice['reason'] ?? ''));

            if (mb_strlen($reason) < self::MIN_REASON) {
                $this->invalid($field, "Nilai manual {$label} wajib disertai alasan/verifikasi minimal ".self::MIN_REASON.' karakter.');
            }
        }

        return [
            'merge_case_id' => $case->id,
            'field' => $field,
            'comparison_status' => $comparison['status'],
            'source' => $source,
            'final_value_encrypted' => $value,
            'final_value_display' => PatientIdentityMask::field($field, $value),
            'manual_reason' => $reason,
            'resolved_by' => $actor->id,
            'resolved_at' => $now,
        ];
    }

    private function raw(Patient $patient, string $field): ?string
    {
        $value = $patient->getAttribute($field);

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value === null ? null : (string) $value;
    }

    private function manualValue(string $field, mixed $value): ?string
    {
        $value = trim((string) $value);
        $label = PatientMergeField::label($field);

        if ($value === '') {
            $this->invalid($field, "Nilai manual {$label} tidak boleh kosong.");
        }

        // Control characters never belong in an identity field (a newline is
        // allowed only in an address).
        $control = $field === 'address' ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';

        if (preg_match($control, $value) === 1) {
            $this->invalid($field, "Nilai manual {$label} mengandung karakter yang tidak diizinkan.");
        }

        // The same limits as patient registration (StorePatientRequest), so a
        // merge can never write a value registration would have refused.
        return match ($field) {
            'ktp_number' => preg_match('/^\d{16}$/D', $value) === 1 ? $value : $this->invalid($field, 'NIK/KTP manual harus 16 digit angka.'),
            'date_of_birth' => $this->validDate($value) ? $value : $this->invalid($field, 'Tanggal lahir manual harus berformat YYYY-MM-DD dan tidak di masa depan.'),
            'gender' => in_array($value, PatientMergeField::GENDERS, true) ? $value : $this->invalid($field, 'Jenis kelamin manual tidak valid.'),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($value) <= 150 ? $value : $this->invalid($field, 'Email manual tidak valid.'),
            'phone', 'whatsapp_number' => preg_match('/^[0-9+\-\s()]{6,50}$/D', $value) === 1 ? $value : $this->invalid($field, "{$label} manual tidak valid."),
            'address' => mb_strlen($value) <= 1000 ? $value : $this->invalid($field, "{$label} manual terlalu panjang."),
            default => mb_strlen($value) <= 150 ? $value : $this->invalid($field, "{$label} manual terlalu panjang."),
        };
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        return $value <= now()->toDateString();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(["fields.{$field}" => $message]);
    }

    private function refreshRisk(PatientMergeCase $case, Patient $a, Patient $b): void
    {
        $flags = $this->blockers->riskFlags(
            $a,
            $b,
            $this->ownership->countsFor($a->id),
            $this->ownership->countsFor($b->id),
            (bool) $case->cross_branch,
        );

        $this->cases->write($case, [
            'risk_flags' => $flags,
            'risk_level' => $flags === [] ? 'normal' : 'high',
        ]);
    }

    private function assertSelectable(User $actor, ?Patient $patient, string $field): void
    {
        if ($patient === null || $patient->trashed()) {
            throw ValidationException::withMessages([$field => 'Pasien tidak ditemukan.']);
        }

        // Out of scope reads exactly like not-found, so ids cannot be probed.
        if (! $this->scope->covers($actor, $patient)) {
            throw ValidationException::withMessages([$field => 'Pasien tidak ditemukan.']);
        }

        if ($patient->isMerged()) {
            throw ValidationException::withMessages([$field => 'Pasien ini sudah digabungkan ke pasien lain. Pilih pasien hasil penggabungan.']);
        }
    }

    private function lockedCase(PatientMergeCase $case): PatientMergeCase
    {
        $locked = $this->cases->lockForUpdate($case->id);

        if ($locked === null) {
            throw ValidationException::withMessages(['case' => 'Pengajuan merge tidak ditemukan.']);
        }

        return $locked;
    }

    private function assertTransition(PatientMergeCase $case, string $to): void
    {
        if (! PatientMergeStatus::canTransition($case->status, $to)) {
            throw ValidationException::withMessages(['case' => 'Status pengajuan ('.PatientMergeStatus::label($case->status).') tidak mengizinkan tindakan ini.']);
        }
    }

    /**
     * Maker-checker, enforced HERE and not in a policy: Super Admin passes
     * every policy through Gate::before, so a policy clause would never run
     * for the one actor most able to be both parties. "Author" means anyone
     * who shaped what is approved: requester, submitter, or a field resolver.
     */
    public function assertNotRequester(PatientMergeCase $case, User $reviewer): void
    {
        $case->loadMissing('fieldResolutions');

        if (in_array((int) $reviewer->id, $case->authorIds(), true)) {
            throw ValidationException::withMessages(['case' => 'Pengaju atau penyusun pengajuan tidak dapat meninjau atau menyetujui pengajuan tersebut.']);
        }
    }

    /** Only the requester shapes, submits or withdraws their own case. */
    private function assertRequester(PatientMergeCase $case, User $actor): void
    {
        if ((int) $case->requested_by !== (int) $actor->id) {
            throw ValidationException::withMessages(['case' => 'Hanya pengaju yang dapat mengubah, mengirim, atau menarik kembali pengajuan ini.']);
        }
    }

    private function refreshDerivedResolutions(PatientMergeCase $case, Patient $a, Patient $b, User $actor): void
    {
        $comparison = $this->comparator->compare($a, $b);
        $now = now();
        $rows = [];

        foreach ($case->fieldResolutions as $resolution) {
            if (! in_array($resolution->source, [PatientMergeField::SOURCE_A, PatientMergeField::SOURCE_B, PatientMergeField::SOURCE_MATCHED], true)) {
                continue;
            }

            $row = $this->resolutionRow($case, $resolution->field, $resolution->source, [], $comparison[$resolution->field], $a, $b, $actor, $now);
            // Keep who decided; only the value is refreshed.
            $row['resolved_by'] = $resolution->resolved_by;
            $row['resolved_at'] = $resolution->resolved_at;
            $rows[] = $row;
        }

        $this->cases->replaceFieldResolutions($case, $rows);
    }
}
