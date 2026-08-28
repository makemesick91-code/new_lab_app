<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use Throwable;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1A — the read-only answer to "is legacy RME
 * migration ACTUALLY open on this deployment right now?".
 *
 * THE GAP THIS CLOSES. FEATURE-LEGACY-IMPORT-HUB-1 shipped a hub that reported
 * a capability as `aktif` on the strength of its feature flag and its route.
 * For legacy RME that is not the whole story and never was: a document is only
 * accepted after CAPABILITY, ROLL-3 ADMISSION and the ROLL-4 OPERATIONS layer
 * all admit it. The hub knew this and said so — with a hard-coded disclaimer
 * that read the same whether the gates were wide open or completely shut. So
 * the page reported "Aktif, but there are other gates" for a surface that
 * refused every single upload, which is precisely the lie its own docblock says
 * the status field exists to prevent. A permanently-true caveat is not a state.
 *
 * IT COMPOSES AUTHORITIES, IT NEVER RE-DECIDES. Every fact below is read from
 * the service that already owns it:
 *
 *   CAPABILITY  LegacyRmeFeatureGuard::migrationEnabled()
 *   ADMISSION   LegacyRmeBranchAdmissionService — including the per-branch
 *               verdict, which is taken by calling the REAL gate rather than
 *               re-reading its allowlist. A second implementation of an
 *               allowlist comparison is how "the report agrees with the gate"
 *               quietly stops being true.
 *   WAVE        LegacyRmeWaveBindingService (declared vs registered vs bound)
 *   OPERATIONS  LegacyRmeOperationsGateService::enforced()
 *
 * Nothing here can admit anything. There is no path in this class that returns
 * a decision to a writer, and no caller may treat `open` as permission: the
 * ceiling that actually accepts a document is still taken inside the
 * transaction that writes it. This is a REPORT — of the recent past, for a
 * human — exactly like the hub page that consumes it.
 *
 * WHY THE OPERATIONS HALF IS DEPLOYMENT-LEVEL, NOT PER-OPERATOR. Answering "may
 * THIS operator migrate THIS branch right now" requires an actor, an assignment
 * lookup and a quota preview, and LegacyRmeOperationsGateService already
 * answers it at the point of use. Duplicating that here would either be a
 * second weaker gate or an N+1 across every branch on a status page. This
 * reports the state an operator cannot discover for themselves — is a wave
 * declared, registered, running, and does it still match the approval that
 * authorized it — and leaves the per-operator answer where it is enforced.
 *
 * FAIL CLOSED, AND NEVER FABRICATE. An unreadable wave table yields `open =
 * false` with an explicit blocker, never a cheerful default. A blocker is
 * always a stable machine code; the human sentence lives in the view.
 *
 * PII POLICY. Branch codes, a wave label, statuses and booleans. Never a
 * patient name, a Nomor RM, a KTP/NIK, a filename or a document path.
 */
class LegacyRmeActivationStateService
{
    /** Every gate admits: new migration work can genuinely start. */
    public const BLOCKER_NONE = null;

    /** The archive capability itself is switched off. */
    public const BLOCKER_CAPABILITY_OFF = 'CAPABILITY_OFF';

    /** Capability is on, but no branch is admitted — the safe resting state. */
    public const BLOCKER_NO_BRANCH_ADMITTED = 'NO_BRANCH_ADMITTED';

    /** Branches are admitted with no owner approval recorded for the wave. */
    public const BLOCKER_APPROVAL_MISSING = 'APPROVAL_MISSING';

    /** The allowlist was widened without widening the approval. */
    public const BLOCKER_APPROVAL_INCOMPLETE = 'APPROVAL_INCOMPLETE';

    /** Branches are admitted but no wave label is declared. */
    public const BLOCKER_WAVE_NOT_DECLARED = 'WAVE_NOT_DECLARED';

    /** A wave is declared but has no operational record. */
    public const BLOCKER_WAVE_NOT_REGISTERED = 'WAVE_NOT_REGISTERED';

    /** The wave exists but is not ingesting (draft, paused, draining, done). */
    public const BLOCKER_WAVE_NOT_ACTIVE = 'WAVE_NOT_ACTIVE';

    /** The wave record disagrees with the approval that authorized it. */
    public const BLOCKER_WAVE_BINDING_MISMATCH = 'WAVE_BINDING_MISMATCH';

    /** The wave record could not be read at all. */
    public const BLOCKER_WAVE_UNREADABLE = 'WAVE_UNREADABLE';

    public function __construct(
        private readonly LegacyRmeFeatureGuard $feature,
        private readonly LegacyRmeBranchAdmissionService $admission,
        private readonly LegacyRmeWaveBindingService $binding,
        private readonly LegacyRmeOperationsGateService $operations,
    ) {}

