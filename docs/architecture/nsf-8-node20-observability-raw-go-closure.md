# NSF-8 — Node 20+ Runtime & Observability Raw GO Closure

## Objective

Close remaining NSF raw WATCH from NSF-7 by:

1. Upgrading VPS Node runtime to 20+ (eliminate Tailwind `@tailwindcss/oxide` EBADENGINE on Node 18).
2. Automating observability deep-check in deploy gate via `--include-observability`.
3. Hardening NSF-R009 to verify read-only `pg_stat_database` access (pg_stat_statements optional).

## Baselines

| Sprint | Status |
| --- | --- |
| DQ-1 / DQ-2 / DQ-3 / DQ-3.1 | GO |
| DMO-3 | GO (raw + effective) |
| NSF-7 | MERGED — R011/R012 automated CI gates GO |
| FG-1 | Combined Foundation GO |

NSF-7 merge commit: `c984973`. GO tag: `nsf-7-evidence-gate-automation-r011-r012-ci-go`.

## Node 18 EBADENGINE risk

VPS Node 18 caused `npm run build` EBADENGINE for `@tailwindcss/oxide` (requires Node >=20). Build succeeded but emitted warning. NSF-8 policy: VPS Node must be **>=20** for frontend build.

CI already uses Node 22 (`.github/workflows/foundation-evidence-gates.yml`). `package.json` declares `"engines": { "node": ">=20" }`.

## NSF-R009 observability definition

| Mode | Behavior |
| --- | --- |
| Without `--include-observability` | NSF-R009 status `skipped` — transparent, non-blocking; raw NSF not WATCH solely for skipped deep-check |
| With `--include-observability` on pgsql | Read-only `SELECT` from `pg_stat_database` for `current_database()` |
| pg_stat_statements | Optional enhancement; not required for NSF-R009 raw GO |
| Non-pgsql (CI/local SQLite) | `not_applicable` |

Checks are read-only. No `CREATE EXTENSION`, no superuser operations, no destructive DB access.

## Deploy gate (permanent)

```bash
php artisan data-quality:dq1-audit --fail-on=error
php artisan inventory:batch-governance-audit --fail-on=error
php artisan inventory:source-document-batch-audit --fail-on=error
php artisan inventory:ambiguous-batch-review-pack
php artisan architecture:dmo-governance-check
php artisan architecture:nsf-governance-check --include-observability
php artisan architecture:foundation-governance-summary
npm ci && npm run build   # Node >=20 on VPS
```

CI (`scripts/ci/foundation-evidence-gates.sh`) runs NSF governance **without** `--include-observability` — pg_stat is production/VPS only.

## GO / WATCH / NO-GO

| Decision | Condition |
| --- | --- |
| **GO** | Zero error-level failures; NSF-R009 `passed` when `--include-observability` on VPS pgsql |
| **WATCH** | pg_stat_database unreadable; or observability deep-check not run on deploy |
| **NO-GO** | Any error-level NSF/DMO/DQ failure |

## Node upgrade rollback

Document pre-upgrade `node --version` / `npm --version`. Rollback: reinstall prior Node package (apt/NodeSource) if build fails; app PHP code unchanged.

## Deployment steps

1. DB backup to `storage/app/backups/deploy/pre_nsf8_*.sql`
2. Upgrade Node to 20+ if needed (NodeSource 20.x)
3. Checkout GO tag `nsf-8-node20-observability-raw-go-closure-go`
4. `composer install --no-dev`, `npm ci`, `npm run build`
5. `php artisan migrate --force`
6. Run deploy governance gates (above)
7. Cache rebuild + php8.3-fpm/nginx restart
8. Smoke: `php artisan about`, route list, HTTP checks

## Governance rules added

- VPS Node runtime must be >=20 for frontend build.
- Deploy gate must run `architecture:nsf-governance-check --include-observability`.
- NSF-R009 raw GO requires `pg_stat_database` readable in production deploy environment.
- CI evidence gates R011/R012 remain mandatory (NSF-7).
- GO tag must not be moved for docs-only evidence commits.

## ROADMAP-1 Source Lock (2026-07-04)

- NSF-8 is the locked **baseline** for the national foundation expansion roadmap
  ([`config/foundation_roadmap.php`](../../config/foundation_roadmap.php),
  [`national-foundation-expansion-roadmap.md`](national-foundation-expansion-roadmap.md)).
- All future foundation sprints must follow the locked sequence starting at **NSF-9**.
  Order/scope changes require a dedicated ROADMAP update sprint + evidence doc.
- Foundation governance summary + deploy gates now include
  `architecture:foundation-roadmap-check` (GO/WATCH/FAIL).
