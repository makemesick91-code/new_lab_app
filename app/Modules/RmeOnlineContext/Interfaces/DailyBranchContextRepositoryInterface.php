<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Interfaces;

use App\Modules\RmeOnlineContext\Models\DailyBranchContext;

interface DailyBranchContextRepositoryInterface
{
    /**
     * The user's daily context for a clinical date, read WITHOUT a lock.
     *
     * For display and for read-only resolution. Never use this to decide
     * whether a mutation is allowed — see {@see self::lockForUser()}.
     */
    public function findForUserAndDate(int $userId, string $clinicalDate): ?DailyBranchContext;

    /**
     * The same row, read `FOR UPDATE`.
     *
     * Every decision that may write the context takes this, so two concurrent
     * sessions serialise on the row instead of interleaving a read and a write.
     * Must be called inside a transaction.
     */
    public function lockForUser(int $userId, string $clinicalDate): ?DailyBranchContext;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): DailyBranchContext;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(DailyBranchContext $context, array $attributes): DailyBranchContext;
}
