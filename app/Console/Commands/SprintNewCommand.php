<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSprintContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * DEVFLOW-1 — scaffold a new sprint manifest + doc skeleton.
 *
 * Generates a validated-shape manifest and a sprint-doc stub so a prompt only
 * needs id/type/module/objective. Creates NO git branch unless --create-branch.
 */
final class SprintNewCommand extends Command
{
    use ResolvesSprintContext;

    protected $signature = 'sprint:new
        {id : Sprint id, e.g. FIX-XYZ}
        {--type=HOTFIX : Sprint type}
        {--module=unknown : Primary module}
        {--title= : Short objective/title}
        {--runtime : Set runtime_change=true}
        {--schema : Set schema_change=true}
        {--frontend : Set frontend_change=true}
        {--security : Set security_impact=true}
        {--deploy : Set deploy_required=true}
        {--output= : Manifest output path (default .sprint/<id>.yml)}
        {--create-branch : Also create a git feature branch (explicit opt-in)}
        {--force : Overwrite an existing manifest}';

    protected $description = 'Scaffold a sprint manifest + doc skeleton (no git branch unless --create-branch).';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $type = strtoupper((string) $this->option('type'));

        if (config("sprint_profiles.types.{$type}") === null) {
            $this->error("Unknown type {$type}. Known: ".implode(', ', array_keys((array) config('sprint_profiles.types'))));

            return self::FAILURE;
        }

        $slug = Str::slug($id);
        $goTag = $slug.'-go';
        $base = (string) config('devflow.manifest.required_base_branch');

        $manifest = [
            'id' => $id,
            'type' => $type,
            'module' => (string) $this->option('module'),
            'title' => (string) ($this->option('title') ?: $id),
            'base_branch' => $base,
            'runtime_change' => (bool) $this->option('runtime'),
            'schema_change' => (bool) $this->option('schema'),
            'frontend_change' => (bool) $this->option('frontend'),
            'security_impact' => (bool) $this->option('security'),
            'branch_isolation_impact' => false,
            'ledger_impact' => false,
            'deploy_required' => (bool) $this->option('deploy'),
            'browser_required' => false,
            'test_profiles' => [],
            'go_tag' => $goTag,
        ];

        $outPath = $this->toAbsolute((string) ($this->option('output') ?: '.sprint/'.$slug.'.yml'));
        if (is_file($outPath) && ! $this->option('force')) {
            $this->error("Manifest already exists: {$outPath} (use --force).");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($outPath));
        File::put($outPath, $this->renderYaml($manifest));
        $this->info("Manifest written: {$outPath}");

        $docPath = base_path('docs/sprints/'.$slug.'.md');
        if (! is_file($docPath) || $this->option('force')) {
            File::ensureDirectoryExists(dirname($docPath));
            File::put($docPath, $this->renderDoc($manifest, $type));
            $this->info("Sprint doc stub written: {$docPath}");
        }

        $this->newLine();
        $this->line('Suggested branch: feature/'.$slug);
        $this->line('Suggested GO tag: '.$goTag);
        $this->line('Audit level: '.(config("sprint_profiles.types.{$type}.audit_level")));
        $this->line('Next: php artisan sprint:manifest-check --manifest='.$outPath);

        if ($this->option('create-branch')) {
            $this->call('sprint:prepare'); // read-only; branch creation left to the operator/git
            $this->warn('Branch creation is intentionally manual: git checkout -b feature/'.$slug);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function renderYaml(array $manifest): string
    {
        $lines = ['# Sprint manifest — '.$manifest['id'], '# Validate: php artisan sprint:manifest-check', ''];
        foreach ($manifest as $k => $v) {
            if (is_bool($v)) {
                $lines[] = "{$k}: ".($v ? 'true' : 'false');
            } elseif (is_array($v)) {
                $lines[] = "{$k}: []";
            } else {
                $lines[] = "{$k}: ".$this->scalar((string) $v);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function scalar(string $v): string
    {
        return preg_match('/[:#]/', $v) === 1 ? '"'.$v.'"' : $v;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function renderDoc(array $manifest, string $type): string
    {
        return implode("\n", [
            '# '.$manifest['id'].' — '.$manifest['title'],
            '',
            '- Type: '.$type,
            '- Module: '.$manifest['module'],
            '- Base branch: '.$manifest['base_branch'],
            '- GO tag: '.$manifest['go_tag'],
            '',
            '## Objective',
            '',
            (string) $manifest['title'],
            '',
            '## Acceptance criteria',
            '',
            '- [ ] ...',
            '',
            '## Notes',
            '',
            'This sprint inherits all recurring rules from docs/engineering/'.strtolower($type === 'FOUNDATION_SPRINT' ? 'foundation-sprint-template' : ($type === 'HOTFIX' ? 'hotfix-runtime-template' : 'sprint-runtime-template')).'.md.',
            '',
        ])."\n";
    }
}
