# Graphify Update — Sprint 22 → Sprint 24.3

**Date:** 2026-06-13
**Graph rebuilt from commit:** `9aff71c4` (`feature/sprint-24-phase-24-3-rme-receivable-dashboard-foundation`)
**Previous graph commit:** `d461ad8f` (Sprint 23 Phase 23.10.4)

## Graphify command used

```bash
graphify update .
```

AST-only re-extraction (no LLM / no API cost), per the project convention documented in `AGENTS.md` / `CLAUDE.md`. This is the safe regeneration path — it rebuilds `graphify-out/graph.json` and `graphify-out/GRAPH_REPORT.md` in place without manual deletion.

**Result:** `11700 nodes · 16685 edges · 1716 communities` (previously `11421 nodes · 16588 edges · 1515 communities`). Extraction 100% EXTRACTED. Corpus: 1604 files / ~806,485 words.

> **Note on `GRAPH_REPORT.md` / `graph.json`:** `graphify-out/` is gitignored (`.gitignore:30`). These files are tool-generated, local-only artifacts and are overwritten by every `graphify update`. They are **not** hand-editable deliverables. This companion document is the committable record of what the refreshed graph now covers across Sprints 22–24. To reproduce the graph locally, run `graphify update .` from the repo root.

## Why an update was needed

The graph was last built at `d461ad8f` (Sprint 23 Phase 23.10.4). Sprints 23.10.5 → 24.3 added new routes, controller methods, services, models, views, and tests that were stale or absent in the prior graph. Files changed since the prior graph commit include RME invoice/payment models, services, controllers, repository, clinic-visit request/service, cashier + receivable + visit-form views, sidebar, and their tests.

---

## Sprint 22 coverage — Pilot Stabilization & Owner Dashboard Foundation

Tags: `sprint-22-planning` → `sprint-22-phase-22-8-closure-rc-go-no-go`, `sprint-22-release-candidate`.

- **22.1 Role/Permission/Menu hardening** — new permissions `view_owner_dashboard`, `view_branch_dashboard`; new roles Owner / Kasir / Perawat; Doctor hardened (no lab/cashier). Dashboard route + "Dasbor" sidebar gated.
- **22.2 RME smoke-test data & operator checklist** — pilot E2E data + checklist (docs).
- **22.3 VPS pilot deployment checklist & safe seeder rollout** (docs).
- **22.4 RME → Lab candidate E2E validation** — `tests/Feature/Pilot/RmeLabCandidateE2EValidationTest.php`.
- **22.5 Owner Dashboard RME/Lab KPI wiring** — `OwnerDashboardRmeLabKpiService` (global aggregate across active branches; optional single-branch scope via `metrics($branchId)`). Read-only.
- **22.6 Owner Dashboard branch filter & KPI drilldown polish** — `OwnerDashboardRmeLabDrilldownService`.
- **22.7 VPS checklist update + Owner dashboard manual smoke** (docs).
- **22.8 Closure / Release-Candidate / Go-No-Go** (docs). Owner Dashboard remained read-only; no schema changes.

Reporting services now present in the graph: `OwnerDashboardRmeLabKpiService`, `OwnerDashboardRmeLabDrilldownService`, `RmeDashboardKpiService`, `LabDashboardKpiService`, `DashboardService`.

---

## Sprint 23 coverage — RME Advanced/Cashier Workflow & Print Hardening

Tags: `sprint-23-phase-23-3-...` → `sprint-23-phase-23-10-8-visit-number-unique-hotfix`.

- **23.3 / 23.5** — Owner Dashboard access/menu/role enablement; branch-scope correction + dashboard renaming + split RME report roles.
- **23.7 Master Data Cabang CRUD** — reused existing `mst_branches` columns; no new migration.
- **23.8 Patient ID format finalization + new-patient registration flow** — RM composed from `branch.code`.
- **23.9.1 RME clinic source from branch master** — `RmeClinicSourceFromBranchTest`.
- **23.9.3 RME visit list branch filter fix.**
- **23.9.5 VPS smoke closure documentation.**
- **23.10 RME pilot data-entry hardening** — `RmePilotDataEntryHardeningTest`.
- **23.10.2 Odontogram additional fields before finalization.**
- **23.10.4 Odontogram selected-results table** — `resources/views/rme/visits/partials/odontogram-selected-results.blade.php`.
- **23.10.5 RME cashier branch scoping + clinical summary** — `resources/views/rme/cashier/partials/clinical-summary.blade.php`.
- **23.10.6 Medical-record print + odontogram merge** — `resources/views/rme/visits/partials/print-body.blade.php`; `MedicalRecordPrintOdontogramMergeTest`.
- **23.10.7 Combined medical-record print VPS smoke** (docs).
- **23.10.8 Clinic visit number uniqueness hotfix** — `ClinicVisit` model / visit-number generation.

