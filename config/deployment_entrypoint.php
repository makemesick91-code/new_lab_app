<?php

/**
 * DEPLOY-HARDEN-1 — Immutable Deployment Entrypoint & Self-Update Safety.
 *
 * Read-only registry declaring what a safe deployment ENTRYPOINT must enforce,
 * so App\Support\Deploy\DeploymentEntrypointScanner and
 * App\Services\Foundation\DeploymentEntrypointGovernanceService can validate the
 * bootstrap, the deploy script, the rollback script and the runner against it —
 * without mutating anything or running a single deployment command.
 *
 * THE INVARIANT
 *
 *     RUNNING_DEPLOY_PROGRAM != MUTABLE_REPOSITORY_FILE
 *
 * A deployment rewrites the working tree, including the deployment scripts
 * themselves. bash reads a script incrementally and keeps a byte offset into the
 * open file, so rewriting those bytes under a running interpreter is undefined
 * behaviour. Two production releases had to be rescued with a manual pre-pull.
 * The permanent fix is an immutable execution snapshot: once a deployment starts
 * executing its program, that program cannot change for the duration of the run.
 *
 * SAFETY
 *  - Every marker literal lives HERE, so the deploy/rollback/bootstrap scripts
 *    and the app code never carry the contract inline (ENT-9/ENT-10/ENT-11
 *    config-not-code convention).
 *  - The scanner only reads files and config. It never deploys, never rolls
 *    back, never takes a lock and never removes a snapshot.
 */
