<?php

namespace App\Modules\DoctorAccess\Services;

use App\Modules\DoctorAccess\Support\DoctorEstateResilienceVerdict;
use App\Modules\DoctorAccess\Support\DoctorFleetReadinessVerdict;
use App\Modules\DoctorAccess\Support\DoctorGlobalEnforcementPrerequisite as Prerequisite;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Android\AndroidDoctorEnforcementScope;
use App\Support\Android\Phase4aPilotPreparationScanner;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1 — the Half-B gate that
 * measures, beside the Half-B gate that only reads signatures.
 *
 * THE DEFECT THIS CLOSES, IN ONE LINE.
 *
 *     Phase4aPilotPreparationScanner::globalPrerequisiteCheck()
 *         attested true + measured false  ===>  PASS
 *
 * That check compares `global_prerequisites` against
 * `global_prerequisites_attested` and asserts that each name carries a
 * signature. It reads no measurement of any kind, so the one artifact meant to
 * stop a premature fleet-wide lockout can be satisfied by typing `true` five
 * times. `DoctorEstateResilienceService` already closed this for ONE of the
 * five. This engine closes it for all five, by giving the other four a
 * producer to be contradicted by.
 *
 * TWO AGGREGATES, NEVER FOLDED INTO ONE.
 *
 * This programme has repeatedly been bitten by a single verdict answering two
 * questions, so the two are named and reported separately:
 *
 *   `verdict` — READINESS OF THE MACHINERY. Is every declared prerequisite
 *       actually measured by something, and does no recorded signature stand
 *       against what was measured? This is what a readiness sprint can close,
 *       and it is what this engine's verdict means.
 *
 *   `activation_prerequisites` — READINESS OF THE WORLD. The worst of the five
 *       measurements. Today it is not green and it is not this sprint's to
 *       make green: a spare tablet at every branch is a purchase order, and a
 *       rehearsed device-loss runbook is an afternoon in a clinic.
 *
 * A GREEN `verdict` BESIDE A RED `activation_prerequisites` IS THE EXPECTED
 * AND CORRECT STATE of this deployment. It says: the instruments are honest,
 * and they are telling you not to activate yet. Collapsing the two would
 * either redden the machinery for months over a purchase order, or — far
 * worse — let a green headline imply the fleet is ready.
 *
 * {@see self::AUTHORIZES_ACTIVATION} is `false` unconditionally and is printed
 * with every report.
 */
class DoctorGlobalEnforcementReadinessService
{
    /** Every prerequisite is measured and no signature contradicts a measurement. */
    public const VERDICT_READY = 'READY';

    /** A signature stands against a measurement, or a prerequisite measures nothing. */
    public const VERDICT_BLOCKED = 'BLOCKED';

    /**
     * The instruments could not be read.
     *
     * Never a pass. A readiness engine that cannot reach its own producers has
     * not found the deployment ready; it has failed to look.
     */
    public const VERDICT_UNVERIFIED = 'UNVERIFIED';

    /**
     * Stated in the payload rather than left to a reader's assumption, and the
     * same contract the estate engine carries.
     */
    public const AUTHORIZES_ACTIVATION = false;

    /** The two engine snapshots disagree, so no verdict over them is safe. */
    public const FINDING_SNAPSHOT_DISAGREEMENT = 'engine_snapshots_disagree';

    /** A signature stands against a measurement. */
    public const FINDING_CONTRADICTION = 'attestation_contradicts_measurement';

    /** The list and its signature block have drifted apart. */
    public const FINDING_SIGNATURE_SLOT_MISSING = 'declared_prerequisite_has_no_signature_slot';

    /** A declared prerequisite that nothing in this engine measures. */
    public const FINDING_NO_PRODUCER = 'declared_prerequisite_has_no_producer';

