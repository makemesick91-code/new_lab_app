<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeRolloutCheck;
use App\Modules\LegacyRme\Support\LegacyRmeSodStaffing;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Modules\LegacyRme\Support\SeparatePublisherGuard;
use App\Services\Foundation\FoundationMonitoringStatusService;
use App\Support\Clinical\ClinicalClock;
use Throwable;

/**
 * LEGACY-RME-STEADY-STATE-OPS-1 — the one question an operator has to answer
 * before touching a routine migration batch.
 *
 * WHY THIS EXISTS. Every control needed to run a batch safely already shipped.
 * What did not exist was a single answer to "may I open a batch right now?".
 * Answering it meant running `legacy-rme:rollout-readiness`,
 * `legacy-rme:migration-status`, `legacy-rme:wave-status` and a backup check,
 * then correlating four reports by eye against a 509-line runbook. That is a
 * reasonable way to run a sprint and an unreasonable way to run routine work:
 * the failure mode is not a missing gate, it is an operator who read three
 * green reports and missed the fourth.
 *
 * WHAT IT IS. A READ-ONLY AGGREGATOR. It composes the existing services and
 * adds the three dimensions none of them covered:
 *
 *   1. BACKUP FRESHNESS  — no prior legacy gate asked whether a restore point
 *      exists. Reused from the ENT-12 / MON-1 signal, never re-probed here.
 *   2. RESTING STATE      — runbook §0 was prose; prose cannot be asserted.
 *   3. BRANCH MATRIX      — per-branch READY/NOT_READY with named blockers.
 *
 * WHAT IT IS NOT. It computes no quota, admits no branch, resolves no patient,
 * writes nothing and calls no mutating service. It cannot turn a refusal into
 * an acceptance: it only reads what the authoritative services already decided
 * and reports where they disagree with a safe posture. Deleting this class
 * would not admit a single document that is not admissible today.
 *
 * FAIL CLOSED. Every check is individually guarded. A check that throws becomes
 * UNKNOWN, and UNKNOWN blocks exactly like FAIL — "we could not tell" has never
 * been a basis for opening a clinical migration.
 *
 * PII-FREE BY CONTRACT. Counts, branch codes, statuses, timings and config
 * values only. Never a patient name, a Nomor RM, a KTP/NIK, a filename or a
 * document path. The check DTO carries the same contract.
 */
class LegacyRmeSteadyStateOpsService
{
    public const STATUS_GO = 'GO';

    public const STATUS_WATCH = 'WATCH';

    public const STATUS_FAIL = 'FAIL';

    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const DECISION_GO = 'GO';

    public const DECISION_WATCH = 'WATCH';

    public const DECISION_NO_GO = 'NO_GO';

    public function __construct(
        private readonly LegacyRmeFeatureGuard $feature,
        private readonly LegacyRmeBranchAdmissionService $admission,
        private readonly LegacyRmeWaveBindingService $binding,
        private readonly LegacyRmeMigrationReconciliationService $reconciliation,
        private readonly LegacyRmeMigrationQuotaService $quota,
        private readonly LegacyRmeIngestionCapacityService $capacity,
        private readonly LegacyRmeOperationsGateService $gate,
        private readonly LegacyRmeMigrationOperationsService $operations,
        private readonly LegacyRmeRolloutReadinessService $rollout,
        private readonly SeparatePublisherGuard $separation,
        private readonly LegacyRmeSodStaffing $sodStaffing,
        private readonly FoundationMonitoringStatusService $monitoring,
        private readonly BranchService $branches,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * The consolidated steady-state readiness report.
     *
     * @param  array{include_monitoring?: bool, branch?: string|null}  $options
     * @return array<string, mixed>
     */
    public function readiness(array $options = []): array
    {
        // Collected BY DEFAULT. Backup freshness is a core gate, and a
        // pre-flight whose default answer is "I did not check the one thing that
        // cannot be walked back" is a pre-flight nobody trusts. Callers may skip
        // it explicitly for a fast, openly degraded check.
        $includeMonitoring = (bool) ($options['include_monitoring'] ?? true);
        $branchFilter = $this->normalizeBranchCode($options['branch'] ?? null);

        // Collected once and shared by every check that needs it. Re-reading the
        // monitoring signals per check would be both slower and free to
        // disagree with itself between checks.
        $monitoringReport = $this->monitoringReport($includeMonitoring);

        $checks = [
            $this->checkDeploymentReadiness(),
            $this->checkOperationsLayerEnforced(),
            $this->checkSeparationOfDuties(),
            $this->checkClinicalCalendar(),
            $this->checkAdmissionApproval(),
            $this->checkBatchBinding(),
            $this->checkBatchWindow(),
            $this->checkBatchSizePolicy(),
            $this->checkReconciliationBalance(),
            $this->checkQueueHeadroom(),
            $this->checkBackupFreshness($monitoringReport),
        ];

        $checks = array_map(static fn (LegacyRmeRolloutCheck $c) => $c->toArray(), $checks);
        $checks = array_map(fn (array $c) => $c + ['severity' => $this->severityFor($c)], $checks);

        $decision = $this->decide($checks);
        $stopTheLine = $this->stopTheLine($checks);

        return [
            'sprint' => (string) config('legacy_rme_steady_state.sprint'),
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) app()->environment(),
            'clinical_today' => $this->clinicalToday(),

            'decision' => $decision,
            // The operator-facing headline. Deliberately narrower than the
            // decision: WATCH is not permission to open a batch that mutates
            // clinical staging state, so only GO qualifies.
            'ready_for_routine_batch' => $decision === self::DECISION_GO,

            'resting_state' => $this->restingState(),
            'multi_branch' => (array) config('legacy_rme_steady_state.multi_branch', []),
            'batch_sizing' => (array) config('legacy_rme_steady_state.batch_sizing', []),

            'summary' => $this->summarise($checks),
            'severity_summary' => $this->severitySummary($checks),
            'stop_the_line' => $stopTheLine,
            'blockers' => $this->blockers($checks),
            'checks' => $checks,

            'branch_matrix' => $this->branchMatrix($branchFilter),
            'branch_filter' => $branchFilter,

            // Composed decisions, not recomputed probes. The full sub-reports
            // stay behind their own commands so this payload remains readable.
            'deployment_readiness' => $this->deploymentReadinessSummary(),
            'monitoring' => $monitoringReport === null ? null : [
                'decision' => $monitoringReport['decision'] ?? self::STATUS_UNKNOWN,
                'summary' => $monitoringReport['summary'] ?? [],
                'included' => true,
            ],
        ];
    }

