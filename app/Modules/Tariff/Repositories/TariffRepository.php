<?php

namespace App\Modules\Tariff\Repositories;

use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class TariffRepository implements TariffRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Tariff::query()
            ->with(['treatment.category', 'branch'])
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->whereHas('treatment', function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when($filters['treatment_id'] ?? null, fn ($q, $v) => $q->where('treatment_id', $v))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null,
                fn ($q) => $q->where('is_active', $filters['is_active']))
            ->when(array_key_exists('requires_lab', $filters) && $filters['requires_lab'] !== null,
                fn ($q) => $q->whereHas('treatment', fn ($t) => $t->where('requires_lab', $filters['requires_lab'])))
            ->orderByDesc('effective_date')
            ->orderBy('treatment_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findInBranch(int $branchId, int $id): ?Tariff
    {
        return Tariff::query()
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function findActiveForTreatment(int $branchId, int $treatmentId, ?Carbon $onDate = null): ?Tariff
    {
        $onDate = ($onDate ?? now())->toDateString();

        return Tariff::query()
            ->where('branch_id', $branchId)
            ->where('treatment_id', $treatmentId)
            ->where('is_active', true)
            ->whereDate('effective_date', '<=', $onDate)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    public function inForceForTreatments(int $branchId, array $treatmentIds, string $onDate): array
    {
        $treatmentIds = array_values(array_unique(array_map('intval', $treatmentIds)));
        if ($treatmentIds === []) {
            return [];
        }

        $rows = Tariff::query()
            ->where('branch_id', $branchId)
            ->whereIn('treatment_id', $treatmentIds)
            ->where('is_active', true)
            ->whereDate('effective_date', '<=', $onDate)
            ->orderBy('treatment_id')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get();

        $inForce = [];
        foreach ($rows as $tariff) {
            $inForce[(int) $tariff->treatment_id] ??= $tariff;
        }

        return $inForce;
    }

    public function treatmentUsability(array $treatmentIds): array
    {
        $treatmentIds = array_values(array_unique(array_map('intval', $treatmentIds)));
        if ($treatmentIds === []) {
            return [];
        }

        $usable = Treatment::query()
            ->whereIn('id', $treatmentIds)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $result = [];
        foreach ($treatmentIds as $id) {
            $result[$id] = in_array($id, $usable, true);
        }

        return $result;
    }

    public function activeTreatmentCodes(): array
    {
        return Treatment::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->mapWithKeys(fn ($code, $id) => [(int) $id => (string) $code])
            ->all();
    }

    public function atEffectiveDateIncludingTrashed(int $branchId, array $treatmentIds, string $effectiveDate): array
    {
        $treatmentIds = array_values(array_unique(array_map('intval', $treatmentIds)));
        if ($treatmentIds === []) {
            return [];
        }

        return Tariff::withTrashed()
            ->where('branch_id', $branchId)
            ->whereIn('treatment_id', $treatmentIds)
            ->whereDate('effective_date', $effectiveDate)
            ->get()
            ->keyBy(fn (Tariff $tariff) => (int) $tariff->treatment_id)
            ->all();
    }

    public function create(array $data): Tariff
    {
        return Tariff::create($data);
    }

    public function update(Tariff $tariff, array $data): Tariff
    {
        $tariff->update($data);

        return $tariff->refresh();
    }

    public function delete(Tariff $tariff): bool
    {
        return (bool) $tariff->delete();
    }
}
