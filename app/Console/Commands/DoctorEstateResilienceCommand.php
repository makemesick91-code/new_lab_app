<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\DoctorAccess\Services\DoctorEstateResilienceService;
use App\Modules\DoctorAccess\Support\DoctorEstateCapacityLevel;
use App\Modules\DoctorAccess\Support\DoctorEstateResilienceVerdict;
use Illuminate\Console\Command;

/**
 * DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1.
 *
 * `doctor:rollout-readiness`  — is a trusted path PROVISIONED?
 * `doctor:fleet-readiness`    — has it been EXERCISED?
 * `doctor:estate-resilience`  — does the HARDWARE survive losing a tablet?
 *
 * Read-only. It registers no device, approves nothing, enrols no credential,
 * writes no attestation, arms no flag and never widens the pilot cohort. A PASS
 * authorises a later activation DECISION and nothing else.
 */
class DoctorEstateResilienceCommand extends Command
{
    protected $signature = 'doctor:estate-resilience
        {--json : Output the report as JSON}
        {--strict : Exit non-zero unless every estate gate PASSES (the FULL-MATURITY question, never greener than high availability)}
        {--activation-preflight : Exit non-zero unless the ACTIVATION-TESTING prerequisite is satisfied (Level 1 + credential + authorization coverage, and signed)}';

    protected $description = 'Trusted device estate resilience: per-branch capacity, spare headroom, credential and authorization coverage';

