# VPS Deploy & Rollback Runbook (ENT-15)

> **Production target (since 2026-11-03):** Biznet host `DaengtisiaMS` (`139.190.106.38`), reached with
> `ssh daengtisiams-biznet-prod` as `dmsadmin`; privileged steps run through `sudo -n`. The Hostinger host
> `srv1730088` is WRITE-FROZEN. Prove the target first: `bash scripts/production-target-check.sh --remote`
> (`docs/runbooks/production-host-identity.md`).
>
> **No deploy has run on Biznet yet.** The first one needs explicit owner approval and the readiness checklist
> in `docs/runbooks/production-host-identity.md` §6 closed (Node/npm are not installed there).

Durable governance: `docs/architecture/enterprise-documentation-runbook-governance.md`.
Verified by: `php artisan foundation:enterprise-documentation-check --strict`.

## Purpose

Deploy a GO-tagged release to the VPS pilot safely (ENT-10 CI/CD gate, ENT-11
deploy automation) and roll back to a prior GO tag if a release regresses. Covers
the **deploy and rollback** topics.

## When to Use

- After a sprint PR is merged and GO-tagged, to deploy that tag to the pilot.
- When a deployed release regresses and must be reverted to a known-good GO tag.

## Prerequisites

- Merged PR into the base branch and an annotated GO tag on the merge commit.
- `ssh daengtisiams-biznet-prod` reaches `/var/www/asia-dental-lab-v2` and
  `bash scripts/production-target-check.sh --remote` reports `PRODUCTION_TARGET=VERIFIED`.
- CI required gates green; local `foundation:cicd-enterprise-gate-check --strict`
  and `foundation:deployment-rollback-check --strict` both GO.

## Safe Commands

**One canonical deploy command. It runs ON the production host, as root through `sudo -n`, and nowhere else.**

```
# Rule 229: <release-id> and the full <APPROVED_SHA> come from the release approval record merged on the base
# (deploy/release-approvals/<release-id>.approval). Without an approved release the runner prints
# DEPLOYMENT=DENIED (exit 77) and launches nothing. The first deploy that carries the guard uses
# docs/runbooks/production-host-identity.md section 6c.
# Rule 231: the record must also carry the approver's signature (<release-id>.approval.sig) that verifies against
# the host's release trust anchor; the first deploy that carries rule 231 uses section 6d.
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh start --release-id <release-id> --approved-sha <APPROVED_SHA>'
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh status'
```

**A manual pre-pull is NOT required (DEPLOY-HARDEN-1).** A production checkout
that is behind the target is advanced to the pinned commit by the deployment
itself. Do not hand-pull before deploying.

What happens inside one run:

1. **Exclusive lock** — `flock` on `/run/daengtisiams-deploy/release.lock`
   (root-controlled, never under `storage/`), the ONE lock shared by deploy and
   rollback since rule 231; the older `deploy.lock` and `rollback.lock` are taken
   too. A second deploy, or a rollback, fails closed with exit 75 before any
   backup, migration or checkout mutation. Inside the lock, leftovers of a killed
   run (snapshot directories, private refs) are reconciled first.
2. **Exact target pinned** — the remote is fetched and `TARGET_SHA` frozen. If
   origin advances afterwards, that commit belongs to the NEXT deployment.
3. **Immutable execution snapshot** — the deployment payload (`scripts/`,
   `deploy/`) is exported from the pinned git **object** into a per-run 0700
   root-owned directory, trust-verified (owner, mode, symlink), and the live
   runtime identity authority is overlaid onto it.
4. **The deploy runs from that snapshot**, so the `git checkout` further down
   cannot rewrite the bytes being interpreted. Post-mutation helpers
   (`harden-secret-permissions.sh`, `verify-runtime-isolation.sh`) also come
   from the snapshot, never from the freshly rewritten tree.
5. Backup → advance checkout to `TARGET_SHA` → `php artisan migrate --force` →
   gates → cache rebuild → ownership → secret hardening → runtime isolation →
   restart → smoke → **HEAD must equal `TARGET_SHA`** → `DEPLOY OK`.
6. Snapshot removed, lock released — on every exit path, including a crash.

Starting the launcher is **not** a completed deployment. The authority is
`exit=0` **and** the `DEPLOY OK` marker.

Rollback to a prior GO tag (same lock/pin/snapshot protection, backup-first, no
data restore):

