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
        {--base-sha= : Authoritative exact base commit SHA (overrides remote resolution)}
        {--base-branch= : Canonical base branch to resolve through the canonical remote}
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
        $changed = null;
        if (! $this->option('no-diff-check')) {
            $changed = $this->resolveChangedFiles();

            // Fail closed: an unresolved canonical base would otherwise present
            // an EMPTY change set, which silently passes every contradiction
            // check. Never validate a manifest against an unverified base.
            if (! $changed['resolved']) {
                if ($this->option('json')) {
                    $this->line((string) json_encode([
                        'valid' => false,
                        'decision' => 'NO-GO',
                        'errors' => ['canonical base unresolved: '.$changed['reason']],
                        'warnings' => [],
                        'base_authority' => $changed['base_ref']?->toArray(),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    if ($changed['base_ref'] !== null) {
                        $this->reportBaseAuthority($changed['base_ref']);
                    }
                    $this->error('Manifest check aborted — '.$changed['reason']);
                    $this->line('Re-run after `git fetch`, or pass --base-sha with an authoritative exact commit SHA.');
                }

                return self::FAILURE;
            }

            $changedFiles = $changed['files'];
        }

        $result = $validator->validate($manifest, $changedFiles);

        if ($this->option('json')) {
            $this->line((string) json_encode($result + [
                'manifest' => $manifest->data,
                'base_authority' => $changed['base_ref']?->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Manifest: '.($manifest->id() ?? '(no id)').' ['.($manifest->type() ?? '(no type)').']');
            if ($changed !== null && $changed['base_ref'] !== null) {
                $this->reportBaseAuthority($changed['base_ref']);
            }
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
