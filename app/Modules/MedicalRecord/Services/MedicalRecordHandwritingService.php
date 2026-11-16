<?php

namespace App\Modules\MedicalRecord\Services;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Support\Storage\ClinicalEvidenceStorage;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * DMS-RME-REVISION-PROGRAM-6 / P6-HW-01..04 — persist one handwriting RM page
 * as ONE unit: the medical-record draft (when the patient has no book yet), the
 * page row and its object on the private clinical disk either all exist
 * afterwards or none of them does. Closes Program 5 residual R5-3.
 *
 * Why the order inside the transaction is what it is:
 *
 *  1. `$resolveTarget` runs first. It may create the canonical draft and it
 *     authorizes the record; anything it throws rolls the draft back, so a
 *     refused request never leaves a book behind.
 *  2. The medical-record row is locked FOR NO KEY UPDATE before the page is
 *     chosen. Page 1 has no unique index, so without the lock two concurrent
 *     first saves each found "no row" and inserted one; page 2+ raced into the
 *     UNIQUE(medical_record_id, page_number) index and answered HTTP 500. The
 *     lock serialises saves of the same book only. It is deliberately NOT
 *     FOR UPDATE: the page insert's foreign key takes FOR KEY SHARE on this row,
 *     and FOR UPDATE would make every other writer referencing the record wait
 *     (the deadlock class Program 5 removed from the visit row, rule 223 §1).
 *  3. The object key is built from the page that will actually be written (the
 *     clamped one, never the raw request value) plus a random suffix. A
 *     second-granular key let a later save in the same second replace the
 *     bytes a committed row already pointed at, and let two rows share one
 *     object; the stored sha256 then described bytes that no longer existed.
 *  4. The object is written, then the row. A database transaction cannot undo
 *     a filesystem write, so a failure INSIDE the transaction body removes the
 *     object this call wrote. That object has a fresh key no other row can
 *     reference, so removing it can never destroy earlier evidence. A failure
 *     after the body (COMMIT, after-commit callbacks) keeps the object, because
 *     the row may already be committed. A replaced page's previous object is
 *     never deleted (it stays as history, exactly as before).
 */
final class MedicalRecordHandwritingService
{
    /** DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-6-1) — a save over a page changed since this device loaded it. */
    public const MESSAGE_STALE_PAGE = 'Halaman telah diubah di perangkat lain. Muat ulang halaman sebelum menyimpan agar tulisan tidak tertimpa.';

    /** Rule 240 §4 (memo M3.3) — an overwrite of a page sealed by a finalized sheet. */
    public const MESSAGE_SEALED_PAGE = 'Halaman RM ini sudah terkunci karena termasuk rekam medis yang telah final. Koreksi hanya melalui amandemen yang disetujui; tulis catatan baru pada halaman baru.';

    /** Rule 240 §4.4 (plan default 4) — an overwrite of a page another encounter wrote. */
    public const MESSAGE_EARLIER_ENCOUNTER_PAGE = 'Halaman RM ini ditulis pada pemeriksaan lain dan tidak dapat ditimpa. Koreksi hanya melalui amandemen yang disetujui; tulis catatan baru pada halaman baru.';

    public function __construct(
        private readonly MedicalRecordHandwritingRepositoryInterface $handwritings,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
    ) {}

