<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSprintContext;
use App\Support\Devflow\SprintTestPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * DEVFLOW-1 — orchestrate the focused/regression/required test run.
 *
 * Thin orchestrator over the existing test tooling (Pest via `php artisan
 * test`, Pint, git diff --check, build). It NEVER replaces Pest and NEVER
 * hides skipped tests; it exits non-zero on any failure.
 */
final class SprintTestCommand extends Command
{
    use ResolvesSprintContext;

    protected $signature = 'sprint:test
        {--manifest= : Path to the sprint manifest}
        {--changed-files= : Comma/newline-separated changed files (overrides git diff)}
        {--base-sha= : Authoritative exact base commit SHA (overrides remote resolution)}
        {--base-branch= : Canonical base branch to resolve through the canonical remote}
        {--plan : Only print the plan; run nothing}
        {--focused : Run only the focused Pest filters}
        {--regression : Run the focused + related regression suites}
        {--all-required : Run all mandatory gates (pint, diff-check, focused, escalation)}
        {--no-build : Skip the frontend build even if frontend_change=true}
        {--json : Output a JSON result summary}';

    protected $description = 'Run the focused/regression/required test plan. Orchestrates Pest/Pint/build; never hides skips.';

    public function handle(SprintTestPlanner $planner): int
    {
        $manifest = $this->loadManifest();
        $changed = $this->resolveChangedFiles();
        $plan = $planner->plan($changed['files'], $changed['resolved'], $manifest);

        $mode = $this->resolveMode();
        $steps = $this->buildSteps($mode, $plan, $manifest);

        if ($this->option('plan')) {
            $this->line("Mode: {$mode}");
            foreach ($steps as $s) {
                $this->line('  '.implode(' ', $s['cmd']));
            }

            return self::SUCCESS;
        }

        $results = [];
        $failed = false;
        foreach ($steps as $s) {
            $this->line('▶ '.$s['label'].': '.implode(' ', $s['cmd']));
            $exit = $this->runProcess($s['cmd']);
            $results[] = ['label' => $s['label'], 'cmd' => implode(' ', $s['cmd']), 'exit' => $exit];
            if ($exit !== 0) {
                $failed = true;
                if ($s['fail_fast']) {
                    $this->error('Critical step failed — stopping.');
                    break;
                }
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['mode' => $mode, 'failed' => $failed, 'steps' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function resolveMode(): string
    {
        if ($this->option('all-required')) {
            return 'all-required';
        }
        if ($this->option('regression')) {
            return 'regression';
        }

        return 'focused';
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return list<array{label:string,cmd:list<string>,fail_fast:bool}>
     */
    private function buildSteps(string $mode, array $plan, $manifest): array
    {
        $steps = [];

        if ($mode === 'all-required') {
            $steps[] = ['label' => 'pint', 'cmd' => ['./vendor/bin/pint', '--dirty', '--test'], 'fail_fast' => true];
            $steps[] = ['label' => 'git-diff-check', 'cmd' => ['git', 'diff', '--check'], 'fail_fast' => true];
        }

        // Focused / regression Pest filters.
        $filters = (array) $plan['focused_filters'];
        if ($filters !== []) {
            $filterArg = implode('|', $filters);
            $steps[] = ['label' => 'pest-focused', 'cmd' => ['php', 'artisan', 'test', '--filter='.$filterArg], 'fail_fast' => false];
        } else {
            $this->warn('No focused filters resolved from the change set.');
        }

        // Escalation → full required suite.
        if (($mode === 'all-required' || $mode === 'regression') && ($plan['escalate_full_suite'] ?? false)) {
            $steps[] = ['label' => 'pest-full-required', 'cmd' => ['php', 'artisan', 'test'], 'fail_fast' => false];
        }

        // Frontend build when the manifest says so.
        if ($manifest !== null && $manifest->flag('frontend_change') && ! $this->option('no-build') && in_array($mode, ['regression', 'all-required'], true)) {
            $steps[] = ['label' => 'build', 'cmd' => ['npm', 'run', 'build'], 'fail_fast' => true];
        }

        return $steps;
    }

    /**
     * @param  list<string>  $cmd
     */
    private function runProcess(array $cmd): int
    {
        $result = Process::path(base_path())->timeout(3600)->run($cmd, function ($_type, $buffer): void {
            $this->output->write($buffer);
        });

        return $result->exitCode() ?? 1;
    }
}
