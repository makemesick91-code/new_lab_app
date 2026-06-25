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
}
