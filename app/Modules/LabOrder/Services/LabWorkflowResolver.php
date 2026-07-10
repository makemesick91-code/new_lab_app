<?php

namespace App\Modules\LabOrder\Services;

use App\Modules\LabOrder\Models\LabOrder;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 — resolves which workflow engine an order uses and guards
 * the legacy pipeline against V2 orders.
 *
 * The active engine for NEW orders is decided ONLY here, by branching on the
 * `lab.workflow_v2` feature flag — config never toggles behavior on its own
 * (NSF-9). Existing orders keep whatever version they were stamped with;
 * legacy history stays readable and legacy-mutable, V2 orders are refused by
 * the legacy inline pipeline.
 */
class LabWorkflowResolver
{
    public function __construct(
        private readonly FeatureFlagService $featureFlags,
    ) {}

    /** Is Lab Workflow V2 the active engine for newly created orders? */
    public function isV2Active(): bool
    {
        return $this->featureFlags->enabled((string) config('lab_workflow.v2_feature_flag', 'lab.workflow_v2'));
    }

    /** Workflow version to stamp on a newly created order. */
    public function versionForNewOrder(): int
    {
        return $this->isV2Active()
            ? (int) config('lab_workflow.version.v2', LabOrder::WORKFLOW_V2)
            : (int) config('lab_workflow.version.legacy', LabOrder::WORKFLOW_LEGACY);
    }

    /**
     * Guard the legacy creation entry point. Once V2 is the active engine, new
     * orders must be created through the Lab Workflow V2 (Cabang) flow — the
     * legacy create path is disabled server-side (not merely hidden in the UI).
     */
    public function assertLegacyCreationAllowed(): void
    {
        if ($this->isV2Active()) {
            throw ValidationException::withMessages([
                'workflow_version' => 'Pembuatan order melalui alur lama dinonaktifkan. Gunakan alur Lab Workflow V2.',
            ]);
        }
    }

    /**
     * Guard a legacy (Sprint 3-7) mutation: refuse when the order is a V2 order.
     * Server-side enforcement so a V2 order can never be driven by the legacy
     * pipeline (route/controller/direct request are all covered by callers).
     */
    public function assertLegacyMutable(LabOrder $order): void
    {
        if ($order->isV2Workflow()) {
            throw ValidationException::withMessages([
                'workflow_version' => 'Order ini memakai Lab Workflow V2 dan tidak dapat diproses melalui alur lama.',
            ]);
        }
    }
}
