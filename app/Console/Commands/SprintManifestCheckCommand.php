<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSprintContext;
use App\Support\Devflow\SprintManifestValidator;
use Illuminate\Console\Command;

/**
 * DEVFLOW-1 — validate a sprint manifest (schema + type + diff consistency).
 */
final class SprintManifestCheckCommand extends Command
{
    use ResolvesSprintContext;

    protected $signature = 'sprint:manifest-check
        {--manifest= : Path to the sprint manifest (default .sprint/current.yml)}
        {--changed-files= : Comma/newline-separated changed files (overrides git diff)}
        {--no-diff-check : Skip the manifest-vs-diff contradiction checks}
        {--json : Output JSON}
        {--strict : Return non-zero on WATCH as well as NO-GO}';

    protected $description = 'Validate the sprint manifest against the schema, type and (optionally) the change set.';

    public function handle(SprintManifestValidator $validator): int
    {
        $manifest = $this->loadManifest();

        if ($manifest === null) {
            $this->fail('No readable manifest at '.$this->manifestPath());

            return self::FAILURE;
        }

        $changedFiles = null;
        if (! $this->option('no-diff-check')) {
            $changedFiles = $this->resolveChangedFiles()['files'];
        }

        $result = $validator->validate($manifest, $changedFiles);

        if ($this->option('json')) {
            $this->line((string) json_encode($result + ['manifest' => $manifest->data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Manifest: '.($manifest->id() ?? '(no id)').' ['.($manifest->type() ?? '(no type)').']');
            $this->line('Decision: '.$result['decision']);
            foreach ($result['errors'] as $e) {
                $this->error('  ✗ '.$e);
            }
            foreach ($result['warnings'] as $w) {
                $this->warn('  ! '.$w);
            }
            if ($result['errors'] === [] && $result['warnings'] === []) {
                $this->info('  ✓ manifest is valid and consistent.');
            }
        }

        if (! $result['valid']) {
            return self::FAILURE;
        }
        if ($result['decision'] === 'WATCH' && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
