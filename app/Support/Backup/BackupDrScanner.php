<?php

namespace App\Support\Backup;

/**
 * ENT-12 — read-only Backup & Disaster Recovery Automation scanner.
 *
 * Verifies the automated backup path (scripts/backup-vps.sh) and the
 * rehearsable, non-production restore path (scripts/restore-rehearsal.sh)
 * without mutating anything: the backup script is fail-fast, pg_dumps into an
 * allowed backup directory, verifies the dump, and prunes old backups by
 * retention; the restore-rehearsal script is fail-fast, restores the latest
 * verified backup into a SCRATCH database that is explicitly guarded to differ
 * from production, records non-sensitive evidence (path/size, RTO/RPO), and
 * never restores over the production database; the release-evidence profiles and
 * the release-safety pre-deploy gate declare the ENT-12 artifact/gate; and the
 * documented RTO/RPO objectives are present.
 *
 * All literals (destructive patterns, required markers) come from
 * config('backup_dr') so no backup/restore/app source file carries the
 * sensitive patterns inline.
 */
class BackupDrScanner
{
    /**
     * Resolve and read a configured automation file. Returns null when the file
     * is missing so callers can flag it explicitly.
     */
    private function readAutomationFile(string $key): ?string
    {
        $path = (string) config("backup_dr.automation_files.{$key}", '');
        if ($path === '') {
            return null;
        }

        $full = base_path($path);
        if (! is_file($full)) {
            return null;
        }

        return (string) file_get_contents($full);
    }

    /**
     * Automated backup script posture: fail-fast, pg_dump into an allowed
     * directory, backup verification, retention pruning, no production-endangering
     * destructive command.
     *
     * @return array<string, mixed>
     */
    public function backupScriptPosture(): array
    {
        $contents = $this->readAutomationFile('backup_script');
        if ($contents === null) {
            return ['file_present' => false, 'ok' => false, 'issues' => ['backup script missing']];
        }

        $lower = strtolower($contents);
        $issues = [];

        $destructive = $this->findForbidden($lower);
        if ($destructive !== []) {
            $issues[] = 'destructive command(s): '.implode(', ', $destructive);
        }

        $failFast = (string) config('backup_dr.backup_expectations.required_fail_fast_marker', 'set -euo pipefail');
        $failFastPresent = str_contains($contents, $failFast);
        if (! $failFastPresent) {
            $issues[] = 'missing fail-fast marker (set -euo pipefail)';
        }

        $backupCmd = (string) config('backup_dr.backup_expectations.required_backup_command', 'pg_dump');
        $backupPresent = str_contains($lower, strtolower($backupCmd));
        if (! $backupPresent) {
            $issues[] = "backup script must run {$backupCmd}";
        }

        $verifyMarker = (string) config('backup_dr.backup_expectations.required_verify_marker', 'php artisan foundation:backup-verify');
        $verifyPresent = str_contains($contents, $verifyMarker);
        if (! $verifyPresent) {
            $issues[] = 'backup script must verify the dump (foundation:backup-verify)';
        }

        $retentionMarker = (string) config('backup_dr.backup_expectations.required_retention_marker', 'BACKUP_DR_RETENTION_DAYS');
        $retentionPresent = str_contains($contents, $retentionMarker);
        if (! $retentionPresent) {
            $issues[] = 'backup script must prune by retention';
        }

        $dir = (string) config('backup_dr.backup_expectations.required_backup_directory', '');
        $dirPresent = $dir === '' || str_contains($contents, $dir);
        if (! $dirPresent) {
            $issues[] = "backup script must write to allowed directory ({$dir})";
        }

        $missingMarkers = $this->missingMarkers($contents, (array) config('backup_dr.backup_expectations.required_markers', []));
        if ($missingMarkers !== []) {
            $issues[] = 'missing backup marker(s): '.implode(', ', $missingMarkers);
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'fail_fast' => $failFastPresent,
            'backup_command_present' => $backupPresent,
            'verify_present' => $verifyPresent,
            'retention_present' => $retentionPresent,
            'no_destructive_command' => $destructive === [],
            'issues' => $issues,
        ];
    }

