# ENT-11 — Deployment & Rollback Automation Governance (Durable Lock)

Status: **LOCKED**. Verified by `foundation:deployment-rollback-check`.

ENT-11 automates and locks the VPS deploy path and a rehearsable rollback path
on top of the shipped NSF-10 release-evidence chain and the ENT-10 CI/CD
enterprise gate. It is read-only governance plus two runtime automation scripts
(`scripts/deploy-vps.sh`, `scripts/rollback-vps.sh`); it never mutates data and
never runs a destructive database command.

## Scope source

- Roadmap entry `ENT-11` in `config/foundation_roadmap.php`
  (title *Deployment & Rollback Automation*, category `release_safety`,
  depends `ENT-10`, related `NSF-10` / `LB-1`).
- Objective: automate the VPS deploy path
  (backup → deploy → cache rebuild → permission reset → smoke) and a tested
  rollback path, building on the existing deploy script.
- Production safety rule: backup failure stops deploy; every deploy has a
  rollback plan.

## Runtime surfaces

| Surface | File | Purpose |
| --- | --- | --- |
| Deploy automation | `scripts/deploy-vps.sh` | Backup → pull → build → `migrate --force` → verify → gates → cache rebuild → permissions → restart → smoke. Idempotent, fail-fast. |
| Rollback automation | `scripts/rollback-vps.sh <tag/commit>` | Record current ref → backup DB → checkout target GO tag/commit → build → clear cache → re-verify ENT-5..11 gates → rebuild cache → permissions → restart → smoke. Fail-fast, no destructive DB command. |
| Backup helper | `scripts/backup_postgres.sh` | Existing pg_dump helper. |
| Restore helper | `scripts/restore_postgres.sh <backup>` | Existing pg_restore helper — the only, explicit, guarded data-rollback step. Never automatic. |
| Governance config | `config/deployment_rollback.php` | Declares required markers, destructive-command patterns (config-not-code), evidence + safety expectations. |
| Scanner | `App\Support\Deploy\DeploymentRollbackScanner` | Read-only posture checks of both scripts + evidence + release-safety. |
| Governance service | `App\Services\Foundation\DeploymentRollbackGovernanceService` | Publishes `deployment_rollback_governance` section, re-verifies ENT-5..10 GO. |
| Command | `foundation:deployment-rollback-check` (`--json`, `--strict`, `--fail-on-warning`) | Read-only gate; non-zero on FAIL (and WATCH under strict). |
| Evidence artifact | `deployment-rollback-check.json` | Required in the `ci` and `vps` release-evidence profiles. |

## Rollback design (safe by construction)

- All migrations are additive and backward-compatible, so an older code tag runs
  against the current schema. Code rollback therefore keeps the current schema
  and runs **no** `migrate --force`, `migrate:fresh`, `migrate:reset`,
  `migrate:rollback`, `db:wipe`, `schema:drop`, `DROP DATABASE/SCHEMA`, or
  `TRUNCATE`.
- A DB backup is always taken before the rollback checkout, so the pre-rollback
  state is recoverable.
- A data rollback (restore) is a separate, explicit, guarded step:
  `bash scripts/restore_postgres.sh <backup_file>`. It is never triggered
  automatically by the rollback script.
- The ENT-8 cache-order hardening is preserved: route/config cache is cleared
  before the route-dependent governance gates, then rebuilt after.

## Rules ENT11-DR001..ENT11-DR012

- **ENT11-DR001** — Deploy is automated through the canonical deploy script.
- **ENT11-DR002** — Every deploy takes a verified DB backup before pull/migrate.
- **ENT11-DR003** — Migration safety: additive `migrate --force` only, never destructive.
- **ENT11-DR004** — A rehearsable rollback script exists and is fail-fast.
- **ENT11-DR005** — Rollback records the current ref and backs up before switching.
- **ENT11-DR006** — Rollback never performs a destructive database operation.
- **ENT11-DR007** — ENT-8 cache-order hardening is preserved in deploy and rollback.
- **ENT11-DR008** — Deploy and rollback reset permissions and restart the runtime.
- **ENT11-DR009** — Deploy and rollback re-verify the ENT-5..10 foundation stack.
- **ENT11-DR010** — Deploy/rollback evidence is captured and required per profile.
- **ENT11-DR011** — Evidence and gate output stay non-sensitive.
- **ENT11-DR012** — New deploy/rollback automation registers here with tests first.

## Integration with prior foundations

`foundation:deployment-rollback-check` re-verifies that ENT-5 queue-retry,
ENT-6 idempotency/outbox, ENT-7 developer-console, ENT-8 health-check, ENT-9
security-compliance, and ENT-10 CI/CD enterprise gate governance all remain GO.
The `deployment_rollback_governance` section is informational only and is not
wired into the blocking `combinedDecision`, mirroring the ENT-5..10 sibling
sections.

## What ENT-11 does NOT do

- No `migrate:fresh` / `db:wipe` / destructive reset on any environment.
- No automatic data restore during rollback.
- No secret, credential, backup contents, environment values, or KTP/NIK-shaped
  value written to any evidence artifact or printed by any gate.
- No queue worker enabled (still worker-ready posture only).
- No change to RME / payment / inventory / lab / dashboard workflows.
