# DQ-1 ACID, Constraint & Data Quality Audit — Deploy Evidence

**Sprint:** DQ-1  
**Date:** 2026-07-04  
**Final decision:** **WATCH** (deploy OK; controlled data-quality backlog on batch-tracked movements)

## Git / release

| Item | Value |
| --- | --- |
| Feature branch | `feature/dq-1-acid-constraint-data-quality-audit` |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| PR | [#163](https://github.com/makemesick91-code/new_lab_app/pull/163) |
| Merge commit | `742af68` |
| GO tag | `dq-1-acid-constraint-data-quality-audit-go` |

## VPS deploy

| Item | Value |
| --- | --- |
| Path | `/var/www/asia-dental-lab-v2` |
| Previous HEAD | `df1f745` (`nsf-6-national-scale-foundation-application-rules-go`) |
| Deployed HEAD | `742af68` (`dq-1-acid-constraint-data-quality-audit-go`) |
| DB backup | `storage/app/backups/deploy/pre_dq1_20260704-041624.sql` |
| Backup size | 584,920 bytes |

## Commands / results

### Local

```text
php artisan test --filter=Dq1          → 7 passed (181 assertions)
./vendor/bin/pint --dirty              → passed
php artisan data-quality:dq1-audit     → 20 checks PASS, decision GO
php artisan data-quality:dq1-audit --fail-on=error → exit 0
php artisan architecture:foundation-governance-summary → Combined WATCH (NSF/DMO pre-existing)
```

### VPS (GO tag `dq-1-acid-constraint-data-quality-audit-go`)

```text
composer install --no-dev              → OK
npm ci && npm run build                → OK (Node 18 EBADENGINE warning on @tailwindcss/oxide — non-blocking)
php artisan migrate --force            → Nothing to migrate
cache rebuild                          → OK
php8.3-fpm + nginx reload              → OK
php artisan about                      → pilot, Laravel 12.61.0
php artisan data-quality:dq1-audit     → 19 PASS, 1 FAIL (DQ1-DATA-006 batch backlog: 12 rows)
php artisan data-quality:dq1-audit --fail-on=error → exit 1 (expected on GO tag — batch backlog)
php artisan architecture:foundation-governance-summary → Combined NO-GO (DQ1 batch backlog)
php artisan tinker environment         → pilot
curl -I http://127.0.0.1               → HTTP response OK
```

## DQ-1 audit summary (VPS production data)

| Category | Result |
| --- | --- |
| ACID (001–004) | PASS |
| CONSTRAINT (001–006) | PASS |
| DATA-001–005, 007–010 | PASS |
| DATA-006 | **FAIL** at GO tag — 12 batch-tracked movements missing `inventory_batch_id` (historical pilot data; report-only) |

## Known warnings / deferred

1. **DQ1-DATA-006 batch backlog (12 rows)** — pre-existing pilot inventory data; no destructive cleanup. Post-GO hotfix on base downgrades this to **WARN** when direction is valid (report-only).
2. **npm EBADENGINE** — Node 18 on VPS vs Tailwind oxide >=20 requirement; build succeeded.
3. **Foundation Combined NO-GO on VPS** — driven by DQ1-DATA-006 FAIL at GO tag only; ACID/constraint checks clean.

## Governance rules added

- `php artisan data-quality:dq1-audit` registered (read-only, `--json`, `--fail-on=error`)
- Integrated into `architecture:foundation-governance-summary`
- Documented in `docs/architecture/dq-1-acid-constraint-data-quality-audit.md`
- Pre-deploy gate updated in `docs/architecture/nsf-governance-deploy-gates.md`
- Future rule: multi-write ops must use `DB::transaction`; migrations additive only

## Smoke

| Check | Status |
| --- | --- |
| App boots (`about`) | PASS |
| Routes cached | PASS |
| DQ-1 command registered | PASS |
| HTTP localhost | PASS |
| No migration errors | PASS |

## Next recommended sprint

**DQ-2** — Batch-tracked movement backfill plan (report → staged fix) or inventory batch governance closure, using DQ-1 evidence as baseline.
