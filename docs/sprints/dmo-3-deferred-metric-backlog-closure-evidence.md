# DMO-3 Deferred Metric Backlog Closure — Deploy Evidence

## Sprint status

**COMPLETE / MERGED / GO TAGGED / DEPLOYED / SMOKE PASS — GO**

| Field | Value |
| --- | --- |
| Sprint | DMO-3 |
| Feature branch | `feature/dmo-3-deferred-metric-backlog-closure` |
| PR | [#168](https://github.com/makemesick91-code/new_lab_app/pull/168) |
| Merge commit | `baf9489` |
| GO tag | `dmo-3-deferred-metric-backlog-closure-go` |
| VPS path | `/var/www/asia-dental-lab-v2` |
| VPS previous HEAD | `69cc953` (`fg-1-foundation-watch-burndown-combined-go-closure-go`) |
| VPS deployed HEAD | `baf9489` |
| VPS deployed tag | `dmo-3-deferred-metric-backlog-closure-go` |

## DB backup

| Field | Value |
| --- | --- |
| Path | `storage/app/backups/deploy/pre_dmo3_20260704-063552.sql` |
| Size | 587K |

## DQ chain (VPS)

| Sprint | Decision |
| --- | --- |
| DQ-1 | GO (20/20 pass, `--fail-on=error` pass) |
| DQ-2 | GO (10/10 pass, `--fail-on=error` pass) |
| DQ-3 | GO (10/10 pass, `--fail-on=error` pass) |
| DQ-3.1 | GO (0 ambiguous rows) |

## DMO deferred metrics — final status

| ID | Metric | Status | Proof |
| --- | --- | --- | --- |
| DMO-M001 | net_revenue | **GO / resolved** | `DmoMetricService::netRevenue` — collected RME + Lab payments, VOID excluded |
| DMO-M003 | receivable_aging_bucket | **GO / resolved** | `DmoMetricService::receivableAgingBuckets` — invoice-remaining, 5 buckets |
| DMO-M006 | treatment/tariff boundary | **GO / resolved** | `TariffBoundaryService::resolveActiveTariff` — branch-specific, no fallback |
| DMO-M007 | pod_count | **GO / resolved** | `DmoMetricService::podCount` — DELIVERED/COMPLETED + signature proof |

## Governance (VPS)

| Area | Raw | Effective |
| --- | --- | --- |
| NSF | WATCH | GO |
| DMO | **GO** | **GO** |
| DQ | GO | GO |
| Combined Foundation | **GO** | **GO** |

Foundation summary reason: `GO — 4 deferred/evidence/environment watch items documented; no blockers` (NSF only).

DMO governance: 446 passed, 0 warnings, 0 errors — Decision GO.

## Build / migrate / cache

| Step | Result |
| --- | --- |
| composer install --no-dev | OK (nothing to update) |
| npm ci && npm run build | OK (Node 18 EBADENGINE warning non-blocking) |
| php artisan migrate --force | Nothing to migrate |
| optimize:clear + config/route/view/event cache | OK |
| php8.3-fpm restart + nginx reload | OK |

## Smoke

| Check | Result |
| --- | --- |
| `php artisan about` | pilot, debug OFF |
| `curl -I http://127.0.0.1` | HTTP 302 |
| migrate:status | OK |

## Tests (local pre-merge)

- `php artisan test --filter=Dmo3` — 11 passed
- `php artisan test --filter=FoundationGovernance` — 11 passed
- `php artisan test --filter=DmoGovernance` — 9 passed

## Warnings / risks

- npm EBADENGINE for `@tailwindcss/oxide` on Node 18 — build succeeded (non-blocking).
- `git fetch --tags --prune` on VPS may reject legacy tag `sprint-16-complete`; use `git fetch origin tag dmo-3-deferred-metric-backlog-closure-go` for future deploys.
- Receivable aging UI still uses 4 display buckets (`>30`); canonical DMO service uses 5 buckets.
- `net_revenue` / `pod_count` remain blocked from Owner KPI promotion by design.

## Final decision

**GO** — DMO deferred backlog closed; DMO raw GO; Combined Foundation GO; DQ chain GO.

## Next recommended sprint

**NSF-7**
