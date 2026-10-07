<?php

namespace App\Modules\PatientMerge\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Interfaces\PatientMergeCaseRepositoryInterface;
use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use App\Modules\PatientMerge\Interfaces\PatientRmAliasRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Models\PatientRmAlias;
use App\Modules\PatientMerge\Support\PatientIdentityMask;
use App\Modules\PatientMerge\Support\PatientMergeField;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — approve AND merge, as one
 * atomic transaction.
 *
 * Approval and execution commit together or not at all, so no case can sit
 * approved while the identities it was approved against change. Everything
 * the preview showed is re-derived here under row locks: the case status,
 * both patients, the identity fingerprint the reviewer approved, every
 * blocker, and the registry against the live schema.
 *
 * WHAT A MERGE DOES. It moves OWNERSHIP of every registered patient-owned row
 * from the source patient to the canonical patient, writes the reconciled
 * identity onto the canonical patient, keeps the source's Nomor RM as a
 * searchable alias, and marks the source merged (read-only, never deleted).
 *
 * WHAT A MERGE NEVER DOES. Delete a row, merge two rows' content, renumber,
 * re-post a payment, change an amount, rewrite a branch, convert legacy to
 * native, or take a KTP from a third patient.
 *
 * Conservation is PROVEN, not assumed: per-group counts before and after must
 * satisfy canonical_after == canonical_before + source_before and source_after
 * == 0, and every reassign must move exactly the ids it read. Counts are taken
 * over the two LOCKED patients only — a whole-table total would change with
 * any concurrent write for an unrelated patient and refuse a correct merge.
 * Any mismatch throws and the whole merge rolls back.
 */
class PatientMergeExecutionService
{
    public function __construct(
        private readonly PatientMergeCaseRepositoryInterface $cases,
        private readonly PatientMergeOwnershipRepositoryInterface $ownership,
        private readonly PatientRmAliasRepositoryInterface $aliases,
        private readonly PatientIdentityComparator $comparator,
        private readonly PatientMergeBlockerService $blockers,
        private readonly PatientMergeCaseService $caseService,
        private readonly AuditLogService $audit,
        private readonly LegacySingleActiveDocumentService $legacySlots,
    ) {}

    public function approveAndMerge(PatientMergeCase $case, User $approver, ?string $note): PatientMergeCase
    {
        try {
            return DB::transaction(fn (): PatientMergeCase => $this->merge($case, $approver, $note));
        } catch (PatientMergeBlockedException $blocked) {
            // Written AFTER the rollback, so the refusal survives it.
            $this->audit->log(PatientMergeCaseService::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_REFUSED', null, [
                'case_number' => $case->case_number,
                'reason_code' => $blocked->reasonCode,
                'blockers' => array_column($blocked->blockers, 'code'),
            ], $approver);

            throw $blocked;
        } catch (ValidationException $invalid) {
            throw $invalid;
        } catch (\Throwable $failure) {
            // Anything else (a conservation mismatch, a database error) is a
            // refused merge too: the transaction rolled back, and the attempt
            // must not vanish from the audit trail.
            $this->audit->log(PatientMergeCaseService::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_FAILED', null, [
                'case_number' => $case->case_number,
                'error' => class_basename($failure),
            ], $approver);

