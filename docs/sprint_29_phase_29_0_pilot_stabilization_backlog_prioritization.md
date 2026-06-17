# Sprint 29 Phase 29.0 — Pilot Stabilization Backlog Prioritization

## 1. Status

- **Mode:** pilot stabilization backlog prioritization only
- **Deployment:** no deployment
- **Migration:** no migration
- **Production code change:** no production code change
- **Bug fix execution:** no bug fix implemented
- **Runtime behavior change:** no runtime behavior change
- **Integration change:** no integration implemented
- **Backup/restore execution:** no real backup or restore executed
- **Destructive data operation:** no destructive data operation
- **Baseline:** Sprint 28 closure GO at `b55d485`

## 2. Purpose

- Start Sprint 29 from the completed Sprint 28 closure baseline.
- Convert Sprint 28 pilot readiness, runbook, monitoring, and issue triage outputs into prioritized stabilization lanes.
- Decide which pilot issues should become implementation phases later.
- Separate blocker/high-risk stabilization from low-risk polish.
- Protect RME Control Workflow and cashier/payment/receivable correctness.
- Avoid implementation in this phase.
- Produce a clear, reviewable backlog for Sprint 29 follow-up work.

## 3. Non-goals

- No production code change.
- No bug fix implementation.
- No stabilization implementation.
- No migration/schema change.
- No deployment.
- No database mutation.
- No destructive data operation.
- No route/controller/service/model/view change.
- No WhatsApp/API integration.
- No monitoring/backup/restore implementation.
- No real backup execution.
- No real restore execution.
- No business rule change.

## 4. Inputs from Sprint 28

| Phase | Input document | How it feeds Sprint 29.0 | Risk level |
| --- | --- | --- | --- |
| Sprint 28.0 | `docs/sprint_28_phase_28_0_post_sprint_27_baseline_pilot_readiness_backlog_planning.md` | Baseline/backlog planning establishes the candidate stabilization items to prioritize. | Medium |
| Sprint 28.1 | `docs/sprint_28_phase_28_1_pilot_readiness_operator_smoke_checklist.md` | Pilot readiness/operator smoke checklist surfaces operator-facing defects and reproduction steps. | High |
| Sprint 28.2 | `docs/sprint_28_phase_28_2_pilot_daily_operation_runbook.md` | Pilot daily operation runbook informs operational friction lanes and manual SOP candidates. | Medium |
| Sprint 28.3 | `docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md` | WhatsApp reminder & receivable follow-up planning feeds the manual SOP lane. | Medium |
| Sprint 28.4 | `docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md` | Monitoring, backup & restore rehearsal planning feeds the readiness lane. | High |
| Sprint 28.5 | `docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md` | Pilot issue triage & stabilization backlog is the primary source of backlog items to prioritize. | High |
| Sprint 28.6 | `docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md` | Sprint 28 closure GO/NO-GO report confirms the baseline and protected guardrails. | High |

## 5. Prioritization principles

- Safety first.
- RME data integrity first.
- Payment/receivable correctness first.
- Patient privacy first.
- Reproducible issues before vague reports.
- Blockers before enhancements.
- High-risk regression before UI polish.
- Manual SOP before automation when risk is unclear.
- Planning before implementation.
- One future implementation phase per clearly scoped risk lane.

## 6. Stabilization priority levels

| Level | Meaning |
| --- | --- |
| **P0 BLOCKER** | Pilot cannot safely continue; data/payment/RME overwrite or integrity risk. |
| **P1 HIGH** | Major pilot workflow risk with workaround but high operational impact. |
| **P2 MEDIUM** | Repeated friction, reporting mismatch, print/layout issue, or operator confusion. |
| **P3 LOW** | Minor copy, documentation, training, or polish issue. |
| **P4 ENHANCEMENT** | Improvement request, no current defect. |
| **NEEDS CONFIRMATION** | Not enough evidence/reproduction steps. |

## 7. Scoring model

Each candidate is scored across the following dimensions to derive a priority level:

| Scoring dimension | Description |
| --- | --- |
| Safety impact | Risk to patient safety or clinical correctness. |
| Financial/payment impact | Risk to payment, receivable, or cashier correctness. |
| RME/clinical record impact | Risk to RME, odontogram, or clinical record integrity. |
| Frequency | How often the issue occurs in the pilot. |
| Reproducibility | Whether the issue has clear reproduction steps. |
| Workaround availability | Whether a safe manual workaround exists. |
| User role affected | Which operator role (doctor, cashier, admin, owner, perawat) is affected. |
| Implementation risk | Risk introduced by implementing the fix. |
| Test coverage readiness | Whether test coverage exists or can be added before implementation. |

**Rule:** P0/P1 items must not move to implementation unless evidence, reproduction steps, expected/actual behavior, and rollback/safety notes exist.

**Rule:** Items without reproduction steps stay as NEEDS CONFIRMATION.

## 8. Stabilization lanes

- **Lane A:** RME Control Workflow safety.
- **Lane B:** cashier/payment/receivable correctness.
- **Lane C:** patient registration/search/RM identity.
- **Lane D:** odontogram/RME print and browser print layout.
- **Lane E:** report export/print.
- **Lane F:** operator access/menu/role visibility.
- **Lane G:** WhatsApp reminder and receivable follow-up manual SOP.
- **Lane H:** monitoring/log/backup/restore readiness.
- **Lane I:** UX copy/help text/training notes.
- **Lane J:** technical debt / test hardening.

