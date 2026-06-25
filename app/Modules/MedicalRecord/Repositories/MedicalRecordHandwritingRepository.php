<?php

namespace App\Modules\MedicalRecord\Repositories;

use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;

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
}
