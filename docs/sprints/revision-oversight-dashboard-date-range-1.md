# REVISION-OVERSIGHT-DASHBOARD-DATE-RANGE-1 — Oversight dashboard date range

Rule: `.cursor/rules/187-oversight-dashboard-date-range-foundation.mdc` (extends
rule 186). Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
@ `24bc3d39` = GO tag `feature-finance-klinik-data-analyst-roles-1-go` =
production HEAD at start (tree `bbece961`).

## What changed

`/oversight/dashboard` (Finance Klinik, Data Analyst) moves from a single
clinic date to an **inclusive WITA date range**:

| Input | Meaning |
|---|---|
| none | today (WITA) .. today |
| `start_date` + `end_date` | that inclusive range |
| only one of them | **rejected** (422 / redirect with error) |
| legacy `date` alone | that single day (old links keep meaning) |
| `date` + range params | **rejected** |
| `start_date > end_date` | **rejected — never exchanged** |
| span > 366 days, or a date outside 2000-01-01..2099-12-31 | **rejected** |

Timestamp columns are compared as
`col >= start_date 00:00 WITA (in UTC) AND col < (end_date + 1) 00:00 WITA (in UTC)`;
the DATE column `visit_date` as `[start_date, end_date + 1)`. Never `23:59:59`,
never `whereDate()`, never a function on a column.

| KPI | Over the range |
|---|---|
| Jumlah Pasien | DISTINCT checked-in patients over all days; total deduplicated across branches AND dates |
| Jumlah Transaksi | DISTINCT cashier batches by `paid_at` (carry-over batch = one) |
| Jumlah Omzet | `SUM(trx_rme_payments.amount)` by `paid_at`, invoice-branch attribution, lab excluded |
| Piutang Aktif Per Saat Ini | **unchanged current balance** — repository method takes no range |
| Jumlah Pasien Cancel | `cancelled_at` in range; never `updated_at` / `visit_date` |

`start_date == end_date` is numerically identical to the original dashboard
(pinned by comparing the legacy `?date=` feed with the one-day range feed).

## Files

- NEW `app/Modules/ClinicOversight/Support/ClinicalDateRange.php` — value
  object composed from two strict `ClinicalDayWindow`s (WITA→UTC conversion in
  one place), bounds, 366-day max, presets.
- NEW `app/Modules/ClinicOversight/Requests/OversightDashboardFilterRequest.php`
  — the one compatibility rule; redirect target is the dashboard itself.
- `Interfaces/OversightKpiRepositoryInterface.php`, `Repositories/OversightKpiRepository.php`
  — four period KPIs take the range; receivables signature untouched.
- `Services/OversightDashboardService.php` — `build(User, ?ClinicalDateRange, branch)`,
  `METRIC_VERSION` 2, `receivable_basis = current_balance`.
- `Controllers/OversightDashboardController.php` — one range for page, feed, CSV,
  print; audit `date_from` / `date_to` (already allowlisted).
- `Support/OversightFormat.php` — `clinicalDate()` (`dd/mm/yyyy`).
- `resources/views/oversight/dashboard/{index,print}.blade.php` — two inputs,
  presets, Reset keeping branch, range labels, explicit Piutang wording,
  `requestSeq` refresh guard.
- Tests: NEW `ClinicOversightDashboardDateRangeTest.php`; `ClinicOversightDashboardKpiTest.php`
  adapted to the new service signature (assertions unchanged).
- `config/ci_runner.php` — new suite declared mandatory.

No migration, no permission/role/route change, no new index.

## Independent review

Adversarial read-only review of the full diff: **no CRITICAL, HIGH or MEDIUM**.
Three LOW findings:

- **F1 (fixed):** the dashboard request inherited the parent's
  `visit_date_to => after_or_equal:visit_date_from`, so
  `?visit_date_to=…&visit_date_from[]=x` was still an HTTP 500 on all four
  dashboard endpoints. Reproduced red, then fixed: the dashboard validates only
  `branch_id` + dates (test `ignores the list-page filters…`).
- **F2 (fixed):** the error alert blamed "dates" for any filter error (now
  neutral); in the default view the feed rolled to the new day at WITA
  midnight while the pickers kept yesterday, so *Terapkan* could silently pin
  yesterday — the component now announces a moved range and the pickers follow
  (JS test). "Hari ini" and "Bulan ini" both highlighted on the 1st is the same
  range and left as is.
- **F3 (recorded):** the dashboard routes carry no rate limit (unchanged by this
  revision). Every query is bounded (≤ 366 days), grouped and index-backed, and
  only permission holders reach it. Adding route middleware is out of scope.

## Findings recorded, not fixed here

- **Fixed in this sprint (found by the new tests):** a cross-field
  `after_or_equal:start_date` rule turned `start_date[]=…` into a TypeError
  (HTTP 500). Order is now checked after per-field validation.
- **Residual (pre-existing, out of scope):** `OversightFilterRequest`
  `visit_date_to => after_or_equal:visit_date_from` still has this shape on the
  OTHER oversight surfaces (clinical record list, monitoring) —
  `visit_date_from[]=…` → 500 there. No data exposure (authorized surface,
  server error only). The dashboard no longer inherits it. Separate sprint.
- **Calendar bounds:** before this revision the dashboard's single `date`
  accepted e.g. `0001-01-01`, whose UTC bound (`0000-12-31 16:00:00`) is not a
  valid PostgreSQL timestamp literal. The dashboard now refuses any date outside
  2000-01-01..2099-12-31. (The monitoring surfaces compare only the DATE column
  `visit_date`, so they are not affected and are unchanged.)
