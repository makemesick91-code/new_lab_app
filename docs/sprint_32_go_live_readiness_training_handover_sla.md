# Sprint 32 — Go-Live Readiness, Training, Handover & SLA

Status: Draft / Local Validation Pending
Baseline: Sprint 31 GO at 0ad4a45
Scope: Docs / go-live readiness / training / handover / SLA checklist-test only

## Purpose

Sprint 32 follows Sprint 30 pilot operational smoke and Sprint 31 backup/restore rehearsal
execution and recovery readiness. It converts the accumulated pilot readiness and recovery
readiness artifacts into a single, auditable **go-live readiness package**.

This sprint prepares the final go-live readiness, operator training, handover, and the
support/SLA model that must be in place before any actual go-live.

This sprint does **not** execute go-live, deployment, production change, real backup, real
restore, or any automation. It only **defines** the readiness gates, role ownership, training
acceptance, handover evidence, and support responsibilities that precede a separately
supervised go-live execution.

## Baseline references

```
Sprint 30 GO: sprint-30-pilot-execution-bugfix-operational-smoke-go at 53c3442
Sprint 31 GO: sprint-31-backup-restore-rehearsal-execution-recovery-readiness-go at 0ad4a45
```

Sprint 31 feature reference:

```
Sprint 31 feature commit: de85daf
```

Lineage: Sprint 29 pilot stabilization / safety review / backup-restore readiness planning →
Sprint 30 pilot execution operational smoke → Sprint 31 backup/restore rehearsal execution &
recovery readiness → **Sprint 32 go-live readiness, training, handover & SLA** (this sprint).

## Go-live readiness scope

This sprint produces a **readiness package only**. Specifically:

- Readiness package only — no go-live executed in this sprint.
- No production go-live execution.
- No deployment.
- No VPS / production access.
- No production data mutation.
- No migration.
- No runtime behavior change.
- Manual operator-confirmed checklist only.
- Go-live must be executed later in a separate supervised workflow if (and only if) approved.

## Operational readiness checklist

Each item is a manual operator-confirmed gate. Mark `READY` / `NOT READY` / `N/A` with evidence.

- [ ] **Branch/operator readiness** — target branch, commit, and tag confirmed; operators identified.
- [ ] **Role and permission readiness** — Owner / Admin cabang / Kasir / Doctor / Perawat roles verified least-privilege.
- [ ] **Login/access readiness** — each key operator can log in to the intended branch context.
- [ ] **RME workflow readiness** — visit → doctor RME finalize → cashier_pending transition verified.
- [ ] **Odontogram/medical record readiness** — odontogram + handwriting RM capture verified.
- [ ] **Cashier invoice/payment readiness** — full-payment invoice → PAID → receipt verified.
- [ ] **Receivable/piutang readiness** — outstanding/piutang view and follow-up path verified.
- [ ] **Print/export readiness** — visit print bundle, receipt, and PDF export verified.
- [ ] **WhatsApp manual reminder SOP readiness** — manual (non-automated) reminder SOP available and understood.
- [ ] **Monitoring evidence readiness** — monitoring/observation evidence collection path defined.
- [ ] **Backup/restore readiness reference** — Sprint 31 recovery readiness reference confirmed (`docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md`).
- [ ] **Support contact readiness** — support contact list populated (placeholder until assigned).
- [ ] **Escalation path readiness** — escalation owner and path documented.
- [ ] **Owner/admin acceptance readiness** — owner/admin acceptance sign-off ready to be recorded.

## Training plan

For each audience group: define training objective, training material, required demo scenario,
acceptance evidence, and follow-up owner.

### Owner / management

- **Objective:** understand scope, readiness state, support/SLA model, and acceptance responsibilities.
- **Material:** go-live readiness package, handover summary, SLA/support model.
- **Demo scenario:** review pilot dashboard / readiness summary and acceptance gates.
- **Acceptance evidence:** signed acknowledgement of readiness and support model.
- **Follow-up owner:** Owner / management lead.

### Admin cabang

