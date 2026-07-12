# LAB-PROD-2 — Operational Analytics & KPI

**Branch:** `feature/lab-prod-2-operational-analytics-kpi`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
**Baseline:** `lab-ops-readiness-1-technician-external-lab-closure-go` (runtime `6214cb3`).
LAB-PROD-1 was never merged; LAB-PROD-2 builds on the shipped Lab Workflow V2 + pilot
UAT-1 foundations (`LabWorkflowSlaBaselineService`, `LabWorkflowOperationalDashboardService`).

## What shipped

Read-only operational analytics dashboard + CSV export for Admin Lab / Owner (full tier)
and linked technicians (own tier), driven entirely by canonical Lab Workflow V2 data.

- **Config:** `config/lab_operational_analytics.php` — canonical KPI registry (16 metrics:
  source/formula/denominator/exclusions/support), period presets, custom-range guard,
  scan cap, permission tiers.
- **Repository:** `LabOperationalAnalyticsRepositoryInterface` →
  `LabOperationalAnalyticsRepository` (PG/SQLite-portable, V2-only, branch/technician
  scoped, `max_scan_orders`-capped, PII-free). Bound in `RepositoryServiceProvider`.
- **Service:** `LabOperationalAnalyticsService` — every KPI formula + denominator +
  previous-period comparison + scope resolver. **Reuses `LabWorkflowSlaBaselineService`**
  for per-stage cycle time (single source of truth, no duplicate calculator).
- **Request/Controller:** `LabOperationalAnalyticsFilterRequest` +
  `LabOperationalAnalyticsController` (thin, index + `export`).
- **View:** `resources/views/lab/analytics/index.blade.php` — KPI cards, WIP per stage,
  SLA performance, throughput trend (progressive HTML bars, no chart dep), cycle-time
  table, QC quality, internal/external, technician table, data-quality coverage panel.
  Reuses `x-ui.*` + design tokens.
- **Commands:** `lab-workflow:operational-kpi-audit` + `lab-workflow:operational-kpi-go-no-go`
  (backed by `LabOperationalKpiAuditService`; GO/WATCH/NO_GO; `--json`/`--strict`).
- **Route:** `lab-analytics.operational-kpi.{index,export}` (permission-gated).
- **Permissions:** `view_lab_operational_analytics` (Admin Lab, Owner) +
  `view_own_lab_operational_analytics` (Technician) — PermissionSeeder + RoleSeeder.
- **Sidebar:** "Analitik & KPI Lab".

## KPIs

Workload/WIP (orders received, open WIP, WIP per stage, rework active, open overdue),
throughput + previous-period delta + daily trend, cycle time (reused SLA baseline stages),
**SLA compliance vs `due_date`** (eligible / on-time / late / % / median lateness),
QC first-pass yield + rework rate, technician operational KPI (WIP/assigned/completed/
median/sample), internal vs external + external turnaround, and a **data-quality coverage**
panel (with/without due date, delivered, stuck) so incomplete data is never hidden as 0.

## Rules locked

See `docs/architecture/lab-operational-analytics-kpi-contract.md` and
`.cursor/rules/80-lab-operational-analytics-kpi.mdc`. Canonical data only; SLA deadline =
`due_date` (no-due excluded, not zeroed); no fabricated metrics; no PII in dashboard/export;
missing data = excluded/WATCH; immutable history; real-time aggregation (no summary table);
server-side branch isolation + technician self-scope (IDOR-safe); Owner cross-branch
read-only; audit + GO/NO-GO gates; LAB-PROD-3 reuse contract.

## Validation

- New tests (32): `LabOperationalAnalyticsMetricTest` (12), `LabOperationalAnalyticsAccessTest`
  (13), `LabOperationalKpiAuditCommandTest` (7).
- Regression: `tests/Feature/LabWorkflow` + `tests/Feature/AccessControl` 282 passed / 8
  GD-skipped. Pint + `git diff --check` clean.
- `lab-workflow:operational-kpi-audit --strict` / `-go-no-go --strict` GO on a schema with
  V2 data + seeded permissions.

## Deploy note

**No migration.** Run `db:seed --class=PermissionSeeder --force` +
`db:seed --class=RoleSeeder --force` + `permission:cache-reset` on VPS (idempotent), then
`lab-workflow:operational-kpi-go-no-go --strict`.

## Next

**LAB-PROD-3 — Technician Capacity Planning** (reuses this service + `own` tier).