    /**
     * `$resolveTarget` returns the authorized record and the visit that stores
     * it, and runs inside the transaction. `$png` is already decoded, validated
     * and blank-guarded.
     *
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-6-1, owner decision 2026-11-16) —
     * optimistic concurrency per page. `$expectedRevision` is the revision the
     * saving device loaded (MedicalRecord::handwritingRevision(), `none` for a
     * page it opened as new). It is compared, under the record lock and before
     * any object is written, with the page as stored now; a different revision
     * means another device saved this page meanwhile, and the save is refused
     * instead of silently replacing that drawing. Null skips the comparison
     * (a caller that never loaded a page, e.g. the first save of a new book).
     *
     * Rule 240 §4 (memo M3.3, owner decision 2026-11-17) — after that compare and
     * before any object is written, an overwrite of an existing page is refused
     * unless the page is not sealed AND the live encounter wrote it: a page sealed
     * when a sheet was finalized, a page from before rule 240 that a finalize
     * already covered, and a page another encounter wrote (plan default 4: a page
     * after its encounter only as an amendment item) are all refused. Decided on
     * the rows read under the visit and record locks, inside this transaction, so
     * it binds every caller, Super Admin included. A NEW page (an empty slot) is
     * never locked: appending in a live, consented encounter stays allowed
     * (OD-5-6, R7-1). The optional third element `$resolveTarget` returns is the
     * authorized encounter; it is the live encounter for this decision and is
     * stamped on the page as its author, because the page itself is stored on the
     * book's visit. Without it every stamped page is refused (fail closed).
     *
     * @param  Closure(): array{0: MedicalRecord, 1: ClinicVisit, 2?: ClinicVisit|null}  $resolveTarget
     * @return array{medical_record: MedicalRecord, storage_visit: ClinicVisit, page_number: int}
     */
    public function save(Closure $resolveTarget, int $requestedPage, string $png, ?int $actorId, ?string $expectedRevision = null): array
    {
        $writtenKey = null;
        $bodyDone = false;

        try {
            return DB::transaction(function () use ($resolveTarget, $requestedPage, $png, $actorId, $expectedRevision, &$writtenKey, &$bodyDone): array {
                [$medicalRecord, $storageVisit, $encounter] = array_pad($resolveTarget(), 3, null);

                $this->medicalRecords->lockForHandwriting($medicalRecord->id);

                $page = $requestedPage <= 1
                    ? 1
                    : min($requestedPage, $this->handwritings->nextPageNumber($medicalRecord->id));

                if ($expectedRevision !== null
                    && ! hash_equals($this->currentRevision($medicalRecord, $page), $expectedRevision)) {
                    throw ValidationException::withMessages([
                        'handwriting_data' => self::MESSAGE_STALE_PAGE,
                    ]);
                }

                $authoredBy = $encounter instanceof ClinicVisit ? (int) $encounter->getKey() : null;

                // Rule 240 §4 — an existing page is overwritten only when it is not
                // sealed and the live encounter wrote it (plan default 4); nothing
                // has been written yet, so a refusal leaves no object and no row.
                $lockMessage = self::lockMessage(
                    $this->handwritings->pageSlotLock($medicalRecord->id, $page, (int) $medicalRecord->patient_id, $authoredBy),
                );

                if ($lockMessage !== null) {
                    throw ValidationException::withMessages([
                        'handwriting_data' => $lockMessage,
                    ]);
                }

                $writtenKey = $this->objectKey($storageVisit, $page);
                ClinicalEvidenceStorage::disk()->put($writtenKey, $png);
                $hash = hash('sha256', $png);

                if ($page === 1) {
                    $this->savePageOne($storageVisit, $medicalRecord, $writtenKey, $hash, $actorId, $authoredBy);
                } else {
                    $this->savePage($storageVisit, $medicalRecord, $page, $writtenKey, $hash, $actorId, $authoredBy);
                }

                $bodyDone = true;

                return [
                    'medical_record' => $medicalRecord,
                    'storage_visit' => $storageVisit,
                    'page_number' => $page,
                ];
            });
        } catch (Throwable $e) {
            // Only while the body had NOT finished: a failure after it (the COMMIT
            // itself, or an after-commit callback) leaves the outcome unknown — the
            // server may already have committed the row — so the object is kept.
            // An orphaned private object is the safe failure; deleting evidence a
            // committed row names is not.
            if ($writtenKey !== null && ! $bodyDone) {
                $this->discardUncommitted($writtenKey);
            }

            throw $e;
        }
    }

    /**
     * Rule 240 §4 — presentation twin of the refusal in save(): why this stored
     * page may not be overwritten by the live encounter, or null when it may. The
     * page renders read-only with this reason, so it offers no save the server
     * would refuse (rule 135). Never the authority.
     */
    public function pageLockMessage(MedicalRecord $medicalRecord, int $page, ?int $liveEncounterId): ?string
    {
        return self::lockMessage($this->handwritings->pageSlotLock(
            (int) $medicalRecord->getKey(),
            $page,
            (int) $medicalRecord->patient_id,
            $liveEncounterId,
        ));
    }

    /** Rule 240 §4 — is the stored page sealed (by a finalize, or a pre-rule page a finalize covered)? */
    public function isPageSealed(MedicalRecord $medicalRecord, int $page): bool
    {
        return $this->pageLockMessage($medicalRecord, $page, null) === self::MESSAGE_SEALED_PAGE;
    }

