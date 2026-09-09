<?php

namespace App\Console\Commands;

use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use Illuminate\Console\Command;

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — could fleet-wide doctor enforcement
 * be switched on without stranding a clinician?
 *
 * `android:phase4a-pilot-scope` answers who the configured scope COVERS.
 * This answers the other half, and the half that decides whether widening that
 * scope is survivable: who is actually PROVISIONED. A doctor can be covered
 * without being provisioned, and that combination is what a lockout looks like.
 *
 * Read-only. It writes nothing, arms nothing and cannot activate global
 * enforcement — and it reports the global state by asking the resolved scope
 * rather than by reading a recorded claim, because a safety line that is true
 * because somebody typed `false` is not a safety line.
 *
 * Safe on production. It prints user ids, doctor codes and display names — an
 * operator has to be able to recognise the person — and deliberately prints no
 * email, no identity number, no credential material and no key.
 */
class DoctorGlobalRolloutReadinessCommand extends Command
{
    protected $signature = 'doctor:rollout-readiness
        {--json : Output the report as JSON}
        {--strict : Exit non-zero unless every target doctor is ready}';

    protected $description = 'Report whether every doctor account could be enforced onto a trusted device: per-doctor paths, per-branch readiness, the enforced cohort and the global posture. Read-only, no secrets.';

    public function handle(DoctorGlobalRolloutReadinessService $readiness): int
    {
        $report = $readiness->build();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        /*
         * NOT_READY means nobody is provisioned at all, which is a broken
         * deployment rather than a rollout in progress, so it fails whether or
         * not --strict was asked for.
         *
         * PARTIAL is the ordinary state of a rollout and exits 0. That is
         * deliberate: this command is meant to be safe to add to a deploy
         * chain, and a gate that reddens for the entire duration of a staged
         * rollout is a gate that gets removed from the chain.
         */
        if ($report['verdict'] === DoctorGlobalRolloutReadinessService::VERDICT_NOT_READY) {
            return 1;
        }

        if ($this->option('strict') && $report['verdict'] !== DoctorGlobalRolloutReadinessService::VERDICT_GLOBAL_READY) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info('Doctor global rollout readiness — DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1');
        $this->newLine();

        $notReady = array_values(array_filter(
            $report['doctors'],
            static fn (array $row): bool => $row['state'] !== DoctorGlobalRolloutReadinessService::STATE_READY,
        ));

        $this->table(
            ['User id', 'Doctor', 'Code', 'State', 'Device', 'Blocking reason'],
            array_map(static fn (array $row): array => [
                $row['user_id'],
                $row['doctor_name'] ?? $row['user_name'],
                $row['doctor_code'] ?? '-',
                $row['state'],
                $row['path']['device_name'] ?? '-',
                $row['reasons'] === [] ? '-' : implode(', ', $row['reasons']),
            ], $report['doctors']),
        );

        if ($report['branches'] !== []) {
            $this->newLine();
            $this->line('-- Readiness by the branch a trusted device sits in --');
            $this->line('   (a doctor may practise at every RME branch, so this is where the HARDWARE is)');
            $this->table(
                ['Branch', 'Devices that could carry a doctor', 'Doctors ready via a device here'],
                array_map(static fn (array $row): array => [
                    $row['branch_code'] ?? '(unassigned)',
                    $row['ready_devices_registered_here'],
                    $row['doctors_ready_via_a_device_here'],
                ], $report['branches']),
            );
        }

        $this->newLine();
        $this->line('TARGET_DOCTOR_COUNT='.$report['target_doctor_count']);
        $this->line('READY_DOCTOR_COUNT='.$report['ready_doctor_count']);
        $this->line('NOT_READY_DOCTOR_COUNT='.$report['not_ready_doctor_count']);
        $this->line('READY_DOCTOR_USER_IDS='.$this->csv($report['ready_doctor_user_ids']));

        $this->newLine();
        $this->line('PILOT_COHORT_USER_IDS='.$this->csv($report['cohort']['user_ids']));
        $this->line('PILOT_COHORT_SIZE='.$report['cohort']['size']);
        $this->line('PILOT_COHORT_MAXIMUM='.$report['cohort']['maximum']);
        $this->line('PILOT_COHORT_ALL_READY='.$this->bool($report['cohort']['all_ready']));
        $this->line('COVERED_BUT_NOT_READY='.$this->csv($report['cohort']['covered_but_not_ready']));

        $this->newLine();
        $this->line('ACTIVE_DEVICE_COUNT='.$report['devices']['active']);
        $this->line('REVOKED_DEVICE_COUNT='.$report['devices']['revoked']);
        $this->line('USABLE_CREDENTIAL_COUNT='.$report['devices']['usable_credentials']);
        $this->line('REVOKED_CREDENTIAL_COUNT='.$report['devices']['revoked_credentials']);

        $this->newLine();
        $this->line('ENFORCEMENT_FLAG_ARMED='.$this->bool($report['runtime']['enforcement_flag_armed']));
        $this->line('WEBAUTHN_LOGIN_ARMED='.$this->bool($report['runtime']['webauthn_login_armed']));
        $this->line('ENFORCEMENT_SCOPE_MODE='.$report['runtime']['enforcement_scope_mode']);
        $this->line('ENFORCEMENT_SCOPE_USABLE='.$this->bool($report['runtime']['enforcement_scope_usable']));
        $this->line('ENFORCEMENT_POSTURE='.$report['runtime']['enforcement_posture']);
        $this->line('GLOBAL_SCOPE_PERMITTED='.$this->bool($report['runtime']['global_scope_permitted']));
        $this->line('GLOBAL_ENFORCEMENT_ACTIVE='.$this->bool($report['runtime']['global_enforcement_active']));

        if ($report['blocking_reasons'] !== []) {
            $this->newLine();
            $this->line('-- What is stopping the '.count($notReady).' doctors who are not ready --');

            foreach ($report['blocking_reasons'] as $reason => $count) {
                $this->line('  '.$reason.'='.$count);
            }
        }

        foreach ($report['findings'] as $finding) {
            $this->newLine();
            $this->warn('FINDING '.$finding['finding'].' (user '.$finding['user_id'].'): '.$finding['detail']);
        }

        $this->newLine();
        $this->line('READINESS_VERDICT='.$report['verdict']);
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
