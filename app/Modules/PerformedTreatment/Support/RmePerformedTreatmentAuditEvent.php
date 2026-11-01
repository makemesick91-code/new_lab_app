<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Support;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — audit actions written to
 * the shared `sys_audit_logs` trail. Payloads hold ids, codes, counts and lengths
 * only: never a patient name, KTP/NIK, a note or the free-text void reason (which
 * stays on the clinical row itself).
 */
final class RmePerformedTreatmentAuditEvent
{
    public const ENTITY_TYPE = 'clinic_visit';

    public const DECLARED = 'RME_TREATMENT_DECLARATION_SET';

    public const RECORDED = 'RME_PERFORMED_TREATMENT_RECORDED';

    public const VOIDED = 'RME_PERFORMED_TREATMENT_VOIDED';
}