    /**
     * Pure aggregation, unit-testable without a database.
     *
     * FAIL and UNKNOWN both block. WATCH degrades but does not block by itself —
     * the same precedence ROLL-2's readiness gate established, kept identical so
     * two legacy readiness reports can never disagree about what a status means.
     *
     * @param  array<int, array<string, mixed>>  $checks
     */
    public function decide(array $checks): string
    {
        foreach ($checks as $check) {
            if (in_array($check['status'] ?? self::STATUS_UNKNOWN, [self::STATUS_FAIL, self::STATUS_UNKNOWN], true)) {
                return self::DECISION_NO_GO;
            }
        }

        foreach ($checks as $check) {
            if (($check['status'] ?? null) === self::STATUS_WATCH) {
                return self::DECISION_WATCH;
            }
        }

        return self::DECISION_GO;
    }

    // -----------------------------------------------------------------
    // Checks
    // -----------------------------------------------------------------

    /**
     * The ROLL-2 deployment gate, composed rather than re-implemented.
     *
     * A steady-state batch cannot be safer than the deployment it runs on, so
     * its decision is inherited verbatim: NO_GO there is FAIL here.
     */
    private function checkDeploymentReadiness(): LegacyRmeRolloutCheck
    {
        return $this->guarded('deployment_readiness', function (): LegacyRmeRolloutCheck {
            $report = $this->rollout->report();
            $decision = (string) ($report['decision'] ?? self::STATUS_UNKNOWN);
            $context = ['rollout_decision' => $decision, 'rollout_summary' => $report['summary'] ?? []];

            if ($decision === LegacyRmeRolloutReadinessService::DECISION_NO_GO) {
                return LegacyRmeRolloutCheck::fail(
                    'deployment_readiness',
                    'The deployment readiness gate is NO_GO, so no batch may be opened on it.',
                    $context,
                    'Run `php artisan legacy-rme:rollout-readiness` and clear every FAIL and UNKNOWN check first.',
                );
            }

            if ($decision === LegacyRmeRolloutReadinessService::DECISION_WATCH) {
                return LegacyRmeRolloutCheck::watch(
                    'deployment_readiness',
                    'The deployment readiness gate reports WATCH.',
                    $context,
                    'Review the WATCH checks in `php artisan legacy-rme:rollout-readiness` before opening a batch.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'deployment_readiness',
                'The deployment readiness gate is GO.',
                $context,
            );
        });
    }

    /**
     * The ROLL-4 operations layer must be enforced, and so must ROLL-3
     * admission. A batch run with either switched off is not a routine batch —
     * it is the pre-ROLL-3 path, which had no server-side branch confinement.
     */
    private function checkOperationsLayerEnforced(): LegacyRmeRolloutCheck
    {
        return $this->guarded('operations_layer_enforced', function (): LegacyRmeRolloutCheck {
            $operations = $this->gate->enforced();
            $admission = $this->admission->enforced();
            $context = ['operations_enforced' => $operations, 'admission_enforced' => $admission];

            if (! $operations || ! $admission) {
                return LegacyRmeRolloutCheck::fail(
                    'operations_layer_enforced',
                    'A migration control layer is not enforced on this deployment.',
                    $context,
                    'Enable both the ROLL-3 admission gate and the ROLL-4 operations layer. They exist so a local test can exercise the pre-gate path, never so production can opt out.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'operations_layer_enforced',
                'The admission gate and the operations layer are both enforced.',
                $context,
            );
        });
    }

    /**
     * Separation of duties.
     *
     * READ THROUGH THE GUARD, NEVER THROUGH ITS CONFIG KEY. SOD-1 gives the
     * separate-publisher switch exactly one home in the application, and an
     * architecture test enforces that: a second reader would be deciding for
     * itself whether the rule applies, which is how two surfaces drift apart.
     * This service therefore ASKS `SeparatePublisherGuard::enabled()` and does
     * not interpret anything.
     *
     * The separate-publisher rule is a production INVARIANT: with it off, one
     * account can upload a document and certify its own upload. That is a
     * stop-the-line condition, not a warning.
     *
     * The separate-APPROVER switch is a documented, deliberately accepted risk —
     * it ships OFF because only one staffed governance account exists, and the
     * wave still cannot admit a branch the owner did not already approve at
     * deploy time. It is therefore reported as WATCH, never as a refusal.
     * Flipping it here would break the very operations this sprint exists to
     * make routine.
     */
    private function checkSeparationOfDuties(): LegacyRmeRolloutCheck
    {
        return $this->guarded('separation_of_duties', function (): LegacyRmeRolloutCheck {
            $separatePublisher = $this->separation->enabled();
            $separateApprover = (bool) config('legacy_rme_operations.require_separate_approver');

            // FIX-LEGACY-RME-ROUTINE-OPS-1 — a switch being on is not the same
            // as the duty being staffable. Counts and booleans only; the
            // report says an approver EXISTS, never who it is.
            $staffing = $this->sodStaffing->evaluate();

            $context = [
                'separate_publisher_enforced' => $separatePublisher,
                'separate_approver_enforced' => $separateApprover,
                // Account separation is enforced by the server. Two distinct
                // HUMANS behind those accounts is a governance control the
                // application cannot observe, and must never be reported as if
                // it had been verified here.
                'human_separation_verifiable_by_application' => false,
                // Two distinct ACCOUNTS able to perform each half IS
                // observable, and is the precondition the human control rests
                // on. Reported separately so the two claims are never confused.
                'account_separation_verifiable_by_application' => true,
            ] + $staffing;

            if (! $separatePublisher) {
                return LegacyRmeRolloutCheck::fail(
                    'separation_of_duties',
                    'The separate-publisher invariant is disabled, so an account could certify its own upload.',
                    $context + ['code' => 'SEPARATE_PUBLISHER_DISABLED'],
                    'Restore the separate-publisher requirement before any batch is opened. It is a production invariant, not a preference.',
                );
            }

            // An enforced rule that cannot be satisfied is worse than one
            // turned off on purpose: the deployment reads as protected while
            // the duty is unperformable. Refused, not warned about — a batch
            // opened here would stall at its first publish or approval.
            // The guarded chain is file → review → publish, and review is a
            // mandatory transition, so a deployment that can file and publish
            // but not review still cannot complete a document.
            if (! $staffing['document_chain_staffed']) {
                return LegacyRmeRolloutCheck::fail(
                    'separation_of_duties',
                    'Separate publisher is enforced but the file-review-publish chain cannot be staffed by distinct accounts.',
                    $context + ['code' => 'SOD_STAFFING_UNAVAILABLE'],
                    'Provision the missing half of the maker-checker pair: one account able to create imports, and a different account able to BOTH review and publish them. Reconcile roles with `db:seed --class=RoleSeeder --force` before granting anything by hand.',
                );
            }

            if (! $separateApprover) {
                return LegacyRmeRolloutCheck::watch(
                    'separation_of_duties',
                    'Separate publisher is enforced; separate approver remains off by documented decision.',
                    $context,
                    'Turn on the separate-approver requirement once a second staffed governance account exists. Confirm maker and checker are two different people — the application cannot verify that.',
                );
            }

            if (! $staffing['distinct_creator_approver_pair_available']) {
                return LegacyRmeRolloutCheck::fail(
                    'separation_of_duties',
                    'Separate approver is enforced but no second account can approve a batch its creator did not.',
                    $context + ['code' => 'SOD_STAFFING_UNAVAILABLE'],
                    'Provision a governance approver distinct from the account that registers batches. A single all-powerful account satisfies neither half. Reconcile roles with `db:seed --class=RoleSeeder --force` before granting anything by hand.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'separation_of_duties',
                'Both separation-of-duties requirements are enforced, and each is staffed by distinct accounts.',
                $context,
            );
        });
    }

    /**
     * Clinical date decisions must resolve on the clinic's own calendar. A wrong
     * timezone silently moves every date boundary — the historical-date rule,
     * the native-RME cutoff and the daily quota window all shift together.
     */
    private function checkClinicalCalendar(): LegacyRmeRolloutCheck
    {
        return $this->guarded('clinical_calendar', function (): LegacyRmeRolloutCheck {
            $posture = $this->clock->inspect();
            $context = [
                'configured' => $posture['configured'] ?? null,
                'effective' => $posture['effective'] ?? null,
                'expected' => $posture['expected'] ?? null,
                'clinical_today' => $this->clock->todayString(),
            ];

            if (! ($posture['valid'] ?? false)) {
                return LegacyRmeRolloutCheck::fail(
                    'clinical_calendar',
                    'The clinical calendar timezone is not valid, so no date boundary can be trusted.',
                    $context + ['code' => 'CLINICAL_TIMEZONE_WRONG'],
                    'Set the clinical timezone to a valid IANA identifier. Date decisions fail closed and never fall back to UTC.',
                );
            }

            if (! ($posture['canonical'] ?? false)) {
                return LegacyRmeRolloutCheck::watch(
                    'clinical_calendar',
                    'The clinical calendar is valid but is not the canonical value for this product.',
                    $context,
                    'Confirm this deployment is deliberately running on a non-canonical clinical timezone.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'clinical_calendar',
                'Clinical dates resolve on the canonical clinic calendar.',
                $context,
            );
        });
    }

    /**
     * Every admitted branch must be covered by the owner's approval, and an
     * approval reference must exist. An admitted-but-unapproved branch is open
     * for migration with nobody accountable for having opened it.
     */
    private function checkAdmissionApproval(): LegacyRmeRolloutCheck
    {
        return $this->guarded('admission_approval', function (): LegacyRmeRolloutCheck {
            $admitted = $this->admission->admittedBranchCodes();
            $unapproved = $this->admission->unapprovedAdmittedBranchCodes();
            $reference = $this->admission->approvalReference();

            $context = [
                'admitted_branch_codes' => $admitted,
                'approved_branch_codes' => $this->admission->approvedBranchCodes(),
                'unapproved_admitted' => $unapproved,
                // Presence only. The reference itself is a governance ticket id
                // and is not echoed into an operational report.
                'approval_reference_present' => $reference !== '',
            ];

            if ($unapproved !== []) {
                return LegacyRmeRolloutCheck::fail(
                    'admission_approval',
                    'A branch is admitted for migration that the recorded approval does not cover.',
                    $context + ['code' => 'ADMITTED_BRANCH_NOT_APPROVED'],
                    'Remove the unapproved branch code from admission, or record an approval that covers it. Admission is never widened to match what is already open.',
                );
            }

            if ($admitted === []) {
                return LegacyRmeRolloutCheck::go(
                    'admission_approval',
                    'No branch is admitted — the safe resting state for admission.',
                    $context,
                );
            }

            if ($reference === '') {
                return LegacyRmeRolloutCheck::fail(
                    'admission_approval',
                    'Branches are admitted but no approval reference is recorded.',
                    $context,
                    'Record the owner approval reference alongside the admitted branch codes before migrating.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'admission_approval',
                'Every admitted branch is covered by a recorded approval.',
                $context,
            );
        });
    }

    /**
     * The declared batch and the batch record must be the same thing.
     *
     * A declared wave code with no matching ACTIVE record, or a record whose
     * approved branch set disagrees with what is declared, means the control
     * layer and the operator are describing different batches.
     */
    private function checkBatchBinding(): LegacyRmeRolloutCheck
    {
        return $this->guarded('batch_binding', function (): LegacyRmeRolloutCheck {
            $declared = $this->binding->declaredWaveCode();
            $wave = $this->binding->resolveWave();
            $admitted = $this->admission->admittedBranchCodes();

            $context = [
                'declared_batch_code' => $declared,
                'batch_record_found' => $wave !== null,
                'batch_status' => $wave?->status,
                'admitted_branch_codes' => $admitted,
            ];

            // Resting state: nothing declared and nothing admitted is correct,
            // not a finding.
            if ($declared === null && $admitted === []) {
                return LegacyRmeRolloutCheck::go(
                    'batch_binding',
                    'No batch is declared and no branch is admitted — safe resting state.',
                    $context,
                );
            }

            if ($declared === null) {
                return LegacyRmeRolloutCheck::fail(
                    'batch_binding',
                    'Branches are admitted but no batch code is declared.',
                    $context + ['code' => 'BATCH_BINDING_MISMATCH'],
                    'Declare the batch code that governs the admitted branches, or withdraw admission.',
                );
            }

            // A retired governance identity has no approval record and never
            // will: ROLL-4-WAVE-3 was formally SKIPPED / NOT REQUIRED. Work
            // declared under it is unaccountable by construction.
            $retired = array_map(
                static fn ($code): string => strtoupper(trim((string) $code)),
                (array) config('legacy_rme_steady_state.routine_batch.retired_codes', []),
            );

            if (in_array(strtoupper($declared), $retired, true)) {
                return LegacyRmeRolloutCheck::fail(
                    'batch_binding',
                    'The declared batch code is a retired governance identity.',
                    $context + ['code' => 'BATCH_BINDING_MISMATCH', 'retired_codes' => $retired],
                    'Use a fresh routine batch code with its own approval. Retired codes were closed deliberately and are never reopened.',
                );
            }

            if ($wave === null) {
                return LegacyRmeRolloutCheck::fail(
                    'batch_binding',
                    'The declared batch code has no matching batch record.',
                    $context + ['code' => 'BATCH_BINDING_MISMATCH'],
                    'Register the batch through `legacy-rme:wave-admin register`, or correct the declared code.',
                );
            }

            if (! $this->binding->bindingMatches($wave)) {
                return LegacyRmeRolloutCheck::fail(
                    'batch_binding',
                    'The declared batch and its record disagree about scope.',
                    $context + ['code' => 'BATCH_BINDING_MISMATCH'],
                    'Reconcile the declared approval reference and branch codes with the batch record before migrating.',
                );
            }

            if ($wave->status !== LegacyRmeWaveStatus::ACTIVE) {
                // Not a defect. A batch legitimately sits in DRAFT, APPROVED,
                // PAUSED or DRAINING; it simply is not open for new work, and
                // an operator about to upload needs to know that plainly.
                return LegacyRmeRolloutCheck::watch(
                    'batch_binding',
                    sprintf('The declared batch exists but is %s, so it is not accepting new documents.', (string) $wave->status),
                    $context,
                    'Activate or resume the batch when work should proceed. A non-ACTIVE batch refuses new uploads by design.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'batch_binding',
                'The declared batch is ACTIVE and its record matches what is declared.',
                $context,
            );
        });
    }

    /**
     * A batch must be time-bounded, and an approval must not stay usable
     * forever by accident. The wave record already carries a planned window;
     * nothing previously checked whether today was still inside it.
     */
    private function checkBatchWindow(): LegacyRmeRolloutCheck
    {
        return $this->guarded('batch_window', function (): LegacyRmeRolloutCheck {
            $wave = $this->binding->resolveWave();

            if ($wave === null) {
                return LegacyRmeRolloutCheck::go(
                    'batch_window',
                    'No batch is bound, so no batch window applies.',
                    ['batch_record_found' => false],
                );
            }

            $today = $this->clock->today();
            $start = $wave->planned_start_date;
            $end = $wave->planned_end_date;

            $context = [
                'declared_batch_code' => $wave->code,
                'planned_start_date' => $start?->toDateString(),
                'planned_end_date' => $end?->toDateString(),
                'clinical_today' => $today->toDateString(),
            ];

            if ($end === null) {
                return LegacyRmeRolloutCheck::watch(
                    'batch_window',
                    'The batch declares no planned end date, so its approval has no expiry.',
                    $context,
                    'Record a planned end date. A routine batch is time-bounded; an approval that never expires is one nobody revisits.',
                );
            }

            // Compared on the clinic's calendar, and inclusive of the final
            // planned day — a batch approved "through the 20th" is open on the
            // 20th.
            if ($today->toDateString() > $end->toDateString()) {
                return LegacyRmeRolloutCheck::watch(
                    'batch_window',
                    'The batch is past its planned end date.',
                    $context + ['expired' => true],
                    'Close the batch out, or record a fresh approval extending it. Do not keep migrating against a lapsed window.',
                );
            }

            if ($start !== null && $today->toDateString() < $start->toDateString()) {
                return LegacyRmeRolloutCheck::watch(
                    'batch_window',
                    'The batch has not reached its planned start date.',
                    $context,
                    'Wait for the planned start date, or correct it if the batch is genuinely starting early.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'batch_window',
                'Today is inside the batch planned window.',
                $context,
            );
        });
    }

    /**
     * Routine batches stay inside the routine sizing envelope. Above it, a batch
     * is still permitted by the server-side rail but is no longer ROUTINE, and
     * needs an approval that names who accepted the larger blast radius.
     */
    private function checkBatchSizePolicy(): LegacyRmeRolloutCheck
    {
        return $this->guarded('batch_size_policy', function (): LegacyRmeRolloutCheck {
            $wave = $this->binding->resolveWave();
            $routineMax = (int) config('legacy_rme_steady_state.batch_sizing.max_daily', 100);
            $declarableMax = (int) config('legacy_rme_operations.quota.max_declarable_daily', 500);

            if ($wave === null) {
                return LegacyRmeRolloutCheck::go(
                    'batch_size_policy',
                    'No batch is bound, so no sizing policy applies.',
                    ['routine_max_daily' => $routineMax],
                );
            }

            $waveLimit = $this->quota->waveDailyLimit($wave);
            $context = [
                'declared_batch_code' => $wave->code,
                'batch_daily_quota' => $waveLimit,
                'routine_default_daily' => (int) config('legacy_rme_steady_state.batch_sizing.default_daily', 25),
                'routine_max_daily' => $routineMax,
                'absolute_declarable_max' => $declarableMax,
            ];

            // NULL is "no ceiling declared", which is materially different from
            // a large ceiling: an unbounded batch is not a routine batch at all.
            if ($waveLimit === null) {
                return LegacyRmeRolloutCheck::watch(
                    'batch_size_policy',
                    'The batch declares no daily quota, so it is not quota-bounded.',
                    $context,
                    'Declare a daily quota. A routine batch is bounded by an exact ceiling, not by operator restraint.',
                );
            }

            if ($waveLimit > $routineMax) {
                return LegacyRmeRolloutCheck::watch(
                    'batch_size_policy',
                    'The batch quota exceeds the routine envelope, so this is not a routine batch.',
                    $context + ['exceeds_routine_envelope' => true],
                    sprintf('Reduce the daily quota to %d or below, or record elevated approval naming who accepted the larger blast radius.', $routineMax),
                );
            }

            return LegacyRmeRolloutCheck::go(
                'batch_size_policy',
                'The batch quota is inside the routine sizing envelope.',
                $context,
            );
        });
    }

    /**
     * The books must balance. `unexplained` and `quota_drift` are the two
     * independent integrity checks ROLL-4 defined, and either being non-zero
     * means the migration's own evidence cannot be trusted.
     */
    private function checkReconciliationBalance(): LegacyRmeRolloutCheck
    {
        return $this->guarded('reconciliation_balance', function (): LegacyRmeRolloutCheck {
            $wave = $this->binding->resolveWave();

            if ($wave === null) {
                return LegacyRmeRolloutCheck::go(
                    'reconciliation_balance',
                    'No batch is bound, so there is no ledger to reconcile.',
                    ['batch_record_found' => false],
                );
            }

            $recon = $this->reconciliation->forWave($wave);
            $context = [
                'declared_batch_code' => $wave->code,
                'accepted' => $recon->accepted,
                'published' => $recon->published,
                'cancelled' => $recon->cancelled,
                'failed_unresolved' => $recon->failedUnresolved,
                'in_flight' => $recon->inFlight,
                'unexplained' => $recon->unexplained,
                'quota_drift' => $recon->quotaDrift,
                'stale_processing' => $recon->staleProcessing,
                'balanced' => $recon->balanced(),
            ];

            $codes = [];
            if ($recon->unexplained !== 0) {
                $codes[] = 'UNEXPLAINED_RECORDS';
            }
            if ($recon->quotaDrift !== 0) {
                $codes[] = 'QUOTA_LEDGER_DRIFT';
            }

            if ($codes !== []) {
                return LegacyRmeRolloutCheck::fail(
                    'reconciliation_balance',
                    'The batch ledger does not balance.',
                    $context + ['code' => $codes[0], 'codes' => $codes],
                    'Stop the line. Do not open or continue a batch on a ledger that cannot account for every document. Investigate through `legacy-rme:migration-status` before any further upload.',
                );
            }

            // In-flight work is normal mid-batch and blocks only CLOSURE, which
            // the completion invariants already enforce. Surfaced so an operator
            // does not mistake a busy batch for a finished one.
            if ($recon->inFlight > 0 || $recon->failedUnresolved > 0 || $recon->staleProcessing > 0) {
                return LegacyRmeRolloutCheck::watch(
                    'reconciliation_balance',
                    'The batch ledger balances but work is still outstanding.',
                    $context,
                    'Resolve in-flight, failed and stale documents before closing the batch. They do not block continuing work.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'reconciliation_balance',
                'The batch ledger balances and nothing is outstanding.',
                $context,
            );
        });
    }

    /**
     * The render pipeline must have headroom. Saturation is reported, never
     * relieved: this check cannot cancel an in-flight job to make room.
     */
    private function checkQueueHeadroom(): LegacyRmeRolloutCheck
    {
        return $this->guarded('queue_headroom', function (): LegacyRmeRolloutCheck {
            $capacity = $this->capacity->evaluate();
            $context = [
                'capacity_enforced' => $this->capacity->enforced(),
                'available' => $capacity->available,
                'code' => $capacity->code,
                'measurements' => $capacity->measurements,
            ];

            if (! $capacity->available) {
                return LegacyRmeRolloutCheck::watch(
                    'queue_headroom',
                    'The ingestion pipeline is saturated, so new uploads are being refused.',
                    $context,
                    'Let the worker drain, or investigate a stalled worker. Backpressure is protecting the pipeline; it is not a defect to override.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'queue_headroom',
                'The ingestion pipeline has headroom for new documents.',
                $context,
            );
        });
    }

    /**
     * A verified, recent restore point must exist before a batch that mutates
     * clinical staging state is opened.
     *
     * Reuses the ENT-12 / MON-1 `deploy_backup` signal. Deliberately does NOT
     * re-probe the backup directory: a second implementation of "is the backup
     * fresh?" would be free to disagree with the first, and the operator would
     * have no way to tell which one was right.
     *
     * @param  array<string, mixed>|null  $monitoringReport
     */
    private function checkBackupFreshness(?array $monitoringReport): LegacyRmeRolloutCheck
    {
        return $this->guarded('backup_freshness', function () use ($monitoringReport): LegacyRmeRolloutCheck {
            $required = (bool) config('legacy_rme_steady_state.backup.required_before_batch', true);
            $maxAgeHours = (int) config('legacy_rme_steady_state.backup.max_age_hours', 24);
            $context = ['required_before_batch' => $required, 'max_age_hours' => $maxAgeHours];

            if (! $required) {
                return LegacyRmeRolloutCheck::watch(
                    'backup_freshness',
                    'The pre-batch backup requirement is switched off on this deployment.',
                    $context,
                    'Restore the pre-batch backup requirement outside local and CI environments.',
                );
            }

            // NOT ESTABLISHED is deliberately a different finding from STALE.
            // Both block, because neither is a restore point we can stand
            // behind — but only an actually missing or stale backup is a
            // stop-the-line condition. Reporting "I did not look" as though a
            // control had failed would train operators to ignore the loudest
            // signal the report has.
            if ($monitoringReport === null) {
                return LegacyRmeRolloutCheck::unknown(
                    'backup_freshness',
                    'Backup freshness was not established because monitoring signals were skipped.',
                    $context + ['code' => 'BACKUP_NOT_ESTABLISHED'],
                    'Re-run without --skip-monitoring, or verify the latest backup with `php artisan foundation:backup-verify`.',
                );
            }

            $signal = $this->monitoringSignal($monitoringReport, 'deploy_backup');

            if ($signal === null) {
                return LegacyRmeRolloutCheck::unknown(
                    'backup_freshness',
                    'The backup signal was not present in the monitoring report.',
                    $context + ['code' => 'BACKUP_NOT_ESTABLISHED'],
                    'Verify the latest backup with `php artisan foundation:backup-verify`.',
                );
            }

            $status = (string) ($signal['status'] ?? self::STATUS_UNKNOWN);
            $context += [
                'backup_signal_status' => $status,
                'backup_signal_summary' => (string) ($signal['summary'] ?? ''),
            ];

            if ($status === self::STATUS_GO) {
                return LegacyRmeRolloutCheck::go(
                    'backup_freshness',
                    'A verified recent backup exists, so the batch has a restore point.',
                    $context,
                );
            }

            // WATCH, FAIL and UNKNOWN all mean the same thing for this purpose:
            // there is no restore point we can stand behind. A batch that
            // mutates clinical staging state without one is the failure this
            // programme cannot walk back, so it refuses rather than warns.
            return LegacyRmeRolloutCheck::fail(
                'backup_freshness',
                'No verified recent backup could be confirmed for this deployment.',
                $context + ['code' => 'BACKUP_MISSING_OR_STALE'],
                'Take and verify a fresh backup before opening the batch. Run the canonical backup script, then `php artisan foundation:backup-verify`.',
            );
        });
    }

    // -----------------------------------------------------------------
    // Derived views
    // -----------------------------------------------------------------

    /**
     * Runbook §0, made assertable.
     *
     * @return array<string, mixed>
     */
    private function restingState(): array
    {
        try {
            $expected = (array) config('legacy_rme_steady_state.resting_state', []);
            $wave = $this->binding->resolveWave();
            $recon = $wave === null ? null : $this->reconciliation->forWave($wave);

            $observed = [
                'capability_off' => ! $this->feature->migrationEnabled(),
                'admission_empty' => $this->admission->admittedBranchCodes() === [],
                'no_active_batch' => $wave === null || $wave->status !== LegacyRmeWaveStatus::ACTIVE,
                'zero_in_flight' => ($recon?->inFlight ?? 0) === 0,
                'zero_unexplained' => ($recon?->unexplained ?? 0) === 0,
                'zero_quota_drift' => ($recon?->quotaDrift ?? 0) === 0,
            ];

            $deviations = [];
            foreach ($expected as $key => $mustHold) {
                if ($mustHold && ($observed[$key] ?? false) !== true) {
                    $deviations[] = $key;
                }
            }

            return [
                'at_rest' => $deviations === [],
                'observed' => $observed,
                'deviations' => $deviations,
                // A batch being open is NOT a defect — it is the whole point of
                // opening one. This distinguishes "mid-batch" from "should be
                // at rest but is not".
                'interpretation' => $deviations === []
                    ? 'AT_REST'
                    : ($observed['no_active_batch'] === false ? 'BATCH_IN_PROGRESS' : 'NOT_AT_REST'),
            ];
        } catch (Throwable $e) {
            return ['at_rest' => null, 'observed' => [], 'deviations' => [], 'interpretation' => 'UNKNOWN', 'error' => class_basename($e)];
        }
    }

    /**
     * Per-branch operational readiness across every RME-enabled branch.
     *
     * Branch codes are operational labels, never patient data. MAIN is excluded
     * because it is an administrative branch that can never hold RME history.
     *
     * @return list<array<string, mixed>>
     */
    private function branchMatrix(?string $branchFilter): array
    {
        try {
            $forbidden = array_map(
                'strtoupper',
                (array) config('legacy_rme_rollout.admission.forbidden_branch_codes', ['MAIN'])
            );
            $admitted = $this->admission->admittedBranchCodes();
            $approved = $this->admission->approvedBranchCodes();
            $wave = $this->binding->resolveWave();
            $progress = [];

            if ($wave !== null) {
                foreach ($this->operations->branchProgress($wave) as $row) {
                    $progress[(string) $row['branch_code']] = $row;
                }
            }

            $rows = [];

            foreach ($this->branches->listRmeEnabled() as $branch) {
                $code = strtoupper(trim((string) $branch->code));

                if ($code === '' || in_array($code, $forbidden, true)) {
                    continue;
                }
                if ($branchFilter !== null && $code !== $branchFilter) {
                    continue;
                }

                $isAdmitted = in_array($code, $admitted, true);
                $isApproved = in_array($code, $approved, true);
                $enrolled = $progress[$code] ?? null;

                $blockers = [];
                if ($isAdmitted && ! $isApproved) {
                    $blockers[] = 'ADMITTED_BRANCH_NOT_APPROVED';
                }
                if ($isAdmitted && $enrolled === null) {
                    $blockers[] = 'NOT_ENROLLED_IN_BATCH';
                }
                if (($enrolled['unexplained'] ?? 0) !== 0) {
                    $blockers[] = 'UNEXPLAINED_RECORDS';
                }
                if (($enrolled['quota_drift'] ?? 0) !== 0) {
                    $blockers[] = 'QUOTA_LEDGER_DRIFT';
                }

                $rows[] = [
                    'branch_code' => $code,
                    'branch_active' => (bool) $branch->is_active,
                    'rme_enabled' => true,
                    'approved' => $isApproved,
                    'admitted' => $isAdmitted,
                    'enrolled_in_batch' => $enrolled !== null,
                    'batch_branch_status' => $enrolled['status'] ?? null,
                    'ingesting' => (bool) ($enrolled['ingesting'] ?? false),
                    'daily_quota' => $enrolled['daily_quota'] ?? null,
                    'assigned_operators' => $enrolled['assigned_operators'] ?? null,
                    'accepted' => $enrolled['accepted'] ?? null,
                    'published' => $enrolled['published'] ?? null,
                    'in_flight' => $enrolled['in_flight'] ?? null,
                    'blockers' => $blockers,
                    // "Ready" here means READY TO MIGRATE NOW: approved, open,
                    // enrolled and clean. A branch that is deliberately closed
                    // is NOT_READY for migration and that is the correct answer,
                    // not a fault.
                    'readiness' => ($isApproved && $isAdmitted && $enrolled !== null && $blockers === [])
                        ? 'READY'
                        : 'NOT_READY',
                ];
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function deploymentReadinessSummary(): array
    {
        try {
            $report = $this->rollout->report();

            return [
                'decision' => (string) ($report['decision'] ?? self::STATUS_UNKNOWN),
                'summary' => (array) ($report['summary'] ?? []),
            ];
        } catch (Throwable $e) {
            return ['decision' => self::STATUS_UNKNOWN, 'summary' => [], 'error' => class_basename($e)];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function monitoringReport(bool $include): ?array
    {
        if (! $include) {
            return null;
        }

        try {
            // Audits are never invoked from here: they shell out to other
            // commands, and a readiness report must stay cheap enough to run
            // before every batch.
            return $this->monitoring->collect(['include_audits' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>|null
     */
    private function monitoringSignal(array $report, string $key): ?array
    {
        foreach ((array) ($report['signals'] ?? []) as $signal) {
            if (is_array($signal) && ($signal['key'] ?? null) === $key) {
                return $signal;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Classification
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $check
     */
    private function severityFor(array $check): string
    {
        $status = (string) ($check['status'] ?? self::STATUS_UNKNOWN);
        $map = (array) config('legacy_rme_steady_state.severity.map', []);
        $severity = (string) ($map[$status] ?? 'BLOCKER');

        $incidentCodes = (array) config('legacy_rme_steady_state.severity.incident_codes', []);
        $code = $check['context']['code'] ?? null;

        // A compromised control or audit trail outranks a plain blocker.
        if (is_string($code) && in_array($code, $incidentCodes, true) && $status !== self::STATUS_GO) {
            return 'INCIDENT';
        }

        return $severity;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return list<string>
     */
    private function stopTheLine(array $checks): array
    {
        $declared = (array) config('legacy_rme_steady_state.stop_the_line', []);
        $found = [];

        foreach ($checks as $check) {
            if (($check['status'] ?? null) === self::STATUS_GO) {
                continue;
            }

            $codes = (array) ($check['context']['codes'] ?? []);
            $single = $check['context']['code'] ?? null;
            if (is_string($single)) {
                $codes[] = $single;
            }

            foreach ($codes as $code) {
                if (is_string($code) && in_array($code, $declared, true)) {
                    $found[$code] = true;
                }
            }
        }

        return array_values(array_keys($found));
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return list<array<string, mixed>>
     */
    private function blockers(array $checks): array
    {
        $blockers = [];

        foreach ($checks as $check) {
            if (in_array($check['status'] ?? null, [self::STATUS_FAIL, self::STATUS_UNKNOWN], true)) {
                $blockers[] = [
                    'id' => $check['id'] ?? null,
                    'status' => $check['status'] ?? null,
                    'severity' => $check['severity'] ?? null,
                    'summary' => $check['summary'] ?? null,
                    'remediation' => $check['remediation'] ?? null,
                ];
            }
        }

        return $blockers;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, int>
     */
    private function summarise(array $checks): array
    {
        $summary = [self::STATUS_GO => 0, self::STATUS_WATCH => 0, self::STATUS_FAIL => 0, self::STATUS_UNKNOWN => 0];

        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? self::STATUS_UNKNOWN);
            $summary[$status] = ($summary[$status] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, int>
     */
    private function severitySummary(array $checks): array
    {
        $levels = (array) config('legacy_rme_steady_state.severity.levels', []);
        $summary = [];
        foreach ($levels as $level) {
            $summary[(string) $level] = 0;
        }

        foreach ($checks as $check) {
            $severity = (string) ($check['severity'] ?? 'BLOCKER');
            $summary[$severity] = ($summary[$severity] ?? 0) + 1;
        }

        return $summary;
    }

    private function clinicalToday(): ?string
    {
        try {
            return $this->clock->todayString();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeBranchCode(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }

        $code = strtoupper(trim($code));

        return $code === '' ? null : $code;
    }

    private function guarded(string $id, callable $probe): LegacyRmeRolloutCheck
    {
        try {
            return $probe();
        } catch (Throwable $e) {
            return LegacyRmeRolloutCheck::unknown(
                $id,
                'The check could not be evaluated on this deployment.',
                ['error' => class_basename($e)],
                'Investigate the underlying dependency. An unevaluated check never counts as ready.',
            );
        }
    }
}
