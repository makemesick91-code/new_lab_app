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
| Roadmap check (NSF-9) | `php artisan architecture:foundation-roadmap-check` |
| Feature flags (NSF-9) | `php artisan foundation:feature-flags` |
| Release safety (NSF-9/NSF-10) | `php artisan foundation:release-safety-check [--profile=local\|ci\|vps]` |
| Automated smoke (NSF-9) | `php artisan release:automated-smoke` |
| Backup verify (NSF-10) | `php artisan foundation:backup-verify --path=<backup.sql>` |
| Release evidence capture (NSF-10) | `php artisan release:evidence-capture --profile=ci\|vps` |
| Release evidence check (NSF-10) | `php artisan release:evidence-check --profile=ci\|vps` |

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
| Roadmap check (NSF-9) | `php artisan architecture:foundation-roadmap-check` |
| Feature flags (NSF-9) | `php artisan foundation:feature-flags` — no risky flag enabled |
| Cache governance (CACHE-1) | `php artisan foundation:cache-governance-check` — GO required; JSON → `storage/release-evidence/latest/cache-governance-check.json` |
| Queue governance (QUEUE-1) | `php artisan foundation:queue-governance-check` — GO/WATCH required; JSON → `storage/release-evidence/latest/queue-governance-check.json` |
| Idempotency audit (QUEUE-1) | `php artisan foundation:idempotency-audit` — GO/WATCH required; JSON → `storage/release-evidence/latest/idempotency-audit.json` |
| Outbox audit (QUEUE-1) | `php artisan foundation:outbox-audit` — GO/WATCH required; JSON → `storage/release-evidence/latest/outbox-audit.json` |
| DB performance governance (DBPERF-1) | `php artisan foundation:db-performance-check --include-db-stats` — GO/WATCH required; JSON → `storage/release-evidence/latest/db-performance-check.json` |
| Release safety (NSF-9) | `php artisan foundation:release-safety-check` |
| Automated smoke (NSF-9) | `php artisan release:automated-smoke --base-url=http://127.0.0.1` |
| Foundation summary | `php artisan architecture:foundation-governance-summary` |
| Backup verify (NSF-10) | `php artisan foundation:backup-verify --path="$BACKUP"` — GO/WATCH required, never FAIL |
| Release evidence capture (NSF-10) | `php artisan release:evidence-capture --profile=vps --base-url=http://127.0.0.1 --backup-path="$BACKUP"` |
| Release evidence check (NSF-10) | `php artisan release:evidence-check --profile=vps` |
| Release safety, vps profile (NSF-10) | `php artisan foundation:release-safety-check --profile=vps` — must be GO after evidence capture |
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

## ROADMAP-1 Source Lock (2026-07-04)

- Foundation sequencing is source-locked in
  [`config/foundation_roadmap.php`](../../config/foundation_roadmap.php); see
  [`national-foundation-expansion-roadmap.md`](national-foundation-expansion-roadmap.md).
- Foundation governance summary must include the roadmap check — the deploy gate now
  also runs `architecture:foundation-roadmap-check` (GO/WATCH/FAIL) alongside the
  DQ/DMO/NSF/Combined gates.
- Production deploys stay additive; roadmap changes require a dedicated ROADMAP update
  sprint + evidence doc. NSF-9 completed this sequence; next locked sprint: **NSF-10**.

## NSF-9 Release Safety, Feature Flag & Automated Smoke (2026-07-04)

- New commands: `php artisan foundation:feature-flags [--json]`,
  `php artisan foundation:release-safety-check [--json]`,
  `php artisan release:automated-smoke [--base-url=] [--json]`.
- New configs: `config/feature_flags.php`, `config/release_safety.php`,
  `config/automated_smoke.php`.
- Full policy: [`nsf-9-release-safety-feature-flag-automated-smoke.md`](nsf-9-release-safety-feature-flag-automated-smoke.md).
- Pre-merge/pre-GO-tag/VPS-deploy gate tables below are updated to include the
  three new NSF-9 commands alongside DQ/DMO/NSF/ROADMAP — see updated rows in
  §2–§4.
- Foundation governance summary (`architecture:foundation-governance-summary`)
  now prints `FEATURE_FLAGS`, `RELEASE_SAFETY`, and `AUTOMATED_SMOKE` sections
  in addition to `NSF`/`DMO`/`DQ`/`ROADMAP`/`Combined`.
- CI: `.github/workflows/foundation-evidence-gates.yml` job
  `release_safety_gate` runs the three NSF-9 commands + roadmap check on every
  PR/push (needs `critical_test_gate`).
- Deploy: `scripts/deploy-vps.sh` runs `foundation:feature-flags`,
  `foundation:release-safety-check`, and `release:automated-smoke` (twice:
  command-readiness pre-restart, `--base-url=http://127.0.0.1` post-restart).

## NSF-10 Observability, Backup & Release Safety Hardening (2026-07-04)

- New commands: `php artisan foundation:backup-verify {--path=} {--json}`,
  `php artisan release:evidence-capture {--profile=local|ci|vps} {--base-url=}
  {--backup-path=} {--json}`, `php artisan release:evidence-check
  {--profile=local|ci|vps} {--json}`.
- New configs: `config/backup_governance.php`, `config/release_evidence.php`.
- `foundation:release-safety-check` and `architecture:foundation-governance-summary`
  both gain `--profile=local|ci|vps` (default `local`, backward compatible
  with the NSF-9 no-argument call) and now consume the captured evidence
  chain instead of a static local file-existence list.
- Full policy: [`nsf-10-observability-backup-release-safety-hardening.md`](nsf-10-observability-backup-release-safety-hardening.md).
- CI: `.github/workflows/foundation-evidence-gates.yml` job
  `nsf10_release_evidence_gate` (needs `release_safety_gate`) captures/checks
  CI evidence and uploads `storage/ci-evidence` as artifact
  `nsf-10-release-evidence`.
- Deploy: `scripts/deploy-vps.sh` runs `foundation:backup-verify` right after
  the existing DQ/DMO/NSF/roadmap/flags/smoke/summary gates, then
  `release:evidence-capture --profile=vps`, `release:evidence-check
  --profile=vps`, and `foundation:release-safety-check --profile=vps` before
  cache rebuild/restart. No existing gate was removed.
- Closes the NSF-9 `RELEASE_SAFETY: WATCH`.
