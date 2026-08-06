<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Repositories;

use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * LEGACY-RME-PDF-1A — Eloquent implementation of the legacy RME staging
 * persistence boundary.
 */
class LegacyRmeImportRepository implements LegacyRmeImportRepositoryInterface
{
    /**
     * @param  array<int, int>  $branchIds
     * @return Collection<int, LegacyRmeImport>
     */
    public function listForPatientInBranches(array $branchIds, int $patientId, bool $includeUnscoped = false): Collection
    {
        return $this->scoped($branchIds, $includeUnscoped)
            ->where('patient_id', $patientId)
            ->orderBy('selected_rme_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, int>  $branchIds
     */
    public function findByIdInBranches(array $branchIds, int $id, bool $includeUnscoped = false): ?LegacyRmeImport
    {
        return $this->scoped($branchIds, $includeUnscoped)->find($id);
    }

    public function findByUuid(string $uuid): ?LegacyRmeImport
    {
        return LegacyRmeImport::query()->where('uuid', $uuid)->first();
    }

    /**
     * @return Collection<int, LegacyRmeImport>
     */
    public function openImportsForPatientOnDate(int $patientId, string $rmeDate): Collection
    {
        return LegacyRmeImport::query()
            ->where('patient_id', $patientId)
            ->whereDate('selected_rme_date', $rmeDate)
            ->whereNotIn('status', LegacyRmeImportStatus::TERMINAL)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LegacyRmeImport
    {
        return LegacyRmeImport::query()->create($attributes);
    }

    /**
     * Fail-closed branch scope: an empty scope resolves to no rows at all.
     *
     * @param  array<int, int>  $branchIds
     * @return Builder<LegacyRmeImport>
     */
    private function scoped(array $branchIds, bool $includeUnscoped): Builder
    {
        $ids = array_values(array_unique(array_map('intval', $branchIds)));

        $query = LegacyRmeImport::query();

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
