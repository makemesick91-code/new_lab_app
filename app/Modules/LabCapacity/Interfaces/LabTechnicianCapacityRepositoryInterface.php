<?php

namespace App\Modules\LabCapacity\Interfaces;

use Illuminate\Support\Collection;

/**
 * LAB-PROD-3 — Capacity-planning data boundary.
 *
 * All order queries are Lab Workflow V2 only (workflow_version = 2), capped by
 * config('lab_technician_capacity.max_scan_orders'), and PII-free (no patient
 * name / KTP / NIK). Branch scope applies to DEMAND (orders); technicians are
 * lab-wide. Configuration/profile calculators live in the service, not here.
 */
interface LabTechnicianCapacityRepositoryInterface
{
    /**
     * Open (non-terminal) V2 orders with the fields needed to compute assigned
     * load, unassigned demand, per-service demand and due-date risk.
     *
     * @param  array{lab_service_id?:int|null,status?:string|null,technician_id?:int|null,sourcing?:string|null}  $filters
     */
    public function openOrders(?int $branchId, array $filters): Collection;

    /** Active capacity profiles for the given technicians. */
    public function capacityProfiles(array $technicianIds): Collection;

    /** Availability overrides for the given technicians within [$from,$to]. */
    public function availabilityOverrides(array $technicianIds, string $from, string $to): Collection;

    /** Eligible capability rows for the given technicians. */
    public function capabilities(array $technicianIds): Collection;

    /** Active service workload profiles. */
    public function workloadProfiles(): Collection;

    /** Active lab services (id, name) for demand rows + config UI. */
    public function activeLabServices(): Collection;
}
