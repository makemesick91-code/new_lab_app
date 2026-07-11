<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSprintContext;
use App\Support\Devflow\SprintTestPlanner;
use Illuminate\Console\Command;

/**
 * DEVFLOW-1 — resolve the focused test + CI-escalation plan from the change set.
 */
final class SprintTestPlanCommand extends Command
{
    use ResolvesSprintContext;

    protected $signature = 'sprint:test-plan
        {--manifest= : Path to the sprint manifest}
        {--changed-files= : Comma/newline-separated changed files (overrides git diff)}
        {--base= : Base ref to diff against (default: canonical base branch)}
        {--json : Output JSON}';

    protected $description = 'Compute the focused test plan and CI escalation from git diff + the regression matrix.';

    public function handle(SprintTestPlanner $planner): int
    {
        $manifest = $this->loadManifest();
        $base = is_string($this->option('base')) && $this->option('base') !== '' ? $this->option('base') : null;
        $changed = $this->resolveChangedFiles($base);

        $plan = $planner->plan($changed['files'], $changed['resolved'], $manifest);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan + ['diff_base' => $changed['base'], 'diff_resolved' => $changed['resolved']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Changed files: '.count($plan['changed_files']).' (base: '.$changed['base'].', resolved: '.($changed['resolved'] ? 'yes' : 'no').')');
        $this->line('Impacted categories: '.(implode(', ', $plan['matched_categories']) ?: '(none)'));
        if ($plan['related_categories'] !== []) {
            $this->line('Related categories: '.implode(', ', $plan['related_categories']));
        }
        if ($plan['unmatched_files'] !== []) {
            $this->warn('Unmatched files (escalate): '.implode(', ', $plan['unmatched_files']));
        }
        $this->newLine();
        $this->line('Focused Pest filters: '.(implode(', ', $plan['focused_filters']) ?: '(none)'));
        $this->line('Test paths: '.(implode(', ', $plan['test_paths']) ?: '(none)'));
        $this->line('CI jobs: '.implode(', ', $plan['ci_jobs']));
        $this->newLine();

        if ($plan['escalate_full_suite']) {
            $this->warn('FULL REQUIRED SUITE escalation:');
            foreach ($plan['escalation_reasons'] as $r) {
                $this->warn('  - '.$r);
            }
        } else {
            $this->info('No full-suite escalation — focused plan is sufficient.');
        }

        if ($plan['focused_filters'] !== []) {
            $this->newLine();
            $this->line('Suggested: php artisan sprint:test --focused');
        }

        return self::SUCCESS;
    }
}
