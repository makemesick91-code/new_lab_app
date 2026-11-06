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
    public function __construct(
        private readonly MedicalRecordHandwritingRepositoryInterface $handwritings,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
    ) {}

    /**
     * `$resolveTarget` returns the authorized record and the visit that stores
     * it, and runs inside the transaction. `$png` is already decoded, validated
     * and blank-guarded.
     *
     * @param  Closure(): array{0: MedicalRecord, 1: ClinicVisit}  $resolveTarget
     * @return array{medical_record: MedicalRecord, storage_visit: ClinicVisit, page_number: int}
     */
    public function save(Closure $resolveTarget, int $requestedPage, string $png, ?int $actorId): array
    {
        $writtenKey = null;
        $bodyDone = false;

        try {
            return DB::transaction(function () use ($resolveTarget, $requestedPage, $png, $actorId, &$writtenKey, &$bodyDone): array {
                [$medicalRecord, $storageVisit] = $resolveTarget();

                $this->medicalRecords->lockForHandwriting($medicalRecord->id);

                $page = $requestedPage <= 1
                    ? 1
                    : min($requestedPage, $this->handwritings->nextPageNumber($medicalRecord->id));

                $writtenKey = $this->objectKey($storageVisit, $page);
                ClinicalEvidenceStorage::disk()->put($writtenKey, $png);
                $hash = hash('sha256', $png);

                if ($page === 1) {
                    $this->savePageOne($storageVisit, $medicalRecord, $writtenKey, $hash, $actorId);
                } else {
                    $this->savePage($storageVisit, $medicalRecord, $page, $writtenKey, $hash, $actorId);
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
    private function savePageOne(ClinicVisit $storageVisit, MedicalRecord $medicalRecord, string $path, string $hash, ?int $actorId): void
    {
        $existing = $this->handwritings->findByMedicalRecordId($medicalRecord->id);

        if ($existing !== null) {
            $this->handwritings->update($existing, [
                'handwriting_path' => $path,
                'handwriting_hash' => $hash,
                'saved_at' => now(),
            ]);

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
        ]);
    }

    /**
     * Page 2+ — additive pages table. `$page` is already clamped to the next
     * addable page, so a client can never skip a page or write an arbitrary
     * number, and saving here never touches Page 1 or a sibling page.
     */
    private function savePage(ClinicVisit $storageVisit, MedicalRecord $medicalRecord, int $page, string $path, string $hash, ?int $actorId): void
    {
        $existing = $this->handwritings->findPageByMedicalRecordIdAndPage($medicalRecord->id, $page);

        if ($existing !== null) {
            $this->handwritings->updatePage($existing, [
                'handwriting_path' => $path,
                'handwriting_hash' => $hash,
                'saved_at' => now(),
                'updated_by' => $actorId,
            ]);

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
        ]);
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
