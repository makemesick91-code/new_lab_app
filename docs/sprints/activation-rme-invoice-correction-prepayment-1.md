# ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1

**Status: ACTIVATION NO_GO — prerequisite release only.** Owner decisions D1, D7, D10 and D21
are still PENDING, so the correction capability stays OFF and no operational role holds a
correction permission. This release ships the single activation prerequisite that needs no
owner decision: every RME payment is now bound to the invoice `correction_version` the cashier
saw. Rule: `.cursor/rules/191-rme-invoice-correction-prepayment-activation.mdc`.

- Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `0b2c23bb`
  (docs-only evidence on top of the deployed runtime `03fe25b1`, tag
  `feature-rme-invoice-correction-receipt-foundation-1-prepayment-foundation-go`).
- No migration. No seeder change. No permission change. No route change. No flag change.

## 1. Baseline (measured 2026-10-17, read-only)

| Item | Value |
|---|---|
| Production HEAD | `03fe25b1` = foundation scoped GO tag, working tree clean (one untracked runtime HOME directory, not task-owned) |
| `rme.invoice_correction` | `enabled=false`, `env_resolution=default`, captured, config cached |
| Operational permissions | `request_/approve_/apply_rme_invoice_correction` → **Super Admin only** (via `'*'`); 0 direct user grants |
| `view_billing_correction_oversight` | Data Analyst, Finance Klinik, Super Admin |
| Corrections / events / document versions | 0 / 0 / 0 |
| Invoices / corrected / payments | 6 (5 PAID, 1 UNPAID) / 0 / 7 |
| Σ grand_total = Σ paid | Rp 3.548.000 = Rp 3.548.000 |

## 2. Owner decision activation matrix

Source of every definition: `docs/AUDIT-RME-BILLING-CORRECTION-PAYMENT-REFUND-FOUNDATION-1.md` §26.
No decision was recorded after the foundation release; the only "D7 APPROVED" hits in the
repository belong to the separate KTP-OCR register.

| Code | Exact question | Status | Evidence | Required technical behaviour once decided | Blocks activation | Safe default now |
|---|---|---|---|---|---|---|
| D1 | Who approves a PRE-payment correction (proposed: Admin Klinik, a different account than the maker) | **PENDING** | none | `RoleSeeder` grant of `approve_rme_invoice_correction` (+ `request_` to the cashier role, `apply_` to the decided role); maker ≠ checker already enforced per account in the service | YES | permissions granted to no operational role |
| D7 | Receipt versioning policy on correction (proposed: snapshot + DIGANTIKAN/REVISI) | **PENDING** | none | already safe: v0 ORIGINAL / vN REVISION frozen, only UNPAID invoices correctable so no issued receipt is ever affected; the owner must confirm the wording/stamp | YES (policy confirmation) | frozen versions, no receipt rewritten |
| D10 | Does a PENDING correction block payment (proposed: yes, block; plus STALE) | **PENDING** | none | if YES: refuse payment while a SUBMITTED/APPROVED correction exists (not built). Either way: payment bound to the applied version — **built in this release** | YES | payment accepted; correction goes STALE |
| D21 | Price authority — tariff vs cashier input (proposed: prefill + validate `mst_tariffs`, deviation needs a reason) | **PENDING** | none | central server-side price resolution for invoice creation AND correction lines; deviation reason; per-branch tariffs | YES | same validation as invoice creation (active treatment, non-negative, discount ≤ gross); Rp0 refused |
| D23 | `requires_lab` items on correction | PENDING | none | — | no (already refused) | invoices with lab candidates refused / STALE |

Proposed policies in the activation brief (Kasir submits, an authorized checker approves, the
maker never approves, no payment allowed, versions preserved, current version authoritative,
price/discount changes authorized) are recorded as **proposals**. Of them, only "payment must use
the authoritative current invoice version" is implemented — because it holds under every answer
to D10, not because it was approved.

## 3. Existing foundation reuse matrix

| Component | Classification | Note |
|---|---|---|
| Correction lifecycle, maker≠checker, STALE, one-active index, lock order | REUSE_AS_IS | rule 190 §5–8 |
| Proposal builder (server-side cents, Rp0 refusal, overflow, no doctor re-attribution) | REUSE_AS_IS | |
| Frozen v0/vN document versions, snapshot hash | REUSE_AS_IS | D7 confirmation pending |
| Correction audit events | REUSE_AS_IS | |
| Read-only oversight (Finance Klinik, Data Analyst) | REUSE_AS_IS | unchanged |
| Payment paths vs `correction_version` | **REQUIRES_HARDENING → done** | this release |
| Price / discount authority | BLOCKED_BY_POLICY | D21 + tariff data |
| Pending-correction payment block | BLOCKED_BY_POLICY | D10 |
| Role grants | BLOCKED_BY_POLICY | D1 |
| Post-payment correction, refund, reversal, credit, Rp0 adjustment | OUT_OF_SCOPE | |

