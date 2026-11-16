<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Services;

use App\Models\User;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeAmendment\Interfaces\RmeRecordVersionRepositoryInterface;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §5) — the version history of
 * a final sheet and of a finalized odontogram.
 *
 * - Version 1 is written by finalize (`final`, recordFinal()) inside the finalize
 *   transaction, after the visit and subject locks. A record finalized before
 *   rule 240 gets a `baseline` version 1 the first time an amendment needs one
 *   (ensureBaseline(), called by M3.3 PR5 under the same locks). Both are
 *   idempotent: a subject that already has version 1 gets it back.
 * - A snapshot is the clinical record as it stands: for a sheet, its SOAP fields
 *   and notes, its structured diagnoses and the LOCKED pages of the patient's
 *   handwriting book (object key and SHA-256, never bytes); for a chart, its
 *   payload. Nothing is logged and no audit row carries a snapshot.
 * - Each version's hash is SHA-256 over a canonical JSON encoding of its subject,
 *   number, kind, previous hash and snapshot, so the versions form a chain
 *   (verifyChain()). PostgreSQL `jsonb` does not keep key order, so the encoding
 *   sorts object keys recursively.
 * - Reading is authorized like reading the record itself (the subject's `view`
 *   ability), asked here so every caller inherits it.
 */
final class RmeRecordVersionService
{
    public const SNAPSHOT_SCHEMA_VERSION = 1;

    public function __construct(
        private readonly RmeRecordVersionRepositoryInterface $versions,
        private readonly MedicalRecordHandwritingRepositoryInterface $handwritings,
    ) {}

    /**
     * Version 1 of a subject just finalized. Must run inside the finalize
     * transaction, after the subject's lock; returns the existing version 1 when
     * there is one (a re-finalize writes nothing).
     */
    public function recordFinal(MedicalRecord|Odontogram $subject, ?int $actorId): RmeRecordVersion
    {
        // A finalize has just sealed every page of the book (rule 240 §4.1), so the
        // locked pages are the whole book whatever encounter is live.
        return $this->firstVersion($subject, RmeRecordVersion::KIND_FINAL, $actorId, null);
    }

    /**
     * Version 1 of a subject finalized before rule 240 (no version yet), written
     * from the record as it stands, before an amendment changes it. Must run
     * inside the amendment transaction, after the subject's lock; returns the
     * existing version 1 when there is one. `$liveEncounterId` is the amending
     * encounter: its own unsealed pages are still writable and so not "locked"
     * (rule 240 §4.4); null counts every stamped page as locked.
     */
    public function ensureBaseline(MedicalRecord|Odontogram $subject, ?int $actorId, ?int $liveEncounterId = null): RmeRecordVersion
    {
        return $this->firstVersion($subject, RmeRecordVersion::KIND_BASELINE, $actorId, $liveEncounterId);
    }

    /** @return Collection<int, RmeRecordVersion> */
    public function versionsFor(User $actor, MedicalRecord|Odontogram $subject): Collection
    {
        Gate::forUser($actor)->authorize('view', $subject);
        [$type, $id] = $this->subjectKey($subject);

        return $this->versions->listFor($type, $id);
    }

    public function version(User $actor, MedicalRecord|Odontogram $subject, int $version): ?RmeRecordVersion
    {
        Gate::forUser($actor)->authorize('view', $subject);
        [$type, $id] = $this->subjectKey($subject);

        return $this->versions->findVersion($type, $id, $version);
    }

    /**
     * The "Diamandemen n×" print marker (M3.3 PR5 renders it): the number of
     * applied amendments, counted from the immutable versions rather than kept in
     * a second column that would have to be written alongside every amendment.
     * The print that shows it is authorized on its own.
     */
    public function amendmentCount(MedicalRecord|Odontogram $subject): int
    {
        [$type, $id] = $this->subjectKey($subject);

        return $this->versions->countKind($type, $id, RmeRecordVersion::KIND_AMENDMENT);
    }

    /**
     * True when the subject's versions are numbered 1..n without a gap, each
     * previous hash is the hash of the version before it (none for version 1),
     * and each stored hash matches its stored content.
     */
    public function verifyChain(MedicalRecord|Odontogram $subject): bool
    {
        [$type, $id] = $this->subjectKey($subject);
        $previous = null;
        $expected = 1;

        foreach ($this->versions->listFor($type, $id) as $version) {
            if ($version->version !== $expected
                || $version->previous_hash !== $previous
                || ! hash_equals($this->hashFor($type, $id, $version->version, $version->kind, $version->previous_hash, $version->snapshot), $version->hash)) {
                return false;
            }

            $previous = $version->hash;
            $expected++;
        }

        return true;
    }

