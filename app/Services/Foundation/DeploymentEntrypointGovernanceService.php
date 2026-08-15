<?php

namespace App\Services\Foundation;

use App\Support\Deploy\DeploymentEntrypointScanner;

/**
 * DEPLOY-HARDEN-1 — Immutable Deployment Entrypoint governance layer.
 *
 * Locks the property that two rescued production releases proved was missing:
 * a deployment must never execute from a repository file it is about to
 * rewrite. It validates the trusted bootstrap (lock, exact-SHA pin, immutable
 * snapshot, trust boundary, guaranteed cleanup), the deploy and rollback
 * entrypoints (handover, in-tree refusal, HEAD verification, snapshot-sourced
 * helpers), the execution closure, the operator interface and the release-safety
 * pre-deploy gate — and confirms the ENT-11 deployment/rollback automation it
 * builds on is still GO.
 *
 * Informational only: like every sibling foundation section it is published into
 * architecture:foundation-governance-summary but is NOT wired into the blocking
 * combined decision. The blocking enforcement lives where it belongs — in the
 * deploy script itself, which fails closed.
 */
class DeploymentEntrypointGovernanceService
{
    public function __construct(
        private readonly DeploymentEntrypointScanner $scanner,
        private readonly DeploymentRollbackGovernanceService $deploymentRollbackGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'DH1-R001',
                'title' => 'A running deployment program is never a mutable repository file',
                'description' => 'Once a deployment starts executing its deployment program, that program is immutable for the whole run. bash reads a script incrementally and keeps a byte offset into the open file, so a git checkout that rewrites the running script is undefined behaviour. RUNNING_DEPLOY_PROGRAM != MUTABLE_REPOSITORY_FILE.',
            ],
            [
                'id' => 'DH1-R002',
                'title' => 'Deployment executes from an immutable, trusted snapshot',
                'description' => 'scripts/deploy-immutable-exec.sh exports the deployment payload from the git COMMIT OBJECT (git archive, never the working tree) into a per-run directory under a root-controlled path, verifies it is owner-restricted, mode-restricted and symlink-free, and runs the deployment program from there.',
            ],
            [
                'id' => 'DH1-R003',
                'title' => 'The whole execution closure lives inside the snapshot',
                'description' => 'Every script the deployment executes across the repository mutation — the deploy script, the rollback script, the secret-permission helper and the runtime-isolation verifier — is carried in the snapshot. Sourcing or invoking a mutable helper after the mutation would preserve the same defect one indirection removed.',
            ],
            [
                'id' => 'DH1-R004',
                'title' => 'The deployment target is pinned to an exact SHA before any mutation',
                'description' => 'The bootstrap fetches, resolves and freezes TARGET_SHA before the repository is touched. A remote branch that advances afterwards belongs to a LATER deployment; the running one still lands on exactly the commit it pinned. Rollback pins its target the same way.',
            ],
            [
                'id' => 'DH1-R005',
                'title' => 'HEAD is verified against the pinned target before DEPLOY OK',
                'description' => 'The checkout is fast-forwarded to the pinned commit and the resulting HEAD is compared to it, both immediately after the checkout and again as the last gate before the success marker. Any mismatch is NOT GO. No approximate branch equality.',
            ],
            [
                'id' => 'DH1-R006',
                'title' => 'Only one deployment critical section runs at a time',
                'description' => 'The bootstrap takes an exclusive flock on a trusted, non-runtime-writable path. A second deployment fails closed with a BUSY exit before any backup, migration or checkout mutation. The advisory lock is authoritative; a human-readable status file never is.',
            ],
            [
                'id' => 'DH1-R007',
                'title' => 'Lock and snapshot authority are not writable by the application runtime',
                'description' => 'The lock file and the execution snapshot live under a root-controlled path (/run or /var/lock), never under storage/ or bootstrap/cache which the DaengtisiaMS runtime user and, historically, the co-tenant application can write. A deployment payload the runtime can rewrite is not a deployment payload.',
            ],
            [
                'id' => 'DH1-R008',
                'title' => 'Crash, signal and failure paths release the lock and clean the snapshot',
                'description' => 'flock is released by the kernel when the holding process dies, so a crashed deployment cannot leave a permanently stuck lock. A trap purges the snapshot on every exit path with a guarded removal (expected prefix, own run id, real directory, no symlink) and the original failure exit code is preserved — cleanup never turns a failed deploy into a successful one.',
            ],
            [
                'id' => 'DH1-R009',
                'title' => 'Manual pre-pull is never a prerequisite',
                'description' => 'A production checkout that is behind the target is brought to the pinned commit by the deployment itself (fast-forward to the exact SHA). The one canonical operator command stays `bash scripts/deploy-vps-runner.sh start`, executed on the production VPS and nowhere else.',
            ],
            [
                'id' => 'DH1-R010',
                'title' => 'A tree without the bootstrap fails closed, never falls back',
                'description' => 'The deploy and rollback scripts refuse to run when the immutable bootstrap is absent, and refuse to run when they are not executing from the snapshot. A forged environment variable cannot talk them into the old self-modifying path. A dirty tracked checkout also stops the run — never resolved by discarding work.',
            ],
            [
                'id' => 'DH1-R011',
                'title' => 'Rollback is self-update safe and reasserts the security foundations',
                'description' => 'Rollback runs through the same lock/pin/snapshot mechanism, resolves the operator ref to one exact commit before mutating, and takes its snapshot from the CURRENT code so INFRA-SEC-ENV-1 secret hardening and the INFRA-SEC-RUNTIME-1 isolation verifier still run in their modern form even when the target predates them.',
            ],
            [
                'id' => 'DH1-R012',
                'title' => 'The hardening preserves every existing deployment safety contract',
                'description' => 'Backup still runs before migration and backup failure still aborts; migrations stay additive migrate --force with no destructive database command; the ENT-8 cache order, the ENT-11 rollback contract, INFRA-SEC-ENV-1 secret permissions and INFRA-SEC-RUNTIME-1 runtime isolation all remain mandatory fail-closed gates, and no secret value is ever printed.',
            ],
        ];
    }

    /**
     * @param  string|null  $knownRollbackDecision  Pre-computed ENT-11 decision.
     *                                              The governance summary already
     *                                              collects it, so passing it in
     *                                              avoids re-running the whole
     *                                              ENT-5..10 chain a second time
     *                                              inside an already expensive
     *                                              aggregation.
     * @return array<string, mixed>
     */
    public function report(?string $knownRollbackDecision = null): array
    {
        $enabled = (bool) config('deployment_entrypoint.enabled', true);

        $bootstrap = $this->scanner->bootstrapPosture();
        $closure = $this->scanner->executionClosurePosture();
        $runner = $this->scanner->runnerPosture();
        $releaseSafety = $this->scanner->releaseSafetyPosture();

        $entrypoints = [];
        foreach ((array) config('deployment_entrypoint.mutating_entrypoints', []) as $key) {
            $entrypoints[(string) $key] = $this->scanner->mutatingEntrypointPosture((string) $key);
        }

        $rollbackDecision = $knownRollbackDecision
            ?? (string) ($this->deploymentRollbackGovernance->collect()['decision'] ?? 'UNKNOWN');

        $errors = [];
        $warnings = [];

        foreach ([
            'bootstrap' => $bootstrap,
            'execution_closure' => $closure,
            'operator_interface' => $runner,
        ] as $label => $posture) {
            foreach ((array) ($posture['issues'] ?? []) as $issue) {
                $errors[] = "{$label}: {$issue}";
            }
        }
        foreach ($entrypoints as $key => $posture) {
            foreach ((array) ($posture['issues'] ?? []) as $issue) {
                $errors[] = "{$key}: {$issue}";
            }
        }

        // The pre-deploy gate registration is governance bookkeeping, not a
        // safety property of the running deployment: warn, do not fail.
        foreach ((array) ($releaseSafety['issues'] ?? []) as $issue) {
            $warnings[] = "release_safety: {$issue}";
        }

        if ($rollbackDecision !== 'GO') {
            $errors[] = "ENT-11 deployment/rollback governance is {$rollbackDecision}, expected GO";
        }

        $decision = 'GO';
        if (! $enabled) {
            $decision = 'DISABLED';
        } elseif ($errors !== []) {
            $decision = 'FAIL';
        } elseif ($warnings !== []) {
            $decision = 'WATCH';
        }

        return [
            'sprint' => (string) config('deployment_entrypoint.sprint', 'DEPLOY-HARDEN-1'),
            'enabled' => $enabled,
            'decision' => $decision,
            'status' => $decision === 'GO' ? 'immutable_deployment_entrypoint_ready' : strtolower($decision),
            'bootstrap' => $bootstrap,
            'mutating_entrypoints' => $entrypoints,
            'execution_closure' => $closure,
            'operator_interface' => $runner,
            'release_safety' => $releaseSafety,
            'deployment_rollback_governance_decision' => $rollbackDecision,
            'rules' => self::rules(),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
