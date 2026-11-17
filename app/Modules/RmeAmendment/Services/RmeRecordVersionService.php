<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Services;

use App\Models\User;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Models\DiagnosisRequirementOverride;
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
 *   (ensureBaseline(), under the amendment's locks). Both are idempotent: a
 *   subject that already has version 1 gets it back. Version n+1 (`amendment`)
 *   is written by an applied amendment (recordAmendment(), M3.3 PR5), chained to
 *   the latest version. Every version is written by write(): the stored text and
 *   the hash come from the same canonicalJson() result.
 * - A snapshot is the clinical record as it stands: for a sheet, its SOAP fields
 *   and notes, its structured diagnoses, its emergency diagnosis overrides and
 *   the LOCKED pages of the patient's handwriting book (object key and SHA-256,
 *   never bytes); for a chart, its payload. Nothing is logged and no audit row
 *   carries a snapshot.
 * - Each version's hash is SHA-256 over a canonical JSON encoding of its subject,
 *   number, kind, previous hash, snapshot schema version and snapshot (object keys
 *   sorted recursively), so
 *   the versions form a chain (verifyChain()). The snapshot is stored as that
 *   canonical text, verbatim (a `json` column), and the hash is always computed
 *   over the stored text itself, never over a re-encoding of its decoded value:
 *   a decode/re-encode does not round-trip every number (5.0, -0.0).
 * - Reading is authorized like reading the record itself (the subject's `view`
 *   ability), asked here so every caller inherits it.
 */
final class RmeRecordVersionService
{
    public const SNAPSHOT_SCHEMA_VERSION = 1;

    /** The flags of RmeInvoiceSnapshot. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

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
     * and each stored hash matches its stored content — the snapshot text exactly
     * as stored (RmeRecordVersion::snapshotJson()).
     */
    public function verifyChain(MedicalRecord|Odontogram $subject): bool
    {
        [$type, $id] = $this->subjectKey($subject);
        $previous = null;
        $expected = 1;

        foreach ($this->versions->listFor($type, $id) as $version) {
            if ($version->version !== $expected
                || $version->previous_hash !== $previous
                || ! hash_equals($this->hashFor($type, $id, $version->version, $version->kind, $version->previous_hash, $version->snapshotJson(), $version->snapshot_schema_version), $version->hash)) {
                return false;
            }

            $previous = $version->hash;
            $expected++;
        }

        return true;
    }

    /**
     * SHA-256 of the canonical JSON of the version: its subject, number, kind,
     * previous hash, snapshot schema version (M3.3 PR5: a snapshot read under the
     * wrong schema is detected) and snapshot. `$snapshot` is either the
     * snapshot's stored text (hashed byte for byte) or an array (hashed as its
     * canonical text, which is what a version stores).
     *
     * @param  array<string, mixed>|string  $snapshot
     */
    public function hashFor(string $subjectType, int $subjectId, int $version, string $kind, ?string $previousHash, array|string $snapshot, int $snapshotSchemaVersion): string
    {
        $snapshotJson = is_string($snapshot) ? $snapshot : self::canonicalJson($snapshot);
        $encode = static fn (mixed $value): string => json_encode($value, self::JSON_FLAGS);

        // The canonical (key-sorted) envelope, with the snapshot spliced in as its
        // exact text: equal to canonicalJson() of the whole envelope whenever the
        // text is the snapshot's canonical encoding, and never a re-encoding of it.
        return hash('sha256', '{"kind":'.$encode($kind)
            .',"previous_hash":'.$encode($previousHash)
            .',"snapshot":'.$snapshotJson
            .',"snapshot_schema_version":'.$encode($snapshotSchemaVersion)
            .',"subject_id":'.$encode($subjectId)
            .',"subject_type":'.$encode($subjectType)
            .',"version":'.$encode($version)
            .'}');
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
            // Rule 240 §1 — the emergency override is part of the locked sheet and
            // can be what let it finalize. The granting account by id; no patient
            // identity.
            'diagnosis_overrides' => $subject->diagnosisRequirementOverrides()
                ->reorder('id')
                ->get(['id', 'used_by', 'reason', 'expires_at', 'created_at'])
                ->map(fn (DiagnosisRequirementOverride $override): array => [
                    'id' => (int) $override->getKey(),
                    'reason' => $override->reason,
                    'used_by' => $override->used_by !== null ? (int) $override->used_by : null,
                    'expires_at' => $override->expires_at?->toIso8601String(),
                    'created_at' => $override->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'pages' => $this->handwritings->lockedBookPages((int) $subject->patient_id, $liveEncounterId),
        ];
    }

    private function firstVersion(MedicalRecord|Odontogram $subject, string $kind, ?int $actorId, ?int $liveEncounterId): RmeRecordVersion
    {
        $this->assertWritable($subject);

        [$type, $id] = $this->subjectKey($subject);
        $existing = $this->versions->findVersion($type, $id, 1);

        if ($existing !== null) {
            return $existing;
        }

        return $this->write($subject, 1, $kind, null, $actorId, $liveEncounterId);
    }

    /**
     * Version n+1 (`amendment`) of a subject an approved amendment has just
     * changed, chained to `$latest`. Must run inside the amendment transaction,
     * after the subject's lock, with `$latest` the subject's latest version read
     * under that lock (anything else is refused: the base of an approval must
     * still be current, rule 240 §7).
     */
    public function recordAmendment(MedicalRecord|Odontogram $subject, RmeRecordVersion $latest, ?int $actorId, ?int $liveEncounterId = null): RmeRecordVersion
    {
        $this->assertWritable($subject);
        [$type, $id] = $this->subjectKey($subject);
        $current = $this->versions->latestFor($type, $id);

        if ($current === null || (int) $current->getKey() !== (int) $latest->getKey()) {
            throw new LogicException('An amendment version is chained only to the latest version of the record.');
        }

        return $this->write($subject, (int) $latest->version + 1, RmeRecordVersion::KIND_AMENDMENT, (string) $latest->hash, $actorId, $liveEncounterId);
    }

    /**
     * The one writer of a version: the canonical text is what is stored,
     * verbatim, and the hash is computed over that same text (review MEDIUM-C);
     * nothing re-encodes it on the way in or out.
     */
    private function write(MedicalRecord|Odontogram $subject, int $number, string $kind, ?string $previousHash, ?int $actorId, ?int $liveEncounterId): RmeRecordVersion
    {
        [$type, $id] = $this->subjectKey($subject);
        $snapshot = self::canonicalJson($this->snapshotOf($subject, $liveEncounterId));

        return $this->versions->create([
            'subject_type' => $type,
            'subject_id' => $id,
            'version' => $number,
            'kind' => $kind,
            'snapshot_schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'snapshot' => $snapshot,
            'previous_hash' => $previousHash,
            'hash' => $this->hashFor($type, $id, $number, $kind, $previousHash, $snapshot, self::SNAPSHOT_SCHEMA_VERSION),
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    /** Inside the caller's transaction, and only for a final sheet or a finalized chart. */
    private function assertWritable(MedicalRecord|Odontogram $subject): void
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
     * Canonical JSON: object keys sorted recursively, lists in order. Produced
     * once, when a version is written; verifying hashes the stored text.
     */
    private static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonical($value), self::JSON_FLAGS);
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
