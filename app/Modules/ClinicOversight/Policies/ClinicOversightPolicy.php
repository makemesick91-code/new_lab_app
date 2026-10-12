<?php

namespace App\Modules\ClinicOversight\Policies;

use App\Models\User;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\MedicalRecord\Models\MedicalRecord;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — record-level authorization of
 * the read-only oversight module, registered as named gates
 * (`clinic-oversight.*`) in RepositoryServiceProvider.
 *
 * Every ability is a READ ability. There is no write ability here and there is
 * deliberately nothing to grant one: the oversight module has no POST/PUT/
 * PATCH/DELETE route at all.
 *
 * NOTE — Super Admin passes every gate through the single global Gate::before.
 * That is why the module never relies on a gate ALONE for the properties that
 * must hold for everyone: an out-of-scope id is refused (404) by the
 * branch-scoped lookup BEFORE any gate runs, and "only PUBLISHED legacy
 * archives stream" is re-asserted in the controller.
 */
class ClinicOversightPolicy
{
    public function __construct(private readonly ClinicOversightScope $scope) {}

    public function viewDashboard(User $user): bool
    {
        return $this->scope->holds($user, ClinicOversightScope::PERMISSION_DASHBOARD);
    }

    public function viewClinicalRecords(User $user): bool
    {
        return $this->scope->holds($user, ClinicOversightScope::PERMISSION_CLINICAL);
    }

    public function viewMonitoring(User $user): bool
    {
        return $this->scope->holds($user, ClinicOversightScope::PERMISSION_MONITORING);
    }

    public function viewVisit(User $user, ClinicVisit $visit): bool
    {
        return $this->scope->allowsBranch($user, ClinicOversightScope::PERMISSION_CLINICAL, $this->int($visit->branch_id));
    }

    public function viewMedicalRecord(User $user, MedicalRecord $record): bool
    {
        return $this->scope->allowsBranch($user, ClinicOversightScope::PERMISSION_CLINICAL, $this->int($record->branch_id));
    }

    public function viewLegacyRme(User $user, LegacyRmeRecord $record): bool
    {
        return $record->isPublished()
            && $this->scope->allowsBranch($user, ClinicOversightScope::PERMISSION_CLINICAL, $this->int($record->origin_branch_id));
    }

    public function viewLegacyOdontogram(User $user, LegacyOdontogramRecord $record): bool
    {
        return $record->isPublished()
            && $this->scope->allowsBranch($user, ClinicOversightScope::PERMISSION_CLINICAL, $this->int($record->branch_id));
    }

    /**
     * Print / PDF / CSV of an area the user may already VIEW. Export is never an
     * area on its own: without the area's view permission it is refused.
     */
    public function export(User $user, string $area): bool
    {
        return $this->scope->mayExport($user, $area);
    }

    private function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
