<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\DoctorAccess\Services\DoctorFleetReadinessService;
use App\Modules\DoctorAccess\Support\DoctorFleetReadinessVerdict;
use Illuminate\Console\Command;

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1.
 *
 * `doctor:rollout-readiness` answers whether a trusted path is PROVISIONED.
 * This answers whether the fleet is READY — every doctor locked to a home
 * branch, authorized across the whole eligible estate, and having actually
 * logged in at least once.
 *
 * Read-only. It assigns no branch, authorizes no device, creates no credential,
 * arms no flag and never widens the pilot cohort. A READY verdict authorises a
 * later activation DECISION and nothing else (rule 152, GR-R1/GR-R2).
 */
class DoctorFleetReadinessCommand extends Command
{
    protected $signature = 'doctor:fleet-readiness
        {--json : Output the report as JSON}
        {--strict : Exit non-zero unless the whole fleet is READY}';

    protected $description = 'Fleet rollout readiness: home branch, authorization matrix and proven device logins per doctor';

    public function handle(DoctorFleetReadinessService $readiness): int
    {
        $report = $readiness->build();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        /*
         * NO-GO fails whether or not --strict was asked for. It means nobody
         * clears the gates, or there was nothing to measure — both are broken
         * states rather than a rollout in progress.
         *
         * PARTIAL exits 0 without --strict, matching the sibling command, so
         * this is safe to sit in a deploy chain for the whole duration of a
         * staged rollout. A gate that reddens for months gets deleted.
         */
        if ($report['verdict'] === DoctorFleetReadinessVerdict::NO_GO) {
            return 1;
        }

        if ($this->option('strict') && $report['verdict'] !== DoctorFleetReadinessVerdict::READY) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info('Doctor fleet rollout readiness — DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1');
        $this->newLine();

        $this->table(
            ['Doctor', 'User', 'Home branch', 'Authorized', 'Login proven', 'Blocker', 'Readiness'],
            array_map(static fn (array $row): array => [
                (string) ($row['doctor_name'] ?? $row['user_name'] ?? '?'),
                (string) $row['user_id'],
                $row['home_branch_code'] ?? 'UNSET',
                count($row['authorized_device_ids']).'/'
                    .(count($row['authorized_device_ids']) + count($row['unauthorized_eligible_device_ids'])),
                $row['real_device_login_proven'] ? 'yes ('.$row['real_device_login_count'].')' : 'NO',
                $row['primary_blocker'] ?? '-',
                $row['state'],
            ], $report['doctors']),
        );

        $this->newLine();
        $this->line('ELIGIBLE_DOCTORS='.$report['eligible_doctor_count']);
        $this->line('LOCKED_DOCTORS='.$report['locked_doctor_count']);
        $this->line('UNSET_DOCTORS='.$report['unset_doctor_count']);
        $this->newLine();
        $this->line('ELIGIBLE_TRUSTED_DEVICES='.$report['devices']['eligible_count']);
        $this->line('DEVICE_ESTATE_TOTAL='.$report['devices']['estate_count']);
        $this->line('TRUSTED_DEVICES_WITH_READINESS_PROOF='.$report['devices']['with_readiness_proof_count']);
        $this->line('TRUSTED_DEVICES_WITHOUT_READINESS_PROOF='.$this->csv($report['devices']['without_readiness_proof_ids']));
        $this->newLine();
        $this->line('AUTHORIZATION_TARGET_PAIRS='.$report['authorization_matrix']['target_pairs']);
        $this->line('AUTHORIZATION_ACTIVE_PAIRS='.$report['authorization_matrix']['active_pairs']);
        $this->line('AUTHORIZATION_MISSING_PAIRS='.$report['authorization_matrix']['missing_pairs']);
        $this->line('AUTHORIZATION_DUPLICATE_ACTIVE_PAIRS='.$report['authorization_matrix']['duplicate_active_pairs']);
        $this->newLine();
        $this->line('REAL_DEVICE_READY_DOCTORS='.$report['real_device_ready_doctor_count']);
        $this->line('REAL_DEVICE_NOT_READY_DOCTORS='.$report['real_device_not_ready_doctor_count']);
        $this->line('FLEET_READY_DOCTORS='.$report['fleet_ready_doctor_count']);
        $this->line('FLEET_READY_DOCTOR_USER_IDS='.$this->csv($report['fleet_ready_doctor_user_ids']));

        $this->newLine();
        $this->line('HOME_BRANCH_MATRIX:');
        foreach ($report['home_branch_matrix'] as $code => $count) {
            $this->line('  '.$code.'='.$count);
        }

        if ($report['blocker_tally'] !== []) {
            $this->newLine();
            $this->line('-- What is stopping the fleet --');
            foreach ($report['blocker_tally'] as $blocker => $count) {
                $this->line('  '.$blocker.'='.$count);
            }
        }

        foreach ($report['findings'] as $finding) {
            $this->newLine();
            $this->warn('FINDING '.$finding['finding'].' '.$this->detail($finding));
        }

        $this->newLine();
        $this->line('PROVISIONING_VERDICT='.($report['provisioning']['verdict'] ?? 'unknown'));
        $this->line('GLOBAL_ENFORCEMENT_ACTIVE='.$this->bool((bool) ($report['runtime']['global_enforcement_active'] ?? false)));
        $this->line('AUTHORIZES_ACTIVATION='.$this->bool($report['authorizes_activation']));

        $this->newLine();
        $this->line('FLEET_READINESS='.$report['verdict']);

        /*
         * Printed with the verdict, not buried in --json. The single most
         * likely misreading of this report is that a device proof identifies
         * the clinician, and an operator who only ever reads the last two lines
         * should still see that it does not.
         */
        $this->newLine();
        $this->comment($report['proof_semantics']);
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function detail(array $finding): string
    {
        $parts = [];

        foreach ($finding as $key => $value) {
            if ($key === 'finding') {
                continue;
            }

            $parts[] = $key.'='.(is_scalar($value) ? (string) $value : json_encode($value));
        }

        return $parts === [] ? '' : '('.implode(', ', $parts).')';
    }

    /**
     * @param  list<int>  $ids
     */
    private function csv(array $ids): string
    {
        return $ids === [] ? 'none' : implode(',', $ids);
    }

    private function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
