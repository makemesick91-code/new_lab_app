<?php

namespace App\Console\Commands;

use App\Services\Architecture\FoundationRoadmapService;
use Illuminate\Console\Command;

/**
 * ROADMAP-1 — read-only governance check for the source-locked national
 * foundation expansion roadmap.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (0 with --strict is preserved; WATCH never fails CI by default)
 *  - FAIL  → non-zero (roadmap missing or unsafe order/guardrail violation)
 */
class ArchitectureFoundationRoadmapCheckCommand extends Command
{
    protected $signature = 'architecture:foundation-roadmap-check
        {--json : Output JSON report}
        {--strict : Exit non-zero when decision is WATCH or FAIL}';

    protected $description = 'Read-only validation of the source-locked national foundation expansion roadmap.';

    public function handle(FoundationRoadmapService $service): int
    {
        $report = $service->collect();
        $decision = (string) ($report['summary']['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        if ($decision === 'FAIL') {
            return self::FAILURE;
        }

        if ($decision === 'WATCH' && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $s = $report['summary'];

        $this->info('Foundation Roadmap Check (ROADMAP-1)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('Active track: '.($report['active_track'] ?? 'n/a'));
        $this->line('Next recommended sprint: '.($report['next_recommended_sprint'] ?? 'n/a'));
        $this->line('Total planned sprints: '.($report['total_planned_sprints'] ?? 0));
        $this->line('RC locked after expansion: '.(($report['rc_locked_after_expansion'] ?? false) ? 'yes' : 'no'));
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0,
            $s['passed'] ?? 0,
            $s['warnings'] ?? 0,
            $s['errors'] ?? 0,
            $s['decision'] ?? 'FAIL',
        ));

        $this->newLine();
        $this->line('Approved sequence:');
        foreach ($report['approved_sequence'] ?? [] as $item) {
            $this->line(sprintf('  %2d. %-11s %s', $item['priority'], $item['id'], $item['title']));
        }

        $failing = array_filter($report['checks'] ?? [], fn (array $c) => ($c['status'] ?? '') !== 'passed');
        if ($failing !== []) {
            $this->newLine();
            $this->line('Non-passing checks:');
            foreach ($failing as $check) {
                $this->line(sprintf('  - [%s] %s: %s', $check['status'], $check['check_id'], $check['message']));
            }
        }
    }
}