    public function __construct(
        private readonly DoctorFleetReadinessService $fleet,
        private readonly DoctorEstateResilienceService $estate,
        private readonly DoctorHalfBRollbackProofService $rollback,
        private readonly AndroidDoctorEnforcementScope $scope,
        private readonly FeatureFlagService $flags,
        private readonly Phase4aPilotPreparationScanner $posture,

        /*
         * The FACTORY, not a resolved disk. Resolving `local` at construction
         * time would capture whichever disk existed when this service was
         * built, which in a test is the real one a `Storage::fake()` was
         * supposed to replace. Asked for at the moment of use instead.
         */
        private readonly FilesystemFactory $filesystem,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $fleetReport = $this->safely(fn (): array => $this->fleet->build());
        $estateReport = $this->safely(fn (): array => $this->estate->build());

        $measurements = [
            Prerequisite::REAL_DEVICE_PILOT_PASSED => $this->measurePilotPassed($fleetReport),
            Prerequisite::EVERY_DOCTOR_HAS_ACTIVE_DEVICE => $this->measureEveryDoctorProvisioned($fleetReport),
            Prerequisite::SPARE_DEVICE_PER_BRANCH => $this->measureSpareDevice($estateReport),
            Prerequisite::DEVICE_LOSS_RUNBOOK_REHEARSED => $this->measureDeviceLossRehearsal(),
            Prerequisite::ROLLBACK_TO_BROWSER_LOGIN_PROVEN => $this->measureRollback(),
        ];

        $snapshot = $this->snapshotAgreement($fleetReport, $estateReport);
        $prerequisites = $this->crossCheck($measurements, $snapshot);
        $findings = $this->findings($prerequisites, $snapshot);

        return [
            /*
             * THE MACHINERY, and deliberately not the world. See the class
             * docblock: a reader who wants to know whether the fleet may be
             * enforced reads `activation_prerequisites`, one line below.
             */
            'verdict' => $this->machineryVerdict($prerequisites, $snapshot),
            'verdict_semantics' => 'HALF_B_READINESS is the readiness of the INSTRUMENTS: every declared '
                .'prerequisite has a producer, and no recorded signature stands against a measurement. It is '
                .'NOT the readiness of the fleet — read activation_prerequisites for that — and it authorizes '
                .'no activation, no flag, no phase move and no cohort change.',

            'activation_prerequisites' => Prerequisite::worst(array_map(
                static fn (array $row): string => (string) $row['measured'],
                array_values($prerequisites),
            )),
            'activation_prerequisites_semantics' => 'The worst of the five measurements: whether the WORLD is '
                .'ready for fleet-wide enforcement. A prerequisite that is UNVERIFIED has not been measured as '
                .'true and must not be signed.',

            'prerequisites' => $prerequisites,
            'contradicting_prerequisites' => array_values(array_map(
                static fn (array $row): string => (string) $row['prerequisite'],
                array_filter($prerequisites, static fn (array $row): bool => $row['contradiction'] === true),
            )),
            'blocking_prerequisites' => array_values(array_map(
                static fn (array $row): string => (string) $row['prerequisite'],
                array_filter($prerequisites, static fn (array $row): bool => $row['blocks_activation'] === true),
            )),
            'findings' => $findings,
            'snapshot_agreement' => $snapshot,
            'runtime' => $this->runtime(),

            'authorizes_activation' => self::AUTHORIZES_ACTIVATION,
            'activation_semantics' => 'Half B is armed by three things this engine cannot reach: '
                .'android_release.enforcement.scope.global_permitted, a governance_phase move, and the '
                .'enforcement flag. Reading READY here changes none of them.',
        ];
    }

    // -----------------------------------------------------------------------
    // The five producers
    // -----------------------------------------------------------------------

