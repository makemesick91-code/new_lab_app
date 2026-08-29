<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — one human, one clinical day, one branch.
 *
 * The durable authority behind the daily working-branch lock. `current_branch_id`
 * outranks the session-scoped `trx_user_online_contexts` row: an operator who
 * logs out, logs in from another browser, or posts straight at the endpoint
 * still resolves to this value.
 *
 * Every field is written by {@see DailyBranchContextService}
 * or {@see BranchChangeApprovalService}
 * inside a transaction. `$fillable` is deliberately narrow: `current_branch_id`
 * is the authority, so it must never be reachable from a request payload.
 */
class DailyBranchContext extends Model
{
    protected $table = 'trx_daily_branch_contexts';

    /**
     * Server-derived only. There is no HTTP path that mass-assigns this model —
     * the services set every attribute explicitly — but the list is kept tight
     * so a future caller cannot widen the authority by accident.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'clinical_date',
        'role_context',
        'initial_branch_id',
        'current_branch_id',
        'first_selected_at',
        'last_changed_at',
        'change_count',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'clinical_date' => 'date',
            'initial_branch_id' => 'integer',
            'current_branch_id' => 'integer',
            'change_count' => 'integer',
            'first_selected_at' => 'datetime',
            'last_changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'current_branch_id');
    }

    public function initialBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'initial_branch_id');
    }

    /**
     * True when the given branch is the one this day is committed to.
     *
     * Re-selecting the SAME branch is harmless and must stay allowed: an
     * operator refreshing the selector, or a second tab replaying the form,
     * is not attempting a switch.
     */
    public function isLockedTo(int $branchId): bool
    {
        return (int) $this->current_branch_id === $branchId;
    }
}
