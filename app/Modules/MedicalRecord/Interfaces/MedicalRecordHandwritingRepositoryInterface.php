<?php

namespace App\Modules\MedicalRecord\Interfaces;

use App\Modules\MedicalRecord\Models\MedicalRecord;
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

    /**
     * Rule 240 §5 (memo M3.3 PR3) — every LOCKED page of the patient's handwriting
     * book as it stands, for a record version's snapshot, by the rule of
     * pageSlotLock() for `$liveEncounterId` (a row is listed exactly when that
     * answers a lock for it): sealed; written before rule 240 (no authoring
     * stamp), whether or not a finalize covers it; or written by an encounter
     * other than `$liveEncounterId` (null: every stamped page). Object
     * keys and SHA-256 only, never bytes; an inline data-URI page reports no key.
     * An amendment page (rule 240 §8) also names its `rme_amendment_id`; no other
     * page carries the key. Ordered by record, page, row.
     *
     * @return list<array<string, mixed>>
     */
    public function lockedBookPages(int $patientId, ?int $liveEncounterId = null): array;

    /**
     * Rule 240 §8 (memo M3.3 PR6) — append an approved handwriting correction to
     * the book as its NEXT page (pages table, never page 1, never in place of a
     * page), sealed at once and stamped with the amendment. Only inside the
     * approve transaction, under the book record's lock (LogicException
     * otherwise). The row is stored on the book's visit; `$page` names
     * `handwriting_path`, `handwriting_hash`, `authored_clinic_visit_id` (the
     * amended encounter), `sealed_by_medical_record_id` (the amended sheet),
     * `rme_amendment_id`, `created_by` and `updated_by`.
     *
     * @param  array<string, mixed>  $page
     */
    public function appendSealedAmendmentPage(MedicalRecord $book, array $page): MedicalRecordHandwritingPage;

    /** Rule 240 §8 — the book page an applied amendment appended, if any. */
    public function findAmendmentPage(int $amendmentId): ?MedicalRecordHandwritingPage;

    /**
     * Rule 243 §2.8 (doctor encounter scope) — the authoring encounters of a
     * record's pages (rule 240 §4.4, `authored_clinic_visit_id`), keyed by page
     * number: page 1 from every legacy row of the record, page N from its pages
     * row. Unstamped rows (written before rule 240) name nothing, so a page with
     * no stamped row is absent. Two queries, whatever the page count.
     *
     * @return array<int, list<int>>
     */
    public function pageAuthors(int $medicalRecordId): array;
}