## 9. RME Control Workflow prioritization guardrails

- Same patient/RM must be preserved.
- Control visit must create a new visit.
- Old RME/odontogram/invoice must not be overwritten.
- Parent receivable can remain visible/payable in cashier control.
- Payment allocation must remain FIFO previous receivable first.
- Parent receivable must not block control completion.
- Rp0 invoice must not appear in active receivables.
- Any violation is P0 BLOCKER until proven otherwise.

## 10. Cashier / Payment / Receivable prioritization guardrails

- Active receivable follow-up is only for remaining balance > 0.
- Rp0 invoice must not be followed up as active receivable.
- Payment receipt allocation must remain traceable.
- Split allocation must remain auditable when parent/current invoice both exist.
- Duplicate payment entry risk is P0/P1 depending impact.
- Disputed balance must be escalated, not silently fixed.
- Any payment/receivable mismatch must include invoice identity and evidence before implementation.

## 11. Prioritized backlog template

> **Note:** The rows below are **placeholders / examples only** with placeholder IDs. They are **not confirmed defects** and must not be treated as approved implementation work. Real backlog items are populated from Sprint 28.5 triage evidence during Sprint 29 follow-up phases.

| Priority | Backlog ID | Lane | Title | Evidence source | Reproducible? | Safety/financial/RME impact | Proposed next phase | Owner | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| P0 BLOCKER | P0-RME-001 | Lane A | _(placeholder example)_ RME control overwrite risk | Sprint 28.5 triage | No (needs repro) | RME integrity | Sprint 29 Phase 29.1 | TBD | NEEDS CONFIRMATION |
| P1 HIGH | P1-CASHIER-001 | Lane B | _(placeholder example)_ Receivable follow-up mismatch | Sprint 28.5 triage | No (needs repro) | Financial/payment | Sprint 29 Phase 29.2 | TBD | NEEDS CONFIRMATION |
| P2 MEDIUM | P2-PRINT-001 | Lane D | _(placeholder example)_ Odontogram print layout issue | Sprint 28.1 checklist | No (needs repro) | None (cosmetic) | Sprint 29 Phase 29.5 | TBD | NEEDS CONFIRMATION |
| P2 MEDIUM | P2-REPORT-001 | Lane E | _(placeholder example)_ Report export mismatch | Sprint 28.2 runbook | No (needs repro) | Reporting only | Sprint 29 Phase 29.5 | TBD | NEEDS CONFIRMATION |
| P3 LOW | P3-TRAINING-001 | Lane I | _(placeholder example)_ Training note/help text gap | Sprint 28.2 runbook | N/A | None | Sprint 29 Phase 29.5 | TBD | NEEDS CONFIRMATION |
| NEEDS CONFIRMATION | NEEDS-CONFIRMATION-001 | TBD | _(placeholder example)_ Unverified pilot report | Pilot operator report | No | Unknown | TBD | TBD | NEEDS CONFIRMATION |

## 12. Future Sprint 29 candidate phases

- **Sprint 29 Phase 29.1 — P0/P1 RME Control Workflow Regression Stabilization Planning**
- **Sprint 29 Phase 29.2 — Cashier Payment Receivable High-Risk Stabilization Planning**
- **Sprint 29 Phase 29.3 — WhatsApp Reminder Manual Pilot SOP**
- **Sprint 29 Phase 29.4 — Monitoring Backup Restore Rehearsal on Non-Production Target**
- **Sprint 29 Phase 29.5 — Pilot Report/Print/UX Polish Backlog Planning**
- **Sprint 29 Phase 29.6 — Sprint 29 Stabilization Execution Readiness GO/NO-GO**

## 13. GO/NO-GO decision for Sprint 29.0

**GO if:**

- Prioritization document is complete.
- Sprint history is updated.
- Focused test passes.
- No production code changed.
- No migration.
- No deployment.
- No destructive operation.
- No bug fix/stabilization implementation.
- No runtime behavior change.
- No RME/payment/receivable/cashier business rule change.
- Next implementation/planning lanes are clearly separated.

**NO-GO if:**

- Any production code is changed.
- Any migration/deploy/destructive command is introduced.
- Any fix/stabilization is implemented.
- Any runtime behavior changes.
- Any RME/payment/receivable/cashier rule changes.
- Prioritization criteria are missing.
- Sprint history/test is missing.
- P0/P1 items do not require evidence/reproduction/safety notes.

## 14. Safety confirmation

- No production code change.
- No migration.
- No deployment.
- No destructive operation.
- No bug fix implementation.
- No stabilization implementation.
- No runtime behavior change.
- No WhatsApp/API integration.
- No real backup execution.
- No real restore execution.
- No route/controller/service/model/view/config/seeder change.
- No RME/payment/receivable/cashier business rule change.

## 15. Final decision

Sprint 29 Phase 29.0 posture: GO CANDIDATE FOR PR REVIEW

**GO CANDIDATE FOR PR REVIEW**

## 16. Validation plan

- `php artisan test --filter=Sprint29Phase290PilotStabilizationBacklogPrioritization`
- `vendor/bin/pint --test tests/Feature/Sprint29/Sprint29Phase290PilotStabilizationBacklogPrioritizationTest.php`
- `git diff --check`
