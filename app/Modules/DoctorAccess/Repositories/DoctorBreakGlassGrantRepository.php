<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Repositories;

use App\Modules\DoctorAccess\Interfaces\DoctorBreakGlassGrantRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBreakGlassGrant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DoctorBreakGlassGrantRepository implements DoctorBreakGlassGrantRepositoryInterface
{
    public function activeForUser(int $userId, Carbon $at): ?DoctorBreakGlassGrant
    {
        return $this->activeQuery($userId, $at)->first();
    }

    public function activeForUserForUpdate(int $userId, Carbon $at): ?DoctorBreakGlassGrant
    {
        return $this->activeQuery($userId, $at)->lockForUpdate()->first();
    }

    public function findForUpdate(int $id): ?DoctorBreakGlassGrant
    {
        return DoctorBreakGlassGrant::query()->lockForUpdate()->find($id);
    }

    public function create(array $attributes): DoctorBreakGlassGrant
    {
        // forceFill, because the model declares nothing fillable on purpose:
        // a lifecycle column must never be drivable from a request payload.
        $grant = new DoctorBreakGlassGrant;
        $grant->forceFill($attributes)->save();

        return $grant->refresh();
    }

    public function update(DoctorBreakGlassGrant $grant, array $attributes): DoctorBreakGlassGrant
    {
        $grant->forceFill($attributes)->save();

        return $grant->refresh();
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return DoctorBreakGlassGrant::query()
            ->with(['user', 'doctor', 'grantedBy', 'revokedBy'])
            // Newest first: an operator opening this screen during an incident
            // is looking for what was just granted, not for history.
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * ACTIVE, expressed once.
     *
     * Both the gate's read and the granting transaction's lock ask exactly
     * this, so the row an operator is shown and the row the gate honours can
     * never be decided by two different predicates.
     *
     * `>` and not `>=` on the expiry: a grant whose window closes at this
     * instant is closed. The boundary belongs to the side that denies.
     */
    private function activeQuery(int $userId, Carbon $at): Builder
    {
        return DoctorBreakGlassGrant::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $at)
            ->orderByDesc('expires_at')
            ->orderByDesc('id');
    }
}