    /**
     * real_device_pilot_passed — did the bounded pilot actually happen, on
     * hardware, with the doctors it is declared over?
     *
     * Measured over the CURRENT cohort rather than a remembered one. The cohort
     * is the union of two host variables, it has no database representation,
     * and it moved inside a single day once already; a count written into
     * config would describe whichever estate existed when it was typed.
     *
     * An EMPTY cohort is UNVERIFIED. A pilot nobody is enrolled in has not
     * passed — it has not run, and `worst([])` returning PASS is the defect
     * that let a sibling gate report green over zero usable tablets.
     *
     * @param  array<string,mixed>|null  $fleetReport
     * @return array<string,mixed>
     */
    private function measurePilotPassed(?array $fleetReport): array
    {
        if ($fleetReport === null) {
            return $this->measurement(Prerequisite::UNVERIFIED, 'The fleet readiness engine could not be read.');
        }

        $cohort = $this->scope->pilotDoctorUserIds();
        $minimum = (int) config('doctor_global_enforcement_readiness.pilot.minimum_cohort_size', 1);

        if (count($cohort) < max(1, $minimum)) {
            return $this->measurement(
                Prerequisite::UNVERIFIED,
                'The enforcement cohort resolves to '.count($cohort).' doctor(s), below the minimum of '
                .max(1, $minimum).'. A pilot with no enrolled doctors has not passed; it has not run.',
                ['cohort' => $cohort],
            );
        }

        $rows = (array) ($fleetReport['doctors'] ?? []);
        $byUser = [];

        foreach ($rows as $row) {
            $byUser[(int) ($row['user_id'] ?? 0)] = (array) ($row['blockers'] ?? []);
        }

        $unproven = [];
        $unknown = [];

        foreach ($cohort as $userId) {
            if (! array_key_exists($userId, $byUser)) {
                $unknown[] = $userId;

                continue;
            }

            if (in_array(DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN, $byUser[$userId], true)) {
                $unproven[] = $userId;
            }
        }

        /*
         * A cohort member the fleet engine does not measure is UNVERIFIED, not
         * a pass by omission. An id in the host variable that matches no
         * eligible doctor account is exactly the drift this must surface.
         */
        if ($unknown !== []) {
            return $this->measurement(
                Prerequisite::UNVERIFIED,
                'The cohort names user(s) '.implode(', ', array_map('strval', $unknown)).' whom the fleet '
                .'engine does not measure, so the pilot cannot be evaluated over the cohort it declares.',
                ['cohort' => $cohort, 'unmeasured' => $unknown],
            );
        }

        return $this->measurement(
            $unproven === [] ? Prerequisite::PASS : Prerequisite::FAIL,
            $unproven === []
                ? 'Every doctor in the enforcement cohort ('.implode(', ', array_map('strval', $cohort))
                .') has a recorded device login proof on trusted hardware.'
                : 'Cohort doctor(s) '.implode(', ', array_map('strval', $unproven)).' have no device login '
                .'proof on hardware they can still use, so the bounded pilot has not passed for them.',
            ['cohort' => $cohort, 'without_proof' => $unproven],
        );
    }

    /**
     * every_enforced_doctor_has_an_active_device — composed, never
     * re-implemented.
     *
     * The provisioning engine owns this question and its verdict is carried
     * through rather than paraphrased. A second trusted-path calculator is a
     * second thing to keep in step, and this programme has already paid for
     * one predicate written down twice.
     *
     * @param  array<string,mixed>|null  $fleetReport
     * @return array<string,mixed>
     */
    private function measureEveryDoctorProvisioned(?array $fleetReport): array
    {
        if ($fleetReport === null) {
            return $this->measurement(Prerequisite::UNVERIFIED, 'The fleet readiness engine could not be read.');
        }

        $verdict = (string) ($fleetReport['provisioning']['verdict'] ?? '');
        $notReady = (int) ($fleetReport['provisioning']['not_ready_doctor_count'] ?? 0);
        $ready = (int) ($fleetReport['provisioning']['ready_doctor_count'] ?? 0);

        if ($verdict === '') {
            return $this->measurement(Prerequisite::UNVERIFIED, 'The provisioning engine returned no verdict.');
        }

        /*
         * An empty population fails. `TRUSTED_PATHS_COMPLETE` over zero
         * doctors is arithmetically a pass and operationally a broken query.
         */
        if ($ready + $notReady === 0) {
            return $this->measurement(
                Prerequisite::UNVERIFIED,
                'The provisioning engine measured zero doctors. An empty population is not a satisfied '
                .'prerequisite.',
            );
        }

        return $this->measurement(
            $verdict === DoctorGlobalRolloutReadinessService::VERDICT_TRUSTED_PATHS_COMPLETE
                ? Prerequisite::PASS
                : Prerequisite::FAIL,
            'Provisioning verdict is '.$verdict.': '.$ready.' doctor(s) hold a complete trusted path, '
            .$notReady.' do not.',
            ['provisioning_verdict' => $verdict, 'ready' => $ready, 'not_ready' => $notReady],
        );
    }

