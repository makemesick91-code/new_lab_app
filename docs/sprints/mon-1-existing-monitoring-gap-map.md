# MON-1 — Existing Monitoring & Observability Gap Map

Pre-implementation inventory for `MON-1 — Foundation Monitoring & Observability Gap Consolidation`.
Purpose: prove what already exists so MON-1 **consolidates and surfaces** existing signals
instead of duplicating NSF/CICD gates, deploy evidence, smoke gates, or domain audits.

Baseline: `sprint-68-45-...-go` (remote base tip `969de79`, VPS HEAD `969de79`).

---

## 1. Existing health endpoints (DO NOT rebuild)

| Endpoint | Route name | Controller | Source |
| --- | --- | --- | --- |
| `GET /health/live` | `health.live` | `HealthCheckController@live` | ENT-8 |
| `GET /health/ready` | `health.ready` | `HealthCheckController@ready` (503 on down) | ENT-8 |
| `GET /health/lb` | `health.lb` | `LoadBalancerHealthController` | LB-1 |

Backed by `App\Support\Health\HealthCheckService`:
- `liveness()` → `{status, service, check:live}`.
- `readiness()` → `{status, service, check:ready, components{database,cache,queue,storage,object_storage}}`.
- Status enum: `ok | degraded | down`. Component config in `config/health_check.php`.

**MON-1 reuses `HealthCheckService` in-process — it never re-implements probes or adds another health endpoint.**

## 2. Existing deploy / smoke / evidence gates (DO NOT rebuild)

- **NSF-9 Release Safety & Automated Smoke Gate** — `config/release_safety.php`, deploy pre-gate + automated 7/7 smoke.
- **NSF-10 Release Evidence** — `App\Services\Foundation\ReleaseEvidenceService`, `config/release_evidence.php`, `release:evidence-capture` / `release:evidence-check`. Evidence artifacts land in `storage/release-evidence/latest` (vps profile), `storage/ci-evidence` (ci), `storage/release-evidence/local`.
- **ENT-11 Deployment & Rollback Automation** — `scripts/deploy-vps.sh`, `scripts/deploy-vps-runner.sh` (SSH-safe detached), `scripts/rollback-vps.sh`, `config/deployment_rollback.php`, `foundation:deployment-rollback-check`.
- **ENT-12 Backup & DR** — `scripts/backup-vps.sh`, `config/backup_dr.php` (`required_backup_directory = storage/app/backups/deploy`, retention 14d/min 3), `foundation:backup-dr-check`.
- **ENT-8 Health Pack** — `foundation:health-check`, `config/health_check.php`.

**MON-1 reads the outputs of these (backup file listing, deploy-log presence, governance-summary decision) but does not re-run capture/smoke and does not add another evidence pipeline.**

## 3. Existing CI / gate commands (DO NOT rebuild)

- `foundation:ci-runtime-control-check` (CICD-CTRL-1) — classifier + gate posture.
- `foundation:security-compliance-check` (ENT-9).
- `foundation:cicd-enterprise-gate-check` (ENT-10).
- `foundation:enterprise-documentation-check` (ENT-15).
- `foundation:enterprise-closure-check` (ENT-16).
- `foundation:health-check`, `foundation:backup-dr-check`, `foundation:deployment-rollback-check`.
- `architecture:foundation-governance-summary`, `architecture:foundation-roadmap-check`, `architecture:ui-governance-check`.
- **NSF-R011 Critical Test Gate / NSF-R012 Quality Gate** run in CI via `.github/workflows/foundation-evidence-gates.yml` + `scripts/ci/foundation-evidence-gates.sh`.

**MON-1 does not re-classify CI, does not weaken gates, does not add a competing enterprise gate.** It records these commands in a registry and surfaces their *last cached evidence decision* — the commands stay authoritative and independent.

## 4. Existing domain audit commands (DO NOT rebuild)

