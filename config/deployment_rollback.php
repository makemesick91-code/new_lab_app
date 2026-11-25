<?php

/**
 * ENT-11 — Deployment & Rollback Automation configuration.
 *
 * Read-only registry that declares what a safe, automated VPS deploy path
 * (backup → deploy → cache rebuild → permission reset → smoke) and a tested
 * rollback path must enforce, so App\Support\Deploy\DeploymentRollbackScanner
 * and App\Services\Foundation\DeploymentRollbackGovernanceService can validate
 * the deploy script, the rollback script, the backup/restore helpers, the
 * release-evidence profiles, and the release-safety rollback checklist against
 * it — without mutating anything or running a single command.
 *
 * Builds on the shipped NSF-10 evidence chain and the ENT-10 CI/CD enterprise
 * gate: the deploy path already exists in scripts/deploy-vps.sh, this sprint
 * hardens it, adds a rehearsable rollback path in scripts/rollback-vps.sh, and
 * captures deploy/rollback evidence.
 *
 * SAFETY:
 *  - Every destructive-command / safety literal lives HERE, so the deploy
 *    script, the rollback script, and the app code never carry the sensitive
 *    patterns inline (mirrors the ENT-9 / ENT-10 config-not-code convention).
 *  - The scanner only reads files and config; it never runs a deploy, a
 *    rollback, a backup, a restore, or any destructive command.
 */