    /**
     * spare_device_available_per_branch — read from the gate that has always
     * owned it.
     *
     * Level 3 of the estate capacity policy. Carried through by its gate key
     * rather than recomputed, so this engine can never print a different
     * answer from `doctor:estate-resilience` for the same question.
     *
     * @param  array<string,mixed>|null  $estateReport
     * @return array<string,mixed>
     */
    private function measureSpareDevice(?array $estateReport): array
    {
        if ($estateReport === null) {
            return $this->measurement(Prerequisite::UNVERIFIED, 'The estate resilience engine could not be read.');
        }

        foreach ((array) ($estateReport['gates'] ?? []) as $gate) {
            if ((string) ($gate['gate'] ?? '') !== DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE) {
                continue;
            }

            $verdict = (string) ($gate['verdict'] ?? Prerequisite::UNVERIFIED);

            return $this->measurement(
                in_array($verdict, Prerequisite::STATUSES, true) ? $verdict : Prerequisite::UNVERIFIED,
                'Read from the estate engine gate "'.DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE
                .'": '.$verdict.'. '.(string) ($gate['detail'] ?? ''),
                ['required_capacity' => $estateReport['required_capacity'] ?? null],
            );
        }

        /*
         * The gate key is a constant on both sides precisely so this branch
         * stays unreachable. If it is reached, the producer and the consumer
         * have drifted and UNVERIFIED is the only honest answer.
         */
        return $this->measurement(
            Prerequisite::UNVERIFIED,
            'The estate engine published no gate named "'.DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE
            .'". The producer and this consumer have drifted apart.',
        );
    }

