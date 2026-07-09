# HOTFIX — FIX-PRE-68-45 Doctor Performance Treatment Date Table (2026-07-09)

Branch `hotfix/fix-pre-68-45-doctor-performance-treatment-date`
(base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`; **do NOT target main**).
Rollback baseline `hotfix-fix-pre-68-45-doctor-performance-403-go`.

## Goal

Enhance the Doctor Performance / Income report (`GET /rme/reports/doctor-performance`
→ `rme.reports.doctor-performance`) so **`Tanggal Perawatan` is shown automatically**
in the report table. A doctor is **never forced to pick a treatment date first**; the
daily table is derived from the already-scoped result and expands into a per-treatment
breakdown for each date.

## Scope

**No migration, no route, no permission change, no schema change.** Presentation +
read-only aggregation only. KTP/NIK never rendered. All `-403` hotfix access rules
preserved verbatim. Inventory Sprint 68.45 not started.

## Changes

- **`DoctorPerformanceReportService::dailyBreakdown($scope, $treatmentId, $invoiceStatus)`**
  (new, private): groups the scoped result by **treatment date** — the canonical
  `trx_clinic_visits.visit_date`, the same field the date-range filter already uses.
  Grouped in PHP (portable across PG/SQLite, mirrors `OwnerDashboardKpiService`). Each
  date row carries: distinct patients, distinct treatment types (`jenis perawatan`),
  total treatment items (`total tindakan`), the **exact** paid total for that date
  (RME payment truth), and a nested per-treatment breakdown (name, item count, distinct
  patients, exact billed subtotal). Payment is only attributable at the visit/invoice
  level, so per-treatment shows exact **billed** and the date-level **Total Dibayar** is
  the exact RME payment sum.
- `report()` now includes `daily_rows` in the **detail** payload (always computed — no
  date filter required) and `daily_rows => []` in the summary payload. The daily table
  appears whenever a doctor is scoped: always for the linked-doctor own tier, and for an
  executive who has drilled into one doctor.
- **View** `resources/views/rme/reports/doctor-performance.blade.php`: new
  "Rincian Harian per Tanggal Perawatan" card above the existing aggregate treatment
  breakdown. Table columns: `Tanggal Perawatan | Jumlah Pasien | Jumlah Jenis Perawatan
  | Total Tindakan | Total Dibayar | Aksi`. Each date row expands (Blade + Alpine
  `x-data="{ open: false }"`, `x-cloak`) into a nested per-treatment table
  (`Jenis Perawatan | Jumlah Tindakan | Jumlah Pasien | Nilai Ditagih`) plus a
  date-level "Total Dibayar" row. Empty state when no treatments. No React/Vue.

## Access rules (unchanged from `-403`)

- Linked Doctor → own dates/treatments only (`mst_doctors.user_id`, IDOR-forced; a
  forged `?doctor_id=` is ignored).
- Doctor A never sees Doctor B's dates/treatments.
- Unlinked Doctor → clear 403 ("Akun dokter belum terhubung ke data dokter…").
- Kepala Cabang → 403 (no doctor-report permission this hotfix).
- Owner / Supervisor RME → executive; Super Admin → gate bypass.
- User without permission → 403.
- Payment totals sourced from RME invoice/payment truth; lab tables never touched.
- No KTP/NIK rendered.

## Validation

- `tests/Feature/RME/DoctorPerformanceTreatmentDateTableTest.php` — **14 passed** (33 assertions).
- Regression `DoctorPerformanceReport|DoctorPerformanceAccessAudit` — 22 passed.
- RME `Report|DoctorPerformance|Cashier|Payment` subset — 231 passed.
- `rme:doctor-performance-access-audit --strict` → OK (exit 0) with permissions seeded.
- Gates GO: `architecture:ui-governance-check --strict`, `foundation:ci-runtime-control-check --strict`,
  `foundation:security-compliance-check`, `foundation:cicd-enterprise-gate-check`,
  `foundation:enterprise-documentation-check`, `foundation:roadmap-check --strict`
  (next `MON-1`, not stale).
- `pint --dirty --test` + `git diff --check` clean; `npm run build` pass; graphify updated.

## Known risks

- The daily table lists a date only when it has invoice items (recorded treatments);
  triage-only visits with no billing produce no treatment rows by design.
- Per-treatment "Nilai Ditagih" is billed subtotal (exact); per-treatment paid is not
  item-attributable, so only the date-level "Total Dibayar" reflects actual payments.

## Rollback

Revert the merge commit, or `./scripts/rollback-vps.sh hotfix-fix-pre-68-45-doctor-performance-403-go`.

## Next recommended sprint

Inventory Sprint 68.45 (not started; requires explicit approval).
