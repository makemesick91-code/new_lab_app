# NSF Governance & Deploy Gates (NSF-6)

## 1. Purpose

Define pre-merge, pre-GO-tag, and VPS deploy gates for National Scale Foundation sprints.

## 2. Pre-merge gates

| Gate | Command / check |
| --- | --- |
| NSF governance | `php artisan architecture:nsf-governance-check --strict --include-dmo` |
| NSF observability (VPS deploy) | `php artisan architecture:nsf-governance-check --include-observability` |
| DMO governance | `php artisan architecture:dmo-governance-check --strict` |
| DQ-1 data quality | `php artisan data-quality:dq1-audit --fail-on=error` |
| DQ-2 batch governance | `php artisan inventory:batch-governance-audit --fail-on=error` |
| DQ-3 source-document batch | `php artisan inventory:source-document-batch-audit --fail-on=error` |
| DQ-2 backfill (pre-execute) | `php artisan inventory:backfill-missing-batches --dry-run` |
| DQ-3 backfill (pre-execute) | `php artisan inventory:backfill-source-document-batches --dry-run` |
| DQ-3.1 review pack | `php artisan inventory:ambiguous-batch-review-pack` |
| DQ-3.1 repair (pre-execute) | `php artisan inventory:repair-ambiguous-batch-links --mapping=<approved> --dry-run` |
| Foundation summary | `php artisan architecture:foundation-governance-summary` |
| Foundation summary (JSON) | `php artisan architecture:foundation-governance-summary --json` |

FG-1 rules: Foundation summary must enumerate exact WATCH causes (rule ID + classification). Combined GO is allowed when DQ chain is GO and remaining NSF/DMO warnings are deferred backlog, evidence-only, environment, or **automated_ci_gate** — see `docs/architecture/fg-1-foundation-watch-burndown-combined-go-closure.md`.

NSF-7 (CI evidence gates): `.github/workflows/foundation-evidence-gates.yml` automates NSF-R011 (critical + full suite) and NSF-R012 (build/pint). See `docs/architecture/nsf-7-evidence-gate-automation-r011-r012-ci.md`.

NSF-8 (VPS Node 20+ & observability): VPS deploy must use Node >=20 and `architecture:nsf-governance-check --include-observability`. See `docs/architecture/nsf-8-node20-observability-raw-go-closure.md`.
| Targeted tests | `--filter=NsfGovernance`, `DmoGovernance`, `Dq1`, `DataQuality`, `OwnerKpiRegistry` |
| Full suite | `php artisan test` (CI: `full_suite_gate` job on schedule/push/dispatch) |
| Critical regression | CI: `critical_test_gate` job on PR |
| Style | `./vendor/bin/pint --test` (CI: `quality_gate` job) |
| Build | `npm ci && npm run build` (CI: `quality_gate` job) |
| CI workflow | `.github/workflows/foundation-evidence-gates.yml` |
| Local CI script | `bash scripts/ci/foundation-evidence-gates.sh` |

## 3. Pre-GO-tag gates

| Gate | Requirement |
| --- | --- |
| PR merged | Into stable base branch |
| NSF decision | GO or WATCH (deferred backlog only) |
| DMO decision | GO (DMO-3 resolved M001/M003/M006/M007) or WATCH for new deferred items only |
| Evidence | `storage/app/architecture/nsf6-governance-check.json` |
| Rollback plan | Documented in sprint evidence |

## 4. VPS deploy gates

| Gate | Requirement |
| --- | --- |
| Pre-deploy backup | `storage/app/backups/deploy/pre_dq1_*.sql`, `pre_dq2_*.sql`, or `pre_nsf6_*.sql` with recorded size |
| DQ-1 audit | `php artisan data-quality:dq1-audit --fail-on=error` — GO or controlled WATCH |
| DQ-2 audit | `php artisan inventory:batch-governance-audit --fail-on=error` — GO or controlled WATCH |
| DQ-3 audit | `php artisan inventory:source-document-batch-audit --fail-on=error` — GO or controlled WATCH |
| DQ-2 backfill | Dry-run first; `--execute` only when deterministic/safe |
| DQ-3 backfill | Dry-run first; `--execute` only when deterministic/safe |
| DQ-3.1 repair | Review pack → approved mapping → dry-run → backup → `--execute` only when mapping validates |
| GO tag checkout | Deploy exact sprint GO tag first |
| Migrate | `php artisan migrate --force` only — never `migrate:fresh` / `db:wipe` |
| Node runtime | Node >=20 required for `npm ci && npm run build` (NSF-8) |
| NSF observability | `php artisan architecture:nsf-governance-check --include-observability` |
| Foundation summary | `php artisan architecture:foundation-governance-summary` |
| Cache rebuild | config/route/view/event cache |
| Services | php8.3-fpm restart, nginx reload |

## 5. Evidence file standards

| Path pattern | Content |
| --- | --- |
| `storage/app/architecture/dq2-*.json` | DQ-2 batch governance / backfill evidence |
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
