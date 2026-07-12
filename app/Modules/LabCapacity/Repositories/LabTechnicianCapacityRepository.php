<?php

namespace App\Modules\LabCapacity\Repositories;

use App\Modules\LabCapacity\Interfaces\LabTechnicianCapacityRepositoryInterface;
use App\Modules\LabCapacity\Models\LabServiceWorkloadProfile;
use App\Modules\LabCapacity\Models\TechnicianAvailabilityOverride;
use App\Modules\LabCapacity\Models\TechnicianCapability;
use App\Modules\LabCapacity\Models\TechnicianCapacityProfile;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\LabService\Models\LabService;
use Illuminate\Support\Collection;

/**
 * LAB-PROD-3 — Capacity-planning repository. V2-only, capped, PII-free.
 */
class LabTechnicianCapacityRepository implements LabTechnicianCapacityRepositoryInterface
{
    private function cap(): int
    {
        return (int) config('lab_technician_capacity.max_scan_orders', 5000);
    }

    public function openOrders(?int $branchId, array $filters): Collection
    {
        return LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->whereNotIn('status', LabWorkflowState::TERMINAL)
            ->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(
                $filters['lab_service_id'] ?? null,
                fn ($q, $id) => $q->whereHas('items', fn ($i) => $i->where('lab_service_id', $id))
            )
            ->when(
                $filters['technician_id'] ?? null,
                fn ($q, $t) => $q->whereHas('activeAssignment', fn ($a) => $a->where('technician_id', $t))
            )
            ->with([
                'items:id,lab_order_id,lab_service_id,quantity',
                'activeAssignment',
                'branch:id,name',
            ])
            ->orderBy('id')
            ->limit($this->cap())
            ->get(['id', 'order_number', 'branch_id', 'due_date', 'order_date', 'received_at', 'status', 'priority']);
    }

    public function capacityProfiles(array $technicianIds): Collection
    {
        return TechnicianCapacityProfile::query()
            ->where('is_active', true)
            ->whereIn('technician_id', $technicianIds)
            ->orderByDesc('effective_from')
            ->get();
    }

    public function availabilityOverrides(array $technicianIds, string $from, string $to): Collection
    {
        return TechnicianAvailabilityOverride::query()
            ->whereIn('technician_id', $technicianIds)
            ->whereDate('override_date', '>=', $from)
            ->whereDate('override_date', '<=', $to)
            ->get();
    }

    public function capabilities(array $technicianIds): Collection
    {
        return TechnicianCapability::query()
            ->where('is_eligible', true)
            ->whereIn('technician_id', $technicianIds)
            ->get();
    }

    public function workloadProfiles(): Collection
    {
        return LabServiceWorkloadProfile::query()
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->get();
    }

    public function activeLabServices(): Collection
    {
        return LabService::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
