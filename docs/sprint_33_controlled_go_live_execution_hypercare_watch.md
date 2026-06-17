# Sprint 33 — Controlled Go-Live Execution & Hypercare Watch

Status: Draft / Local Validation Pending
Baseline: Sprint 32 GO at 54ed93a
Scope: Docs / controlled go-live execution plan / hypercare watch checklist-test only

## Purpose

Sprint 33 follows the Sprint 32 go-live readiness, training, handover, and SLA closure and
converts that accepted readiness into an auditable **controlled go-live execution plan** and a
**hypercare watch package**.

This sprint:

- Prepares a controlled go-live execution plan and hypercare watch checklist.
- Defines approval gates, execution phases, smoke verification, hypercare routines, incident
  handling, escalation, rollback/recovery decision-making, and acceptance evidence before any real
  supervised go-live.
- Does **not** execute go-live, deployment, production/VPS action, backup, restore, or automation.

All content here is local repository documentation and a checklist/documentation validation test
only. Any real go-live, deployment, or recovery work must happen later in a separate supervised
workflow if and when the owner/admin explicitly approves it.

## Baseline references

```text
Sprint 31 GO: sprint-31-backup-restore-rehearsal-execution-recovery-readiness-go at 0ad4a45
Sprint 32 GO: sprint-32-go-live-readiness-training-handover-sla-go at 54ed93a
```

Sprint 32 feature reference:

```text
Sprint 32 feature commit: 1b53cd2
```

Lineage: Sprint 30 pilot operational smoke → Sprint 31 backup/restore rehearsal & recovery
readiness (`0ad4a45`) → Sprint 32 go-live readiness, training, handover & SLA (`54ed93a`) →
Sprint 33 controlled go-live execution plan & hypercare watch (this document).

## Controlled go-live scope

This sprint covers:

- A **controlled go-live execution plan only** — a documented, operator-confirmed runbook.
- A **hypercare watch package only** — checklist routines for the post-go-live watch window.
- **No actual production go-live execution** in this sprint.
- No deployment.
- No VPS/production access.
- No production data mutation.
- No migration.
- No runtime behavior change.
- No automation/scheduler/queue change.
- Manual operator-confirmed checklist only.
- Real go-live must be executed later in a **separate supervised workflow** if approved.

## Pre-go-live approval gates

All gates must be confirmed (operator-signed) before any real supervised go-live is scheduled:

- [ ] Sprint 32 readiness accepted
- [ ] Operator training accepted
- [ ] Handover package accepted
- [ ] Owner/admin sign-off captured
- [ ] Support contact assigned
- [ ] Escalation owner assigned
- [ ] Rollback/escalation path documented
- [ ] Privacy/data handling review complete
- [ ] Backup/restore readiness reference accepted from Sprint 31
- [ ] Known limitations accepted
- [ ] Communication channel confirmed
- [ ] Support coverage confirmed
- [ ] GO / WATCH / NO-GO decision recorded

## Controlled go-live execution runbook placeholder

> **This sprint does not execute go-live.**
> Actual go-live must be a **separate supervised workflow**.
> The actual workflow must confirm branch, commit, tag, backup/recovery readiness, operator
> availability, support coverage, and rollback/escalation path before execution.

This section is a placeholder structure only. No real secrets, production credentials, or
production-specific destructive commands are included. Commands listed elsewhere are
example/checklist placeholders and must not be executed against production from this sprint.

Placeholder phases:

- **Phase 0 — pre-execution confirmation:** confirm pre-go-live approval gates, branch/commit/tag,
  operator and support availability.
- **Phase 1 — final readiness review:** re-review Sprint 32 readiness, Sprint 31 recovery
  readiness, known limitations, and open risks.
- **Phase 2 — deployment/go-live approval gate:** record explicit owner/admin GO/WATCH/NO-GO before
  any execution.
- **Phase 3 — supervised go-live execution placeholder:** executed only in the separate supervised
  workflow; not performed in this sprint.
- **Phase 4 — immediate post-go-live smoke verification:** run the post-go-live smoke checklist
  below and capture evidence.
- **Phase 5 — owner/admin acceptance:** record go-live acceptance using the acceptance template.
- **Phase 6 — hypercare watch activation:** start the hypercare watch window and assign the support
  owner.
- **Phase 7 — issue tracking and stabilization review:** log issues, triage by severity, and record
  the hypercare closure recommendation.

## Post-go-live smoke verification checklist

- [ ] Application boots
- [ ] Login page reachable
- [ ] Key roles can access menus
- [ ] RME visit workflow smoke
- [ ] Odontogram/medical record smoke
- [ ] Cashier invoice/payment smoke
- [ ] Receivable/piutang smoke
- [ ] Print/export smoke
- [ ] WhatsApp manual reminder SOP evidence only
- [ ] Reporting smoke
- [ ] Monitoring evidence placeholder
- [ ] Backup/restore readiness evidence location recorded
- [ ] Owner/admin smoke acceptance recorded

## Hypercare watch checklist

- [ ] Watch window start/end placeholder
- [ ] Support owner assignment
- [ ] Daily issue triage
- [ ] Daily operator feedback
- [ ] Critical workflow watch
- [ ] RME workflow watch
- [ ] Cashier/payment watch
- [ ] Receivable/piutang watch
- [ ] Print/export watch
- [ ] Login/access watch
- [ ] Monitoring evidence review
- [ ] Incident log review
- [ ] Unresolved issue escalation
- [ ] End-of-day summary
- [ ] Hypercare closure recommendation

