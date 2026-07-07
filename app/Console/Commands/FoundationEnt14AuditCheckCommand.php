<?php

namespace App\Console\Commands;

use App\Services\Foundation\EntFoundationRuntimeHardeningGovernanceService;
use Illuminate\Console\Command;

class FoundationEnt14AuditCheckCommand extends Command
{
    protected $signature = 'foundation:ent-1-4-audit-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only POST-ENT audit that ENT-1..ENT-4 governance/config/docs locks are complete, GO-tagged and doc-backed.';

    public function handle(EntFoundationRuntimeHardeningGovernanceService $service): int
    {
        $report = $service->collectEntAudit();
        $decision = (string) ($report['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('POST-ENT ENT-1..ENT-4 Foundation Audit');
            $this->line('Decision: '.$decision);
            $this->line('Audited: '.implode(', ', (array) ($report['audited_sprints'] ?? [])));
            $this->line('Runtime backfill required: '.(($report['runtime_backfill_required'] ?? false) ? 'yes' : 'no (governance/config/docs locks)'));
            foreach ((array) ($report['per_sprint'] ?? []) as $id => $s) {
                $this->line(sprintf('  - %s [%s] %s: %s', $id, $s['status'] ?? '?', ($s['ok'] ?? false) ? 'OK' : 'FAIL', $s['title'] ?? ''));
                foreach ((array) ($s['issues'] ?? []) as $i) {
                    $this->line('      ! '.$i);
                }
            }
            $s = $report['summary'];
            $this->line(sprintf('Checks: %d | Passed: %d | Warnings: %d | Errors: %d', $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0));
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
