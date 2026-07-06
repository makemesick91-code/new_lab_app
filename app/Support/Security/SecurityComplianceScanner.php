<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * ENT-9 — read-only Security & PII Compliance scanner.
 *
 * Verifies the enterprise security posture without mutating anything: masking
 * helpers stay present, no raw KTP/NIK display echo leaks into Blade, every
 * data-export route is auth/permission gated, and the audit + branch-isolation
 * primitives remain in place. All regex/field literals come from
 * config('security_compliance') so app code never carries the sensitive
 * patterns inline.
 */
class SecurityComplianceScanner
{
    /**
     * Assert the configured masking helpers exist and the developer-console
     * masker stays enabled.
     *
     * @return array{ok: bool, missing: list<string>, developer_console_masking_enabled: bool}
     */
    public function maskingPosture(): array
    {
        $missing = [];

        foreach ((array) config('security_compliance.masking.helpers', []) as $helper) {
            $class = (string) ($helper['class'] ?? '');
            $method = (string) ($helper['method'] ?? '');

            if ($class === '' || ! class_exists($class) || ($method !== '' && ! method_exists($class, $method))) {
                $missing[] = trim($class.'::'.$method, ':');
            }
        }

        $consoleMasking = (bool) config('developer_console.masking.enabled', false);
        $requireConsoleMasking = (bool) config('security_compliance.masking.require_developer_console_masking', true);

        $ok = $missing === [] && (! $requireConsoleMasking || $consoleMasking);

        return [
            'ok' => $ok,
            'missing' => $missing,
            'developer_console_masking_enabled' => $consoleMasking,
        ];
    }

    /**
     * Scan Blade templates for an unmasked KTP/NIK display echo. Form inputs
     * and value bindings are excluded so the scan flags only display leaks.
     *
     * @return array{ok: bool, findings: list<array{file: string, line: int}>, files_scanned: int}
     */
    public function viewScanPosture(): array
    {
        $config = (array) config('security_compliance.view_scan', []);

        if (($config['enabled'] ?? true) === false) {
            return ['ok' => true, 'findings' => [], 'files_scanned' => 0];
        }

        $forbidden = (array) ($config['forbidden_echo_patterns'] ?? []);
        $exclusions = (array) ($config['exclusion_patterns'] ?? []);
        $findings = [];
        $filesScanned = 0;

        foreach ((array) ($config['paths'] ?? ['resources/views']) as $relative) {
            $root = base_path($relative);
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with((string) $file->getFilename(), '.blade.php')) {
                    continue;
                }

                $filesScanned++;
                $contents = (string) file_get_contents($file->getPathname());
                $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

                foreach ($lines as $index => $line) {
                    if ($this->lineMatchesAny($line, $exclusions)) {
                        continue;
                    }

                    if ($this->lineMatchesAny($line, $forbidden)) {
                        $findings[] = [
                            'file' => ltrim(str_replace(base_path(), '', $file->getPathname()), '/'),
                            'line' => $index + 1,
                        ];
                    }
                }
            }
        }

        return ['ok' => $findings === [], 'findings' => $findings, 'files_scanned' => $filesScanned];
    }

    /**
     * Assert every data-export route carries an auth/permission gate in its
     * fully resolved middleware stack.
     *
     * @return array{ok: bool, ungated: list<string>, export_routes: int}
     */
    public function exportGatingPosture(): array
    {
        $config = (array) config('security_compliance.export_gating', []);

        if (($config['enabled'] ?? true) === false) {
            return ['ok' => true, 'ungated' => [], 'export_routes' => 0];
        }

        $nameFragments = (array) ($config['export_name_fragments'] ?? ['export']);
        $requiredFragments = (array) ($config['required_middleware_fragments'] ?? ['permission:', 'can:', 'auth']);

        $ungated = [];
        $exportRoutes = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            $uri = (string) $route->uri();

            $isExport = false;
            foreach ($nameFragments as $fragment) {
                if (($name !== '' && str_contains($name, (string) $fragment)) || str_contains($uri, (string) $fragment)) {
                    $isExport = true;
                    break;
                }
            }

            if (! $isExport) {
                continue;
            }

            $exportRoutes++;
            $middleware = implode(',', $route->gatherMiddleware());

            $gated = false;
            foreach ($requiredFragments as $fragment) {
                if (str_contains($middleware, (string) $fragment)) {
                    $gated = true;
                    break;
                }
            }

            if (! $gated) {
                $ungated[] = $name !== '' ? $name : $uri;
            }
        }

        return ['ok' => $ungated === [], 'ungated' => $ungated, 'export_routes' => $exportRoutes];
    }

    /**
     * Assert the immutable audit table and branch-isolation context remain.
     *
     * @return array{ok: bool, audit_table_exists: bool, branch_context_exists: bool, never_trust_request_branch_id: bool}
     */
    public function auditAndIsolationPosture(): array
    {
        $auditTable = (string) config('security_compliance.audit.table', 'sys_audit_logs');
        $auditExists = Schema::hasTable($auditTable);

        $contextClass = (string) config('security_compliance.branch_isolation.context_class', '');
        $contextExists = $contextClass !== '' && class_exists($contextClass);
        $neverTrust = (bool) config('security_compliance.branch_isolation.never_trust_request_branch_id', true);

        return [
            'ok' => $auditExists && $contextExists && $neverTrust,
            'audit_table_exists' => $auditExists,
            'branch_context_exists' => $contextExists,
            'never_trust_request_branch_id' => $neverTrust,
        ];
    }

    /**
     * @param  list<string>  $patterns
     */
    private function lineMatchesAny(string $line, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match((string) $pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
