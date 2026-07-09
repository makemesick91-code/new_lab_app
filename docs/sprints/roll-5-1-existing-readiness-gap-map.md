# ROLL-5-1 — Existing Readiness Gap Map

Sprint: **ROLL-5-1 — Five Branch Controlled Production Rollout Readiness**
Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Baseline tags: `sprint-68-45-...-go`, `mon-1-foundation-monitoring-observability-gap-consolidation-go`

This sprint **validates, hardens, documents, and gates** the existing
production-readiness foundations for a **controlled 5-branch staged rollout**.
It is **not** a national-scale sprint, **not** a feature sprint, **not** ENT-17,
**not** another MON-1. It **reuses and orchestrates** existing foundations —
it does not rebuild them.

---

## 1. Existing rollout-related foundations (already solved — do NOT duplicate)

| Foundation | What it already provides |
|---|---|
| **ENT-8 Health Pack** | `/health/live`, `/health/ready` (+ 503), `HealthCheckService` liveness/readiness with per-component ok/degraded/down. |
| **LB-1** | `/health/lb` minimal health endpoint. |
| **ENT-11 Deploy/Rollback** | `scripts/deploy-vps.sh`, `scripts/deploy-vps-runner.sh` (SSH-safe detached), `scripts/rollback-vps.sh`, `foundation:deployment-rollback-check`. |
| **ENT-12 Backup/DR** | `scripts/backup-vps.sh`, `scripts/restore-rehearsal.sh` (non-production scratch DB), `foundation:backup-dr-check`, `foundation:backup-verify`, backups under `storage/app/backups/deploy`. |
| **ENT-13 / ENT-14** | Load-test 5-branch baseline + scale projection (analysis-only, non-production). |
| **NSF-9 / NSF-10** | Release-safety pre-deploy gates + release-evidence pipeline (`storage/release-evidence/latest/*-check.json`). |
| **Automated smoke** | `AutomatedSmokeFoundationTest` + deploy runner 7/7 smoke. |

## 2. Existing monitoring (MON-1)

- `config/foundation_monitoring.php` — canonical signal registry (health, queue, storage/cache, deploy/backup, evidence, audit signals).
- `App\Services\Foundation\FoundationMonitoringStatusService` — read-only consolidation → `GO | WATCH | FAIL | UNKNOWN`, guarded per-signal, no PII/secrets, `decide()`/`reasons()`/`summaryCounts()`.
- `php artisan foundation:monitoring-observability-check` (`--json`/`--strict`/`--fail-on-warning`/`--include-audits`).
- Read-only UI `GET /foundation/monitoring` gated by `view_developer_console` (Super Admin via `Gate::before`).

**ROLL-5-1 reuses MON-1 as the monitoring source of truth** — it embeds MON-1's
collected signals for the app-health / storage / backup / evidence / audit
categories instead of re-implementing them.

## 3. Existing CI/CD gates (CICD-CTRL / NSF)

- **CICD-CTRL-1** `scripts/ci/resolve-gates.sh` classifier + `foundation:ci-runtime-control-check` — must stay active; unknown/high-risk fails safe.
- **NSF-9** `foundation:release-safety-check`; **NSF-10** release-evidence gate.
- **ENT-9** `foundation:security-compliance-check`; **ENT-10** `foundation:cicd-enterprise-gate-check`; **ENT-15** `foundation:enterprise-documentation-check`; `architecture:ui-governance-check`; `foundation:roadmap-check`.

## 4. Existing deploy / backup / smoke evidence

- Deploy runner writes DB backup before pull/migrate, runs `migrate --force` only, rebuilds caches, resets permissions, reloads php-fpm/nginx, captures `*-check.json` evidence, runs automated smoke.
- Backups: `storage/app/backups/deploy/*.sql`. Restore rehearsal (ENT-12): scratch DB only, never production.

## 5. Existing inventory / RME / doctor audit commands

- `inventory:procurement-workflow-audit --strict` (Sprint 68.45) — Kepala-Cabang PO leak = FAIL.
- `rme:doctor-performance-access-audit --strict` (FIX-PRE-68-45) — unlinked-doctor / role-leak detection.
- These are **source-specific and authoritative**. ROLL-5-1 **invokes** them (via `--include-audits`) and reports exit status — it never copies their logic.

## 6. Existing branch isolation mechanisms

- `App\Modules\Branch\Services\BranchContext` — `id()`, `requireId()`, `rmeBranchId()`, `requireRmeBranchId()`, `inventoryBranchId()`, `forUser()`; never trusts request `branch_id`.
- `App\Modules\Branch\Services\BranchService` — `listActive()`, `listRmeEnabled()`, `rmeEnabledIds()`, `defaultBranch()`.
- `Branch` model — `is_active`, `is_rme_enabled`, `scopeRmeEnabled()`, `MAIN_CODE`. MAIN excluded from RME/clinic selection.

## 7. Existing permissions / roles relevant to 5-branch rollout

- Roles (RoleSeeder): Super Admin (`*`), Owner, Admin Klinik, Doctor, Kasir, Perawat, Admin Warehouse, Kepala Cabang.
- `view_developer_console` (Super Admin only via `Gate::before`) — gates the MON-1 / dev-console surfaces; ROLL-5-1 reuses it (no new permission).
- Kepala Cabang is PR-create-only (no `manage_purchase_order`) — the single server-side chokepoint is `PurchaseOrderPolicy::create`.

## 8. Already solved — must NOT be duplicated

- Health probing (ENT-8/LB-1), deploy/rollback automation (ENT-11), backup/DR + restore rehearsal (ENT-12), release safety/evidence (NSF-9/10), CI runtime control (CICD-CTRL-1), monitoring consolidation (MON-1), inventory procurement audit (68.45), doctor-performance access audit (FIX-PRE-68-45), load-test baseline/projection (ENT-13/14).

## 9. Actual gaps ROLL-5-1 fills

1. **No single "is the app ready to roll out to 5 branches, in stages" answer.** MON-1 answers "is the platform healthy now"; it does not answer "are the 5-branch rollout preconditions (branch data, roles, RME/cashier/inventory surfaces, restore-drill evidence) met for stage 1 / 2 / 3".
2. **No rollout stage model** (1 → 3 → 5 branches) with per-stage readiness gating.
3. **No branch-count / required-role / route-surface readiness signals** oriented to rollout.
4. **No restore-drill evidence detection** feeding a readiness decision (WATCH until performed).
5. **No lightweight capacity smoke** wired into a readiness decision for a controlled 5-branch scale (ENT-13/14 are non-production analysis, not a per-deploy readiness gate).
6. **No controlled-rollout runbook / Go-No-Go / daily-monitoring / incident SOP** consolidating the above for operators.

### What ROLL-5-1 adds (orchestration, not reinvention)

- `config/rollout_readiness.php` — canonical 5-branch rollout readiness registry (stages, categories, required commands, thresholds).
- `App\Services\Foundation\FiveBranchRolloutReadinessService` — read-only aggregation reusing MON-1 + BranchService + HealthCheckService; per-category + per-stage decision.
- `php artisan rollout:five-branch-readiness` (`--json`/`--strict`/`--fail-on-warning`/`--include-audits`/`--stage=`/`--capacity-smoke`).
- Read-only UI `GET /foundation/rollout/five-branch-readiness` gated by `view_developer_console`; cross-linked from MON-1 + dev-console.
- Runbooks: controlled rollout runbook + backup-restore drill runbook (with evidence template).
- Durable rules (CLAUDE.md + `.cursor/rules`).
