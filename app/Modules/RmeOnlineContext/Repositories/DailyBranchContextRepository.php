<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Repositories;

use App\Modules\RmeOnlineContext\Interfaces\DailyBranchContextRepositoryInterface;
use App\Modules\RmeOnlineContext\Models\DailyBranchContext;

class DailyBranchContextRepository implements DailyBranchContextRepositoryInterface
{
    public function findForUserAndDate(int $userId, string $clinicalDate): ?DailyBranchContext
    {
        return DailyBranchContext::query()
            ->where('user_id', $userId)
            ->whereDate('clinical_date', $clinicalDate)
            ->first();
    }

    public function lockForUser(int $userId, string $clinicalDate): ?DailyBranchContext
    {
        return DailyBranchContext::query()
            ->where('user_id', $userId)
            ->whereDate('clinical_date', $clinicalDate)
            ->lockForUpdate()
            ->first();
    }

    public function create(array $attributes): DailyBranchContext
    {
        return DailyBranchContext::query()->create($attributes);
    }

    public function update(DailyBranchContext $context, array $attributes): DailyBranchContext
    {
        $context->update($attributes);

        return $context->refresh();
    }
}
