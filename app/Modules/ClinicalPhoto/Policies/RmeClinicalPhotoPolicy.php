<?php

namespace App\Modules\ClinicalPhoto\Policies;

use App\Models\User;
use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use App\Modules\ClinicalPhoto\Services\RmeClinicalPhotoService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Auth\Access\Response;

/**
 * DMS memo M4.4 / M1.x, PTM PR4 (rule 242) — the policy layer for clinical
 * photos. It asks the service's own predicates, so the policy and the service
 * can never disagree; the service decides again on every call, because
 * Gate::before lets Super Admin past this class.
 */
class RmeClinicalPhotoPolicy
{
    public function __construct(
        private readonly RmeClinicalPhotoService $photos,
    ) {}

    /** The account side of the upload authority (403); state is the service's (422). */
    public function upload(User $user, ClinicVisit $clinicVisit): Response
    {
        if (! $this->photos->enabled()) {
            return Response::denyAsNotFound();
        }

        $refusal = $this->photos->uploadAccountRefusal($user, $clinicVisit);

        return $refusal === null ? Response::allow() : Response::deny($refusal);
    }

    /** Out of scope reads exactly like a missing photo (404). */
    public function view(User $user, RmeClinicalPhoto $photo): Response
    {
        return $this->photos->mayView($user, $photo) ? Response::allow() : Response::denyAsNotFound();
    }

    /** Rule 242 §9 — read scope first (404), then permission and maker ≠ checker (403). */
    public function void(User $user, RmeClinicalPhoto $photo): Response
    {
        if (! $this->photos->mayView($user, $photo)) {
            return Response::denyAsNotFound();
        }

        $refusal = $this->photos->voidAccountRefusal($user, $photo);

        return $refusal === null ? Response::allow() : Response::deny($refusal);
    }
}
