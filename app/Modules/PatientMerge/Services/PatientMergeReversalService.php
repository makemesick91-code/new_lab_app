<?php

namespace App\Modules\PatientMerge\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Interfaces\PatientMergeCaseRepositoryInterface;
use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use App\Modules\PatientMerge\Interfaces\PatientRmAliasRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Support\PatientMergeField;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — Merge Reversal REVIEW.
 *
 * There is no "Undo Merge" button. A reversal is a case of its own:
 *
 *   1. requested with a reason → the merge is assessed;
 *   2. it is SAFE only when it can be PROVEN — nothing was created for the
 *      canonical patient since the merge, every moved row is still where the
 *      merge put it, and neither patient has been merged again;
 *   3. a safe reversal is executed by a DIFFERENT reviewer, moving back
 *      exactly the rows the merge recorded and restoring both identities from
 *      the encrypted snapshot;
 *   4. anything else is SUPERVISED MANUAL RECONCILIATION — the system never
 *      guesses which post-merge visit, RME, odontogram or payment belongs to
 *      which person.
 */
class PatientMergeReversalService
{
    public const MODE_SAFE = 'safe_automatic';

    public const MODE_SUPERVISED = 'supervised_reconciliation';

    public function __construct(
        private readonly PatientMergeCaseRepositoryInterface $cases,
        private readonly PatientMergeOwnershipRepositoryInterface $ownership,
        private readonly PatientRmAliasRepositoryInterface $aliases,
        private readonly AuditLogService $audit,
        private readonly PatientIdentityComparator $comparator,
    ) {}

