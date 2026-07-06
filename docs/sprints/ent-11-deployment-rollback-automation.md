# ENT-11 — Deployment & Rollback Automation

Branch: `feature/ent-11-deployment-rollback-automation`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Category: `release_safety` · Depends: ENT-10 · Related shipped: NSF-10, LB-1

## Scope source

`config/foundation_roadmap.php` → `ENT-11` (title *Deployment & Rollback
Automation*). Objective: automate the VPS deploy path
(backup → deploy → cache rebuild → permission reset → smoke) and a tested
rollback path, building on the existing deploy script. Production safety rule:
backup failure stops deploy; every deploy has a rollback plan.

## What shipped

Implementation-heavy release-safety sprint — real runtime automation plus a
read-only governance gate:

- **`scripts/rollback-vps.sh`** (new) — rehearsable rollback to a prior GO
  tag/commit: records current ref → pg_dump backup → checkout target ref →
  composer/npm build → clear cache → re-verify ENT-5..11 gates → rebuild cache →
  reset permissions → restart php-fpm + nginx → smoke. Fail-fast
  (`set -euo pipefail`), no destructive DB command, no automatic data restore.
- **`scripts/deploy-vps.sh`** (hardened) — now also runs
  `foundation:deployment-rollback-check` and captures its evidence artifact,
  after the preserved ENT-8 route/config cache-clear ordering.
- **`config/deployment_rollback.php`** (new) — read-only registry of required
  markers, destructive-command patterns (config-not-code), evidence + safety
  expectations.
- **`App\Support\Deploy\DeploymentRollbackScanner`** (new) — read-only posture
  checks: deploy script, rollback script, evidence profiles, release-safety.
- **`App\Services\Foundation\DeploymentRollbackGovernanceService`** (new) —
  publishes `deployment_rollback_governance` (ENT11-DR001..DR012) into the
  foundation governance summary; re-verifies ENT-5..10 GO. Informational only,
  not in the blocking combined decision.
- **`foundation:deployment-rollback-check`** (new command) — `--json`,
  `--strict`, `--fail-on-warning`. Non-zero on FAIL (and WATCH under strict).
- Wiring: `release_evidence.php` (ci/vps profiles + service job map),
  `release_safety.php` (pre-deploy gate + rollback script), `foundation_governance.php`
  (ENT-11 CI-gate registry), CI workflow + `scripts/ci/foundation-evidence-gates.sh`,
  `config/foundation_roadmap.php` (ENT-11 completed, next → ENT-12).
- Docs: `docs/architecture/deployment-rollback-automation-governance.md`, this
  sprint doc, freeze-rules reference, `.cursor/rules/60-deployment-rollback-automation.mdc`.

## No-change guarantees

No migration, no route, no permission, no queue driver change, no queue worker
enabled, no business workflow change. ENT-5..ENT-10 governance unchanged and
still required GO. KTP/NIK never exposed; no secret/backup contents in artifacts.

## Evidence

- `foundation:deployment-rollback-check --strict` → GO (11/11 checks).
- `foundation:roadmap-check --strict` → GO, next `ENT-12`, not stale.
- ENT-5..ENT-10 strict governance checks → GO.
- `deployment-rollback-check.json` required in ci + vps evidence profiles.
- `bash -n scripts/deploy-vps.sh` / `scripts/rollback-vps.sh` clean.
