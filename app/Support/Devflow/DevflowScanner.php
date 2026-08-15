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

    /**
     * DEVFLOW-FIX-BASE-REF-1 — the canonical base authority is intact.
     *
     * Verifies the contract that makes governance conclusions reproducible:
     * a single explicit canonical remote, an exact-SHA-only pattern, and no
     * configured fallback to a local/main/master/HEAD~1/tag authority.
     *
     * @return array{ok:bool,issues:list<string>,remote:string,fetch_enabled:bool}
     */
    public function baseResolutionPosture(): array
    {
        $issues = [];
        $config = (array) config('devflow.base_resolution', []);

        $remote = trim((string) ($config['remote'] ?? ''));
        if ($remote === '') {
            $issues[] = 'devflow.base_resolution.remote must name the canonical remote explicitly (never auto-selected).';
        }

        $shaPattern = (string) ($config['exact_sha_pattern'] ?? '');
        if ($shaPattern === '') {
            $issues[] = 'devflow.base_resolution.exact_sha_pattern is required so revision expressions cannot pose as exact SHAs.';
        } else {
            // The pattern must accept a real 40-hex id and reject expressions.
            foreach (['HEAD', 'HEAD~1', 'main', '--help', 'abc123'] as $bad) {
                if (@preg_match($shaPattern, $bad) === 1) {
                    $issues[] = "exact_sha_pattern must not accept '{$bad}'.";
                }
            }
            if (@preg_match($shaPattern, str_repeat('a', 40)) !== 1) {
                $issues[] = 'exact_sha_pattern must accept a 40-hex commit id.';
            }
        }

        $branchPattern = (string) ($config['safe_branch_pattern'] ?? '');
        if ($branchPattern === '') {
            $issues[] = 'devflow.base_resolution.safe_branch_pattern is required to block option injection via ref names.';
        } elseif (@preg_match($branchPattern, '--upload-pack=evil') === 1) {
            $issues[] = 'safe_branch_pattern must reject option-like ref names.';
        }

        // The prohibition must be declared, not merely implied by code.
        $forbidden = (array) ($config['forbidden_fallbacks'] ?? []);
        foreach (['local_branch_ref', 'main', 'master'] as $required) {
            if (! in_array($required, $forbidden, true)) {
                $issues[] = "devflow.base_resolution.forbidden_fallbacks must declare '{$required}'.";
            }
        }

        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'remote' => $remote,
            'fetch_enabled' => (bool) ($config['fetch_enabled'] ?? true),
        ];
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
