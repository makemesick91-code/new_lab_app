<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\PerformedTreatment\Interfaces\RmePerformedTreatmentRepositoryInterface;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentFeatureGuard;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentPilotScope;
use App\Modules\RmeInvoice\Support\RmeZeroValueClosureFeatureGuard;
use App\Services\Foundation\FeatureFlagService;
use Throwable;

/**
 * ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219 §15) — the read-only
 * activation readiness of the doctor's "Tindakan Dilakukan" record: is the global
 * switch ON, is the pilot allowlist valid, and which RME branch does an ON flag
 * actually reach (ADMITTED) or not (DENIED)?
 *
 * This is the production verification probe: it never logs in, never opens a REPL
 * and never writes a row. It reports counts only — no patient, visit or treatment
 * identity.
 */
final class RmePerformedTreatmentReadinessService
{
    public const VERDICT_READY_NOT_ARMED = 'READY_NOT_ARMED';

    public const VERDICT_ARMED = 'ARMED';

    public const VERDICT_NOT_ARMED_INVALID = 'NOT_ARMED_INVALID_CONFIGURATION';

    public const VERDICT_ARMED_INVALID = 'ARMED_INVALID_CONFIGURATION';

    public function __construct(
        private readonly RmePerformedTreatmentFeatureGuard $feature,
        private readonly RmeZeroValueClosureFeatureGuard $zeroValue,
        private readonly RmePerformedTreatmentPilotScope $pilot,
        private readonly BranchService $branches,
        private readonly RmePerformedTreatmentRepositoryInterface $rows,
        private readonly FeatureFlagService $flags,
    ) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        $enabled = $this->feature->enabled();
        $status = $this->pilot->configurationStatus();
        $admittedIds = $this->pilot->admittedBranchIds();
        $valid = $status === RmePerformedTreatmentPilotScope::STATUS_VALID;

        $branches = [];
        foreach ($this->branches->listRmeEnabled() as $branch) {
            $code = BranchCodeAlias::normalize((string) $branch->code);

            if ($code === null || $code === Branch::MAIN_CODE) {
                continue;
            }

            $branches[$code] = in_array((int) $branch->id, $admittedIds, true) ? 'ADMITTED' : 'DENIED';
        }
        ksort($branches);

        $approval = (array) config('rme_performed_treatment.pilot.approval', []);

        return [
            'capability' => RmePerformedTreatmentFeatureGuard::FLAG,
            'verdict' => match (true) {
                $enabled && $valid => self::VERDICT_ARMED,
                $enabled => self::VERDICT_ARMED_INVALID,
                $valid => self::VERDICT_READY_NOT_ARMED,
                default => self::VERDICT_NOT_ARMED_INVALID,
            },
            'flag' => [
                'enabled' => $enabled,
                'env_resolution' => $this->envResolution(RmePerformedTreatmentFeatureGuard::FLAG),
            ],
            'zero_value_closure_enabled' => $this->zeroValue->enabled(),
            'pilot' => [
                'configuration_status' => $status,
                'declared_codes' => $this->pilot->declaredCodes(),
                'admitted_branch_codes' => $this->pilot->admittedBranchCodes(),
                'max_pilot_branches' => RmePerformedTreatmentPilotScope::MAX_PILOT_BRANCHES,
                'approval_decision' => isset($approval['decision']) ? (string) $approval['decision'] : null,
                'approval_date' => isset($approval['approved_on']) ? (string) $approval['approved_on'] : null,
            ],
            'branches' => $branches,
            'records' => $this->rows->totals(),
        ];
    }

    public function configurationValid(): bool
    {
        return $this->pilot->configurationStatus() === RmePerformedTreatmentPilotScope::STATUS_VALID;
    }

    private function envResolution(string $flag): ?string
    {
        try {
            $hydrated = $this->flags->get($flag);
        } catch (Throwable) {
            return null;
        }

        return is_array($hydrated) && isset($hydrated['env_resolution']) ? (string) $hydrated['env_resolution'] : null;
    }
}
