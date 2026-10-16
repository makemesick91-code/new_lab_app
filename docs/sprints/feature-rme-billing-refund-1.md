# FEATURE-RME-BILLING-REFUND-1 — Billing Refund (Phase 0 shipped; refund execution not built)

> **Release scope: Phase 0 financial safety prerequisites only.** Refund request, approval, settlement, oversight and
> reporting are **NOT IMPLEMENTED** and **NOT ACTIVE**. The refund feature GO tag `feature-rme-billing-refund-1-go`
> is **not** created by this sprint and must not be created until refund execution is authorised, built, deployed and
> verified. This sprint's tag is `feature-rme-billing-refund-1-phase-0-financial-prerequisites-go`.
>
> Baseline: canonical base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `65ec50f9`
> (= `audit-rme-billing-correction-payment-refund-foundation-1-go`); production runtime `3b1e03e0` at start.
> Design: `docs/architecture/rme-billing-refund-technical-design.md`. Rules: 188 (updated), **189** (new).

---

## 1. Decision gate (audit §26) — outcome

The owner asked to prioritise refund. The register was read in full; every decision is still marked
**"Perlu owner = YA"** and no later record decides any of them (searched `docs/`, `.cursor/rules/`, `CLAUDE.md`,
commits since the audit; the only `D7 … APPROVED` hits belong to the separate KTP-OCR register).

| Code | Meaning (verbatim from the register) | Status |
|---|---|---|
| D4 | Super Admin wajib untuk refund | PENDING — recommended: yes, for approval |
| D5 | Metode refund | PENDING — *printed* text: transfer at month end; recommended: cash + transfer, reference required |
| D6 | Kredit saldo pasien | PENDING — recommended: not in early phases |
| D7 | Kebijakan versi kwitansi | PENDING — recommended: snapshot on correction + DIGANTIKAN/REVISI |
| D8 | Efek koreksi ke omzet historis | PENDING — recommended: never restate closed periods |
| D9 | Bruto vs bersih | PENDING — recommended: Model C |
| D12 | Koreksi invoice dibayar sebagian | PENDING — recommended: delta from ledger, status re-derived |
| D18 | Eksekusi refund butuh izin terpisah dari approval | PENDING — recommended: yes |
| Prereq D1, D2, D10, D11, D21 | pre/post-payment approver, payment block during correction, Rp0 per item, price authority | PENDING |
| Prereq D22, D23 | Rp0 completion path / zombie #36; correction of `requires_lab` items | PENDING |

The brief's recommended defaults (Kasir requests, Super Admin approves, maker ≠ checker, immutable payments, separate
settlement, partial + full refunds, read-only Finance Klinik / Data Analyst, Model C) were treated as **proposed
defaults, not approvals**, as instructed. They are encoded in the design as PROPOSED.

**Structural finding (independent of the decisions).** Every payment path caps the amount at the remaining balance and
invoice totals never change after creation, so `Σ payments ≤ grand_total` always holds (production: 0 overpaid of 6
invoices). The server-derived refundable amount of every invoice is therefore **0**, and every real refund cause
(price reduction after payment, clinical cancellation such as the printed Kawat Gigi policy) needs **invoice
correction (Phase C)** and **receipt snapshot (Phase E)** first. A payment-entry error is a *reversal*, not a refund.
Design §1 has the full scenario table.

→ Refund execution: **not built, not deployed, not activated.** Only safe, decision-free prerequisites ship.

---

## 2. What shipped — Phase 0 financial safety prerequisites

All four are fully defined by existing rules or by fact (no owner policy chosen), and have zero observed production
blast radius (read-only measurement below).

