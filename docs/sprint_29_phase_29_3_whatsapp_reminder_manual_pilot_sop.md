# Sprint 29 Phase 29.3 — WhatsApp Reminder Manual Pilot SOP

## 1. Status

- **Mode:** WhatsApp reminder manual pilot SOP only
- **Deployment:** no deployment
- **Migration:** no migration
- **Production code change:** no production code change
- **Bug fix execution:** no bug fix implemented
- **Stabilization execution:** no stabilization implemented
- **Runtime behavior change:** no runtime behavior change
- **WhatsApp API integration:** no WhatsApp API integration
- **WhatsApp automation:** no WhatsApp automation
- **Queue/job/notification change:** no queue/job/notification change
- **Destructive data operation:** no destructive data operation
- **Baseline:** Sprint 29.2 GO at `266a0d2`

## 2. Purpose

- Create a manual SOP for pilot WhatsApp reminders and receivable follow-up before technical automation.
- Protect cashier/payment/receivable correctness while reminders are still manual.
- Protect patient privacy and reduce accidental message leakage.
- Define when operators may send appointment reminders.
- Define when cashier/admin may send receivable/piutang follow-up.
- Define message evidence and daily logging requirements.
- Keep this phase docs-only and reviewable.
- Prepare safe future automation planning without changing runtime behavior now.

## 3. Non-goals

- No production code change.
- No WhatsApp API integration.
- No WhatsApp bot implementation.
- No automation scheduler.
- No queue/job/notification implementation.
- No reminder sending from the application.
- No receivable follow-up sending from the application.
- No migration/schema change.
- No deployment.
- No database mutation.
- No destructive data operation.
- No route/controller/service/model/view change.
- No cashier/payment/receivable/RME business rule change.
- No direct modification to WhatsApp, cashier, payment, receivable, or RME code.

## 4. Background

- Sprint 28.3 documented WhatsApp Reminder & Receivable Follow-up Workflow Planning.
- Sprint 28.5 documented pilot issue triage and stabilization backlog.
- Sprint 29.0 prioritized pilot stabilization backlog.
- Sprint 29.2 documented cashier/payment/receivable high-risk stabilization planning.
- WhatsApp automation must not be implemented before manual SOP, privacy rules, and evidence rules are proven during pilot.
- Manual SOP is the safest next step before API integration.

## 5. Manual WhatsApp reminder scope

Two manual lanes are defined for the pilot:

- **Lane A: Appointment reminder / visit attendance confirmation.**
- **Lane B: Receivable / piutang follow-up.**

Scope rules:

- Manual messages are sent outside the application.
- Application data is only used as a reference.
- No automatic sending occurs in this phase.
- Manual SOP must not override cashier/payment/receivable truth.
- Manual SOP must not expose No. KTP.
- Manual SOP must not expose unnecessary clinical details.

## 6. Roles and responsibility

- **Operator/front office:** appointment reminder and attendance confirmation.
- **Cashier/admin:** receivable/piutang follow-up after balance verification.
- **Supervisor/owner:** escalation for disputed balance, complaint, or sensitive case.
- **IT/support:** observe SOP issues and collect non-sensitive evidence.
- **Doctor/clinical role:** not responsible for receivable follow-up unless locally required by clinic policy.

## 7. Appointment reminder manual SOP

- Check scheduled visit list.
- Verify patient name and WA number.
- Verify branch/date/time/doctor/treatment if available.
- Do not include sensitive diagnosis/clinical notes.
- Send reminder using approved template.
- Record message status manually.
- Record patient response manually.
- Escalate invalid number or cancellation.
- Do not spam repeated reminders.
- Do not message outside approved operational hours unless authorized.

## 8. Receivable / piutang follow-up manual SOP

- Verify invoice identity.
- Verify patient identity using safe non-KTP reference.
- Verify remaining balance is greater than 0.
- Verify Rp0 invoice is excluded.
- Verify fully paid invoice is excluded.
- Verify parent/current invoice context if related to RME control visit.
- Verify payment receipt/allocation evidence if needed.
- Send follow-up using approved template.
- Record follow-up status manually.
- Escalate disputed balance.
- Do not silently fix or reinterpret balance from WhatsApp reply.
- Do not send follow-up if remaining balance is unclear.

## 9. Cashier/payment/receivable guardrails

- Invoice identity must be preserved.
- Remaining balance must be accurate.
- Active receivable follow-up is only for remaining balance > 0.
- Rp0 invoice must not be followed up as active receivable.
- Fully paid invoice must not be followed up as active receivable.
- Payment allocation must remain FIFO previous receivable first.
- Split allocation must remain traceable if parent/current invoice both exist.
- Payment receipt allocation must remain traceable.
- Disputed balance must be escalated, not silently fixed.
- Any mismatch must be triaged before next follow-up.

