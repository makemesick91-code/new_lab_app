<?php

namespace App\Console\Commands;

use App\Services\Architecture\DmoApplicationRulesService;
use Illuminate\Console\Command;

class ArchitectureDmoGovernanceCheckCommand extends Command
{
    private const UNSAFE_EXIT = 10;

    /** @var list<string> */
    private const DOMAINS = ['all', 'owner', 'rme', 'cashier', 'inventory', 'lab', 'system'];

    protected $signature = 'architecture:dmo-governance-check
        {--json : Output JSON report}
        {--output= : Write report under storage/app/architecture only}
        {--strict : Exit non-zero when error-level rule failures exist}
        {--domain=all : Filter domain: owner|rme|cashier|inventory|lab|system|all}
        {--include-warnings : Include warning results (default true)}
        {--no-warnings : Omit warning results}';

    protected $description = 'Read-only DMO-2 governance validation against application-level architecture rules.';

    public function handle(DmoApplicationRulesService $service): int
    {
        $domain = (string) $this->option('domain');

        if (! in_array($domain, self::DOMAINS, true)) {
            $this->error("Invalid domain [{$domain}].");

            return self::FAILURE;
        }

        $report = $service->collect([
            'domain' => $domain,
            'include_warnings' => ! $this->option('no-warnings'),
            'strict' => (bool) $this->option('strict'),
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

        $errors = (int) ($report['summary']['errors'] ?? 0);
        if ($this->option('strict') && $errors > 0) {
            $this->error("Governance check failed with {$errors} error(s). Decision: ".$report['summary']['decision']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $s = $report['summary'];
        $this->info('DMO-2 Governance Check');
        $this->line('Generated: '.$report['generated_at']);
        $this->line(sprintf(
            'Rules: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['rules'],
            $s['passed'],
            $s['warnings'],
            $s['errors'],
            $s['decision'],
        ));
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
                mkdir($parent, 0755, true);
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