- `inventory:procurement-workflow-audit --strict` (Sprint 68.45) — FAIL only on Kepala-Cabang PO-permission leak; WARN is VPS-safe.
- `rme:doctor-performance-access-audit --strict` (hotfix FIX-PRE-68-45) — unlinked-doctor / permission-leak audit.
- `data-quality:dq1-audit`, `inventory:batch-governance-audit`, `inventory:source-document-batch-audit`.

**MON-1 keeps these source-specific. `--include-audits` may *invoke* them (CLI-only) and report their exit status, but MON-1 never re-implements their logic and never runs them on a web request.**

## 5. Existing dashboard / dev-console / ops pages (DO NOT rebuild)

- **ENT-7 Developer Assistance Console** — `GET /dev-console` (`developer-console.index`), `permission:view_developer_console` (Super Admin via `Gate::before`), `DeveloperConsoleController` → `DeveloperConsoleService`. Already surfaces: `runtime_health`, `storage_health`, `disk_backup`, `failed_jobs`, `audit_events`, `slow_queries`, `deploy_evidence`, `application_log`. All free text passes `SensitiveValueMasker::mask()`.
- Owner KPI dashboard (`dashboard`), inventory dashboards, etc. — business, not foundation monitoring.

**MON-1 does NOT duplicate the dev-console.** The dev-console shows raw per-section detail; the genuine gap is a *single consolidated GO/WATCH/FAIL/UNKNOWN decision* across all signals plus a canonical registry. MON-1 reuses `view_developer_console` (Super Admin only) — **no new permission** — and reuses `SensitiveValueMasker` + `HealthCheckService`.

## 6. Already solved — must NOT be duplicated

1. Health probing → `HealthCheckService` / health endpoints.
2. Smoke + release safety → NSF-9.
3. Release evidence capture/verify → NSF-10.
4. CI classification / gates → CICD-CTRL-1 + NSF-R011/R012.
5. Deploy / rollback automation → ENT-11.
6. Backup / DR → ENT-12.
7. Per-section raw runtime/storage/log/failed-job inspection → ENT-7 dev-console.
8. Domain audits → `inventory:procurement-workflow-audit`, `rme:doctor-performance-access-audit`.

## 7. Actual gaps MON-1 will fill

| Gap | MON-1 deliverable |
| --- | --- |
| No single canonical description of *where each monitoring signal lives* | **Scope A** — `config/foundation_monitoring.php` signal registry (read-only, `auto_run=false` for every expensive command). |
| No single consolidated observability **decision** (GO/WATCH/FAIL/UNKNOWN) across health + queue + deploy evidence + storage/cache + audit metadata | **Scope B** — `FoundationMonitoringStatusService` (read-only, lean, graceful on missing files/tables, reuses `HealthCheckService` + `SensitiveValueMasker`). |
| No one command to consolidate all signals into an explainable decision | **Scope C** — `foundation:monitoring-observability-check` (`--json`, `--strict`, `--include-audits`). |
| No consolidated read-only monitoring **surface** with an overall decision banner | **Scope D** — `GET /foundation/monitoring` (`view_developer_console`, Super Admin only). |
| Storage/cache write-permission problems only surface *after* a Laravel 500 | **Scope E** — explicit writable probe for `storage/framework/cache/data`, `storage/logs`, `bootstrap/cache` (temp file create+delete, report-only, never chmod). |
| Runtime commit/tag not exposed for ops correlation | Safe guarded `git rev-parse` / `git describe` (function_exists + try/catch, null on failure). |

### Non-goals (explicit)
- Not another CI system, deploy-evidence system, smoke gate, or domain-audit system.
- No mutation of runtime state from the UI (read-only; no log clear, no queue retry/delete).
- No secrets / env file / DB password / tokens / raw stack traces / KTP-NIK / raw failed-job payloads / full log payloads exposed anywhere.
- No heavy command executed on a web request (audit execution is CLI-only via `--include-audits`; the web page reads cached evidence metadata).
- No new permission, no migration, no seeder/role change.
