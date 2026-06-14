# Sprint 25 Phase 25.8 — Pilot Daily Operations Checklist + Support Runbook

## Goal

Provide operational documentation for the DaengtisiaMS VPS pilot: a daily
operations checklist for routine morning/closing verification, and a
first-level support runbook for triage, service handling, backups, and
rollback.

This phase does not add application features and does not change production
logic.

## Scope

Documentation / operations only.

## Files Added

| File | Purpose |
|---|---|
| `docs/pilot_daily_operations_checklist.md` | Daily morning/closing checks, VPS quick commands, daily report template, GO/WATCH/NO-GO status meanings |
| `docs/pilot_support_runbook.md` | Environment, severity levels, first response, restart/log/backup/rollback SOPs, what-not-to-do, escalation template |
| `docs/sprint_25_phase_25_8_pilot_daily_operations_checklist_support_runbook.md` | This completion doc |
| `docs/graphify_sprint_25_8_update.md` | Graphify refresh record for Sprint 25.8 |

## Baseline

| Item | Value |
|---|---|
| Previous phase commit | `220c5d8` |
| Previous phase tag | `sprint-25-phase-25-7-pilot-monitoring-backup-readiness-baseline` |
| Current branch | `feature/sprint-25-phase-25-8-pilot-daily-operations-checklist-support-runbook` |

## Operational Coverage

- Daily morning checklist: dashboard, login, Owner Dashboard KPI, RME, Kasir/Piutang RME, branch filter, inventory/lab access, storage/file access, Laravel log scan, service health.
- Daily closing checklist: data input saved, payment/RME safe, follow-up mutation safe, backup checkpoint, final error scan, owner/user feedback captured.
- VPS read-only quick commands for daily inspection.
- Daily report template + GO / WATCH / NO-GO decision semantics.
- Support runbook: environment table, S1–S4 severity, first response checklist, restart service SOP, Laravel log handling SOP, manual DB + runtime backup SOP, rollback SOP, what-not-to-do during pilot, escalation template.

## Decision

Decision: GO.

DaengtisiaMS pilot now has standardized daily operations and first-level support
documentation.

## Constraints

- No production code changed.
- No migration added.
- No migration run.
- No VPS deploy.
- No payment logic changed.
- No follow-up mutation logic changed.
- No scheduler/cron added.
- No WhatsApp/external integration added.
- No full test suite run.
