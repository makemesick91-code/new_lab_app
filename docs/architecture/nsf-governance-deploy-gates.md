# NSF Governance & Deploy Gates (NSF-6)

## 1. Purpose

Define pre-merge, pre-GO-tag, and VPS deploy gates for National Scale Foundation sprints.

## 2. Pre-merge gates

| Gate | Command / check |
| --- | --- |
| NSF governance | `php artisan architecture:nsf-governance-check --strict --include-dmo` |
| DMO governance | `php artisan architecture:dmo-governance-check --strict` |
| DQ-1 data quality | `php artisan data-quality:dq1-audit --fail-on=error` |
| Foundation summary | `php artisan architecture:foundation-governance-summary` |
| Targeted tests | `--filter=NsfGovernance`, `DmoGovernance`, `Dq1`, `DataQuality`, `OwnerKpiRegistry` |
| Full suite | `php artisan test` |
| Style | `./vendor/bin/pint --dirty` |
| Build | `npm ci && npm run build` |

## 3. Pre-GO-tag gates

| Gate | Requirement |
| --- | --- |
| PR merged | Into stable base branch |
| NSF decision | GO or WATCH (deferred backlog only) |
| DMO decision | GO or WATCH (deferred backlog only) |
| Evidence | `storage/app/architecture/nsf6-governance-check.json` |
| Rollback plan | Documented in sprint evidence |

## 4. VPS deploy gates

| Gate | Requirement |
| --- | --- |
| Pre-deploy backup | `storage/app/backups/deploy/pre_dq1_*.sql` or `pre_nsf6_*.sql` with recorded size |
| DQ-1 audit | `php artisan data-quality:dq1-audit --fail-on=error` — GO or controlled WATCH |
| GO tag checkout | Deploy exact sprint GO tag first |
| Migrate | `php artisan migrate --force` only — never `migrate:fresh` / `db:wipe` |
| Cache rebuild | config/route/view/event cache |
| Services | php8.3-fpm restart, nginx reload |

## 5. Evidence file standards

| Path pattern | Content |
| --- | --- |
| `storage/app/architecture/dq1-*.json` | DQ-1 ACID/constraint/data quality evidence |
| `storage/app/architecture/nsf6-*.json` | NSF/DMO governance evidence |
| `storage/app/performance/nsf6-*.json` | Runtime observability, slow query audit |

Record path and file size in sprint evidence. No PHI/PII or raw row-level financial data.

## 6. Smoke standards

| URL | Expected |
| --- | --- |
| `/login` | HTTP 200 |
| Protected routes (`/dashboard`, `/rme/visits`, `/inventory/dashboard`) | HTTP 302 to login when unauthenticated |
| Any route | No Laravel 500 |

## 7. Rollback standards

- Revert sprint commit(s) on stable branch.
- No DB rollback expected when sprint has no migrations.
- VPS: checkout previous GO tag or stable HEAD; restore DB from pre-deploy backup only if data migration occurred.

## 8. GO/WATCH/NO-GO decision standard

| Decision | Condition |
| --- | --- |
| **GO** | Zero error-level NSF and DMO rule failures |
| **WATCH** | Warnings only (manual gates, deferred backlog, pg_stat local N/A) |
| **NO-GO** | Any error-level rule failure |

## 9. NDA handoff readiness

Before NDA-1:

- NSF-R001–R021 active in `config/nsf.php`
- `architecture:nsf-governance-check` and `architecture:foundation-governance-summary` available
- VPS evidence captured with pg_stat status
- Both DMO and NSF governance WATCH/GO with zero errors