## 10. RME control visit connected guardrails

- Same patient/RM must be preserved.
- Control workflow must create a new visit.
- Old RME/odontogram/invoice must not be overwritten.
- Parent/previous receivable context must remain traceable.
- Current control invoice must remain distinguishable from parent/previous invoice.
- Cashier must be able to distinguish parent/current invoice context.
- Manual WhatsApp follow-up must not confuse parent and current invoice.
- Any cross-over issue between control visit and receivable follow-up is P0/P1 until triaged.

## 11. Privacy and consent rules

- Do not expose No. KTP in WhatsApp messages.
- Do not expose unnecessary clinical notes.
- Do not send screenshots containing sensitive clinical/payment data unless approved.
- Use minimal identity confirmation.
- Use patient name carefully.
- Avoid sending detailed itemized clinical information over WhatsApp.
- Use approved clinic phone/account only.
- Do not use personal staff phone unless clinic policy permits.
- Keep WA evidence internal and privacy-safe.
- Redact sensitive content in pilot issue reports.

## 12. Approved manual message templates

Templates must:

- Be polite.
- Avoid sensitive clinical details.
- Avoid No. KTP.
- Avoid threat language.
- Avoid exact diagnosis.
- Include branch/clinic identity.
- Include only necessary date/time/invoice/balance info where appropriate.
- State that balance confirmation follows cashier records.

### 12.1 Appointment reminder templates

- **Initial reminder:**
  > Halo {Nama Pasien}, ini pengingat dari {Nama Klinik} cabang {Cabang}. Anda memiliki jadwal kunjungan pada {Tanggal} pukul {Jam}. Mohon konfirmasi kehadiran Anda. Terima kasih.

- **Attendance confirmation:**
  > Halo {Nama Pasien}, mohon konfirmasi apakah Anda dapat hadir pada jadwal {Tanggal} pukul {Jam} di {Nama Klinik} cabang {Cabang}. Balas YA untuk konfirmasi.

- **Reschedule confirmation:**
  > Halo {Nama Pasien}, jadwal kunjungan Anda di {Nama Klinik} cabang {Cabang} telah dijadwalkan ulang ke {Tanggal Baru} pukul {Jam Baru}. Mohon konfirmasi.

- **Cancellation acknowledgement:**
  > Halo {Nama Pasien}, kami telah mencatat pembatalan jadwal kunjungan Anda di {Nama Klinik} cabang {Cabang}. Silakan hubungi kami untuk menjadwalkan kembali. Terima kasih.

### 12.2 Receivable follow-up templates

- **Friendly reminder:**
  > Halo {Nama Pasien}, ini pengingat dari {Nama Klinik} cabang {Cabang} terkait sisa pembayaran pada invoice {No. Invoice}. Sisa saldo mengikuti catatan kasir. Mohon konfirmasi rencana pembayaran. Terima kasih.

- **Second follow-up:**
  > Halo {Nama Pasien}, menindaklanjuti pengingat sebelumnya dari {Nama Klinik} cabang {Cabang} terkait invoice {No. Invoice}. Sisa saldo mengikuti catatan kasir. Mohon konfirmasi. Terima kasih.

- **Payment confirmation request:**
  > Halo {Nama Pasien}, mohon konfirmasi apakah pembayaran untuk invoice {No. Invoice} sudah dilakukan. Konfirmasi saldo mengikuti catatan kasir {Nama Klinik} cabang {Cabang}.

- **Disputed balance escalation acknowledgement:**
  > Halo {Nama Pasien}, terima kasih atas tanggapan Anda. Perbedaan saldo invoice {No. Invoice} akan kami teruskan ke kasir/supervisor untuk diperiksa. Kami akan menghubungi Anda kembali.

## 13. Manual log template

| Date/time | Sender role | Branch | Patient safe reference | WA number last 4 digits only | Lane | Invoice/visit reference if needed | Message template used | Result | Patient response summary | Escalation needed? | Follow-up owner | Privacy note |
|-----------|-------------|--------|------------------------|------------------------------|------|-----------------------------------|-----------------------|--------|--------------------------|--------------------|-----------------|--------------|
|           |             |        |                        |                              | A/B  |                                   |                       |        |                          | Yes/No             |                 |              |

## 14. Escalation rules

Escalate when any of the following occur:

