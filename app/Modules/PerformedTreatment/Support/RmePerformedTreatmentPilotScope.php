<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Support;

use App\Modules\Branch\Services\BranchAllowlistResolver;

/**
 * ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219 §15; owner DPT-Q8) —
 * WHERE an ON `rme.doctor_performed_treatments` flag reaches.
 *
 * The flag is a single global switch. The Owner approved a pilot for ONE branch
 * (SPN4, designated 2026-11-02), so authoring, the RME-page section, the cashier
 * projection and the rule 218 evidence additionally require the visit's branch to
 * be admitted by the source-controlled allowlist `config/rme_performed_treatment.php`.
 *
 * FAIL CLOSED — any configuration other than exactly one well-formed code naming
 * one active RME-enabled branch admits NOTHING:
 *   EMPTY · MALFORMED (not a list of strings) · MAIN_LISTED · TOO_MANY_BRANCHES
 *   (DPT-Q8 is one branch; a second needs a new Owner decision) · UNRESOLVED (the
 *   code names no active RME-enabled branch).
 *
 * The branch always comes from the VISIT (and, for a write, from the row re-read
 * under the visit lock) — never from the request, the session or the actor.
 */
final class RmePerformedTreatmentPilotScope
{
    public const CONFIG_KEY = 'rme_performed_treatment.pilot.branch_codes';

    /** DPT-Q8: the pilot is ONE branch. Raising this is a new Owner decision. */
    public const MAX_PILOT_BRANCHES = 1;

    public const STATUS_VALID = 'VALID';

    public const STATUS_EMPTY = 'EMPTY';

    public const STATUS_MALFORMED = 'MALFORMED';

    public const STATUS_MAIN_LISTED = 'MAIN_LISTED';

    public const STATUS_TOO_MANY_BRANCHES = 'TOO_MANY_BRANCHES';

    public const STATUS_UNRESOLVED = 'UNRESOLVED';

    public function __construct(
        private readonly BranchAllowlistResolver $resolver,
    ) {}

    /**
     * The canonical codes the allowlist names (MAIN removed); empty when malformed.
     *
     * @return list<string>
     */
    public function declaredCodes(): array
    {
        return $this->resolver->inspect(config(self::CONFIG_KEY))['codes'];
    }

    public function configurationStatus(): string
    {
        return $this->evaluate()['status'];
    }

    /**
     * Ids of the branches the pilot admits — empty unless the configuration is VALID.
     *
     * @return list<int>
     */
    public function admittedBranchIds(): array
    {
        $result = $this->evaluate();

        return $result['status'] === self::STATUS_VALID ? array_values($result['live']) : [];
    }

    /**
     * Canonical codes of the admitted branches — empty unless VALID.
     *
     * @return list<string>
     */
    public function admittedBranchCodes(): array
    {
        $result = $this->evaluate();

        return $result['status'] === self::STATUS_VALID ? array_keys($result['live']) : [];
    }

    public function admits(?int $branchId): bool
    {
        return $branchId !== null && in_array($branchId, $this->admittedBranchIds(), true);
    }

    /**
     * @return array{status: string, live: array<string, int>}
     */
    private function evaluate(): array
    {
        $inspected = $this->resolver->inspect(config(self::CONFIG_KEY));

        if ($inspected['malformed']) {
            return ['status' => self::STATUS_MALFORMED, 'live' => []];
        }

        if ($inspected['main_listed']) {
            return ['status' => self::STATUS_MAIN_LISTED, 'live' => []];
        }

        if ($inspected['codes'] === []) {
            return ['status' => self::STATUS_EMPTY, 'live' => []];
        }

        if (count($inspected['codes']) > self::MAX_PILOT_BRANCHES) {
            return ['status' => self::STATUS_TOO_MANY_BRANCHES, 'live' => []];
        }

        $live = $this->resolver->liveBranchIds($inspected['codes']);

        if (count($live) !== count($inspected['codes'])) {
            return ['status' => self::STATUS_UNRESOLVED, 'live' => []];
        }

        return ['status' => self::STATUS_VALID, 'live' => $live];
    }
}
