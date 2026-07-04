# DMO-3 — Deferred Metric Backlog Closure

## Objective

Close DMO deferred backlog items M001/M003/M006/M007 with explicit read-only metric source-of-truth definitions while keeping DQ chain and Combined Foundation GO stable.

## Baseline

- FG-1 Combined Foundation GO (`fg-1-foundation-watch-burndown-combined-go-closure-go`).
- DQ-1 / DQ-2 / DQ-3 / DQ-3.1 GO.
- DMO raw was WATCH due to deferred backlog only.

## Metric definitions

### DMO-M001 — net_revenue

- **Source:** `DmoMetricService::netRevenue`
- **RME:** `SUM(trx_rme_payments.amount)` in period where linked invoice `status != VOID`.
- **Lab:** `SUM(trx_payments.amount)` in period where linked invoice `status != VOID`.
- **net_revenue:** collected RME + Lab (no receivable remaining counted).
- **Limitation:** No separate refund reversal fields at payment level; net equals collected until accounting fields exist.
- **Owner KPI:** continues to use `paid_amount` / `total_revenue` — `net_revenue` is not an Owner KPI card.

### DMO-M003 — receivable_aging_bucket

- **Source:** `DmoMetricService::receivableAgingBuckets`
- **Basis:** invoice remaining = `grand_total - SUM(payments)` for `UNPAID`/`PARTIAL` with `grand_total > 0` and remaining > 0.
- **Age anchor:** `due_date` when column exists, else `created_at`.
- **Buckets:** `0-7`, `8-14`, `15-30`, `31-60`, `61+` (computed at read time; no persisted bucket table).

### DMO-M006 — treatment/tariff multi-branch boundary

- **Source:** `TariffBoundaryService::resolveActiveTariff`
- Treatment master is global; tariff is branch-scoped on `mst_tariffs.branch_id`.
- Lookup uses explicit branch context — **no cross-branch fallback**.
- Missing tariff returns `null` (safe validation state).

### DMO-M007 — pod_count

- **Source:** `DmoMetricService::podCount`
- Counts `trx_lab_deliveries` with `status IN (DELIVERED, COMPLETED)`, receiver signature proof present, `received_at` in period.
- Excludes cancelled/draft deliveries without proof.

## Branch & privacy rules

- Metrics are read-only aggregates; no KTP/NIK or patient row exports.
- Branch filter uses active branch IDs only; owner cross-branch summaries aggregate permitted branches.
- RME and Lab billing tables remain separated.

## Governance config

- `config/foundation_governance.php` — DMO-M001/M003/M006/M007 classified `resolved_metric`.
- `config/dmo.php` — `deferred_warnings` emptied; `resolved_metrics` documents proof.

## Tests

- `tests/Feature/Architecture/Dmo3DeferredMetricBacklogClosureTest.php`
- Updated `FoundationGovernanceSummaryCommandTest`

## Commands

```bash
php artisan architecture:dmo-governance-check --json
php artisan architecture:foundation-governance-summary --json
php artisan test --filter=Dmo3
```

## Deploy procedure

1. DB backup on VPS.
2. Checkout GO tag `dmo-3-deferred-metric-backlog-closure-go`.
3. `composer install --no-dev`, `npm ci && npm run build`.
4. `php artisan migrate --force` only.
5. DQ chain audits + Foundation/DMO governance summary.
6. Cache rebuild + smoke.

## GO / WATCH / NO-GO

| Area | Criteria |
| --- | --- |
| DMO raw GO | DMO-M001/M003/M006/M007 passed; 0 DMO errors |
| Combined GO | DQ chain GO + no blocking NSF/DMO warnings |
| WATCH | Blocking NSF/DMO warnings or unresolved deferred metrics |
| NO-GO | Governance errors or DQ chain failure |

## Known limitations

- `net_revenue` does not subtract refunds/discounts beyond invoice-level discount at payment time.
- Receivable aging UI (`rme.cashier.receivables`) still uses 4 display buckets (`>30`); canonical DMO buckets use five bands including `31-60` and `61+`.
- `net_revenue` / `pod_count` remain blocked from Owner KPI promotion.

## Permanent rules (DMO-3)

- DMO metrics must have explicit source-of-truth definitions in code + tests.
- Revenue metrics must not count receivable remaining as collected revenue.
- RME and Lab billing metrics must remain separated unless combined intentionally and documented.
- Receivable aging must be invoice-remaining based, not visit-status based.
- Tariff lookup must be branch-specific and must not silently fallback cross-branch.
- POD count must count only confirmed proof/completed delivery source records.
- DMO governance checks must show source/proof and not remain deferred without owner/risk/target sprint.
