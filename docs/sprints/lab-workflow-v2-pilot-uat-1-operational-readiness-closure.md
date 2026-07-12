# LAB-WORKFLOW-V2-PILOT-UAT-1 — Pilot Operational Readiness, Real-Role UAT, SLA Baseline & Closure

Branch: `feature/lab-workflow-v2-pilot-uat-1-operational-readiness-closure`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
Baseline: `devflow-1-safe-sprint-acceleration-release-automation-go` @ `51223539f940` (verified exact).
GO tag (on merge, after deploy + real-role UAT + smoke): `lab-workflow-v2-pilot-uat-1-operational-readiness-closure-go`

## Scope

Operational-closure sprint for the Lab Workflow V2 pilot. **No migration, no permission change, no new role, no runtime driver change; ledger untouched; KTP/NIK never rendered; Workflow V2 canonical + legacy inactive for new orders; all transitions via the state machine.** Manifest: `.sprint/current.yml` (`sprint:manifest-check` GO).

## Delivered (implementation)

### Phase 2 — Technician account closure (closes the "0 eligible technicians" pilot blocker)
- `App\Modules\Technician\Services\TechnicianAccountAuditor` — read-only `audit()` (GO/WATCH/NO-GO; codes `no_eligible_technician` [critical NO-GO], `orphan_technician_no_user`, `technician_user_missing_role`, `technician_user_inactive`, `technician_user_missing_or_soft_deleted`, `duplicate_user_link` [critical NO-GO]); guarded `linkUser()` (transactional + `lockForUpdate` + fail-closed + idempotent; **never changes the user's role**, refuses ambiguous links, projects eligibility in dry-run).
- `php artisan lab:technician-account-audit {--json}{--strict}` (strict exits 2 on anomaly).
- `php artisan lab:technician-link-user --technician= --user= [--dry-run|--apply] {--json}` (dry-run default; `--apply` required to persist; refuses to link a user lacking the Technician role).

### Phase 10 — Pilot operational readiness gate
- `App\Modules\LabOrder\Services\LabWorkflowPilotReadinessAuditor` — one GO/WATCH/NO-GO decision aggregating: V2 active, legacy-create-blocked, RME branches, active external lab, **eligible technicians** (reuses `TechnicianAssignmentEligibility`), QC/courier/admin-lab actor availability, Admin-Lab-only posture (reuses `AdminLabLabOnlyAuditor`), technician-account posture (reuses `TechnicianAccountAuditor`), invalid status (NO-GO), stuck orders (WATCH), orphan tasks, failed jobs, evidence storage. Every check independently guarded (errored check → UNKNOWN, never a silent GO); free text masked via `SensitiveValueMasker`. Only V2-inactive, no-RME-branch, no-eligible-technician, and invalid-status emit NO-GO; staffing/master gaps are WATCH.
- `php artisan lab-workflow:pilot-readiness-audit {--json}{--strict}{--branch=}{--order=}`.

### Phase 8 — Operational dashboard
- `App\Modules\LabOrder\Services\LabWorkflowOperationalDashboardService::overview()` — V2-only status-group counts via a **single GROUP BY** (no N+1); operational buckets (Waiting Pickup → In Transit to Branch), per-step internal-production breakdown, delivered-today, overdue (idle > 3d), recent activity (order number + patient name + status + time only — **no KTP/NIK/clinical data**). Scope server-side: `manage_lab_orders` → all branches; branch operator → own `BranchContext` branch, **fail-closed to 0 (empty) if unresolved**; a requested branch filter is validated against `BranchService::rmeEnabledIds()` (IDOR-safe).
- Controller `LabWorkflowOperationalDashboardController` + `LabWorkflowDashboardRequest` + view `lab-workflow/dashboard/index.blade.php` + route `lab-workflow-dashboard.index` (`GET lab/operational-dashboard`, `permission:view_lab_orders|manage_lab_orders`) + sidebar item "Dasbor Operasional Lab".

### Phase 9 — SLA / cycle-time baseline
- `App\Modules\LabOrder\Services\LabWorkflowSlaBaselineService::baseline()` — per-stage durations (request→pickup, pickup→received, received→analysis, assignment wait, step 1–4, QC wait, external turnaround, model-done→delivery, delivery→delivered, total lead time) from the append-only `trx_lab_order_status_logs` timeline (**`changed_at` only, never `updated_at`**); count/avg/median/min/max; rework count (QC_FAILED transitions); overdue; bounded (V2-only, date window, 2000-order cap), eager-loaded. Labeled **"Baseline pilot — bukan benchmark final"**. The controller passes the dashboard's **fail-closed effective branch id** into the SLA service so a branch operator can never widen scope.

### Phases 11/12 — Docs
- `docs/operations/lab-workflow-v2-pilot-runbook.md` (per-role + troubleshooting).
- `docs/operations/lab-workflow-v2-pilot-uat-checklist.md` (real-role UAT record; PASS never pre-marked; automated tests are logic evidence, not a substitute for real-role UAT).

## Security review
- Branch isolation: dashboard/SLA both scope by `BranchContext` for operators (fail-closed) and validate any requested branch against `rmeEnabledIds()`; **a review-found cross-branch SLA leak** (operator with unresolved branch → SLA passed `null` → all branches) was closed via `effective_branch_id`.
- Admin Lab stays Lab-only; readiness reuses `AdminLabLabOnlyAuditor`. No permission/role change. `linkUser` never changes a user's role. No PII in any output (masker + name-only recent activity).

## Tests (all green locally)
- `LabTechnicianAccountAuditTest` (12), `LabWorkflowPilotReadinessAuditTest` (7), `LabWorkflowSlaBaselineTest` (4), `LabWorkflowOperationalDashboardTest` (6) — **29 new**.
- Regression: `tests/Feature/LabWorkflow` + `tests/Feature/AccessControl` = 225 passed / 8 GD-skipped; `Sidebar|RolePermissionHardening|PilotRouteAuthorization` = 82 passed. pint clean, `git diff --check` clean, `view:cache` compiles, `npm run build` passes, `sprint:scope-audit` GO.

## Deploy / real-role UAT gates (human-gated — NOT done by the assistant)
Real-role UAT (real operators logging in per role), the VPS deploy, and the GO tag require the operator/owner. Post-deploy commands: `lab-workflow:pilot-readiness-audit --strict`, `lab:technician-account-audit --strict`, and — only if a master technician is genuinely unlinked and owner-confirmed — `lab:technician-link-user --technician=<id> --user=<id> --dry-run` then `--apply`. No migration/seed needed.
