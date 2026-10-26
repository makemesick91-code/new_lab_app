---
paths:
  - "scripts/*.sh"
  - "deploy/**"
  - "config/deployment_*.php"
  - "config/release_*.php"
  - "config/backup_*.php"
  - "config/filesystems.php"
  - "config/runtime_portability.php"
  - "app/Support/Deploy/**"
  - "app/Support/Backup/**"
  - "app/Support/Storage/**"
  - "app/Services/Auth/**"
  - "tests/Feature/Deploy/**"
  - "tests/Feature/Storage/**"
---

# Deployment, runtime identity & infrastructure security — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

## Invariants

### Deploy entrypoint and order

- **AIR-DEP-01** `scripts/deploy-vps-runner.sh` detaches the deploy (`setsid` + `nohup`) and records `exit=<code>` in
  its status file; completion is `exit=0` **and** `DEPLOY OK: <stamp>` in the log. "Launcher accepted" and a dropped
  `follow` pipe prove nothing either way. `.github/workflows/deploy-vps.yml` (pipes `deploy-vps.sh` over SSH stdin) is
  superseded and must not be used; no CI job may call a deploy or rollback script. Source:
  `.cursor/rules/92-immutable-deployment-entrypoint.mdc`, `.cursor/rules/91-dedicated-self-hosted-ci-runner.mdc`, archive
  `DEPLOY-HARDEN-1`, `POST-ENT`, `LEGACY-RME-PROGRAM-CLOSURE-1`.
- **AIR-DEP-02** `RUNNING_DEPLOY_PROGRAM != MUTABLE_REPOSITORY_FILE`. `deploy-vps.sh` hands off to
  `scripts/deploy-immutable-exec.sh` before any mutation: `flock` on `/run/daengtisiams-deploy/<role>.lock` (fallback
  `/var/lock/daengtisiams-deploy`), a second attempt exits `75` before backup, then `git archive <TARGET_SHA> -- scripts
  deploy` into a 0700 root-owned snapshot with the live `deploy/runtime-identity.conf` overlaid. Lock and snapshot
  never live under `storage/` or `bootstrap/cache`. Source: `92-immutable-deployment-entrypoint.mdc`.
- **AIR-DEP-03** After the checkout every helper runs from the snapshot as `bash "${DEPLOY_TOOLS_DIR}/scripts/…"`; a new
  post-checkout helper must be added to `execution_closure.closure_members` in `config/deployment_entrypoint.php`. Never
  `source` or `bash` a working-tree script after the mutation. Source: `92-immutable-deployment-entrypoint.mdc` (rule 4).
- **AIR-DEP-04** Pin the target before mutating: `git merge --ff-only "$TARGET_SHA"`, never `git pull`; assert
  `HEAD == TARGET_SHA` after the checkout and again before `DEPLOY OK`. A dirty tracked tree fails closed (never
  `git reset --hard`); never delete a lock file to unstick a deploy; a manual pre-pull must never become a
  prerequisite again (NO-GO); only merged authority is deployed. Source: `92-immutable-deployment-entrypoint.mdc`.
- **AIR-DEP-05** Keep the order in `scripts/deploy-vps.sh`: secret hardening before the environment file is sourced →
  private DB dump → fast-forward → `migrate --force` → `foundation:backup-verify` → `route:clear` + `config:clear`
  before the route-dependent governance gates (ENT-8 order) → gates → cache rebuild as the runtime user →
  `deploy:auth-landing-smoke --strict` → secret re-verify → `verify-runtime-isolation.sh --require-host` → `DEPLOY OK`.
  A backup failure aborts before migration. Source: `.cursor/rules/60-deployment-rollback-automation.mdc`,
  `.cursor/rules/59-cicd-enterprise-gate.mdc`, `.cursor/rules/82-login-redirect-runtime-permissions.mdc`.
