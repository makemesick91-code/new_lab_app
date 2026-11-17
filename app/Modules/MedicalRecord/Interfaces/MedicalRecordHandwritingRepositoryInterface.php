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

    /** Rule 240 §4 — the slot holds a sealed page (or a pre-rule page a finalize covered). */
    public const LOCK_SEALED = 'sealed';

    /** Rule 240 §4 — the slot holds a page another encounter wrote (plan default 4). */
    public const LOCK_EARLIER_ENCOUNTER = 'earlier_encounter';

    /**
     * Rule 240 §4 (memo M3.3) — may the stored page in this slot be overwritten by
     * `$liveEncounterId`? Page 1 is every legacy row of the record, page N the
     * pages row. Returns null when it may, otherwise why not:
     *  - LOCK_SEALED: a row is sealed, or — for a row with neither a seal nor an
     *    authoring stamp (written before rule 240) — a final sheet of the patient
     *    was finalized at or after the row was created (a tie locks; a missing
     *    timestamp or a final sheet without `finalized_at` locks; soft-deleted
     *    final sheets count);
     *  - LOCK_EARLIER_ENCOUNTER: a row was written by an encounter other than
     *    `$liveEncounterId` (null locks every stamped row), or a row with neither
     *    a seal nor an authoring stamp that no finalize covers (its encounter is
     *    unknown, so it is never the live encounter's own).
     * A slot with no row is never locked: appending a page stays possible. Read
     * inside the caller's transaction, under its record lock.
     */
    public function pageSlotLock(int $medicalRecordId, int $pageNumber, int $patientId, ?int $liveEncounterId): ?string;

    /**
     * Rule 240 §4 — seal every not-yet-sealed page of the patient's handwriting
     * book (every sheet of the patient, page 1 and pages 2+) for the sheet being
     * finalized. An existing seal is never re-stamped. Returns the rows sealed.
     */
    public function sealBookPages(int $patientId, int $sealingMedicalRecordId): int;
}
