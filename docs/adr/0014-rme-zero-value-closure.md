# ADR 0014 — "Selesai Tanpa Biaya": a terminal NO_CHARGE status, not a payment

- **Status:** Accepted (2026-11-01) — implemented behind flag `rme.zero_value_closure` (OFF)
- **Context:** owner decisions S6-D1 … S6-D8; rule 217; production invoice #36.

## Context

A Rp0 invoice is created `UNPAID`. A payment must be `> 0`, so it can never become `PAID`; the visit
stays `cashier_pending` unless the patient pays some other receivable in the same batch. Dashboards
counted it as "belum dibayar".

## Options considered

1. **Record a Rp0 payment row** — rejected: a fake payment pollutes the payment ledger, receipts and
   every report that equates "paid" with "money received".
2. **Mark it `PAID`** — rejected: `PAID` drives receipts, lab candidates and the doctor "Lunas" counts;
   a free visit is not a paid one.
3. **Auto-complete at issuance** — rejected by the owner (S6-D2): a human must confirm the patient is
   not charged.
4. **A new terminal status `NO_CHARGE` reached by an explicit cashier action** — chosen.

## Decision

`NO_CHARGE` is written only by `RmePaymentService::closeZeroValueInvoice()`, which sits beside `pay()` so
it takes the same invoice lock and inherits every cashier and hold guard by construction. Eligibility is a
pure evaluator (`RmeZeroValueEligibility`) over source-controlled lists. PostgreSQL CHECKs bind the
provenance columns to the status and the status to a zero total.

## Consequences

- Every reader that gates on payable / active / paid keeps working: `NO_CHARGE` is none of them.
- Label maps, DQ1 and the doctor-performance filter were extended explicitly.
- Rollback is the flag; closed invoices stay closed.
- Residual: the Kontrol batch path can still complete a Rp0 Kontrol visit through an unrelated payment
  (the #36 pattern) — recorded in rule 217 §8, not decided.
- Two readers did NOT keep working and were fixed after the independent security review: the oversight
  room-treatment view whitelisted billed statuses (a closed Rp0 treatment vanished from its day), and DQ1
  had no check that a `NO_CHARGE` invoice carries no payment row (the invariant spans two tables, so no
  CHECK can express it). Lesson: a new terminal status needs a sweep of every status WHITELIST, not only
  the payability predicates.
- Fail-closed narrowings after the review (S6-Q1 examined Kontrol parent, S6-Q2 no lab lines) are
  engineering decisions pending owner confirmation, not owner decisions.
