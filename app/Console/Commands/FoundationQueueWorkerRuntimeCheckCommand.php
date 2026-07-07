<?php

namespace App\Console\Commands;

use App\Services\Foundation\EntFoundationRuntimeHardeningGovernanceService;
use Illuminate\Console\Command;

class FoundationQueueWorkerRuntimeCheckCommand extends Command
{
    protected $signature = 'foundation:queue-worker-runtime-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only POST-ENT queue worker runtime governance check (conservative systemd worker on top of ENT-5).';

    public function handle(EntFoundationRuntimeHardeningGovernanceService $service): int
    {
        $report = $service->collectQueueWorker();
        $decision = (string) ($report['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('POST-ENT Queue Worker Runtime Governance');
            $this->line('Decision: '.$decision);
            $this->line('Service: '.($report['service_name'] ?? '?').' (present: '.(($report['service_file_present'] ?? false) ? 'yes' : 'NO').')');
            $this->line('Queue connection: '.($report['connection'] ?? '?').' (ok: '.(($report['connection_ok'] ?? false) ? 'yes' : 'NO').')');
            $this->line('queue:work present: '.(($report['worker_command_present'] ?? false) ? 'yes' : 'NO'));
            $this->line('No destructive command: '.(($report['no_destructive_command'] ?? false) ? 'yes' : 'NO'));
            $this->line('Queues subset of ENT-5: '.(($report['queues_subset_of_ent5'] ?? false) ? 'yes' : 'NO'));
            $this->line('Failed jobs table: '.($report['failed_jobs_table'] ?? '?'));
            $this->line('Activated by deploy: '.(($report['activated_by_deploy'] ?? false) ? 'YES (unexpected)' : 'no (operator-run, worker-ready)'));
            $nonPassing = array_filter($report['checks'] ?? [], fn (array $c) => ($c['status'] ?? '') !== 'passed');
            foreach ($nonPassing as $c) {
                $this->line(sprintf('  - [%s] %s: %s', $c['status'], $c['check_id'], $c['message']));
            }
        }

        if ($decision === 'FAIL') {
            return self::FAILURE;
        }
        if ($decision === 'WATCH' && ($this->option('strict') || $this->option('fail-on-warning'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
