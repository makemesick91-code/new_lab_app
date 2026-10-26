---
paths:
  - "app/Services/Foundation/**"
  - "app/Services/Architecture/**"
  - "app/Support/Devflow/**"
  - "app/Support/Foundation/**"
  - "app/Support/Runtime/**"
  - "app/Support/LoadBalancer/**"
  - "app/Support/LoadTest/**"
  - "app/Support/Queue/**"
  - "app/Support/Cache/**"
  - "app/Support/Database/**"
  - "app/Support/Documentation/**"
  - "app/Support/Security/**"
  - "config/foundation_*.php"
  - "config/feature_flags.php"
  - "config/devflow.php"
  - "config/queue_governance.php"
  - "config/sprint_*.php"
  - "config/shared_foundations.php"
  - "tests/Feature/Architecture/**"
  - "tests/Feature/Foundation/**"
  - "docs/architecture/**"
  - ".sprint/**"
---

# Enterprise foundation, governance commands & DEVFLOW — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

## Invariants

### Governance summary and readiness commands

- **AIR-FND-01** Publish a new governance rule family as its own informational section in
  `architecture:foundation-governance-summary` (`App\Services\Architecture\FoundationGovernanceSummaryService`). Do not
  wire it into the blocking `combinedDecision` unless the sprint explicitly decides to, and never merge, rename or drop
  an existing section: sibling pairs stay separate (`queue_governance` vs `queue_retry_governance`, `cache_governance`
  vs `cache_redis_governance`, `observability_governance` vs `observability_pipeline_governance`). Source:
  `.cursor/rules/54-…`, `66-…`, `77-…`; archive ENT-5, OBS-2, DEVFLOW-1.
- **AIR-FND-02** Keep every readiness/governance command (`foundation:*-check`, `storage:object-readiness-check`,
  `runtime:stateless-readiness-check`, `lb:readiness-check`, `cache:redis-readiness-check`, `obs:*`) read-only and
  non-destructive: no state mutation, no network vendor call, no secret printed (report booleans such as
  "configured", never the value). Source: archive STORAGE-1, OBS-1, OBS-2, ENT-16; `.cursor/rules/65-…`.
- **AIR-FND-03** `--strict` means different things per command: in most deploy-chain gates it is an alias of
  `--fail-on-warning` and fails on WATCH (`foundation:queue-retry-failed-job-check`,
  `foundation:deployment-rollback-check`, `foundation:health-check`, `lb:readiness-check`,
  `runtime:stateless-readiness-check`), while MON-1 and ROLL-5 fail on FAIL alone. Read the command signature before
  relying on it, and never change an existing command's `--strict` meaning. Guard every probe so an
  error degrades to UNKNOWN/WATCH, never a 500, and never count UNKNOWN as GO. Source: `.cursor/rules/65-…`, `68-…`,
  `69-…`.
- **AIR-FND-04** Keep destructive-command literals and forbidden patterns in config (`config/cicd_enterprise_gate.php`,
  `config/deployment_rollback.php`, `config/enterprise_foundation_closure.php`, `config/devflow.php`,
  `config/queue_governance.php`), never in scanner, app or script source or comments: a scanner that contains the
  literal it bans reports itself. Source: `.cursor/rules/59-…`, `60-…`, `65-…`, `77-…`; archive DEVFLOW-1.
- **AIR-FND-05** Never write the environment-file name as a literal in governance rule text, governance docs, runbooks
  or evidence. `config/release_evidence.php` `forbidden_patterns` bans it and fails the NSF-10 release-evidence gate once
  the rule array is captured into `foundation-governance-summary.json`; write "the environment file". Source: archive
  STORAGE-1, ENT-0; `.cursor/rules/77-…` (rule 9).
- **AIR-FND-06** Register a new mandatory foundation gate everywhere it is consumed: the readiness command,
  `config/release_evidence.php` profiles plus the `ReleaseEvidenceService` capture map, `config/foundation_governance.php`
  CI-gate registry, `config/release_safety.php` pre-deploy gates, the CI workflow and
  `scripts/ci/foundation-evidence-gates.sh`, and `scripts/deploy-vps.sh`. A gate declared but not captured or not run is
  not a gate. Source: archive ENT-10…ENT-16; `.cursor/rules/59-…`, `60-…`.
