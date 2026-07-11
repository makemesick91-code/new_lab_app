<?php

declare(strict_types=1);

namespace App\Support\Devflow;

/**
 * DEVFLOW-1 — Shared foundation registry scanner.
 *
 * Read-only posture check for config/shared_foundations.php: every canonical
 * entry's class must exist and its test reference must be present. Kept
 * conservative to avoid false positives blocking sprints:
 *   NO-GO — a canonical class is missing (broken foundation).
 *   WATCH — a test reference is missing, or an entry is malformed.
 *   GO    — all canonical entries intact.
 */
final class SharedFoundationScanner
{
    public function __construct(private readonly string $basePath) {}

    /**
     * @return array{decision:string,entries:list<array<string,mixed>>,summary:array<string,int>}
     */
    public function scan(): array
    {
        $registry = (array) config('shared_foundations.registry', []);
        $entries = [];
        $errors = 0;
        $warnings = 0;

        foreach ($registry as $concern => $def) {
            $status = (string) ($def['status'] ?? 'advisory');
            $class = (string) ($def['canonical_class'] ?? '');
            $testRef = (string) ($def['test_reference'] ?? '');

            $issues = [];
            $classExists = $class !== '' && (class_exists($class) || interface_exists($class));

            if ($status === 'canonical') {
                if (! $classExists) {
                    $issues[] = "canonical class missing: {$class}";
                    $errors++;
                }
                if ($testRef === '' || ! $this->pathExists($testRef)) {
                    $issues[] = "test reference missing: {$testRef}";
                    $warnings++;
                }
            } else {
                // advisory: only warn if a named class is declared but absent.
                if ($class !== '' && ! $classExists && ! $this->looksLikeVendor($class)) {
                    $issues[] = "advisory class not found: {$class}";
                    $warnings++;
                }
            }

            $entries[] = [
                'concern' => (string) $concern,
                'status' => $status,
                'canonical_class' => $class,
                'class_exists' => $classExists,
                'test_reference' => $testRef,
                'ok' => $issues === [],
                'issues' => $issues,
            ];
        }

        $decision = $errors > 0 ? 'NO-GO' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'decision' => $decision,
            'entries' => $entries,
            'summary' => [
                'total' => count($entries),
                'errors' => $errors,
                'warnings' => $warnings,
            ],
        ];
    }

    private function pathExists(string $relative): bool
    {
        $full = $this->basePath.DIRECTORY_SEPARATOR.$relative;

        return file_exists($full);
    }

    private function looksLikeVendor(string $class): bool
    {
        return str_starts_with($class, 'Illuminate\\')
            || str_starts_with($class, 'Symfony\\')
            || str_starts_with($class, 'Barryvdh\\')
            || str_starts_with($class, 'Spatie\\');
    }
}
