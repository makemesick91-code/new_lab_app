# Proposal — controlled recovery for an incorrect Rp0 ORIGINAL invoice (DPT-Q7)

**Status: `PROPOSAL_NOT_APPROVED`.** Nothing in this file is approved, implemented or authorized. It exists because
the Owner approved the DIRECTION of DPT-Q7 on 2026-11-02 ("a dedicated, controlled recovery solution is needed")
and stated that this approval does not authorize any particular financial operation. Register:
`docs/decisions/rme-doctor-performed-treatments-owner-decision-package.md` (DPT-Q7).

## 1. The problem, precisely

A visit's ORIGINAL invoice is Rp0, and later evidence — typically the treating doctor's "Tindakan Dilakukan"
record (rule 219) — shows a treatment that should have been billed.

- **D28** (rule 201) refuses any correction of a Rp0 ORIGINAL invoice (`CORRECTION_ORIGINAL_TOTAL_ZERO`).
- There is **one active invoice per visit**, and an RME is never voided.
- So today there is **no in-app path** to recover the amount. The SOP (rule 219 §10, rule 218 §8) is: do not close
  "Selesai Tanpa Biaya", record an incident, escalate to the Owner.

## 2. What any recovery must preserve (non-negotiable)

1. The ORIGINAL invoice, its snapshot and its history stay reconstructable — never rewritten in place.
2. No direct SQL, no silent edit, no deletion.
3. Maker ≠ checker per account (rule 197 pattern); a case-specific Owner reference (rule 214 registry) if money moves.
4. The patient is never held at the clinic for it (OPS-OQ-1).
5. D21 manual pricing, disputes (rule 203), refunds (rules 215–216) and KPIs keep their meaning.
6. Branch isolation: the invoice's own branch owns any new obligation.
7. One audit row per mutation; no PII in payloads.

## 3. Candidate directions (for the Owner to choose between — none chosen)

| # | Direction | Shape | Main open question |
|---|---|---|---|
| A | Supervised exception to D28 | the existing correction flow, admitted for a Rp0 original only with a case-specific Owner reference and a checker | does a correction of a Rp0 original change the meaning of D21g/D22? |
| B | Separate supplementary receivable | a new, explicitly linked obligation against the visit, original invoice untouched | how is it reported so it is never double-counted as visit revenue? |
| C | Documented acceptance as a loss | an Owner-approved record that the amount is not collected; no financial row changes | who may approve, and how is it audited and reported? |

Any of the three needs its own engineering contract, TDD, PostgreSQL concurrency proof, security review and an
explicit Owner decision before a single row is written.

## 4. Explicitly NOT authorized by DPT-Q7

Invoice void · replacement invoice · artificial receivable · write-off · refund creation · direct financial SQL ·
changing a historical invoice snapshot · forcing NO_CHARGE.

## 5. Inputs the Owner would need

- How often the situation occurs (it can only be measured once `rme.doctor_performed_treatments` is ON and signals
  `CBC_PERFORMED_TREATMENT_NOT_BILLED` accumulate; the pilot is detective).
- Whether the amounts involved justify A/B over C.
- Whether the patient must be informed and consent to a supplementary charge (B).
