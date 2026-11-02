<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\PerformedTreatment\Services\RmePerformedTreatmentReadinessService;
use Illuminate\Console\Command;

/**
 * ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219 §15) — read-only
 * activation readiness of the doctor's "Tindakan Dilakukan" record: the global
 * switch, the pilot allowlist and, per active RME branch, whether an ON flag
 * reaches it (ADMITTED) or not (DENIED). Counts only; never a patient identity.
 *
 * Exit: 1 when the allowlist is invalid (it admits nothing — never a silent
 * DENY_ALL), 1 when --expect does not match the flag; otherwise 0.
 */
class RmePerformedTreatmentReadinessCommand extends Command
{
    protected $signature = 'rme:performed-treatment-readiness
        {--json : Print the report as JSON}
        {--expect= : Assert the flag state: "on" (must be ARMED) or "off"}';

    protected $description = 'Report doctor performed-treatment pilot readiness (rule 219 §15): flag, pilot allowlist, admitted/denied branches. Read-only.';

    public function handle(RmePerformedTreatmentReadinessService $readiness): int
    {
        // Same operating discipline as every production probe: run it as the
        // runtime user, never root, so it can never leave a root-owned artefact.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->error('Refusing to run as root: run it as the runtime user, e.g. `runuser -u daengtisiams -- php artisan rme:performed-treatment-readiness`.');

            return self::FAILURE;
        }

        $expect = $this->option('expect');
        if ($expect !== null && ! in_array($expect, ['on', 'off'], true)) {
            $this->error('--expect must be "on" or "off".');

            return self::FAILURE;
        }

        $report = $readiness->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('RME DOCTOR PERFORMED TREATMENTS — PILOT READINESS (rule 219 §15)');
            $this->line('VERDICT='.$report['verdict']);
            $this->line('FLAG_ENABLED='.($report['flag']['enabled'] ? 'true' : 'false').' env_resolution='.($report['flag']['env_resolution'] ?? 'n/a'));
            $this->line('ZERO_VALUE_CLOSURE_ENABLED='.($report['zero_value_closure_enabled'] ? 'true' : 'false'));
            $this->line(sprintf(
                'PILOT configuration=%s declared=%s admitted=%s max=%d decision=%s',
                $report['pilot']['configuration_status'],
                implode(',', $report['pilot']['declared_codes']) ?: '(none)',
                implode(',', $report['pilot']['admitted_branch_codes']) ?: '(none)',
                $report['pilot']['max_pilot_branches'],
                $report['pilot']['approval_decision'] ?? 'n/a',
            ));
            foreach ($report['branches'] as $code => $admission) {
                $this->line('BRANCH '.$code.'='.$admission);
            }
            $this->line(sprintf(
                'RECORDS declarations=%d no_treatment=%d performed=%d voided=%d',
                $report['records']['declarations'],
                $report['records']['no_treatment_declarations'],
                $report['records']['performed'],
                $report['records']['voided'],
            ));
        }

        if (! $readiness->configurationValid()) {
            return self::FAILURE;
        }

        if ($expect === 'on' && $report['verdict'] !== RmePerformedTreatmentReadinessService::VERDICT_ARMED) {
            return self::FAILURE;
        }

        if ($expect === 'off' && $report['flag']['enabled']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
