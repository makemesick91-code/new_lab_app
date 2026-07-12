# LAB-PROD-2 — Operational Analytics & KPI Contract (canonical)

Durable, testable contract for the Lab Workflow V2 operational analytics surface.
This is the single source of truth for every KPI's data source, formula,
denominator, exclusion rule, scope, and support status. LAB-PROD-3 (Technician
Capacity Planning) and later sprints MUST reuse this contract and its service.

- Config registry: `config/lab_operational_analytics.php`
- Service (formulas): `App\Modules\LabOrder\Services\LabOperationalAnalyticsService`
- Repository (queries): `App\Modules\LabOrder\Interfaces\LabOperationalAnalyticsRepositoryInterface`
  → `App\Modules\LabOrder\Repositories\LabOperationalAnalyticsRepository`
- Cycle-time source of truth (reused, never re-derived): `LabWorkflowSlaBaselineService`
- Audit + gate: `lab-workflow:operational-kpi-audit`, `lab-workflow:operational-kpi-go-no-go`
- UI: `GET /lab/analytics/operational-kpi` (`lab-analytics.operational-kpi.index`),
  CSV export `lab-analytics.operational-kpi.export`.

## 1. Canonical data sources (V2 only — `workflow_version = 2`)

| Source | Used for |
| --- | --- |
| `trx_lab_orders` (`status`, `order_date`, `due_date`, `branch_id`) | workload/WIP, orders received, overdue, data quality |
| `trx_lab_order_status_logs` (append-only: `new_status`, `changed_at`) | throughput, cycle time, SLA completion, QC attempts |
| `trx_lab_order_assignments` (`technician_id`, `assigned_at`, `started_at`, `completed_at`) | technician KPI |
| `trx_lab_model_analyses` (`decision`, `analyzed_at`) | internal vs external |
| `trx_lab_external_dispatches` (`sent_at`, `returned_at`) | external turnaround |
| `trx_lab_delivery_tasks` (`delivered_at`) | delivered-today context |

`changed_at` is the ONLY transition timestamp used (never `updated_at`). Historical
logs are immutable — analytics never writes them.

## 2. KPI formulas + denominators (locked)

| KPI | Formula | Denominator | Exclusions |
| --- | --- | --- | --- |
| Orders received | count(V2 orders, `order_date` in period) | — | soft-deleted |
| Open WIP | count(V2 orders, status NOT terminal) | — | DELIVERED, CANCELLED |
| WIP per stage | count(V2 orders) grouped by phase | — | — |
| Rework active | count(status QC_FAILED or REWORK_REQUIRED) | — | — |
| Open overdue | count(non-terminal, `due_date < today`) | — | no `due_date`; terminal |
| Throughput | count(first DELIVERED transition, `changed_at` in period) | — | never delivered |
| Throughput delta | throughput(period) − throughput(previous **equal-length** window) | — | — |
| Cycle time | median + max minutes per canonical stage (`LabWorkflowSlaBaselineService`) | orders with both stage timestamps | missing/negative stage timestamps |
| **SLA compliance %** | on_time / eligible × 100 | completed (delivered in period) orders that **had a `due_date`** | orders without `due_date`; cancelled |
| SLA lateness | median lateness (days) of LATE eligible cases | late eligible cases | on-time; no `due_date` |
| QC first-pass yield | orders whose FIRST in-period QC attempt was QC_PASSED / orders with a QC attempt | orders with ≥1 QC attempt in period | never sent to QC |
| QC rework rate | orders with ≥1 QC_FAILED / orders with a QC attempt | same as above | never sent to QC |
| Technician KPI | per technician: active WIP, assigned, completed, median completion minutes, sample | assignments per technician | assignments missing start+complete (cycle only) |
| Internal vs external | count(analysis decision INTERNAL) vs count(EXTERNAL) in period | orders with a decision | no analysis decision |
| External turnaround | median days `returned_at − sent_at` | dispatches with both timestamps | missing `sent_at`/`returned_at` |

On-time rule: delivered `changed_at` ≤ `due_date` end-of-day. The boundary (delivered
within the due day) is **on-time**.

## 3. Non-negotiable rules

1. **SLA deadline source** = `trx_lab_orders.due_date` only (no separate SLA policy
   config exists). Orders without a `due_date` are **excluded** from SLA compliance and
   surfaced in the data-quality panel — never silently counted, never a fake 0.
2. **Missing/incomplete data is shown as excluded / coverage / WATCH, never as 0.**
3. **No fabricated, dummy, or hard-coded metric values.** A denominator of 0 yields
   `null` ("N/A"), never a fake percentage.
4. **No PII** (KTP/NIK/WhatsApp/clinical notes/scans/prescriptions) in any KPI,
   drill-down, or CSV export. Patient display name only where already permitted.
5. **Timezone**: app timezone; day-grouping done in PHP for PostgreSQL/SQLite parity.
6. **Immutable workflow/history** — analytics is read-only.
7. **Real-time aggregation** (no summary table added this sprint). Every query is V2-only
   and hard-capped by `max_scan_orders`. A future summary/read-model must follow the
   ENT-3 reporting-summary contract (`rpt_*`, freshness, idempotent refresh).

## 4. Authorization + branch isolation (server-side, IDOR-safe)

- Tier **full** (`view_lab_operational_analytics` or `manage_lab_orders`): all RME
  branches, branch filter honoured (validated against `BranchService::rmeEnabledIds()`;
  a crafted `branch_id` → all branches). Held by **Admin Lab**, **Owner** (Super Admin
  via `Gate::before`). Cross-branch is **read-only**.
- Tier **own** (`view_own_lab_operational_analytics` + linked active
  `mst_technicians.user_id`): forced to the caller's own `technician_id`; a forged
  `technician_id` is ignored. No cross-technician, no branch widening.
- No analytics permission → **403**. `manage_clinic_visits` / `manage_rme_billing` /
  Doctor / Kasir / Admin Klinik are denied. The sidebar is never the security boundary.
- CSV export uses the **identical** scope + filters as the screen.

## 5. Commands

```
php artisan lab-workflow:operational-kpi-audit        # PASS/WARN/FAIL per check
php artisan lab-workflow:operational-kpi-audit --strict  # exit 2 on NO_GO, 1 on WATCH
php artisan lab-workflow:operational-kpi-go-no-go --strict  # exit 0 ONLY on GO
```

Decision: **NO_GO** on any FAIL (missing required source column, unknown workflow
status in data, impossible/negative durations, duplicate metric key); **WATCH** on
coverage-only warnings (permission not seeded, order missing branch/timestamp, no V2
data); **GO** otherwise. WATCH is never used to mask a core-metric failure.

## 6. LAB-PROD-3 reuse contract

Technician Capacity Planning MUST consume `LabOperationalAnalyticsService` +
`LabOperationalAnalyticsRepositoryInterface` (technician assignment aggregates, cycle
time via `LabWorkflowSlaBaselineService`) rather than re-deriving any KPI. The
technician self-scope tier (`view_own_lab_operational_analytics`, forced
`technician_id`) is the capacity-planning access baseline.
