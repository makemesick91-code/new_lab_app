<?php

namespace App\Services\Foundation;

use App\Support\DeveloperConsole\SensitiveValueMasker;
use Throwable;

/**
 * ROLL-5-1A — Staging Restore-Drill Evidence parser/validator.
 *
 * Read-only. Locates the canonical restore-drill evidence JSON, validates its
 * schema, asserts the drill was SAFE (never a production overwrite, never a
 * production-like environment, no leaked secret/KTP/NIK), and returns a single
 * GO | WATCH | FAIL | UNKNOWN status with sanitized details + a remediation hint.
 *
 * Guarantees:
 *  - Never restores or mutates anything. Validates existing evidence only.
 *  - Never throws out of evaluate(): all IO is guarded.
 *  - Never echoes secrets, env values, DB credentials, tokens, KTP/NIK, raw
 *    patient rows, or raw dumps — every free-text value is masked and the
 *    verification map is whitelisted to known keys/enum values.
 *  - Missing evidence => WATCH (never a fake GO). Unsafe evidence => FAIL.
 *
 * This validates evidence for a CONTROLLED staging restore drill — it does NOT
 * perform DR restore, and it is NOT a full DR certification.
 */
class RestoreDrillEvidenceService
{
    public const GO = 'GO';

    public const WATCH = 'WATCH';

    public const FAIL = 'FAIL';

    public const UNKNOWN = 'UNKNOWN';

    /** @var array<int, string> */
    private const VERIFICATION_ENUM = [self::GO, self::WATCH, self::FAIL, self::UNKNOWN];

    public function __construct(private readonly SensitiveValueMasker $masker) {}

    /**
     * Evaluate the latest (or an explicit) restore-drill evidence file.
     *
     * @return array{status: string, unsafe: bool, summary: string, remediation: ?string, decision: string, details: array<string, mixed>}
     */
    public function evaluate(?string $path = null): array
    {
        try {
            return $this->doEvaluate($path);
        } catch (Throwable $e) {
            return [
                'status' => self::UNKNOWN,
                'unsafe' => false,
                'summary' => 'bukti uji restore tidak dapat dievaluasi dengan aman',
                'remediation' => 'Periksa log; evaluasi bukti gagal dengan aman (tidak memblokir).',
                'decision' => self::UNKNOWN,
                'details' => [
                    'evidence_present' => false,
                    'error_class' => $this->masker->mask(get_class($e)),
                ],
            ];
        }
    }

