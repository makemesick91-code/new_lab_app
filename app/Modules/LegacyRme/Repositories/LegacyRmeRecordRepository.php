<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Repositories;

use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * LEGACY-RME-PDF-1A — Eloquent implementation of the published legacy RME
 * record persistence boundary. Read + create only; published records are
 * immutable.
 */
class LegacyRmeRecordRepository implements LegacyRmeRecordRepositoryInterface
{
    /**
     * @param  array<int, int>  $branchIds
     * @return Collection<int, LegacyRmeRecord>
     */
    public function listPublishedForPatientInBranches(array $branchIds, int $patientId, bool $includeUnscoped = false): Collection
    {
        return $this->scoped($branchIds, $includeUnscoped)
            ->where('patient_id', $patientId)
            ->where('status', LegacyRmeRecord::STATUS_PUBLISHED)
            ->orderBy('rme_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, int>  $branchIds
     */
    public function findByIdInBranches(array $branchIds, int $id, bool $includeUnscoped = false): ?LegacyRmeRecord
    {
        return $this->scoped($branchIds, $includeUnscoped)->find($id);
    }

    public function findByUuid(string $uuid): ?LegacyRmeRecord
    {
        return LegacyRmeRecord::query()->where('uuid', $uuid)->first();
    }

    public function findBySourceImportId(int $importId): ?LegacyRmeRecord
    {
        return LegacyRmeRecord::query()->where('source_import_id', $importId)->first();
    }

    /**
     * @return Collection<int, LegacyRmeRecord>
     */
    public function findByPdfChecksum(string $sha256): Collection
    {
        return LegacyRmeRecord::query()
            ->where('source_pdf_sha256', $sha256)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LegacyRmeRecord
    {
        return LegacyRmeRecord::query()->create($attributes);
    }

    /**
     * Fail-closed branch scope: an empty scope resolves to no rows at all.
     *
     * @param  array<int, int>  $branchIds
     * @return Builder<LegacyRmeRecord>
     */
    private function scoped(array $branchIds, bool $includeUnscoped): Builder
    {
        $ids = array_values(array_unique(array_map('intval', $branchIds)));

        $query = LegacyRmeRecord::query();

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($ids, $includeUnscoped): void {
            $builder->whereIn('origin_branch_id', $ids);

            if ($includeUnscoped) {
                $builder->orWhereNull('origin_branch_id');
            }
        });
    }
}