| ID | Prerequisite | Implementation |
|---|---|---|
| **BCF-02** | Financial audit log | `RME_INVOICE_CREATED` (in `RmeInvoiceService::create`) and `RME_PAYMENT_RECORDED` (in `RmePaymentService::recordPayment`, the single payment writer → covers `pay`, control allocation and visit allocation). Written via `AuditLogService` **inside** the fact's transaction (commit/rollback together). Vocabulary + PII-free payloads in `App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent`: ids, numbers, amounts, statuses, counts, batch uuid, `reference_recorded` boolean — never patient name, item description, notes or the reference text. |
| **BCF-08** | Duplicate-invoice race guard | `create()` now locks the visit row with `FOR NO KEY UPDATE` at the start of its transaction and every gate reads the **locked** row, never the caller's instance. `FOR UPDATE` was rejected: it conflicts with the `FOR KEY SHARE` of foreign-key inserts and would deadlock against the diagnosis writer. |
| **BCF-03** (upper bound only) | `paid_at` cannot be in the future | `RmePaymentService::normalizePaidAt()` runs at the top of all three payment paths; refuses blank and unparseable values, converts to the application timezone, then refuses `paid_at > now + 60 s` (`paid_at` field error). Form input (no offset) is stored exactly as before; an offset-bearing value is stored as its true instant. **Backdating is not bounded** — that is D24. |
| **BCF-09** | Payment immutability | `RmePayment` model guard: `updating` refuses any change except `patient_id`/`updated_at`; `deleting` refuses soft and force delete. Patient merge is unaffected (it writes via the query builder and only `patient_id`). Raw SQL is not stopped — application-layer guard, stated honestly. |

**Not shipped from Phase 0:** BCF-01 price/discount authority — it *is* a tariff/discount policy (D21) and was not
invented. BCF-07 (numbering lock) is optional and unrelated to refund.