- **Objective:** manage branch operations, visits, and operator access within branch isolation.
- **Material:** login/access SOP, RME visit creation SOP, branch role guide.
- **Demo scenario:** create a clinic visit and route it to the correct doctor/cashier within the branch.
- **Acceptance evidence:** completed demo visit screenshot/log.
- **Follow-up owner:** Admin cabang lead.

### Cashier

- **Objective:** process invoice and full payment, print receipt, track receivable.
- **Material:** cashier/payment SOP, print/export SOP, receivable follow-up SOP.
- **Demo scenario:** invoice a finalized RME visit, take full payment, print receipt.
- **Acceptance evidence:** PAID invoice + printed/exported receipt evidence.
- **Follow-up owner:** Cashier lead.

### Doctor / clinic operator using RME

- **Objective:** capture odontogram + handwriting RM and finalize RME.
- **Material:** RME SOP, odontogram/handwriting capture guide.
- **Demo scenario:** complete odontogram + handwriting RM and finalize to cashier_pending.
- **Acceptance evidence:** finalized RME visit with handwriting PNG present.
- **Follow-up owner:** Lead doctor / clinic operator.

### Lab/admin operator (if relevant)

- **Objective:** review RME lab case candidates and (if approved) convert to lab orders.
- **Material:** lab candidate queue SOP, conversion SOP.
- **Demo scenario:** review a pending lab case candidate in the queue.
- **Acceptance evidence:** queue review evidence / candidate status note.
- **Follow-up owner:** Lab/admin lead.

### Support/technical maintainer

- **Objective:** operate support routine, triage, backup/restore readiness reference, escalation.
- **Material:** SLA/support model, incident reporting SOP, Sprint 31 recovery readiness reference.
- **Demo scenario:** run through severity triage and escalation path on a sample issue.
- **Acceptance evidence:** documented triage walkthrough.
- **Follow-up owner:** Technical maintainer / support lead.

## Handover package checklist

- [ ] **Application scope summary** — modules, pilot boundaries, and known constraints.
- [ ] **Current stable branch and GO tag** — recorded branch, commit, and GO tag lineage.
- [ ] **Login/access SOP** — how operators authenticate and reach their branch context.
- [ ] **RME SOP** — visit → odontogram → handwriting RM → finalize.
- [ ] **Cashier/payment SOP** — invoice → full payment → receipt.
- [ ] **Receivable follow-up SOP** — outstanding/piutang tracking and follow-up.
- [ ] **Print/export SOP** — visit bundle, receipt, PDF export.
- [ ] **WhatsApp manual reminder SOP** — manual reminder procedure (no automation).
- [ ] **Backup/restore readiness SOP reference** — Sprint 31 recovery readiness reference.
- [ ] **Incident reporting SOP** — how issues are reported and tracked.
- [ ] **Support contact list placeholder** — to be populated with named contacts.
- [ ] **Escalation matrix** — severity → owner → channel.
- [ ] **Known limitations** — documented pilot limitations.
- [ ] **Open risks** — documented open risks and mitigations.
- [ ] **Acceptance sign-off** — owner/admin sign-off recorded.

## SLA/support model

- **Support hours:** _placeholder_ (e.g. pilot business hours TBD).
- **Response target:** _placeholder_ (per-severity, TBD).
- **Resolution target:** _placeholder_ (per-severity, TBD).
- **Communication channel:** _placeholder_ (primary + backup channel TBD).
- **Escalation owner:** _placeholder_ (named escalation owner TBD).

### Severity levels

- **P0:** production outage, data loss, credential exposure, payment/RME critical blocker.
- **P1:** critical workflow blocked, failed login for key operator, critical report/print failure, major data mismatch.
- **P2:** non-critical workflow issue, UI confusion, training gap, minor reporting mismatch.
- **P3:** enhancement request, wording issue, low-risk documentation update.

### Routines and rules

