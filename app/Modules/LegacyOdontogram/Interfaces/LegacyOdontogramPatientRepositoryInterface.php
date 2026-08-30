<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Interfaces;

use App\Models\User;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Collection;

/**
 * BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — the module's only door to
 * `mst_patients`.
 *
 * Before this sprint the controller reached for `Patient::query()->find()`
 * directly. That single line is what let the defect exist: there was no seam to
 * project columns at, no seam to apply a scope at, and no seam to test at, so
 * the lookup's behaviour was whatever `intval()` happened to do that day.
 *
 * Both methods project a fixed, non-sensitive column set. KTP/NIK, phone,
 * WhatsApp, e-mail and address are never selected, so they cannot be rendered,
 * logged or serialised by accident further up the stack.
 */
interface LegacyOdontogramPatientRepositoryInterface
{
    /**
     * Resolve one patient by surrogate key.
     *
     * Soft-deleted patients are excluded — a deleted patient is "not found",
     * never a selectable target for new clinical evidence.
     */
    public function findSelectableById(?User $actor, int $patientId): ?Patient;

    /**
     * Resolve patients by canonical Nomor RM: exact match first, then a bounded
     * "ends with" match so an operator can type the tail they remember.
     *
     * @return Collection<int, Patient>
     */
    public function searchByMedicalRecordNumber(?User $actor, string $medicalRecordNumber, int $limit): Collection;
}
