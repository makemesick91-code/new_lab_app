# NSF-9 — Release Safety, Feature Flag & Automated Smoke

## 1. Objective

Lock a release-safety foundation so every future risky infrastructure/foundation
sprint (CACHE-1, QUEUE-1, DBPERF-2, STORAGE-1, STATELESS-1, LB-1, REPLICA-1,
PART-1, SEARCH-1, NDA-1) can ship behind a governed, default-off feature flag
and be verified automatically by a read-only smoke suite before and after
deploy.

## 2. Baseline from ROADMAP-1

- Roadmap source of truth: [`config/foundation_roadmap.php`](../../config/foundation_roadmap.php).
- Canonical doc: [`national-foundation-expansion-roadmap.md`](national-foundation-expansion-roadmap.md).
- Baseline before NSF-9: DQ-1/2/3/3.1 GO, DMO GO, NSF raw/effective GO,
  ROADMAP GO, Combined Foundation GO, Node VPS >=20, observability validated,
  R011/R012 CI evidence gates automated (NSF-8, `b3b3858`).
- NSF-9 is `priority: 1` in `approved_sequence`, depends on `NSF-8`.

## 3. Feature flag policy

- Registry: [`config/feature_flags.php`](../../config/feature_flags.php) — config-driven,
  read-only, one entry per flag with required metadata: `name`, `description`,
  `default`, `env_key`, `owner`, `risk_level`, `rollout_status`,
  `expires_at`/`review_target`, `dependencies`, `rollback_action`.
- Service: `App\Services\Foundation\FeatureFlagService` — `all()`, `get()`,
  `enabled()`, `metadata()`, `assertKnown()`, `riskyEnabledFlags()`,
  `validateGovernance()`.
- Command: `php artisan foundation:feature-flags [--json]`.
- Rule: every future foundation infra flag (`foundation.cache.*`,
  `foundation.queue.*`, `foundation.db.pg_bouncer_readiness`,
  `foundation.reporting.*`, `foundation.storage.*`,
  `foundation.stateless_app_readiness`, `foundation.load_balancer_pilot`,
  `foundation.read_replica_readiness`, `foundation.partitioning_design_only`,
  `foundation.search_log_explorer_readiness`,
  `foundation.national_distributed_architecture_plan`) defaults to `false`
  and has `risk_level` `high`/`critical`. Governance/safety flags introduced
  in NSF-9 itself (`release.automated_smoke_required`,
  `release.rollback_checklist_required`,
  `release.feature_flag_required_for_risky_changes`) default `true` because
  the capability is implemented in this sprint.
- Env override: each flag has a dedicated `env_key`; unset env falls back to
  `default`. No business behavior is changed by this sprint — the registry
  only exists for future services to branch on.

## 4. Release safety policy

- Config: [`config/release_safety.php`](../../config/release_safety.php) — required
  pre-deploy gates, required deploy evidence fields, rollback checklist,
  safety rules, deploy gate file paths.
- Service: `App\Services\Foundation\ReleaseSafetyService::collect()`.
- Command: `php artisan foundation:release-safety-check [--json]`.
- Safety rules: no risky foundation change without a feature flag; no deploy
  without a DB backup; no release without smoke; no release with a failing
  DQ/DMO/NSF/ROADMAP gate; no release with secrets/PII in logs/artifacts.

## 5. Automated smoke policy

- Config: [`config/automated_smoke.php`](../../config/automated_smoke.php).
- Service: `App\Services\Foundation\AutomatedSmokeService::run(?string $baseUrl)`.
- Command: `php artisan release:automated-smoke [--base-url=] [--json]`.
- Script: `scripts/release/automated-smoke.sh` (bash strict mode, `BASE_URL`
  env optional, non-zero exit on FAIL).
