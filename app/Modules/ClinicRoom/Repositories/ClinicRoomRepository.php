<?php

namespace App\Modules\ClinicRoom\Repositories;

use App\Modules\ClinicRoom\Interfaces\ClinicRoomRepositoryInterface;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ClinicRoomRepository implements ClinicRoomRepositoryInterface
{
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return ClinicRoom::query()
            ->where('branch_id', $branchId)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listActive(int $branchId): Collection
    {
        return ClinicRoom::query()
            ->where('branch_id', $branchId)
            ->where('status', ClinicRoom::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }

    public function findInBranch(int $branchId, int $id): ?ClinicRoom
    {
        return ClinicRoom::query()
            ->where('branch_id', $branchId)
            ->find($id);
    }

    public function create(array $data): ClinicRoom
    {
        return ClinicRoom::create($data);
    }

    public function update(ClinicRoom $room, array $data): ClinicRoom
    {
        $room->update($data);

        return $room->refresh();
    }

    public function delete(ClinicRoom $room): bool
    {
        return (bool) $room->delete();
    }
}
