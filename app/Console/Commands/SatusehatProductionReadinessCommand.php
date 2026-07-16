<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Services\SatusehatProductionReadinessService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-3 — read-only production-readiness report. Reports each readiness
 * category WITHOUT enabling anything. No network, no PII. This never claims
 * external readiness as complete; production stays blocked.
 */
class SatusehatProductionReadinessCommand extends Command
{
    protected $signature = 'satusehat:production-readiness
        {--json : Emit machine-readable JSON}';

    protected $description = 'Report SATUSEHAT production readiness (read-only; production stays blocked).';

    public function handle(SatusehatProductionReadinessService $service): int
    {
        $report = $service->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('SATUSEHAT-3 Production Readiness');
        $this->line('Environment: '.$report['environment']);
        $this->line('Production activation allowed: '.($report['production_allowed'] ? 'YES' : 'NO (blocked)'));
        foreach ($report['categories'] as $c) {
            $this->line(sprintf('  [%s] %-40s %s', strtoupper($c['kind']), $c['label'], $c['status']));
        }
        $this->line('Summary: '.json_encode($report['summary']));

        return self::SUCCESS;
    }
}