    /**
     * The deployment-wide activation state, plus a per-branch admission verdict
     * for each branch code the caller cares about.
     *
     * @param  list<string>  $branchCodes  branch codes already resolved by the
     *                                     caller from its own scope; never a
     *                                     value taken from a request body
     * @return array<string, mixed>
     */
    public function state(array $branchCodes = []): array
    {
        $capability = $this->feature->migrationEnabled();

        $admissionEnforced = $this->admission->enforced();
        $admitted = $this->admission->admittedBranchCodes();
        $approvalReference = $this->admission->approvalReference();
        $unapproved = $this->admission->unapprovedAdmittedBranchCodes();

        $operationsEnforced = $this->operations->enforced();
        $declaredWave = $this->binding->declaredWaveCode();

        $wave = null;
        $waveUnreadable = false;

        if ($declaredWave !== null) {
            try {
                $wave = $this->binding->resolveWave();
            } catch (Throwable) {
                // A missing or unreadable wave table must read as "shut", never
                // as "no wave needed". Reporting the latter would turn an
                // infrastructure fault into an apparent green light.
                $waveUnreadable = true;
            }
        }

        $bindingMatches = $wave !== null && $this->binding->bindingMatches($wave);

        $blocker = $this->blocker(
            capability: $capability,
            admissionEnforced: $admissionEnforced,
            admitted: $admitted,
            approvalReference: $approvalReference,
            unapproved: $unapproved,
            operationsEnforced: $operationsEnforced,
            declaredWave: $declaredWave,
            wave: $wave,
            waveUnreadable: $waveUnreadable,
            bindingMatches: $bindingMatches,
        );

        return [
            'applies' => true,
            'open' => $blocker === self::BLOCKER_NONE,
            'blocker' => $blocker,

            'capability_enabled' => $capability,

            'admission_enforced' => $admissionEnforced,
            'admitted_branch_codes' => $admitted,
            'approved_branch_codes' => $this->admission->approvedBranchCodes(),
            'unapproved_admitted_branch_codes' => $unapproved,
            'approval_recorded' => $approvalReference !== '',

            'operations_enforced' => $operationsEnforced,
            'declared_wave' => $declaredWave,
            'registered_wave' => $wave?->code,
            'wave_status' => $wave?->status,
            'wave_ingesting' => $wave !== null && $wave->status === LegacyRmeWaveStatus::INGESTABLE,
            'binding_matches' => $bindingMatches,

            'branches' => $this->branchStates($branchCodes, $wave),
        ];
    }

    /**
     * The first gate that refuses, in the order an operator can act on them.
     *
     * Order is deliberate and mirrors the runtime chain: capability explains
     * everything and reveals nothing about the wave; admission is meaningless
     * until the capability is on; a wave is only required once a branch is
     * admitted. Reporting a later blocker while an earlier one also holds would
     * send an operator to fix the wrong thing.
     *
     * @param  list<string>  $admitted
     * @param  list<string>  $unapproved
     */
    private function blocker(
        bool $capability,
        bool $admissionEnforced,
        array $admitted,
        string $approvalReference,
        array $unapproved,
        bool $operationsEnforced,
        ?string $declaredWave,
        ?LegacyRmeMigrationWave $wave,
        bool $waveUnreadable,
        bool $bindingMatches,
    ): ?string {
        if (! $capability) {
            return self::BLOCKER_CAPABILITY_OFF;
        }

        // Enforcement off is a local/CI posture. The readiness gate FAILs when a
        // real deployment runs that way, so this reports the gates as open
        // rather than inventing a blocker that does not exist there.
        if ($admissionEnforced) {
            if ($admitted === []) {
                return self::BLOCKER_NO_BRANCH_ADMITTED;
            }

            if ($approvalReference === '') {
                return self::BLOCKER_APPROVAL_MISSING;
            }

            if ($unapproved !== []) {
                return self::BLOCKER_APPROVAL_INCOMPLETE;
            }
        }

        if (! $operationsEnforced) {
            return self::BLOCKER_NONE;
        }

        if ($waveUnreadable) {
            return self::BLOCKER_WAVE_UNREADABLE;
        }

        if ($declaredWave === null) {
            return self::BLOCKER_WAVE_NOT_DECLARED;
        }

        if ($wave === null) {
            return self::BLOCKER_WAVE_NOT_REGISTERED;
        }

        if ($wave->status !== LegacyRmeWaveStatus::INGESTABLE) {
            return self::BLOCKER_WAVE_NOT_ACTIVE;
        }

        if (! $bindingMatches) {
            return self::BLOCKER_WAVE_BINDING_MISMATCH;
        }

        return self::BLOCKER_NONE;
    }

    /**
     * Per-branch admission verdict and wave enrolment.
     *
     * The admitted flag comes from the REAL gate
     * ({@see LegacyRmeBranchAdmissionService::decideForBranchCode()}), so this
     * report can never disagree with the decision the upload path takes. The
     * enrolment flag is read from the wave's own branch rows in ONE query, so
     * adding a branch cannot turn a status page into an N+1.
     *
     * @param  list<string>  $branchCodes
     * @return list<array<string, mixed>>
     */
    private function branchStates(array $branchCodes, ?LegacyRmeMigrationWave $wave): array
    {
        $codes = array_values(array_unique(array_filter(
            array_map(static fn (string $code): string => strtoupper(trim($code)), $branchCodes),
            static fn (string $code): bool => $code !== '',
        )));

        if ($codes === []) {
            return [];
        }

        $enrolled = [];

        if ($wave !== null) {
            try {
                foreach ($wave->branches()->get(['branch_code', 'status']) as $branch) {
                    $enrolled[strtoupper(trim((string) $branch->branch_code))] = (string) $branch->status;
                }
            } catch (Throwable) {
                // Unreadable enrolment reports as "not enrolled" for every
                // branch rather than as enrolled-by-default.
                $enrolled = [];
            }
        }

        $rows = [];

        foreach ($codes as $code) {
            $decision = $this->admission->decideForBranchCode($code);
            $branchStatus = $enrolled[$code] ?? null;

            $rows[] = [
                'branch_code' => $code,
                // The gate's OWN verdict flag, not a re-derivation of it.
                'admitted' => $decision->admitted,
                'admission_code' => $decision->code,
                'wave_branch_status' => $branchStatus,
                'wave_branch_ingesting' => $branchStatus === LegacyRmeWaveBranchStatus::INGESTABLE,
            ];
        }

        return $rows;
    }
}
