# ADR 0015 — Clinical vs billing cross-check of Rp0 closures: detective, because nothing clinical is authoritative yet

- **Status:** Accepted (2026-11-01) — implemented; rides flag `rme.zero_value_closure` (OFF)
- **Context:** rule 217 §8 residual MEDIUM-1; owner decisions CB-Q1, CB-Q2, CB-Q3; rule 218.

## Context

Under D21 the cashier chooses each invoice line. A performed paid procedure can therefore be billed as
"Konsultasi Dokter" Rp0 and closed "Selesai Tanpa Biaya". The obvious fix — reconcile the invoice with the
clinical record and refuse the closure on a mismatch — needs a clinical record that says which treatments were
performed. The audit found none:

- the doctor's primary input is a handwritten RME image;
- the odontogram is a tooth-condition chart; its "perawatan" column is free text;
- structured diagnoses are diagnoses, and production holds none;
- the registered treatment ("Tindakan Awal") is triage and differs from the bill on 6 of 10 legitimate
  production invoices;
- the only structured procedure identity is the invoice line itself (SATUSEHAT reuses it).

Graphify shows no domain dependency between the clinical models and `Treatment` / `RmeInvoiceItem`.

## Options considered

1. **Block NO_CHARGE when the registered treatment is a paid one and not billed** — rejected: on production data
   it would mis-flag most genuine visits, and Tindakan Awal is not evidence of performance (CB-Q1).
2. **Infer procedures from free text** (RME notes, odontogram notes) — rejected: unreliable, and it would turn
   ambiguous clinical prose into a financial obligation.
3. **Build a structured "Tindakan Dilakukan" doctor input now and block on confirmed discrepancies** — the right
   end state (CB-Q1), but it changes the examination workflow; deferred to its own sprint (CB-Q3).
4. **Detective cross-check now** (chosen, CB-Q2) — deterministic signals from structured rows only, recorded in
   the closure audit row, shown in oversight and as a non-blocking cashier notice; never a refusal.

## Decision

Option 4. The evaluator is pure and reuses rule 217's single free-service predicate. Its vocabulary is
`REVIEW_REQUIRED`, `INSUFFICIENT_STRUCTURED_EVIDENCE`, `NOT_APPLICABLE`; it never says `CONSISTENT` or
`CONFIRMED_DISCREPANCY`, because no authoritative source exists to confirm either. The result is stored in the
existing `sys_audit_logs` row (jsonb) rather than in new invoice columns or a new table, so the financial schema is
unchanged and oversight shows the state AT closure.

## Consequences

- MEDIUM-1 becomes `PARTIALLY_MITIGATED`: visible where a structured signal exists, never prevented; a procedure
  written only by hand raises nothing.
- No migration, no permission, no route, no new flag; flag OFF leaves production behaviour unchanged.
- The CB-Q1 sprint must add the structured input, decide its blocking contract, and only then may a
  `CONFIRMED_DISCREPANCY` status exist.