### 2.1 Production measurement (read-only, 2026-10-16 WITA)
`BEGIN TRANSACTION READ ONLY`, sentinel `transaction_read_only=on`, `ROLLBACK`, aggregates only, temp file removed:
payments 7 (0 soft-deleted); invoices 6 (PAID 5, UNPAID 1); `paid_at − created_at`: **7 of 7 within 5 minutes**, 0
future, 0 backdated; visits with > 1 invoice 0; negative line subtotals 0; Rp0 invoices 1 (#36); overpaid invoices
**0**; RME audit rows **0**; multi-invoice batches 0. `paid_at` column is `timestamp without time zone`.

**DataViz (form selection, step 1 of the skill's procedure):** the data's job is "a handful of headline numbers" —
n = 7 payments and every distribution collapses to one bucket — so the correct form is a KPI row / table, **not a
chart**. No colour was assigned, so no palette needed validating. Charts become appropriate only once Model C
reporting has real refund volume over time (design §8).

---

## 3. Final statuses

```
FINANCIAL_FOUNDATION     : PASS   (Phase 0: BCF-02, BCF-08, BCF-03 upper bound, BCF-09)
REFUND_REQUEST           : NOT_IMPLEMENTED
REFUND_APPROVAL          : NOT_IMPLEMENTED
REFUND_SETTLEMENT        : NOT_IMPLEMENTED
REFUND_REPORTING         : NOT_IMPLEMENTED
ROLE_AUTHORIZATION       : PASS   (no permission/role changed; Finance Klinik / Data Analyst untouched)
FULL_SUITE               : SKIPPED (temporary Full-Suite policy ACTIVE; never claimed passed)
FINAL_DECISION           : CONDITIONAL_GO — Phase 0 GO; refund NO_GO until §4 decisions + Phase C/E
```

CI, merge, deploy and production evidence: §6 (appended at release).

---

## 4. Decision request to the owner

To build refund execution the owner must decide, at minimum:

1. **D4** — who approves a refund (recommended: Super Admin, never the requester).
2. **D18** + **D25 (new)** — is settlement a separate permission (recommended: yes), and must the settler be a different
   account from the approver?
3. **D5** — settlement methods (cash, bank transfer) and whether a transfer reference is mandatory.
4. **D6** — patient credit balance instead of cash refund (recommended: not now).
5. **D7** — receipt versioning on correction (snapshot + DIGANTIKAN/REVISI).
6. **D8 / D9** — reporting: keep historical gross untouched, report settled refunds separately (Model C)?
7. **D12** — correction of a partially paid invoice.
8. **D13 / D16** — refund branch attribution = invoice branch; cancellation before settlement.
9. **D2, D10, D21** — the correction (Phase A/C) decisions refund depends on.

New decisions found by this sprint (register continues the audit's numbering):

| Code | Decision | Why it matters |
|---|---|---|
| **D24** | `paid_at` lower bound / backdating window | Phase 0 bounds only the future; how far a cashier may backdate (and whether before invoice creation) is policy |
| **D25** | Settler must be a different account from the approver? | completes the cash segregation D18 begins |
| **D26** | Payment reversal (payment-entry error) — authority and semantics | a reversal moves no money and is **not** a refund; it needs its own audited event |
| **D27** | `paid_at` entry/display timezone | the form shows the application (UTC) clock to WITA cashiers; production shows nobody edits it today (7/7 default), but an edited local time would be refused by the new bound |

---

## 5. Tests

* `tests/Feature/RME/RmeBillingRefundPhase0FinancialPrerequisitesTest.php` — 26 tests (audit payloads, PII-free,
  refused-means-unaudited, stale-instance gate, carry-over and control batches, `paid_at` on all three paths, HTTP field
  error, tolerance, backdating explicitly unbounded, timezone unchanged, immutability per column, delete/forceDelete,
  merge still works).
* `tests/Feature/RME/RmeBillingRefundPhase0InvoiceRaceConcurrencyTest.php` — PostgreSQL 16 two-session race proof
  (skips elsewhere, stated).
* Mutation: **18 mutants, 17 killed, 1 equivalent** (a redundant `refresh()` the repository already performs — removed).
  Post-review mutants: lock removed (killed by the race test), `lockForUpdate()` restored (killed by the FK-writer test),
  timezone conversion removed (killed — reproduces the stored-14-h-ahead bypass), blank check removed (killed).
  The PostgreSQL race test first **survived** the lock-removal mutant: the probe's explicit `FOR UPDATE` blocked the
  broken code's INSERT through the foreign-key `FOR KEY SHARE`, so it passed for the wrong reason. The probe now
  models the pre-fix request (insert only); the mutant is killed.
* Both suites declared in `config/ci_runner.php` `critical_gate_mandatory_suites` and selected by the new
  `RmeBillingRefund` token in **both** critical-gate variants.

---

### 5.1 Independent adversarial review — findings and dispositions
| Sev | Finding | Disposition |
|---|---|---|
| MEDIUM | Offset bypass: `…T22:00+14:00` passed the instant check but was stored 14 h ahead (Eloquent keeps the offset's wall clock) | **Fixed** — normalize to app timezone before check and store; 2 tests |
| LOW | Lock-order inversion: `FOR UPDATE` on the visit vs the diagnosis writer's FK key-share → possible 40P01 | **Fixed** — `FOR NO KEY UPDATE`; PostgreSQL FK-writer test added |
| LOW | Blank `paid_at` parsed to "now" | **Fixed** — refused; 3-case dataset |
| LOW | A cashier typing WITA wall time into the UTC-defaulted field is refused | **Accepted, recorded as D27**; the message now points to the pre-filled value |
| INFO | `*Quietly` / `withoutEvents` / builder updates bypass the model guard | stated limit (rule 189 §6) |
| — | No existing write path broken; no deadlock beyond the above; no PII; no other entry point; no authorization/branch regression | confirmed |

Regression consequence: four fixture sites in two ClinicOversight KPI test files rewrote payments through Eloquent —
three soft-deletes (to prove KPIs exclude soft-deleted rows) and one re-branch (to prove attribution follows the invoice
branch). The guard now refuses both, by design. The fixtures write the columns directly
(`covWritePaymentRowDirectly()` / `covSoftDeletePayment()`) — the way such rows actually arise — and every assertion
is unchanged.

## 6. Release evidence

Appended after CI, merge, deploy and production verification.