            throw $failure;
        }
    }

    private function merge(PatientMergeCase $case, User $approver, ?string $note): PatientMergeCase
    {
        $case = $this->cases->lockForUpdate($case->id)
            ?? throw ValidationException::withMessages(['case' => 'Pengajuan merge tidak ditemukan.']);

        if (! $case->isPendingReview()) {
            throw ValidationException::withMessages(['case' => 'Hanya pengajuan berstatus Menunggu Review yang dapat disetujui.']);
        }

        $this->caseService->assertNotRequester($case, $approver);

        // Legacy intake serializes on (type, patient) advisory locks, then reads
        // the patient row. Take the same locks FIRST, in a fixed order, so an
        // upload deciding a document slot can never interleave with the merge
        // that changes which patient owns the archive.
        $pair = [(int) $case->patient_a_id, (int) $case->patient_b_id];
        sort($pair);

        foreach ([LegacyImportType::LEGACY_RME, LegacyImportType::LEGACY_ODONTOGRAM] as $type) {
            foreach ($pair as $patientId) {
                $this->legacySlots->lockSlot($type, $patientId);
            }
        }

        $patients = $this->ownership->lockPatients([$case->patient_a_id, $case->patient_b_id]);
        $a = $patients->get($case->patient_a_id);
        $b = $patients->get($case->patient_b_id);
        $case->load('fieldResolutions');

        $blockers = $this->blockers->blockers($case, $a, $b);

        if ($blockers === [] && $this->comparator->fingerprint($a, $b) !== $case->identity_fingerprint) {
            $blockers[] = [
                'code' => 'IDENTITY_CHANGED_SINCE_SUBMISSION',
                'message' => 'Data identitas salah satu pasien berubah setelah pengajuan dikirim. Kembalikan pengajuan ke Draf dan tinjau ulang.',
            ];
        }

        if ($blockers !== []) {
            throw PatientMergeBlockedException::fromBlockers($blockers);
        }

        $canonical = $case->canonical_patient_id === $a->id ? $a : $b;
        $source = $canonical->id === $a->id ? $b : $a;
        $now = now();

        $this->cases->write($case, [
            'status' => PatientMergeStatus::APPROVED,
            'reviewed_by' => $approver->id,
            'reviewed_at' => $now,
            'review_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);
        $this->cases->write($case, ['status' => PatientMergeStatus::MERGING]);

        $before = [
            'canonical' => $this->ownership->countsFor($canonical->id),
            'source' => $this->ownership->countsFor($source->id),
        ];

        $snapshot = [
            'canonical' => $this->identitySnapshot($canonical),
            'source' => $this->identitySnapshot($source),
        ];

        $final = $this->finalIdentity($case);
        $manifest = [
            'tables' => [],
            'closed_doctor_assignments' => [],
            'repointed_aliases' => [],
            'alias_ids' => [],
            'source_ktp_released' => false,
            // Under the patient locks no new row can be created for either
            // patient, so anything above these ids later is post-merge work.
            'high_water' => $this->ownership->highWaterMarks(),
        ];

        // 1. Free the source's KTP slot first: the unique index would reject
        //    the canonical patient taking a value the source still holds.
        if ($source->ktp_number !== null) {
            $source->forceFill(['ktp_number' => null])->save();
            $manifest['source_ktp_released'] = true;
        }

        // 2. Reconciled identity onto the canonical patient. Re-check the KTP
        //    against every OTHER patient under the lock (fail closed).
        if (is_string($final['ktp_number'] ?? null) && $final['ktp_number'] !== ''
            && $this->ownership->ktpOwner($final['ktp_number'], [$canonical->id, $source->id]) !== null) {
            throw new PatientMergeBlockedException('KTP_OWNED_BY_OTHER_PATIENT', 'NIK/KTP final sudah terdaftar pada pasien lain.');
        }

        $canonical->forceFill($final)->save();

        // 3. Doctor assignments: an active assignment of the same doctor on
        //    both would violate the partial unique index. The source's
        //    duplicate is CLOSED (kept as history), never deleted.
        $closed = $this->ownership->collidingActiveDoctorAssignments($source->id, $canonical->id);
        $originalNotes = $this->ownership->closeDoctorAssignments($closed, $now, 'Ditutup otomatis oleh penggabungan pasien '.$case->case_number.' (penugasan yang sama sudah aktif pada pasien canonical).');
        $manifest['closed_doctor_assignments'] = $closed;
        $manifest['closed_doctor_assignment_notes'] = $originalNotes;

        // 4. Ownership moves, table by table, exactly as registered.
        foreach (PatientOwnedRecordRegistry::REASSIGN as $table => $spec) {
            $ids = $this->ownership->idsFor($table, $spec['column'], $source->id);

            if ($ids === []) {
                continue;
            }

            $moved = $this->ownership->reassign($table, $spec['column'], $ids, $canonical->id);

            if ($moved !== count($ids)) {
                throw new RuntimeException("Patient merge moved {$moved} of ".count($ids)." rows in {$table}.");
            }

            $manifest['tables'][$table] = $ids;
        }

        // 5. Aliases. Old numbers that resolved to the source now resolve to
        //    the canonical patient; the source's own number becomes an alias.
        $manifest['repointed_aliases'] = $this->aliases->repointCanonical($source->id, $canonical->id);

        if ($source->medical_record_number !== null && $source->medical_record_number !== '') {
            $manifest['alias_ids'][] = $this->createAlias($case, $source, $canonical, $approver)->id;
        }

        // 6. The source becomes a read-only pointer. Never deleted; its Nomor
        //    RM stays on its row so no future registration can be issued it.
        $source->forceFill([
            'merged_into_patient_id' => $canonical->id,
            'merged_at' => $now,
            'merged_by' => $approver->id,
            'merge_case_id' => $case->id,
            'is_active' => false,
        ])->save();

        // 7. Prove conservation. A mismatch rolls the whole merge back.
        $after = [
            'canonical' => $this->ownership->countsFor($canonical->id),
            'source' => $this->ownership->countsFor($source->id),
        ];
        $this->assertConserved($before, $after);

        $this->cases->write($case, [
            'status' => PatientMergeStatus::COMPLETED,
            'canonical_patient_id' => $canonical->id,
            'source_patient_id' => $source->id,
            'merged_at' => $now,
            'before_summary' => [
                'canonical' => ['id' => $canonical->id, 'medical_record_number' => $snapshot['canonical']['medical_record_number'], 'counts' => $before['canonical']],
                'source' => ['id' => $source->id, 'medical_record_number' => $snapshot['source']['medical_record_number'], 'counts' => $before['source']],
                'identity' => $this->maskedIdentity($snapshot),
            ],
            'after_summary' => [
                'canonical' => ['id' => $canonical->id, 'medical_record_number' => $canonical->medical_record_number, 'counts' => $after['canonical']],
                'source_counts' => $after['source'],
                'final_identity' => array_map(fn (array $row): ?string => $row['display'], $this->finalIdentityDisplay($case)),
                // A reversal compares the canonical's identity against this: a
                // post-merge correction must not be silently overwritten.
                'final_identity_hash' => $this->comparator->identityHash($canonical),
            ],
            'moved_records' => $manifest,
            'identity_snapshot_encrypted' => $snapshot,
        ]);

        $this->audit->log(PatientMergeCaseService::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_COMPLETED', null, [
            'case_number' => $case->case_number,
            'canonical_patient_id' => $canonical->id,
            'source_patient_id' => $source->id,
            'requested_by' => $case->requested_by,
            'approved_by' => $approver->id,
            'field_sources' => $case->fieldResolutions->pluck('source', 'field')->all(),
            'moved_counts' => array_map('count', $manifest['tables']),
            'alias_count' => count($manifest['alias_ids']),
            'closed_doctor_assignments' => count($closed),
        ], $approver);

        return $case->fresh();
    }

    /** @return array<string, ?string> field => final raw value, from the encrypted resolutions */
    private function finalIdentity(PatientMergeCase $case): array
    {
        $final = [];

        foreach ($case->fieldResolutions as $resolution) {
            if (! array_key_exists($resolution->field, PatientMergeField::FIELDS)) {
                continue;
            }

            $value = $resolution->final_value_encrypted;
            $final[$resolution->field] = $value === null || $value === '' ? null : (string) $value;
        }

        return $final;
    }

    /** @return array<string, array{source: ?string, display: ?string}> */
    private function finalIdentityDisplay(PatientMergeCase $case): array
    {
        $rows = [];

        foreach ($case->fieldResolutions as $resolution) {
            $rows[$resolution->field] = ['source' => $resolution->source, 'display' => $resolution->final_value_display];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function identitySnapshot(Patient $patient): array
    {
        $snapshot = [
            'id' => $patient->id,
            'medical_record_number' => $patient->medical_record_number,
            'branch_id' => $patient->branch_id,
            'is_active' => (bool) $patient->is_active,
        ];

        foreach (PatientMergeField::fields() as $field) {
            $value = $patient->getAttribute($field);
            $snapshot[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $snapshot;
    }

    /** @return array<string, array<string, ?string>> */
    private function maskedIdentity(array $snapshot): array
    {
        $masked = [];

        foreach ($snapshot as $role => $identity) {
            foreach (PatientMergeField::fields() as $field) {
                $masked[$role][$field] = PatientIdentityMask::field($field, $identity[$field] ?? null);
            }
        }

        return $masked;
    }

    private function createAlias(PatientMergeCase $case, Patient $source, Patient $canonical, User $approver): PatientRmAlias
    {
        $number = (string) $source->medical_record_number;
        $existing = PatientRmAlias::query()->where('alias_medical_record_number', $number)->lockForUpdate()->first();

        if ($existing !== null) {
            if ($existing->revoked_at === null) {
                throw new RuntimeException('An active alias already exists for a Nomor RM that is not yet merged.');
            }

            // A previously reversed merge left a revoked alias for this exact
            // number. Re-arm it under this case rather than fighting the
            // unique index; the revocation is superseded, not erased from audit.
            $existing->forceFill([
                'source_patient_id' => $source->id,
                'canonical_patient_id' => $canonical->id,
                'merge_case_id' => $case->id,
                'created_by' => $approver->id,
                'merged_at' => now(),
                'revoked_at' => null,
            ])->save();

            return $existing;
        }

        return $this->aliases->create([
            'alias_medical_record_number' => $number,
            'source_patient_id' => $source->id,
            'canonical_patient_id' => $canonical->id,
            'alias_type' => 'merged',
            'merge_case_id' => $case->id,
            'created_by' => $approver->id,
            'merged_at' => now(),
        ]);
    }

    /**
     * @param  array{canonical: array<string, int>, source: array<string, int>}  $before
     * @param  array{canonical: array<string, int>, source: array<string, int>}  $after
     */
    private function assertConserved(array $before, array $after): void
    {
        $groups = array_merge(PatientOwnedRecordRegistry::CONSERVED_GROUPS, ['odontograms']);

        foreach ($groups as $group) {
            $expected = ($before['canonical'][$group] ?? 0) + ($before['source'][$group] ?? 0);

            if (($after['canonical'][$group] ?? 0) !== $expected || ($after['source'][$group] ?? 0) !== 0) {
                throw new RuntimeException("Patient merge conservation failed for {$group}: expected {$expected} on canonical and 0 on source.");
            }
        }
    }
}
