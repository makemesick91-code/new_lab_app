---
kb_status: historical
kb_verified_against: 3a231c28
kb_updated: 2026-11-20
---

# RME billing — posture snapshot (2026-10-25)

Moved verbatim on 2026-11-20 from `.claude/rules/rme-billing.md` (commit `3a231c28`) so that the domain rule file
stays within its 30,000-character budget (RME-LAB-FEE-APPROVAL-1, owner decision A-15). Historical snapshot:
verify every statement against the code before relying on it. Back to [00-INDEX](../00-INDEX.md).

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- `rme.invoice_correction` **ON** as a pilot (SPN4, one maker + one checker), `PILOT_ACTIVE`, probe WATCH. Full pilot
  GO **NO_GO**: the one real correction reconciles (PASS RECONSTRUCTED) but `OWNER_CASE_APPROVAL=UNVERIFIED` and
  `HUMAN_SEPARATION=RED_FLAG_UNRESOLVED`; the closure tag does not exist.
- `rme.invoice_postpayment_correction` **OFF** (`IMPLEMENTATION_GO / FLAG_OFF`, deployed `b7ffa92b`); PP-1…PP-10
  ratified; activation needs its own owner decision (PP-10), blocked while the pre-payment pilot is NO_GO.
- Receipt snapshots written by every payment regardless of flags; none observed live as of 2026-10-26.
- Dispute hold live on all branches; one SPN4 reviewer designation as of 2026-10-25. Payment idempotency live; older
  payments have no submission row. Pricing MANUAL everywhere; strict tariff mode and price exceptions OFF.
- **Deployed, flag OFF:** refund request & approval (rule 215; D4 via RF-1, D18 via RF-2; runtime `9826bd69`, 2026-10-31;
  identity allowlist EMPTY, nobody can act); refund settlement (rule 216; D5 via RF-9, D25 via RF-10; runtime
  `a550e00d`, 2026-10-31; settler list EMPTY, permission held by no role, nobody can settle); Owner reference
  registry (rule 214, `61450801`); **"Selesai Tanpa Biaya"** (rule 217, Sprint 6/7, owner decisions S6-D1…S6-D8
  of 2026-11-01): a legitimate Rp0 Konsultasi / Kontrol invoice closes to the terminal status `NO_CHARGE` (never
  PAID, no payment row) and completes its visit — runtime `4520a276`, 2026-11-01, flag `rme.zero_value_closure`
  OFF, activation a separate Owner decision. **Hardening 2026-11-01 (rule 217 §9):** S6-Q1/S6-Q2 APPROVED, S6-Q3
  REVISED AND APPROVED — a Kontrol may be Rp0 but a normally paid treatment on it is billed > Rp0 and paid normally
  (mixed invoice ≠ NO_CHARGE); approved free services = `consultation` category + reserved `control` category on a
  valid Kontrol; flag-gated Kontrol issuance guard; batch-path #36 residual resolved for closable invoices; read-only
  oversight "Tanpa Biaya (Rp0)"; S6-D3 preserved (no creator ≠ closer) — runtime `ba33f9bb`, deployed and verified
  2026-11-01, flag still OFF; MEDIUM-1 NARROWED, not closed. **Rule 218 (owner CB-Q1/Q2/Q3, 2026-11-01):** a
  DETECTIVE clinical vs billing cross-check is recorded with every NO_CHARGE closure and shown in oversight; a signal
  never refuses (a fault fails closed) — runtime `1043b815`, deployed 2026-11-01, flag OFF; MEDIUM-1 `PARTIALLY_MITIGATED`. **Rule 219 (CB-Q1 input, DPT-Q1, 2026-11-01):** the treating doctor's structured "Tindakan Dilakukan" record (flag `rme.doctor_performed_treatments`, OFF) feeds rule 218 as signal `CBC_PERFORMED_TREATMENT_NOT_BILLED` (snapshot v2); "Tidak Ada Tindakan" = operational KONTROL, which is NEVER NO_CHARGE; still detective, MEDIUM-1 still `PARTIALLY_MITIGATED`. **DPT-Q2…Q10 ratified 2026-11-02:** NO_CHARGE blocking by
  doctor evidence is a STAGED later step (not implemented); D28 recovery direction approved but no operation
  authorized; pilot SPN4 only (rule 219 §15). **Not built:** reversal (D26 excluded), patient credit (D6 excluded). **PENDING owner decisions:** D21c
  discount policy, D21g/D22 for Rp0 **issuance**, vouchers and any Rp0 correction (answered for the closure scope
  only; D28 unchanged), D24 `paid_at` backdating. The historical Rp0 UNPAID SPN4 invoice #36 stays untouched (S6-D2).
- Open owner questions: a correction settling a PARTIAL invoice leaves no LUNAS receipt; whether a pre-payment approval
  reference becomes mandatory.
- Residuals: `branchIdsFor(null)` still answers every RME branch (`RmeInvoiceController::rmeBranches()` and
  `RmeReportController` rely on `auth`); a failed lab-candidate generation has no retry (OD-7-5, rule 225).