return [
    'sprint' => 'ENT-11',

    // Master switch for the ENT-11 governance surface.
    'enabled' => (bool) env('DEPLOYMENT_ROLLBACK_GOVERNANCE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default in
    // the governance command (mirrors the ENT-10/health-check configs).
    'strict' => (bool) env('DEPLOYMENT_ROLLBACK_GOVERNANCE_STRICT', false),

    // Automation files the deploy/rollback gate inspects. Missing the deploy or
    // rollback script is a hard FAIL; the backup/restore helpers are advisory.
    'automation_files' => [
        'deploy_script' => 'scripts/deploy-vps.sh',
        'rollback_script' => 'scripts/rollback-vps.sh',
        'backup_script' => 'scripts/backup_postgres.sh',
        'restore_script' => 'scripts/restore_postgres.sh',
    ],

    // Destructive command literals that must never appear in the deploy or
    // rollback script. Kept here (config) so no deploy/rollback/app source file
    // carries the literal patterns inline. Case-insensitive scan.
    'forbidden_destructive_patterns' => [
        'migrate:fresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
        'schema:drop',
        'drop database',
        'drop schema',
        'truncate',
    ],

    // Deploy automation contract — the deploy script must take a DB backup
    // before pulling/migrating, migrate additively with --force, rebuild caches,
    // reset permissions, and run the smoke command.
    'deploy_expectations' => [
        'required_backup_command' => 'pg_dump',
        'required_migrate_command' => 'migrate --force',
        'backup_before_migrate' => true,
        'required_markers' => [
            'php artisan release:automated-smoke',
            // INFRA-SEC-RUNTIME-1: ownership follows the EXPLICITLY resolved
            // dedicated runtime identity. This replaced the previous
            // 'chown -R www-data:www-data' literal, which pinned the contract to
            // the account shared with the co-tenant application on the
            // production host.
            // DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233) replaced the root
            // 'chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}"' with the repair of
            // foreign entries only (lchown, -execdir, never a hardlink); the
            // marker still proves ownership goes to the resolved identity.
            'dms_privsep_repair_runtime_ownership "$RUNTIME_USER" "$RUNTIME_GROUP"',
            // INFRA-SEC-ENV-1: the deploy must harden + fail-closed verify the
            // secret-bearing environment files on every run. Removing the call
            // fails this gate in CI and on the VPS, so a future deploy cannot
            // silently regress to a world-readable secret file.
            'harden-secret-permissions.sh',
            // INFRA-SEC-RUNTIME-1: the deploy must prove, on the real host, that
            // the dedicated runtime identity is intact and the co-tenant cannot
            // read this application's secrets or private clinical storage.
            // Removing the call fails this gate in CI and on the VPS.
            'verify-runtime-isolation.sh',
        ],
        // The deploy path must also capture/verify NSF-10 release evidence.
        'required_evidence_markers' => [
            'release:evidence-capture',
            'release:evidence-check',
            'foundation:backup-verify',
        ],
    ],

    // Rollback automation contract — the rollback script must be fail-fast,
    // record the current ref before switching, take a DB backup before the
    // rollback, check out the target ref, rebuild caches, reset permissions,
    // restart the runtime, and re-run the smoke command. It must NEVER run a
    // destructive database command or an automatic schema-destroying migration
    // (additive migrations are backward-compatible so older code runs against
    // the current schema; a data rollback is a separate, explicitly guarded
    // restore step using scripts/restore_postgres.sh).
    'rollback_expectations' => [
        'required_fail_fast_marker' => 'set -euo pipefail',
        // Records the current HEAD/tag before rolling back (rollback checklist).
        'required_ref_capture_markers' => [
            'git rev-parse HEAD',
        ],
        // Mandatory DB backup before any rollback action.
        'required_backup_command' => 'pg_dump',
        // Backup must be taken before the code is checked out to the target ref.
        'backup_before_checkout' => true,
        'required_checkout_marker' => 'git checkout',
        // Post-rollback runtime hardening + verification.
        'required_markers' => [
            'php artisan release:automated-smoke',
            // INFRA-SEC-RUNTIME-1: rolling the CODE back must never roll the
            // RUNTIME IDENTITY back onto the shared account. Rule 233: the
            // ownership repair of foreign entries, never a root chown -R.
            'dms_privsep_repair_runtime_ownership "$RUNTIME_USER" "$RUNTIME_GROUP"',
            'nginx -t',
            // INFRA-SEC-ENV-1: rolling back to an older ref must not restore a
            // world-readable secret file either.
            'harden-secret-permissions.sh',
            // INFRA-SEC-RUNTIME-1: a rollback must not silently drop co-tenant
            // isolation. The verifier is staged before the checkout so this gate
            // runs even when the target ref predates this sprint.
            'verify-runtime-isolation.sh',
            // R6-B7: a target that could not finish the rollback after the code
            // switch is refused before any backup or checkout.
            'ROLLBACK_POST_SWITCH_COMMANDS=',
            'trap - EXIT TERM INT HUP',
        ],
        // The rollback path re-verifies the ENT-5..10 foundation stack.
        'required_gate_markers' => [
            'php artisan architecture:foundation-roadmap-check',
            'php artisan foundation:cicd-enterprise-gate-check',
            'php artisan foundation:deployment-rollback-check',
        ],
    ],

    // DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233, OD-1 DEPLOY-PRIVSEP) — privilege
    // separation between the root deploy/rollback program and the runtime user,
    // who owns storage/ and bootstrap/cache. Checked by
    // DeploymentRollbackScanner::privilegeSeparationIssues() on BOTH scripts, so
    // foundation:deployment-rollback-check fails in CI the moment root PHP or a
    // root path mutation of a runtime tree comes back. It is a regression guard
    // for reviewed code, not a runtime defence (on the VPS the check itself runs
    // as the runtime user). Lines are scanned as LOGICAL lines: backslash
    // continuations joined, comment lines and trailing comments dropped.
    'privilege_separation' => [
        'library' => 'scripts/lib/runtime-privsep.sh',
        // Every script must source the library from the snapshot, prepare
        // before the first mutation, refuse an environment file another
        // identity could have written, normalize modes AS the runtime user,
        // gate on drift, and run composer without its scripts.
        'required_markers' => [
            'source "${DEPLOY_TOOLS_DIR}/scripts/lib/runtime-privsep.sh"',
            'dms_privsep_as_runtime "$RUNTIME_USER" "$@"',
            'dms_privsep_prepare 0 0 "$RUNTIME_USER" "$RUNTIME_GROUP"',
            'dms_privsep_assert_trusted_file 0 .env',
            'dms_privsep_as_runtime_fn "$RUNTIME_USER" "" dms_privsep_normalize_modes',
            'dms_privsep_final_gate 0 0 "$RUNTIME_USER" "$RUNTIME_GROUP"',
            'composer install --no-dev --optimize-autoloader --no-scripts',
            'as_runtime php artisan package:discover --ansi',
            // DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235): composer and npm run as the
            // build identity in a sandboxed transient service, over trees root
            // hands over and claims back; the version probes never load a
            // composer plugin.
            'source "${DEPLOY_TOOLS_DIR}/scripts/lib/build-privsep.sh"',
            'dms_build_identity_check "$BUILD_USER" "$BUILD_HOME" "$RUNTIME_USER" "$RUNTIME_GROUP"',
            'dms_build_handover "$BUILD_USER" "$BUILD_GROUP" vendor',
            '"$BUILD_MASKS" composer install --no-dev --optimize-autoloader --no-scripts',
            // Rule 235 (R4): root hashes pg_dump's bytes in its own staging copy.
            'PGPASSWORD="${DB_PASSWORD}" dms_write_private_dump "${DUMP_STAGING}/dump.sql" pg_dump',
            'DUMP_SHA256="$(sha256sum < "${DUMP_STAGING}/dump.sql" | cut -d\' \' -f1)"',
            'dms_privsep_as_runtime_fn_from "${DUMP_STAGING}/dump.sql" "$RUNTIME_USER" "$DUMP_HELPERS" dms_write_private_dump "$BACKUP" cat',
            'dms_build_claim "$BUILD_USER" vendor',
            'dms_build_handover "$BUILD_USER" "$BUILD_GROUP" node_modules public/build',
            '"$BUILD_MASKS" npm ci',
            '"$BUILD_MASKS" npm run build',
            'dms_build_claim "$BUILD_USER" node_modules public/build',
            'dms_build_assert_no_symlinks public/build',
            'composer --version --no-plugins',
        ],
        // Rule 235: a logical line that runs composer, npm, npx, node, yarn or
        // pnpm must START with the build hand-off; anything else runs
        // third-party code as root. Only the version probes are exempt.
        'build_line_prefix' => '/^\s*(?:run\s+)?dms_build_run\b/',
        'allowed_root_build_probes' => [
            'composer --version',
            'npm --version',
            'node --version',
        ],
        // A `php` token (php, php8.3, /usr/bin/php — anywhere on the line,
        // behind timeout/nice/bash -c/backticks included) must be immediately
        // preceded by one of these runtime hand-offs; otherwise it runs as root.
        'runtime_php_prefix' => '/(?:\bas_runtime|\bruntime_json\s+\S+|\brunuser\s+-u\s+\S+\s+--|\bdms_privsep_as_runtime\s+\S+)\s+$/',
        // The only root PHP allowed: the toolchain probe, which loads no
        // application code.
        'allowed_root_php' => [
            "php -r 'echo PHP_VERSION;'",
        ],
        // A composer subcommand that can run scripts needs --no-scripts on the
        // same logical line; the version probe is the only other root composer.
        'allowed_root_composer_without_no_scripts' => [
            'composer --version',
        ],
        // A logical line that starts with one of these runs as the runtime
        // user, so the root-only file-command rule below does not apply to it.
        'runtime_line_prefix' => '/^\s*(?:run\s+)?(?:[A-Za-z_][A-Za-z0-9_]*=\S*\s+)*(?:as_runtime|runtime_json|runuser\s+-u|dms_privsep_as_runtime(?:_fn(?:_from)?)?|dms_privsep_runtime_output)\b/',
        // Root mutations a runtime-planted symlink can steer, on ANY logical
        // line (a redirect is opened by root's shell even behind as_runtime).
        'forbidden_line_patterns' => [
            'recursive chmod/chown' => '/\b(?:chmod|chown|chgrp)\s+(?:-[A-Za-z]*R\b|--recursive\b)/',
            'find -exec or xargs chmod/chown' => '/(?:-exec(?:dir)?|\bxargs(?:\s+-\S+)*)\s+(?:\S*\/)?(?:chmod|chown|chgrp)\b/',
            'root redirect into a runtime tree' => '/(?:^|[^<>])(?:\d|&)?>{1,2}\|?\s*["\']?(?:\$\{?APP_DIR\}?["\']?\/)?(?:storage\b|bootstrap\/cache)/',
            'root tee into a runtime tree' => '/\btee\b(?:\s+-\S+)*\s+["\']?(?:\$\{?APP_DIR\}?["\']?\/)?(?:storage\b|bootstrap\/cache)/',
            'root sources a runtime file' => '/(?:^|[;&|]\s*|\b(?:then|do|else)\s+)(?:source|\.)\s+["\']?(?:\$\{?APP_DIR\}?["\']?\/)?(?:storage\b|bootstrap\/cache)/',
            // Rule 235 (R4): root writes ONE private copy, into this run's root-only
            // snapshot directory (${DUMP_STAGING}), to hash pg_dump's bytes itself;
            // the dump in the runtime tree is still written by the runtime user.
            'root dump helper (the dump must be written by the runtime user)' => '/(?:^|[;&|]\s*|\b(?:then|do|else)\s+)(?:[A-Za-z_][A-Za-z0-9_]*=\S*\s+)*dms_(?:prepare_private_backup_dir\b|write_private_dump\b(?!\s+"\$\{DUMP_STAGING\}\/))/',
        ],
        // Root file commands that name a runtime tree, on lines that do not
        // start with a runtime hand-off.
        'forbidden_root_line_patterns' => [
            'root file command on a runtime tree' => '/(?:^|[;&|(]\s*|\b(?:then|do|else|sudo|xargs|exec)\s+)(?:\S*\/)?(?:chmod|chown|chgrp|mkdir|rmdir|touch|cp|mv|rm|ln|install|truncate|rsync|dd|unlink|sed\s+-i)\b[^;&|]*?(?:\s|["\'=\/])(?:storage\b|bootstrap\/cache)/',
        ],
    ],

    // ENT-8 cache-order hardening: route/config cache must be cleared BEFORE the
    // route-dependent governance gates run in BOTH the deploy and rollback
    // scripts. The scanner asserts the clear markers appear before the first
    // route-dependent gate marker.
    'cache_order' => [
        'pre_gate_clear_markers' => [
            'php artisan route:clear',
            'php artisan config:clear',
        ],
        'first_route_dependent_gate' => 'php artisan foundation:developer-console-check',
        'post_gate_rebuild_markers' => [
            'config:cache',
            'route:cache',
            'view:cache',
        ],
    ],

    // Release-evidence profiles that must declare the ENT-11 evidence artifact
    // plus the ENT-5..10 sibling artifacts (read from config/release_evidence.php).
    'evidence' => [
        'artifact' => 'deployment-rollback-check.json',
        'required_in_profiles' => ['ci', 'vps'],
        'required_sibling_artifacts' => [
            'cicd-enterprise-gate-check.json',
            'security-compliance-check.json',
            'health-check-check.json',
        ],
    ],

    // Release-safety rollback checklist (config/release_safety.php
    // rollback_checklist) must include these markers so every deploy has a
    // documented, rehearsable rollback plan.
    'required_rollback_checklist' => [
        'previous_head_or_tag_recorded',
        'db_backup_verified_before_deploy',
        'no_destructive_migration',
    ],

    // Release-safety pre-deploy gate (config/release_safety.php) must include
    // this command name.
    'required_pre_deploy_gate_command' => 'foundation:deployment-rollback-check',
];