    public function request(PatientMergeCase $case, User $actor, string $reason): PatientMergeCase
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < PatientMergeCaseService::MIN_REASON) {
            throw ValidationException::withMessages(['reversal_reason' => 'Alasan reversal minimal '.PatientMergeCaseService::MIN_REASON.' karakter.']);
        }

        return DB::transaction(function () use ($case, $actor, $reason): PatientMergeCase {
            $case = $this->locked($case);

            if (! PatientMergeStatus::canTransition($case->status, PatientMergeStatus::REVERSAL_REQUIRED)) {
                throw ValidationException::withMessages(['case' => 'Reversal hanya dapat diajukan untuk penggabungan yang sudah selesai.']);
            }

            $assessment = $this->assess($case);

            $this->cases->write($case, [
                'status' => PatientMergeStatus::REVERSAL_REQUIRED,
                'reversal_requested_by' => $actor->id,
                'reversal_requested_at' => now(),
                'reversal_reason' => $reason,
                'reversal_assessment' => $assessment,
            ]);

            $this->audit->log(PatientMergeCaseService::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_REVERSAL_REQUESTED', null, [
                'case_number' => $case->case_number,
                'mode' => $assessment['mode'],
                'reasons' => $assessment['reasons'],
                'reason_length' => mb_strlen($reason),
            ], $actor);

            return $case->fresh();
        });
    }

    /** Close a reversal review without reversing; the merge stands. */
    public function dismiss(PatientMergeCase $case, User $actor): PatientMergeCase
    {
        return DB::transaction(function () use ($case, $actor): PatientMergeCase {
            $case = $this->locked($case);

            if ($case->status !== PatientMergeStatus::REVERSAL_REQUIRED) {
                throw ValidationException::withMessages(['case' => 'Tidak ada review reversal yang terbuka.']);
            }

            $this->cases->write($case, ['status' => PatientMergeStatus::COMPLETED]);
            $this->audit->log(PatientMergeCaseService::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_REVERSAL_DISMISSED', null, ['case_number' => $case->case_number], $actor);

            return $case->fresh();
        });
    }

    public function execute(PatientMergeCase $case, User $actor): PatientMergeCase
    {
        return DB::transaction(function () use ($case, $actor): PatientMergeCase {
            $case = $this->locked($case);

            if ($case->status !== PatientMergeStatus::REVERSAL_REQUIRED) {
                throw ValidationException::withMessages(['case' => 'Tidak ada review reversal yang terbuka.']);
            }

            if ((int) $case->reversal_requested_by === (int) $actor->id) {
                throw ValidationException::withMessages(['case' => 'Pengaju reversal tidak dapat menjalankan reversal tersebut sendiri.']);
            }

            $patients = $this->ownership->lockPatients([$case->canonical_patient_id, $case->source_patient_id]);
            $canonical = $patients->get($case->canonical_patient_id);
            $source = $patients->get($case->source_patient_id);

            // Re-assessed under the locks — never trust the assessment stored
            // when the review was opened.
            $assessment = $this->assess($case);

            if ($assessment['mode'] !== self::MODE_SAFE) {
                throw new PatientMergeBlockedException('REVERSAL_REQUIRES_SUPERVISION', 'Reversal tidak dapat dijalankan otomatis: '.implode(' ', $assessment['reasons']).' Lakukan rekonsiliasi manual yang diawasi.');
            }

            $manifest = $case->moved_records ?? [];
            $snapshot = $case->identity_snapshot_encrypted ?? [];

            foreach ($manifest['tables'] ?? [] as $table => $ids) {
                $spec = PatientOwnedRecordRegistry::REASSIGN[$table] ?? null;

                if ($spec === null) {
                    continue;
                }

                $this->ownership->reassign($table, $spec['column'], array_map('intval', $ids), $source->id);
            }

            $notes = $manifest['closed_doctor_assignment_notes'] ?? null;

            // Older manifests (none in production) recorded ids only.
            if (! is_array($notes)) {
                $notes = array_fill_keys(array_map('intval', $manifest['closed_doctor_assignments'] ?? []), null);
            }

            $this->ownership->reopenDoctorAssignments($notes);
            $this->aliases->restoreCanonical(array_map('intval', $manifest['repointed_aliases'] ?? []), $source->id);
            $this->aliases->revokeForCase($case->id);

            // Identities back exactly as they were. Both KTP slots are cleared
            // first so neither restore collides with the other's value.
            $canonical->forceFill(['ktp_number' => null])->save();
            $source->forceFill(['ktp_number' => null])->save();

            foreach (['canonical' => $canonical, 'source' => $source] as $role => $patient) {
                $restore = $snapshot[$role] ?? [];
                $ktp = $restore['ktp_number'] ?? null;

                if (is_string($ktp) && $ktp !== '' && $this->ownership->ktpOwner($ktp, [$canonical->id, $source->id]) !== null) {
                    throw new PatientMergeBlockedException('KTP_OWNED_BY_OTHER_PATIENT', 'NIK/KTP asal salah satu pasien kini dipakai pasien lain. Lakukan rekonsiliasi manual yang diawasi.');
                }

                $values = [];

                foreach (PatientMergeField::fields() as $field) {
                    $values[$field] = $restore[$field] ?? null;
                }

                $patient->forceFill($values)->save();
            }

            $source->forceFill([
                'merged_into_patient_id' => null,
                'merged_at' => null,
                'merged_by' => null,
                'merge_case_id' => null,
                'is_active' => (bool) ($snapshot['source']['is_active'] ?? true),
            ])->save();

            $this->cases->write($case, [
                'status' => PatientMergeStatus::REVERSED,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
            ]);

            $this->audit->log(PatientMergeCaseService::AUDIT_ENTITY, $case->id, 'PATIENT_MERGE_REVERSED', null, [
                'case_number' => $case->case_number,
                'canonical_patient_id' => $canonical->id,
                'source_patient_id' => $source->id,
                'restored_counts' => array_map('count', $manifest['tables'] ?? []),
            ], $actor);

            return $case->fresh();
        });
    }

    /**
     * @return array{mode: string, reasons: array<int, string>, activity: array<string, int>}
     */
    public function assess(PatientMergeCase $case): array
    {
        $reasons = [];
        $canonical = Patient::withTrashed()->find($case->canonical_patient_id);
        $source = Patient::withTrashed()->find($case->source_patient_id);
        $activity = [];

        if ($canonical === null || $source === null || $case->merged_at === null) {
            return ['mode' => self::MODE_SUPERVISED, 'reasons' => ['Data penggabungan tidak lengkap.'], 'activity' => []];
        }

        if ((int) $source->merged_into_patient_id !== (int) $canonical->id) {
            $reasons[] = 'Pasien sumber tidak lagi menunjuk ke pasien canonical.';
        }

        if ($canonical->isMerged()) {
            $reasons[] = 'Pasien canonical telah digabungkan lagi ke pasien lain.';
        }

        // A deleted patient cannot receive restored records: they would vanish
        // from every patient-scoped screen.
        if ($canonical->trashed() || $source->trashed()) {
            $reasons[] = 'Salah satu pasien telah dihapus setelah penggabungan.';
        }

        // The canonical identity was edited after the merge (a correction by
        // front office). Restoring the snapshot would silently undo it.
        $finalHash = $case->after_summary['final_identity_hash'] ?? null;

        if (! is_string($finalHash) || ! hash_equals($finalHash, $this->comparator->identityHash($canonical))) {
            $reasons[] = 'Identitas pasien canonical telah berubah setelah penggabungan.';
        }

        $manifest = $case->moved_records ?? [];
        $ignore = ['trx_rme_patient_doctor_assignments' => array_map('intval', $manifest['closed_doctor_assignments'] ?? [])];
        $activity = $this->ownership->activitySince($canonical->id, $case->merged_at, $manifest['tables'] ?? [], $ignore, $manifest['high_water'] ?? []);

        if ($activity !== []) {
            $reasons[] = 'Terdapat aktivitas setelah penggabungan pada data pasien canonical ('.implode(', ', array_map(
                fn (string $table, int $count): string => (PatientOwnedRecordRegistry::REASSIGN[$table]['label'] ?? $table).": {$count}",
                array_keys($activity),
                $activity,
            )).').';
        }

        foreach (($case->moved_records['tables'] ?? []) as $table => $ids) {
            $spec = PatientOwnedRecordRegistry::REASSIGN[$table] ?? null;

            if ($spec === null) {
                $reasons[] = "Tabel {$table} tidak lagi terdaftar.";

                continue;
            }

            $stillOwned = count(array_intersect(
                array_map('intval', $ids),
                $this->ownership->idsFor($table, $spec['column'], $canonical->id),
            ));

            if ($stillOwned !== count($ids)) {
                $reasons[] = "Sebagian data {$spec['label']} yang dipindahkan sudah tidak lagi dimiliki pasien canonical.";
            }
        }

        return [
            'mode' => $reasons === [] ? self::MODE_SAFE : self::MODE_SUPERVISED,
            'reasons' => $reasons,
            'activity' => $activity,
        ];
    }

    private function locked(PatientMergeCase $case): PatientMergeCase
    {
        return $this->cases->lockForUpdate($case->id)
            ?? throw ValidationException::withMessages(['case' => 'Pengajuan merge tidak ditemukan.']);
    }
}
