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

## Shipped — evidence (2026-10-13)

| Item | Value |
|---|---|
| Candidate | `fa0760d2` (tree `faedd63a`) |
| PR | #482, squash-merged as **`3b1e03e0`** — merge tree `faedd63a` = candidate tree |
| CI (PR, exact candidate SHA) | run `38321079967` **success**: Classifier, Quality (Pint + JS 223/223), Selective Module, Android, **Critical 5662 passed / 30023 assertions, exit 0, warning contract 0**, NSF-9, NSF-10 |
| Full Suite | **SKIPPED** — GLOBAL TEMPORARY FULL-SUITE POLICY active (`.github/ci-policy/full-suite-policy.json`); not a pass |
| Deploy | `scripts/deploy-vps-runner.sh start` executed **on the VPS**: `exit=0`, `DEPLOY OK: 20261013-013100`, `DEPLOY_HEAD_TARGET_MATCH=YES (3b1e03e0)`, snapshot trusted + cleaned |
| Backup | `pre_auto_deploy_20261013-013100.sql`, 32,194,697 bytes, verify 9/9 GO, mode 0640 |
| Migrations | none (`Nothing to migrate.`) |
| Runtime isolation | 70 GO / 0 FAIL / 0 SKIP |
| Smoke | 6 passed + 1 known warning (co-tenant loopback `127.0.0.1/login` 404; verified over the canonical domain) |
| GO tag | `revision-oversight-dashboard-date-range-1-go` (object `b6b045e9`) → `3b1e03e0`, exact-match at VPS HEAD |

### Production verification (read-only)

The DEPLOYED service was run on production with the database session forced
read-only, and compared with an independent SQL reconciliation that shares no
code with the application (PostgreSQL `AT TIME ZONE` bounds, `BETWEEN` on the
DATE column):

| Range (WITA) | Patients | Transactions | Revenue | Piutang | Cancel | Match |
|---|---|---|---|---|---|---|
| 2026-10-12 .. 2026-10-30 | 0 | 0 | Rp 0 | Rp 0 | 0 | yes |
| 2026-10-01 .. 2026-10-13 | 3 | 7 | Rp 3,548,000 | Rp 0 | 0 | yes |
| 2026-10-11 .. 2026-10-11 | 2 | 3 | Rp 1,650,000 | Rp 0 | 0 | yes |
| default | today = 2026-10-13 | | | | | yes |

The last production payment (2026-10-11 15:27 UTC = 23:27 WITA on Oct 11) is
counted on Oct 11 and excluded from the range starting Oct 12 — a real
midnight-boundary case. All 14 request-rule cases (range, single, none, legacy
`date`, reversed, only-one, mixed, 366/367 days, bad format, year 0, array,
ignored list keys) behaved as specified. The real dashboard and print views
rendered the two inputs, presets, Reset, period label, current-balance Piutang
wording and a refresh URL carrying both dates; no 16-digit identity number on
the page. Row counts identical before/after; `laravel.log` byte-identical
(1,437,201 bytes / 162 ERROR); no failed jobs; pilot, debug OFF, maintenance
OFF. Guests: page/print/export 302, feed 401 (including an invalid range and
the array case) — never 500. RBAC unchanged: both permissions held only by
Finance Klinik, Data Analyst, Super Admin; 0 direct grants.

**Limit:** no Finance Klinik / Data Analyst session was used (none created on
purpose); the page was verified by rendering the deployed views in-process for
a Super Admin actor, and the HTTP auth boundary by guest requests.
