# NSF-8 — Node 20+ & Observability Raw GO Closure Evidence

**Status:** COMPLETE — MERGED / GO TAGGED / DEPLOYED / SMOKE PASS — **GO**.

## Sprint metadata

| Field | Value |
| --- | --- |
| Feature branch | `feature/nsf-8-node20-observability-raw-go-closure` |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| GO tag | `nsf-8-node20-observability-raw-go-closure-go` |
| PR | [#170](https://github.com/makemesick91-code/new_lab_app/pull/170) — MERGED |
| Feature commit | `1a00787` — NSF-8 harden Node runtime and observability gate |
| Merge commit | `49db18a` |
| GO tag commit | `49db18a` |
| Deploy date | 2026-07-04 |

## PR checks (GitHub Actions — Foundation Evidence Gates)

| Check | Result |
| --- | --- |
| NSF-R011 Critical Test Gate | ✅ PASS (3m6s) |
| NSF-R012 Quality Gate | ✅ PASS (1m10s) |
| NSF-R011 Full Suite Gate | ⏭️ skipped (PR path; runs on base) |
| mergeStateStatus | CLEAN |

## Local validation (pre-merge, already recorded)

- `php artisan test --filter=Nsf8` → 8 passed
- `php artisan test --filter='Nsf8|Nsf7|FoundationGovernance'` → 29 passed
- `php artisan architecture:nsf-governance-check` → GO
- `php artisan architecture:foundation-governance-summary` → Combined GO
- `./vendor/bin/pint --dirty` → passed

## VPS deploy evidence

| Field | Value |
| --- | --- |
| VPS path | `/var/www/asia-dental-lab-v2` |
| Host | `145.79.13.224` (srv1730088) |
| Previous HEAD | `c984973` (tag `nsf-7-evidence-gate-automation-r011-r012-ci-go`) |
| Deployed HEAD | `49db18a` (tag `nsf-8-node20-observability-raw-go-closure-go`) |
| DB backup | `storage/app/backups/deploy/pre_nsf8_20260704-130025.sql` (588K) |
| Composer | `composer install --no-dev --prefer-dist --optimize-autoloader` → OK |
| Migrate | `php artisan migrate --force` → Nothing to migrate |

### Node runtime upgrade (Phase E)

| | Before | After |
| --- | --- | --- |
| node | v18.19.1 | **v20.20.2** |
| npm | 9.2.0 | 10.8.2 |

Upgrade path: NodeSource `setup_20.x` → `apt-get install -y nodejs`. `which node` → `/usr/bin/node`.

### npm build (Phase G)

- `npm ci` → added 160 packages, **no EBADENGINE**.
- `npm run build` → vite v6.4.3, 56 modules transformed, built in 1.72s, **no Tailwind oxide EBADENGINE** (Node 18 warning eliminated by the Node 20 upgrade).

## Deploy gates (Phase H)

| Gate | Result |
| --- | --- |
| DQ-1 (`data-quality:dq1-audit --fail-on=error`) | GO (20/20) |
| DQ-2 (`inventory:batch-governance-audit --fail-on=error`) | GO (10/10) |
| DQ-3 (`inventory:source-document-batch-audit --fail-on=error`) | GO (10/10) |
| DQ-3.1 (`inventory:ambiguous-batch-review-pack`) | GO (0 ambiguous) |
| DMO (`architecture:dmo-governance-check`) | GO (15 rules / 446 passed / 0 warn) |
| NSF (`architecture:nsf-governance-check`) | GO (23 rules / 21 passed) |
| NSF `--include-observability` | GO (23 rules / 22 passed / 0 warn / 0 err) |
| NSF raw | **GO** |
| NSF effective | **GO** |
| Combined Foundation | **GO — all foundation checks green** |

### NSF-R009 observability (the NSF-8 crux)

From `architecture:foundation-governance-summary --json` → `nsf_governance.observability`:

- `pg_stat_database.readable` = **true** (view `pg_stat_database`, db `asia_dental_lab_pilot`)
- `pg_stat_statements`: extension_installed = true, preloaded = true
- `slow_query_audit_command_available` = true; `runtime_query_observability_command_available` = true

Result: with `--include-observability` the observability rule executes and **passes** (22 vs 21 passed), so **NSF raw = GO** (no observability WATCH). NSF-M002 is now closed in NSF-8 (reclassified `environment`): *"NSF-R009 pg_stat observability validated via --include-observability on VPS deploy; pg_stat_database is sufficient for raw GO."*

### NSF-R011 / NSF-R012

- NSF-R011 (Critical Test Gate) — PASS in PR CI + configured as automated CI gate.
- NSF-R012 (Quality Gate — build + pint) — PASS in PR CI.
- FG1-CI-001 (NSF-R011/R012 automated CI evidence gates configured) — passed.

## Cache / services / smoke (Phase I)

- `optimize:clear` + `config:cache` + `route:cache` + `view:cache` + `event:cache` → all CACHED.
- Ownership `www-data:www-data` on `storage` + `bootstrap/cache`; dirs 775 / files 664.
- `systemctl restart php8.3-fpm` → OK; `nginx -t` → syntax OK; `systemctl reload nginx` → OK.

Smoke:

| Check | Result |
| --- | --- |
| `php artisan about` | pilot / Laravel 12.61.0 / PHP 8.3.6 / Config,Route,View,Event CACHED |
| `php artisan migrate:status` | up to date (35 migrations Ran) |
| `curl -I http://127.0.0.1` | **HTTP 302** (login redirect — healthy) |
| `route:list` (inventory\|data-quality\|architecture\|dashboard\|rme\|reports) | 199 routes |
| `storage/logs/laravel.log` tail | no ERROR/CRITICAL/Exception |

## Warnings / risks

- None blocking. `nsf_governance.deploy_gates` flags DQ audit / backfill dry-run gates as "documented: false" (warning-level, non-blocking) — the DQ audits themselves all ran GO during this deploy and are recorded above.
- Node runtime is now Node 20 LTS; keep future deploys on Node ≥20 to preserve EBADENGINE-free builds.

## Final decision

**GO.** Node ≥20 (v20.20.2), npm build clean (no EBADENGINE), DQ-1/DQ-2/DQ-3/DQ-3.1 GO, DMO GO, NSF-R009 `--include-observability` GO (pg_stat_database readable), NSF-R011/R012 GO, NSF raw GO, NSF effective GO, Combined Foundation GO, deploy + smoke pass.

Preserved chains: DQ chain GO, DMO GO, NSF R011/R012 GO, Combined Foundation GO. GO tag `nsf-8-node20-observability-raw-go-closure-go` → `49db18a`; **not moved** by this docs-only evidence commit.