    /**
     * device_loss_runbook_rehearsed — evidenced, the way a restore drill is.
     *
     * No query establishes that a clinic rehearsed losing a tablet. What CAN
     * be established is whether somebody recorded having done it, in a
     * well-formed artifact, recently enough to describe today's fleet, with an
     * outcome that says it worked.
     *
     * ABSENT is UNVERIFIED, never FAIL: nobody having rehearsed yet is the
     * ordinary state of a rung this deployment has not reached, and a gate
     * that is red for months gets deleted rather than fixed. UNVERIFIED still
     * contradicts a signature recorded `true`, which is the property that
     * protects the fleet.
     *
     * @return array<string,mixed>
     */
    private function measureDeviceLossRehearsal(): array
    {
        $config = (array) config('doctor_global_enforcement_readiness.device_loss_rehearsal', []);
        $path = (string) ($config['evidence_path'] ?? '');

        if ($path === '') {
            return $this->measurement(
                Prerequisite::UNVERIFIED,
                'No evidence path is configured for the device-loss rehearsal.',
            );
        }

        $disk = $this->filesystem->disk('local');

        try {
            if (! $disk->exists($path)) {
                return $this->measurement(
                    Prerequisite::UNVERIFIED,
                    'No device-loss rehearsal evidence exists at "'.$path.'". Nobody has recorded a rehearsal '
                    .'on this deployment, which is not the same as one having failed.',
                    ['evidence_path' => $path],
                );
            }

            $raw = (string) $disk->get($path);
        } catch (Throwable $e) {
            return $this->measurement(
                Prerequisite::UNVERIFIED,
                'The device-loss rehearsal evidence could not be read: '.$e->getMessage(),
                ['evidence_path' => $path],
            );
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $this->measurement(
                Prerequisite::FAIL,
                'The device-loss rehearsal evidence at "'.$path.'" is not a JSON object. Malformed evidence is '
                .'a failure, not an absence.',
                ['evidence_path' => $path],
            );
        }

        $missing = array_values(array_filter(
            (array) ($config['required_keys'] ?? []),
            static fn ($key): bool => ! array_key_exists((string) $key, $decoded),
        ));

        if ($missing !== []) {
            return $this->measurement(
                Prerequisite::FAIL,
                'The device-loss rehearsal evidence is missing required key(s): '
                .implode(', ', array_map('strval', $missing)).'.',
                ['evidence_path' => $path],
            );
        }

        $marker = (string) ($config['template_marker'] ?? 'TEMPLATE');

        if ($marker !== '' && str_contains((string) ($decoded['drill_id'] ?? ''), $marker)) {
            return $this->measurement(
                Prerequisite::UNVERIFIED,
                'The device-loss rehearsal evidence is an unfilled template. A placeholder that validates is a '
                .'placeholder that gets signed.',
                ['evidence_path' => $path],
            );
        }

        $performedAt = $this->parseDate((string) ($decoded['performed_at'] ?? ''));

        if (! $performedAt instanceof Carbon) {
            return $this->measurement(
                Prerequisite::FAIL,
                'The device-loss rehearsal evidence carries no readable performed_at date.',
                ['evidence_path' => $path],
            );
        }

        /*
         * A REHEARSAL IN THE FUTURE IS NOT A FRESH ONE.
         *
         * `diffInDays()` is absolute in some Carbon versions and signed in
         * others, and a staleness test written as "difference greater than the
         * limit" reads a date years ahead as either very stale or perfectly
         * fresh depending on which version is installed. Neither is a
         * judgement anyone wants a clinical gate to make, so the direction is
         * decided explicitly and first. This programme has already shipped a
         * date check that let invalid literals reach GO.
         */
        if ($performedAt->isFuture()) {
            return $this->measurement(
                Prerequisite::FAIL,
                'The device-loss rehearsal evidence is dated '.$performedAt->toDateString().', which is in the '
                .'future. A rehearsal that has not happened yet is not evidence that it went well.',
                ['evidence_path' => $path, 'performed_at' => $performedAt->toDateString()],
            );
        }

        $maxAge = (int) ($config['max_age_days'] ?? 365);

        if ($performedAt->diffInDays(now()) > $maxAge) {
            return $this->measurement(
                Prerequisite::UNVERIFIED,
                'The device-loss rehearsal was performed '.$performedAt->toDateString().', more than '.$maxAge
                .' days ago. It describes a fleet that may no longer exist.',
                ['evidence_path' => $path, 'performed_at' => $performedAt->toDateString()],
            );
        }

        $outcome = (string) ($decoded['outcome'] ?? '');
        $passing = (string) ($config['passing_outcome'] ?? 'passed');
        $regained = ($decoded['clinician_regained_access'] ?? null) === true;

        if ($outcome !== $passing || ! $regained) {
            return $this->measurement(
                Prerequisite::FAIL,
                'The device-loss rehearsal is recorded with outcome "'.$outcome.'" and clinician_regained_access '
                .var_export($decoded['clinician_regained_access'] ?? null, true)
                .'. A rehearsal that ran and did not restore a clinician is evidence of the opposite thing.',
                ['evidence_path' => $path, 'performed_at' => $performedAt->toDateString()],
            );
        }

        return $this->measurement(
            Prerequisite::PASS,
            'A device-loss rehearsal is recorded for '.$performedAt->toDateString().' with outcome "'.$outcome
            .'" and the clinician regaining access.',
            ['evidence_path' => $path, 'performed_at' => $performedAt->toDateString()],
        );
    }

    /**
     * rollback_to_browser_login_proven — executed, not described.
     *
     * @return array<string,mixed>
     */
    private function measureRollback(): array
    {
        try {
            $proof = $this->rollback->prove();
        } catch (Throwable $e) {
            return $this->measurement(
                Prerequisite::UNVERIFIED,
                'The rollback rehearsal could not be executed: '.$e->getMessage(),
            );
        }

        $failed = array_values(array_filter(
            (array) ($proof['steps'] ?? []),
            static fn (array $step): bool => (string) $step['status'] !== Prerequisite::PASS,
        ));

        return $this->measurement(
            (string) $proof['measured'],
            $failed === []
                ? 'A rollback from the activated posture back to the captured posture was executed in-process '
                .'and restored browser login to a doctor Half B would newly capture.'
                : 'The rollback rehearsal did not complete cleanly: '.implode('; ', array_map(
                    static fn (array $step): string => (string) $step['step'].' = '.(string) $step['status'],
                    $failed,
                )).'.',
            ['proof' => $proof],
        );
    }

