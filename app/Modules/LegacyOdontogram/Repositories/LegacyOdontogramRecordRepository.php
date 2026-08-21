<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Repositories;

use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecordPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LegacyOdontogramRecordRepository implements LegacyOdontogramRecordRepositoryInterface
{
    public function listPublishedForPatientInBranches(array $branchIds, int $patientId, bool $includeUnscoped = false): Collection
    {
        return $this->scoped($branchIds, $includeUnscoped)
            ->with(['branch:id,name,code'])
            ->where('patient_id', $patientId)
            ->where('status', LegacyOdontogramRecord::STATUS_PUBLISHED)
            // Ordered by the CLINICAL date, never by upload or creation time:
            // a chart archived today is a document from years ago and must sort
            // where it clinically belongs. `id` breaks same-day ties so the
            // order is total and stable across PostgreSQL and SQLite.
            ->orderBy('odontogram_date')
            ->orderBy('id')
            ->get();
    }

    public function findByIdInBranches(array $branchIds, int $id, bool $includeUnscoped = false): ?LegacyOdontogramRecord
    {
        return $this->scoped($branchIds, $includeUnscoped)->find($id);
    }

    public function findBySourceImportId(int $importId): ?LegacyOdontogramRecord
    {
        return LegacyOdontogramRecord::query()->where('source_import_id', $importId)->first();
    }

    public function create(array $attributes): LegacyOdontogramRecord
    {
        return LegacyOdontogramRecord::query()->create($attributes);
    }

    public function createPage(LegacyOdontogramRecord $record, array $attributes): LegacyOdontogramRecordPage
    {
        return LegacyOdontogramRecordPage::query()->create(
            array_merge($attributes, ['odontogram_legacy_record_id' => $record->getKey()]),
        );
    }

    public function pagesFor(LegacyOdontogramRecord $record): Collection
    {
        return LegacyOdontogramRecordPage::query()
            ->where('odontogram_legacy_record_id', $record->getKey())
            ->orderBy('page_number')
            ->get();
    }

    public function findPage(LegacyOdontogramRecord $record, int $pageNumber): ?LegacyOdontogramRecordPage
    {
        return LegacyOdontogramRecordPage::query()
            ->where('odontogram_legacy_record_id', $record->getKey())
            ->where('page_number', $pageNumber)
            ->first();
    }

    public function lockForUpdate(int $id): ?LegacyOdontogramRecord
    {
        return LegacyOdontogramRecord::query()->lockForUpdate()->find($id);
    }

    /**
     * The ONLY write path on a published record, and it is deliberately
     * column-explicit: forceFill with a fixed four-key array cannot be widened
     * by a caller passing extra attributes.
     */
    public function markVoided(LegacyOdontogramRecord $record, ?int $actorId, string $reason): LegacyOdontogramRecord
    {
        $record->forceFill([
            'status' => LegacyOdontogramRecord::STATUS_VOID,
            'voided_by' => $actorId,
            'voided_at' => now(),
            'void_reason' => $reason,
        ])->save();

        return $record->refresh();
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function scoped(array $branchIds, bool $includeUnscoped): Builder
    {
        $ids = array_values(array_unique(array_map('intval', $branchIds)));

        $query = LegacyOdontogramRecord::query();

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($ids, $includeUnscoped): void {
            $builder->whereIn('branch_id', $ids);

            if ($includeUnscoped) {
                $builder->orWhereNull('branch_id');
            }
        });
    }

    /**
     * Every row carrying this document's checksum, for duplicate detection.
     * Mirrors the Legacy RME archive: the same scanned chart must not end up
     * published irreversibly into two different patients' clinical histories.
     *
     * @return Collection<int, LegacyOdontogramRecord>
     */
    public function findByPdfChecksum(string $sha256): Collection
    {
        return LegacyOdontogramRecord::query()
            ->where('source_pdf_sha256', $sha256)
            ->orderBy('id')
            ->get();
    }
}
