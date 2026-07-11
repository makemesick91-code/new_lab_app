<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Support\Devflow\GitChangeInspector;
use App\Support\Devflow\SprintManifest;

/**
 * DEVFLOW-1 — shared manifest/git resolution for sprint:* commands.
 */
trait ResolvesSprintContext
{
    protected function manifestPath(): string
    {
        $option = $this->hasOption('manifest') ? $this->option('manifest') : null;

        if (is_string($option) && $option !== '') {
            return $this->toAbsolute($option);
        }

        return $this->toAbsolute((string) config('devflow.manifest.default_path', '.sprint/current.yml'));
    }

    protected function loadManifest(): ?SprintManifest
    {
        $path = $this->manifestPath();

        if (! is_file($path)) {
            return null;
        }

        try {
            return SprintManifest::fromFile($path);
        } catch (\Throwable $e) {
            $this->error('Manifest parse error: '.$e->getMessage());

            return null;
        }
    }

    protected function gitInspector(): GitChangeInspector
    {
        return new GitChangeInspector(base_path());
    }

    /**
     * Changed files: explicit --changed-files (comma or newline) if provided,
     * else resolved from git against the base branch. Returns [files, resolved].
     *
     * @return array{files:list<string>,resolved:bool,base:string,reason:string}
     */
    protected function resolveChangedFiles(?string $baseRef = null): array
    {
        $explicit = $this->hasOption('changed-files') ? $this->option('changed-files') : null;

        if (is_string($explicit) && $explicit !== '') {
            $files = array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $explicit) ?: [])));

            return ['files' => $files, 'resolved' => true, 'base' => 'explicit', 'reason' => '--changed-files supplied'];
        }

        return $this->gitInspector()->changedFiles($baseRef);
    }

    protected function toAbsolute(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }
}
