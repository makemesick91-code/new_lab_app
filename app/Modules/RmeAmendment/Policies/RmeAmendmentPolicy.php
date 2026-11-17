<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Policies;

use App\Models\User;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use Illuminate\Auth\Access\Response;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §6) — the policy layer of
 * the supervised amendment (the route permission is the outer layer,
 * RmeAmendmentService the inner one).
 *
 * Gate::before lets Super Admin past every clause here, which is exactly why the
 * identity rules that must bind Super Admin too (requester = doctor of record or
 * finalizer; checker ≠ requester, ≠ doctor of record) are re-decided by the
 * service inside its transaction. A record the account may not see answers 404,
 * so the existence of an amendment does not leak (CG-05).
 */
class RmeAmendmentPolicy
{
    /** The supervisor queue. */
    public function viewAny(User $user): bool
    {
        return $user->can(RmeAmendmentService::PERMISSION_APPROVE);
    }

    public function view(User $user, RmeAmendment $amendment): Response
    {
        return $this->amendments()->mayView($user, $amendment)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /** File an amendment of this final sheet or finalized chart. */
    public function request(User $user, MedicalRecord|Odontogram $subject): Response
    {
        $refusal = $this->amendments()->requestRefusal($subject, $user);

        return $refusal === null ? Response::allow() : Response::deny($refusal);
    }

    public function cancel(User $user, RmeAmendment $amendment): Response
    {
        if (! $this->amendments()->mayView($user, $amendment)) {
            return Response::denyAsNotFound();
        }

        return (int) $amendment->requested_by === (int) $user->getKey()
            ? Response::allow()
            : Response::deny(RmeAmendmentService::MESSAGE_CANCEL_NOT_REQUESTER);
    }

    public function reject(User $user, RmeAmendment $amendment): Response
    {
        $amendments = $this->amendments();
        $subject = $amendments->subjectOf($amendment);

        if ($subject === null || ! $amendments->mayView($user, $amendment, $subject)) {
            return Response::denyAsNotFound();
        }

        // A decided amendment passes here and gets the service's "no longer
        // pending" answer; everything else is refused with its own reason.
        $refusal = $amendment->isPending() ? $amendments->decisionRefusal($amendment, $subject, $user) : null;

        return $refusal === null ? Response::allow() : Response::deny($refusal);
    }

    private function amendments(): RmeAmendmentService
    {
        return app(RmeAmendmentService::class);
    }
}