- **AIR-DEP-06** Never add `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `schema:drop`,
  `DROP DATABASE` or `TRUNCATE` to a deploy, rollback, backup or CI script. Destructive patterns and required markers
  live in `config/deployment_rollback.php`, `config/cicd_enterprise_gate.php` and `config/deployment_entrypoint.php`,
  never inline in a script or scanner. Source: `60-…`, `59-…`.
- **AIR-DEP-07** `DeploymentRollbackScanner` substring-matches required markers over the whole script, so editing a
  marked line means changing config, both scripts and tests together (a parameterised `chown` once dropped the literal
  and failed CI). The `harden-secret-permissions.sh` and `verify-runtime-isolation.sh` calls are required in both the
  deploy and rollback contracts; removing either fails `foundation:deployment-rollback-check`. Source:
  `.cursor/rules/100-production-secret-file-permission-hardening.mdc`, `.cursor/rules/92-dedicated-runtime-identity-co-tenant-isolation.mdc`,
  archive `FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS`.
- **AIR-DEP-08** `scripts/rollback-vps.sh <tag|commit>` resolves the ref to one exact SHA before mutating, takes its own
  `rollback.lock`, builds its snapshot from the CURRENT code (so modern gates run against an older target), dumps the
  database before the checkout, and re-asserts secret and isolation invariants. Rollback never restores data: that is
  the separate, explicit `scripts/restore_postgres.sh`. Source: `60-…`, `92-immutable-deployment-entrypoint.mdc` (rule 20).

### Runtime identity and filesystem

- **AIR-DEP-09** The runtime identity is `daengtisiams`, read only from `deploy/runtime-identity.conf`; resolution fails
  closed and never falls back to the first `user =` in a pool directory, the process table or `www-data`.
  `DMS_FORBIDDEN_RUNTIME_USERS` always includes `root` and `www-data`, and `www-data` is never added to the
  `daengtisiams` group. Source: `92-dedicated-runtime-identity-co-tenant-isolation.mdc`.
- **AIR-DEP-10** The PHP-FPM pool is `[daengtisiams]` on `/run/php/php8.3-fpm-daengtisiams.sock` (`deploy/php-fpm/daengtisiams.conf`),
  never the default `[www]`. nginx stays `www-data` and only *connects* to the socket — never fix a symptom by giving
  `www-data` read access to secrets. The tracked queue-worker and background units declare `User=daengtisiams`.
  Source: `92-dedicated-runtime-identity-co-tenant-isolation.mdc`.
- **AIR-DEP-11** The runtime writes only `storage/` and `bootstrap/cache/`; source, scripts and `vendor/` stay
  root-owned and never `chown -R` the checkout. Directories `2775` (setgid), files `0664`, never `0777`. Cache commands
  (`optimize:clear`, `config:cache`, `route:cache`, `view:cache`) run as the runtime user via `runuser`, and the
  CACHE/SESSION/VIEW CACHE/LOG/BOOTSTRAP CACHE writable gates fail the deploy. Source: `82-…`, `92-dedicated-…`.
- **AIR-DEP-12** `scripts/provision-runtime-identity.sh` is idempotent and dry-run by default, validates `php-fpm -t`
  and `nginx -t` before any reload, and runs no database command. Its `--rollback` to the shared runtime is incident
  recovery that leaves INFRA-SEC-RUNTIME-1 WATCH/NO-GO. `verify-runtime-isolation.sh` runs with `--require-host` in
  production; `--skip-os-account` is test-only and rejected alongside it. Source: `92-dedicated-…` (rules 19, 23–24).

### Secrets, dumps, backup and restore

- **AIR-DEP-13** The environment file is `root:daengtisiams 0640` — not `0600`, because `config:cache` runs as the
  runtime user and phpdotenv's `safeLoad()` swallows a permission error, silently producing an empty application key
  and database credentials. Other bits always zero; secret-bearing backup copies `0600`; a symlinked secret fails
  closed; prove access with `stat`/`namei`/`test -r`, never by printing contents; never recursive `chmod`. `sed -i`
  recreates the file as `root:root`, so re-assert after any edit. Source: `100-production-secret-file-permission-hardening.mdc`.
- **AIR-DEP-14** Create every database dump through `scripts/lib/private-db-dump.sh` (`dms_write_private_dump`): umask
  077 in a subshell, `.partial` with `noclobber`, atomic rename, published `0600`. The deploy's `restrict_backup_tree` then
  normalizes dumps to `0640` and directories to `2750` (runtime user and group): never "fix" that to `0600`, and never
  widen past `0640`. Backup
  directories are `2750` via `dms_prepare_private_backup_dir` (a default ACL is refused); the storage-wide normalization
  prunes `storage/app/backups`; the password reaches `pg_dump` only as `PGPASSWORD`. Tests observe the mode **at
  creation**. (supersedes INFRA-SEC-ENV-1 "chmod the dump immediately after `pg_dump`") Source:
  `.cursor/rules/176-private-database-dump-creation.mdc`.
- **AIR-DEP-15** `scripts/backup-vps.sh` runs `pg_dump` → `foundation:backup-verify` → prune by
  `BACKUP_DR_RETENTION_DAYS` while keeping `min_retained_backups` (`config/backup_dr.php`). It is scheduled by
  `deploy/systemd/daengtisiams-db-backup.{service,timer}` (`OnCalendar=*-*-* 19:00:00` UTC = 03:00 WITA,
  `Persistent=true`, `User=daengtisiams`), never through Laravel's scheduler: production has no `schedule:run`
  invoker, so a scheduled task silently never fires. Source: `.cursor/rules/61-backup-disaster-recovery-automation.mdc`,
  archive `FIX-SUNU-GO-LIVE-BLOCKERS-1`.
- **AIR-DEP-16** Restores run as the `postgres` superuser with the dump streamed on stdin (dumps are not readable by
  that account), `ON_ERROR_STOP=1`, single transaction and checked `PIPESTATUS`; never grant the app role superuser.
  `scripts/restore_postgres.sh` refuses the production database without `--force-production` plus a typed
  confirmation; `--yes` skips the typed confirmation and is used only with an explicit owner decision for that restore. `scripts/restore-rehearsal.sh` restores into `<db>_dr_rehearsal_<stamp>`, aborts if it equals the
  production name, and drops only that scratch database via a trap. Absent drill evidence stays WATCH. Source: `61-…`,
  archive `FIX-SUNU-GO-LIVE-BLOCKERS-1`, `.cursor/rules/125-final-pre-full-suite-readiness-gate.mdc` (§6).

### Upload limits and request budget

- **AIR-DEP-17** The application is the size authority (`legacy_mass_upload.package.max_bytes`, 500 MiB); infra sits
  just above it. Pool `php_admin_value`: `upload_max_filesize 500M`, `post_max_size 512M`, `max_input_time 900`,
  `memory_limit 256M`; `max_execution_time` stays 30 pool-wide. nginx `client_max_body_size 512m`
  (`deploy/nginx/upload-body-size.conf`) equals `post_max_size`, so nginx is the outer bound (a PHP-level refusal drops
  the CSRF token and shows 419). Never `0` or `-1`. A 413 is infrastructure — name the layer, and never raise nginx
  alone. Source: archive `BUGFIX-MASS-UPLOAD-REQUEST-ENTITY-TOO-LARGE-1`.
- **AIR-DEP-18** The deploy installs neither the pool file nor the nginx include. Install the pool with the isolated
  `install -o root -g root -m 0644` step, `php-fpm8.3 -t`, reload; add the include to the DaengtisiaMS 443 server block
  only, `nginx -t`, reload. Verify with `nginx -T | grep client_max_body_size` and `php-fpm8.3 -tt`, never `php -i`
  (the host CLI PHP belongs to the co-tenant). Source: archive `BUGFIX-MASS-UPLOAD-REQUEST-ENTITY-TOO-LARGE-1`,
  `.cursor/rules/130-raw-sql-driver-parity-and-gate-selection.mdc` (R3).
- **AIR-DEP-19** `set_time_limit()` replaces and restarts the limit, so it may only raise: read
  `ini_get('max_execution_time')` and return when it is `0` (unlimited — the CLI default) or already at least the
  budget, as `LegacyMassUploadController::grantIntakeExecutionBudget()` does. An unconditional call armed a 900-second
  kill timer on the whole Pest process. Source: archive `BUGFIX-MASS-UPLOAD-REQUEST-ENTITY-TOO-LARGE-1`.

### Storage

- **AIR-DEP-20** `App\Support\Storage\ClinicalEvidenceStorage` is the only storage authority for patient-linked
  binaries (disk `clinical_evidence`: private, `serve => false`, `throw => true`). Never write clinical evidence through
  `disk('public')`, `store(…,'public')`, `storeAs(…,'public')`, `storePublicly*` or `public_path()`; the scan in
  `tests/Feature/Storage/ClinicalEvidencePrivacyTest.php` covers `app/` and `database/`. Reads go through
  policy-gated controllers; print and PDF embed inline base64. Source:
  `.cursor/rules/121-clinical-evidence-private-storage.mdc`, `.cursor/rules/121-final-stabilization-residual-audit.mdc` (R5).
- **AIR-DEP-21** The `local` disk has `serve => true`, so private evidence never goes under `storage/app/private`.
  Every private disk (`clinical_evidence`, `legacy_rme_private`, `legacy_odontogram_private`,
  `legacy_mass_upload_private`) keeps `serve => false`, `visibility private`, `throw => true` and no `url`; a storage
  disk is a data authority with no silent fallback. The nginx `location ^~ /storage/ { deny all; }` is permanent.
  Source: `121-clinical-…`, `121-final-…` (R7), archive `LEGACY-RME-PDF-1B`.
- **AIR-DEP-22** `clinical-evidence:migrate-public` keeps object keys verbatim: `--apply` copies and verifies SHA-256,
  `--purge-source` re-verifies before deleting, each phase writes a manifest. The `object` disk stays off
  (`OBJECT_STORAGE_ENABLED=false`, `storage:object-readiness-check` → `disabled_ready`); cutover is its own sprint.
  Adding a `STORAGE-R*` rule means repinning every exact-list and `toHaveCount` pin. Source: archive
  `STORAGE-PUBLIC-CLINICAL-EVIDENCE-1`, `STORAGE-1`, `121-final-…` (R14).

### Auth landing, guards, evidence

- **AIR-DEP-23** Every auth completion path lands through `App\Services\Auth\PostAuthenticationRedirectService`;
  `url.intended` is honored only when internal, well-formed, route-resolvable and authorized against the target
  route's own `permission:`/`role:` middleware. Never fix a landing 403 by granting a dashboard permission; keep
  session regeneration. Source: `82-login-redirect-runtime-permissions.mdc`.
- **AIR-DEP-24** Interactive REPLs are refused twice: `App\Support\Deploy\ProductionShellCommandGuard` scans the
  scripts in `config/release_safety.php` `forbidden_production_commands.scanned_files` (`deploy:forbidden-command-check`
  runs in `scripts/sprint-release.sh` before anything ships), and `ForbiddenConsoleCommandGuard` refuses
  `blocked_console_commands` at `CommandStarting` except in explicitly allowed environments. A runbook line that names
  a forbidden command must carry the `-- never` marker (`ProductionShellCommandGuardTest`). Source: archive
  `PRODUCTION-ANDROID-RELEASE-APK-EVIDENCE-1`, `DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1`,
  `REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1`.
- **AIR-DEP-25** A new release-evidence artifact needs two registrations: its profile in `config/release_evidence.php`
  and a capture entry in `App\Services\Foundation\ReleaseEvidenceService`, or it is permanently missing and holds the
  profile at WATCH. `release_evidence.forbidden_patterns` bans the environment-file literal, so never write it in rule
  text or a registered runbook. Evidence carries metadata only. Source: archive `DEPLOY-HARDEN-1`, `INFRA-SEC-ENV-1`, `60-…`.
- **AIR-DEP-26** The deploy never starts or enables the queue worker. The tracked unit's `--queue=` list must include
  every produced queue (ENT5-Q009), appended to preserve priority, with `StartLimitIntervalSec` under `[Unit]`
  (ENT5-Q010). Installed-unit drift is reported, never a deploy gate (a gate on it deadlocked deploys). Source:
  `.cursor/rules/133-dedicated-queue-producer-consumer-contract.mdc`, archive `POST-ENT`,
  `BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1`.
- **AIR-DEP-27** `runtime:stateless-readiness-check` and `deploy:portability-check` are read-only; `file` session or
  cache and `sync` queue are warnings on the single VPS, never errors; runtime writes stay inside
  `allowed_local_write_paths` (`config/runtime_portability.php`). Source: archive `STATELESS-1`.
- **AIR-DEP-28** Verify production over the canonical URL (CG-14). The NSF-9 loopback `/login` probe returns 404 because
  a co-tenant answers it — a known warning, not a regression. `systemctl reload nginx` is graceful, so re-verify after
  workers cycle; `grep -r` does not follow the symlinks in `sites-enabled`, so read `nginx -T` before filing a missing
  directive. Source: `121-clinical-…` (traps), `121-final-…` (R11).

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- Production runs as `daengtisiams` on the PHP 8.3 pool; the co-tenant keeps its own newer-PHP pool as `www-data`.
  Accepted residuals: any `www-data` process can reach the FastCGI socket (connect ≠ read), and root reads everything.
- Live upload limits since 2026-10-03: nginx `512m`, pool `500M`/`512M`/`900`/`256M`. The DB backup timer is versioned
  in the repo; whether it is installed on the host is an operator step — check `systemctl` before assuming backups are
  scheduled.
- Object storage disabled; clinical evidence on the local private disk; restore-drill evidence absent (honest WATCH).
- Measured 2026-10-26 by token substring: `SecretFilePermissionHardeningTest`, `DeployRuntimePermissionsScriptTest` and
  `DeployAuthLandingSmokeCommandTest` match no critical-gate token, so only the deferred Full Suite runs them — confirm
  with `pest --list-tests` before relying on CI for those invariants.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/60-deployment-rollback-automation.mdc` | ENT-11 deploy and rollback contract |
