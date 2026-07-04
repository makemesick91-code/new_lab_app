<?php

namespace App\Services\Foundation;

/**
 * NSF-10 — Read-only backup verification.
 *
 * Verifies a database backup file is present, safely located, non-empty, and
 * reasonably sized/aged WITHOUT ever printing dump contents, restoring it, or
 * uploading it anywhere. Path is confined to config('backup_governance')
 * allowed directories to prevent path traversal.
 *
 * Emits GO / WATCH / FAIL:
 *  - FAIL : missing/zero-byte file, outside allowed directory, wrong
 *           extension, or world-writable permissions.
 *  - WATCH: file passes critical checks but optional metadata (freshness,
 *           SQL header) could not be confirmed.
 *  - GO   : all critical and optional checks pass.
 */
class BackupVerificationService
{
    /**
     * @return array<string, mixed>
     */
    public function verify(?string $path): array
    {
        $config = config('backup_governance', []);

        if (! is_array($config) || $config === []) {
            return $this->result(null, [
                $this->fail('BACKUP-CONFIG-EXISTS', 'config/backup_governance.php is missing or empty.'),
            ]);
        }

        if ($path === null || trim($path) === '') {
            return $this->result(null, [
                $this->fail('BACKUP-PATH-PROVIDED', 'No --path was provided to verify.'),
            ]);
        }

        $checks = [];
        $checks[] = $this->pass('BACKUP-CONFIG-EXISTS', 'backup_governance config present.');

        $allowedDirs = array_map(
            fn (string $dir) => rtrim(base_path($dir), DIRECTORY_SEPARATOR),
            (array) ($config['allowed_directories'] ?? [])
        );

        $candidate = str_starts_with($path, '/') ? $path : base_path($path);
        $resolved = realpath($candidate);

        $withinAllowed = $resolved !== false && collect($allowedDirs)->contains(
            fn (string $dir) => $resolved === $dir || str_starts_with($resolved, $dir.DIRECTORY_SEPARATOR)
        );

        $checks[] = $withinAllowed
            ? $this->pass('BACKUP-PATH-ALLOWED', 'Backup path resolves inside an allowed backup directory.')
            : $this->fail('BACKUP-PATH-ALLOWED', 'Backup path is outside allowed directories: '.implode(', ', (array) ($config['allowed_directories'] ?? [])));

        $exists = $resolved !== false && is_file($resolved);
        $checks[] = $exists
            ? $this->pass('BACKUP-FILE-EXISTS', 'Backup file exists.')
            : $this->fail('BACKUP-FILE-EXISTS', 'Backup file does not exist at the given path.');

        if (! $exists || ! $withinAllowed) {
            $checks[] = $this->fail('BACKUP-VERIFICATION-ABORTED', 'Verification aborted: path invalid or file missing.');

            return $this->result($resolved ?: $path, $checks);
        }

        $size = (int) filesize($resolved);
        $checks[] = $size > 0
            ? $this->pass('BACKUP-NOT-EMPTY', 'Backup file is not zero bytes.')
            : $this->fail('BACKUP-NOT-EMPTY', 'Backup file is zero bytes.');

        $minSize = (int) ($config['min_size_bytes'] ?? 1024);
        $checks[] = $size >= $minSize
            ? $this->pass('BACKUP-MIN-SIZE', "Backup file size ({$size} bytes) meets minimum ({$minSize} bytes).")
            : $this->fail('BACKUP-MIN-SIZE', "Backup file size ({$size} bytes) is below configured minimum ({$minSize} bytes).");

        $extension = strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION));
        $allowedExtensions = (array) ($config['allowed_extensions'] ?? ['sql']);
        $checks[] = in_array($extension, $allowedExtensions, true)
            ? $this->pass('BACKUP-EXTENSION-EXPECTED', "Backup file extension .{$extension} is expected.")
            : $this->fail('BACKUP-EXTENSION-EXPECTED', "Backup file extension .{$extension} is not in allowed list: ".implode(', ', $allowedExtensions));

        $perms = fileperms($resolved);
        $worldWritable = $perms !== false && ($perms & 0002) !== 0;
        $checks[] = ! $worldWritable
            ? $this->pass('BACKUP-NOT-WORLD-WRITABLE', 'Backup file is not world-writable.')
            : $this->fail('BACKUP-NOT-WORLD-WRITABLE', 'Backup file is world-writable — unsafe permissions.');

        $mtime = (int) filemtime($resolved);
        $now = time();
        $staleAfter = (int) ($config['stale_after_seconds'] ?? (90 * 24 * 60 * 60));

        if ($mtime > $now + 60) {
            $checks[] = $this->fail('BACKUP-MTIME-REASONABLE', 'Backup file mtime is in the future — clock or file integrity issue.');
        } elseif (($now - $mtime) > $staleAfter) {
            $checks[] = $this->warn('BACKUP-MTIME-REASONABLE', 'Backup file mtime is older than the configured freshness window — treat as stale evidence.');
        } else {
            $checks[] = $this->pass('BACKUP-MTIME-REASONABLE', 'Backup file mtime is within a reasonable window.');
        }

        $checks[] = $this->headerCheck($resolved, $config);

        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'path' => $resolved,
            'size_bytes' => $size,
            'extension' => $extension,
            'mtime' => $mtime > 0 ? date('c', $mtime) : null,
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false, 'dump_contents_read' => false],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function headerCheck(string $resolved, array $config): array
    {
        $sniffBytes = (int) ($config['header_sniff_bytes'] ?? 4096);
        $markers = (array) ($config['sql_header_markers'] ?? []);

        if ($markers === []) {
            return $this->warn('BACKUP-SQL-HEADER', 'No SQL header markers configured to verify.');
        }

        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            return $this->warn('BACKUP-SQL-HEADER', 'Could not open backup file to sniff header.');
        }

        $header = fread($handle, $sniffBytes) ?: '';
        fclose($handle);

        foreach ($markers as $marker) {
            if (str_contains($header, (string) $marker)) {
                return $this->pass('BACKUP-SQL-HEADER', 'Backup file header matches a known SQL dump marker.');
            }
        }

        return $this->warn('BACKUP-SQL-HEADER', 'Backup file header did not match a known SQL dump marker (may still be valid, e.g. custom dump format).');
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private function result(?string $path, array $checks): array
    {
        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));

        return [
            'generated_at' => now()->toIso8601String(),
            'path' => $path,
            'size_bytes' => null,
            'extension' => null,
            'mtime' => null,
            'checks' => $checks,
            'summary' => [
                'decision' => $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO'),
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false, 'dump_contents_read' => false],
        ];
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