```
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/rollback-vps.sh <prior-go-tag>'
```

The operator's tag is resolved to one exact commit **before** any mutation, and
the rollback snapshot is taken from the **current** code — so INFRA-SEC-ENV-1
secret hardening and the INFRA-SEC-RUNTIME-1 isolation verifier still run in
their modern form even when the target predates them.

Migration on the VPS is always `php artisan migrate --force` and never destructive.

### One-time transition (DEPLOY-HARDEN-1 release only) — historical

This one-time bootstrap was run on the Hostinger host for the DEPLOY-HARDEN-1 release (2026-08-15) and is never
needed again: Biznet already carries `scripts/deploy-immutable-exec.sh`. The original procedure is preserved in
`docs/sprints/deploy-harden-1-immutable-deployment-entrypoint-self-update-safety.md`.

## Forbidden Commands

Never run these during deploy or rollback on the pilot/production VPS:

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- `php artisan schema:drop`
- `php artisan migrate:reset`
- Any automatic production data restore during deploy; production data restore is
  the separate, explicit `scripts/restore_postgres.sh` step only.

## Evidence

- Pre-deploy backup filename + size (e.g. `pre_auto_deploy_<ts>.sql`).
- Pre-deploy backup owner/group/mode (expected `0640`, directory `2750`, no
  default ACL) — metadata only; never print or grep dump contents. The dump is
  created 0600 by `scripts/lib/private-db-dump.sh` and set to 0640 by the
  deploy's backup-tree normalization (rule 176).
- Release-evidence VPS profile all GO (`release:evidence-check --profile=vps`).
- Automated smoke result and the deployed GO tag exact-match at VPS HEAD.
- DEPLOY-HARDEN-1 immutable-execution proof, printed by the run and kept in
  `/var/lib/daengtisiams-deploy/runner/deploy-<ts>.log` (rule 231: root-only, outside
  the checkout; runs before rule 231 are in `storage/logs/deploy-runner/`):
  `DEPLOY_LOCK_ACQUIRED`, `DEPLOY_TARGET_PINNED`, `DEPLOY_RUN_ID`,
  `DEPLOY_SNAPSHOT_CREATED`, `DEPLOY_SNAPSHOT_TRUSTED`,
  `DEPLOY_EXECUTION_SOURCE`, `SOURCE_DEPLOY_SCRIPT_SHA256` /
  `SNAPSHOT_DEPLOY_SCRIPT_SHA256` (equal at capture time),
  `DEPLOY_HEAD_TARGET_MATCH`, `DEPLOY_SNAPSHOT_CLEANED`.
- Deployment logs under `/var/lib/daengtisiams-deploy/runner/` (and, for runs
  before rule 231, `storage/logs/deploy-runner/`) are audit evidence and are
  retained; never edit, move or delete them. Execution snapshots are disposable
  and are removed on every exit (or by the next run, after a kill).

## Rollback / Fallback

- If deploy gates FAIL, the script aborts before restarting runtime — fix forward
  or run `scripts/rollback-vps.sh <prior-go-tag>`.
- Rollback records the current ref, takes a backup, checks out the target tag,
  re-verifies ENT-5..15 gates, rebuilds/restarts, and smokes — it never runs a
  destructive DB command and never auto-restores data.

- Private storage paths (SECURITY-F8-PRIVATE-STORAGE-PATHS-1): rolling back to
  an older tag keeps them private, because rollback reads the runtime identity
  file from the live host copy, which still lists them. A git revert of this
  change is different: the deploy that lands the revert still restricts them for
  the same reason, but every later deploy widens them again. Treat a revert as
  reopening the exposure and re-contain the paths. Never widen a private tree by
  hand.

## Troubleshooting

- Route-dependent gate fails on first run after a route-adding sprint → the VPS
  may hold a stale route cache; the script clears route/config cache before gates
  (ENT-8). Re-run once if a first-run governance flake appears.