- **AIR-FND-07** When you add a rule id to a governance service, repin every pin of that family: exact-list
  `toBe([...])` assertions and bare `toHaveCount(N)` assertions alike. Sweep by the publishing service file, not by
  id prefix (OBS-R001…R012 and OBS-R013…R024 come from two deliberately separate services). Source: archive
  FINAL-STABILIZATION-RESIDUAL-AUDIT-1 (`13-cicd-testing.md`).

### Roadmap

- **AIR-FND-08** Treat `config/foundation_roadmap.php` as source-locked. `next_recommended_sprint` is the first entry
  with `status => planned` in priority order (`App\Services\Architecture\FoundationRoadmapService::nextRecommendedSprint`);
  staleness is config-driven, not git-tag-driven, so shipping a GO tag does not move it. It must never point at a
  completed entry. Verify with `foundation:roadmap-check --strict` (same service as `architecture:foundation-roadmap-check`).
  Source: archive ROADMAP-1, MON-1.
- **AIR-FND-09** Never hard-code a next-sprint expectation in a new test; derive it from the roadmap config or service.
  When a sprint legitimately changes roadmap status, repin every existing test that asserts `next_recommended_sprint`
  (about twenty pin the current value) in the same change. Source: archive ROADMAP-1, ENT-0, ENT-16, MON-1.
- **AIR-FND-10** Disambiguate similar roadmap ids with `disambiguates`: `ROADMAP-1` (the older national-expansion
  roadmap) is not `ROADMAP-1-CANONICALIZATION`, and `CACHE-1` is not `CACHE-1-REDIS-READINESS`. A completed,
  governance-tracked entry carries `go_tag` evidence; no entry is marked completed from partial overlap. Source:
  archive ROADMAP-1, ENT-0.
- **AIR-FND-11** Treat the ENT sequence as closed: ENT-1…ENT-16 ended with the `enterprise-foundation-go` tag and
  there is no ENT-17. Post-closure work adds no ENT roadmap entry, moves no ENT or closure tag, and inherits ENT-5…16.
  ENT-1…ENT-4 are governance locks that are audited (`foundation:ent-1-4-audit-check`), never retrofitted into
  runtime. Source: `.cursor/rules/65-…`, `66-…`.

### Feature flags

- **AIR-FND-12** Beyond CG-08: every flag that declares an `env_key` captures `env_value` at config build in
  `config/feature_flags.php`, because under `config:cache` a runtime `env()` read returns nothing.
  `FeatureFlagService::validateGovernance()` must keep failing `FLAG-ENV-CAPTURE` (uncaptured key) and
  `FLAG-ENV-VALUE-VALID` (unparseable override). Source: `.cursor/rules/92-feature-flag-runtime-override.mdc`.
- **AIR-FND-13** Resolve a flag as captured `env_value` → runtime `env()` (uncached only) → declared default. An unset
  or blank override yields the default (blank is not `false`); an invalid one yields the default and fails governance.
  Evidence shows the normalized value (`true`/`false`/`null`/`'invalid'`), never the raw string. No request, header,
  session or browser state may influence a flag. Source: `.cursor/rules/92-feature-flag-runtime-override.mdc`.
- **AIR-FND-14** Declare `default => false` for every high/critical flag. Enabling one is a deliberate WATCH, rollback
  is "set false and rebuild the config cache" with no schema rollback, and `rollout_status: implemented` describes the
  code, never the production switch. Source: `.cursor/rules/92-feature-flag-runtime-override.mdc`.

### Queue governance

- **AIR-FND-15** Make every `ShouldQueue` class extend `App\Support\Queue\EnterpriseQueueJob`, use
  `EnterpriseQueueRetryDefaults`, or declare `$tries`, `$backoff` and `$timeout` within
  `config/queue_governance.php` `ent5_retry_failed_job.retry_standards` (defaults 3 / [10,60,180] / 120; caps tries ≤ 5,
  timeout ≤ 600) — ENT5-Q008. Gate: `foundation:queue-retry-failed-job-check --strict`. Source:
  `.cursor/rules/54-queue-retry-failed-job-governance.mdc`.
