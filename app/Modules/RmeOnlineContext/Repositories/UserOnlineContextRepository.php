<?php

namespace App\Modules\RmeOnlineContext\Repositories;

use App\Modules\RmeOnlineContext\Interfaces\UserOnlineContextRepositoryInterface;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use Illuminate\Support\Collection;

class UserOnlineContextRepository implements UserOnlineContextRepositoryInterface
{
    public function findForUser(int $userId): ?UserOnlineContext
    {
        return UserOnlineContext::query()
            ->with(['branch', 'clinicRoom', 'user'])
            ->where('user_id', $userId)
            ->first();
    }

    public function upsertForUser(int $userId, array $attributes): UserOnlineContext
    {
        return UserOnlineContext::query()->updateOrCreate(
            ['user_id' => $userId],
            $attributes,
        );
    }

    public function onlineDoctorsForBranch(int $branchId): Collection
    {
        return UserOnlineContext::query()
            ->with(['user', 'branch', 'clinicRoom'])
            ->where('branch_id', $branchId)
            ->where('role_context', UserOnlineContext::ROLE_DOCTOR)
            ->where('status', UserOnlineContext::STATUS_ONLINE)
            ->orderBy('online_since')
            ->get();
    }
}