    /**
     * @return array{status: string, unsafe: bool, summary: string, remediation: ?string, decision: string, details: array<string, mixed>}
     */
    private function doEvaluate(?string $path): array
    {
        $runbook = (string) config('rollout_readiness.paths.restore_drill_runbook');
        $found = $this->locateEvidence($path);

        if ($found === null) {
            return [
                'status' => self::WATCH,
                'unsafe' => false,
                'summary' => 'belum ada bukti uji restore — jalankan drill staging sesuai runbook',
                'remediation' => 'Lakukan restore drill ke DB staging/disposable (bukan produksi) sesuai `'.$runbook.'`, lalu validasi dengan `php artisan rollout:restore-drill-evidence --strict`.',
                'decision' => self::WATCH,
                'details' => [
                    'evidence_present' => false,
                    'runbook_present' => is_file(base_path($runbook)),
                    'checked_paths' => $this->candidatePaths($path),
                ],
            ];
        }

        $raw = (string) @file_get_contents($found);
        $basename = basename($found);

        // Secret / PII scan on the RAW payload BEFORE trusting any field. A leak
        // is an unsafe FAIL and the offending content is never echoed back.
        $leak = $this->detectSensitiveLeak($raw);
        if ($leak !== null) {
            return $this->fail(
                'bukti uji restore ditolak: terdeteksi pola sensitif ('.$leak.')',
                'Hapus data sensitif dari bukti (tanpa rahasia/KTP/NIK/dump mentah), lalu buat ulang bukti.',
                ['evidence_present' => true, 'evidence_file' => $basename, 'schema_valid' => false, 'issues' => ['leaked_'.$leak]],
                unsafe: true,
            );
        }

        $data = json_decode($raw, true);

        // A blank --create-template placeholder is incomplete evidence, not a
        // malformed real drill: report WATCH (never GO, never a hard FAIL).
        if (is_array($data) && str_contains(strtoupper((string) ($data['drill_id'] ?? '')), 'TEMPLATE')) {
            return [
                'status' => self::WATCH,
                'unsafe' => false,
                'summary' => 'template bukti uji restore (placeholder) — belum ada drill nyata',
                'remediation' => 'Isi bukti dari drill staging/disposable nyata, lalu jalankan `php artisan rollout:restore-drill-evidence --strict`.',
                'decision' => self::WATCH,
                'details' => ['evidence_present' => true, 'evidence_file' => $basename, 'schema_valid' => false, 'issues' => ['template_placeholder']],
            ];
        }

        if (! is_array($data)) {
            return $this->fail(
                'bukti uji restore tidak valid: JSON tidak dapat diurai',
                'Perbaiki format JSON bukti sesuai `'.(string) config('rollout_readiness.restore_drill.evidence_template_doc').'`.',
                ['evidence_present' => true, 'evidence_file' => $basename, 'schema_valid' => false, 'issues' => ['invalid_json']],
            );
        }

        $schemaIssues = $this->schemaIssues($data);
        if ($schemaIssues !== []) {
            return $this->fail(
                'bukti uji restore tidak valid: skema tidak lengkap',
                'Lengkapi field wajib bukti sesuai template restore-drill.',
                ['evidence_present' => true, 'evidence_file' => $basename, 'schema_valid' => false, 'issues' => $schemaIssues],
            );
        }

        // Sanitized, whitelisted details for safe rendering.
        $details = $this->sanitizedDetails($data, $basename);

        // --- Safety-critical FAILs (unsafe) ---
        if (($data['production_overwrite'] ?? null) !== false) {
            return $this->fail(
                'bukti uji restore TIDAK AMAN: production_overwrite bukan false',
                'Restore drill tidak boleh menyentuh DB produksi. Ulangi drill ke DB disposable/staging.',
                array_merge($details, ['issues' => ['production_overwrite_not_false']]),
                unsafe: true,
            );
        }

        $env = strtolower(trim((string) ($data['environment'] ?? '')));
        $forbidden = array_map('strtolower', (array) config('rollout_readiness.restore_drill.forbidden_environments', []));
        if (in_array($env, $forbidden, true)) {
            return $this->fail(
                'bukti uji restore TIDAK AMAN: environment produksi ('.$this->masker->mask($env).')',
                'Restore drill harus di environment staging/test, bukan produksi/pilot/live.',
                array_merge($details, ['issues' => ['production_environment']]),
                unsafe: true,
            );
        }

        // Evidence's own decision / verification FAIL => FAIL (not unsafe).
        $verification = $this->normalizeVerification($data['verification'] ?? []);
        if (in_array(self::FAIL, array_values($verification), true) || strtoupper((string) ($data['decision'] ?? '')) === self::FAIL) {
            return $this->fail(
                'bukti uji restore menandakan restore gagal',
                'Perbaiki penyebab kegagalan restore lalu jalankan ulang drill hingga sukses.',
                array_merge($details, ['issues' => ['restore_failed']]),
            );
        }

        // --- WATCH downgrades (incomplete / stale / unverifiable) ---
        $issues = [];
        $status = self::GO;

        $size = (int) ($data['source_backup_size_bytes'] ?? 0);
        if ($size <= 0) {
            $status = self::WATCH;
            $issues[] = 'source_backup_size_not_positive';
        }

        // If the referenced backup resolves to a local file, verify it non-empty.
        $localBackup = $this->localBackupPath((string) ($data['source_backup_path'] ?? ''));
        if ($localBackup !== null) {
            if (! is_file($localBackup)) {
                $status = self::WATCH;
                $issues[] = 'source_backup_missing_locally';
            } elseif (filesize($localBackup) === 0) {
                return $this->fail(
                    'bukti uji restore: file backup sumber kosong (tidak dapat dipulihkan)',
                    'Ambil backup baru yang valid (non-zero) sebelum drill.',
                    array_merge($details, ['issues' => ['source_backup_empty']]),
                );
            }
        }

        $markers = array_map('strtolower', (array) config('rollout_readiness.restore_drill.safe_target_markers', []));
        $target = strtolower((string) ($data['restore_target'] ?? ''));
        if ($target !== '' && ! $this->containsAny($target, $markers)) {
            $status = self::WATCH;
            $issues[] = 'restore_target_not_recognized_disposable';
        }

        $ageHours = $this->ageHours($data, $found);
        $staleHours = (float) config('rollout_readiness.thresholds.restore_drill_stale_hours', 720);
        $stale = $ageHours !== null && $ageHours > $staleHours;
        if ($stale) {
            $status = self::WATCH;
            $issues[] = 'evidence_stale';
        }

        if (strtoupper((string) ($data['decision'] ?? '')) === self::WATCH) {
            $status = self::WATCH;
            $issues[] = 'evidence_decision_watch';
        }

        $details['issues'] = $issues;
        $details['age_hours'] = $ageHours !== null ? round($ageHours, 1) : null;
        $details['stale'] = $stale;

        if ($status === self::GO) {
            return [
                'status' => self::GO,
                'unsafe' => false,
                'summary' => sprintf(
                    'bukti uji restore staging valid (%s%s)',
                    $ageHours !== null ? sprintf('%.1f jam lalu', $ageHours) : 'waktu tidak diketahui',
                    ', production_overwrite=false',
                ),
                'remediation' => null,
                'decision' => self::GO,
                'details' => $details,
            ];
        }

        return [
            'status' => self::WATCH,
            'unsafe' => false,
            'summary' => 'bukti uji restore perlu perhatian: '.implode(', ', $issues),
            'remediation' => $stale
                ? 'Ulangi restore drill staging; bukti terakhir sudah kedaluwarsa.'
                : 'Lengkapi/ulangi restore drill staging hingga bukti lengkap dan valid.',
            'decision' => self::WATCH,
            'details' => $details,
        ];
    }

