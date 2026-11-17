<?php

namespace App\Modules\MedicalRecord\Repositories;

use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class MedicalRecordHandwritingRepository implements MedicalRecordHandwritingRepositoryInterface
{
    public function findByMedicalRecordId(int $medicalRecordId): ?MedicalRecordHandwriting
    {
        return MedicalRecordHandwriting::query()
            ->where('medical_record_id', $medicalRecordId)
            ->latest()
            ->first();
    }

    public function create(array $data): MedicalRecordHandwriting
    {
        return MedicalRecordHandwriting::create($data);
    }

    public function update(MedicalRecordHandwriting $handwriting, array $data): MedicalRecordHandwriting
    {
        $handwriting->update($data);

        return $handwriting->refresh();
    }

    public function findPageByMedicalRecordIdAndPage(int $medicalRecordId, int $pageNumber): ?MedicalRecordHandwritingPage
    {
        return MedicalRecordHandwritingPage::query()
            ->where('medical_record_id', $medicalRecordId)
            ->where('page_number', $pageNumber)
            ->first();
    }

    public function createPage(array $data): MedicalRecordHandwritingPage
    {
        return MedicalRecordHandwritingPage::create($data);
    }

    public function updatePage(MedicalRecordHandwritingPage $page, array $data): MedicalRecordHandwritingPage
    {
        $page->update($data);

        return $page->refresh();
    }

    public function nextPageNumber(int $medicalRecordId): int
    {
        // Page 1 is the legacy row, so the highest used page is at least 1 and
        // the next addable page is at least 2.
        $maxPage = (int) MedicalRecordHandwritingPage::query()
            ->where('medical_record_id', $medicalRecordId)
            ->max('page_number');

        return max($maxPage, 1) + 1;
    }

    public function pageSlotLock(int $medicalRecordId, int $pageNumber, int $patientId, ?int $liveEncounterId): ?string
    {
        $query = $pageNumber <= 1
            ? MedicalRecordHandwriting::query()->where('medical_record_id', $medicalRecordId)
            : MedicalRecordHandwritingPage::query()
                ->where('medical_record_id', $medicalRecordId)
                ->where('page_number', $pageNumber);

        $lock = null;

        foreach ($query->get(['id', 'authored_clinic_visit_id', 'sealed_at', 'created_at']) as $row) {
            if ($row->sealed_at !== null) {
                return self::LOCK_SEALED;
            }

            if ($row->authored_clinic_visit_id === null) {
                if ($this->finalizedSince($patientId, $row->created_at)) {
                    return self::LOCK_SEALED;
                }

                // Review MEDIUM-A — written before rule 240 by an encounter nobody
                // recorded, so it is never the live encounter's own: overwriting it
                // would let a later encounter restamp a closed encounter's page.
                $lock = self::LOCK_EARLIER_ENCOUNTER;

                continue;
            }

            if ($liveEncounterId === null || (int) $row->authored_clinic_visit_id !== $liveEncounterId) {
                $lock = self::LOCK_EARLIER_ENCOUNTER;
            }
        }

        return $lock;
    }

    public function sealBookPages(int $patientId, int $sealingMedicalRecordId): int
    {
        // The seal is metadata about the page, not a new drawing: written through
        // the base query so the rows' `updated_at` keeps meaning "last drawn".
        $stamp = [
            'sealed_at' => now()->format('Y-m-d H:i:s'),
            'sealed_by_medical_record_id' => $sealingMedicalRecordId,
        ];

        $scope = fn (Builder $rows): Builder => $rows
            ->whereNull('sealed_at')
            ->whereIn('medical_record_id', MedicalRecord::withTrashed()->where('patient_id', $patientId)->select('id'));

        return $scope(MedicalRecordHandwriting::query())->toBase()->update($stamp)
            + $scope(MedicalRecordHandwritingPage::query())->toBase()->update($stamp);
    }

    public function lockedBookPages(int $patientId, ?int $liveEncounterId = null): array
    {
        $book = MedicalRecord::withTrashed()->where('patient_id', $patientId)->select('id');
        $columns = ['id', 'medical_record_id', 'handwriting_path', 'handwriting_hash', 'authored_clinic_visit_id', 'sealed_at', 'sealed_by_medical_record_id'];

        // Exactly when pageSlotLock() answers a lock for the row: sealed; written
        // by another encounter than the live one; or unstamped (written before
        // rule 240), which pageSlotLock() locks whether or not a finalize covers it
        // (sealed when one does, LOCK_EARLIER_ENCOUNTER otherwise — review
        // MEDIUM-A). Which of the two reasons applies does not change "locked".
        $locked = fn (MedicalRecordHandwriting|MedicalRecordHandwritingPage $row): bool => $row->sealed_at !== null
            || $row->authored_clinic_visit_id === null
            || $liveEncounterId === null
            || (int) $row->authored_clinic_visit_id !== $liveEncounterId;

        $describe = fn (MedicalRecordHandwriting|MedicalRecordHandwritingPage $row, int $pageNumber): array => [
            'medical_record_id' => (int) $row->medical_record_id,
            'page_number' => $pageNumber,
            'row_id' => (int) $row->getKey(),
            'object_key' => ClinicalEvidenceStorage::isInlineDataUri($row->handwriting_path) ? null : $row->handwriting_path,
            'sha256' => $row->handwriting_hash,
            'authored_clinic_visit_id' => $row->authored_clinic_visit_id !== null ? (int) $row->authored_clinic_visit_id : null,
            'sealed_at' => $row->sealed_at?->toIso8601String(),
            'sealed_by_medical_record_id' => $row->sealed_by_medical_record_id !== null ? (int) $row->sealed_by_medical_record_id : null,
        ] + ($row->getAttribute('rme_amendment_id') !== null
            // Rule 240 §8 — only an amendment page carries the key, so every
            // other page is described exactly as before.
            ? ['rme_amendment_id' => (int) $row->getAttribute('rme_amendment_id')]
            : []);

        $pages = [];
        foreach (MedicalRecordHandwriting::query()->whereIn('medical_record_id', $book)->get($columns) as $row) {
            if ($locked($row)) {
                $pages[] = $describe($row, 1);
            }
        }
        foreach (MedicalRecordHandwritingPage::query()->whereIn('medical_record_id', $book)->get([...$columns, 'page_number', 'rme_amendment_id']) as $row) {
            if ($locked($row)) {
                $pages[] = $describe($row, (int) $row->page_number);
            }
        }

        usort($pages, fn (array $a, array $b): int => [$a['medical_record_id'], $a['page_number'], $a['row_id']]
            <=> [$b['medical_record_id'], $b['page_number'], $b['row_id']]);

        return $pages;
    }

    public function appendSealedAmendmentPage(MedicalRecord $book, array $page): MedicalRecordHandwritingPage
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('An amendment page is appended only inside the approve transaction (rule 240 §8).');
        }

        foreach (['handwriting_path', 'handwriting_hash', 'authored_clinic_visit_id', 'sealed_by_medical_record_id', 'rme_amendment_id'] as $required) {
            if (blank($page[$required] ?? null)) {
                throw new LogicException("An amendment page needs its {$required} (rule 240 §8).");
            }
        }

        // The seal and the amendment link are not mass-assignable: written here,
        // once, with the row. The next number is read under the book's record
        // lock, which the caller holds; UNIQUE(medical_record_id, page_number)
        // is the backstop.
        $row = new MedicalRecordHandwritingPage;
        $row->forceFill([
            'medical_record_id' => (int) $book->getKey(),
            'clinic_visit_id' => (int) $book->clinic_visit_id,
            'branch_id' => (int) $book->branch_id,
            'doctor_id' => $book->clinicVisit()->withTrashed()->value('doctor_id'),
            'page_number' => $this->nextPageNumber((int) $book->getKey()),
            'handwriting_path' => (string) $page['handwriting_path'],
            'handwriting_hash' => (string) $page['handwriting_hash'],
            'saved_at' => now(),
            'created_by' => $page['created_by'] ?? null,
            'updated_by' => $page['updated_by'] ?? null,
            'authored_clinic_visit_id' => (int) $page['authored_clinic_visit_id'],
            'sealed_at' => now(),
            'sealed_by_medical_record_id' => (int) $page['sealed_by_medical_record_id'],
            'rme_amendment_id' => (int) $page['rme_amendment_id'],
        ])->save();

        return $row->refresh();
    }

    public function findAmendmentPage(int $amendmentId): ?MedicalRecordHandwritingPage
    {
        return MedicalRecordHandwritingPage::query()->where('rme_amendment_id', $amendmentId)->first();
    }

    public function pageAuthors(int $medicalRecordId): array
    {
        $authors = [];

        foreach (MedicalRecordHandwriting::query()
            ->where('medical_record_id', $medicalRecordId)
            ->whereNotNull('authored_clinic_visit_id')
            ->pluck('authored_clinic_visit_id') as $visitId) {
            $authors[1][] = (int) $visitId;
        }

        foreach (MedicalRecordHandwritingPage::query()
            ->where('medical_record_id', $medicalRecordId)
            ->whereNotNull('authored_clinic_visit_id')
            ->get(['page_number', 'authored_clinic_visit_id']) as $row) {
            $authors[(int) $row->page_number][] = (int) $row->authored_clinic_visit_id;
        }

        return $authors;
    }

    /**
     * Rule 240 §4, the derived rule for a page written before rule 240: was a
     * sheet of this patient finalized at or after the page was created? Fails
     * closed — a missing creation time, or a final sheet without `finalized_at`,
     * counts as covering the page. A soft-deleted final sheet still counts.
     */
    private function finalizedSince(int $patientId, ?Carbon $createdAt): bool
    {
        return MedicalRecord::withTrashed()
            ->where('patient_id', $patientId)
            ->where('status', MedicalRecord::STATUS_FINAL)
            ->when($createdAt !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereNull('finalized_at')
                ->orWhere('finalized_at', '>=', $createdAt)))
            ->exists();
    }
}
