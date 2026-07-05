<?php

namespace App\Services\Foundation;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * NSF-10 — Release evidence artifact capture/check.
 *
 * Closes the NSF-9 RELEASE_SAFETY WATCH by turning "local evidence not yet
 * captured" into a real, repeatable command: capture() re-runs existing
 * read-only governance commands and writes their JSON output as safe,
 * summarized artifacts; check() validates those artifacts exist, are
 * non-empty, safe, and recent enough for the given profile.
 *
 * Every artifact is produced by Artisan::call() against an already-registered
 * governance command — capture never invents data and never mutates state.
 */
class ReleaseEvidenceService
{
    /**
     * @return array<string, mixed>
     */
    public function capture(string $profile, ?string $baseUrl = null, ?string $backupPath = null): array
    {
        $config = config('release_evidence', []);
        $profiles = (array) ($config['profiles'] ?? []);

        if (! array_key_exists($profile, $profiles)) {
            return [
                'generated_at' => now()->toIso8601String(),
                'profile' => $profile,
                'captured' => [],
                'skipped_unsafe' => [],
                'checks' => [$this->fail('EVIDENCE-PROFILE-KNOWN', "Unknown release evidence profile: {$profile}")],
                'summary' => ['decision' => 'FAIL', 'checks' => 1, 'passed' => 0, 'warnings' => 0, 'errors' => 1],
            ];
        }

        $directory = $this->resolveDirectory((string) ($profiles[$profile]['directory'] ?? ''));
        if ($directory === null) {
            return [
                'generated_at' => now()->toIso8601String(),
                'profile' => $profile,
                'captured' => [],
                'skipped_unsafe' => [],
                'checks' => [$this->fail('EVIDENCE-DIRECTORY-SAFE', 'Evidence directory could not be resolved safely under storage/.')],
                'summary' => ['decision' => 'FAIL', 'checks' => 1, 'passed' => 0, 'warnings' => 0, 'errors' => 1],
            ];
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $required = (array) ($profiles[$profile]['required_artifacts'] ?? []);
        $optional = (array) ($profiles[$profile]['optional_artifacts'] ?? []);

        $jobs = $this->buildJobs($profile, $baseUrl, $backupPath);

        $captured = [];
        $skippedUnsafe = [];
        $errors = [];

        foreach ($jobs as $filename => $job) {
            $isRequired = in_array($filename, $required, true);
            $isOptional = in_array($filename, $optional, true);
            if (! $isRequired && ! $isOptional) {
                continue;
            }

            $outcome = $this->runJob($filename, $job, $directory);

            if ($outcome['status'] === 'written') {
                $captured[] = $outcome;
            } elseif ($outcome['status'] === 'unsafe') {
                $skippedUnsafe[] = $outcome;
                if ($isRequired) {
                    $errors[] = "{$filename} was unsafe and not written (required artifact).";
                }
            } else {
                if ($isRequired) {
                    $errors[] = "{$filename} could not be captured: ".$outcome['message'];
                }
            }
        }

        $checks = [];
        $checks[] = $this->pass('EVIDENCE-CAPTURE-RAN', sprintf('Evidence capture ran for profile "%s".', $profile));
        $checks[] = $errors === []
            ? $this->pass('EVIDENCE-REQUIRED-CAPTURED', 'All required artifacts for this profile were captured.')
            : $this->fail('EVIDENCE-REQUIRED-CAPTURED', 'Required artifact capture failure(s): '.implode(' | ', $errors));

        $errorCount = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warningCount = $skippedUnsafe !== [] && $errorCount === 0 ? 1 : 0;
        if ($warningCount > 0) {
            $checks[] = $this->warn('EVIDENCE-OPTIONAL-UNSAFE-SKIPPED', 'Optional artifact(s) skipped for containing unsafe content: '.implode(', ', array_column($skippedUnsafe, 'artifact')));
        }

        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));
        $decision = $errorCount > 0 ? 'FAIL' : ($warningCount > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'profile' => $profile,
            'directory' => $this->relativePath($directory),
            'base_url' => $baseUrl,
            'backup_path' => $backupPath,
            'captured' => $captured,
            'skipped_unsafe' => $skippedUnsafe,
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warningCount,
                'errors' => $errorCount,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $profile): array
    {
        $config = config('release_evidence', []);
        $profiles = (array) ($config['profiles'] ?? []);

        if (! array_key_exists($profile, $profiles)) {
            return $this->finalize($profile, null, [
                $this->fail('EVIDENCE-PROFILE-KNOWN', "Unknown release evidence profile: {$profile}"),
            ], []);
        }

        $directory = $this->resolveDirectory((string) ($profiles[$profile]['directory'] ?? ''));
        $required = (array) ($profiles[$profile]['required_artifacts'] ?? []);
        $optional = (array) ($profiles[$profile]['optional_artifacts'] ?? []);
        $maxAge = $profiles[$profile]['max_age_seconds'] ?? null;

        $checks = [];
        $artifactReports = [];

        foreach ($required as $filename) {
            [$check, $report] = $this->inspectArtifact($directory, $filename, $maxAge, required: true);
            $checks[] = $check;
            $artifactReports[] = $report;
        }

        foreach ($optional as $filename) {
            [$check, $report] = $this->inspectArtifact($directory, $filename, $maxAge, required: false);
            $checks[] = $check;
            $artifactReports[] = $report;
        }

        if ($required === [] && $optional === []) {
            $checks[] = $this->pass('EVIDENCE-PROFILE-NO-REQUIREMENTS', "Profile \"{$profile}\" has no required or optional artifacts configured.");
        }

        return $this->finalize($profile, $directory, $checks, $artifactReports);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  list<array<string, mixed>>  $artifacts
     * @return array<string, mixed>
     */
    private function finalize(string $profile, ?string $directory, array $checks, array $artifacts): array
    {
        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'profile' => $profile,
            'directory' => $directory !== null ? $this->relativePath($directory) : null,
            'artifacts' => $artifacts,
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function inspectArtifact(?string $directory, string $filename, ?int $maxAge, bool $required): array
    {
        $path = $directory !== null ? $directory.DIRECTORY_SEPARATOR.$filename : null;
        $exists = $path !== null && is_file($path);

        $report = [
            'artifact' => $filename,
            'required' => $required,
            'exists' => $exists,
            'size_bytes' => $exists ? filesize($path) : null,
            'age_seconds' => $exists ? (time() - filemtime($path)) : null,
        ];

        if (! $exists) {
            $check = $required
                ? $this->fail("EVIDENCE-ARTIFACT-{$this->slug($filename)}", "Required artifact missing: {$filename}")
                : $this->warn("EVIDENCE-ARTIFACT-{$this->slug($filename)}", "Optional artifact missing: {$filename}");

            return [$check, $report];
        }

        $contents = (string) file_get_contents($path);
        $report['size_bytes'] = strlen($contents);

        if (trim($contents) === '') {
            $check = $required
                ? $this->fail("EVIDENCE-ARTIFACT-{$this->slug($filename)}", "Required artifact is empty: {$filename}")
                : $this->warn("EVIDENCE-ARTIFACT-{$this->slug($filename)}", "Optional artifact is empty: {$filename}");

            return [$check, $report];
        }

        if (! $this->isSafe($contents)) {
            $check = $this->fail("EVIDENCE-ARTIFACT-{$this->slug($filename)}", "Artifact failed safety scan: {$filename}");

            return [$check, $report];
        }

        if ($required && $maxAge !== null && $report['age_seconds'] > $maxAge) {
            $check = $this->fail("EVIDENCE-ARTIFACT-{$this->slug($filename)}", "Required artifact is stale (older than {$maxAge}s): {$filename}");

            return [$check, $report];
        }

        $check = $this->pass("EVIDENCE-ARTIFACT-{$this->slug($filename)}", "Artifact present, non-empty, safe, and fresh: {$filename}");

        return [$check, $report];
    }

    /**
     * @return array<string, array{command: string, arguments: array<string, mixed>, raw_output: bool}>
     */
    private function buildJobs(string $profile, ?string $baseUrl, ?string $backupPath): array
    {
        $jobs = [
            'foundation-roadmap-check.json' => [
                'command' => 'architecture:foundation-roadmap-check',
                'arguments' => ['--json' => true],
            ],
            'feature-flags.json' => [
                'command' => 'foundation:feature-flags',
                'arguments' => ['--json' => true],
            ],
            'cache-governance-check.json' => [
                'command' => 'foundation:cache-governance-check',
                'arguments' => ['--json' => true],
            ],
            'queue-governance-check.json' => [
                'command' => 'foundation:queue-governance-check',
                'arguments' => ['--json' => true],
            ],
            'idempotency-audit.json' => [
                'command' => 'foundation:idempotency-audit',
                'arguments' => ['--json' => true],
            ],
            'outbox-audit.json' => [
                'command' => 'foundation:outbox-audit',
                'arguments' => ['--json' => true],
            ],
            'db-performance-check.json' => [
                'command' => 'foundation:db-performance-check',
                'arguments' => $profile === 'vps'
                    ? ['--json' => true, '--include-db-stats' => true]
                    : ['--json' => true],
            ],
            'automated-smoke.json' => [
                'command' => 'release:automated-smoke',
                'arguments' => ['--json' => true],
            ],
            'foundation-governance-summary.json' => [
                'command' => 'architecture:foundation-governance-summary',
                'arguments' => ['--json' => true],
            ],
            'nsf-governance-check.json' => [
                'command' => 'architecture:nsf-governance-check',
                'arguments' => $profile === 'vps'
                    ? ['--json' => true, '--include-observability' => true]
                    : ['--json' => true],
            ],
        ];

        if ($profile === 'vps') {
            $jobs['backup-verify.json'] = [
                'command' => 'foundation:backup-verify',
                'arguments' => ['--json' => true, '--path' => $backupPath],
            ];
            $jobs['deploy-runtime.json'] = [
                'synthetic' => 'deploy_runtime',
                'backup_path' => $backupPath,
            ];
            $jobs['dmo-governance-check.json'] = [
                'command' => 'architecture:dmo-governance-check',
                'arguments' => ['--json' => true],
            ];
            $jobs['dq-audits.txt'] = [
                'synthetic' => 'dq_audits',
            ];

            if ($baseUrl !== null && $baseUrl !== '') {
                $jobs['automated-smoke-http.json'] = [
                    'command' => 'release:automated-smoke',
                    'arguments' => ['--json' => true, '--base-url' => $baseUrl],
                ];
            }

            // Captured last (after every other vps artifact exists) so its
            // own embedded evidence-chain snapshot is only ever pessimistic
            // about itself (self-reference is unavoidable) — never about a
            // sibling artifact ordering gap. The authoritative decision for
            // the deploy gate is the standalone `foundation:release-safety-check
            // --profile=vps` run executed after capture, not this snapshot.
            $jobs['release-safety-check.json'] = [
                'command' => 'foundation:release-safety-check',
                'arguments' => ['--json' => true, '--profile' => 'vps'],
            ];
        }

        return $jobs;
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function runJob(string $filename, array $job, string $directory): array
    {
        try {
            if (($job['synthetic'] ?? null) === 'deploy_runtime') {
                $payload = $this->buildDeployRuntimeArtifact((string) ($job['backup_path'] ?? ''));
                $contents = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } elseif (($job['synthetic'] ?? null) === 'dq_audits') {
                $contents = $this->buildDqAuditsArtifact();
            } else {
                // A dedicated BufferedOutput (not the shared Artisan::output()
                // facade buffer) is required here: some governance commands
                // (e.g. nsf-governance-check --include-observability, and
                // foundation-governance-summary which includes it internally)
                // themselves issue a nested Artisan::call(), which overwrites
                // the shared "last output" buffer and would otherwise drain
                // empty content back to this outer call.
                $buffer = new BufferedOutput;
                Artisan::call((string) $job['command'], (array) $job['arguments'], $buffer);
                $contents = $buffer->fetch();
            }
        } catch (Throwable $e) {
            return ['artifact' => $filename, 'status' => 'error', 'message' => $e->getMessage()];
        }

        $maxBytes = (int) config('release_evidence.max_artifact_bytes', 2 * 1024 * 1024);
        if (strlen($contents) > $maxBytes) {
            $contents = substr($contents, 0, $maxBytes);
        }

        if (! $this->isSafe($contents)) {
            return ['artifact' => $filename, 'status' => 'unsafe', 'message' => 'Content matched a forbidden pattern.'];
        }

        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $contents);

        return [
            'artifact' => $filename,
            'status' => 'written',
            'bytes' => strlen($contents),
            'path' => $this->relativePath($path),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDeployRuntimeArtifact(string $backupPath): array
    {
        $nodeVersion = $this->safeShellVersion('node --version');
        $npmVersion = $this->safeShellVersion('npm --version');
        $goTag = $this->safeShellVersion('git describe --tags --exact-match HEAD 2>/dev/null');
        $commit = $this->safeShellVersion('git rev-parse --short HEAD');

        $backupSize = ($backupPath !== '' && is_file($backupPath)) ? filesize($backupPath) : null;

        return [
            'generated_at' => now()->toIso8601String(),
            'node_version' => $nodeVersion,
            'npm_version' => $npmVersion,
            'php_version' => PHP_VERSION,
            'laravel_version' => Application::VERSION,
            'go_tag' => $goTag,
            'commit' => $commit,
            'backup_path' => $backupPath !== '' ? basename($backupPath) : null,
            'backup_size_bytes' => $backupSize,
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function safeShellVersion(string $command): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $output = @shell_exec($command);

        return $output !== null ? trim($output) : null;
    }

    private function buildDqAuditsArtifact(): string
    {
        $lines = ['DQ audits summary (NSF-10 evidence)', 'generated_at='.now()->toIso8601String(), ''];

        foreach ([
            'data-quality:dq1-audit' => [],
            'inventory:batch-governance-audit' => [],
            'inventory:source-document-batch-audit' => [],
        ] as $command => $arguments) {
            try {
                $buffer = new BufferedOutput;
                Artisan::call($command, $arguments, $buffer);
                $lines[] = "--- {$command} ---";
                $lines[] = trim($buffer->fetch());
                $lines[] = '';
            } catch (Throwable) {
                $lines[] = "--- {$command} (error) ---";
                $lines[] = 'Command unavailable in this environment.';
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    private function isSafe(string $contents): bool
    {
        $forbidden = (array) config('release_evidence.forbidden_patterns', []);
        foreach ($forbidden as $needle) {
            if ($needle !== '' && str_contains($contents, (string) $needle)) {
                return false;
            }
        }

        $forbiddenRegex = (array) config('release_evidence.forbidden_regex', []);
        foreach ($forbiddenRegex as $pattern) {
            if (@preg_match((string) $pattern, $contents) === 1) {
                return false;
            }
        }

        return true;
    }

    private function resolveDirectory(string $relative): ?string
    {
        if ($relative === '') {
            return null;
        }

        $storageRoot = rtrim(storage_path(), DIRECTORY_SEPARATOR);
        $target = rtrim(base_path($relative), DIRECTORY_SEPARATOR);

        if ($target !== $storageRoot && ! str_starts_with($target.DIRECTORY_SEPARATOR, $storageRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $target;
    }

    private function relativePath(string $absolute): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
    }

    private function slug(string $filename): string
    {
        return strtoupper(str_replace(['.', '-'], ['_', '-'], pathinfo($filename, PATHINFO_FILENAME)));
    }

    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'blocking' => false, 'message' => $message];
    }

    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
