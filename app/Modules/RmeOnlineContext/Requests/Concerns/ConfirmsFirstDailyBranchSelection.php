<?php

namespace App\Modules\RmeOnlineContext\Requests\Concerns;

use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Validation\Validator;

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D5 — make the operator confirm
 * the branch BEFORE the day is committed to it.
 *
 * WHY THIS EXISTS. The first branch a Kasir or Admin Klinik picks commits them
 * for the whole clinical day: `trx_daily_branch_contexts` is
 * UNIQUE(user_id, clinical_date), and undoing a wrong pick needs a Super Admin
 * to approve a branch-change request. On a branch opening for the first time,
 * with staff who have never used the selector, a mis-click is not unlikely —
 * it is the expected failure.
 *
 * WHY CONFIRMATION RATHER THAN AN UNDO WINDOW. A self-service unlock would add
 * a second, softer way out of a lock whose entire value is that it is hard to
 * leave, and it would need its own state machine, its own audit story and its
 * own race. A confirmation adds no reversal path at all: it only asks the
 * question before the write instead of after it.
 *
 * WHY IN THE FORM REQUEST. The requirement is that confirmation PRECEDES the
 * mutation. A FormRequest runs before the controller action, so the lock
 * cannot exist yet when this refuses — whereas a check inside the service
 * would be racing the very write it guards.
 *
 * FAIL CLOSED. The confirmation token is the branch id the operator saw. A
 * submit with no token, or one naming a different branch from the one being
 * committed, is refused — so a stale form, a double submit that changed the
 * selection, or a tampered field cannot commit a day to a branch nobody
 * confirmed. Only the FIRST selection of a clinical day is gated; once the day
 * is locked there is nothing left to confirm and the guard steps aside.
 */
trait ConfirmsFirstDailyBranchSelection
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            /*
             * AUTHORIZATION FIRST, VALIDATION SECOND.
             *
             * A FormRequest's validator runs BEFORE the controller, so without
             * this guard a Perawat posting to the admin-clinic endpoint got a
             * 302 validation bounce instead of the 403 the controller owes
             * them — the confirmation rule was answering a question about a
             * role the caller does not even hold. Caught by
             * PerawatOnlineContextTest, which asserts that refusal.
             *
             * Only a user actually subject to a LOCKED role context can create
             * a daily lock, so only they have anything to confirm. Everyone
             * else falls through to the controller's own refusal.
             */
            $onlineContext = app(UserOnlineContextService::class);

            if (! $onlineContext->requiresAdminClinicContext($user)
                && ! $onlineContext->requiresKasirContext($user)) {
                return;
            }

            /** @var DailyBranchContextService $dailyBranchContext */
            $dailyBranchContext = app(DailyBranchContextService::class);

            // Already committed today: this submit cannot create the lock, so
            // there is nothing for the operator to confirm.
            if ($dailyBranchContext->currentFor($user) !== null) {
                return;
            }

            $selected = $this->input('branch_id');
            $confirmed = $this->input(DailyBranchContextService::CONFIRMATION_FIELD);

            if ($confirmed === null || $confirmed === '') {
                $validator->errors()->add(
                    DailyBranchContextService::CONFIRMATION_FIELD,
                    'Konfirmasi cabang terlebih dahulu. Pilihan cabang pertama hari ini akan dikunci untuk seluruh hari operasional.',
                );

                return;
            }

            // Compared loosely on purpose: the confirmation arrives as a form
            // string and the selection as an integer-ish input. What matters
            // is that they name the SAME branch, not that they share a type.
            if ((int) $confirmed !== (int) $selected) {
                $validator->errors()->add(
                    DailyBranchContextService::CONFIRMATION_FIELD,
                    'Cabang yang dikonfirmasi berbeda dengan cabang yang dipilih. Ulangi konfirmasi.',
                );
            }
        });
    }
}
