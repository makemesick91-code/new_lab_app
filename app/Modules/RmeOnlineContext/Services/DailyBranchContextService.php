<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\RmeOnlineContext\Interfaces\DailyBranchContextRepositoryInterface;
use App\Modules\RmeOnlineContext\Models\DailyBranchContext;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the durable daily working-branch lock
 * for Kasir and Admin Klinik.
 *
 * ── THE CONTRACT ──────────────────────────────────────────────────────────
 *
 *   FIRST branch of a clinical day  → free, for any branch the user may work in
 *   SECOND, different branch        → refused, until a Super Admin approves it
 *   SAME branch again               → idempotent, always allowed
 *   NEXT clinical day               → one new free selection
 *
 * ── WHY THE GUARD LIVES IN THE SERVICE ────────────────────────────────────
 *
 * `UserOnlineContextService::startAdminClinicSession()` and `startKasirSession()`
 * are the ONLY paths that move a locked role's working branch. Guarding there
 * covers the HTTP endpoint, the console, the test helpers and any future caller
 * at once. A controller-level or Blade-level check would cover exactly one of
 * them, which is why the disabled dropdown is not the feature.
 *
 * ── WHICH ROLES ARE LOCKED, AND WHY IT IS KEYED ON ROLE CONTEXT ───────────
 *
 * Only `admin_clinic` and `kasir`. Doctor and Perawat keep their existing
 * behaviour untouched. The decision is made from the role context being STARTED
 * — a value the server picks by choosing which service method to call, never a
 * request field.
 *
 * The stored lock itself, though, is keyed on (user, clinical day) ALONE. A user
 * holding both `Kasir` and `Admin Klinik` must not be able to open an
 * admin-clinic context at one branch and a cashier context at another; that is
 * the same human working two branches in one day, which is precisely what this
 * feature forbids.
 *
 * ── THE FIRST-SELECTION RACE ──────────────────────────────────────────────
 *
 * Two sessions selecting different branches simultaneously must not both
 * succeed. The winner is decided by the database: `UNIQUE(user_id,
 * clinical_date)`. The loser's INSERT raises, and we then re-read the committed
 * row and re-evaluate against it — so the loser is either idempotent (it wanted
 * the same branch) or refused (it wanted a different one). Never a second
 * context, never a silent overwrite.
 */
class DailyBranchContextService
{
    /**
     * The role contexts subject to the daily lock.
     *
     * Deliberately NOT `RmeWorkingBranchScope::isContextBound()`, which also
     * covers Perawat. Perawat is explicitly out of scope for this feature and
     * must keep its current free-selection behaviour.
     *
     * @var array<int, string>
     */
    public const LOCKED_ROLE_CONTEXTS = [
        UserOnlineContext::ROLE_ADMIN_CLINIC,
        UserOnlineContext::ROLE_KASIR,
    ];

    public function __construct(
        private readonly DailyBranchContextRepositoryInterface $contexts,
        private readonly BranchRepositoryInterface $branches,
        private readonly ClinicalClock $clock,
    ) {}

    public static function isLockedRoleContext(string $roleContext): bool
    {
        return in_array($roleContext, self::LOCKED_ROLE_CONTEXTS, true);
    }

    /**
     * Today's clinical calendar date — the clinic's own wall clock, never UTC.
     *
     * The lock interval is a calendar day (00:00:00–23:59:59 in the clinical
     * timezone), not a rolling 24 hours from the first selection.
     */
    public function clinicalToday(): string
    {
        return $this->clock->todayString();
    }

    public function currentFor(User $user): ?DailyBranchContext
    {
        return $this->contexts->findForUserAndDate((int) $user->id, $this->clinicalToday());
    }

    /**
     * The branch this user is committed to today, or null if the day is still
     * open (or the user is not a locked role).
     *
     * This is the value that outranks the session-scoped online-context row.
     */
    public function lockedBranchIdFor(User $user): ?int
    {
        $context = $this->currentFor($user);

        return $context ? (int) $context->current_branch_id : null;
    }

    public function isLockedToday(User $user): bool
    {
        return $this->currentFor($user) !== null;
    }

    /**
     * THE GUARD. Establish or confirm the day's branch for a locked role.
     *
     * Called by the online-context service AFTER branch eligibility has already
     * been asserted, so an approved switch can never become a route to an
     * ineligible branch: the branch had to be legitimate before the lock is even
     * consulted.
     *
     * WHY THIS COMMITS BEFORE THE SESSION ROW IS WRITTEN.
     *
     * The caller writes `trx_user_online_contexts` after this returns, outside
     * this transaction. That ordering is deliberate: the daily context is the
     * authority and the session row is derived from it. If the session write
     * failed, the day would be committed to the branch the operator actually
     * asked for, and simply selecting it again succeeds — the same-branch path
     * is idempotent. The reverse ordering would be the dangerous one: a session
     * pointing at a branch no daily context authorises.
     *
     * @throws ValidationException when the day is already committed elsewhere.
     */
    public function assertSelectable(User $user, int $branchId, string $roleContext): void
    {
        if (! self::isLockedRoleContext($roleContext)) {
            return;
        }

        $clinicalDate = $this->clinicalToday();

        DB::transaction(function () use ($user, $branchId, $roleContext, $clinicalDate): void {
            $context = $this->contexts->lockForUser((int) $user->id, $clinicalDate);

            if ($context === null) {
                try {
                    $this->contexts->create([
                        'user_id' => (int) $user->id,
                        'clinical_date' => $clinicalDate,
                        'role_context' => $roleContext,
                        'initial_branch_id' => $branchId,
                        'current_branch_id' => $branchId,
                        'first_selected_at' => now(),
                        'change_count' => 0,
                    ]);

                    return;
                } catch (QueryException $exception) {
                    // Lost the first-selection race. The row the winner committed
                    // is now the authority; fall through and be judged by it, so
                    // the loser is idempotent or refused — never a second context.
                    if (! $this->isUniqueViolation($exception)) {
                        throw $exception;
                    }

                    $context = $this->contexts->lockForUser((int) $user->id, $clinicalDate);

                    if ($context === null) {
                        throw $exception;
                    }
                }
            }

            if ($context->isLockedTo($branchId)) {
                // Re-selecting the same branch. Nothing changes, nothing is
                // refused: a refreshed selector is not an attempted switch.
                return;
            }

            throw ValidationException::withMessages([
                'branch_id' => $this->lockedMessage($context),
            ]);
        });
    }

    /**
     * The refusal an operator sees. Actionable, and it names the branch they are
     * already committed to — that is their own working context, not a leak.
     */
    private function lockedMessage(DailyBranchContext $context): string
    {
        $branchName = $this->branches->findById((int) $context->current_branch_id)?->name
            ?? 'cabang yang dipilih sebelumnya';

        return 'Cabang kerja Anda hari ini sudah terkunci di '.$branchName.'. '
            .'Ajukan perpindahan cabang untuk mendapatkan persetujuan Super Admin.';
    }

    /**
     * Recognise a unique-constraint violation across both drivers the project
     * runs on: PostgreSQL in production, SQLite in the suite.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        if ($exception->getCode() === '23505') {  // PostgreSQL unique_violation
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation')
            || str_contains($message, 'duplicate key');
    }
}
