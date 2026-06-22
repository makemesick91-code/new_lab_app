# Sprint 58.4 — RME Dashboard Page & KPI Cards — Implementation Spec

**Branch:** `feature/sprint-58-4-rme-dashboard-page-kpi-cards`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`

## 1. Goal
Replace the temporary `/rme/dashboard` redirect (Sprint 58.3) with a real standalone
**Dashboard RME** page showing aggregate KPI cards and shortcut links to the main RME pages.

## 2. Current behavior
`routes/web.php:204` — `Route::get('dashboard', fn () => redirect()->route('rme.visits.index'))->name('dashboard')`.
`GET /rme/dashboard` 302-redirects to `/rme/visits`. No UI.

## 3. Expected behavior
`GET /rme/dashboard` (name `rme.dashboard`) renders a standalone page titled **Dashboard RME**
with 9 aggregate KPI cards and 5 shortcut cards. No redirect.

## 4. Non-goals
- No schema/migration changes; no new tables.
- No permission slug rename/add; no role assignment changes.
- No authorization-logic rewrite (reuse existing `view_clinic_visits|manage_clinic_visits` gate).
- No changes to Admin Warehouse sidebar (Sprint 58.2) or `/inventory/dashboard`.
- No patient-detail tables — aggregate counts/totals only.
- No new dependencies; no `.env` changes; no deploy; no GO tag.

## 5. Files inspected
- `routes/web.php` (RME group 200–291, dashboard redirect 204).
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` (`index` widget pattern).
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php` (`visitsTodayCount`, `scopeBranchIds`).
- `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php` (count helpers).
- `app/Modules/ClinicVisit/Models/ClinicVisit.php` (columns/constants).
- `app/Modules/MedicalRecord/Services/MedicalRecordService.php` (`draftCount`).
- `app/Modules/MedicalRecord/Models/MedicalRecord.php` (`status`, `finalized_at`, `branch_id`).
- `app/Modules/RmeInvoice/Models/RmeInvoice.php` (status constants, `grand_total`, `branch_id`).
- `app/Modules/RmeInvoice/Models/RmePayment.php` (`amount`, `paid_at`, `branch_id`).
- `app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php` (`receivables` constraint).
- `app/Modules/Branch/Services/BranchService.php` (`rmeEnabledIds`, `listRmeEnabled`).
- `resources/views/rme/visits/index.blade.php` (shell + `ui-card` style).
- `resources/views/layouts/partials/sidebar.blade.php` (RME nav group).

## 6. Files expected to change
- **New** `app/Modules/RmeDashboard/Controllers/RmeDashboardController.php`
- **New** `resources/views/rme/dashboard/index.blade.php`
- **Edit** `routes/web.php` — import controller; swap redirect closure for controller action.
- **Edit** `resources/views/layouts/partials/sidebar.blade.php` — add a "Dasbor RME" sub-link (minimal).
- **New** `tests/Feature/RME/RmeDashboardPageTest.php`

## 7. Route / controller design
- Keep route inside the existing `prefix('rme')->name('rme.')` →
  `permission:view_clinic_visits|manage_clinic_visits` group (unchanged gate).
- `Route::get('dashboard', [RmeDashboardController::class, 'index'])->name('dashboard')`.
- Controller `index(Request)` returns `view('rme.dashboard.index', [...])`.
- Branch scope resolved exactly like `ClinicVisitService::scopeBranchIds`: default = all
  RME-enabled branch ids (`BranchService::rmeEnabledIds()`); optional `?branch_id` narrows
  only if it is within the allowed set (otherwise ignored).
- All aggregation done in the controller against `whereIn('branch_id', $branchIds)`.

## 8. Dashboard metric definitions (all branch-scoped to `$branchIds`)
| # | Card | Definition |
|---|------|-----------|
| 1 | Kunjungan Hari Ini | `ClinicVisit whereDate(visit_date, today)` count |
| 2 | Kunjungan Bulan Ini | `ClinicVisit whereBetween(visit_date, [startOfMonth, endOfMonth])` count |
| 3 | Pasien Baru Bulan Ini | `ClinicVisit visit_type = 'new'` this month, count. **Decision:** counted via visit_type='new' (not `Patient.created_at`) because `Patient.branch_id` may be null for cross-branch RM and would leak/undercount across branches. Conservative & branch-consistent. |
| 4 | Kunjungan Kontrol / Follow-up | `ClinicVisit whereIn(visit_type, FOLLOW_UP_VISIT_TYPES=['control','continued_treatment'])` this month, count |
| 5 | Rekam Medis Draft | `MedicalRecordService::draftCount()` (status=draft, all RME branches). Branch filter not applied here — reuses existing all-RME-branch helper; documented. |
| 6 | Rekam Medis Finalized (bulan ini) | `MedicalRecord status='final' whereBetween(finalized_at, this month)` count |
| 7 | Menunggu Kasir | `ClinicVisit status='cashier_pending'` count |
| 8 | Piutang RME Aktif | **Conservative count** of active receivable invoices: `whereIn(status,[UNPAID,PARTIAL]) AND grand_total>0 AND grand_total > SUM(payments)` — replicates `RmeInvoiceController::receivables` constraint. Count (not rupiah) to avoid stored-vs-computed remaining drift. |
| 9 | Pembayaran Hari Ini | `RmePayment whereDate(paid_at, today)->sum(amount)` (rupiah total) |

## 9. Branch isolation design
- Single source of allowed branches: `BranchService::rmeEnabledIds()` — same set used by RME
  visits/medical-records/cashier/reports listings.
- No use of `BranchContext::id()` for listing (it resolves to one fallback branch only; there is
  no Owner all-branch path in `BranchContext`). Matches existing RME multi-branch listing pattern.
- Optional `?branch_id` validated against the allowed set before use; invalid/foreign ids ignored
  → fall back to all RME branches. No cross-branch leakage of non-RME branches.
- Cards 5 reuses an existing helper already scoped to `rmeEnabledIds()`.

## 10. Privacy design
- Only aggregate counts and one rupiah total are surfaced. No patient identity, KTP, phone,
  WhatsApp, address, diagnosis, treatment notes, tokens, passwords, or `.env` values are queried,
  passed to the view, or rendered. No per-row patient tables.
- Test asserts the rendered HTML does not contain `ktp_number`, `whatsapp_number`, `phone`,
  `address`, `password`, `token`, `.env`.

## 11. UI layout design
- `<x-settings-shell title="Dashboard RME">` (same shell as RME visits index).
- Header: "Dashboard RME" + subtitle "Ringkasan operasional RME".
- KPI grid: responsive `grid-cols-2 sm:grid-cols-3 lg:grid-cols-3` of `ui-card` tiles (value + label),
  reusing the existing `ui-card` style from visits index.
- Shortcut grid: `x-ui.card`/anchor tiles → `rme.visits.index`, `rme.medical-records.index`,
  `rme.cashier.index`, `rme.reports.patients`, `rme.reports.payments`.
- Optional branch filter `<select>` (Semua Cabang RME + `listRmeEnabled()`), GET self-submit —
  mirrors visits index. No global layout redesign.

## 12. Test plan (`tests/Feature/RME/RmeDashboardPageTest.php`)
- `rme.dashboard` route resolves and is NOT a redirect (200, not 302).
- Authorized user (with `view_clinic_visits`) gets 200 and sees "Dashboard RME".
- Page contains all 9 KPI labels.
- Page contains shortcut hrefs to `/rme/visits`, `/rme/medical-records`, `/rme/cashier`,
  `/rme/reports/patients`, `/rme/reports/payments`.
- Page does NOT contain sensitive tokens (`ktp_number`, `whatsapp_number`, `phone`, `address`,
  `password`, `token`, `.env`).
- Unauthorized user (no RME permission) is forbidden (403).
- Metrics reflect seeded data (today vs this-month counts).

## 13. Risk checklist
- [x] No schema/migration.  [x] No new dependency.  [x] No permission slug change.
- [x] No role assignment change.  [x] No authorization rewrite (reuse existing gate).
- [x] No Admin Warehouse sidebar / login-redirect change.  [x] `/inventory/dashboard` untouched.
- [x] No `.env` change.  [x] No deploy / GO tag.  [x] No Playwright/package file changes.
- [x] Branch isolation via existing `rmeEnabledIds()` only.
- [x] Privacy: aggregate-only, asserted by test.

## 14. Rollback plan
Single-commit, additive change. Rollback = revert the commit (restores the Sprint 58.3 redirect
closure and removes the new controller/view/test). No data/schema impact.
