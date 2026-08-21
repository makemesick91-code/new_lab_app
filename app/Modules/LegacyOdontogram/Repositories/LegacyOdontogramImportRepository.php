<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Repositories;

use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImportPage;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LegacyOdontogramImportRepository implements LegacyOdontogramImportRepositoryInterface
{
    public function paginateInBranches(array $branchIds, array $filters = [], bool $includeUnscoped = false, int $perPage = 20): LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;
        $patient = $filters['patient'] ?? null;

        return $this->scoped($branchIds, $includeUnscoped)
            ->with(['patient:id,name,medical_record_number', 'originBranch:id,name,code'])
            ->when(
                is_string($status) && LegacyOdontogramImportStatus::isValid($status),
                fn (Builder $query) => $query->where('status', $status),
            )
            ->when(
                is_string($patient) && trim($patient) !== '',
                fn (Builder $query) => $query->whereHas(
                    'patient',
                    function (Builder $inner) use ($patient): void {
                        $term = '%'.trim((string) $patient).'%';

                        // Name and Nomor RM only. KTP/NIK is never a search key
                        // here — matching on it would make an identity document
                        // an enumeration oracle for anyone who can open the list.
                        $inner->where('name', 'like', $term)
                            ->orWhere('medical_record_number', 'like', $term);
                    },
                ),
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findByIdInBranches(array $branchIds, int $id, bool $includeUnscoped = false): ?LegacyOdontogramImport
    {
        return $this->scoped($branchIds, $includeUnscoped)->find($id);
    }

    public function listForPatientInBranches(array $branchIds, int $patientId, bool $includeUnscoped = false): Collection
    {
        return $this->scoped($branchIds, $includeUnscoped)
            ->where('patient_id', $patientId)
            ->orderBy('selected_odontogram_date')
            ->orderBy('id')
            ->get();
    }

    public function findForProcessing(int $id): ?LegacyOdontogramImport
    {
        return LegacyOdontogramImport::query()->find($id);
    }

    public function lockForUpdate(int $id): ?LegacyOdontogramImport
    {
        return LegacyOdontogramImport::query()->lockForUpdate()->find($id);
    }

    public function create(array $attributes): LegacyOdontogramImport
    {
        return LegacyOdontogramImport::query()->create($attributes);
    }

    public function update(LegacyOdontogramImport $import, array $attributes): LegacyOdontogramImport
    {
        $import->fill($attributes)->save();

        return $import->refresh();
    }

    public function upsertPage(LegacyOdontogramImport $import, int $pageNumber, array $attributes): LegacyOdontogramImportPage
    {
        /** @var LegacyOdontogramImportPage $page */
        $page = LegacyOdontogramImportPage::query()->updateOrCreate(
            [
                'legacy_import_id' => $import->getKey(),
                'page_number' => $pageNumber,
            ],
            $attributes,
        );

        return $page;
    }

    public function pagesFor(LegacyOdontogramImport $import): Collection
    {
        return LegacyOdontogramImportPage::query()
            ->where('legacy_import_id', $import->getKey())
            ->orderBy('page_number')
            ->get();
    }

    public function findPage(LegacyOdontogramImport $import, int $pageNumber): ?LegacyOdontogramImportPage
    {
        return LegacyOdontogramImportPage::query()
            ->where('legacy_import_id', $import->getKey())
            ->where('page_number', $pageNumber)
            ->first();
    }

    public function deletePages(LegacyOdontogramImport $import): void
    {
        LegacyOdontogramImportPage::query()
            ->where('legacy_import_id', $import->getKey())
            ->delete();
    }

    /**
     * Branch scope, applied once, here.
     *
     * An EMPTY branch list is an unresolvable scope, and the only safe reading
     * of that is "this caller may see nothing" — never "apply no filter", which
     * is what an omitted whereIn would silently mean.
     *
     * @param  list<int>  $branchIds
     */
    private function scoped(array $branchIds, bool $includeUnscoped): Builder
    {
        $ids = array_values(array_unique(array_map('intval', $branchIds)));

        $query = LegacyOdontogramImport::query();

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

    /**
     * Every row carrying this document's checksum, for duplicate detection.
     * Mirrors the Legacy RME archive: the same scanned chart must not end up
     * published irreversibly into two different patients' clinical histories.
     *
     * @return Collection<int, LegacyOdontogramImport>
     */
    public function findByPdfChecksum(string $sha256): Collection
    {
        return LegacyOdontogramImport::query()
            ->where('source_pdf_sha256', $sha256)
            ->orderBy('id')
            ->get();
    }
}
