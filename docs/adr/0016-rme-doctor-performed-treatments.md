# ADR 0016 — Doctor "Tindakan Dilakukan": a structured clinical record, separate from billing, with an explicit "Tidak Ada Tindakan"

- **Status:** Accepted (2026-11-01) — implemented behind flag `rme.doctor_performed_treatments` (OFF)
- **Context:** owner decisions CB-Q1, CB-Q3, DPT-Q1 (revised and approved); rules 217, 218, 219; ADR 0015.

## Context

ADR 0015 made the clinical vs billing cross-check of Rp0 closures detective because DaengtisiaMS had no
authoritative record of a treatment the doctor PERFORMED. Owner CB-Q1 named the missing source — a structured doctor
input chosen from the treatment master on the RME page — and CB-Q3 put it in its own sprint because it touches the
examination workflow. DPT-Q1 then decided what the absence of a treatment means: only an EXPLICIT "Tidak Ada
Tindakan" counts, and it classifies the visit as operational KONTROL.

Constraints carried in: D21 (the cashier prices lines manually), D28 (a Rp0-original invoice is never corrected),
rule 217 (Rp0 eligibility, S6-Q1 real parent for a Kontrol), rule 218 (detective only, CB-Q2), the consent gate and
explicit "Selesai Pemeriksaan" (rule 109), room and patient scope (rule 138), one-account identity (rule 199).

## Options considered

1. **Reuse invoice lines or Tindakan Awal as the record** — rejected: they are the cashier's choice and a triage
   choice; CB-Q1 says neither is evidence of performance.
2. **Parse the handwritten RME / odontogram free text** — rejected (ADR 0015 option 2).
3. **A boolean on the visit ("ada tindakan?")** — rejected: it cannot say WHICH treatment, so it cannot feed the
   billing cross-check, and a default value would turn silence into a declaration.
4. **Append-only performed-treatment rows + a separate declaration row, derived state** (chosen). The rows carry a
   stable `treatment_id` plus snapshots; the declaration makes "Tidak Ada Tindakan" an explicit, versioned act; the
   state is derived (`HAS_PERFORMED_TREATMENTS`, `NO_TREATMENT_DECLARED`, `NOT_RECORDED`), so an empty list can never
   masquerade as a declaration.
5. **Make the declaration mandatory and block NO_CHARGE on a confirmed discrepancy now** — deferred: the blocking
   contract and the mandatory gate are owner questions (DPT-Q2, DPT-Q3); building them on an assumed answer would
   change completion for every visit. The record ships optional and detective.

## Decision

- New bounded context `App\Modules\PerformedTreatment`, two tables, one additive migration. No patient, branch or
  price column: patient and branch derive from the visit; a clinical row is never a financial record.
- One authority, `RmePerformedTreatmentService`: flag, account + ability (`ClinicVisitActorGuard`), the visit's own
  treating doctor (`DoctorIdentityResolver`, no impersonation), and the encounter authority
  (`RmeVisitConsentService::assertPerformedTreatmentAuthoringAllowed`). Every write locks the visit row first and
  re-checks status, consent and identity after the lock; the examination-completion UPDATE contends on the same row.
- The service never transitions the visit and does not depend on `ClinicVisitService`.
- Operational KONTROL is a classification only: `visit_type` is never rewritten, no parent visit is created, and
  `OPERATIONAL_CONTROL != AUTOMATIC_NO_CHARGE` — rule 217 eligibility does not read the record.
- Rule 218 gains `CBC_PERFORMED_TREATMENT_NOT_BILLED` and a version-2 snapshot with `doctor_evidence` when the flag
  is ON; with the flag OFF the evaluator receives `null` and stays version 1 byte for byte. Still detective.
- The cashier sees a read-only projection (code, name, quantity, tooth count, billed/unbilled); oversight shows the
  recorded doctor state per closure.

## Consequences

- The CB-Q1 source exists, so a future blocking contract has something authoritative to stand on; until DPT-Q2 and
  DPT-Q3 are answered and the flag is ON, MEDIUM-1 stays `PARTIALLY_MITIGATED`.
- A treatment performed but not recorded still raises no signal while the declaration is optional.
- The record is read-only after "Selesai Pemeriksaan" (amendment is DPT-Q4); mistakes inside the encounter are
  voided with a reason, never edited or deleted.
- D28 still has no recovery path for a recorded paid treatment on a Rp0-original invoice (DPT-Q7).
- Two more tables enter patient-merge activity detection; neither carries a patient FK.
- Rollback is the flag; rows are clinical evidence and are never dropped.