## Incident triage and escalation matrix

Severity levels:

- **P0:** production outage, data loss, credential exposure, payment/RME critical blocker, rollback
  required.
  - Owner: support owner + escalation owner (immediate).
  - Expected action: contain, capture evidence, evaluate rollback/recovery decision checklist.
  - Escalation rule: escalate immediately to owner/admin; engage technical maintainer.
  - Communication rule: notify owner/admin and operators without delay via the agreed channel.
  - Decision outcome: continue / watch / rollback / stop recorded with evidence.
- **P1:** critical workflow blocked, failed login for key operator, critical report/print failure,
  major data mismatch.
  - Owner: support owner.
  - Expected action: prioritize same-day investigation and mitigation.
  - Escalation rule: escalate to escalation owner if unresolved within the SLA response target.
  - Communication rule: same-day notification to owner/admin and affected operators.
  - Decision outcome: continue / watch / escalate recorded.
- **P2:** non-critical workflow issue, UI confusion, training gap, minor reporting mismatch.
  - Owner: support owner / assigned operator.
  - Expected action: log, schedule, and address within the agreed window.
  - Escalation rule: escalate only if recurring or blocking acceptance.
  - Communication rule: include in the daily hypercare summary.
  - Decision outcome: continue / backlog recorded.
- **P3:** enhancement request, wording issue, low-risk documentation update.
  - Owner: backlog owner.
  - Expected action: capture in backlog for a future sprint.
  - Escalation rule: none unless re-prioritized by owner/admin.
  - Communication rule: include in the daily hypercare summary.
  - Decision outcome: backlog recorded.

## Rollback and recovery decision checklist

This is a decision checklist only. Do **not** execute rollback or recovery from this sprint.

- [ ] Rollback trigger identified
- [ ] Recovery owner assigned
- [ ] Impact scope confirmed
- [ ] Backup/recovery readiness evidence reviewed
- [ ] Operator/admin notified
- [ ] Escalation contact notified
- [ ] Decision recorded as continue / watch / rollback / stop
- [ ] Post-decision evidence captured

## Communication plan

- Owner/admin announcement: placeholder.
- Operator go-live notice: placeholder.
- Support channel: placeholder.
- Escalation contact: placeholder.
- Issue reporting format: placeholder (use the hypercare issue log template).
- Daily hypercare summary: placeholder.
- Incident communication rule: placeholder (align with the escalation matrix).
- Closure announcement: placeholder.

## Evidence template

| date/time | environment | phase | operator | reviewer/approver | checklist item | expected result | actual result | evidence path | issue severity | decision | follow-up owner | target date |
|-----------|-------------|-------|----------|-------------------|----------------|-----------------|---------------|---------------|----------------|----------|-----------------|-------------|
|           |             |       |          |                   |                |                 |               |               |                |          |                 |             |

## Hypercare issue log template

| issue ID | date/time | reporter | module/workflow | severity | description | impact | owner | status | action taken | evidence path | next step | closure date |
|----------|-----------|----------|-----------------|----------|-------------|--------|-------|--------|--------------|---------------|-----------|--------------|
|          |           |          |                 |          |             |        |       |        |              |               |           |              |

## Go-live acceptance template

| acceptance area | owner/approver | expected result | actual result | evidence path | decision | open issue | sign-off date |
|-----------------|----------------|-----------------|---------------|---------------|----------|------------|---------------|
|                 |                |                 |               |               |          |            |               |

## Hypercare closure criteria

- No unresolved P0.
- P1 issues resolved or accepted with mitigation.
- P2/P3 issues logged with owners.
- Daily summaries completed.
- Owner/admin acceptance recorded.
- Support/SLA handover confirmed.
- Next backlog items identified.
- GO / WATCH / EXTEND HYPERCARE / ROLLBACK recommendation recorded.

## Go / Watch / No-Go / Rollback criteria

- **GO** — proceed to a separate supervised go-live execution, or continue operation after smoke
  verification passes with owner/admin acceptance.
- **WATCH** — continue with active mitigations and daily hypercare monitoring.
- **NO-GO** — stop before go-live due to safety, privacy, data integrity, recovery, training,
  support, or acceptance risk.
- **ROLLBACK** — trigger the recovery/escalation path due to severe production impact, data
  integrity risk, or unacceptable workflow failure (executed only in the separate supervised
  workflow).

## Explicit out of scope

- No production code change.
- No migration.
- No deployment.
- No production/VPS access.
- No real go-live execution.
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
- No WhatsApp automation/send.
- No external service call.
- No `.env` change.
- No dependency/package install.

## Validation commands

```bash
php artisan test --filter=Sprint33ControlledGoLiveExecutionHypercareWatch
vendor/bin/pint --test tests/Feature/Sprint33/Sprint33ControlledGoLiveExecutionHypercareWatchTest.php
git diff --check
```

## PR readiness marker

GO CANDIDATE FOR PR REVIEW

## Next sprint recommendation

```text
Sprint 34 — Post Go-Live Stabilization, Issue Burn-down & Operational Closure
```

Sprint 34 should focus on post-go-live stabilization, issue burn-down, operational closure, support
metrics review, accepted backlog consolidation, and final production operations handover. It must
remain gated by owner/admin acceptance and unresolved issue severity (no closure while any
unresolved P0 or unmitigated P1 remains).
