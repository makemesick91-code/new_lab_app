<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSprintContext;
use App\Support\Devflow\SprintAuditPlanner;
use Illuminate\Console\Command;

/**
 * DEVFLOW-1 — emit the audit depth + checklist for the current sprint type.
 */
final class SprintAuditPlanCommand extends Command
{
    use ResolvesSprintContext;

    protected $signature = 'sprint:audit-plan
        {--manifest= : Path to the sprint manifest}
        {--changed-files= : Comma/newline-separated changed files (overrides git diff)}
        {--json : Output JSON}';

    protected $description = 'Emit the audit level and inspection checklist scoped to the sprint type + change set.';

    public function handle(SprintAuditPlanner $planner): int
    {
        $manifest = $this->loadManifest();
        if ($manifest === null) {
            $this->error('No readable manifest at '.$this->manifestPath());

            return self::FAILURE;
        }

        $changed = $this->resolveChangedFiles();
        $plan = $planner->plan($manifest, $changed['files']);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line("Sprint type: {$plan['type']}");
        $this->line("Audit level: {$plan['audit_level']} ({$plan['level_name']})");
        $this->newLine();
        $this->line('Inspect:');
        foreach ($plan['inspect'] as $i) {
            $this->line('  - '.$i);
        }
        if ($plan['changed_policies'] !== []) {
            $this->warn('Changed policies: '.implode(', ', $plan['changed_policies']));
        }
        if ($plan['migrations'] !== []) {
            $this->warn('Migrations: '.implode(', ', $plan['migrations']));
        }
        if ($plan['integration_risks'] !== []) {
            $this->newLine();
            $this->warn('Integration risks:');
            foreach ($plan['integration_risks'] as $r) {
                $this->warn('  - '.$r);
            }
        }
        $this->newLine();
        $this->line('Suggested commands:');
        foreach ($plan['suggested_commands'] as $c) {
            $this->line('  '.$c);
        }

        return self::SUCCESS;
    }
}
