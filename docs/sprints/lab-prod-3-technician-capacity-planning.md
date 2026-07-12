# LAB-PROD-3 — Technician Capacity Planning

**Branch:** `feature/lab-prod-3-technician-capacity-planning`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
**Baseline:** LAB-PROD-2 `lab-prod-2-operational-analytics-kpi-go` @ `6da05b5`
**GO tag:** `lab-prod-3-technician-capacity-planning-go`

## Objective

Read-only decision-support so Admin Lab / Owner can see technician capacity vs
demand, over- and under-capacity, unassigned work, per-service demand, due-date
risk, internal-vs-external distribution, and explainable placement
recommendations — **without auto-assigning anyone or ranking employees**.

## Scope (implementation-heavy, runtime-usable)

- **4 additive `mst_lab_*` migrations** (`2026_07_12_100001..4`): technician
  capacity profiles, service workload profiles, technician capabilities,
  availability overrides. Additive only; no `migrate:fresh`/`db:wipe`.
- **Config** `config/lab_technician_capacity.php` — feature flag, horizons
  (7/14/30/custom, max 90), planning unit, utilization bands, reason codes,
  remaining-workload band fractions, caps, permissions. Kept **separate** from
  `config/lab_operational_analytics.php` so the LAB-PROD-2 audit stays GO.
- **Module `App\Modules\LabCapacity`** — models, `LabTechnicianCapacityRepository`
  (V2-only, capped, PII-free), `LabTechnicianCapacityPlanningService` (engine +
  recommendations), `LabCapacityConfigService` (transactional writes), audit
  service, 2 controllers, 5 FormRequests.
- **Reuse (no duplicate calculators):** `LabOperationalAnalyticsRepositoryInterface::technicianAssignmentStats`
  (historical completion confidence), `LabWorkflowSlaBaselineService`,
  `BranchService`, `TechnicianAssignmentEligibility`.
- **Routes** `lab-capacity-planning.*` (index/export + configuration CRUD).
- **Permissions** `view_lab_technician_capacity`, `view_own_lab_technician_capacity`,
  `manage_lab_technician_capacity`, `export_lab_technician_capacity`.
- **UI** `resources/views/lab/capacity-planning/{index,configuration,disabled}.blade.php`
  (`x-ui.*` + tokens, no PII, server-computed).
- **Commands** `lab-workflow:technician-capacity-audit` +
  `lab-workflow:technician-capacity-go-no-go` (`--json`/`--strict`, GO/WATCH/NO_GO).

## Canonical capacity model

- **planning_unit** explicit (`minutes`|`units`); never mixed in one plan.
- **available_capacity** = Σ over working days of `daily_capacity`, with per-day
  availability overrides (absolute override wins, else reduction; floored at 0).
  No profile ⇒ UNCONFIGURED (null capacity, excluded from utilization).
- **remaining workload** per order = Σ items (`service planned_workload × qty ×
  band fraction`). Band fractions (config) map from `LabWorkflowState` constants:
  pre-production 1.0 → step 1..4 0.85/0.65/0.45/0.20 → QC 0.10 → rework 0.75 →
  near-done 0.05 → post-production 0.0. No workload profile ⇒ UNPLANNABLE.
- **assigned_load** = remaining of open orders with an active assignment;
  **unassigned_demand** = remaining of open orders without one.
- **utilization** = assigned / available × 100 (guarded); bands NORMAL <80,
  WATCH 80–100, OVER_CAPACITY >100, UNAVAILABLE (0 capacity), UNCONFIGURED.
- **capacity_gap** = available − assigned. **backlog_coverage_days** = load ÷
  avg daily capacity.
- **due-risk** deterministic pool simulation (overdue → due asc → priority →
  received → id): ON_TRACK / AT_RISK / PROJECTED_LATE / OVERDUE / NO_DUE_DATE /
  UNPLANNABLE. Uses `due_date` only (LAB-PROD-2 contract).
- **recommendations** (read-only): candidates filtered by capability + capacity +
  active, ranked by projected utilization; reason codes when none. Never mutates.

## Guardrails

No auto-assign, no employee ranking, no fake zeros, no PII, branch-scoped demand
(technicians lab-wide), technician own-scope IDOR-forced, owner cross-branch
read-only, additive migrations, transactional config writes, feature-flag safe
disable.

## Validation

- Tests: `tests/Feature/LabWorkflow/LabTechnicianCapacity{Planning,Access,Config,AuditCommand}Test.php` (42).
- `lab-workflow:technician-capacity-go-no-go --strict` → GO.
- `lab-workflow:operational-kpi-go-no-go --strict` unaffected.
- DEVFLOW strict gates (manifest/scope/ci-runtime-control/security-compliance/
  cicd-enterprise/ui-governance/shared-service/roadmap/devflow) all GO.
- pint + `git diff --check` clean; `npm run build` pass.

## Reuse contract (LAB-PROD-4+)

Consume `LabTechnicianCapacityPlanningService` + `LabTechnicianCapacityRepositoryInterface`
+ the `own` tier; never build a parallel capacity calculator.
