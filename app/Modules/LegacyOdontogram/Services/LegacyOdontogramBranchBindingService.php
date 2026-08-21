<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Models\User;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\LegacyRme\Services\LegacyRmeBranchResolver;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\Patient\Models\Patient;

/**
 * FIX-04b — the SINGLE source of truth for the branch a legacy odontogram
 * chart belongs to.
 *
 * THE RULE. The branch is DERIVED from the branch-code segment of the patient's
 * canonical Nomor RM — it is never chosen by the operator:
 *
 *     DG-TKM1-2024-9985  →  TKM1  →  Cabang Telkomas
 *
 * WHY IT IS NOT OPERATOR INPUT. `origin_branch_id` is not a descriptive note:
 * the repositories filter row visibility on it and the policies evaluate it.
 * Letting a request choose it would let an operator file a patient's history
 * under a branch that does not own that patient, and quietly change who can read
 * it afterwards. The patient's own RM already states the answer, so the server
 * reads it there. No form field maps to a branch and the FormRequest accepts
 * none, so there is nothing to override in the first place.
 *
 * FAIL CLOSED, ALWAYS. There is no fallback: not the acting user's branch, not
 * BranchContext, not the first RME-enabled branch, and never a value submitted
 * with the request. A missing, malformed, unknown, ambiguous, inactive or
 * non-RME branch code is refused with a stable code, so the operator fixes the
 * patient master data instead of the archive silently landing in the wrong place.
 *
 * WHAT IS REUSED, AND WHAT IS NOT. The RM → branch derivation itself is
 * genuinely document-generic, so LegacyRmeBranchResolver is reused verbatim
 * rather than reimplemented — one parser, one branch-code contract, one set of
 * failure codes. It is deliberately called WITHOUT an actor: passing one would
 * make it apply LegacyRmeWorkspaceScope, whose governance tier is the legacy RME
 * intake permissions, and this capability's operators are not those people. The
 * actor's scope is therefore applied HERE, against this module's own
 * LegacyOdontogramWorkspaceScope.
 */
class LegacyOdontogramBranchBindingService
{
    public function __construct(
        private readonly LegacyRmeBranchResolver $resolver,
        private readonly LegacyOdontogramWorkspaceScope $scope,
    ) {}

    /**
     * Resolve the owning branch from the patient's Nomor RM, then confirm it is
     * inside the acting operator's own workspace scope.
     *
     * A scoped operator may not archive another branch's history — and could not
     * read the row afterwards anyway, so refusing is both the safe and the
     * honest outcome.
     */
    public function resolveForPatient(Patient $patient, ?User $actor = null): LegacyRmeBranchResolution
    {
        // No actor: the shared resolver answers only "which branch does this RM
        // name?", never "may this person use it?".
        $resolution = $this->resolver->resolveForPatient($patient, null);

        if ($resolution->failed()) {
            return $resolution;
        }

        if ($actor !== null && ! $this->scope->allows($actor, $resolution->branchId)) {
            return LegacyRmeBranchResolution::failure(
                LegacyRmeBranchResolution::CODE_BRANCH_OUT_OF_SCOPE,
                sprintf(
                    'Arsip pasien ini milik cabang %s. Akun Anda tidak memiliki akses ke cabang tersebut.',
                    (string) $resolution->branchCode,
                ),
                $resolution->branchCode,
            );
        }

        return $resolution;
    }
}
