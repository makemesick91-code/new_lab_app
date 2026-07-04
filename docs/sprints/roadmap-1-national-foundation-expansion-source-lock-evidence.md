# ROADMAP-1 — National Foundation Expansion Source Lock (Evidence)

- **Status:** COMPLETE / MERGED / GO TAGGED / DEPLOYED / SMOKE PASS
- **Feature branch:** `feature/roadmap-1-national-foundation-expansion-source-lock` (deleted after merge)
- **Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (NOT main)
- **GO tag:** `roadmap-1-national-foundation-expansion-source-lock-go`
- **PR:** #171 — https://github.com/makemesick91-code/new_lab_app/pull/171
- **Merge commit:** `b3b3858`
- **GO tag commit:** `b3b3858`
- **VPS deployed HEAD:** `b3b3858` (tag `roadmap-1-national-foundation-expansion-source-lock-go`), path `/var/www/asia-dental-lab-v2`, env `pilot`
- **DB backup path/size:** `storage/app/backups/deploy/pre_roadmap1_20260704-134335.sql` — 589K
- **CI gates:** NSF-R011 Critical Test Gate `pass`, NSF-R012 Quality Gate `pass` (Full Suite Gate skipped on PR by design)

## Objective

Lock the full planned national foundation expansion roadmap into source so future
Cursor/Claude Code work cannot drift outside the approved plan. Docs/config/governance
only — no foundation feature implemented, no domain behavior change, no destructive
migration.

## Roadmap Sequence (source-locked)

`config/foundation_roadmap.php` → `approved_sequence`:

1. NSF-9 — Release Safety, Feature Flag & Automated Smoke
2. NSF-10 — Observability, Backup & Release Safety Hardening
3. CACHE-1 — Cache Strategy, Redis Readiness & Invalidation Governance
4. QUEUE-1 — Queue, Idempotency & Outbox Foundation
5. DBPERF-1 — PostgreSQL Index Optimization & Query Plan Audit
6. DBPERF-2 — PgBouncer & PostgreSQL Runtime Tuning
7. RPT-1 — Materialized View + rpt_* Summary Foundation
8. STORAGE-1 — Object Storage Readiness
9. STATELESS-1 — Stateless App Readiness
10. LB-1 — Load Balancer Pilot
11. REPLICA-1 — Read Replica Readiness
12. PART-1 — Partitioning Design, Not Production Yet
13. SEARCH-1 — Search Engine & Log Explorer Foundation
14. NDA-1 — National Distributed Architecture Plan
15. RC-1 — Foundation Green Release Candidate Consolidation

**Next recommended sprint:** NSF-9. **RC-1 locked after all expansion sprints.**

## Files Changed

- `config/foundation_roadmap.php` (new) — machine-readable source-locked roadmap.
- `app/Services/Architecture/FoundationRoadmapService.php` (new) — GO/WATCH/FAIL validator.
- `app/Console/Commands/ArchitectureFoundationRoadmapCheckCommand.php` (new) — `architecture:foundation-roadmap-check`.
- `app/Services/Architecture/FoundationGovernanceSummaryService.php` — ROADMAP section + summary keys + command availability.
- `app/Console/Commands/ArchitectureFoundationGovernanceSummaryCommand.php` — renders ROADMAP section.
- `docs/architecture/national-foundation-expansion-roadmap.md` (new) — canonical narrative roadmap.
- `docs/architecture/nsf-application-rules.md`, `nsf-governance-deploy-gates.md`, `fg-1-foundation-watch-burndown-combined-go-closure.md`, `nsf-8-node20-observability-raw-go-closure.md` — source-lock rules added.
- `docs/ai-knowledge/25_DaengtisiaMS_AI_Workflow_Prompts.md` — roadmap reference for Cursor/Claude.
- `tests/Feature/Architecture/FoundationRoadmapGovernanceTest.php` (new) — 13 tests.
- `docs/sprints/roadmap-1-national-foundation-expansion-source-lock-evidence.md` (this doc).

## Command Outputs (local)

```
$ php artisan architecture:foundation-roadmap-check
Decision: GO | Checks: 12 | Passed: 12 | Warnings: 0 | Errors: 0
Next recommended sprint: NSF-9 | Total planned sprints: 15 | RC locked after expansion: yes

$ php artisan architecture:foundation-governance-summary  (ROADMAP section)
ROADMAP: GO (effective: GO) — track: foundation_expansion
  - next recommended sprint: NSF-9
  - total planned sprints: 15
  - RC-1 locked after expansion: yes
Combined: GO — all foundation checks green

$ php artisan data-quality:dq1-audit            → Decision: GO (20/20)
$ php artisan inventory:batch-governance-audit  → Decision: GO (10/10)  [DQ-2]
$ php artisan inventory:source-document-batch-audit → Decision: GO (10/10)  [DQ-3]
$ php artisan inventory:ambiguous-batch-review-pack → Decision: GO (0 ambiguous)  [DQ-3.1]
$ php artisan architecture:dmo-governance-check → Decision: GO (446 passed, 0 errors)
$ php artisan architecture:nsf-governance-check --include-observability → Decision: GO (22 passed, 0 errors)
```

Environment: Laravel 12.61.0 · PHP 8.5.4 · pgsql · queue=database.

## Tests

```
$ php artisan test --filter=FoundationRoadmap
Tests: 13 passed (402 assertions)

$ php artisan test --filter=FoundationGovernance
Tests: 11 passed (48 assertions)
```

## Quality

```
$ ./vendor/bin/pint --dirty   → passed (1 file auto-fixed: test import order)
$ git diff --check            → clean
$ graphify update .           → graph.json + GRAPH_REPORT.md updated
```

## Governance Rules Added

- Foundation roadmap is source-locked in `config/foundation_roadmap.php`.
- Future foundation work must follow the config; Cursor/Claude must reference the roadmap
  before creating any foundation sprint.
- Any order/scope change requires a dedicated ROADMAP update sprint + evidence doc.
- Foundation governance summary + deploy gates now include the roadmap check.

## VPS Deploy

- Path: `/var/www/asia-dental-lab-v2`, env `pilot`, Laravel 12.61.0, PHP 8.3.6.
- DB backup first: `pre_roadmap1_20260704-134335.sql` (589K) — additive only, no migrate:fresh/db:wipe.
- `git checkout roadmap-1-national-foundation-expansion-source-lock-go` → HEAD `b3b3858`.
- `composer install --no-dev --optimize-autoloader` (regenerated autoloader for new command/service).
- `php artisan migrate --force` → **Nothing to migrate** (no schema change).
- Gates on VPS: roadmap **GO** (12/12), summary ROADMAP **GO** / Combined **GO**, DQ1 **GO**, DQ-2 **GO**, DQ-3 **GO**, DQ-3.1 **GO** (0 ambiguous), DMO **GO** (446/0), NSF+observability **GO** (22/0).
- `optimize:clear` + config/route/view/event cache rebuilt; storage/bootstrap perms reset (www-data 775/664).
- `systemctl restart php8.3-fpm`; `nginx -t` OK; `systemctl reload nginx`.
- Smoke: `curl -I http://127.0.0.1` → **HTTP 302** (redirect to login); `laravel.log` no new ERROR/Exception/CRITICAL.

## Final Decision

ROADMAP-1 source lock: **GO** (docs/config/governance only; DQ/DMO/NSF/Combined preserved GO).