    // -----------------------------------------------------------------------
    // Cross-check
    // -----------------------------------------------------------------------

    /**
     * Compare every declared prerequisite against its signature.
     *
     * Iterates the DECLARED list, not the measurement map: a prerequisite
     * somebody adds to config and nobody measures must surface as a producer
     * gap rather than vanish.
     *
     * @param  array<string,array<string,mixed>>  $measurements
     * @param  array<string,mixed>  $snapshot
     * @return array<string,array<string,mixed>>
     */
    private function crossCheck(array $measurements, array $snapshot): array
    {
        $declared = (array) config(Prerequisite::CONFIG_DECLARED, []);
        $signed = (array) config(Prerequisite::CONFIG_ATTESTED, []);
        $rows = [];

        foreach ($declared as $name) {
            $key = (string) $name;
            $hasProducer = array_key_exists($key, $measurements);

            $measured = $hasProducer
                ? (string) $measurements[$key]['measured']
                : Prerequisite::UNVERIFIED;

            /*
             * A disagreement between the two engine snapshots invalidates any
             * measurement drawn from them. It cannot invalidate the rollback
             * rehearsal or the rehearsal evidence, which read neither engine.
             */
            if ($snapshot['agrees'] === false && in_array($key, [
                Prerequisite::REAL_DEVICE_PILOT_PASSED,
                Prerequisite::EVERY_DOCTOR_HAS_ACTIVE_DEVICE,
                Prerequisite::SPARE_DEVICE_PER_BRANCH,
            ], true)) {
                $measured = Prerequisite::UNVERIFIED;
            }

            $attested = ($signed[$key] ?? null) === true;

            $rows[$key] = [
                'prerequisite' => $key,
                'measured' => $measured,
                'attested' => $attested,
                'signature_recorded' => array_key_exists($key, $signed),
                'has_producer' => $hasProducer,

                // The single expression of the rule, asked rather than
                // re-derived. See Prerequisite::contradicts().
                'contradiction' => Prerequisite::contradicts($attested, $measured),

                'signature_slot_missing' => ! array_key_exists($key, $signed),
                'blocks_activation' => $measured !== Prerequisite::PASS,
                'applicable' => true,
                'evidence' => $hasProducer ? $measurements[$key]['detail'] : 'No producer measures this '
                    .'prerequisite, so nothing can contradict a signature recorded against it.',
                'context' => $hasProducer ? ($measurements[$key]['context'] ?? []) : [],
            ];
        }

        return $rows;
    }