---

## Sprint 24 coverage — RME Partial Payment & Piutang Dashboard

### Phase 24.1 — Partial Payment / Cicilan Foundation (`ed36d6a`, tag `sprint-24-phase-24-1-rme-partial-payment-foundation`)

- `RmeInvoice::STATUS_PARTIAL` added to status set (`DRAFT`, `UNPAID`, `PARTIAL`, `PAID`, `VOID`).
- New model methods: `RmeInvoice::paidAmount()`, `remainingAmount()`, `isPartial()`, `isPayable()` (payable when `UNPAID` or `PARTIAL`).
- Multiple `RmePayment` records per invoice.
- `RmePaymentService::pay()` lifecycle:
  - Only `UNPAID`/`PARTIAL` invoices accept payment.
  - **Overpayment guard** — `amount > remainingBefore` is rejected; zero-remaining rejected.
  - `remainingAfter <= 0` → status `PAID`; otherwise → status `PARTIAL`.
  - On `PAID` and visit `CASHIER_PENDING` → visit transitions to `COMPLETED`.
  - Partial payment keeps visit `CASHIER_PENDING`.
  - **Lab candidate generation (`RmeLabIntegrationService::generateForPaidInvoice`) runs only after the invoice reaches `PAID`** (post-commit), preserving the Sprint 21 idempotent behavior.
- UI surfaces paid amount, remaining amount, and payment history (`rme/cashier/show.blade.php`, `rme/cashier/payment/create.blade.php`).
- Tests: `tests/Feature/RME/RmePaymentTest.php`, `CashierBillingTest.php`, `LabIntegrationTest.php`.

### Phase 24.2 — VPS RME Partial Payment Smoke (`a09f0a5`, tag `sprint-24-phase-24-2-vps-rme-partial-payment-smoke`)

Doc-only smoke record: partial payment PASS, overpayment guard PASS, final settlement PASS, lab candidate after full payment PASS, no Laravel log error.

### Phase 24.2.1 — New-Patient Visit Branch Consistency (`bc5e480`, tag `sprint-24-phase-24-2-1-rme-new-patient-branch-consistency`)

- For `patient_mode=new`, top `branch_id` must equal `new_patient[branch_id]`. `StoreClinicVisitRequest` enforces a `same` rule — message: *"Cabang RME pasien baru harus sama dengan Klinik/Cabang kunjungan."* Backend rejects mismatched new-patient branch.
- `new_patient.branch_id` must exist in `mst_branches` with `is_active` + `is_rme_enabled`.
- UI: lower "Cabang RME" field syncs with top Klinik/Cabang field (`rme/visits/_form.blade.php`).
- Existing-patient branch behavior unchanged.
- Tests: `tests/Feature/RME/ClinicVisitNewPatientFlowTest.php`.

### Phase 24.3 — RME Receivable / Piutang Dashboard Foundation (`7dcacd4` + smoke `9aff71c`, tags `sprint-24-phase-24-3-rme-receivable-dashboard-foundation`, `sprint-24-phase-24-3-vps-piutang-rme-smoke`)

- New route `rme.cashier.receivables` → `RmeInvoiceController@receivables` (`routes/web.php:256`).
- New view `resources/views/rme/cashier/receivables.blade.php`.
- New sidebar menu **"Piutang RME"** (`layouts/partials/sidebar.blade.php`).
- Active receivable dashboard for `UNPAID` and `PARTIAL` invoices.
- Summary cards: Jumlah Invoice, Total Tagihan, Sudah Dibayar, Sisa Piutang.
- Filters: search (invoice number / patient name / visit number), branch, status, date range.
- Row actions: Detail, Bayar / Bayar Cicilan.
- VPS smoke (doc): page opens, summary cards visible, PARTIAL + UNPAID visible, Detail link works, Bayar Cicilan link works, status/branch/search/reset filters work, final Laravel log empty.

---

## Verification

| Check | Result |
| --- | --- |
| `graphify update .` rebuild | PASS — graph at `9aff71c4`, 11700 nodes |
| `git diff --check` | PASS (no whitespace errors) |
| `php artisan route:list \| grep rme.cashier.receivables` | PASS — route resolves to `RmeInvoiceController@receivables` |
| Working tree (production code) | Unchanged — only this doc + gitignored `graphify-out/` regenerated |

No production logic, migrations, controllers, services, requests, or views were modified by this task.
