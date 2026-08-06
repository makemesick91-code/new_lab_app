<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Repositories;

use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * @param  array<int, int>  $branchIds
     * @param  array{status?: string|null, patient?: string|null}  $filters
     * @return LengthAwarePaginator<int, LegacyRmeImport>
     */
    public function paginateInBranches(array $branchIds, array $filters = [], bool $includeUnscoped = false, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->scoped($branchIds, $includeUnscoped)
            ->with(['patient:id,name,medical_record_number', 'originBranch:id,name,code', 'uploadedBy:id,name']);

        $status = $filters['status'] ?? null;

        if (is_string($status) && LegacyRmeImportStatus::isValid($status)) {
            $query->where('status', $status);
        }

        $patient = $filters['patient'] ?? null;

        if (is_string($patient) && trim($patient) !== '') {
            $term = '%'.trim($patient).'%';

            // Name and medical record number only — a legacy workspace never
            // searches on KTP/NIK.
            $query->whereHas('patient', function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', $term)
                    ->orWhere('medical_record_number', 'like', $term);
            });
        }

        return $query->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function findForProcessing(int $id): ?LegacyRmeImport
    {
        return LegacyRmeImport::query()->find($id);
    }

    public function lockForUpdate(int $id): ?LegacyRmeImport
    {
        return LegacyRmeImport::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(LegacyRmeImport $import, array $attributes): LegacyRmeImport
    {
        $import->fill($attributes);
        $import->save();

        return $import;
    }

    /**
     * @return Collection<int, LegacyRmeImport>
     */
    public function openImportsForPatientWithChecksum(int $patientId, string $sha256): Collection
    {
        return LegacyRmeImport::query()
            ->where('patient_id', $patientId)
            ->where('source_pdf_sha256', $sha256)
            ->whereNotIn('status', LegacyRmeImportStatus::TERMINAL)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, LegacyRmeImport>
     */
    public function findByPdfChecksum(string $sha256): Collection
    {
        return LegacyRmeImport::query()
            ->where('source_pdf_sha256', $sha256)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertPage(LegacyRmeImport $import, int $pageNumber, array $attributes): LegacyRmeImportPage
    {
        /** @var LegacyRmeImportPage $page */
        $page = LegacyRmeImportPage::query()->updateOrCreate(
            [
                'legacy_import_id' => $import->getKey(),
                'page_number' => $pageNumber,
            ],
            $attributes,
        );

        return $page;
    }

    /**
     * @return Collection<int, LegacyRmeImportPage>
     */
    public function pagesFor(LegacyRmeImport $import): Collection
    {
        return LegacyRmeImportPage::query()
            ->where('legacy_import_id', $import->getKey())
            ->orderBy('page_number')
            ->get();
    }

    public function findPage(LegacyRmeImport $import, int $pageNumber): ?LegacyRmeImportPage
    {
        return LegacyRmeImportPage::query()
            ->where('legacy_import_id', $import->getKey())
            ->where('page_number', $pageNumber)
            ->first();
    }

    public function deletePages(LegacyRmeImport $import): void
    {
        LegacyRmeImportPage::query()
            ->where('legacy_import_id', $import->getKey())
            ->delete();
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
