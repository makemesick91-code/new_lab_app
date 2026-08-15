# VPS Deploy & Rollback Runbook (ENT-15)

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
- `ssh daengtisiams-vps` reaches `/var/www/asia-dental-lab-v2`.
- CI required gates green; local `foundation:cicd-enterprise-gate-check --strict`
  and `foundation:deployment-rollback-check --strict` both GO.

## Safe Commands

**One canonical deploy command. It runs ON the production VPS and nowhere else.**

```
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && bash scripts/deploy-vps-runner.sh start'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && bash scripts/deploy-vps-runner.sh status'
```

**A manual pre-pull is NOT required (DEPLOY-HARDEN-1).** A production checkout
that is behind the target is advanced to the pinned commit by the deployment
itself. Do not hand-pull before deploying.

What happens inside one run:

1. **Exclusive lock** — `flock` on `/run/daengtisiams-deploy/deploy.lock`
   (root-controlled, never under `storage/`). A second deploy fails closed with
   exit 75 before any backup, migration or checkout mutation.
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
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && bash scripts/rollback-vps.sh <prior-go-tag>'
```

The operator's tag is resolved to one exact commit **before** any mutation, and
the rollback snapshot is taken from the **current** code — so INFRA-SEC-ENV-1
secret hardening and the INFRA-SEC-RUNTIME-1 isolation verifier still run in
their modern form even when the target predates them.

Migration on the VPS is always `php artisan migrate --force` and never destructive.

### One-time transition (DEPLOY-HARDEN-1 release only)

A host still running the pre-DEPLOY-HARDEN-1 launcher has no bootstrap in its
checkout, so the new deploy script would abort (fail closed — it never falls back
to the unsafe path). For that single release, run the target's own deployment
payload from a root-controlled path outside the checkout:

```
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 \
  && git fetch --no-tags origin <base-branch> \
  && install -d -m 0700 /root/dh1-bootstrap \
  && git archive --format=tar <MERGE_SHA> -- scripts | tar -x -C /root/dh1-bootstrap \
  && DEPLOY_SCRIPT=/root/dh1-bootstrap/scripts/deploy-vps.sh \
     bash /root/dh1-bootstrap/scripts/deploy-vps-runner.sh start'
```

This mutates nothing in the working tree, reads only tracked bytes of the merged
commit, and hands over to the same immutable bootstrap. Afterwards the canonical
in-tree command above is the permanent path and this transition is never needed
again.

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
- Release-evidence VPS profile all GO (`release:evidence-check --profile=vps`).
- Automated smoke result and the deployed GO tag exact-match at VPS HEAD.
- DEPLOY-HARDEN-1 immutable-execution proof, printed by the run and kept in
  `storage/logs/deploy-runner/deploy-<ts>.log`:
  `DEPLOY_LOCK_ACQUIRED`, `DEPLOY_TARGET_PINNED`, `DEPLOY_RUN_ID`,
  `DEPLOY_SNAPSHOT_CREATED`, `DEPLOY_SNAPSHOT_TRUSTED`,
  `DEPLOY_EXECUTION_SOURCE`, `SOURCE_DEPLOY_SCRIPT_SHA256` /
  `SNAPSHOT_DEPLOY_SCRIPT_SHA256` (equal at capture time),
  `DEPLOY_HEAD_TARGET_MATCH`, `DEPLOY_SNAPSHOT_CLEANED`.
- Deployment logs under `storage/logs/deploy-runner/` are audit evidence and are
  retained. Execution snapshots are disposable and are removed on every exit.

## Rollback / Fallback

- If deploy gates FAIL, the script aborts before restarting runtime — fix forward
  or run `scripts/rollback-vps.sh <prior-go-tag>`.
- Rollback records the current ref, takes a backup, checks out the target tag,
  re-verifies ENT-5..15 gates, rebuilds/restarts, and smokes — it never runs a
  destructive DB command and never auto-restores data.

## Troubleshooting

- Route-dependent gate fails on first run after a route-adding sprint → the VPS
  may hold a stale route cache; the script clears route/config cache before gates
  (ENT-8). Re-run once if a first-run governance flake appears.
- `nginx -t` fails → do not reload; fix the config first.
- **`BUSY another deploy is already running` (exit 75)** → a deployment already
  holds the lock. This is correct, fail-closed behaviour: nothing was backed up,
  migrated or checked out. Identify the holder and wait for it:

  ```
  ssh daengtisiams-vps 'flock -n /run/daengtisiams-deploy/deploy.lock -c true \
    && echo "lock FREE" || echo "lock HELD"'
  ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && bash scripts/deploy-vps-runner.sh status'
  ```

  The lock **file** existing on disk is normal and does not mean the lock is
  held; `flock` is the authority. Never delete the lock file to "unstick" a
  deploy — a killed holder releases it automatically.
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

## Security / PII Notes

- The deploy script never prints secrets or the environment file; DB credentials
  are read from the server environment, never echoed.
- Evidence artifacts are non-sensitive (no KTP/NIK, no credentials).

## Owner / Reviewer

- Owner: Release engineer. Reviewer: Enterprise Foundation governance owner.

## Review Cadence

- Review each release-safety sprint (ENT-10/ENT-11) and at least quarterly.
