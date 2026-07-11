<?php

declare(strict_types=1);

namespace App\Support\Devflow;

/**
 * DEVFLOW-1 — Foundation posture scanner.
 *
 * Read-only posture checks that the DEVFLOW-1 foundation is intact and that
 * the release wrapper / CI classifier still contain their safety markers and
 * carry no destructive command. All markers/patterns come from config/devflow.php
 * so this class never carries the literal destructive strings inline.
 */
final class DevflowScanner
{
    public function __construct(private readonly string $basePath) {}

    /**
     * Every canonical DEVFLOW-1 file exists.
     *
     * @return array{ok:bool,missing:list<string>,present:list<string>}
     */
    public function filesPosture(): array
    {
        $files = (array) config('devflow.files', []);
        $missing = [];
        $present = [];

        foreach ($files as $key => $relative) {
            if ($this->fileExists((string) $relative)) {
                $present[] = (string) $key;
            } else {
                $missing[] = (string) $key.' ('.$relative.')';
            }
        }

        return ['ok' => $missing === [], 'missing' => $missing, 'present' => $present];
    }

    /**
     * Required safety markers are present in the referenced files.
     *
     * @return array{ok:bool,missing_markers:list<string>}
     */
    public function requiredMarkersPosture(): array
    {
        $requirements = (array) config('devflow.required_markers', []);
        $files = (array) config('devflow.files', []);
        $missing = [];

        foreach ($requirements as $fileKey => $markers) {
            $relative = (string) ($files[$fileKey] ?? '');
            $content = $this->read($relative);
            if ($content === null) {
                $missing[] = "{$fileKey}: file unreadable";

                continue;
            }
            foreach ((array) $markers as $marker) {
                if (! str_contains($content, (string) $marker)) {
                    $missing[] = "{$fileKey}: missing marker '{$marker}'";
                }
            }
        }

        return ['ok' => $missing === [], 'missing_markers' => $missing];
    }

    /**
     * Forbidden destructive markers are absent from the referenced files.
     *
     * @return array{ok:bool,violations:list<string>}
     */
    public function forbiddenMarkersPosture(): array
    {
        $forbidden = (array) config('devflow.forbidden_markers', []);
        $files = (array) config('devflow.files', []);
        $violations = [];

        foreach ($forbidden as $fileKey => $markers) {
            $relative = (string) ($files[$fileKey] ?? '');
            $content = $this->read($relative);
            if ($content === null) {
                continue; // absence handled by requiredMarkers/files posture
            }
            foreach ((array) $markers as $marker) {
                if (str_contains($content, (string) $marker)) {
                    $violations[] = "{$fileKey}: forbidden marker present '{$marker}'";
                }
            }
        }

        return ['ok' => $violations === [], 'violations' => $violations];
    }

    /**
     * The CICD-CTRL-1 safety invariant is intact: docs_only is the only
     * critical-skipping profile and the default profile is unknown_high_risk.
     *
     * @return array{ok:bool,issues:list<string>}
     */
    public function cicdInvariantPosture(): array
    {
        $issues = [];
        $skip = (array) config('ci_runtime_control.skip_critical_profiles', []);
        $default = (string) config('ci_runtime_control.default_profile', '');

        if ($skip !== ['docs_only']) {
            $issues[] = 'skip_critical_profiles must be exactly [docs_only]; got ['.implode(', ', $skip).']';
        }
        if ($default !== 'unknown_high_risk') {
            $issues[] = "default_profile must be unknown_high_risk; got '{$default}'";
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    private function fileExists(string $relative): bool
    {
        return $relative !== '' && file_exists($this->basePath.DIRECTORY_SEPARATOR.$relative);
    }

    private function read(string $relative): ?string
    {
        if ($relative === '') {
            return null;
        }
        $full = $this->basePath.DIRECTORY_SEPARATOR.$relative;

        return is_file($full) && is_readable($full) ? (string) file_get_contents($full) : null;
    }
}
