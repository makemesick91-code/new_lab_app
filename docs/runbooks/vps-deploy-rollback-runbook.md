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

Deploy (canonical, idempotent, fail-fast):

```
ssh daengtisiams-vps 'bash -s' < scripts/deploy-vps.sh
```

The script backs up the database before pull, uses `php artisan migrate --force`
only, clears route/config cache **before** the governance gates (ENT-8 ordering),
runs every foundation gate incl. `foundation:cicd-enterprise-gate-check` and
`foundation:deployment-rollback-check`, rebuilds caches, resets permissions,
restarts php-fpm + reloads nginx, and runs the automated smoke.

Rollback to a prior GO tag (fail-fast, backup-first, no data restore):

```
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && bash scripts/rollback-vps.sh <prior-go-tag>'
```

Migration on the VPS is always `php artisan migrate --force` and never destructive.

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
