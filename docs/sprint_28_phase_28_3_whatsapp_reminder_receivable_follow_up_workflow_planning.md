# Sprint 28 Phase 28.3 — WhatsApp Reminder & Receivable Follow-up Workflow Planning

## Status

**Mode:** WhatsApp reminder / receivable follow-up workflow planning only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Integration change:** No WhatsApp/API integration implemented
**Destructive data operation:** No destructive data operation
**Baseline:** Sprint 28.2 GO at `05539ef`

## Purpose

Sprint 28 Phase 28.3 plans how the pilot will use WhatsApp as a manual, operator-driven
communication channel for appointment reminders and receivable/piutang follow-up, without
building any integration or changing runtime behavior.

Goals:

- Plan the appointment reminder workflow for pilot operations.
- Plan the receivable/piutang follow-up workflow for pilot operations.
- Reuse the existing patient WhatsApp (WA) number as the communication channel.
- Protect the Sprint 27 RME Control Workflow, the Sprint 28.1 operator smoke checklist, and
  the Sprint 28.2 pilot daily operation runbook.
- Prepare a safe implementation backlog without changing runtime behavior.

This phase is documentation / workflow planning / checklist test only. It does not change RME,
payment, receivable, cashier, odontogram, invoice, route, service, controller, model, view,
migration, seeder, queue, job, notification, or configuration behavior.

## Non-goals

- No WhatsApp API implementation.
- No payment gateway implementation.
- No queue/job/notification implementation.
- No database schema change.
- No route/controller/service/model/view change.
- No automated message sending.
- No patient data cleanup.
- No production data mutation.
- No business rule change.

## Planning Assumptions

- The patient WA number is used for appointment confirmation and receivable follow-up.
- No. KTP remains the patient identity binding and must not be exposed in message templates.
- Messages must avoid sensitive clinical details (diagnosis/treatment specifics).
- The operator/admin must verify the recipient before sending any message.
- A manual operator-driven process is the first safe pilot posture.
- Automation may be planned later only after the manual workflow is accepted.

## Appointment Reminder Workflow

This workflow is manual and operator-driven. Each row is a planning checkpoint, not an
implemented feature.

| # | Aspect | Planned behavior |
|---|--------|------------------|
| 1 | Candidate trigger | Upcoming appointment / visit schedule / control visit schedule. |
| 2 | Recipient | Patient WA number. |
| 3 | Sender role | Operator/admin. |
| 4 | Timing | H-1 reminder, same-day reminder, missed appointment follow-up. |
| 5 | Verification | Patient identity and WA number checked before sending. |
| 6 | Message type | Reminder, confirmation, reschedule request. |
| 7 | Status capture | Confirmed, rescheduled, no response, wrong number, cancelled. |
| 8 | Escalation | Repeated no response or wrong number escalated to admin. |

Checklist:

- [ ] Identify appointment/visit/control-visit candidates for the day.
- [ ] Verify patient identity and WA number before contact.
- [ ] Send the approved reminder/confirmation/reschedule message only.
- [ ] Record the response status (confirmed/rescheduled/no response/wrong number/cancelled).
- [ ] Escalate repeated no response or wrong number.

## Receivable Follow-up Workflow

This workflow is manual and cashier/admin-driven. Each row is a planning checkpoint, not an
implemented feature.

| # | Aspect | Planned behavior |
|---|--------|------------------|
| 1 | Candidate trigger | Active receivable with remaining balance > 0. |
| 2 | Rp0 exclusion | Rp0 invoice must not be included in active receivable follow-up. |
| 3 | Carry-over rule | Previous receivable and control visit carry-over must preserve FIFO payment allocation. |
| 4 | Recipient | Patient WA number. |
| 5 | Sender role | Cashier/admin. |
| 6 | Timing | Gentle reminder, due follow-up, overdue follow-up. |
| 7 | Verification | Invoice/receivable amount verified before contact. |
| 8 | Message type | Payment reminder, payment confirmation request, payment allocation clarification. |
| 9 | Status capture | Promised to pay, paid, partial payment, no response, dispute, wrong number. |
| 10 | Escalation | Disputed balance or repeated no response escalated to admin. |

