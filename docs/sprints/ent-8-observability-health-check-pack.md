# ENT-8 — Observability & Health Check Pack

**Branch:** `feature/ent-8-observability-health-check-pack`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Depends on:** ENT-7, OBS-2, LB-1
**Category:** observability
**GO tag:** `ent-8-observability-health-check-pack-go`

## Scope source (canonical)

`config/foundation_roadmap.php` → `approved_sequence` → `ENT-8`:
title *Observability & Health Check Pack*, objective "Ship the enterprise
health-check pack: DB/Redis/queue/storage health, uptime/alerting readiness,
building on OBS-1/OBS-2 and the LB-1 /health/lb endpoint." Allowed scope: health
check endpoints/commands, alerting threshold readiness, queue/db/redis/storage
health. Out of scope: external paging vendor without privacy review, PII/secret
in health output.

## Implementation (runtime)

- **`config/health_check.php`** — feature/endpoint toggles, component map (database critical), alerting thresholds, external-channel flag (OFF), strict flag. Reads `HEALTH_CHECK_*` env with safe defaults.
- **`app/Support/Health/HealthCheckService.php`** — read-only liveness/readiness aggregation + alerting-threshold readiness. Non-sensitive: no host/credentials/PII; probe exceptions swallowed.
- **`app/Http/Controllers/HealthCheckController.php`** — `live()` / `ready()`; minimal JSON, 503 when down.
- **Routes** (`routes/web.php`) — `GET /health/live` (`health.live`), `GET /health/ready` (`health.ready`), config-gated, GET-only, unauthenticated; alongside LB-1 `/health/lb`.
- **`app/Services/Foundation/HealthCheckGovernanceService.php`** — ENT8-HC001..010; verifies ENT-5/ENT-6/ENT-7 GO; live non-sensitivity scan of the payload.
- **`app/Console/Commands/FoundationHealthCheckCommand.php`** — `foundation:health-check` (`--json`, `--strict`/`--fail-on-warning`); FAIL→1, WATCH→1 under strict.
- **Summary wiring** — `health_check_governance` section in `FoundationGovernanceSummaryService` + render line in the summary command (informational; not in blocking `combinedDecision`).

## Governance / rules integration

- Roadmap: ENT-8 → `completed` with `governance_section`/`readiness_command`/`policy_doc`/`go_tag`; `next_recommended_sprint` → **ENT-9**; ENT-7 `deploy_evidence_commit` recorded.
- `rules.health_check_governance_locked` + doc pointer added.
- Release evidence artifact `health-check-check.json` (CI + VPS required), `ReleaseEvidenceService` job map, `release_safety.php` pre-deploy gate, `foundation_governance.php` QUEUE-1 gate, CI workflow + gate script, `scripts/deploy-vps.sh`.
- Freeze-rules Section 8 references this doc; AI mirror `.cursor/rules/57-observability-health-check-pack.mdc`.

## Deploy-order hardening

`scripts/deploy-vps.sh` now clears route/config cache **before** the governance
gates (route-dependent `foundation:health-check` / `foundation:developer-console-check`),
closing the ENT-7 stale-route-cache deploy hiccup.

## Preserved foundations

ENT-5 queue retry, ENT-6 idempotency/outbox, and ENT-7 developer-console
governance are unchanged and re-verified GO by the ENT-8 check. No RME / payment /
inventory / lab / dashboard workflow touched. No migration. No queue worker
enabled. No external alerting channel wired.

## Tests

- `tests/Feature/Architecture/Ent8ObservabilityHealthCheckPackTest.php`
- `tests/Feature/Foundation/HealthCheckGovernanceCommandTest.php`
- `tests/Feature/Foundation/HealthCheckEndpointTest.php`
- Sibling roadmap tests updated from `next = ENT-8` → `ENT-9`.