- Read-only: verifies app boot, route list compilation, expected named
  routes (`login`, `dashboard`, `rme.visits.index`, `inventory.dashboard`),
  storage/bootstrap-cache writability, governance command registration, and
  — only when `--base-url` is supplied — an HTTP probe of `/login` where
  200/301/302/401/403 are healthy and 500/502/503/504 are failures. Never
  creates/updates/deletes patient, inventory, payment, lab, or RME records;
  never requires credentials; never crawls protected pages.
- Without `--base-url` the result is classified as **command-readiness
  GO**, explicitly not a production HTTP smoke — see the `note` field in
  `release:automated-smoke --json` and the `automated_smoke` section of
  `architecture:foundation-governance-summary`.

## 6. CI/CD integration

- `.github/workflows/foundation-evidence-gates.yml` gains job
  `release_safety_gate` (needs `critical_test_gate`), running
  `foundation:feature-flags`, `foundation:release-safety-check`,
  `release:automated-smoke --json` (command-readiness only in CI — no base
  URL), and `architecture:foundation-roadmap-check`, uploading
  `storage/ci-evidence/nsf-9-*` artifacts.
- `scripts/ci/foundation-evidence-gates.sh` gains `run_release_safety()`,
  invoked by both the default flow and `--critical-only`.

## 7. Deploy gate integration

- `scripts/deploy-vps.sh` deploy governance gates now include
  `architecture:foundation-roadmap-check`, `foundation:feature-flags`,
  `foundation:release-safety-check`, `release:automated-smoke` (pre-restart)
  and `release:automated-smoke --base-url=http://127.0.0.1` (post-restart
  smoke), alongside the pre-existing DQ/DMO/NSF gates (nothing removed).

## 8. GO / WATCH / NO-GO criteria

| Layer | GO | WATCH | NO-GO |
| --- | --- | --- | --- |
| Feature flags | Metadata complete, all risky flags default false, none enabled | Metadata gaps documented | Any risky flag defaults/enabled true |
| Release safety | All required gates/evidence/rollback/safety-rule fields defined, deploy files present, feature-flag governance safe | Local evidence artifacts (CI/VPS-only) not yet captured | Missing config, unregistered gate command, or unsafe flag governance |
| Automated smoke | App boots, routes compile, expected routes exist, storage writable, governance commands registered, (if probed) healthy HTTP status | HTTP probe unreachable locally | Missing route/command/writable path, or failing HTTP status |
| Combined | All of the above GO/WATCH-non-blocking plus pre-existing DQ/DMO/NSF/ROADMAP GO | Any layer WATCH | Any layer FAIL, or any pre-existing DQ/DMO/NSF error |

## 9. Rollback checklist

1. Previous HEAD/tag recorded before deploy.
2. DB backup verified before deploy (`storage/app/backups/deploy/pre_nsf9_*.sql`).
3. Runtime change documented (this doc + evidence doc).
4. Config/env change documented (new config files only — no `.env` secret
   changes required; new `env_key`s are optional overrides).
5. No destructive migration (NSF-9 ships zero migrations).
6. GO tag never moved by a docs-only evidence commit.
7. To roll back: `git checkout` the previous GO tag
   (`nsf-8-node20-observability-raw-go-closure-go` or later confirmed tag)
   and re-run the deploy gates; no DB restore needed since no schema changed.

## 10. What NSF-9 does NOT implement

- No Redis, no queue, no PgBouncer, no read replica, no load balancer, no
  object storage migration, no partitioning, no materialized views, no
  search engine. All corresponding flags exist but default `false`.
- No feature-flag-gated business-behavior change — flags exist for future
  sprints to consume.
- No new migration, no new permission, no new user-facing route.

## 11. Future sprint dependency / next sprint

- Roadmap next recommended sprint: **NSF-10 — Observability, Backup &
  Release Safety Hardening**.
- NSF-10 builds on the NSF-9 flag/smoke foundation to harden backup
  verification and observability coverage before CACHE-1/QUEUE-1/DBPERF-2.
