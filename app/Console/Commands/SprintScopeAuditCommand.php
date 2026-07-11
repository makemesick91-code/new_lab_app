<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSprintContext;
use App\Support\Devflow\SprintScopeAuditor;
use Illuminate\Console\Command;

/**
 * DEVFLOW-1 — audit sprint scope coherence ("one sprint = one outcome").
 */
final class SprintScopeAuditCommand extends Command
{
    use ResolvesSprintContext;

    protected $signature = 'sprint:scope-audit
        {--manifest= : Path to the sprint manifest}
        {--changed-files= : Comma/newline-separated changed files (overrides git diff)}
        {--json : Output JSON}
        {--strict : Return non-zero on WATCH as well as NO-GO}';

    protected $description = 'Check that the change set is a coherent single-outcome scope for the sprint type.';

    public function handle(SprintScopeAuditor $auditor): int
    {
        $manifest = $this->loadManifest();
        if ($manifest === null) {
            $this->error('No readable manifest at '.$this->manifestPath());

            return self::FAILURE;
        }

        $changed = $this->resolveChangedFiles();
        $result = $auditor->audit($manifest, $changed['files']);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Scope decision: '.$result['decision']);
            $this->line('Modules touched: '.$result['module_count'].' ('.(implode(', ', $result['touched_modules']) ?: 'none').')');
            foreach ($result['reasons'] as $r) {
                $result['decision'] === 'GO' ? $this->info('  '.$r) : $this->warn('  '.$r);
            }
        }

        if ($result['decision'] === 'NO-GO') {
            return self::FAILURE;
        }
        if ($result['decision'] === 'WATCH' && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
