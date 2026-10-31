# RME refund settlement — design, threat model and reconciliation contract

Task: `FEATURE-RME-REFUND-SETTLEMENT-1` (Sprint 5/7). Durable rule: `.cursor/rules/216-rme-refund-settlement.mdc`.
Owner decisions: `docs/decisions/rme-refund-request-approval-owner-decision-package.md` §7.4 (RF-9 C, RF-10 B,
RF-11) and §7.7 (Sprint 5 implementation record).

## 1. Problem

Sprint 4 (rule 215) lets a Front Office maker file a refund request for an OPEN overpayment obligation and a
reviewer approve it. An APPROVED request is a decision, not a payment. Nothing recorded whether the money then
left the clinic, so every balance reader still counted the refunded money as received, and nothing stopped the
same refund from being paid twice.

## 2. Model

| Object | Change |
|---|---|
| `trx_rme_refund_settlements` (new) | one payout attempt for one APPROVED request |
| `trx_rme_refund_settlement_events` (new) | append-only transitions |
| `trx_rme_refund_requests` | status += `SETTLED` (APPROVED → SETTLED) |
| `trx_rme_refund_request_events` | `to_status` CHECK += `SETTLED` |
| `trx_rme_overpayment_obligations` | status += `SETTLED`; `settled_at`, `rme_refund_settlement_id` (null while OPEN) |
| `trx_rme_payments`, invoice, items, receipts, document versions | **unchanged and never written** |

Settlement states:

```
DISBURSING ──confirm(evidence; starter)──▶ SETTLED   (terminal; obligation + request SETTLED, same transaction)
    │   └──abort(starter)──────────────▶ ABORTED   (terminal; money did not move; request stays APPROVED)
    └──report unknown(any settler)──▶ OUTCOME_UNKNOWN ──confirm(evidence; any settler)──▶ SETTLED
                                            └──abort(evidence; starter only)──▶ ABORTED
```

`DISBURSING` exists so that two people cannot both start paying the same refund: the claim is made before the
money moves, and the partial unique index allows one live (non-ABORTED) claim per request and per obligation.
`OUTCOME_UNKNOWN` exists because a bank transfer can be in an uncertain state; the system never retries, and a
human reconciles against the bank statement before confirming or aborting.

## 3. Net received (RFS-1)

```
net received = Σ trx_rme_payments.amount − Σ trx_rme_refund_settlements.amount WHERE status = 'SETTLED'
remaining    = max(0, grand_total − net received)
overpaid     = max(0, net received − grand_total)
```

One definition, three forms: `RmeInvoiceLedger::netReceivedCents()` (one invoice),
`RmeInvoiceLedger::netReceivedTotalsQuery()` (grouped subquery, `rme_invoice_id, paid_total`) and
`RmeInvoiceLedger::netReceivedSql()` (correlated expression; the column argument is validated as a code
literal). Eloquent lists use `RmeInvoice::scopeWithNetReceived()` + `aggregatedNetReceived()`.

Readers moved to net in this sprint: invoice `remainingAmount()`, payment status refresh and payment caps,
post-payment correction (submit snapshot, apply, eligibility, create screen), refund request eligibility and
snapshot, receipt snapshots (`totals.refunded_cents`), document integrity checks, the cashier receivable list
(summary, aging, per-row balance) and export, the oversight visit page, the doctor performance report (money
received net of refunds paid back, per scope, doctor and treatment date), the RME dashboard, oversight active
receivables, DMO receivable metrics, the DQ "PAID with balance" audit, and both owner dashboards. Gross
"Sudah Dibayar" labels stay gross; the invoice summary states the refund paid back beside them. Revenue
(omzet) figures stay gross by payment date, as RFS-6 requires; the refund outflow is reported beside them. `paidAmount()`/`paidCents()` stay gross for display and
for the "payments unchanged" invariant.

