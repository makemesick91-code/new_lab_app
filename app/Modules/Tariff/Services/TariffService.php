<?php

namespace App\Modules\Tariff\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Models\Tariff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TariffService
{
    public function __construct(
        private readonly TariffRepositoryInterface $tariffs,
        private readonly BranchContext $branchContext,
    ) {}

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->tariffs->paginate($this->branchContext->requireId(), $filters, $perPage);
    }

    public function find(int $id): ?Tariff
    {
        return $this->tariffs->findInBranch($this->branchContext->requireId(), $id);
    }

    public function create(array $data): Tariff
    {
        return DB::transaction(function () use ($data) {
            return $this->tariffs->create(array_merge($data, [
                'branch_id' => $this->branchContext->requireId(),
            ]));
        });
    }

    public function update(Tariff $tariff, array $data): Tariff
    {
        return DB::transaction(fn () => $this->tariffs->update($tariff, $data));
    }

    public function delete(Tariff $tariff): bool
    {
        return DB::transaction(fn () => $this->tariffs->delete($tariff));
    }
}