- Invalid WA number.
- Wrong recipient risk.
- Patient denies balance.
- Patient says already paid.
- Patient asks for detailed medical/payment breakdown.
- Disputed balance.
- Angry/complaint response.
- Parent/current invoice confusion.
- Suspected data mismatch.
- Sensitive/privacy concern.
- Any P0/P1 cashier/payment/RME risk.

## 15. Manual pilot daily checklist

- Review scheduled visits for tomorrow/today.
- Verify WA numbers.
- Send appointment reminders.
- Record responses.
- Review receivables with remaining balance > 0.
- Exclude Rp0 and fully paid invoices.
- Verify invoice/receipt context.
- Send receivable follow-up only when safe.
- Record follow-up status.
- Escalate disputes.
- Summarize daily SOP issues.
- Prepare backlog note for Sprint 29 stabilization planning.

## 16. Future automation readiness criteria

Planning-only. Automation may be considered only when:

- Manual SOP used consistently.
- Template content approved.
- Privacy rules verified.
- WA number quality known.
- Opt-out/complaint handling defined.
- Balance verification flow stable.
- Rp0/fully paid exclusion verified.
- Parent/current invoice context stable.
- Manual logs produce useful evidence.
- Staff roles and approval flow clear.
- No unresolved P0/P1 cashier/RME issue.
- Future automation must start with technical design, not direct implementation.

## 17. Out-of-scope implementation list

- No WhatsApp API provider setup.
- No webhook setup.
- No queue/job/notification setup.
- No scheduler/cron setup.
- No controller changes.
- No model changes.
- No service changes.
- No repository changes.
- No route changes.
- No Blade/view changes.
- No migration changes.
- No seeder changes.
- No config/env changes.
- No payment allocation code changes.
- No invoice calculation code changes.
- No receivable follow-up code changes.
- No RME workflow code changes.

## 18. Risk and mitigation

| Risk | Mitigation |
|------|------------|
| Wrong recipient | Verify patient name + WA number before sending; escalate wrong recipient risk. |
| Privacy leakage | Apply privacy and consent rules; no No. KTP, no unnecessary clinical notes. |
| Staff uses unapproved template | Only approved manual message templates may be sent. |
| Follow-up sent to fully paid invoice | Fully paid invoice must not be followed up as active receivable. |
| Follow-up sent to Rp0 invoice | Rp0 invoice must not be followed up as active receivable. |
| Parent/current invoice confusion | Cashier distinguishes parent/current invoice context before follow-up. |
| Patient disputes balance | Disputed balance must be escalated, not silently fixed. |
| WA number invalid | Escalate invalid WA number; do not retry blindly. |
| Manual log incomplete | Daily checklist enforces manual log completion. |
| Staff over-messaging/spam risk | Do not spam repeated reminders; respect operational hours. |
| Scope creep into automation | Out-of-scope implementation list keeps phase docs-only. |
| GO tag created on wrong commit | GO tag created only after PR merge, on the merge commit. |

## 19. GO/NO-GO decision

**GO if:**

- Manual SOP document is complete.
- Sprint history is updated.
- Focused test passes.
- No production code changed.
- No migration.
- No deployment.
- No destructive operation.
- No WhatsApp API integration.
- No WhatsApp automation.
- No queue/job/notification change.
- No bug fix/stabilization implementation.
- No runtime behavior change.
- No RME/payment/receivable/cashier business rule change.
- Manual templates, privacy rules, logs, escalation, and automation readiness criteria are documented.

**NO-GO if:**

- Any production code is changed.
- Any migration/deploy/destructive command is introduced.
- Any WhatsApp automation or API integration is implemented.
- Any queue/job/notification behavior is introduced.
- Any fix/stabilization is implemented.
- Any runtime behavior changes.
- Any RME/payment/receivable/cashier rule changes.
- Privacy rules are missing.
- Message templates are missing.
- Manual log/escalation rules are missing.
- Sprint history/test is missing.

## 20. Safety confirmation

- No production code change.
- No migration.
- No deployment.
- No destructive operation.
- No WhatsApp API integration.
- No WhatsApp automation.
- No queue/job/notification change.
- No bug fix implementation.
- No stabilization implementation.
- No runtime behavior change.
- No route/controller/service/model/view/config/seeder change.
- No RME/payment/receivable/cashier business rule change.

## 21. Final decision

Sprint 29 Phase 29.3 posture: GO CANDIDATE FOR PR REVIEW

GO CANDIDATE FOR PR REVIEW

## 22. Validation plan

- `php artisan test --filter=Sprint29Phase293WhatsappReminderManualPilotSop`
- `vendor/bin/pint --test tests/Feature/Sprint29/Sprint29Phase293WhatsappReminderManualPilotSopTest.php`
- `git diff --check`
