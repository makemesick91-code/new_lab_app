<?php

namespace App\Modules\PatientMerge\Interfaces;

use App\Modules\Patient\Models\Patient;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reads and moves patient-owned rows across the tables listed in
 * PatientOwnedRecordRegistry. Table and column names only ever come from
 * that registry — never from input.
 */
interface PatientMergeOwnershipRepositoryInterface
{
    /**
     * Lock patient rows (soft-deleted included) in id order, so two merges
     * touching overlapping pairs always queue instead of deadlocking.
     *
     * @param  array<int, int>  $patientIds
     * @return Collection<int, Patient>
     */
    public function lockPatients(array $patientIds): Collection;

    /** @return array<string, int> registry group => count, plus odontograms and receivables */
    public function countsFor(int $patientId): array;

    /** @return array<int, int> */
    public function idsFor(string $table, string $column, int $patientId): array;

    /** @param  array<int, int>  $ids */
    public function reassign(string $table, string $column, array $ids, int $toPatientId): int;

    /**
     * Ids of the source's ACTIVE doctor assignments whose doctor also has an
     * active assignment on the canonical patient.
     *
     * @return array<int, int>
     */
    public function collidingActiveDoctorAssignments(int $sourcePatientId, int $canonicalPatientId): array;

    /**
     * Close the given assignments, appending a note (the original is kept).
     *
     * @param  array<int, int>  $ids
     * @return array<int, ?string> id => the note as it was before
     */
    public function closeDoctorAssignments(array $ids, CarbonInterface $at, string $note): array;

    /** @param  array<int, ?string>  $originalNotesById  id => note to restore */
    public function reopenDoctorAssignments(array $originalNotesById): void;

    /** Non-terminal visits (registered/waiting/in_progress/cashier_pending) across the patients. */
    public function activeEncounterCount(array $patientIds): int;

    /** Active SATUSEHAT patient identifiers held by a patient (non-FK reference). */
    public function activeSatusehatPatientIdentifiers(int $patientId): int;

    /** Latest clinical date of the patient's PUBLISHED legacy RME archive (Y-m-d). */
    public function latestPublishedLegacyRmeDate(int $patientId): ?string;

    /** Latest date of the patient's PUBLISHED legacy odontogram archive (Y-m-d). */
    public function latestPublishedLegacyOdontogramDate(int $patientId): ?string;

    /** The patient (other than the excluded ones) that holds this KTP/NIK, soft-deleted included. */
    public function ktpOwner(string $ktp, array $exceptPatientIds): ?int;

    /**
     * Foreign keys to mst_patients in the LIVE schema that the registry does
     * not know about. Non-empty means the merge must refuse.
     *
     * @return array<int, string> "table.column"
     */
    public function unregisteredPatientForeignKeys(): array;

    /**
     * Downstream activity on the canonical patient since an instant, across
     * EVERY registered table: rows created for it (excluding the rows the
     * merge moved), moved rows changed afterwards, and child rows (odontogram,
     * handwriting, diagnoses) created or changed on anything it now owns.
     *
     * @param  array<string, array<int, int>>  $movedByTable
     * @param  array<string, array<int, int>>  $ignoreUpdatedIds  rows the merge itself stamped
     * @param  array<string, int>  $highWater  max id per table recorded inside the merge
     * @return array<string, int> table => count, non-zero only
     */
    public function activitySince(int $patientId, CarbonInterface $since, array $movedByTable = [], array $ignoreUpdatedIds = [], array $highWater = []): array;

    /** @return array<string, int> table => max(id) now, for every watched table */
    public function highWaterMarks(): array;
}