- **AIR-FND-16** Use the `sync` connection only in local/testing; pilot and production use `database` (`redis` only
  after a readiness GO). Failed jobs stay on `database-uuids` + `failed_jobs`, never disabled. Source:
  `.cursor/rules/54-…`.
- **AIR-FND-17** Give every produced queue a verified consumer before its feature reaches GO (ENT5-Q009): register it
  in `producer_consumer_contract.produced_queues` by the config key that resolves its name, add it to
  `ent5_retry_failed_job.allowed_queue_names`, append it to the `ExecStart` `--queue` list of
  `deploy/systemd/daengtisiams-queue-worker.service`, and to `queue_worker.approved_queues`. Append (order is priority),
  compare names exactly, and never count a queue named only in a comment. `consumer_required_when` is a live flag read,
  not an exemption. Source: `.cursor/rules/133-dedicated-queue-producer-consumer-contract.mdc` (supersedes the
  four-name list in rule 54).
- **AIR-FND-18** Diagnose a domain row stuck at `QUEUED` with `attempts = 0` and `reserved_at` NULL as a missing
  consumer. Restore the consumer and let the existing job drain; never `UPDATE` the status, retry, redispatch, clone or
  re-upload, and never "fix" it by moving the producer to `default`. Source: `.cursor/rules/133-…`.
- **AIR-FND-19** Never let a deploy install or start a worker. The worker runs `queue:work` only (never `queue:listen`
  or `--daemon`) as `daengtisiams`; activation is an operator step (`docs/runbooks/queue-worker-activation-runbook.md`).
  Installed-vs-tracked unit drift is an operational note, never a deploy gate, and systemd directives sit in the
  section systemd honours (`StartLimitIntervalSec` in `[Unit]`, ENT5-Q010). Source: `.cursor/rules/54-…`, `66-…`,
  `133-…` (worker user `www-data` superseded by INFRA-SEC-RUNTIME-1).
- **AIR-FND-20** Never automate destructive queue commands (flush, clear, bulk failed-job deletion) in app code, a
  scheduler or a deploy script. Jobs touching payments, invoices, inventory, lab candidates or notifications are
  idempotent through `IdempotencyService`; a committed payment is never rolled back because a secondary job failed;
  payloads carry no PII or secrets; no job auto-sends WhatsApp or auto-creates a LabOrder from an RME payment. Source:
  `.cursor/rules/54-…`, `55-idempotency-outbox-foundation.mdc`.
- **AIR-FND-21** Store only hashed idempotency keys (`IdempotencyService`); route cross-module side effects through
  `OutboxService` with a schema version and payload classification; keep external dispatch disabled until an approved
  sprint enables it. Gate: `foundation:idempotency-outbox-check --strict`. Source: `.cursor/rules/55-…`.

### Enterprise contracts (ENT-2…ENT-4, ENT-9, ENT-13…ENT-15)

- **AIR-FND-22** Never ship an unbounded list query over a `trx_*` table, an N+1 regression, or a speculative index.
  New indexes are additive, clearly named migrations (prefer `CREATE INDEX CONCURRENTLY`); a new high-traffic query
  declares its filter, sort and pagination pattern. Source: `.cursor/rules/51-database-performance-contract.mdc`.
- **AIR-FND-23** Keep summaries (`rpt_*`) accelerators, never a source of truth for a transactional decision; refresh is
  idempotent with an explicit strategy, branch-isolated, and never corrupts source data. A new summary object ships with
  owner module, refresh command/job, indexes, rebuild procedure, validation query, tests and a safe-disable plan.
  Prefixes: `rpt_*` reports, `sys_*` system metadata, `stg_*` staging. Source: `.cursor/rules/52-…`.
- **AIR-FND-24** Cache with canonical keys `dms:{env}:{domain}:{scope}:{identifier}:{version}` and an explicit TTL from
  the ENT-4 matrix; invalidate server-side on write; never cache KTP/NIK, clinical notes, scans, tokens or credentials;
  never base an RME, payment or lab decision on cached data; never switch a production cache/session/queue driver
  without readiness evidence and rollback. Source: `.cursor/rules/53-redis-cache-enterprise-policy.mdc`.
