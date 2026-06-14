# Sprint 25 Phase 25.4 — Owner Dashboard Pilot Review Enhancements

## Goal

Sprint 25 Phase 25.4 reviews the Owner Dashboard after Sprint 24 RC and Sprint 25 pilot stabilization.

This phase prepares enhancement candidates for owner business review without changing dashboard logic yet.

## Baseline

- Previous phase commit: `e04e070`
- Previous phase tag: `sprint-25-phase-25-3-pilot-feedback-triage-quick-fix-batch-1`
- Current branch: `feature/sprint-25-phase-25-4-owner-dashboard-pilot-review-enhancements`

## Current Owner Dashboard Coverage

| Area | Current Coverage | Status |
|---|---|---|
| Branch filter | Owner can filter dashboard by active branch | AVAILABLE |
| RME pilot monitoring | Owner can see RME and lab pilot section | AVAILABLE |
| RME receivable KPI | Total remaining receivable, partial invoice count, unpaid invoice count | AVAILABLE |
| Follow-up KPI | Overdue, today, never followed up, scheduled follow-up | AVAILABLE |
| Permission-aware links | Billing shortcut hidden when user lacks permission | AVAILABLE |
| Branch drilldown | Dashboard supports branch-aware KPI/drilldown | AVAILABLE |

## Pilot Review Questions For Owner

Use these questions during owner review:

| ID | Question | Purpose |
|---|---|---|
| ODR-001 | KPI mana yang paling penting untuk dipantau harian? | Prioritize top dashboard cards |
| ODR-002 | Apakah owner butuh ringkasan piutang per cabang dalam satu tabel? | Validate branch receivable summary |
| ODR-003 | Apakah follow-up piutang perlu indikator warna/status yang lebih jelas? | Validate collection workflow UX |
| ODR-004 | Apakah owner ingin melihat trend harian/mingguan/bulanan? | Validate chart/reporting need |
| ODR-005 | Apakah dashboard perlu tombol export ringkasan owner? | Validate reporting/export need |
| ODR-006 | Apakah data RME → Lab funnel sudah cukup untuk business review? | Validate RME-lab KPI usefulness |

## Candidate Enhancement Backlog

| ID | Enhancement Candidate | Type | Priority | Status | Target |
|---|---|---|---|---|---|
| ODE-001 | Owner Dashboard branch receivable summary table | REPORTING | P2 | PROPOSED | Sprint 25.5 |
| ODE-002 | KPI helper text / tooltip for receivable and follow-up cards | UX | P3 | PROPOSED | Sprint 25.5 |
| ODE-003 | Owner dashboard quick links to filtered Piutang RME views | UX | P2 | PARTIALLY_AVAILABLE | Sprint 25.5 |
| ODE-004 | Owner dashboard export summary | REPORTING | P3 | PROPOSED | Future |
| ODE-005 | RME → Lab funnel clarity polish | UX | P3 | PROPOSED | Future |
| ODE-006 | Monthly business review snapshot | REPORTING | P2 | PROPOSED | Future |

## Triage Result

No owner-approved implementation request is available yet.

Therefore, this phase records enhancement candidates and review questions only.

## Implementation Decision

Decision: GO — review/enhancement backlog only.

No production dashboard logic is changed in this phase.

## Constraints

- No product feature implementation.
- No dashboard service logic change.
- No payment logic change.
- No follow-up logic change.
- No RME receivable query change.
- No WhatsApp sending.
- No scheduler/cron.
- No external service integration.
- No VPS deploy.
- No full test suite.

## Recommended Next Step

Proceed to implementation only after owner confirms at least one candidate enhancement.

Recommended first implementation candidate:

`ODE-001 — Owner Dashboard branch receivable summary table`

Reason:

- Useful for business review.
- Low risk.
- Read-only dashboard enhancement.
- Uses existing RME receivable data.
- Aligns with current branch-aware dashboard direction.
