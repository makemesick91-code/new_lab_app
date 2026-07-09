# ROLL-5-1 — Five Branch Controlled Production Rollout Readiness

Branch: `feature/roll-5-1-five-branch-controlled-production-rollout-readiness`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`)
Baseline: `sprint-68-45-...-go` + `mon-1-...-go`
GO tag (on merge): `roll-5-1-five-branch-controlled-production-rollout-readiness-go`

## Purpose

Validate, harden, document, and **gate** the existing production-readiness
foundations for a **safe, staged rollout to 5 clinic branches** (Stage 1: 1
branch → Stage 2: 3 → Stage 3: 5). This is **not** a national-scale sprint, not a
feature sprint, not ENT-17, not another MON-1. It **reuses and orchestrates**
existing foundations — it does not rebuild them.

## What shipped

- **Scope A** — `config/rollout_readiness.php`: canonical registry (target=5, stages 1/3/5, 12 readiness categories, required roles, role-permission leak guards, route surfaces, required commands, includable audits, thresholds, capacity probes). No secrets.
- **Scope B** — `App\Services\Foundation\FiveBranchRolloutReadinessService`: read-only aggregation → `GO/WATCH/FAIL/UNKNOWN`. Reuses MON-1 (`FoundationMonitoringStatusService`) for app-health/storage/backup/evidence/audit signals; `BranchService` for RME-enabled active branch counts (never a request `branch_id`); `HealthCheckService` fallback. Per-category + per-stage decision. Guarded per-signal; degrades to UNKNOWN; masks sensitive values; no runtime mutation.
- **Scope C** — `php artisan rollout:five-branch-readiness` (`--json`/`--strict`/`--fail-on-warning`/`--include-audits`/`--capacity-smoke`/`--stage=1|2|3`). `--strict` fails only on unsafe FAIL; `--fail-on-warning` also fails on WATCH. `--include-audits` invokes existing audit commands (CLI-only) and reports exit status.
- **Scope D** — read-only UI `GET /foundation/rollout/five-branch-readiness` (`foundation.rollout.five-branch-readiness`), gated `auth` + `permission:view_developer_console` (Super Admin only via `Gate::before`; **no new permission**), wrapped in `config('rollout_readiness.enabled')`. Thin controller; sanitized output; never runs heavy audits/capacity smoke on page load. Cross-linked from the ENT-7 dev-console index and the MON-1 monitoring page.
- **Scope E** — `docs/runbooks/roll-5-backup-restore-drill-runbook.md`: restore-drill runbook + evidence template. Restore drill = staging/test scratch DB only; production DB never overwritten. ROLL-5 detects evidence presence (absent → WATCH).
- **Scope F** — lightweight capacity smoke (in-service): bounded, read-only COUNT probes on high-traffic tables. Opt-in via `--capacity-smoke`; slow → WATCH, broken/timeout → FAIL. Explicitly **not** a national-scale load test.
- **Scope G** — docs/rules: `docs/runbooks/roll-5-controlled-rollout-runbook.md` (stage plan, Go/No-Go, daily monitoring, rollback, incident, user-support, permission-audit, capacity-smoke SOPs, non-goals), gap map `docs/sprints/roll-5-1-existing-readiness-gap-map.md`, this sprint doc, CLAUDE.md, `.cursor/rules/69-roll-5-controlled-rollout-readiness.mdc`, MCP memory.
- **Scope H** — tests: `FiveBranchRolloutReadinessServiceTest`, `FiveBranchRolloutReadinessCommandTest`, `FiveBranchRolloutReadinessAccessTest`, `FiveBranchRolloutReadinessSanitizationTest`.

## Not duplicated (reused / orchestrated)

MON-1 monitoring, NSF-9/NSF-10 release gates, CICD-CTRL-1, ENT-8/LB-1 health,
ENT-11 deploy/rollback, ENT-12 backup/DR + restore rehearsal, inventory
procurement audit (68.45), doctor-performance access audit (FIX-PRE-68-45),
ENT-13/14 load-test baseline/projection.

## Guarantees

- Read-only; no migration; no new permission; no route rename (one additive read-only route).
- Branch isolation preserved (BranchService, never trusts request branch_id).
- No secrets / env / KTP / NIK / raw patient notes / raw logs / stack traces exposed.
- Missing optional evidence → WATCH/UNKNOWN, never a 500.
- Real unsafe issues (debug-on in prod, health down, storage not writable, role leak, broken capacity query, failing audit) → FAIL.
- Does **not** certify national scale, HA cluster, external pentest, or full DR.

## Durable rules

1. 5-branch rollout must be **staged** (1 → 3 → 5), not all at once.
2. Run `rollout:five-branch-readiness` before each rollout stage.
3. MON-1 remains the monitoring consolidation source of truth.
4. Domain audit commands (inventory / doctor-performance) remain source-specific and authoritative; ROLL-5 reports their exit status, never copies logic.
5. Restore drill must never target the production DB.
6. Readiness UI/JSON must not expose secrets or PII.
7. National-scale readiness requires a separate future scale-validation sprint.
8. Any new rollout stage must preserve branch isolation and permission boundaries.
