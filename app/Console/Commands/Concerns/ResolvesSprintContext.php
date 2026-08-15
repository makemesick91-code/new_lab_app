<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Support\Devflow\CanonicalBaseRef;
use App\Support\Devflow\CanonicalBaseRefResolver;
use App\Support\Devflow\GitChangeInspector;
use App\Support\Devflow\SprintManifest;

/**
 * DEVFLOW-1 — shared manifest/git resolution for sprint:* commands.
 * DEVFLOW-FIX-BASE-REF-1 — one canonical, pinned base authority per command.
 *
 * Every sprint:* command resolves the base exactly once through the shared
 * resolver and reuses that pinned SHA for the whole invocation, so a remote
 * branch that advances mid-run cannot change the result.
 */
trait ResolvesSprintContext
{
    private ?GitChangeInspector $sprintGitInspector = null;

    private ?CanonicalBaseRefResolver $sprintBaseResolver = null;

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

    protected function baseResolver(): CanonicalBaseRefResolver
    {
        return $this->sprintBaseResolver ??= new CanonicalBaseRefResolver(base_path());
    }

    protected function gitInspector(): GitChangeInspector
    {
        return $this->sprintGitInspector ??= new GitChangeInspector(base_path(), $this->baseResolver());
    }

    /**
     * The pinned canonical base authority for this command invocation.
     *
     * Honours `--base-sha` (exact SHA) and `--base-branch` when the command
     * declares them; otherwise the configured canonical base branch resolved
     * through the canonical remote.
     */
    protected function canonicalBase(): CanonicalBaseRef
    {
        return $this->baseResolver()->resolve(
            $this->optionOrNull('base-sha'),
            $this->optionOrNull('base-branch'),
        );
    }

    /**
     * Changed files: explicit --changed-files (comma or newline) if provided,
     * else resolved from git against the canonical, pinned base SHA.
     *
     * @return array{files:list<string>,resolved:bool,base:string,reason:string,base_ref:?CanonicalBaseRef}
     */
    protected function resolveChangedFiles(?string $baseRef = null): array
    {
        $explicit = $this->hasOption('changed-files') ? $this->option('changed-files') : null;

        if (is_string($explicit) && $explicit !== '') {
            $files = array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $explicit) ?: [])));

            return [
                'files' => $files,
                'resolved' => true,
                'base' => 'explicit',
                'reason' => '--changed-files supplied',
                'base_ref' => null,
            ];
        }

        $baseRef ??= $this->optionOrNull('base-sha') ?? $this->optionOrNull('base-branch');

        return $this->gitInspector()->changedFiles($baseRef);
    }

    /**
     * Print the base authority so governance evidence is never ambiguous
     * about WHICH commit the conclusion was computed against.
     */
    protected function reportBaseAuthority(CanonicalBaseRef $base): void
    {
        foreach ($base->toKeyValueLines() as $line) {
            $this->line($line);
        }

        foreach ($base->diagnostics as $diagnostic) {
            $this->line('  note: '.$diagnostic);
        }

        if (! $base->resolved) {
            $this->error($base->failureReason ?? 'Canonical base could not be resolved.');
        }
    }

    private function optionOrNull(string $name): ?string
    {
        if (! $this->hasOption($name)) {
            return null;
        }

        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function toAbsolute(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }
}
