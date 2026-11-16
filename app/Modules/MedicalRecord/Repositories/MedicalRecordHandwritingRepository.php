<?php

namespace App\Modules\MedicalRecord\Repositories;

use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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

    public function pageSlotLocked(int $medicalRecordId, int $pageNumber, int $patientId): bool
    {
        $query = $pageNumber <= 1
            ? MedicalRecordHandwriting::query()->where('medical_record_id', $medicalRecordId)
            : MedicalRecordHandwritingPage::query()
                ->where('medical_record_id', $medicalRecordId)
                ->where('page_number', $pageNumber);

        foreach ($query->get(['id', 'authored_clinic_visit_id', 'sealed_at', 'created_at']) as $row) {
            if ($row->sealed_at !== null) {
                return true;
            }

            if ($row->authored_clinic_visit_id === null && $this->finalizedSince($patientId, $row->created_at)) {
                return true;
            }
        }

        return false;
    }

    public function sealBookPages(int $patientId, int $sealingMedicalRecordId, ?int $sparedEncounterId = null): int
    {
        // The seal is metadata about the page, not a new drawing: written through
        // the base query so the rows' `updated_at` keeps meaning "last drawn".
        $stamp = [
            'sealed_at' => now()->format('Y-m-d H:i:s'),
            'sealed_by_medical_record_id' => $sealingMedicalRecordId,
        ];

        $scope = function (Builder $rows) use ($patientId, $sparedEncounterId): Builder {
            return $rows
                ->whereNull('sealed_at')
                ->whereIn('medical_record_id', MedicalRecord::withTrashed()->where('patient_id', $patientId)->select('id'))
                ->when($sparedEncounterId !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->whereNull('authored_clinic_visit_id')
                    ->orWhere('authored_clinic_visit_id', '!=', $sparedEncounterId)));
        };

        return $scope(MedicalRecordHandwriting::query())->toBase()->update($stamp)
            + $scope(MedicalRecordHandwritingPage::query())->toBase()->update($stamp);
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
