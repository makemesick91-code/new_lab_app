# RME Refund — Financial Reconciliation Contract (Sprint 4/7, forward to 5/7)

> **Status: CONTRACT for a future implementation.** It states the invariants a refund implementation must
> hold and how they are checked. It extends the correction reconciliation
> (`docs/operations/rme-invoice-correction-financial-reconciliation.md`, ledger invariants FI-1…FI-4 in
> `RmeInvoiceCorrectionService`) and does not replace it. Amounts are compared in **integer cents**.

## 1. Sources of truth

| Fact | Source | Mutable? |
|---|---|---|
| Money received | `Σ trx_rme_payments.amount` per invoice | append-only (AIR-RMB-06) |
| What the invoice should cost now | `trx_rme_invoices.grand_total` at `correction_version` | only through the correction engine |
| Money owed back | `trx_rme_overpayment_obligations.amount` (status `OPEN`) | immutable row |
| Decision to pay it back (Sprint 4) | `trx_rme_refund_requests` (`APPROVED`) | status through the refund service only |
| Money actually paid back (Sprint 5) | settlement record (`SETTLED`) | append-only |

Refund state is **never** inferred from free text, a printed document or an audit message.

## 2. Invariants that hold today and after Sprint 4

| ID | Invariant | Check |
|---|---|---|
| **RFI-1** | For every `OPEN` obligation `O` of invoice `I` with `I.correction_version = O.invoice_version`: `cents(O.amount) = Σpayments(I) − cents(I.grand_total)` | read-only SQL / probe |
| **RFI-2** | At most one `OPEN` obligation per invoice | partial UNIQUE `trx_rme_ovp_one_open_uq` + query |
| **RFI-3** | `Σpayments(I) > grand_total(I)` ⇔ `I` has exactly one `OPEN` obligation (until Sprint 5, §3) | query (production 2026-10-31: 0 overpaid invoices, 0 overpaid without obligation) |
| **RFI-4** | Every refund request references an obligation of the same invoice and branch | join query |
| **RFI-5** | Every request's `amount = obligation_amount_snapshot = cents(obligation.amount)` [RF-4 A] | CHECK + join |
| **RFI-6** | At most one `REQUESTED`/`APPROVED` request per obligation | partial UNIQUE + query |
| **RFI-7** | A `REQUESTED`/`APPROVED` request references an `OPEN` obligation | join query |
| **RFI-8** | **No refund action changes the ledger**: before/after fingerprint identical — payments (count, sum, max id), invoices (status counts, Σ grand_total, Σ correction_version), obligations (count, Σ amount, status counts), corrections (type/status counts), receipt snapshots (count), document versions (count) | test RR-LEDGER-*, production delta check |
| **RFI-9** | For every `APPROVED`/`REJECTED` request `decided_by ≠ requested_by` | CHECK + query |
| **RFI-10** | Every `APPROVED` request has a non-null Owner reference that, compared case-folded, appears on no other refund request and on no post-payment correction of any invoice; every consumed reference appears exactly once in the shared registry, scoped to that refund request (and every post-payment correction's reference scoped to its invoice) (migration plan §2a) | `lower()` partial UNIQUE + registry UNIQUE + join |
| **RFI-11** | Event chain: first event `null → REQUESTED`; latest event `to_status` = row status | query |
| **RFI-12** | KPI Omzet (`Σ payments by paid_at`, WITA) and piutang are unaffected by any request state | oversight test RR-OVS-03 |

`RFI-1…RFI-3` are checkable now (no refund code needed) and are the content of the proposed read-only
probe `READ-ONLY-RME-OBLIGATION-RECONCILIATION-PROBE-1`.

### Reporting figures in Sprint 4

```
Kelebihan bayar terutang (OPEN obligations, as of now)        = Σ cents(O.amount)       WHERE O.status = OPEN
  of which "Refund diajukan"                                   = Σ request.amount        WHERE status = REQUESTED
  of which "Refund disetujui — belum dibayar"                  = Σ request.amount        WHERE status = APPROVED
  of which "Belum diajukan"                                    = remainder
```

All attributed to the **invoice branch**; none is deducted from Omzet or added to piutang (PP-6).
`REJECTED`, `CANCELLED`, `STALE` requests do not reduce the amount owed: the obligation stays `OPEN`.

## 3. Forward contract for Sprint 5 (binding on its design)

| ID | Invariant |
|---|---|
| **RFS-1** | `net_received(I) = Σpayments(I) − Σ settled refund amounts(I)` |
| **RFS-2** | `overpaid'(I) = max(0, net_received(I) − grand_total(I))`; RFI-3 becomes: `overpaid'(I) > 0` ⇔ one `OPEN` obligation |
| **RFS-3** | A settlement exists only for an `APPROVED` request; at most one per request **and at most one per obligation**, each backed by a UNIQUE index; amount = request amount |
| **RFS-4** | Settling moves the obligation `OPEN → SETTLED` in the same transaction; afterwards `overpaid'(I) = 0` and the invoice stays `PAID` |
| **RFS-5** | Payments are still never written, re-amounted or deleted |
| **RFS-6** | Model C by `settled_at` (WITA): `Net = Gross − Settled refunds` per branch; closed periods never restated |
| **RFS-7** | Settler ≠ approver per account [RF-10]; settlement instant per RF-11 |

`RmeInvoiceLedger::overpaidCents()` and every caller (correction apply, the PP-3 gates) must move to the
RFS-1 definition in Sprint 5, in one change, with a test that a settled refund does not reopen the
"overpaid" state — otherwise a settled obligation would look like a new overpayment.

## 4. Production reconciliation procedure (after activation; read-only)

1. Take the RFI-8 fingerprint immediately before the operator action (real payments land at any time).
2. After the action, recompute; the only permitted deltas for one approval are: `trx_rme_refund_requests`
   one row changed `REQUESTED → APPROVED`, `trx_rme_refund_request_events` +1, `sys_audit_logs` +1.
   Every other fingerprint component identical.
3. Run RFI-1…RFI-11 for the affected invoice; record PASS/FAIL per invariant, ids and cents only.
4. Never create an invoice, payment, correction, dispute, obligation or refund request to "prove" a rule.
