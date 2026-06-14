# Sprint 26 Stabilization Backlog

## Purpose

This backlog tracks follow-up work from the Sprint 25.9 `WATCH` decision. It is the
documentation/planning source of truth for Sprint 26 stabilization tracks. The pilot remains at
status `WATCH`; nothing in this backlog has been implemented as code.

## Backlog Overview

| ID | Priority | Item | Track | Status | Notes |
|---|---|---|---|---|---|
| S26-BL-001 | P1 | Receivable validation checklist | Receivable Validation | Proposed | Needed before GO |
| S26-BL-002 | P1 | Branch receivable sample audit | Branch Summary | Proposed | Confirm branch scoping |
| S26-BL-003 | P1 | Backup restore rehearsal plan | Backup Restore | Proposed | Non-production only |
| S26-BL-004 | P1 | Owner KPI confirmation checklist | Owner KPI | Proposed | Confirm dashboard expectations (S25-FB-005) |
| S26-BL-005 | P2 | RME follow-up monitoring notes | RME Follow-Up | Proposed | Track pilot consistency |
| S26-BL-006 | P2 | Monitoring/log review template | Monitoring | Proposed | Daily review evidence |
| S26-BL-007 | P2 | SOP adoption review checklist | SOP Adoption | Proposed | Confirm daily usage |
| S26-BL-008 | P3 | Sprint 26 stabilization closure report | Closure | Proposed | Final GO/WATCH/NO-GO |

## Priority Definition

| Priority | Meaning |
|---|---|
| P1 | Required before full GO |
| P2 | Important stabilization support |
| P3 | Closure / reporting / later hardening |

## Detailed Backlog

### S26-BL-001 — Receivable Validation Checklist

- Priority: P1
- Track: Receivable Validation
- Goal: Validate receivable summary against sample manual data.
- Output: Checklist/report.
- Suggested phase: Sprint 26.2
- Risk reduced: Receivable accuracy risk (summary vs source records).

### S26-BL-002 — Branch Receivable Sample Audit

- Priority: P1
- Track: Branch Summary
- Goal: Confirm branch-level receivable summary and branch scoping (per S25-FB-006).
- Output: Sample audit note.
- Suggested phase: Sprint 26.2 or 26.5
- Risk reduced: Branch summary interpretation/scoping risk.

### S26-BL-003 — Backup Restore Rehearsal Plan

- Priority: P1
- Track: Backup Restore
- Goal: Prepare safe restore rehearsal outside production.
- Output: Restore rehearsal runbook.
- Suggested phase: Sprint 26.3
- Risk reduced: Backup readiness uncertainty (restore not yet exercised end-to-end).

### S26-BL-004 — Owner KPI Confirmation Checklist

- Priority: P1
- Track: Owner KPI
- Goal: Confirm Owner Dashboard metrics match business review needs (S25-FB-005).
- Output: KPI confirmation checklist.
- Suggested phase: Sprint 26.4
- Risk reduced: Dashboard usefulness risk.

### S26-BL-005 — RME Follow-Up Monitoring Notes

- Priority: P2
- Track: RME Follow-Up
- Goal: Monitor RME follow-up usage during pilot.
- Output: Monitoring notes.
- Suggested phase: Sprint 26.6
- Risk reduced: Follow-up consistency risk.

### S26-BL-006 — Monitoring/Log Review Template

- Priority: P2
- Track: Monitoring
- Goal: Standardize daily monitoring notes.
- Output: Review template.
- Suggested phase: Sprint 26.2 / 26.6
- Risk reduced: Operational visibility risk.

### S26-BL-007 — SOP Adoption Review Checklist

- Priority: P2
- Track: SOP Adoption
- Goal: Confirm staff use checklist and support runbook.
- Output: SOP adoption checklist.
- Suggested phase: Sprint 26.7
- Risk reduced: User adoption risk.

### S26-BL-008 — Sprint 26 Stabilization Closure Report

- Priority: P3
- Track: Closure
- Goal: Decide whether pilot remains WATCH or can move to GO.
- Output: GO/WATCH/NO-GO report.
- Suggested phase: Sprint 26.8
- Risk reduced: Decision uncertainty.

## Carry-Over From Sprint 25 Backlog

These items were already flagged in the Sprint 25.9 continued backlog and remain open:

| Source | Item | Mapped Backlog ID |
|---|---|---|
| S25-FB-005 | Confirm owner dashboard KPIs and implement approved KPIs | S26-BL-004 |
| Sprint 25.9 P1 | Rehearse DB backup restore end-to-end in a safe environment | S26-BL-003 |
| ODE-002 | KPI helper text / tooltip for receivable & follow-up cards | Future (post-stabilization implementation phase) |
| ODE-006 | Monthly business review snapshot | Future (Sprint 26/27) |
| ODE-004 | Owner dashboard export summary | Later |
| ODE-005 | RME → Lab funnel clarity polish | Later |

## Notes

This backlog is docs/planning focused. Any production code fix must be scoped into a separate
implementation phase with its own branch, validation, and rollback notes. Backup restore work
is **non-production only** — never `migrate:fresh` or `db:wipe` on the VPS.
