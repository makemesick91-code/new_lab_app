<?php

namespace App\Modules\LabService\Repositories;

use App\Modules\LabService\Interfaces\LabServiceRepositoryInterface;
use App\Modules\LabService\Models\LabService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class LabServiceRepository implements LabServiceRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return LabService::query()
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(category) LIKE ?', [$term]);
                });
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listAll(): Collection
    {
        return LabService::where('is_active', true)->orderBy('name')->get();
    }

    public function findById(int $id): ?LabService
    {
        return LabService::find($id);
    }

    public function create(array $data): LabService
    {
        return LabService::create($data);
    }

    public function update(LabService $labService, array $data): LabService
    {
        $labService->update($data);

        return $labService->refresh();
    }

    public function delete(LabService $labService): bool
    {
        return (bool) $labService->delete();
    }

    public function setActiveStatus(LabService $labService, bool $isActive): LabService
    {
        $labService->update(['is_active' => $isActive]);

        return $labService->refresh();
    }
}