Why every reader at once: the readers only diverge after a settled refund is followed by a later correction,
which is rare — and precisely the case where a reader left on gross would show a different balance than the
cashier sees. A partial migration would be a latent financial inconsistency.

## 4. Authority

A settler needs: a usable account, `settle_rme_refund`, a designation in `config/rme_refund_settlement.php`
(`pilot.settlers`, per user and branch codes), the branch admitted in `pilot.branch_codes`, and both flags on.
`settle_rme_refund` is excluded from every role, including Super Admin's `*`
(`RoleSeeder::NO_ROLE_PERMISSIONS`); the settler list ships empty. Owner, Finance Klinik and Data Analyst are
never settlers. Settler ≠ approver on every actor column (service + PostgreSQL CHECK). The maker may settle when
separately designated (RF-10 B).

## 5. Threat model

| Threat | Control |
|---|---|
| Approval read as payment | separate capability, permission, flag, routes; UI states "DISETUJUI ≠ DIBAYARKAN" |
| Double payout (two people, double click, retry) | DISBURSING claim; one live settlement per request and per obligation (partial unique); intent replay returns the original |
| Uncertain transfer re-sent | OUTCOME_UNKNOWN; no auto-retry; abort from it requires evidence |
| A second settler releases a payout that was sent, then pays again | only the starter may abort, from any state (review finding M1) |
| One bank proof re-spelled to prove two refunds | reference **key** (letters + digits, upper case) unique across methods among SETTLED rows |
| Proof that money did not move reused as proof that it did | an evidence hash is usable once across every settlement state |
| `approved_by` copied wrong, defeating the not-approver CHECK | composite FK (request id, approved_by) → request (id, decided_by) |
| Bank data or free text in a log after a database failure | service replaces the QueryException with SQLSTATE + ids, no `previous` |
| Approver pays own approval | service check + CHECK constraint on started/settled/escalated/aborted by |
| Super Admin bypass | no role grant; designation required; separation enforced in the service and the database, not a policy |
| One bank proof reused for two refunds | partial unique on the reference key and on evidence SHA-256 among SETTLED rows |
| Money recorded out without proof | attestation + content-sniffed evidence required; SETTLED CHECK requires every proof column |
| Backdating | `settled_at` is server time; no user date field |
| Refund recorded while the invoice is disputed or being corrected | eligibility rechecked under the locks on start and confirm |
| Revoking an approval during payout | cancel refuses while a claim is held (`REFUND_SETTLEMENT_IN_PROGRESS`) |
| Sensitive bank data leak | last four digits only; audit payload carries ids, amount, method, statuses, evidence hash |
| Evidence exposure | private disk listed in `DMS_PRIVATE_PATHS`; policy-checked download; `no-store`, `nosniff`, CSP sandbox |
| Malicious PDF opened by a colleague | heuristic refusal of active-content names (after `#xx` decoding and inside Flate streams, within a decompression budget) and of encrypted PDFs; always served as an attachment. Residual: a heuristic, not a sanitizer |
| Cross-branch access | settler branches from designation; out of scope = 404 |

## 6. Concurrency and lock order

invoice → obligation → refund request → settlement, then the actor re-read. Proven on PostgreSQL 16 by
`RmeRefundSettlementConcurrencyTest` (14 races + 6 database backstops) with the real service in a second
session (`tests/Feature/RmeInvoiceCorrection/race_child.php`).

## 7. Reporting (RFS-6)

For a WITA period and the invoice's branch: gross incoming (payments by `paid_at`), refund outflow (SETTLED by
`settled_at`), net = gross − outflow. Open obligations, approved-unpaid requests and in-flight payouts are
point-in-time liabilities, shown apart. No closed period is rewritten: a settlement is dated by its own server
time, never back into the period of the original payment.

## 8. Rollback

Turning `rme.billing_refund_settlement` off makes every operational route answer 404 and the services refuse.
Recorded settlements stay readable in oversight. The migrations refuse `down()` while a settlement row or a
SETTLED request/obligation exists: a settlement is a financial fact.
