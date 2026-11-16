<?php

namespace App\Modules\MedicalRecord\Interfaces;

use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;

interface MedicalRecordHandwritingRepositoryInterface
{
    public function findByMedicalRecordId(int $medicalRecordId): ?MedicalRecordHandwriting;

    /** @param array<string, mixed> $data */
    public function create(array $data): MedicalRecordHandwriting;

    /** @param array<string, mixed> $data */
    public function update(MedicalRecordHandwriting $handwriting, array $data): MedicalRecordHandwriting;

    // Sprint 60 — Page 2+ live in the additive pages table. Page 1 stays in the
    // legacy table above (read-through), so these never touch Page 1.
    public function findPageByMedicalRecordIdAndPage(int $medicalRecordId, int $pageNumber): ?MedicalRecordHandwritingPage;

    /** @param array<string, mixed> $data */
    public function createPage(array $data): MedicalRecordHandwritingPage;

    /** @param array<string, mixed> $data */
    public function updatePage(MedicalRecordHandwritingPage $page, array $data): MedicalRecordHandwritingPage;

    /**
     * The next addable page number for a Medical Record. Page 1 is the legacy
     * row, so the minimum returned value is 2.
     */
    public function nextPageNumber(int $medicalRecordId): int;

    /**
     * Rule 240 §4 (memo M3.3) — is the stored page in this slot locked? Page 1 is
     * every legacy row of the record, page N the pages row. Locked when a row is
     * sealed, or — for a row with neither a seal nor an authoring stamp (written
     * before rule 240) — when a final sheet of the patient was finalized at or
     * after the row was created (a tie locks; a missing timestamp locks). A slot
     * with no row is never locked: appending a page stays possible. Read inside
     * the caller's transaction, under its record lock.
     */
    public function pageSlotLocked(int $medicalRecordId, int $pageNumber, int $patientId): bool;

    /**
     * Rule 240 §4 — seal every not-yet-sealed page of the patient's handwriting
     * book (every sheet of the patient, page 1 and pages 2+) for the sheet being
     * finalized, except the pages authored by `$sparedEncounterId` (the live
     * encounter, when the sheet finalized is not its own). An existing seal is
     * never re-stamped. Returns the number of rows sealed.
     */
    public function sealBookPages(int $patientId, int $sealingMedicalRecordId, ?int $sparedEncounterId = null): int;
}
