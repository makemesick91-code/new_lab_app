<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Interfaces;

use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecordPage;
use Illuminate\Support\Collection;

/**
 * FIX-04b — persistence boundary for PUBLISHED legacy odontogram records.
 *
 * THERE IS NO update() AND NO delete(), and there must never be one. A generic
 * update path would quietly restore in-place mutation of clinical evidence,
 * which is exactly what the immutability contract forbids.
 *
 * Exactly one narrow, named transition is exposed: markVoided() writes ONLY the
 * four void columns, so it cannot touch the patient, the branch, the date, the
 * file, the hash or the pages — a "correction" can never be smuggled in as a
 * void. A corrected archive is still a VOID plus a fresh import.
 */
interface LegacyOdontogramRecordRepositoryInterface
{
    /**
     * PUBLISHED and non-voided only, oldest clinical date first.
     *
     * @param  list<int>  $branchIds
     * @return Collection<int, LegacyOdontogramRecord>
     */
    public function listPublishedForPatientInBranches(array $branchIds, int $patientId, bool $includeUnscoped = false): Collection;

    /**
     * @param  list<int>  $branchIds
     */
    public function findByIdInBranches(array $branchIds, int $id, bool $includeUnscoped = false): ?LegacyOdontogramRecord;

    public function findBySourceImportId(int $importId): ?LegacyOdontogramRecord;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LegacyOdontogramRecord;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPage(LegacyOdontogramRecord $record, array $attributes): LegacyOdontogramRecordPage;

    /**
     * @return Collection<int, LegacyOdontogramRecordPage>
     */
    public function pagesFor(LegacyOdontogramRecord $record): Collection;

    public function findPage(LegacyOdontogramRecord $record, int $pageNumber): ?LegacyOdontogramRecordPage;

    public function lockForUpdate(int $id): ?LegacyOdontogramRecord;

    public function markVoided(LegacyOdontogramRecord $record, ?int $actorId, string $reason): LegacyOdontogramRecord;

    /** @return Collection<int, LegacyOdontogramRecord> */
    public function findByPdfChecksum(string $sha256): Collection;
}