    /**
     * Do the two engine reports describe the same moment?
     *
     * The estate engine composes the fleet engine internally, so this report
     * holds TWO fleet snapshots taken microseconds apart. That is ordinarily
     * harmless and occasionally not: a report that mixes two snapshots can
     * print a verdict no single moment supports. Rather than assume, the
     * invariant both engines publish is compared, and a disagreement demotes
     * every measurement drawn from them to UNVERIFIED.
     *
     * @param  array<string,mixed>|null  $fleetReport
     * @param  array<string,mixed>|null  $estateReport
     * @return array<string,mixed>
     */
    private function snapshotAgreement(?array $fleetReport, ?array $estateReport): array
    {
        if ($fleetReport === null || $estateReport === null) {
            return [
                'agrees' => false,
                'detail' => 'One of the two engines could not be read, so their agreement cannot be established.',
            ];
        }

        $fleetDoctors = (int) ($fleetReport['eligible_doctor_count'] ?? -1);
        $estateDoctors = (int) ($estateReport['authorization_matrix']['doctor_count']
            ?? $estateReport['estate_totals']['eligible_doctor_count']
            ?? -1);

        if ($estateDoctors < 0) {
            return [
                'agrees' => true,
                'detail' => 'The estate engine publishes no comparable doctor count, so the two snapshots are '
                    .'not cross-checked on that invariant.',
                'fleet_eligible_doctors' => $fleetDoctors,
            ];
        }

        return [
            'agrees' => $fleetDoctors === $estateDoctors,
            'detail' => $fleetDoctors === $estateDoctors
                ? 'Both engines measured the same eligible doctor population ('.$fleetDoctors.').'
                : 'The engines disagree on the eligible doctor population (fleet '.$fleetDoctors.', estate '
                .$estateDoctors.'). Every measurement drawn from them is demoted to UNVERIFIED.',
            'fleet_eligible_doctors' => $fleetDoctors,
            'estate_eligible_doctors' => $estateDoctors,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $prerequisites
     * @param  array<string,mixed>  $snapshot
     * @return list<array<string,mixed>>
     */
    private function findings(array $prerequisites, array $snapshot): array
    {
        $findings = [];

        if ($snapshot['agrees'] === false) {
            $findings[] = [
                'finding' => self::FINDING_SNAPSHOT_DISAGREEMENT,
                'detail' => (string) $snapshot['detail'],
            ];
        }

        foreach ($prerequisites as $row) {
            if ($row['contradiction'] === true) {
                $findings[] = [
                    'finding' => self::FINDING_CONTRADICTION,
                    'prerequisite' => $row['prerequisite'],
                    'detail' => 'Recorded true, measured '.$row['measured'].'. A signature never overrides a '
                        .'measurement: '.$row['evidence'],
                ];
            }

            if ($row['signature_slot_missing'] === true) {
                $findings[] = [
                    'finding' => self::FINDING_SIGNATURE_SLOT_MISSING,
                    'prerequisite' => $row['prerequisite'],
                    'detail' => 'Declared in '.Prerequisite::CONFIG_DECLARED.' with no slot in '
                        .Prerequisite::CONFIG_ATTESTED.'. That reads identically to "nobody has signed yet" '
                        .'and is in fact "the list and the signatures have drifted apart".',
                ];
            }

            if ($row['has_producer'] === false) {
                $findings[] = [
                    'finding' => self::FINDING_NO_PRODUCER,
                    'prerequisite' => $row['prerequisite'],
                    'detail' => 'Nothing measures this prerequisite, so a signature recorded against it can '
                        .'never be contradicted.',
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  array<string,array<string,mixed>>  $prerequisites
     * @param  array<string,mixed>  $snapshot
     */
    private function machineryVerdict(array $prerequisites, array $snapshot): string
    {
        if ($prerequisites === []) {
            return self::VERDICT_UNVERIFIED;
        }

        foreach ($prerequisites as $row) {
            if ($row['contradiction'] === true || $row['has_producer'] === false) {
                return self::VERDICT_BLOCKED;
            }
        }

        return $snapshot['agrees'] === true ? self::VERDICT_READY : self::VERDICT_UNVERIFIED;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtime(): array
    {
        return [
            'governance_phase' => $this->posture->governancePhase(),
            'enforcement_scope_mode' => $this->scope->mode(),
            'enforcement_scope_usable' => $this->scope->isUsable(),
            'enforcement_cohort' => $this->scope->pilotDoctorUserIds(),
            'global_permitted' => $this->scope->globalPermitted(),
            'enforcement_flag_armed' => $this->flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG),

            // Measured from the resolved scope, never read from a recorded claim.
            'global_enforcement_active_live' => $this->scope->isUnscopedMode()
                && $this->scope->globalPermitted()
                && $this->flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG),
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function measurement(string $status, string $detail, array $context = []): array
    {
        return [
            'measured' => in_array($status, Prerequisite::STATUSES, true) ? $status : Prerequisite::UNVERIFIED,
            'detail' => $detail,
            'context' => $context,
        ];
    }

    /**
     * An engine that throws is UNREADABLE, never absent and never fine.
     *
     * @return array<string,mixed>|null
     */
    private function safely(callable $build): ?array
    {
        try {
            return (array) $build();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDate(string $raw): ?Carbon
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
