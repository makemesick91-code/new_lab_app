# MON-1 — Foundation Monitoring & Observability Gap Consolidation

Foundation-track sprint. **Not ENT-17, not an inventory sprint, not a CI rewrite.**
Consolidates existing monitoring signals into one explainable decision without
duplicating NSF-9/NSF-10/NSF-R011/R012, CICD-CTRL-1, POST-ENT runtime hardening,
the existing health endpoints, deploy/smoke evidence, or the domain audit commands.

- Branch: `feature/mon-1-foundation-monitoring-observability-gap-consolidation`
- Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Baseline: `sprint-68-45-...-go` (base tip `969de79`).
- GO tag: `mon-1-foundation-monitoring-observability-gap-consolidation-go`
- Gap map (pre-code inventory): `docs/sprints/mon-1-existing-monitoring-gap-map.md`
- Runbook: `docs/runbooks/mon-1-foundation-monitoring-observability-runbook.md`

## Scope delivered

- **A — Signal registry** `config/foundation_monitoring.php`: one canonical,
  read-only description of every monitoring signal already present. Invariant
  (test-enforced): no `type: command` signal may have `auto_run: true` — expensive
  commands are CLI-only or read from cached evidence.
- **B — Status service** `App\Services\Foundation\FoundationMonitoringStatusService`:
  read-only aggregation → `GO | WATCH | FAIL | UNKNOWN` + reasons + remediation.
  Reuses `HealthCheckService` (ENT-8) and `SensitiveValueMasker` (ENT-7). Every
  signal is independently guarded — a missing file/table/permission degrades to
  UNKNOWN, never a 500. `decide()` is pure/unit-testable.
- **C — CLI** `foundation:monitoring-observability-check` (`--json`, `--strict`,
  `--fail-on-warning`, `--include-audits`). Default is report-only (exit 0);
  `--strict` exits non-zero only on unsafe FAIL; `--include-audits` invokes the
  existing audit commands and reports their exit status.
- **D — UI** `GET /foundation/monitoring` (`foundation.monitoring.index`), gated
  by `permission:view_developer_console` (Super Admin only). Read-only, sanitized,
  overall decision banner + signal table + runtime card + runbook links. No
  mutation, no heavy audit on page load. Cross-linked from the ENT-7 dev-console.
- **E — Storage/cache health**: writable probe for `storage/framework/cache/data`,
  `storage/logs`, `bootstrap/cache` (temp file create + delete, report-only, never
  chmod). Not-writable → FAIL with a deploy-runner remediation hint.
- **F — Docs/rules**: this doc, the runbook, the gap map, `CLAUDE.md`,
  `.cursor/rules/68-foundation-monitoring-observability.mdc`.
- **G — Tests** (29): `FoundationMonitoringStatusServiceTest`,
  `MonitoringObservabilityCheckCommandTest`, `FoundationMonitoringAccessTest`,
  `FoundationMonitoringSanitizationTest`.

## Guarantees / non-goals

- No new permission, no migration, no seeder/role change, no runtime driver change.
- No secrets / env file / DB password / tokens / raw stack traces / KTP-NIK / raw
  failed-job payloads / full log payloads exposed in UI or JSON.
- No heavy command on a web request; no runtime-state mutation from the UI.
- All existing gates and audit commands remain independent and authoritative.
- CICD-CTRL-1 stays active; MON-1 does not weaken CI, bypass NSF-9/NSF-10, or add
  a competing gate. The classifier chooses gates normally (MON-1 touches
  runtime/route/permission-config/tests, so strong gates apply).

## Durable rules (Scope F)

1. MON-1 consolidates existing signals; it must never duplicate NSF/CICD gates.
2. The monitoring UI is read-only and sanitized.
3. No secrets/raw logs/PII/KTP/NIK/raw failed-job payloads in monitoring UI or JSON.
4. Heavy checks (audits, tests) never run on a web request.
5. Existing audit commands remain source-specific; MON-1 aggregates their status.
6. Storage/cache permission health must be observable before it causes a 500.
7. GO/WATCH/FAIL/UNKNOWN decisions must be explainable (reasons + remediation).

## Roadmap posture (deliberate)

`config/foundation_roadmap.php` is source-locked. Its `next_recommended_sprint`
is derived purely from the first `planned` entry by priority — currently
**`MON-1`** ("Health Monitoring, Alerting & Uptime Readiness", priority 33) — and
staleness is computed from the config status fields, not from git tags. This
sprint delivers the MON-1 readiness intent (monitoring/observability consolidation
+ readiness command + evidence doc) but **deliberately does NOT flip the roadmap
`MON-1` status to `completed`**, so that:

- the config-locked roadmap governance and its ~20 pinned assertions stay green in
  one sprint (no ripple edit of `next_recommended_sprint === 'MON-1'` tests), and
- formal closure/renaming of the roadmap `MON-1` entry (and moving next to
  `PART-1`) is left to an explicit later governance sprint, matching the ROADMAP-1
  disambiguation note that MON-1 "may be consolidated into ENT-8 via a later
  governance sprint."

`foundation:roadmap-check --strict` stays **GO**, `next_recommended_sprint = MON-1`,
not stale.

## Next recommended sprint

Per roadmap: **`MON-1`** remains the resolved next entry (see posture above), with
`PART-1` / `SEARCH-1` / `NDA-1` / `RC-1` queued after. Do not start any next sprint
without explicit approval.