| `.cursor/rules/61-backup-disaster-recovery-automation.mdc` | ENT-12 backup, retention, restore rehearsal |
| `.cursor/rules/82-login-redirect-runtime-permissions.mdc` | Post-auth landing, runtime ownership, writable gates |
| `.cursor/rules/92-dedicated-runtime-identity-co-tenant-isolation.mdc` | `daengtisiams` identity, FPM pool, isolation verifier |
| `.cursor/rules/92-immutable-deployment-entrypoint.mdc` | Snapshot, flock, pinned SHA, `DEPLOY OK` |
| `.cursor/rules/100-production-secret-file-permission-hardening.mdc` | Secret file mode and handling |
| `.cursor/rules/121-clinical-evidence-private-storage.mdc` | Clinical evidence on private disks only |
| `.cursor/rules/176-private-database-dump-creation.mdc` | Dumps private from creation |
| `.cursor/rules/59-cicd-enterprise-gate.mdc` | ENT-10 gate, cache-clear order |
| `.cursor/rules/133-dedicated-queue-producer-consumer-contract.mdc` | Worker unit queue list |
| `.cursor/rules/121-final-stabilization-residual-audit.mdc` | Public-disk scan, `/storage/` deny, rule pins |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/14-deploy-infra-security.md`, plus
`12-foundation-enterprise.md` (`ENT-11`, `ENT-12`, `POST-ENT`, `STORAGE-1`, `STATELESS-1`) and
`06-legacy-import-odontogram-patient.part1.md` (`BUGFIX-MASS-UPLOAD-REQUEST-ENTITY-TOO-LARGE-1`). Grep by sprint id;
never read a whole file.