- **AIR-FND-25** Keep PII field names and scan regexes in `config/security_compliance.php`, keep the masking helpers
  (`PatientDataCompletenessService::maskKtp`, `SensitiveValueMasker::mask`, `developer_console.masking.enabled`)
  present, and gate every export route in its fully resolved middleware stack. A new PII field, export route or audited
  surface extends the config with tests and passes `foundation:security-compliance-check --strict`. Source:
  `.cursor/rules/58-security-pii-compliance-hardening.mdc`.
- **AIR-FND-26** Run load tests and scale projections on non-production only (`loadtest:baseline-run`,
  `loadtest:scale-projection-run` refuse pilot/production by design); label every projected number modeled/estimated,
  never a production benchmark. Source: `.cursor/rules/62-…`, `63-…`.
- **AIR-FND-27** Declare mandatory runbooks in `config/enterprise_documentation.php`; a destructive command may appear
  only inside a forbidden/warning section, and every registered runbook passes the release-evidence forbidden scan.
  Gate: `foundation:enterprise-documentation-check --strict`. Source: `.cursor/rules/64-…`.
- **AIR-FND-28** Keep `rollout:five-branch-readiness` a read-only orchestrator that reuses MON-1
  (`FoundationMonitoringStatusService`), `HealthCheckService` and the domain audit commands; branch counts come from
  `BranchService::listRmeEnabled()`; rollout is staged (1 → 3 → 5 branches) and each stage's status is
  worst(shared base readiness incl. restore drill, that stage's branch gate). Source: `.cursor/rules/69-…`, `70-…`.

### DEVFLOW

- **AIR-FND-29** Treat `.sprint/current.yml` as the sprint source of truth and keep it consistent with the diff:
  `schema_change`, `frontend_change` and `security_impact` contradictions are NO-GO, and the base is never `main` or
  `master`. Edit it minimally and keep its carried-forward blocks (`inherits_closure: LEGACY-RME-PROGRAM-CLOSURE-1` is
  asserted by `LegacyRmeProgramClosureContractTest`); diff it against the base before committing. Source:
  `.cursor/rules/77-…`; archive DEVFLOW-1.
- **AIR-FND-30** Resolve a base only through `App\Support\Devflow\CanonicalBaseRefResolver`: explicit verified exact
  SHA, else the configured remote-tracking ref after a fetch, else fail closed. Never fall back to `refs/heads/<base>`,
  `main`, `master`, `HEAD`, `HEAD~1` or the latest tag. An unresolved base surfaces as UNRESOLVED, never as an empty
  change set (which passes every contradiction check). Source: `.cursor/rules/92-devflow-canonical-base-resolution.mdc`.
- **AIR-FND-31** Read the base branch name only from `config('devflow.manifest.required_base_branch')`; report
  `BASE_SOURCE`, `BASE_SHA`, `HEAD_SHA` with every comparison; in CI classify against
  `github.event.pull_request.base.sha`. Source: `.cursor/rules/92-devflow-canonical-base-resolution.mdc`.
- **AIR-FND-32** Pass refs as validated git argument-array operands, never interpolated into a shell string. `git diff`
  takes a trailing `--`, but `git rev-parse -- <rev>` asks about a path and always fails. Parse porcelain with `-z -uall`
  so modified and new-directory paths keep their exact names. Source: archive DEVFLOW-FIX-BASE-REF-1.
- **AIR-FND-33** Keep DEVFLOW commands fail-closed and non-tagging: `sprint:manifest-check`, `sprint:scope-audit`,
  `devflow:base-ref-check`, `foundation:devflow-check`, `foundation:shared-service-audit`; `sprint:release-check` creates
  no tag and `sprint:test` never hides skips. Under CG-18 use `sprint:test-plan` / `sprint:test --focused`, not
  `--all-required`. Source: `.cursor/rules/77-…`.
- **AIR-FND-34** Reuse the canonical services registered in `config/shared_foundations.php` (`BranchContext`,
  `SensitiveValueMasker`, `AuditLogService` and others); never add a parallel implementation. Source:
  `.cursor/rules/77-…`; archive DEVFLOW-1.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- `next_recommended_sprint` resolves to `MON-1`: MON-1 shipped its readiness intent but was deliberately left `planned`
  to avoid repinning the tests that pin it. Run `foundation:roadmap-check --strict` before relying on this.
- Enterprise Foundation Freeze closed by `enterprise-foundation-go`; POST-ENT hardening is not ENT-17.
- Worker unit runs as `daengtisiams` and consumes `default`, `reports`, `notifications`, `maintenance`,
  `legacy-rme-documents`, `legacy-odontogram-documents`. `satusehat` is allowed and produced but its consumer
  requirement is suspended while `satusehat.enabled` is false.
- Object storage (STORAGE-1), central logging and error tracking (OBS-2), and Redis are readiness-only and OFF.
- GLOBAL TEMPORARY FULL-SUITE POLICY is ACTIVE (CG-18).

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/50-enterprise-architecture-baseline.mdc` | ENT-1 layering, branch, authorization, additive-migration baseline |
| `.cursor/rules/51-database-performance-contract.mdc` | ENT-2 query, index and N+1 contract |
| `.cursor/rules/52-reporting-materialized-summary.mdc` | ENT-3 `rpt_*` summaries, refresh, freshness |
| `.cursor/rules/53-redis-cache-enterprise-policy.mdc` | ENT-4 cache keys, TTL, invalidation, PII |
| `.cursor/rules/54-queue-retry-failed-job-governance.mdc` | ENT-5 retry/backoff/timeout, connections, failed jobs |
| `.cursor/rules/55-idempotency-outbox-foundation.mdc` | ENT-6 idempotency keys and outbox events |
| `.cursor/rules/56-developer-assistance-console.mdc` | ENT-7 read-only audited developer console |
| `.cursor/rules/57-observability-health-check-pack.mdc` | ENT-8 minimal health endpoints |
| `.cursor/rules/58-security-pii-compliance-hardening.mdc` | ENT-9 masking, export gating, PII config |
| `.cursor/rules/59-cicd-enterprise-gate.mdc` | ENT-10 CI gate stack and migration safety |
| `.cursor/rules/60-deployment-rollback-automation.mdc` | ENT-11 deploy/rollback scripts and gates |
| `.cursor/rules/61-backup-disaster-recovery-automation.mdc` | ENT-12 backup, retention, restore rehearsal |
| `.cursor/rules/62-load-test-5-cabang-baseline.mdc` | ENT-13 non-production load-test baseline |
| `.cursor/rules/63-load-test-scale-projection.mdc` | ENT-14 modeled scale projection |
| `.cursor/rules/64-enterprise-documentation-runbook.mdc` | ENT-15 mandatory runbooks and doc scans |
| `.cursor/rules/65-enterprise-foundation-closure-go-no-go.mdc` | ENT-16 closure gate; no ENT-17 |
| `.cursor/rules/66-post-enterprise-foundation-runtime-hardening.mdc` | Queue worker unit, detached deploy runner, ENT-1…4 audit |
| `.cursor/rules/69-roll-5-controlled-rollout-readiness.mdc` | Staged five-branch rollout readiness |
| `.cursor/rules/70-roll-5-1a-restore-drill-evidence.mdc` | Restore-drill evidence gating Stage-1 |
| `.cursor/rules/77-devflow-safe-sprint-acceleration.mdc` | Sprint manifest, profiles, DEVFLOW commands |
| `.cursor/rules/92-devflow-canonical-base-resolution.mdc` | Exact-SHA base resolution, no stale-local fallback |
| `.cursor/rules/92-feature-flag-runtime-override.mdc` | Flag env capture, fail-closed resolution |
| `.cursor/rules/133-dedicated-queue-producer-consumer-contract.mdc` | Produced queue must have a consumer |
| `.cursor/rules/adlms-architect.mdc` | Always-on architecture, branch and inventory basics |
| `.cursor/rules/107-global-temporary-full-suite-policy.mdc` | Full Suite deferred for fix sprints |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/12-foundation-enterprise.md` (STORAGE-1 through
FEATURE-DEV-CONSOLE-OBSERVABILITY-1); pin-family repin rule in `docs/sprints/claude-md-archive/13-cicd-testing.md`.
Grep by sprint id; never read a whole file.
