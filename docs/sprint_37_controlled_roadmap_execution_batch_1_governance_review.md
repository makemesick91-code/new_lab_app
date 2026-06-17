# Sprint 37 — Controlled Roadmap Execution Batch 1 & Governance Review

Status: Draft / Local Validation Pending
Baseline: Sprint 36 GO at 7a1f959
Scope: Docs / controlled roadmap execution Batch 1 / governance review checklist-test only

## Purpose

Sprint 37 follows Sprint 36 operational governance, maintenance cadence, and expansion
readiness. It prepares the **first controlled roadmap execution batch** after the Sprint 35–36
roadmap lock.

This sprint:

- Selects and classifies candidate roadmap items for future implementation.
- Defines acceptance criteria, risk gates, test scope, ownership, and governance review before any
  future production-facing change.
- Locks a small, approved Batch 1 scope to hand to a later supervised implementation sprint.

This sprint **does not** implement features, bugfixes, enhancements, migrations, runtime changes,
deployment, production/VPS action, maintenance, branch expansion, backup, restore, rollback,
monitoring automation, or external integrations. It produces docs / checklist-test governance
artifacts only.

## Baseline references

```text
Sprint 35 GO: sprint-35-production-operations-baseline-continuous-improvement-roadmap-lock-go at c4293ec
Sprint 36 GO: sprint-36-operational-governance-maintenance-cadence-expansion-readiness-go at 7a1f959
```

Sprint 36 feature reference:

```text
Sprint 36 feature commit: 01bac93
```

Stable base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`,
HEAD `7a1f959`.

## Controlled roadmap execution scope

This sprint covers governance/planning only:

- controlled roadmap planning only
- Batch 1 candidate selection only
- implementation readiness only
- governance review only
- future implementation scope definition only
- no actual feature implementation in this sprint
- no production code changes
- no bugfix/enhancement implementation
- no route/controller/service/model/view/config/seeder change
- no migration
- no deployment
- no VPS/production access
- no production data mutation
- no runtime behavior change
- no automation/scheduler/queue change
- no monitoring automation implementation
- no real maintenance execution
- no branch expansion execution
- manual owner/admin confirmed checklist only
- future implementation must happen later in a separate supervised sprint if approved

## Batch 1 roadmap candidate review

Each candidate is a planning placeholder only. No item below is implemented in Sprint 37.

For every candidate the review records: **candidate item placeholder**, **source reference**,
**business impact**, **operational risk**, **technical risk**, **implementation complexity**,
**owner**, **target sprint**, and **decision: include / watch / defer / reject**.

### RME workflow improvement

- Candidate item placeholder: `<RME-WF-001 placeholder>`
- Source reference: Sprint 20–21 RME modules; `docs/sprint_20_final_closure_report.md`
- Business impact: high operator/clinical value
- Operational risk: medium
- Technical risk: medium
- Implementation complexity: medium
- Owner: Product owner + RME lead
- Target sprint: Sprint 38
- Decision: include

### Cashier/payment/receivable improvement

- Candidate item placeholder: `<CASH-001 placeholder>`
- Source reference: RmeInvoice / RmePaymentService; receivable/piutang notes
- Business impact: medium-high (billing accuracy)
- Operational risk: medium
- Technical risk: medium
- Implementation complexity: medium
- Owner: Cashier/finance owner
- Target sprint: Sprint 39 (discovery in Sprint 38)
- Decision: watch

### Reporting/export improvement

- Candidate item placeholder: `<RPT-001 placeholder>`
- Source reference: RME PDF/print hardening (Phase 21.6); reporting/export references
- Business impact: medium
- Operational risk: low
- Technical risk: low-medium
- Implementation complexity: medium
- Owner: Reporting owner
- Target sprint: Sprint 39 (discovery in Sprint 38)
- Decision: watch

### WhatsApp manual reminder operationalization

- Candidate item placeholder: `<WA-001 placeholder>`
- Source reference: manual reminder governance notes
- Business impact: medium
- Operational risk: high (external channel)
- Technical risk: medium
- Implementation complexity: medium-high
- Owner: Operations owner
- Target sprint: deferred
- Decision: defer

### Monitoring/backup/recovery governance hardening

- Candidate item placeholder: `<MON-001 placeholder>`
- Source reference: Sprint 31/36 monitoring/backup/recovery governance docs
- Business impact: medium
- Operational risk: high (recovery-critical)
- Technical risk: medium
- Implementation complexity: medium
- Owner: Operations/monitoring owner
- Target sprint: deferred (governance only, no automation)
- Decision: watch

### Branch expansion readiness

- Candidate item placeholder: `<EXP-001 placeholder>`
- Source reference: Sprint 36 expansion readiness framework
- Business impact: medium
- Operational risk: high
- Technical risk: medium
- Implementation complexity: high
- Owner: Branch expansion owner
- Target sprint: deferred
- Decision: defer

### UX/UI polish and operator feedback

- Candidate item placeholder: `<UX-001 placeholder>`
- Source reference: TailAdmin UI components; operator feedback
- Business impact: low-medium
- Operational risk: low
- Technical risk: low
- Implementation complexity: low-medium
- Owner: Product owner
- Target sprint: Sprint 39+
- Decision: watch

### Training/documentation gap closure

- Candidate item placeholder: `<DOC-001 placeholder>`
- Source reference: Sprint 32 training/handover docs
- Business impact: medium
- Operational risk: low
- Technical risk: low
- Implementation complexity: low
- Owner: Documentation owner
- Target sprint: Sprint 38 (supporting)
- Decision: include

### Technical debt cleanup

- Candidate item placeholder: `<DEBT-001 placeholder>`
- Source reference: code review backlog
- Business impact: low-medium
- Operational risk: low
- Technical risk: medium
- Implementation complexity: medium
- Owner: Engineering owner
- Target sprint: Sprint 39+
- Decision: watch

## Batch 1 selection criteria

A roadmap item is eligible for Batch 1 only if it:

- must be approved by owner/admin
- must have clear business value
- must have manageable operational risk
- must have clear acceptance criteria
- must have targeted test scope
- must not require emergency production change
- must not require unapproved data migration
- must not require unapproved external integration
- must not bypass Sprint 36 governance
- must be reversible or have escalation/rollback reference
- must have documentation update requirement
- must have support/training note when needed

## Recommended Batch 1 scope

```text
Primary Batch 1 Candidate:
RME Workflow Improvement Batch 1

