<?php

namespace App\Console\Commands;

use App\Services\Architecture\NsfApplicationRulesService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

class ArchitectureNsfGovernanceCheckCommand extends Command
{
    private const UNSAFE_EXIT = 10;

    protected $signature = 'architecture:nsf-governance-check
        {--json : Output JSON report}
        {--output= : Write report under storage/app/architecture only}
        {--strict : Exit non-zero when error-level rule failures exist}
        {--include-dmo : Include live DMO governance alignment status}
        {--include-deploy-gates : Include deploy gate documentation checks}
        {--include-observability : Include observability and pg_stat checks}
        {--include-privacy : Scan output for privacy forbidden terms}';

    protected $description = 'Read-only NSF-6 governance validation against national scale foundation application rules.';

    /**
     * Write a diagnostic without ever polluting stdout in JSON mode.
     */
    private function writeDiagnostic(string $message): void
    {
        $output = $this->getOutput()->getOutput();

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);

            return;
        }

        // Buffered/non-console output has no separate error stream. Nothing was
        // written to stdout on this path, so the caller still never sees
        // malformed JSON, and the non-zero exit code carries the failure.
        $output->writeln($message);
    }

    public function handle(NsfApplicationRulesService $service): int
    {
        $report = $service->collect([
            'strict' => (bool) $this->option('strict'),
            'include_dmo' => (bool) $this->option('include-dmo'),
            'include_deploy_gates' => (bool) $this->option('include-deploy-gates'),
            'include_observability' => (bool) $this->option('include-observability'),
            'include_privacy' => (bool) $this->option('include-privacy'),
        ]);

        $outputPath = $this->resolveOutputPath();
        if ($this->option('output') && $outputPath === null) {
            return self::UNSAFE_EXIT;
        }

        /*
         * Encoding must be checked, never assumed.
         *
         * json_encode() returns false on failure, and `$this->line(false)`
         * emits an empty line while the command still exits 0 — a silent
         * success that hands the caller an empty string to decode. JSON mode
         * must emit valid JSON or fail loudly; it must never emit malformed or
         * partial JSON, and never report success having emitted nothing.
         */
        try {
            $payload = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            $this->writeDiagnostic('Failed to encode the governance report as JSON: '.$exception->getMessage());

            return self::UNSAFE_EXIT;
        }

        if ($this->option('json')) {
            $this->line($payload);
        } else {
            $this->printConsole($report);
        }

        if ($outputPath !== null) {
            $this->writeOutputFile($outputPath, (string) $payload);
        }

        $errors = (int) ($report['summary']['errors'] ?? 0);
        if ($this->option('strict') && $errors > 0) {
            $this->error("NSF governance check failed with {$errors} error(s). Decision: ".$report['summary']['decision']);

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
        $this->info('NSF-6 Governance Check');
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