Checklist:

- [ ] Identify active receivables with remaining balance > 0.
- [ ] Exclude Rp0 invoices from the follow-up queue.
- [ ] Verify the invoice/receivable amount before contact.
- [ ] Confirm FIFO previous-receivable-first allocation is preserved before messaging.
- [ ] Send the approved payment reminder/confirmation/clarification message only.
- [ ] Record the response status (promised/paid/partial/no response/dispute/wrong number).
- [ ] Escalate disputed balance or repeated no response.

## RME Control Workflow Safety Notes

WhatsApp follow-up planning must not weaken the Sprint 27 RME Control Workflow GO behavior:

- Control visits still use the same patient/RM but create a new visit.
- Old RME/odontogram/invoice must not be overwritten.
- Parent receivable can remain visible/payable in cashier control.
- Payment allocation remains FIFO previous receivable first.
- Parent receivable does not block control completion.
- Rp0 invoice does not appear in active receivables or the follow-up queue.
- WhatsApp follow-up planning must not change payment allocation or receivable rules.

## Message Template Drafts

Sample templates are in Indonesian, generic, and privacy-safe. Placeholders in `[...]` are
filled manually by the operator after verification.

Rules for every template:

- Do not include No. KTP.
- Avoid detailed diagnosis/treatment info.
- Include a clinic name placeholder.
- Include an operator confirmation placeholder.
- Include an opt-out/manual correction note if the number is wrong.

### Appointment reminder H-1

```
Halo [Nama Pasien], ini pengingat dari [Nama Klinik].
Anda memiliki jadwal kunjungan besok, [Tanggal] pukul [Jam].
Mohon balas YA untuk konfirmasi atau hubungi kami untuk reschedule.
Dikirim oleh: [Nama Operator].
Jika nomor ini salah, mohon abaikan dan beri tahu kami agar kami perbaiki.
```

### Same-day appointment reminder

```
Halo [Nama Pasien], pengingat dari [Nama Klinik].
Jadwal kunjungan Anda hari ini, [Tanggal] pukul [Jam].
Mohon balas YA untuk konfirmasi kehadiran.
Dikirim oleh: [Nama Operator].
Jika nomor ini salah, mohon beri tahu kami agar kami perbaiki.
```

### Missed appointment follow-up

```
Halo [Nama Pasien], dari [Nama Klinik].
Kami belum bertemu Anda pada jadwal [Tanggal]. Apakah Anda ingin menjadwalkan ulang?
Mohon balas dengan tanggal yang Anda inginkan.
Dikirim oleh: [Nama Operator].
Jika nomor ini salah, mohon beri tahu kami agar kami perbaiki.
```

### Receivable gentle reminder

```
Halo [Nama Pasien], dari [Nama Klinik].
Kami mencatat masih ada sisa pembayaran sebesar [Jumlah].
Mohon konfirmasi rencana pembayaran Anda. Terima kasih.
Dikirim oleh: [Nama Operator].
Jika nomor ini salah, mohon beri tahu kami agar kami perbaiki.
```

### Receivable due follow-up

```
Halo [Nama Pasien], dari [Nama Klinik].
Sisa pembayaran Anda sebesar [Jumlah] telah jatuh tempo.
Mohon konfirmasi kapan pembayaran dapat dilakukan.
Dikirim oleh: [Nama Operator].
Jika nomor ini salah, mohon beri tahu kami agar kami perbaiki.
```

### Payment received confirmation

```
Halo [Nama Pasien], terima kasih dari [Nama Klinik].
Pembayaran Anda sebesar [Jumlah] telah kami terima.
Dikirim oleh: [Nama Operator].
Jika ada perbedaan catatan, mohon beri tahu kami.
```