- `nginx -t` fails → do not reload; fix the config first.
- **`BUSY another deploy or rollback holds the release lock` (exit 75)** (before
  rule 231: `BUSY another deploy is already running`) → a deploy or a rollback
  already holds the lock. This is correct, fail-closed behaviour: nothing was
  backed up, migrated or checked out. The message names the last recorded holder.
  Identify it and wait for it:

  ```
  ssh daengtisiams-biznet-prod 'sudo -n flock -n /run/daengtisiams-deploy/release.lock -c true \
    && echo "lock FREE" || echo "lock HELD"'
  ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh status'
  ```

  The runner also refuses a second `start` (`BUSY deploy <id> is still running`,
  exit 75) while the first run's launcher is alive, and records nothing for it.

  The lock **file** existing on disk is normal and does not mean the lock is
  held; `flock` is the authority. Never delete the lock file to "unstick" a
  deploy — a killed holder releases it automatically.
- **`DEPLOY RUNNER STATUS INTERRUPTED`** → the run's launcher died (SIGKILL,
  host restart) without a status. It is not evidence of a deploy and its approval
  is not spent. Read its log to see how far it got; confirm the lock is free (as
  above) and that no `dms-deploy-runner` process remains; then decide, with the
  owner, between re-running the same approved release and a rollback. The next
  run removes the dead run's snapshot by itself.
- **`stale git lock …`** (exit 2, before any backup) → a run was killed during a
  checkout or merge (`index.lock`, `HEAD.lock`, `packed-refs.lock`, `config.lock`
  or a `refs/**/*.lock`), or a git command is running right now. Confirm no git
  process runs on the host (`sudo -n pgrep -a git` prints nothing), inspect
  `git status`, and only then remove exactly the file the message names, e.g.
  `sudo -n rm -- /var/www/asia-dental-lab-v2/.git/index.lock`. Never remove one
  while any git or deploy process is alive.
- **`release <id> was already deployed on this host`** (exit 77) → a release
  deploys once (the spend record is written inside the lock by the deploy that
  completed it). A re-deploy of the same commit (for example after a rollback)
  needs a new owner approval, recorded as a new signed record under a new release
  id (e.g. `<release-id>-r2`).
- **Rollback `is an abbreviated commit id and is ambiguous`** → pass the full
  40-character commit SHA, or a GO tag name. Other names are looked up only as
  tags (`refs/tags/<name>`), never as branches.
- **`DEPLOY RUNNER STATUS NOT-FOUND`** → this deploy id is not in
  `/var/lib/daengtisiams-deploy/runner`; runs launched before rule 231 kept their
  evidence in `storage/logs/deploy-runner/` and were verified at the time.
- **`immutable deployment bootstrap missing`** → the checkout predates
  DEPLOY-HARDEN-1. Use the one-time transition above. Never work around it by
  invoking the deploy script some other way; the refusal is deliberate.
- **`not running from the immutable snapshot`** → the deploy script was invoked
  directly with a forged environment. Use the canonical runner command.
- **`final HEAD ... does not match the pinned target`** → something moved the
  checkout mid-deploy. Do not re-run blindly; inspect `git status` and the
  runner log first.
- **`tracked files are modified in the production checkout`** → a human edited
  the deployed tree. Investigate and resolve deliberately. Do not discard the
  change to make the deploy pass.

## Smoke Verification

- `php artisan foundation:roadmap-check --strict` → GO, next not stale.
- `curl -I http://127.0.0.1/login` → HTTP 200.
- Health endpoints 200; `/dev-console` guest → 302.
- `git describe --tags --exact-match HEAD` equals the deployed GO tag.
- Private storage: the deploy log prints `Private storage paths (live + target)`
  and the isolation gate reports every listed path as private. Spot-check one
  path with `runuser -u www-data -- test -r storage/app/clinical-evidence-private`
  (must fail) and `sudo -n runuser -u daengtisiams -- test -r <same path>` (must succeed).
- Configuration cache (SECURITY-F8B-CONFIG-CACHE-PRIVATE-1): `bootstrap/cache`
  holds the database password and the application key once `config:cache` runs,
  so it is a private path too. `runuser -u www-data -- test -r
  bootstrap/cache/config.php` must fail. A deploy of a release older than that
  change fails closed on a host that declares the path; roll back with
  `scripts/rollback-vps.sh` instead.

## Security / PII Notes

- The deploy script never prints secrets or the environment file; DB credentials
  are read from the server environment, never echoed.
- Evidence artifacts are non-sensitive (no KTP/NIK, no credentials).

## Owner / Reviewer

- Owner: Release engineer. Reviewer: Enterprise Foundation governance owner.

## Review Cadence

- Review each release-safety sprint (ENT-10/ENT-11) and at least quarterly.
