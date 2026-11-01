# Owner decision package — doctor performed treatments (DPT-Q2 … DPT-Q10)

**Sprint:** `FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1` (rule 219, ADR 0016).
**Status of every question below: `PENDING_OWNER_DECISION`.** Nothing here is approved, and nothing here was
implemented on an assumed answer. Where the sprint had to pick a behaviour, it picked the most conservative one
(read-only, optional, detective) and says so in the "Current behaviour" line.

Decided and NOT reopened here: CB-Q1 (structured doctor input is authoritative), CB-Q2 (detective only), CB-Q3
(separate sprint), DPT-Q1 (explicit "Tidak Ada Tindakan" = operational KONTROL; empty = NOT_RECORDED; operational
KONTROL ≠ NO_CHARGE). S6-D1…S6-D8, S6-Q1…S6-Q3b, D21, D28 unchanged.

---

## DPT-Q2 — Blocking contract for a confirmed discrepancy

**Question.** When the doctor's record shows a paid treatment that is not on a Rp0 invoice, should "Selesai Tanpa
Biaya" (a) stay allowed with a recorded signal (today), (b) require a written reason by the cashier, or (c) be
refused (`NO_CHARGE_DENIED`)? And when may rule 218 answer `CONSISTENT` (e.g. a declared "Tidak Ada Tindakan" with
no other signal)?

**Current behaviour.** (a). The signal `CBC_PERFORMED_TREATMENT_NOT_BILLED` is recorded in the closure audit
snapshot (version 2), shown to the cashier and in Oversight. Rule 218 never answers `CONSISTENT` or
`CONFIRMED_DISCREPANCY`.

**Recommendation.** Do not choose (c) until DPT-Q3 makes the declaration mandatory — otherwise a missing record
(NOT_RECORDED) and a truthful record would be treated differently in a way that rewards not recording. Re-evaluate
after one month of production data with the flag ON.

## DPT-Q3 — Is the declaration mandatory before "Selesai Pemeriksaan"?

**Question.** Should completing the examination be refused while the state is `NOT_RECORDED`?

**Current behaviour.** Optional. Completion is unchanged; the record is never a new gate.

**Implementation note.** A mandatory gate belongs in `ClinicVisitService::transitionStatus()` (the single
completion chokepoint, rule 109), behind the flag, with a backlog plan for visits already `in_progress` at the
switch-on moment.

## DPT-Q4 — Amendment after completion

**Question.** May the record be corrected after "Selesai Pemeriksaan" (a missed treatment, a wrong tooth), by whom,
and must the correction be approved?

**Current behaviour.** Read-only after completion. Inside the encounter a row is voided with a reason and
re-recorded; nothing is ever hard-deleted or edited in place.

## DPT-Q5 — Which services may be picked as a "tindakan"

**Question.** Should Konsultasi and Kontrol services (the approved-free service categories of rule 217) be
selectable as a performed treatment?

**Current behaviour (engineering choice, needs confirmation).** Excluded from the picker, together with the excluded
voucher code. Reason: a Konsultasi/Kontrol is a service, not a procedure, so "Tidak Ada Tindakan" stays truthful on
such a visit and the KONTROL classification cannot be contradicted by a row that only names the visit itself.

## DPT-Q6 — Reporting of operational KONTROL

**Question.** Which reports and KPIs should count operational KONTROL (doctor declaration) vs registration type
`visit_type = control`, and should historical KPIs be restated?

**Current behaviour.** Oversight "Tanpa Biaya (Rp0)" shows the recorded doctor state per closure and counts. No KPI
was redefined and nothing historical was reclassified.

## DPT-Q7 — D28 recovery

**Question.** If the doctor's record shows a paid treatment on a visit whose ORIGINAL invoice is Rp0, D28 forbids
correcting it and there is one active invoice per visit. Which recovery is approved (e.g. a supervised exception,
a separate receivable, or acceptance as a loss)?

**Current behaviour.** SOP only: do not close "Selesai Tanpa Biaya", record an incident, escalate to the Owner. No
SQL fix, no void, no replacement invoice, no write-off.

## DPT-Q8 — Activation

**Question.** When, where (all branches or one pilot branch) and with which training is
`rme.doctor_performed_treatments` switched on? Is it activated before, together with, or independently of
`rme.zero_value_closure`?

**Current behaviour.** Deployed OFF. Activation is the separate sprint
`ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1`. The two flags are technically independent.

## DPT-Q9 — Covering or non-assigned doctor

**Question.** If a different doctor actually treats the patient than the visit's assigned `doctor_id` (covering,
handover), who may record?

**Current behaviour.** Only the doctor linked to the visit's `doctor_id` (the identity the rest of the RME already
uses). Any other account, including Super Admin, is refused — there is no impersonation path.

## DPT-Q10 — SATUSEHAT Procedure source

**Question.** Should SATUSEHAT Procedure resources be built from the doctor's record instead of the invoice line?

**Current behaviour.** Unchanged: SATUSEHAT still maps Procedures from invoice lines.
