<?php

namespace App\Modules\RmeOnlineContext\Interfaces;

use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use Illuminate\Support\Collection;

interface UserOnlineContextRepositoryInterface
{
    public function findForUser(int $userId): ?UserOnlineContext;

    public function upsertForUser(int $userId, array $attributes): UserOnlineContext;

    /**
     * @return Collection<int, UserOnlineContext>
     */
    public function onlineDoctorsForBranch(int $branchId): Collection;
}