    private static function lockMessage(?string $lock): ?string
    {
        return match ($lock) {
            MedicalRecordHandwritingRepositoryInterface::LOCK_SEALED => self::MESSAGE_SEALED_PAGE,
            MedicalRecordHandwritingRepositoryInterface::LOCK_EARLIER_ENCOUNTER => self::MESSAGE_EARLIER_ENCOUNTER_PAGE,
            default => null,
        };
    }

    /** The page's revision as stored now (read under the record lock). */
    private function currentRevision(MedicalRecord $medicalRecord, int $page): string
    {
        // Page 1 is read with the SAME selector the page renders from
        // (MedicalRecord::latestHandwriting(): saved_at desc, id desc). Two
        // page-1 rows can exist (saves before the record lock existed); with a
        // different order here every save would be refused as stale forever.
        $row = $page === 1
            ? $medicalRecord->latestHandwriting()
            : $this->handwritings->findPageByMedicalRecordIdAndPage($medicalRecord->id, $page);

        return MedicalRecord::handwritingRevision($row?->handwriting_path);
    }

    private function objectKey(ClinicVisit $storageVisit, int $page): string
    {
        return sprintf(
            'handwritings/%d/%d/handwriting_p%d_%s_%s.png',
            $storageVisit->branch_id,
            $storageVisit->id,
            $page,
            now()->format('YmdHis'),
            bin2hex(random_bytes(8)),
        );
    }

    /**
     * Page 1 — legacy read-through row, written exactly as before so every
     * existing reader (print, receipt, workspace) keeps working.
     */
    private function savePageOne(ClinicVisit $storageVisit, MedicalRecord $medicalRecord, string $path, string $hash, ?int $actorId, ?int $authoredBy): void
    {
        $existing = $this->handwritings->findByMedicalRecordId($medicalRecord->id);

        if ($existing !== null) {
            $this->handwritings->update($existing, [
                'handwriting_path' => $path,
                'handwriting_hash' => $hash,
                'saved_at' => now(),
            ] + $this->authoredStamp($authoredBy));

            return;
        }

        $this->handwritings->create([
            'medical_record_id' => $medicalRecord->id,
            'clinic_visit_id' => $storageVisit->id,
            'branch_id' => $storageVisit->branch_id,
            'doctor_id' => $storageVisit->doctor_id,
            'handwriting_path' => $path,
            'handwriting_hash' => $hash,
            'saved_at' => now(),
            'created_by' => $actorId,
        ] + $this->authoredStamp($authoredBy));
    }

    /**
     * Rule 240 §4 — the encounter that wrote the page. Left untouched when the
     * caller named none, so a known author is never erased.
     *
     * @return array<string, int>
     */
    private function authoredStamp(?int $authoredBy): array
    {
        return $authoredBy !== null ? ['authored_clinic_visit_id' => $authoredBy] : [];
    }

    /**
     * Page 2+ — additive pages table. `$page` is already clamped to the next
     * addable page, so a client can never skip a page or write an arbitrary
     * number, and saving here never touches Page 1 or a sibling page.
     */
    private function savePage(ClinicVisit $storageVisit, MedicalRecord $medicalRecord, int $page, string $path, string $hash, ?int $actorId, ?int $authoredBy): void
    {
        $existing = $this->handwritings->findPageByMedicalRecordIdAndPage($medicalRecord->id, $page);

        if ($existing !== null) {
            $this->handwritings->updatePage($existing, [
                'handwriting_path' => $path,
                'handwriting_hash' => $hash,
                'saved_at' => now(),
                'updated_by' => $actorId,
            ] + $this->authoredStamp($authoredBy));

            return;
        }

        $this->handwritings->createPage([
            'medical_record_id' => $medicalRecord->id,
            'clinic_visit_id' => $storageVisit->id,
            'branch_id' => $storageVisit->branch_id,
            'doctor_id' => $storageVisit->doctor_id,
            'page_number' => $page,
            'handwriting_path' => $path,
            'handwriting_hash' => $hash,
            'saved_at' => now(),
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ] + $this->authoredStamp($authoredBy));
    }

    /**
     * Best effort. The key is unique to this call and no committed row names it,
     * so the only cost of a failed removal is an unreferenced private object.
     */
    private function discardUncommitted(string $key): void
    {
        try {
            ClinicalEvidenceStorage::disk()->delete($key);
        } catch (Throwable $e) {
            Log::warning('An uncommitted clinical evidence object could not be removed.', [
                'type' => $e::class,
            ]);
        }
    }
}
