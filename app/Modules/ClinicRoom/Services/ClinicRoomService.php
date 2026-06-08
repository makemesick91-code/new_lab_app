<?php

namespace App\Modules\ClinicRoom\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicRoom\Interfaces\ClinicRoomRepositoryInterface;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClinicRoomService
{
    public function __construct(
        private readonly ClinicRoomRepositoryInterface $rooms,
        private readonly BranchContext $branchContext,
    ) {}

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->rooms->paginate($this->branchContext->requireId(), $filters, $perPage);
    }

    public function listActive(): Collection
    {
        return $this->rooms->listActive($this->branchContext->requireId());
    }

    public function find(int $id): ?ClinicRoom
    {
        return $this->rooms->findInBranch($this->branchContext->requireId(), $id);
    }

    public function create(array $data): ClinicRoom
    {
        return DB::transaction(function () use ($data) {
            return $this->rooms->create(array_merge($data, [
                'branch_id' => $this->branchContext->requireId(),
            ]));
        });
    }

    public function update(ClinicRoom $room, array $data): ClinicRoom
    {
        return DB::transaction(fn () => $this->rooms->update($room, $data));
    }

    public function delete(ClinicRoom $room): bool
    {
        return DB::transaction(fn () => $this->rooms->delete($room));
    }
}