## 4. D21 evidence — measured, not assumed

Production, read-only (`SET default_transaction_read_only = on`):

| Branch | Active tariffed treatments |
|---|---|
| TLK1 | 11 |
| LDK2, ATG3, SPN4 | **0** |

Active treatments: 13. All 7 invoice items ever billed are at **SPN4** and every one is
`NO_TARIFF` (6 with a treatment, 1 free-text). Discounted items: 0. Zero-price items: 1.

Consequence: a tariff-enforced price authority — the audit's proposal — would refuse every Sunu
invoice line today. D21 therefore needs (a) the owner's policy and (b) tariff data for every
billing branch, before any enforcement. Nothing was invented: no percentage cap, no default
tariff, no "use TLK1's price" fallback.

## 5. What changed — payment ↔ invoice version binding

**The defect** (rule 190 §4a, audit residual L4): a cashier who opened the payment page before
a correction was applied could submit it afterwards and settle the corrected total while
looking at the original one. Graphify confirms the shape: `RmePaymentService` (community 16)
and `RmeInvoiceCorrectionService` (community 107) share no edge; the payment path never read
`correction_version`.

**The fix.**
- `resources/views/rme/cashier/payment/create.blade.php` renders
  `invoice_versions[<id>] = <correction_version>` for the paid invoice, every control
  carry-over parent and every selectable receivable — from the current rows, never `old()` —
  and shows an `invoice_versions` error banner.
- `CreateRmePaymentRequest` requires `invoice_versions`, validates whole ids and whole
  non-negative versions, requires the paid invoice's key, and compares the paid invoice's
  version early (no lock) so the cashier sees "tagihan telah dikoreksi" rather than "amount
  exceeds the remaining balance".
- `RmePaymentService::assertFormVersion()` is the authority: called on the LOCKED row of every
  invoice a batch would settle (paid invoice, each parent, each selected receivable).
  `settleUnderVersionBinding()` wraps all three transactions: a conflict rolls the whole batch
  back, writes `RME_PAYMENT_REFUSED_INVOICE_VERSION` after the rollback, and surfaces
  `[INVOICE_VERSION_MISSING]` / `[INVOICE_VERSION_CHANGED]` on `invoice_versions`.
- `RmeInvoiceVersionConflict` (Support) carries the reason, invoice id, expected and current
  version; `RmeFinancialAuditEvent::paymentVersionRefusedPayload()` is PII-free.

**Lock hierarchy (unchanged).** Payment: paid invoice row → parents / receivables in summary
order. Correction apply: invoice row → correction row. The version is read from rows already
locked; no lock was added, moved or reordered.

**Commit orders, both proven on PostgreSQL 16.15:**

```
correction ──lock──write v1──(hold 2s)──commit
payment         ──lock (waits)────────────────── read v1 ── form says v0 → REFUSED, nothing written
payment(reload)  ──lock (waits)───────────────── read v1 ── form says v1 → PAID against 350.000

payment ──lock──insert payment──(hold 2s)──commit
correction apply   ──lock (waits)─────────────── re-read: paid → STALE (rule 190, unchanged)
```

**Not D10.** A SUBMITTED/APPROVED correction does not change anything the cashier sees, so a
payment against it is still accepted and the correction goes STALE — pinned by a test.

## 6. Tests

New: `tests/Feature/RmeInvoiceCorrection/RmeInvoiceCorrectionPaymentVersionBindingTest.php`
(21 tests) — form renders versions (paid invoice + receivables); HTTP refusal for a missing
version and for five malformed shapes; HTTP acceptance with the current version; HTTP refusal of
a page opened before a correction (reported as a version error, not an amount error); acceptance
after reload against the corrected total; programmatic refusal without a version; refusal +
audit (actor, path, versions) for a stale version; PII-free audit; extra declared ids ignored;
SUBMITTED correction does not block (D10 untouched); binding holds with the flag OFF; visit
batch refused as a whole for a stale or undeclared receivable; visit batch accepted when every
version is current; control batch refused for a stale parent and accepted after reload.

Extended: `RmeInvoiceCorrectionConcurrencyTest.php` (+2, PostgreSQL only) — correction first ⇒
stale form waits and is refused, reloaded form waits and is accepted.

Updated: 139 payment call sites in 23 existing test files now declare the versions a freshly
rendered page would carry, through `rmeFreshPaymentForm()` in `tests/Pest.php`. No assertion
was weakened; the helper never overwrites an explicit `invoice_versions`.

## 7. Remaining limitations

- Activation not performed: D1, D7, D10, D21 PENDING (owner).
- D21 also needs per-branch tariffs (only TLK1 has any).
- `REAL_CORRECTION_PRODUCTION_TEST=NOT_PERFORMED` — no authorized correction case exists and
  none may be fabricated.
- A payment page open across this deploy is refused once with "muat ulang halaman".