## Operator / Cashier Handling Checklist

- [ ] Verify patient identity.
- [ ] Verify WA number.
- [ ] Verify appointment/visit/invoice/receivable status.
- [ ] Send approved message only.
- [ ] Record response status.
- [ ] Escalate wrong number/disputed balance.
- [ ] Do not promise clinical or payment policy changes outside the approved workflow.

## Support / Admin Planning Checklist

- [ ] Review data source candidates.
- [ ] Review privacy/sensitive data boundaries.
- [ ] Review template wording.
- [ ] Review role ownership.
- [ ] Review manual logging format.
- [ ] Review future automation risks.
- [ ] Review daily summary needs.
- [ ] Review backup/restore and audit posture before future implementation.

## Manual Log Format

Record one row per outbound contact.

| Field | Description |
|-------|-------------|
| Date/time | When the message was sent. |
| Sender role/name | Operator/cashier role and name. |
| Patient/RM reference if needed | Identifier only when needed for follow-up. |
| WA number verification status | Verified / unverified / wrong number. |
| Workflow type | Appointment reminder / receivable follow-up. |
| Message template used | Which approved template was sent. |
| Response status | Confirmed/rescheduled/promised/paid/partial/no response/dispute/wrong number. |
| Follow-up date | Next planned follow-up date. |
| Amount reference if receivable-related | Receivable/invoice amount referenced. |
| Notes | Free-text notes. |
| Escalation owner | Who owns the escalation if needed. |

## Future Automation Candidate Design

Planning-only. Nothing here is implemented in this phase.

- Reminder queue candidate.
- Receivable follow-up queue candidate.
- Template management candidate.
- Message status tracking candidate.
- Audit log candidate.
- User permission candidate.
- Branch-aware sending candidate.
- Rate limit / anti-spam candidate.
- Failed-send retry candidate.
- Consent/opt-out candidate.

## Risk and Mitigation

| Risk | Mitigation |
|------|------------|
| Wrong recipient | Verify patient identity and WA number before sending. |
| Sensitive data leakage | Use privacy-safe templates; no No. KTP; no detailed diagnosis/treatment. |
| Incorrect receivable amount | Verify invoice/receivable amount before contact. |
| Duplicate reminder | Record manual log and check before resending. |
| Over-aggressive collection | Use gentle → due → overdue tone; escalate disputes, do not pressure. |
| Rp0 invoice accidentally followed up | Exclude Rp0 invoices from the active receivable follow-up queue. |
| FIFO/payment allocation misunderstanding | Reaffirm FIFO previous-receivable-first rule before messaging. |
| Manual log inconsistency | Use the standard manual log format for every contact. |

## GO / NO-GO

**GO** only if:

- The workflow planning document is complete.
- `docs/sprint_history.md` is updated.
- The focused test passes.
- No production code change.
- No migration.
- No deployment.
- No WhatsApp/API integration.
- No destructive operation.
- No business rule change.

**NO-GO** if:

- Any production code change.
- Any migration or deploy is introduced.
- Any WhatsApp/API integration is implemented.
- Any runtime behavior change.
- Any destructive data operation.
- The workflow plan is incomplete.
- Sprint history or test is missing.

## Recommended Next Phase

Sprint 28 Phase 28.4 may be one of:

- Monitoring/backup/restore rehearsal.
- WhatsApp reminder manual pilot SOP.
- Pilot issue triage and stabilization backlog.
- WhatsApp reminder technical design, planning-only.

## Validation Plan

- `php artisan test --filter=Sprint28Phase283WhatsappReminderReceivableFollowUpWorkflowPlanning`
- `vendor/bin/pint --test tests/Feature/Sprint28/Sprint28Phase283WhatsappReminderReceivableFollowUpWorkflowPlanningTest.php`
- `git diff --check`

## Decision

GO CANDIDATE FOR PR REVIEW
