<?php

namespace App\Console\Commands;

use App\Services\Architecture\OwnerKpiRegistryService;
use Illuminate\Console\Command;

class ArchitectureOwnerKpiRegistryCommand extends Command
{
    private const UNSAFE_EXIT = 10;

    protected $signature = 'architecture:owner-kpi-registry
        {--json : Output JSON report}
        {--output= : Write report under storage/app/architecture only}
        {--include-aliases : Include alias map (default true)}
        {--no-aliases : Omit alias map}
        {--include-consumers : Include consumer routes/controllers/views (default true)}
        {--no-consumers : Omit consumer details}
        {--only=all : Filter: canonical|aliases|blocked|needs_review|all}';

    protected $description = 'Read-only DMO-2 canonical Owner KPI registry with alias resolution.';

    public function handle(OwnerKpiRegistryService $service): int
    {
        $only = (string) $this->option('only');
        $allowed = ['all', 'canonical', 'aliases', 'blocked', 'needs_review'];

        if (! in_array($only, $allowed, true)) {
            $this->error("Invalid --only [{$only}].");

            return self::FAILURE;
        }

        $report = $service->collect([
            'include_aliases' => ! $this->option('no-aliases'),
            'include_consumers' => ! $this->option('no-consumers'),
            'only' => $only,
        ]);

        $outputPath = $this->resolveOutputPath();
        if ($this->option('output') && $outputPath === null) {
            return self::UNSAFE_EXIT;
        }

        if ($this->option('json')) {
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->line($payload);

            if ($outputPath !== null) {
                $this->writeOutputFile($outputPath, $payload);
            }
        } else {
            $this->printConsole($report);

            if ($outputPath !== null) {
                $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $this->writeOutputFile($outputPath, $encoded);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $s = $report['summary'];
        $this->info('DMO-2 Owner KPI Registry');
        $this->line('Generated: '.$report['generated_at']);
        $this->line(sprintf(
            'Canonical KPIs: %d | Aliases: %d | Duplicates resolved: %d | Blocked: %d | Needs review: %d',
            $s['canonical_owner_kpis'],
            $s['aliases'],
            $s['duplicates_resolved'],
            $s['blocked'],
            $s['needs_review'],
        ));
        $this->line('DMO-M005: '.$report['metadata']['dmo_m005_status']);
    }

    private function resolveOutputPath(): ?string
    {
        $raw = $this->option('output');

        if ($raw === null || $raw === '') {
            return null;
        }

        $architectureRoot = realpath(storage_path('app/architecture')) ?: storage_path('app/architecture');

        if (! is_dir($architectureRoot)) {
            mkdir($architectureRoot, 0775, true);
            $architectureRoot = realpath($architectureRoot) ?: $architectureRoot;
        }

        $candidate = (string) $raw;

        if (! str_starts_with($candidate, '/')) {
            $candidate = ltrim($candidate, '/');
            $prefix = 'storage/app/architecture/';
            if (str_starts_with($candidate, $prefix)) {
                $candidate = substr($candidate, strlen($prefix));
            }
            $candidate = $architectureRoot.'/'.ltrim($candidate, '/');
        }

        $realCandidate = realpath(dirname($candidate));

        if ($realCandidate === false) {
            $parent = dirname($candidate);
            if (! is_dir($parent)) {
                mkdir($parent, 0775, true);
            }
            $realCandidate = realpath($parent);
        }

        $normalizedRoot = rtrim($architectureRoot, DIRECTORY_SEPARATOR);
        $normalizedCandidate = rtrim((string) $realCandidate, DIRECTORY_SEPARATOR);

        if ($normalizedCandidate !== $normalizedRoot && ! str_starts_with($normalizedCandidate.'/', $normalizedRoot.'/')) {
            $this->error('Output must be under storage/app/architecture.');

            return null;
        }

        return $candidate;
    }

    private function writeOutputFile(string $path, string $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $payload);
        $this->info('Wrote: '.$path);
    }
}
