<?php

namespace App\Console\Commands;

use App\Support\Deploy\ProductionShellCommandGuard;
use Illuminate\Console\Command;

/**
 * The mechanical half of the "no interactive REPL on production" rule.
 *
 * Run it two ways:
 *
 *   --command="…"  before executing one command, so a forbidden invocation is
 *                  refused rather than run and then regretted.
 *   (no options)   over the tracked deploy and release scripts, so a violation
 *                  is caught at review time instead of at deploy time.
 *
 * Exits non-zero on a violation with no --strict escape hatch, because a
 * safety gate an operator can talk past is a suggestion.
 */
class DeployForbiddenCommandCheckCommand extends Command
{
    protected $signature = 'deploy:forbidden-command-check
        {--command= : A single command line to check before executing it}
        {--json : Emit machine-readable output}';

    protected $description = 'Refuse an interactive-REPL invocation before it reaches production (scans the deploy/release scripts, or one supplied command).';

    public function handle(ProductionShellCommandGuard $guard): int
    {
        $command = (string) $this->option('command');

        $result = $command !== ''
            ? $this->inspectOne($guard, $command)
            : $guard->scan();

        if ($this->option('json')) {
            $this->line((string) json_encode($result + ['reason' => $guard->reason()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'PASS' ? self::SUCCESS : self::FAILURE;
        }

        foreach ($result['findings'] as $finding) {
            $this->error(sprintf(
                'FORBIDDEN [%s] %s:%d  %s',
                $finding['pattern'],
                $finding['file'],
                $finding['line'],
                $finding['excerpt'],
            ));
        }

        foreach ($result['files_missing'] as $missing) {
            $this->error("SCAN TARGET MISSING: {$missing}");
        }

        if ($result['status'] !== 'PASS') {
            $this->newLine();
            $this->error($guard->reason());
            $this->line('Use a purpose-built read-only command instead. Nothing here is bypassable with a flag.');

            return self::FAILURE;
        }

        $this->info($command !== ''
            ? 'Command is permitted.'
            : sprintf('No forbidden invocation in %d deploy/release scripts.', $result['files_scanned']));

        return self::SUCCESS;
    }

    /**
     * @return array{status:string,findings:array<int,array{file:string,line:int,pattern:string,excerpt:string}>,files_scanned:int,files_missing:array<int,string>}
     */
    private function inspectOne(ProductionShellCommandGuard $guard, string $command): array
    {
        $findings = array_map(fn (string $pattern): array => [
            'file' => '--command',
            'line' => 1,
            'pattern' => $pattern,
            'excerpt' => $guard->excerpt($command),
        ], $guard->matchedPatterns($command));

        return [
            'status' => $findings === [] ? 'PASS' : 'FAIL',
            'findings' => $findings,
            'files_scanned' => 0,
            'files_missing' => [],
        ];
    }
}
