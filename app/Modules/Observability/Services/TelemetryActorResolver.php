<?php

namespace App\Modules\Observability\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — who did a request belong to, as an
 * OBSERVATION. Role and working branch, memoized briefly per user.
 *
 * READ-ONLY BY CONSTRUCTION. The branch comes from a plain SELECT on the
 * user's online-context row, falling back to users.branch_id. It deliberately
 * does NOT call BranchContext::forUser(): that resolver can lazily mark an
 * expired context inactive, and telemetry must never write to a
 * clinical-workflow table. This value is a label on a telemetry row and is
 * never an authorization input anywhere.
 */
class TelemetryActorResolver
{
    /**
     * @return array{role: string|null, branch_id: int|null}
     */
    public function contextFor(User $user): array
    {
        $ttl = (int) config('observability_console.actor_context_cache_seconds', 120);

        try {
            return Cache::remember('obs:actor-context:'.$user->getKey(), $ttl, fn () => $this->resolve($user));
        } catch (Throwable) {
            // A broken cache store must not cost the request its telemetry.
            return $this->resolve($user);
        }
    }

    /**
     * @return array{role: string|null, branch_id: int|null}
     */
    private function resolve(User $user): array
    {
        $role = null;
        try {
            $role = $user->getRoleNames()->first();
        } catch (Throwable) {
            $role = null;
        }

        $branchId = null;
        try {
            $branchId = DB::table('trx_user_online_contexts')
                ->where('user_id', $user->getKey())
                ->where('status', 'online')
                ->value('branch_id');
        } catch (Throwable) {
            $branchId = null;
        }

        $branchId ??= $user->getAttribute('branch_id');

        return [
            'role' => $role !== null ? (string) $role : null,
            'branch_id' => $branchId !== null ? (int) $branchId : null,
        ];
    }
}