    /** @param array<string, mixed> $snapshot */
    public function hashFor(string $subjectType, int $subjectId, int $version, string $kind, ?string $previousHash, array $snapshot): string
    {
        return hash('sha256', self::canonicalJson([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'version' => $version,
            'kind' => $kind,
            'previous_hash' => $previousHash,
            'snapshot' => $snapshot,
        ]));
    }

    /**
     * The record as it stands — what a version stores.
     *
     * @return array<string, mixed>
     */
    public function snapshotOf(MedicalRecord|Odontogram $subject, ?int $liveEncounterId = null): array
    {
        if ($subject instanceof Odontogram) {
            return [
                'odontogram_id' => (int) $subject->getKey(),
                'clinic_visit_id' => (int) $subject->clinic_visit_id,
                'branch_id' => $subject->branch_id !== null ? (int) $subject->branch_id : null,
                'medical_record_id' => $subject->medical_record_id !== null ? (int) $subject->medical_record_id : null,
                'status' => $subject->status,
                'finalized_at' => $subject->finalized_at?->toIso8601String(),
                'finalized_by' => $subject->finalized_by !== null ? (int) $subject->finalized_by : null,
                'summary_notes' => $subject->summary_notes,
                'additional_conditions' => $subject->additional_conditions,
                'tooth_map_payload' => $subject->tooth_map_payload,
            ];
        }

        $diagnoses = $subject->diagnoses()
            ->with('clinicalDiagnosis:id,code_system,code,display')
            ->reorder('id')
            ->get()
            ->map(fn (MedicalRecordDiagnosis $diagnosis): array => [
                'id' => (int) $diagnosis->getKey(),
                'clinical_diagnosis_id' => (int) $diagnosis->clinical_diagnosis_id,
                'code_system' => $diagnosis->clinicalDiagnosis?->code_system,
                'code' => $diagnosis->clinicalDiagnosis?->code,
                'display' => $diagnosis->clinicalDiagnosis?->display,
                'diagnosis_role' => $diagnosis->diagnosis_role,
                'clinical_status' => $diagnosis->clinical_status,
                'verification_status' => $diagnosis->verification_status,
                'notes' => $diagnosis->notes,
                'diagnosed_at' => $diagnosis->diagnosed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'medical_record_id' => (int) $subject->getKey(),
            'clinic_visit_id' => (int) $subject->clinic_visit_id,
            'branch_id' => $subject->branch_id !== null ? (int) $subject->branch_id : null,
            'status' => $subject->status,
            'finalized_at' => $subject->finalized_at?->toIso8601String(),
            'finalized_by' => $subject->finalized_by !== null ? (int) $subject->finalized_by : null,
            'fields' => [
                'subjective' => $subject->subjective,
                'objective' => $subject->objective,
                'assessment' => $subject->assessment,
                'plan' => $subject->plan,
                'notes' => $subject->notes,
            ],
            'diagnoses' => $diagnoses,
            'pages' => $this->handwritings->lockedBookPages((int) $subject->patient_id, $liveEncounterId),
        ];
    }

    private function firstVersion(MedicalRecord|Odontogram $subject, string $kind, ?int $actorId, ?int $liveEncounterId): RmeRecordVersion
    {
        // Under the caller's transaction and subject lock, never on its own: a
        // version must commit or roll back with the act that makes it true.
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A record version is written only inside the finalize or amendment transaction.');
        }

        $final = $subject instanceof Odontogram
            ? $subject->isFinalized()
            : $subject->status === MedicalRecord::STATUS_FINAL;

        if (! $final) {
            throw new LogicException('Only a final sheet or a finalized odontogram has versions.');
        }

        [$type, $id] = $this->subjectKey($subject);
        $existing = $this->versions->findVersion($type, $id, 1);

        if ($existing !== null) {
            return $existing;
        }

        $snapshot = $this->snapshotOf($subject, $liveEncounterId);
        // Stored as it will be read back, so the hash is over what jsonb returns.
        $snapshot = json_decode(self::canonicalJson($snapshot), true, flags: JSON_THROW_ON_ERROR);

        return $this->versions->create([
            'subject_type' => $type,
            'subject_id' => $id,
            'version' => 1,
            'kind' => $kind,
            'snapshot_schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'snapshot' => $snapshot,
            'previous_hash' => null,
            'hash' => $this->hashFor($type, $id, 1, $kind, null, $snapshot),
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: int} */
    private function subjectKey(MedicalRecord|Odontogram $subject): array
    {
        return [
            $subject instanceof Odontogram ? RmeRecordVersion::SUBJECT_ODONTOGRAM : RmeRecordVersion::SUBJECT_MEDICAL_RECORD,
            (int) $subject->getKey(),
        ];
    }

    /**
     * Canonical JSON: object keys sorted recursively, lists in order, the same
     * flags as RmeInvoiceSnapshot so the encoding is stable across PHP and jsonb.
     */
    private static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $child): mixed => self::canonical($child), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
