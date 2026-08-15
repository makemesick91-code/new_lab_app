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
        {--base-sha= : Authoritative exact base commit SHA (overrides remote resolution)}
        {--base-branch= : Canonical base branch to resolve through the canonical remote}
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

        // Fail closed: an unresolved canonical base yields an empty change set,
        // and an empty change set audits as trivially coherent. Never emit a
        // scope verdict computed against an unverified base.
        if (! $changed['resolved']) {
            if ($this->option('json')) {
                $this->line((string) json_encode([
                    'decision' => 'NO-GO',
                    'module_count' => 0,
                    'touched_modules' => [],
                    'reasons' => ['canonical base unresolved: '.$changed['reason']],
                    'base_authority' => $changed['base_ref']?->toArray(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                if ($changed['base_ref'] !== null) {
                    $this->reportBaseAuthority($changed['base_ref']);
                }
                $this->error('Scope audit aborted — '.$changed['reason']);
                $this->line('Re-run after `git fetch`, or pass --base-sha with an authoritative exact commit SHA.');
            }

            return self::FAILURE;
        }

        $result = $auditor->audit($manifest, $changed['files']);

        if ($this->option('json')) {
            $this->line((string) json_encode($result + [
                'base_authority' => $changed['base_ref']?->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if ($changed['base_ref'] !== null) {
                $this->reportBaseAuthority($changed['base_ref']);
            }
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