    /**
     * A blank, safe, NON-GO evidence template for --create-template.
     *
     * @return array<string, mixed>
     */
    public function templatePayload(): array
    {
        $keys = (array) config('rollout_readiness.restore_drill.verification_keys', []);
        $verification = [];
        foreach ($keys as $k) {
            $verification[(string) $k] = self::UNKNOWN;
        }
        $verification['pii_redaction_confirmed'] = true;

        return [
            'schema_version' => (int) config('rollout_readiness.restore_drill.schema_version', 1),
            'drill_id' => 'roll-5-1a-TEMPLATE',
            'environment' => 'staging',
            'source_backup_path' => '',
            'source_backup_size_bytes' => 0,
            'restore_target' => 'daengtisiams_restore_drill_TEMPLATE',
            'production_overwrite' => false,
            'started_at' => null,
            'completed_at' => null,
            'duration_seconds' => 0,
            'operator' => '',
            'commands_summary' => ['sanitized command summary only — no secrets'],
            'verification' => $verification,
            // A template must NEVER read as GO — it is an unfinished placeholder.
            'decision' => self::WATCH,
            'notes' => ['TEMPLATE — belum ada drill nyata; jangan tandai GO. Isi lalu jalankan validasi.'],
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return array<int, string> */
    private function candidatePaths(?string $path): array
    {
        if ($path !== null && $path !== '') {
            return [$path];
        }

        return array_values((array) config('rollout_readiness.paths.restore_drill_evidence', []));
    }

    private function locateEvidence(?string $path): ?string
    {
        foreach ($this->candidatePaths($path) as $rel) {
            $abs = $this->absolutePath($rel);
            if (is_file($abs) && filesize($abs) > 0) {
                return $abs;
            }
        }

        return null;
    }

    private function absolutePath(string $rel): string
    {
        // Absolute path passed via --path is honoured; relative is base_path-anchored.
        return str_starts_with($rel, '/') ? $rel : base_path($rel);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function schemaIssues(array $data): array
    {
        $issues = [];

        if ((int) ($data['schema_version'] ?? 0) !== (int) config('rollout_readiness.restore_drill.schema_version', 1)) {
            $issues[] = 'schema_version_mismatch';
        }
        foreach (['drill_id', 'environment', 'source_backup_path', 'restore_target'] as $key) {
            if (! is_string($data[$key] ?? null) || trim((string) ($data[$key] ?? '')) === '') {
                $issues[] = 'missing_'.$key;
            }
        }
        if (! array_key_exists('production_overwrite', $data) || ! is_bool($data['production_overwrite'])) {
            $issues[] = 'missing_production_overwrite';
        }
        if (! is_array($data['verification'] ?? null)) {
            $issues[] = 'missing_verification';
        }
        if (! in_array(strtoupper((string) ($data['decision'] ?? '')), [self::GO, self::WATCH, self::FAIL], true)) {
            $issues[] = 'missing_decision';
        }

        return $issues;
    }

    /**
     * Detect a leaked secret / KTP / NIK pattern in the raw payload. Returns a
     * short category tag (never the matched content) or null.
     */
    private function detectSensitiveLeak(string $raw): ?string
    {
        // 15-16 digit identity-shaped run (KTP/NIK).
        if (preg_match('/\b\d{15,16}\b/', $raw) === 1) {
            return 'pii';
        }
        // Private key blocks.
        if (str_contains($raw, '-----BEGIN')) {
            return 'private_key';
        }
        // key=value / key: value where the key names a credential AND a value follows.
        if (preg_match('/\b(password|passwd|pwd|secret|api[_-]?key|apikey|access[_-]?token|auth[_-]?token|db_password|pgpassword|app_key)\b\s*[=:]\s*[^\s"\']*[^\s"\',}\]]/i', $raw) === 1) {
            return 'secret';
        }
        // Bearer/Basic authorization tokens.
        if (preg_match('/\b(Bearer|Basic)\s+[A-Za-z0-9._\-+\/=]{8,}/', $raw) === 1) {
            return 'authorization';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizedDetails(array $data, string $basename): array
    {
        return [
            'evidence_present' => true,
            'evidence_file' => $basename,
            'schema_valid' => true,
            'drill_id' => $this->masker->mask((string) ($data['drill_id'] ?? '')),
            'environment' => $this->masker->mask((string) ($data['environment'] ?? '')),
            'restore_target' => $this->masker->mask((string) ($data['restore_target'] ?? '')),
            'production_overwrite' => (bool) ($data['production_overwrite'] ?? true),
            'source_backup_file' => basename((string) ($data['source_backup_path'] ?? '')),
            'source_backup_size_bytes' => (int) ($data['source_backup_size_bytes'] ?? 0),
            'evidence_decision' => strtoupper((string) ($data['decision'] ?? self::UNKNOWN)),
            'verification' => $this->normalizeVerification($data['verification'] ?? []),
            'completed_at' => $this->masker->mask((string) ($data['completed_at'] ?? '')),
        ];
    }

    /**
     * Whitelist verification to known keys with enum-only values.
     *
     * @param  mixed  $verification
     * @return array<string, string>
     */
    private function normalizeVerification($verification): array
    {
        $verification = is_array($verification) ? $verification : [];
        $keys = (array) config('rollout_readiness.restore_drill.verification_keys', []);
        $out = [];
        foreach ($keys as $k) {
            $val = strtoupper((string) ($verification[(string) $k] ?? self::UNKNOWN));
            $out[(string) $k] = in_array($val, self::VERIFICATION_ENUM, true) ? $val : self::UNKNOWN;
        }

        return $out;
    }

    private function localBackupPath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $abs = $this->absolutePath($path);

        // Only treat as a checkable local file if it lives under the project.
        return str_starts_with($abs, base_path()) ? $abs : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ageHours(array $data, string $file): ?float
    {
        $completed = (string) ($data['completed_at'] ?? '');
        if ($completed !== '') {
            $ts = strtotime($completed);
            if ($ts !== false) {
                return max(0.0, (time() - $ts) / 3600);
            }
        }
        $mtime = @filemtime($file);

        return $mtime !== false ? max(0.0, (time() - $mtime) / 3600) : null;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{status: string, unsafe: bool, summary: string, remediation: ?string, decision: string, details: array<string, mixed>}
     */
    private function fail(string $summary, string $remediation, array $details, bool $unsafe = false): array
    {
        return [
            'status' => self::FAIL,
            'unsafe' => $unsafe,
            'summary' => $summary,
            'remediation' => $remediation,
            'decision' => self::FAIL,
            'details' => $details,
        ];
    }
}