    public function handle(DoctorEstateResilienceService $resilience): int
    {
        $report = $resilience->build();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        /*
         * Nothing to measure is a broken state, not a rollout in progress, and
         * fails whether or not --strict was asked for. An empty estate reading
         * as "no gaps found" is precisely the false green this family of gates
         * exists to prevent.
         */
        if ($report['branches'] === []) {
            return 1;
        }

        /*
         * A measured FAIL exits 0 by default. The estate is short of hardware
         * today and will be until tablets are bought, and a gate that reddens a
         * deploy chain for months gets deleted rather than fixed. --strict is
         * how an activation preflight asks the question that must be green.
         */
        if ($this->option('strict') && $report['verdict'] !== DoctorEstateResilienceVerdict::PASS) {
            return 1;
        }

        /*
         * THE ACTIVATION QUESTION HAS ITS OWN EXIT CODE, because `--strict`
         * asks a different and STRICTLY HARDER one.
         *
         * `--strict` keys on the aggregate, which is never greener than high
         * availability. An activation preflight wired to it would exit 1 even
         * once every staffed branch holds a usable, authorized tablet — the
         * exact conflation this revision exists to end, relocated into an exit
         * code where it is harder to see. Two questions, two flags.
         */
        if ($this->option('activation-preflight')
            && $report['activation_test_prerequisite']['status'] !== DoctorEstateResilienceVerdict::PASS) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info('Doctor trusted device estate resilience and capacity — '
            .'REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1');
        $this->newLine();

        $l1 = DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE;
        $l2 = DoctorEstateCapacityLevel::ROOM_CAPACITY;
        $l3 = DoctorEstateCapacityLevel::FAILURE_RESILIENCE;

        $this->table(
            ['Branch', 'Staffed', 'Home drs', 'Rooms*', 'Dr rooms', 'Devices', 'Usable', 'L1 test', 'L2 rooms', 'L3 HA'],
            array_map(static fn (array $row): array => [
                (string) ($row['branch_code'] ?? $row['branch_id']),
                $row['staffed'] ? ($row['hosts_active_cover'] ? 'yes+cover' : 'yes') : 'no',
                (string) $row['home_doctor_count'],
                $row['active_treatment_rooms'] === null ? '-' : (string) $row['active_treatment_rooms'],
                $row['active_doctor_rooms'] === null ? '-' : (string) $row['active_doctor_rooms'],
                (string) $row['total_device_count'].'/'.(string) $row['eligible_device_count'],
                (string) $row['locally_usable_device_count'],
                (string) $row['capacity_levels'][$l1],
                (string) $row['capacity_levels'][$l2],
                (string) $row['capacity_levels'][$l3],
            ], $report['branches']),
        );

        $this->newLine();
        $this->line('BRANCH_SCOPE='.$report['branch_scope']);
        $this->comment('* Rooms = ACTIVE treatment rooms, the Level-3 ADVISORY reference only: a room inventory '
            .'is not a peak concurrent station count and it decides no Level-3 verdict. "Dr rooms" is the '
            .'separate LEVEL 2 denominator — active rooms a doctor and a patient meet in. Devices column is '
            .'total/eligible; "Usable" is eligible AND carrying an unrevoked credential, which is what Level 1 '
            .'counts.');
        $this->line('TOTAL_DEVICE_ESTATE='.$report['estate_totals']['total_devices']);
        $this->line('ELIGIBLE_TRUSTED_DEVICES='.$report['estate_totals']['eligible_devices']);
        $this->line('ELIGIBLE_DEVICE_IDS='.$this->csv($report['estate_totals']['eligible_device_ids']));
        $this->line('REVOKED_DEVICES='.$report['estate_totals']['revoked_devices']);

        $this->newLine();
        $capacity = $report['required_capacity'];
        $this->line('REQUIRED_CAPACITY_BASIS='.$capacity['basis']);
        $this->line('MISSING_INPUT='.$capacity['missing_input'].' ('.$capacity['missing_input_state'].')');
        $this->line('MINIMUM_ELIGIBLE_DEVICES_REQUIRED='.$capacity['minimum_eligible_devices_required']);
        $this->line('ELIGIBLE_DEVICES_HELD='.$capacity['eligible_devices_held']);
        $this->line('MINIMUM_ADDITIONAL_DEVICES='.$capacity['minimum_additional_devices']);

        $this->newLine();
        $this->line('-- Gates --');
        foreach ($report['gates'] as $gate) {
            $line = str_pad((string) $gate['gate'], 36).' '.$gate['verdict'];

            match ((string) $gate['verdict']) {
                DoctorEstateResilienceVerdict::PASS => $this->line('  '.$line),
                DoctorEstateResilienceVerdict::UNVERIFIED => $this->warn('  '.$line),
                default => $this->error('  '.$line),
            };

            $this->line('      '.$gate['detail']);
        }

        $this->newLine();
        $failure = $report['failure_domain'];
        $this->line('-- Failure domain: '.$failure['scenario'].' --');
        $this->line('  SERVICEABLE_AFTER_ONE_LOSS='.$this->csvStrings($failure['branches_serviceable_after_one_loss']));
        foreach ($failure['branches_that_stop'] as $stop) {
            $this->error('  STOPS: '.$stop['branch_code']
                .' (home doctors '.$stop['home_doctors_affected']
                .', eligible devices '.$stop['eligible_devices'].')');
        }
        $this->line('  HOME_DOCTORS_AFFECTED_TOTAL='.$failure['home_doctors_affected_total']);

        $attestation = $report['attestation'];
        $this->newLine();
        $this->line('-- Attestation cross-check (an absent signature fails nothing; a contradicting one does) --');

        foreach ($attestation['prerequisites'] as $row) {
            $this->line('  '.$row['prerequisite']
                .'  MEASURED='.$row['measured']
                .'  ATTESTED='.$this->bool((bool) $row['attested']));

            if ($row['contradiction']) {
                $this->error('    CONTRADICTION: signed true, measured otherwise.');
            }

            if ($row['signature_slot_missing']) {
                $this->error('    DRIFT: declared as a prerequisite with no signature slot at all.');
            }
        }

        foreach ($report['findings'] as $finding) {
            $this->newLine();
            $this->warn('FINDING '.$finding['finding']);
            $this->line('  '.$finding['detail']);
        }

        $this->newLine();
        $this->line('AUTHORIZATION_TARGET_PAIRS='.$report['authorization_matrix']['target_pairs']);
        $this->line('AUTHORIZATION_ACTIVE_PAIRS='.$report['authorization_matrix']['active_pairs']);
        $this->line('AUTHORIZATION_MISSING_PAIRS='.$report['authorization_matrix']['missing_pairs']);
        $this->line('AUTHORIZATION_DUPLICATE_ACTIVE_PAIRS='.$report['authorization_matrix']['duplicate_active_pairs']);

        $this->newLine();
        $this->line('GLOBAL_ENFORCEMENT_ACTIVE='.$this->bool((bool) ($report['runtime']['global_enforcement_active'] ?? false)));
        $this->line('AUTHORIZES_ACTIVATION='.$this->bool((bool) $report['authorizes_activation']));

        /*
         * THE THREE LEVELS, PRINTED SEPARATELY AND BEFORE THE AGGREGATE.
         *
         * One line reading ESTATE_RESILIENCE=FAIL is what sent the owner a
         * four-tablet bill when the actionable sentence was "one tablet at
         * TLK1". These are printed in full, in order, with the aggregate after
         * them and labelled as what it is.
         */
        $this->newLine();
        $policy = $report['capacity_policy'];
        $this->line('-- Capacity levels (nested; a lower level satisfies nothing above it) --');
        $this->line('  STAFFED_BRANCHES='.$this->csvStrings($policy['staffed_branch_codes']));

        foreach ($policy['levels'] as $level) {
            $this->newLine();
            $this->line('  LEVEL '.$level['level'].'  '.strtoupper((string) $level['signal']).'='.$level['status']);
            $this->line('    requires: '.$level['requirement']);
            $this->line('    gates activation testing: '.$this->bool((bool) $level['gates_activation_testing']));

            if (($level['branches_failing'] ?? []) !== []) {
                $this->line('    FAIL at: '.$this->csvStrings($level['branches_failing']));
            }

            if (($level['branches_partial'] ?? []) !== []) {
                $this->line('    PARTIAL at: '.$this->csvStrings($level['branches_partial']));
            }

            if (($level['branches_unverified'] ?? []) !== []) {
                $this->line('    UNVERIFIED at: '.$this->csvStrings($level['branches_unverified']));
            }
        }

        $prerequisite = $report['activation_test_prerequisite'];
        $this->newLine();
        $this->line('OVERALL_ACTIVATION_TEST_PREREQUISITE='.$prerequisite['status']);
        $this->line('  MEASURED='.$prerequisite['measured'].'  ATTESTED='.$this->bool((bool) $prerequisite['attested']));
        $this->line('  composed from: '.$this->csvStrings($prerequisite['composed_from']));

        if ($prerequisite['contradiction']) {
            $this->error('  CONTRADICTION: signed true, measured otherwise.');
        }

        $this->newLine();
        $this->line('ESTATE_RESILIENCE='.$report['verdict']);
        $this->comment('  '.$report['verdict_semantics']);

        $this->newLine();
        $this->comment($report['resilience_semantics']);
    }

    /**
     * @param  list<int>  $ids
     */
    private function csv(array $ids): string
    {
        return $ids === [] ? 'none' : implode(',', $ids);
    }

    /**
     * @param  list<string>  $values
     */
    private function csvStrings(array $values): string
    {
        return $values === [] ? 'none' : implode(',', $values);
    }

    private function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