return [
    'sprint' => 'DEPLOY-HARDEN-1',

    // Master switch for the DEPLOY-HARDEN-1 governance surface.
    'enabled' => (bool) env('DEPLOYMENT_ENTRYPOINT_GOVERNANCE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default in
    // the governance command (mirrors the ENT-10/ENT-11 configs).
    'strict' => (bool) env('DEPLOYMENT_ENTRYPOINT_GOVERNANCE_STRICT', false),

    // Entrypoint files the gate inspects. Missing any of these is a hard FAIL:
    // without the bootstrap there is no safe way to run a deployment at all.
    'entrypoint_files' => [
        'bootstrap' => 'scripts/deploy-immutable-exec.sh',
        'deploy_script' => 'scripts/deploy-vps.sh',
        'rollback_script' => 'scripts/rollback-vps.sh',
        'runner_script' => 'scripts/deploy-vps-runner.sh',
    ],

    // The trusted bootstrap contract. These markers prove the five properties
    // that make a deployment entrypoint safe: exclusive lock, pinned target,
    // immutable snapshot taken from a git OBJECT (never the working tree),
    // verified trust boundary, and guaranteed cleanup with a preserved exit
    // code.
    'bootstrap_expectations' => [
        'required_fail_fast_marker' => 'set -euo pipefail',

        // Exclusive, kernel-backed, auto-released-on-crash deployment lock.
        'required_lock_markers' => [
            'flock -n 9',
            'DEPLOY_LOCK_ACQUIRED',
        ],

        // The lock/snapshot root must be a trusted, non-runtime-writable path.
        // storage/ and bootstrap/cache are writable by the application runtime
        // user, so a lock or an execution payload living there could be replaced
        // by the very process the deploy constrains (or by the co-tenant).
        'required_trusted_root_markers' => [
            '/run/daengtisiams-deploy',
            '/var/lock/daengtisiams-deploy',
        ],

        // Exact-target pinning. A remote branch that moves after this point
        // belongs to a LATER deployment, never to the running one.
        'required_pin_markers' => [
            'DEPLOY_TARGET_PINNED',
            'rev-parse --verify',
        ],

        // The snapshot is exported from the commit object, so it can never be
        // poisoned by a dirty checkout and never changes afterwards.
        'required_snapshot_markers' => [
            'git archive',
            'DEPLOY_SNAPSHOT_CREATED',
            'DEPLOY_EXECUTION_SOURCE',
        ],

        // Trust boundary: root/deploy-owned, restricted mode, symlink-checked.
        'required_trust_markers' => [
            'DEPLOY_SNAPSHOT_TRUSTED',
            'assert_trusted',
            'is a symlink',
        ],

        // Cleanup must be trap-driven (so a crash still cleans up), guarded
        // (so `rm -rf` can only ever remove this run's own snapshot) and must
        // never rewrite the real exit code.
        'required_cleanup_markers' => [
            'trap cleanup EXIT',
            'purge_snapshot',
            'DEPLOY_SNAPSHOT_CLEANED',
        ],

        // Hash proof that the snapshot matched its source at capture time.
        'required_hash_markers' => [
            'SOURCE_DEPLOY_SCRIPT_SHA256',
            'SNAPSHOT_DEPLOY_SCRIPT_SHA256',
        ],

        // Unique per-run identity (timestamp alone can collide).
        'required_run_id_marker' => 'DEPLOY_RUN_ID',
    ],

    // Both mutating entrypoints (deploy and rollback) must hand over to the
    // bootstrap BEFORE they touch the repository, must refuse to run outside the
    // snapshot, must pin an exact target, and must verify HEAD landed on it.
    'mutating_entrypoints' => ['deploy_script', 'rollback_script'],

    'mutating_entrypoint_expectations' => [
        'required_fail_fast_marker' => 'set -euo pipefail',

        // Hands over to the trusted bootstrap when not already inside a snapshot.
        'required_handover_markers' => [
            'DMS_DEPLOY_SNAPSHOT_DIR',
            'deploy-immutable-exec.sh',
        ],

        // Fail closed: a tree without the bootstrap must abort rather than fall
        // back to the old self-modifying path, and a forged environment variable
        // must not be able to talk the script into running in-tree.
        'required_refusal_markers' => [
            'immutable deployment bootstrap missing',
            'not running from the immutable snapshot',
        ],

        // Exact-SHA target, never a moving branch tip resolved mid-run.
        'required_pin_marker' => 'DMS_DEPLOY_TARGET_SHA',

        // Fail-closed HEAD verification against the pinned target.
        'required_head_verification_markers' => [
            'git rev-parse --verify HEAD',
            'does not match the pinned',
        ],

        // A dirty tracked checkout must stop the run rather than silently
        // deploying something other than the pinned commit. Never resolved by
        // discarding work.
        'required_dirty_guard_marker' => 'git status --porcelain --untracked-files=no',

        // Post-mutation helpers must be invoked from the immutable snapshot.
        'required_snapshot_tool_marker' => 'DEPLOY_TOOLS_DIR',
    ],

    // Helper invocations that would re-read a script out of the working tree the
    // deployment has just rewritten. That is the same self-modification defect,
    // one indirection removed, so these literals must never reappear in the
    // deploy or rollback script — the snapshot copy must be used instead.
    // Case-insensitive substring scan.
    //
    // Deliberately limited to real invocations of the post-mutation security
    // helpers. The entrypoints' own paths are NOT listed here: both scripts
    // legitimately print `bash scripts/rollback-vps.sh ...` in their usage text,
    // and self-invocation is already prevented by the mandatory handover +
    // snapshot-refusal markers above.
    'forbidden_working_tree_invocations' => [
        'bash scripts/harden-secret-permissions.sh',
        'bash scripts/verify-runtime-isolation.sh',
    ],

    // The deployment closure: every script the deploy/rollback path executes
    // across the repository mutation must be carried inside the snapshot.
    'execution_closure' => [
        'snapshot_paths' => ['scripts', 'deploy'],
        'closure_members' => [
            'scripts/deploy-vps.sh',
            'scripts/rollback-vps.sh',
            'scripts/harden-secret-permissions.sh',
            'scripts/verify-runtime-isolation.sh',
            'deploy/runtime-identity.conf',
        ],
        // The live runtime identity authority is overlaid onto the snapshot
        // after the export: rolling the CODE back must never roll the RUNTIME
        // IDENTITY back onto the shared co-tenant account (INFRA-SEC-RUNTIME-1).
        'required_overlay_marker' => 'deploy/runtime-identity.conf',
    ],

    // The operator interface must stay a single canonical command, and starting
    // it must never be mistaken for a finished deployment.
    'runner_expectations' => [
        'required_markers' => [
            'deploy-runner: started detached',
            'deployment is NOT complete until exit=0 and DEPLOY OK',
        ],
        'canonical_command' => 'bash scripts/deploy-vps-runner.sh start',
    ],

    // Manual pre-pull must not be a permanent prerequisite: the entrypoint has
    // to bring a stale production checkout to the pinned target by itself.
    'stale_checkout_expectations' => [
        'required_marker' => 'git merge --ff-only',
    ],

    // Release-evidence artifact (auditability; kept optional so a missing file
    // can never fail the NSF-10 gate on its own).
    'evidence' => [
        'artifact' => 'deployment-entrypoint-check.json',
        'optional_in_profiles' => ['ci', 'vps'],
    ],

    // Release-safety pre-deploy gate (config/release_safety.php) must include
    // this command name (base name, options ignored).
    'required_pre_deploy_gate_command' => 'foundation:deployment-entrypoint-check',
];