Supporting candidates:
- Cashier/payment/receivable improvement discovery
- Reporting/export improvement discovery
- Training/documentation gap closure
```

**Why RME Workflow Improvement Batch 1 is suitable:**

- high operator value
- directly related to clinical workflow
- can be scoped with targeted tests
- can be reviewed against existing RME modules
- can be implemented later under governance
- does not need to be implemented in Sprint 37

> Sprint 37 does **not** implement RME changes. RME Workflow Improvement Batch 1 is a planning
> recommendation for a future supervised sprint only.

## RME Workflow Improvement Batch 1 implementation-readiness outline

Implementation-readiness for a future Sprint 38 (placeholders only — no code in Sprint 37):

- current RME workflow discovery
- user/operator pain point placeholder
- module map placeholder
- route/view/controller/service touchpoint placeholder
- data integrity risk placeholder
- permission/access risk placeholder
- print/export impact placeholder
- cashier/receivable impact placeholder
- test scope placeholder
- acceptance criteria placeholder
- rollback/escalation reference placeholder
- documentation/training note placeholder
- owner approval placeholder

No code implementation in Sprint 37.

## Governance review checklist

- [ ] Sprint 36 governance accepted
- [ ] Batch 1 candidates reviewed
- [ ] Batch 1 selected
- [ ] owner/admin approval placeholder
- [ ] risk levels assigned
- [ ] acceptance criteria defined
- [ ] test scope defined
- [ ] rollback/escalation reference defined
- [ ] support/training impact reviewed
- [ ] documentation impact reviewed
- [ ] dependency review completed
- [ ] release/GO tag policy reviewed
- [ ] no production execution performed
- [ ] GO / WATCH / DEFER / NO-GO decision recorded

## Risk and decision matrix

### R0/P0 — Critical / blocking

- Examples: production outage, data loss, credential exposure, payment/RME critical blocker,
  rollback required.
- **Owner:** Escalation owner + Product owner
- **Expected action:** stop, contain, escalate immediately
- **Response target placeholder:** `<R0 response target>`
- **Resolution target placeholder:** `<R0 resolution target>`
- **Escalation rule:** immediate owner/admin escalation
- **Closure rule:** owner sign-off + evidence recorded
- **Roadmap/backlog rule:** blocks Batch 1 until resolved
- **Implementation permission rule:** no implementation while open

### R1/P1 — High

- Examples: critical workflow blocked, failed login for key operator, critical report/print
  failure, major data mismatch.
- **Owner:** Operations owner
- **Expected action:** prioritize and mitigate
- **Response target placeholder:** `<R1 response target>`
- **Resolution target placeholder:** `<R1 resolution target>`
- **Escalation rule:** escalate to owner if unresolved
- **Closure rule:** verified fix + evidence
- **Roadmap/backlog rule:** Batch 1 gated until mitigated
- **Implementation permission rule:** implementation only after explicit approval

### R2/P2 — Medium

- Examples: non-critical workflow issue, UI confusion, training gap, minor reporting mismatch.
- **Owner:** Product owner
- **Expected action:** schedule into backlog
- **Response target placeholder:** `<R2 response target>`
- **Resolution target placeholder:** `<R2 resolution target>`
- **Escalation rule:** standard backlog escalation
- **Closure rule:** backlog closure note
- **Roadmap/backlog rule:** may be included in Batch 1 if scoped
- **Implementation permission rule:** normal governance gate

### R3/P3 — Low

- Examples: enhancement request, wording issue, low-risk documentation update.
- **Owner:** Documentation owner
- **Expected action:** log and batch
- **Response target placeholder:** `<R3 response target>`
- **Resolution target placeholder:** `<R3 resolution target>`
- **Escalation rule:** none unless recurring
- **Closure rule:** documentation/backlog note
- **Roadmap/backlog rule:** low-priority backlog
- **Implementation permission rule:** normal governance gate

## Controlled implementation gate for future Sprint 38

Future implementation may only begin when ALL of the following are satisfied:

- [ ] base branch confirmed
- [ ] GO tag confirmed
- [ ] feature branch created
- [ ] candidate item approved
- [ ] impacted modules listed
- [ ] migration need assessed
- [ ] runtime risk assessed
- [ ] permission risk assessed
- [ ] test scope confirmed
- [ ] rollback/escalation reference confirmed
- [ ] documentation update confirmed
- [ ] owner/admin acceptance criteria confirmed
- [ ] implementation allowed only after explicit user approval

## Test strategy for future implementation

Future test categories (for the implementation sprint, not Sprint 37):

- RME feature tests
- permission/access tests
- workflow status tests
- print/export tests
- cashier/receivable impact tests
- regression checklist tests
- targeted Pest filters
- Pint
- `git diff --check`
- no full suite unless needed

## Roadmap batch evidence template

| date/time | roadmap item | category | source reference | owner | business impact | operational risk | technical risk | complexity | selected decision | evidence path | follow-up owner | target sprint |
| --------- | ------------ | -------- | ---------------- | ----- | --------------- | ---------------- | -------------- | ---------- | ----------------- | ------------- | --------------- | ------------- |
|           |              |          |                  |       |                 |                  |                |            |                   |               |                 |               |

## Batch 1 acceptance criteria template

| item ID | module/workflow | acceptance criterion | expected behavior | test coverage | owner/reviewer | risk note | approval status |
| ------- | --------------- | -------------------- | ----------------- | ------------- | -------------- | --------- | --------------- |
|         |                 |                      |                   |               |                |           |                 |

## Future implementation checklist template

| implementation item | affected file/module placeholder | expected change | risk level | test required | documentation required | rollback/escalation note | approval status |
| ------------------- | -------------------------------- | --------------- | ---------- | ------------- | ---------------------- | ------------------------ | --------------- |
|                     |                                  |                 |            |               |                        |                          |                 |

## Governance decision criteria

- **GO** — Batch 1 roadmap scope accepted for a future implementation sprint.
- **WATCH** — Batch 1 scope needs active monitoring, more review, or tighter constraints before
  implementation.
- **DEFER** — Batch 1 item is valuable but postponed due to risk, dependency, or unclear acceptance
  criteria.
- **NO-GO** — stop future implementation due to safety, privacy, data integrity, recovery,
  unresolved R0/R1, support, or acceptance risk.

## Explicit out of scope

- No production code change.
- No bugfix implementation.
- No enhancement implementation.
- No roadmap item implementation.
- No RME implementation.
- No cashier/payment/receivable implementation.
- No reporting/export implementation.
- No WhatsApp automation/send.
- No maintenance execution.
- No branch expansion execution.
- No migration.
- No deployment.
- No production/VPS access.
- No real go-live execution.
- No real post-go-live operation execution.
- No real backup execution.
- No real restore execution.
- No rollback execution.
- No destructive operation.
- No monitoring automation.
- No backup automation.
- No restore automation.
- No cron/scheduler/job/queue/notification change.
- No runtime behavior change.
- No route/controller/service/model/view/config/seeder change.
- No external service call.
- No `.env` change.
- No dependency/package install.

## Validation commands

```bash
php artisan test --filter=Sprint37ControlledRoadmapExecutionBatch1GovernanceReview
vendor/bin/pint --test tests/Feature/Sprint37/Sprint37ControlledRoadmapExecutionBatch1GovernanceReviewTest.php
git diff --check
```

## PR readiness marker

GO CANDIDATE FOR PR REVIEW

## Next sprint recommendation

```text
Sprint 38 — RME Workflow Improvement Batch 1
```

Sprint 38 should focus on implementing the approved and controlled RME Workflow Improvement Batch 1
under the Sprint 36–37 governance gates, with targeted tests, explicit owner/admin approval, and no
production deployment until reviewed. Implementation begins only after explicit user approval in a
separate supervised implementation workflow.
