<?php

namespace App\Modules\Patient\Policies;

use App\Models\User;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\Patient\Services\PatientDocumentAccessService;
use Illuminate\Auth\Access\Response;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — gate for a stored patient
 * identity document.
 *
 * A thin adapter over {@see PatientDocumentAccessService::decide()}: the rule
 * lives there, once. This policy exists so the patient page can ask `@can`
 * before rendering a link, and it never writes an audit row — a policy is also
 * evaluated merely to decide whether to SHOW a button, and auditing that would
 * log a "view" nobody performed. The audited, enforcing call is the
 * controller's {@see PatientDocumentAccessService::authorize()}.
 *
 * A refusal is `denyAsNotFound()`: an operator at another branch learns nothing
 * about whether the document exists.
 *
 * Super Admin never reaches these methods — the single global Gate::before
 * answers first. That is consistent with the service, which treats Super Admin
 * as holding the cross-branch permission through the same Gate.
 */
class PatientDocumentPolicy
{
    public function __construct(
        private readonly PatientDocumentAccessService $access,
    ) {}

    public function view(User $user, PatientDocument $document): Response
    {
        return $this->answer($user, $document);
    }

    public function delete(User $user, PatientDocument $document): Response
    {
        return $this->answer($user, $document);
    }

    private function answer(User $user, PatientDocument $document): Response
    {
        return $this->access->decide($user, $document)->allowed
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
