<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Models\SatusehatUatRun;
use App\Modules\Satusehat\Models\SatusehatUatScenario;
use App\Modules\Satusehat\Models\SatusehatUatSignoff;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4D — read-only human operator UAT status. PII-free.
 *
 * Reports UAT runs, scenario pass/fail counts, and required-role sign-off
 * coverage. A GO decision requires at least one SIGNED_OFF run — automated tests
 * never substitute for real human sign-off.
 */
class SatusehatUatStatusCommand extends Command
{
    protected $signature = 'satusehat:uat-status {--json}';

    protected $description = 'SATUSEHAT-4D human operator UAT status (read-only)';

    public function handle(): int
    {
        $env = (string) config('satusehat.environment');
        $required = (array) config('satusehat_pilot.uat.required_signoff_roles', []);

        $runs = SatusehatUatRun::query()->where('environment', $env)->orderByDesc('id')->get()
            ->map(function (SatusehatUatRun $run) use ($required) {
                $approved = SatusehatUatSignoff::where('uat_run_id', $run->id)
                    ->where('decision', SatusehatUatSignoff::DECISION_APPROVED)->pluck('role')->all();

                return [
                    'id' => $run->id,
                    'title' => $run->title,
                    'status' => $run->status,
                    'scenarios' => SatusehatUatScenario::where('uat_run_id', $run->id)->count(),
                    'failed_scenarios' => SatusehatUatScenario::where('uat_run_id', $run->id)
                        ->where('outcome', SatusehatUatScenario::OUTCOME_FAIL)->count(),
                    'approved_roles' => $approved,
                    'missing_roles' => array_values(array_diff($required, $approved)),
                ];
            })->all();

        $signedOff = count(array_filter($runs, fn ($r) => $r['status'] === SatusehatUatRun::STATUS_SIGNED_OFF));
        $report = [
            'runs' => $runs,
            'total_runs' => count($runs),
            'signed_off_runs' => $signedOff,
            'human_signoff_required_for_go' => true,
            'note' => 'Automated tests do not substitute for real human operator UAT sign-off.',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($runs as $r) {
            $this->line(sprintf('run=%d "%s" status=%s scenarios=%d failed=%d missing_roles=%s',
                $r['id'], $r['title'], $r['status'], $r['scenarios'], $r['failed_scenarios'],
                $r['missing_roles'] === [] ? 'none' : implode(',', $r['missing_roles'])));
        }
        $this->info(sprintf('signed_off_runs=%d — human sign-off is mandatory for operational GO.', $signedOff));

        return self::SUCCESS;
    }
}
