# ROADMAP-1 — Foundation Roadmap Canonicalization

## Purpose

Keep `config/foundation_roadmap.php` (the source-locked foundation expansion
roadmap) in sync with what has actually been delivered. Before this sprint,
`REPLICA-1` was still marked `planned` even though it had already shipped
(GO tag `replica-1-read-replica-readiness-database-scale-foundation-go`),
which made `next_recommended_sprint` stale (`REPLICA-1`) and the roadmap
entirely missing the later `CACHE-1` Redis-readiness, `OBS-1`, and `OBS-2`
sprints.

This sprint fixes the staleness and adds the missing entries — governance,
config, and docs only. No runtime driver, migration, or business workflow
changed.

## Canonical completed foundation sprints (as of this sprint)

| Priority | Id | Title | Governance section | GO tag |
|---|---|---|---|---|
| 8 | `STORAGE-1` | Object Storage Readiness | `storage_governance` | `storage-1-object-storage-readiness-go` |
| 9 | `STATELESS-1` | Runtime Statelessness & Deploy Portability Foundation | `stateless_governance` | `stateless-1-runtime-statelessness-deploy-portability-foundation-go` |
| 10 | `LB-1` | Load Balancer Pilot | `lb_governance` | `lb-1-load-balancer-pilot-go` |
| 11 | `REPLICA-1` | Read Replica Readiness | `database_replica_governance` | `replica-1-read-replica-readiness-database-scale-foundation-go` |
| 12 | `CACHE-1-REDIS-READINESS` | Redis Shared Cache & Session Readiness | `cache_redis_governance` | `cache-1-redis-shared-cache-session-readiness-go` |
| 13 | `OBS-1` | Request ID, Correlation ID & Observability Foundation | `observability_governance` | `obs-1-request-id-correlation-observability-foundation-go` |
| 14 | `OBS-2` | Centralized Logging & Error Tracking Readiness | `observability_pipeline_governance` | `obs-2-centralized-logging-error-tracking-readiness-go` |
| 15 | `ROADMAP-1-CANONICALIZATION` | Foundation Roadmap Canonicalization (this sprint) | `roadmap_governance` | `roadmap-1-foundation-roadmap-canonicalization-go` |

Next recommended sprint: **`MON-1` — Health Monitoring, Alerting & Uptime
Readiness** (priority 16, `planned`).

Earlier foundation sprints (`NSF-9`, `NSF-10`, `CACHE-1`, `QUEUE-1`,
`DBPERF-1`, `DBPERF-2`, `RPT-1`) remain `completed` and unchanged.

## Naming collisions — read this before touching CACHE-1 or ROADMAP-1

Two id collisions exist by design and are intentionally kept, not merged:

- **`CACHE-1`** (priority 3, `Cache Strategy, Redis Readiness & Invalidation
  Governance`) is the earlier **design-only** cache/invalidation-governance
  sprint (`cache_governance`). **`CACHE-1-REDIS-READINESS`** (priority 12) is
  the later **Redis shared cache/session runtime-readiness** sprint
  (`cache_redis_governance`). Each entry carries a `disambiguation_note` /
  `disambiguates` field pointing at the other.
- The config's own top-level `roadmap_id` is `'ROADMAP-1'` — that identifies
  *this file's original source-lock sprint*
  (tag `roadmap-1-national-foundation-expansion-source-lock-go`). The new
  **`ROADMAP-1-CANONICALIZATION`** entry (priority 15, this sprint, tag
  `roadmap-1-foundation-roadmap-canonicalization-go`) is a distinct, later
  sprint that re-synced this same file. Do not rename either id to make them
  match — that would erase the disambiguation.

## Commands

```bash
php artisan foundation:roadmap-check                 # console summary, exit 0 on GO
php artisan foundation:roadmap-check --json           # machine-readable report
php artisan foundation:roadmap-check --strict         # non-zero on stale next / missing metadata / non-GO
php artisan foundation:roadmap-check --fail-on-warning
```

`foundation:roadmap-check` is a thin, read-only wrapper around the existing
`architecture:foundation-roadmap-check` (backed by
`App\Services\Architecture\FoundationRoadmapService`, unchanged) that adds
canonicalization-specific output: `completed_sprints`, `current_sprint`,
`stale_next_detected`, `missing_metadata`, and the expected/known governance
section lists. It never mutates files or data, never calls the network, and
never needs a GitHub token.

## Updating the roadmap after a new foundation sprint

1. Add (or fix the status of) the sprint's entry in
   `config/foundation_roadmap.php`'s `approved_sequence`, in priority order.
   If the id collides with an earlier sprint's short name, add
   `disambiguates` / `disambiguation_note` fields — do not reuse the id.
2. If the sprint is `completed`, set `governance_section`, `readiness_command`,
   `go_tag`, and (if known) `go_commit` so `ROADMAP-R003` can verify evidence.
3. If the sprint adds new governance rules, publish them from its own
   `*GovernanceService::rules()` and wire the section into
   `FoundationGovernanceSummaryService` (see `roadmap_governance` for the
   pattern) — per `ROADMAP-R005`.
4. Update the small number of existing tests that hardcode
   `next_recommended_sprint` (search `next_recommended_sprint.*toBe` /
   `toBeIn` under `tests/Feature/Architecture`) — this is expected each time
   the roadmap advances, exactly as STORAGE-1/STATELESS-1/LB-1 each did.
5. Update this doc's completed-sprint table and next-recommended-sprint value.
6. Record deploy evidence (GO tag, commit, DB backup, smoke result, rollback
   note) per `ROADMAP-R009`.

## Deploy notes

Governance/config/docs/test only — no runtime driver, migration, or business
workflow changed by this sprint. VPS deploy follows the standard foundation
sprint runbook (backup DB, `git pull` at the GO tag, `composer install`,
`npm ci && npm run build`, `migrate --force` — none expected here since no
migration was added, cache rebuild, `php8.3-fpm`/`nginx` restart, then run the
smoke command list in the PR).

## Rollback notes

This sprint is config/code/docs only, additive, and reversible: `git checkout`
the previous GO tag (`obs-2-centralized-logging-error-tracking-readiness-go`)
and redeploy. No migration to roll back. No runtime driver was flipped, so
rollback carries no data-loss risk.

## Privacy / security note

The roadmap config and its governance output never contain secrets or PII —
only sprint ids, titles, statuses, git tags/commit hashes, and command names.