- **Issue triage workflow:** intake → classify severity (P0–P3) → assign owner → track → resolve/verify → close.
- **Daily pilot support routine:** confirm operators can log in, review reported issues, confirm backup/recovery readiness reference, log status.
- **Weekly review routine:** review open issues, severity trends, training gaps, and outstanding risks.
- **Change request intake rule:** all change requests logged as P3 (or higher if they block a workflow) and reviewed before any code change; no ad-hoc production change.
- **Emergency rollback/escalation rule:** P0/P1 triggers escalation owner notification and references the documented rollback/recovery path (Sprint 31) — executed only in a separate supervised workflow.

## Go-live decision gates

All gates must be satisfied (or explicitly waived with documented rationale) before a GO decision:

- [ ] Sprint 30 pilot smoke accepted.
- [ ] Sprint 31 recovery readiness accepted.
- [ ] Operator training complete.
- [ ] Handover package complete.
- [ ] Support contact assigned.
- [ ] Escalation owner assigned.
- [ ] Privacy/data handling review complete.
- [ ] Known limitations accepted.
- [ ] Rollback/escalation path documented.
- [ ] Owner/admin sign-off recorded.
- [ ] GO / WATCH / NO-GO decision recorded.

## Go / Watch / No-Go criteria

- **GO** — readiness package complete and accepted; ready for a separate supervised go-live execution workflow.
- **WATCH** — proceed only with documented mitigations and active support monitoring.
- **NO-GO** — stop due to safety, privacy, data integrity, recovery, training, support, or acceptance risk.

## Go-live runbook placeholder

> **This sprint does not execute go-live.** The section below is a placeholder runbook only.
> Actual go-live must be a **separate supervised workflow**. That workflow must confirm branch,
> commit, tag, backup readiness, operator availability, support coverage, and rollback/escalation
> path before any execution. Do not include real secrets or production credentials here.

Placeholder phases (no execution in this sprint):

1. **Pre-go-live confirmation** — confirm branch / commit / tag and readiness gates.
2. **Final backup/recovery readiness confirmation** — confirm Sprint 31 recovery readiness reference.
3. **Operator availability confirmation** — confirm key operators are available.
4. **Support coverage confirmation** — confirm support + escalation owner coverage.
5. **Go-live execution approval gate** — explicit owner/admin approval required to proceed.
6. **Post-go-live smoke verification** — login, RME, cashier, print smoke checks.
7. **Owner/admin acceptance** — record acceptance.
8. **Issue tracking and support watch** — track issues and run hypercare watch.

## Evidence template

| date/time | environment | audience/group | operator/trainer | reviewer/approver | readiness item | expected result | actual result | evidence path | issue severity | decision | follow-up owner | target date |
|-----------|-------------|----------------|------------------|-------------------|----------------|-----------------|---------------|---------------|----------------|----------|-----------------|-------------|
|           |             |                |                  |                   |                |                 |               |               |                |          |                 |             |

## Training acceptance template

| trainee group | trainee name/role placeholder | module trained | scenario demonstrated | trainer | evidence path | status | follow-up notes | sign-off |
|---------------|-------------------------------|----------------|-----------------------|---------|---------------|--------|-----------------|----------|
|               |                               |                |                       |         |               |        |                 |          |

## Handover sign-off template

| handover item | owner | recipient | evidence path | accepted status | open issue | sign-off date |
|---------------|-------|-----------|---------------|-----------------|------------|---------------|
|               |       |           |               |                 |            |               |

## Explicit out of scope

- No production code change.
- No migration.
- No deployment.
- No production/VPS access.
- No real go-live execution.
- No real backup execution.
- No real restore execution.
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
php artisan test --filter=Sprint32GoLiveReadinessTrainingHandoverSla
vendor/bin/pint --test tests/Feature/Sprint32/Sprint32GoLiveReadinessTrainingHandoverSlaTest.php
git diff --check
```

## PR readiness marker

GO CANDIDATE FOR PR REVIEW

## Next sprint recommendation

Sprint 33 — Controlled Go-Live Execution & Hypercare Watch.

Sprint 33 should focus on a separate, supervised go-live execution workflow, post-go-live smoke
verification, hypercare watch, issue triage, and operational acceptance. It must remain gated by
explicit owner/admin approval and by the Sprint 31 recovery readiness reference. No go-live or
production action may be executed until that supervised workflow is explicitly approved.
