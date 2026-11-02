<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Requests;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\PerformedTreatment\Services\RmePerformedTreatmentService;

/**
 * ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219 §15) — the HTTP mirror of
 * the service's admission: 404 unless the capability is live for the ROUTED visit
 * (flag ON and the visit's branch admitted by the pilot allowlist). Outside the
 * pilot the routes look exactly as they do with the flag OFF. The branch is the
 * route-bound visit's, never a request value; the service re-decides it under the
 * visit row lock.
 */
trait EnsuresPerformedTreatmentAdmission
{
    protected function ensurePerformedTreatmentAdmission(): void
    {
        $visit = $this->route('clinicVisit');

        abort_unless(
            $visit instanceof ClinicVisit && app(RmePerformedTreatmentService::class)->enabledFor($visit),
            404,
        );
    }
}
