# DMS-DEPLOY-PRIVSEP-HARDENING-1 — deploy privilege separation (rule 233)

**Date:** 2026-11-15 (WITA). **Type:** security fix / deployment (Foundation-First Sprint Lock: allowed category).
**Status:** IMPLEMENTED — review, CI, merge, release approval, deploy and GO pending (§10). Closes **OD-1
DEPLOY-PRIVSEP** only after production verification.

| Item | Value |
|---|---|
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` at `46ac4d6236dadc86b6f0f75d9480ffad7631b137` |
| Production before | `d555c7dc5ddb46631c16cd957b86490d06634f7f` (rule 231 r2, deploy `20261114-225508`, GO `dms-release-guard-trust-hardening-1-go`) |
| Branch | `fix/dms-deploy-privsep-hardening-1` |
| Implementation PR / merge SHA | pending |
| Release id / approval | pending (owner-signed, rule 231) |
| Deploy id | pending |
| GO tag | `dms-deploy-privsep-hardening-1-go` — only after production verification |
| Durable rule | `.cursor/rules/233-deploy-privilege-separation.mdc`; index line AIR-DEP-39 in `.claude/rules/deploy-infra.md` |

## 1. Owner request (CG-22)

The owner ordered this sprint end to end (2026-11-15): close OD-1 DEPLOY-PRIVSEP with least privilege, keep every
release control of rules 229/231 intact, run `deploy-vps-runner.sh` on the VPS only, keep the deploy's own database
backup mandatory, never run BK-2 (`DMS-BK2-MANUAL-ONLY-1`), treat offline key custody as optional
(`DMS-OFFLINE-CUSTODY-OPTIONAL-1`), make every new foundation a permanent rule, and stop only at owner-only steps
(signature, rollback decision). The r2 release had accepted OD-1 for that deploy only (rule 231 sprint doc §13.4).

## 2. Baseline (production, read-only, 2026-11-15 07:36 WITA)

`bash scripts/production-target-check.sh --remote` → `PRODUCTION_TARGET=VERIFIED`. Facts measured on the host:

| Fact | Value |
|---|---|
| HEAD | `d555c7dc…` (rule 231 marker present) |
| Kernel | `fs.protected_hardlinks=1`, `fs.protected_symlinks=1`, `fs.protected_regular=2`, `fs.protected_fifos=1` |
| Tools | coreutils 9.4, findutils 4.9.0, `runuser` present; root umask under sudo 0022 |
| Runtime identity | `daengtisiams` (nologin, no home, no sudo, only its own group); FPM pool `[daengtisiams]`; nginx workers `www-data` |
| App dir / `bootstrap/` | `root:root 755` / `root:root 755` |
| `storage/` / `bootstrap/cache` | `daengtisiams:daengtisiams 2775` / `2770`; private paths `2770` (backups `2750`) |
| Runtime trees | 1,824 entries; **0** foreign-owned; **0** hardlinked files; **0** device/FIFO/socket; symlinks only the legacy rule 229 runner `latest.*` links |
| Dumps | 142, all `daengtisiams:daengtisiams 0640` |
| Source side, non-root | **`public/build` directory `www-data:www-data 775`**; `public/assets` (2 directories, 2 files, `www-data`, `775`/`664`, tracked); `public/storage` symlink `www-data`; the environment file and its backups `root:<runtime group>` (rule 100) |
| Readable by the runtime user | the whole source side except the environment-file backups (`0600`) and two root-only files in an app-level `backups/` directory; the environment file and `.git` readable |
| Services | `php8.3-fpm`, `nginx` active; queue worker installed, disabled (C5); db-backup timer active (`User=daengtisiams`); no crontab for root or the runtime user; no `schedule:run` invoker |
| Release lock | idle (`/run/daengtisiams-deploy/`, root 0700); trust anchor present, 1 line |
| Admin account | unrestricted passwordless sudo (as CG-14's `sudo -n` implies) — see §9 OD-6 |

## 3. Audit and threat model

Attacker: code execution as the runtime user (a PHP RCE), between or during deploys; secondarily the web server
identity `www-data`. Asset: root on the host, hence every patient record, key and backup.

| # | Vector (before this sprint) | Severity | Disposition |
|---|---|---|---|
| T1 | Root `require`s runtime-writable PHP: composer's `post-autoload-dump` (`artisan package:discover`), `artisan migrate` and ~50 gates boot Laravel, which loads `bootstrap/cache/{config,services,packages,routes-v7,events}.php` | **CRITICAL** (direct root code execution) | **Fixed**: every artisan call as the runtime user; composer `--no-scripts`, post-autoload-dump replayed as the runtime user |
| T2 | Root unserializes cache entries the runtime can write (file cache; the database cache too, since the runtime holds the database credentials) | **CRITICAL** | **Fixed** by T1: root runs no application PHP |
| T3 | Rollback rebuilt `config:cache` (database password, application key) and ran the smoke **as root** | **HIGH** | **Fixed**: as the runtime user |
| T4 | Root appends to `storage/logs/*.log` and redirects `--json > storage/release-evidence/…` — a planted symlink makes root write/truncate any host file | **HIGH** | **Fixed**: evidence via `runtime_json` (redirect opened by the runtime user); no root log writes |
| T5 | `find … -exec chmod {} \;` as root: a file swapped for a symlink between `lstat` and `chmod` turns a 0664 chmod onto `/etc/shadow` | **HIGH** | **Fixed**: modes set by the runtime user (`dms_privsep_normalize_modes`, refuses root) |
| T6 | `chmod -R u+rwX/o-rwx` as root on a private path the runtime can replace with a symlink (command-line operand is followed): `o-rwx` host-wide (reproduced in rule 231 audit A10) | **HIGH** | **Fixed** (as T5); a symlinked private path or backup tree is NOT GO |
| T7 | `chown -R` as root over the runtime trees (hardlink to a root file → ownership handed to the runtime) | MEDIUM (needs `protected_hardlinks=0`) | **Fixed**: lchown of foreign entries only, from inside their directory, single-link files only; `protected_hardlinks=1` required |
| T8 | Root writes the pre-deploy dump into the runtime-owned backup tree (`> file` follows a planted link; `noclobber` only partly helps) | MEDIUM | **Fixed**: dump written by the runtime user; SHA-256 recorded in the root-only deploy log |
| T9 | `harden-secret-permissions.sh` chmods dumps by path as root inside the runtime-owned backup tree | MEDIUM | **Fixed**: chmod only via `runuser -u <runtime user>`; anything else reported and fails verification |
| T10 | `mkdir -p storage/…` as root (runtime-controlled path) | LOW | **Fixed**: created by the runtime user |
| T11 | `public/build` owned by `www-data 775`: the web server identity can rewrite the application's JavaScript, and root's `npm run build` writes through a directory it controls | MEDIUM | **Fixed**: source tree claimed top-down for root before root writes there; drift gated |
| T12 | Runtime write failures from root-owned files (the post-login 500 class) | availability | **Fixed** structurally: root creates nothing in the runtime trees; repair + gate catch leftovers |
| T13 | Queue worker / scheduler / logs | — | Queue worker untouched (never started by the deploy, AIR-DEP-26); no scheduler invoker; logs written only by the runtime user |
| T14 | Rollback privilege boundary | — | Same separation; snapshot from the CURRENT code, so a rollback to a pre-233 release still runs separated |
| T15 | SSH command injection / CI-VPS trust boundary | — | Unchanged and sound: no workflow opens a remote session (AIR-DEP-31); the runner runs on the VPS only; release id and SHA are validated before use (rule 229/231) |
| T16 | Secret leakage into deploy logs | — | Unchanged: no secret is printed; drift summaries print owner, type and ≤ 3 path segments, never a file name; the dump's digest, not its content |

**Rule 231 §8c review.** S1 (rollback by tag fetches an unapproved tag) is reduced by this sprint — the rolled-back
commit's artisan code no longer runs as root — but composer/npm still run as root on that commit: **always roll back
by full SHA** stays in force (OD-2). S2–S8 are unchanged and stay open as listed there; none is a GO blocker here.

## 4. Design

`RELEASE CONTROLLER (owner signature, rule 231) → VPS runner (root, snapshot) → privileged steps only → runtime user`.

| Step | Identity | Why |
|---|---|---|
| Host preflight, approval, lock, snapshot, git fetch/checkout, `composer install --no-scripts`, `npm ci`/`build`, `systemctl restart php8.3-fpm`, `nginx -t`/reload | root | writes root-owned trees or manages services |
| Kernel guards, runtime-root assertion, foreign-entry repair (lchown), source claim, drift gate, secret hardening of the environment file, isolation verifier | root | ownership and read-only verification; no path writes in runtime trees |
| Post-autoload-dump, `migrate --force`, backup verify, every governance gate, evidence capture/check, cache rebuild, auth-landing smoke, smoke, mode normalization, the dump | runtime user | the application's own identity; holds the database credentials already |

Library: `scripts/lib/runtime-privsep.sh` (closure member). Contract: `config/deployment_rollback.php`
`privilege_separation`, enforced by `DeploymentRollbackScanner::privilegeSeparationIssues()` in
`foundation:deployment-rollback-check` (CI and the VPS deploy). Read-only operator check:
`scripts/privsep-drift-check.sh`.

## 5. Implementation (files)

- `scripts/lib/runtime-privsep.sh` (new), `scripts/privsep-drift-check.sh` (new).
- `scripts/deploy-vps.sh`, `scripts/rollback-vps.sh`: as above; the rollback now also checks the runtime user's
  primary group like the deploy.
- `scripts/harden-secret-permissions.sh`: `--runtime-user`, dump chmod dropped to the runtime user.
- `scripts/provision-runtime-identity.sh`: same library (review R4).
- `tailwind.config.js`: content globs only — the framework view sources instead of the runtime-owned compiled views
  (review R1); generated CSS byte-identical.
- `app/Support/Deploy/DeploymentRollbackScanner.php`, `config/deployment_rollback.php` (markers + contract),
  `config/deployment_entrypoint.php` (closure), `config/ci_runner.php` (mandatory suite).
- Tests: `tests/Feature/Deploy/DeployHardenPrivilegeSeparationTest.php` (new); five suites re-pointed from the old
  root code to the new mechanism with the same intent.
- Rules and docs: rule 233, AIR-DEP-39 (+ AIR-DEP-11/14/33/34/37 updated to stay within the 30,000-character budget),
  CLAUDE.md CG-15 (one sentence), AGENTS.md (pointer), runbook `production-host-identity.md` §6b step 5 + rollback note.

Unchanged: schema, migrations, permissions, roles, routes, JS/CSS, packages, flags, branch locks, queue worker,
timers, `deploy-immutable-exec.sh`, `deploy-vps-runner.sh`, the release approval contract.

## 6. Tests and quality gates (local, WSL2, PHP 8.3)

| Gate | Result |
|---|---|
| `tests/Feature/Deploy` (16 suites, after the review fixes) | **590 passed**, 0 failed (warnings = this worktree has no environment file; CI writes an empty one) |
| `DeployHardenPrivilegeSeparationTest` | **89 passed**, 0 skipped (secondary-group cases ran) |
| Scanner + ENT-11/12/15/16 governance suites | 65 passed |
| Scanner on the real scripts | deploy and rollback `ok=true`, `privilege_separation=true`; provisioning clean; 33 injected regressions each flagged (18 from the review), 9 legitimate forms accepted |
| Read-only-tree rehearsal (§7) | 82 runtime steps, **0 permission failures**, 0 files written outside the runtime trees; exit codes identical to a writable tree |
| `sprint:test --focused` (Architecture, Cicd, Foundation), final tree | **3,232 passed**, 0 failed, exit 0 (first run before the review fixes: 2,413 passed) |
| Instruction-system, host-identity, shell-guard, BK-2, release-evidence, ENT-15 suites (re-run after the last text edits) | 99 passed |
| Pint | passed; `bash -n` passed on every script under `scripts/` |
| Asset build (`npm run build`, 537 compiled views present) | CSS byte-identical before/after the Tailwind content change (`c01c3703…`) |
| ShellCheck | **NOT RUN** — not installed; downloading it is not permitted on this workstation |
| Full Suite | **DEFERRED** (CG-18), not passed |

## 7. Rehearsal: the gates as the runtime user

Production is the only place the runtime user meets a root-owned checkout, so the risk was a gate that writes
outside `storage/`/`bootstrap/cache` and would fail the deploy after the migration. A copy of this release with
every source entry made read-only (0 writable entries outside the runtime trees), an isolated PostgreSQL 16 schema,
and every runtime line of `deploy-vps.sh` in order (82 steps, from the post-autoload-dump to the smoke): 0
permission-shaped errors, 0 files written outside the runtime trees. The same run with a writable tree (24,439
writable entries) gave **identical exit codes for all 82 steps**; the 31 non-zero exits are the production-mode
gates on a workstation (host facts, empty schema). The copy and the schema were removed by the rehearsal's trap. On
the host, everything a gate reads is readable by the runtime user (§2).

## 8. Independent review

A separate agent reviewed the uncommitted change adversarially, given the code and the threat model only (read-only,
no production access, its own temporary directory, deleted afterwards). It found **no path where root runs code the
runtime user controls, `require`s its PHP, or chowns/chmods a host file through a symlink** in the new flow, and
confirmed as sound: the run-as-runtime wrappers (no injection; uid 0 refused; `BASH_ENV`/`LD_PRELOAD` stripped by
sudo's environment reset; lock descriptors 7–9 already closed by the bootstrap), `PGPASSWORD` reaching `pg_dump`
through `runuser`, the `-execdir` repair, the top-down claim order (verified with GNU find 4.9), the composer replay,
umask 027, the final gate's false-drift exposure, and the units/nginx identities. Its findings, all fixed in this PR
before it was opened:

| # | Finding | Severity | Disposition |
|---|---|---|---|
| R1 | `tailwind.config.js` scanned `./storage/framework/views/*.php`: root's `npm run build` read runtime-owned files and followed symlinks (a link to `/proc/kmsg` hangs the deploy inside the lock; a root-only file's tokens could reach the public CSS) | MEDIUM | **Fixed**: the build scans the three framework view directories those compiled views come from (`vendor/`, root-owned). Verified on 537 compiled views: generated CSS **byte-identical** (same SHA-256, same asset name) |
| R2 | Runtime children inherited the root-only deploy log descriptor (`ftruncate` works on `O_APPEND`): log and dump digest forgeable; a daemon could keep it | MEDIUM | **Fixed**: `/dev/null` stdin, stdout/stderr through a pipe copied by a root `cat`, every other descriptor closed; exit status preserved (tested with and without `pipefail`). `setsid` not needed: `dev.tty.legacy_tiocsti=0` on the host |
| R3 | Scanner bypasses (`timeout`/`nice php`, `/usr/bin/php`, `php8.3`, backticks, `bash -c`, `2>`/`&>`, quoted `$APP_DIR`, `tee -a`, `&& chmod`, `xargs chmod`, `-exec /bin/chmod`, `source storage/…`, the pre-233 dump lines, multi-line composer) and false alarms on trailing comments | MEDIUM | **Fixed**: logical lines, token-based `php`/composer detection with explicit runtime hand-offs, broader patterns; all 18 bypasses are test cases, false alarms are pass cases |
| R4 | `scripts/provision-runtime-identity.sh` (manual, `--apply`) still ran `chown -R`, `find -exec chmod`, `chmod -R` and `artisan down/up` as root — a symlink race onto the environment file could lead to root code execution at the next deploy | MEDIUM | **Fixed**: same library (repair, runtime-side modes, runtime-user maintenance mode); legacy dumps in the app-level `backups/` changed only inside a proven root-only directory |
| R5 | `find` exits 1 when PHP-FPM deletes a session file mid-walk (reproduced 13/60 under churn) → abort after migrate; the switch from `\;` to `+` made the widening fragile; errors inside `dms_privsep_prepare` were swallowed (errexit off under `\|\|`) | LOW | **Fixed**: `-ignore_readdir_race` on every walk, per-entry widening as before rule 233, explicit error returns, recounts after repair and claim |
| R6 | A release adding a tracked directory under `storage/` leaves it root-owned until the end; runtime steps write there first | LOW | **Fixed**: ownership repaired right after the checkout |
| R7 | The claim had no hardlink guard (a `www-data`-owned inode could be taken over) | LOW | **Fixed**: hardlinked and special files are refused, as in the repair |
| R8 | git as the runtime user stops at "dubious ownership": release evidence would lose its commit and GO tag | LOW | **Fixed**: command-scope `safe.directory` for exactly the app directory (reproduced and verified with git's own different-owner switch) |
| R9 | The drift check sourced its library and the identity file from the tree it inspects | LOW | **Fixed**: both (and their directories) must be owned by the trusted uid and not group/other-writable, else exit 2 |
| R10 | Root sources the environment file; a file another identity could have written would be executed (pre-existing) | LOW | **Fixed**: `dms_privsep_assert_trusted_file` on the environment file before root sources it (deploy: before the hardening, which would hide the evidence). `restore-rehearsal.sh` streaming runtime-owned dumps to `psql` as the `postgres` superuser stays a residual (§9 R4) |

## 9. Residual risks and open owner decisions

- **OD-2** (rollback floor) and rule 231 §8c S1–S8: unchanged; roll back by full SHA only.
- **OD-6 (new) Admin account sudo.** The deploy account has unrestricted passwordless sudo. A dedicated deploy account
  limited to the runner is the least-privilege end state, but changing the only administrative path risks lockout and
  is an owner decision; not changed here.
- **R1** composer and npm still run as root (supply chain trusted through the lockfiles). A dedicated build account
  owning `vendor/`, `node_modules/` and `public/build` would remove that; it changes AIR-DEP-11 and needs host
  provisioning — owner decision.
- **R2** `git checkout` (root) writes tracked files that live under `storage/` (the `.gitignore` files and five
  `storage/*.php` smoke scripts); git refuses to write through a symlinked leading path, the window is narrow, and the
  repair hands them to the runtime user. Moving those files out of `storage/` removes it. Never run those scripts as
  root.
- **R3** Runtime children inherit the deploy log as stdout/stderr (pre-existing for the cache commands); a compromised
  runtime can add lines to the log but cannot change the root runner's recorded exit code (`verify` checks both).
- **R4** A compromised runtime can still alter dumps it owns after they are written; the deploy log keeps each dump's
  SHA-256 for comparison, and restores stay owner-approved and supervised (AIR-DEP-16). `restore-rehearsal.sh` and
  `restore_postgres.sh` stream a dump to `psql` as the `postgres` superuser, where a planted meta-command would run
  as that OS account: restore only a dump whose digest matches the deploy log. Recommended follow-up: verify the
  digest in the restore tools, or restore through `pg_restore` from a custom-format dump.

## 10. Release states

| State | Evidence |
|---|---|
| Implemented, locally verified | §6, §7 |
| Reviewed | §8: no CRITICAL/HIGH; 10 findings (4 MEDIUM, 6 LOW) fixed before the PR |
| PR / CI / merge | pending |
| Release approval (owner signature) | pending |
| Production deploy + verification | pending |
| GO tag | pending |

**BK-2:** `SKIPPED — DMS-BK2-MANUAL-ONLY-1 (Owner Directive)`