    /**
     * Restore-rehearsal script posture: present, fail-fast, non-production guard,
     * backup verification, evidence markers, no production-restore command, no
     * production-endangering destructive command.
     *
     * @return array<string, mixed>
     */
    public function restoreRehearsalPosture(): array
    {
        $contents = $this->readAutomationFile('restore_rehearsal_script');
        if ($contents === null) {
            return ['file_present' => false, 'ok' => false, 'issues' => ['restore-rehearsal script missing']];
        }

        $lower = strtolower($contents);
        $issues = [];

        $destructive = $this->findForbidden($lower);
        if ($destructive !== []) {
            $issues[] = 'destructive command(s): '.implode(', ', $destructive);
        }

        $failFast = (string) config('backup_dr.restore_rehearsal_expectations.required_fail_fast_marker', 'set -euo pipefail');
        $failFastPresent = str_contains($contents, $failFast);
        if (! $failFastPresent) {
            $issues[] = 'missing fail-fast marker (set -euo pipefail)';
        }

        $guardMarker = (string) config('backup_dr.restore_rehearsal_expectations.required_non_production_guard_marker', 'REHEARSAL_DB');
        $abortMarker = (string) config('backup_dr.restore_rehearsal_expectations.required_guard_abort_marker', '');
        $guardPresent = str_contains($contents, $guardMarker) && ($abortMarker === '' || str_contains($contents, $abortMarker));
        if (! $guardPresent) {
            $issues[] = 'restore rehearsal must guard that the target differs from the production database';
        }

        $backupCmd = (string) config('backup_dr.restore_rehearsal_expectations.required_backup_command', 'pg_dump');
        if (! str_contains($lower, strtolower($backupCmd))) {
            $issues[] = "restore rehearsal must snapshot ({$backupCmd}) before the drill";
        }

        $verifyMarker = (string) config('backup_dr.restore_rehearsal_expectations.required_verify_marker', 'php artisan foundation:backup-verify');
        $verifyPresent = str_contains($contents, $verifyMarker);
        if (! $verifyPresent) {
            $issues[] = 'restore rehearsal must verify the backup (foundation:backup-verify)';
        }

        $missingEvidence = $this->missingMarkers($contents, (array) config('backup_dr.restore_rehearsal_expectations.required_evidence_markers', []));
        if ($missingEvidence !== []) {
            $issues[] = 'missing rehearsal evidence marker(s): '.implode(', ', $missingEvidence);
        }

        $forbiddenProd = [];
        foreach ((array) config('backup_dr.restore_rehearsal_expectations.forbidden_production_restore_markers', []) as $marker) {
            if ($marker !== '' && str_contains($contents, (string) $marker)) {
                $forbiddenProd[] = (string) $marker;
            }
        }
        if ($forbiddenProd !== []) {
            $issues[] = 'restore rehearsal must not call the production restore helper: '.implode(', ', $forbiddenProd);
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'fail_fast' => $failFastPresent,
            'non_production_guard' => $guardPresent,
            'verify_present' => $verifyPresent,
            'records_evidence' => $missingEvidence === [],
            'no_production_restore' => $forbiddenProd === [],
            'no_destructive_command' => $destructive === [],
            'issues' => $issues,
        ];
    }

    /**
     * Retention + RTO/RPO objective posture: retention days configured and
     * positive; RTO/RPO documented as positive integers.
     *
     * @return array<string, mixed>
     */
    public function objectivesPosture(): array
    {
        $retentionDays = (int) config('backup_dr.retention.db_backup_retention_days', 0);
        $minRetained = (int) config('backup_dr.retention.min_retained_backups', 0);
        $rto = (int) config('backup_dr.objectives.rto_minutes', 0);
        $rpo = (int) config('backup_dr.objectives.rpo_minutes', 0);

        $issues = [];
        if ($retentionDays <= 0) {
            $issues[] = 'db_backup_retention_days must be a positive integer';
        }
        if ($minRetained <= 0) {
            $issues[] = 'min_retained_backups must be a positive integer';
        }
        if ($rto <= 0) {
            $issues[] = 'rto_minutes must be a documented positive integer';
        }
        if ($rpo <= 0) {
            $issues[] = 'rpo_minutes must be a documented positive integer';
        }

        return [
            'ok' => $issues === [],
            'retention_days' => $retentionDays,
            'min_retained_backups' => $minRetained,
            'rto_minutes' => $rto,
            'rpo_minutes' => $rpo,
            'issues' => $issues,
        ];
    }

    /**
     * Release-evidence profile posture: the ENT-12 artifact and the ENT-9..11
     * sibling artifacts must be required in the configured profiles.
     *
     * @return array<string, mixed>
     */
    public function evidenceProfilePosture(): array
    {
        $artifact = (string) config('backup_dr.evidence.artifact', '');
        $profiles = (array) config('backup_dr.evidence.required_in_profiles', []);
        $siblings = (array) config('backup_dr.evidence.required_sibling_artifacts', []);

        $issues = [];
        $perProfile = [];

        foreach ($profiles as $profile) {
            $required = (array) config("release_evidence.profiles.{$profile}.required_artifacts", []);

            $artifactPresent = in_array($artifact, $required, true);
            $missingSiblings = array_values(array_filter($siblings, fn (string $s) => ! in_array($s, $required, true)));

            if (! $artifactPresent) {
                $issues[] = "profile {$profile} missing artifact {$artifact}";
            }
            if ($missingSiblings !== []) {
                $issues[] = "profile {$profile} missing sibling artifact(s): ".implode(', ', $missingSiblings);
            }

            $perProfile[$profile] = ['artifact_present' => $artifactPresent, 'missing_siblings' => $missingSiblings];
        }

        return ['artifact' => $artifact, 'profiles' => $perProfile, 'ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Release-safety posture: the pre-deploy gate list includes the ENT-12
     * command.
     *
     * @return array<string, mixed>
     */
    public function releaseSafetyPosture(): array
    {
        $gates = (array) config('release_safety.required_pre_deploy_gates', []);
        $requiredGate = (string) config('backup_dr.required_pre_deploy_gate_command', '');
        $gatePresent = $requiredGate !== '' && collect($gates)->contains(fn (string $gate) => str_contains($gate, $requiredGate));

        $issues = [];
        if (! $gatePresent) {
            $issues[] = "pre-deploy gate missing command: {$requiredGate}";
        }

        return [
            'ok' => $issues === [],
            'pre_deploy_gate_ok' => $gatePresent,
            'issues' => $issues,
        ];
    }

    /**
     * @return list<string>
     */
    private function findForbidden(string $lowerContents): array
    {
        $found = [];
        foreach ((array) config('backup_dr.forbidden_destructive_patterns', []) as $pattern) {
            $pattern = strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($lowerContents, $pattern)) {
                $found[] = $pattern;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $markers
     * @return list<string>
     */
    private function missingMarkers(string $contents, array $markers): array
    {
        $missing = [];
        foreach ($markers as $marker) {
            if ($marker !== '' && ! str_contains($contents, (string) $marker)) {
                $missing[] = $marker;
            }
        }

        return array_values($missing);
    }
}
