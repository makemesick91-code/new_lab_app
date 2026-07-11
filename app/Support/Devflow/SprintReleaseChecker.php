<?php

declare(strict_types=1);

namespace App\Support\Devflow;

/**
 * DEVFLOW-1 — Pre-release readiness checker.
 *
 * Read-only GO/WATCH/NO-GO on whether a sprint is ready to merge/tag/deploy.
 * NEVER creates a tag, deploys, or mutates anything. `sprint:release-check`
 * is the human/CI gate BEFORE the release wrapper is invoked with --apply.
 *
 * Decision: any hard blocker -> NO-GO; soft concern -> WATCH; else GO.
 */
final class SprintReleaseChecker
{
    public function __construct(
        private readonly string $basePath,
        private readonly GitChangeInspector $git,
    ) {}

    /**
     * @param  array{ci_passed?:bool,ci_source?:string}|null  $ciEvidence
     * @return array{decision:string,checks:list<array{id:string,status:string,message:string}>,summary:array<string,int>}
     */
    public function check(SprintManifest $manifest, ?array $ciEvidence = null): array
    {
        $checks = [];

        // 1. Worktree clean.
        $clean = $this->git->isWorktreeClean();
        $checks[] = $this->c('WORKTREE', $clean ? 'passed' : 'failed', $clean ? 'Worktree clean.' : 'Worktree has uncommitted changes.');

        // 2. Current commit resolvable.
        $head = $this->git->headCommit();
        $checks[] = $this->c('HEAD', $head ? 'passed' : 'failed', $head ? "HEAD {$head}" : 'Unable to resolve HEAD commit.');

        // 3. CI evidence input.
        if ($ciEvidence === null) {
            $checks[] = $this->c('CI', 'warning', 'No CI evidence supplied — release-check cannot confirm CI is green.');
        } else {
            $ciPassed = (bool) ($ciEvidence['ci_passed'] ?? false);
            $checks[] = $this->c('CI', $ciPassed ? 'passed' : 'failed', $ciPassed ? 'CI evidence indicates required gates passed.' : 'CI evidence indicates required gates did NOT pass.');
        }

        // 4. Release-safety + evidence config present.
        $safetyOk = $this->configLoaded('release_safety.required_pre_deploy_gates');
        $checks[] = $this->c('RELEASE_SAFETY', $safetyOk ? 'passed' : 'failed', $safetyOk ? 'Release-safety config present.' : 'release_safety config missing.');

        // 5. Backup capability.
        $backupScript = $this->fileExists('scripts/backup-vps.sh') || $this->fileExists('scripts/backup_postgres.sh');
        $checks[] = $this->c('BACKUP', $backupScript ? 'passed' : 'failed', $backupScript ? 'Backup script available.' : 'No backup script found — deploy would run without a backup path.');

        // 6. GO tag not already taken.
        $goTag = $manifest->goTag();
        if ($goTag !== null) {
            $collision = $this->git->tagExists($goTag);
            $checks[] = $this->c('GO_TAG', $collision ? 'failed' : 'passed', $collision ? "GO tag '{$goTag}' already exists (collision)." : "GO tag '{$goTag}' available.");
        } else {
            $checks[] = $this->c('GO_TAG', $manifest->deployRequired() ? 'warning' : 'passed', 'No go_tag declared.');
        }

        // 7. Deploy requirement vs target.
        if ($manifest->deployRequired()) {
            $deployRunner = $this->fileExists('scripts/deploy-vps-runner.sh');
            $checks[] = $this->c('DEPLOY_TARGET', $deployRunner ? 'passed' : 'failed', $deployRunner ? 'Deploy runner available.' : 'deploy_required but no deploy runner found.');
        } else {
            $checks[] = $this->c('DEPLOY_TARGET', 'passed', 'Deploy not required for this sprint.');
        }

        // 8. Rollback target.
        $rollbackScript = $this->fileExists('scripts/rollback-vps.sh');
        $rollbackRequired = (bool) ($manifest->profile()['rollback_required'] ?? true);
        if ($rollbackRequired) {
            $checks[] = $this->c('ROLLBACK', $rollbackScript ? 'passed' : 'failed', $rollbackScript ? 'Rollback runner available.' : 'rollback required but no rollback runner found.');
        } else {
            $checks[] = $this->c('ROLLBACK', 'passed', 'Rollback not required for this sprint type.');
        }

        // 9. Migration risk clarity.
        if ($manifest->flag('schema_change')) {
            $additive = (bool) ($manifest->profile()['additive_migration_only'] ?? true);
            $checks[] = $this->c('MIGRATION', $additive ? 'passed' : 'warning', $additive ? 'Schema change declared additive-only.' : 'Schema change without an additive-only guarantee.');
        }

        $passed = count(array_filter($checks, static fn ($c) => $c['status'] === 'passed'));
        $warnings = count(array_filter($checks, static fn ($c) => $c['status'] === 'warning'));
        $errors = count(array_filter($checks, static fn ($c) => $c['status'] === 'failed'));
        $decision = $errors > 0 ? 'NO-GO' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'decision' => $decision,
            'checks' => $checks,
            'summary' => ['checks' => count($checks), 'passed' => $passed, 'warnings' => $warnings, 'errors' => $errors],
        ];
    }

    private function configLoaded(string $key): bool
    {
        return config($key) !== null;
    }

    private function fileExists(string $relative): bool
    {
        return file_exists($this->basePath.DIRECTORY_SEPARATOR.$relative);
    }

    private function c(string $id, string $status, string $message): array
    {
        return ['id' => $id, 'status' => $status, 'message' => $message];
    }
}
