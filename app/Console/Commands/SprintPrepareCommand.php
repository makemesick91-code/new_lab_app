<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSprintContext;
use App\Support\Devflow\SprintManifestValidator;
use App\Support\Devflow\SprintTestPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * DEVFLOW-1 — read-only sprint preflight. Mutates nothing. GO/WATCH/NO-GO.
 */
final class SprintPrepareCommand extends Command
{
    use ResolvesSprintContext;

    protected $signature = 'sprint:prepare
        {--manifest= : Path to the sprint manifest}
        {--changed-files= : Comma/newline-separated changed files (overrides git diff)}
        {--json : Output JSON}
        {--verbose-tools : Include full tool version detail}
        {--skip-graphify : Skip the graphify presence check}';

    protected $description = 'Read-only preflight: verify repo, branch, manifest, tools and emit an audit/test plan. Mutates nothing.';

    public function handle(SprintManifestValidator $validator, SprintTestPlanner $planner): int
    {
        $checks = [];
        $errors = 0;
        $warnings = 0;

        $add = function (string $id, string $status, string $message) use (&$checks, &$errors, &$warnings): void {
            $checks[] = compact('id', 'status', 'message');
            $status === 'failed' ? $errors++ : ($status === 'warning' ? $warnings++ : null);
        };

        // 1. Repo + artisan.
        $add('REPO', is_file(base_path('artisan')) ? 'passed' : 'failed', 'artisan present at '.base_path('artisan'));

        // 2. Branch — must not be main/master.
        $git = $this->gitInspector();
        $branch = $git->currentBranch();
        if ($branch === null) {
            $add('BRANCH', 'failed', 'Unable to resolve current branch.');
        } elseif (in_array($branch, ['main', 'master'], true)) {
            $add('BRANCH', 'failed', "On protected branch '{$branch}' — create a feature branch.");
        } else {
            $add('BRANCH', 'passed', "On branch '{$branch}'.");
        }

        // 3. Worktree.
        $add('WORKTREE', $git->isWorktreeClean() ? 'passed' : 'warning', $git->isWorktreeClean() ? 'Worktree clean.' : 'Worktree has uncommitted changes (expected while implementing).');

        // 4. Manifest.
        $manifest = $this->loadManifest();
        if ($manifest === null) {
            $add('MANIFEST', 'failed', 'No readable manifest at '.$this->manifestPath());
        } else {
            $changed = $this->resolveChangedFiles();
            $result = $validator->validate($manifest, $changed['files']);
            $status = ! $result['valid'] ? 'failed' : ($result['warnings'] !== [] ? 'warning' : 'passed');
            $add('MANIFEST', $status, 'Manifest '.($manifest->id() ?? '(no id)').': '.$result['decision'].($result['errors'] ? ' — '.implode('; ', $result['errors']) : ''));
        }

        // 5. Required tools.
        foreach ($this->requiredTools() as $tool => $probe) {
            $version = $this->probe($probe);
            if ($version === null) {
                $add('TOOL_'.strtoupper($tool), 'warning', "{$tool} not detected on PATH.");
            } else {
                $add('TOOL_'.strtoupper($tool), 'passed', $this->option('verbose-tools') ? "{$tool}: {$version}" : "{$tool} present.");
            }
        }

        // 6. Graphify (honest).
        if (! $this->option('skip-graphify')) {
            $g = $this->probe(['graphify', '--version']);
            $add('GRAPHIFY', $g === null ? 'warning' : 'passed', $g === null ? 'graphify CLI not detected (use rg/route:list for impact audit).' : 'graphify present.');
        }

        $decision = $errors > 0 ? 'NO-GO' : ($warnings > 0 ? 'WATCH' : 'GO');

        // Plans (advisory).
        $plan = null;
        if ($manifest !== null) {
            $changed = $this->resolveChangedFiles();
            $plan = $planner->plan($changed['files'], $changed['resolved'], $manifest);
        }

        $payload = [
            'decision' => $decision,
            'branch' => $branch,
            'checks' => $checks,
            'summary' => ['checks' => count($checks), 'errors' => $errors, 'warnings' => $warnings],
            'test_plan' => $plan ? ['focused_filters' => $plan['focused_filters'], 'escalate_full_suite' => $plan['escalate_full_suite']] : null,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('sprint:prepare decision: '.$decision);
            foreach ($checks as $c) {
                $mark = $c['status'] === 'passed' ? '✓' : ($c['status'] === 'warning' ? '!' : '✗');
                $line = "  {$mark} {$c['id']}: {$c['message']}";
                $c['status'] === 'failed' ? $this->error($line) : ($c['status'] === 'warning' ? $this->warn($line) : $this->line($line));
            }
            if ($plan) {
                $this->newLine();
                $this->line('Focused filters: '.(implode(', ', $plan['focused_filters']) ?: '(none)'));
                $this->line('Full-suite escalation: '.($plan['escalate_full_suite'] ? 'YES' : 'no'));
            }
        }

        return $decision === 'NO-GO' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,list<string>>
     */
    private function requiredTools(): array
    {
        return [
            'php' => ['php', '--version'],
            'composer' => ['composer', '--version'],
            'node' => ['node', '--version'],
            'npm' => ['npm', '--version'],
            'psql' => ['psql', '--version'],
            'gh' => ['gh', '--version'],
        ];
    }

    /**
     * @param  list<string>  $cmd
     */
    private function probe(array $cmd): ?string
    {
        try {
            $result = Process::path(base_path())->run($cmd);

            return $result->successful() ? trim(strtok($result->output(), "\n") ?: '') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
