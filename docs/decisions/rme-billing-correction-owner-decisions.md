# RME billing correction — owner decision register

This is the living register of the owner decisions that govern RME invoice
correction, price authority, payment and refund. It supersedes, for the
decisions it records, the "all PENDING" register in
`docs/sprints/feature-rme-invoice-correction-receipt-foundation-1.md` §3, which
stays as the historical record of what was true at that release.

## How a decision is recorded here

Three facts are kept apart and never merged:

| Label | Meaning |
|---|---|
| `OWNER_POLICY_APPROVED` | The owner stated the policy. Nothing more is implied. |
| `REPOSITORY_POLICY_RECORDED` | The policy is written into this repository (this file, a rule). |
| `TECHNICAL_ENFORCEMENT_DEPLOYED` | Code enforcing it is merged, deployed and verified on production. |

A decision is only as far along as the last label that is evidenced. A brief's
"recommended default" or a proposal column is never an approval.

## Decision chronology

| Date (WITA) | Decision | Status | Source |
|---|---|---|---|
| 2026-10-17 | D1, D7, D10, D21 (branch tariff authority) | `OWNER_POLICY_APPROVED` | brief of `FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1` |
| 2026-10-20 | **D21 REVISED — manual cashier pricing** | `OWNER_POLICY_APPROVED` (`APPROVED_REVISED`) | brief of `REVISION-RME-MANUAL-PRICING-AUTHORITY-1` |
| 2026-10-20 | D21 of 2026-10-17 (branch tariff authority) | **`SUPERSEDED`** by the revision above | — |

The earlier D21 text is kept below, marked SUPERSEDED, as the record of what was
decided and enforced between 2026-10-17 and 2026-10-20. It is never deleted.

## Provenance of D1, D7, D10 and D21

- **Source:** the owner (Raushan Fikri Ridha) supplied the four decisions as
  task-supplied decision evidence in the brief for
  `FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1`, stating that they had been approved in
  a preceding conversation that is not available to this repository.
- **Date recorded in the repository:** 2026-10-17 (WITA). That is the date this
  file was written; the original approval date is the owner's and is not claimed
  here. No signature, timestamp or approval reference was invented.
- **Not implied:** that any of the four was approved before 2026-10-17, or that
  any other decision D2–D6, D8, D9, D11–D20, D22–D27 is decided.

## Approved decisions

### D1 — Pre-payment correction approval authority — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`

Policy (owner's words, normalised):

1. Kasir may **submit** an invoice correction request.
2. Admin Klinik may **approve** a correction request **within their authorized branch**.
3. Super Admin may approve **across branches**.
4. The requester must **never** approve their own request — including a Super Admin.

Technical implications:

- Maker ≠ checker is already enforced per ACCOUNT in
  `RmeInvoiceCorrectionService::decide()` under the invoice row lock (rule 190
  §7). `Gate::before` lets Super Admin pass every policy, so the policy is never
  the guarantee — the service is.
- Branch scope is already enforced by `RmeWorkingBranchScope` in
  `assertActor()`: a context-bound Admin Klinik is pinned to their working branch;
  Super Admin resolves to every active RME-enabled branch.
- **Not applied by this sprint.** The mapping below is prepared, not seeded.
  Granting it is the activation ceremony (rule 191 §20), never a deploy side effect.

| Role | Permission to grant at activation | Holds today |
|---|---|---|
| Kasir | `request_rme_invoice_correction` | no |
| Admin Klinik | `approve_rme_invoice_correction` | no |
| Super Admin | all three (wildcard) | yes (wildcard) |
| *(open — see D1a)* | `apply_rme_invoice_correction` | no |

**D1a — who APPLIES an approved correction — PENDING_SUB_DECISION.** D1 names
the submitter and the approver, not the applier. The foundation has a separate
`apply_rme_invoice_correction` permission. Whether the approver applies, the
maker applies, or a third role applies is not decided and is not assumed.

### D7 — Document versioning — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED`

Policy: the original financial document is preserved as **v0**; every approved
revision creates a new immutable version **v1, v2, …**; each revision references
the original document, its issue date and the reason; existing documents are
never overwritten.

Technical state: already implemented and deployed by
`FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1` (rule 190 §3):
`trx_rme_invoice_document_versions` (v0 ORIGINAL frozen on first apply, vN
REVISION per applied correction, SHA-256, model-level immutability, rendered only
from the snapshot). Because only UNPAID invoices are correctable, no issued
receipt is ever invalidated. The approval matches what is deployed; no code
change was needed.

### D10 — A pending correction blocks payment — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`

Policy: an invoice with an active correction request must not accept payment
while the request is pending approval or approved but not yet applied. Payment
may resume only after the correction has been rejected, cancelled, become stale
or been successfully applied, and the current invoice version has been
revalidated.

Mapping onto the existing state machine (`RmeInvoiceCorrectionStatus`):

| Owner term | State | Payment |
|---|---|---|
| pending approval | `SUBMITTED` | **blocked** |
| approved but unapplied | `APPROVED` | **blocked** |
| rejected | `REJECTED` | resumes |
| cancelled | `CANCELLED` | resumes |
| stale | `STALE` | resumes |
| applied | `APPLIED` | resumes — against the new `correction_version` only (rule 191) |

Technical implications: enforced in `RmePaymentService` under the invoice row
lock for every invoice a payment batch would settle, on all three payment paths,
with whole-batch rollback. Delivered by `FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1`;
see rule 192.

Rollback implication: while the correction flag is OFF no correction can leave
`SUBMITTED`/`APPROVED`. Before switching the flag OFF, every active correction
must be resolved (rejected or cancelled), or its invoice stays unpayable.

### D21 (CURRENT) — Manual cashier pricing — `OWNER_POLICY_APPROVED` (`APPROVED_REVISED`), `REPOSITORY_POLICY_RECORDED`, technical enforcement: see rule 194

**Provenance.** The owner (Raushan Fikri Ridha) supplied the revision as
task-supplied decision evidence in the brief for
`REVISION-RME-MANUAL-PRICING-AUTHORITY-1`, recorded in this repository on
**2026-10-20 (WITA)**. That is the date this entry was written; no signature,
timestamp or approval reference was invented. `TECHNICAL_ENFORCEMENT_DEPLOYED` is
claimed only by the sprint evidence once production is verified
(`docs/sprints/revision-rme-manual-pricing-authority-1.md`).

**Policy (owner's words, normalised).** There is **no mandatory official treatment
tariff**.

1. The authorized cashier enters the price of every line of the **original**
   invoice manually.
2. No mandatory tariff lookup, no mandatory tariff equality, no automatic branch
   tariff fallback, no mandatory official price document.
3. A treatment price may legitimately differ **between branches** and **between
   patients**.
4. The tariff master is **optional** and is never required to create or correct
   an invoice.
5. The original invoice price is the **authoritative financial snapshot** of that
   issued invoice and is preserved.
6. After an invoice is saved and issued, its billing amounts may **not be edited
   directly**; every later change uses the authorized Billing Correction workflow
   (maker-checker, branch authorization, audit, invoice version check, balance
   validation, immutable snapshots, payment concurrency protection — D1, D7, D10).
7. Every money value is validated authoritatively on the server; relevant
   financial writes are audited.
8. Special zero-value transactions follow separate eligibility rules (not this
   decision).

**Technical implications** — rule 194: every new invoice line records
`pricing_mode = MANUAL` and `price_source` ∈ {`MANUAL_CASHIER_ENTRY`,
`MANUAL_FREE_TEXT`, `CORRECTION_MANUAL_ENTRY`}; the tariff master is never read on
the authoritative pricing path; the correction tariff gate and the
`TARIFF_AUTHORITY_CHANGED` staleness are removed; the strict tariff mode is
`LEGACY_SUPERSEDED` (source-controlled empty list, no environment override). D1,
D7 and D10 are unchanged. Tariff data and its provenance are preserved.

#### D21 sub-decision reconciliation (2026-10-20)

Each tariff-era sub-decision was re-evaluated against the revised D21. Nothing is
marked approved that the owner did not decide.

| Code | Question (tariff era) | Status after the revision | Reason / current behaviour |
|---|---|---|---|
| D21a | Maximum duration of the transitional period | `NOT_APPLICABLE` | Manual pricing is the policy, not a transition. The `BRANCH_TRANSITIONAL` WATCH no longer arises. |
| D21b | Who approves a price/discount exception | `NOT_APPLICABLE` (tariff exceptions) | There is no tariff to deviate from. **Discount approval authority is NOT decided** — folded into D21c. |
| D21c | Maximum discount | **`PENDING_SUB_DECISION`** (re-scoped: general discount policy) | Enforced today: `0 ≤ discount ≤ line gross` on invoice and correction; no percentage/amount cap is invented. |
| D21d | Maximum deviation from the tariff | `NOT_APPLICABLE` | No mandatory tariff. |
| D21e | Exception-approval threshold | `NOT_APPLICABLE` | No tariff exceptions. |
| D21f | Maximum override amount | `NOT_APPLICABLE` | No tariff override; the only ceiling is the decimal(15,2) column (overflow refused). |
| D21g | Intrinsically free treatments / Rp0 consultation & control | **`PENDING_SUB_DECISION`** | Original invoice: pre-revision behaviour kept (Rp0 accepted, audited as `zero_value_lines`). Correction: every new Rp0 line/total refused. The approved Rp0 *adjustment* scope (Kontrol / Konsultasi Dokter, rule 188 §12) is not built. |
| D21h | Official tariff data per billing branch | `NOT_APPLICABLE` as a prerequisite | The tariff master is optional reference; no price list is required to bill. Tariff import/provenance governance (rule 193) stays available. |
| D21i | Which date selects the tariff in force | `NOT_APPLICABLE` for pricing | Retained only for optional reference resolution (readiness / template). |

### D21 (2026-10-17) — Price authority — **SUPERSEDED 2026-10-20** by the revised D21 above

> Kept as the historical record. Do not enforce. Its mandatory tariff authority,
> tariff equality and correction-only-within-tariff rules were withdrawn by the
> owner; the strict mode it introduced is `LEGACY_SUPERSEDED` (rule 194).

Policy:

1. Authoritative prices come from the approved treatment tariff master **per branch**.
2. Price overrides and discounts outside approved policy need specific authorization.
3. A branch without complete authoritative tariffs may keep its existing legacy
   billing workflow **temporarily**, under documented and auditable transitional controls.
4. A branch must not activate invoice correction for treatment items whose prices
   cannot be validated against approved tariff authority.

Technical implications: see rule 192 and
`docs/sprints/feature-rme-branch-tariff-authority-1.md`. Strict pricing is an
explicit per-branch opt-in; nothing becomes strict because it has some tariffs.

#### D21 (2026-10-17) sub-decisions — superseded; see the reconciliation table above

D21 approves the principle. These parameters were **not** stated and are not
invented; until each is decided the code takes the fail-closed default shown.

| Code | Open question | Default until decided |
|---|---|---|
| D21a | Maximum duration of the transitional period | No expiry is enforced; transitional exposure is reported by `rme:tariff-authority-readiness`. |
| D21b | Who approves a price/discount exception (exception approver) | Exceptions are disabled for everyone, Super Admin included, by a source-controlled switch with no environment override. As built the invoice creator would authorize its own exception, so a separate approval step must be built once D21b names the approver. |
| D21c | Maximum discount (percent or amount) | No discount is authorizable in strict mode. |
| D21d | Maximum price deviation from the tariff | No deviation is authorizable in strict mode. |
| D21e | Threshold above which an exception needs approval | Not applicable while exceptions are disabled. |
| D21f | Maximum override amount | Not applicable while exceptions are disabled. |
| D21g | Treatments that are intrinsically free (e.g. `TRT-FREESC` "Scalling Voucher Gratis") and Rp0 consultation/control | Strict mode refuses every Rp0 line and every zero tariff; transitional mode keeps today's behaviour. Ties to D11/D22. |
| D21h | Official tariff data for every billing branch (owner-supplied price list) | No tariff is invented, copied from another branch or derived from a historical invoice. |
| D21i | Which date selects the tariff in force: the billing (invoice) date or the date the treatment was rendered (visit date) | The **invoice's clinical date** (WITA), used identically at invoice creation and at correction, so the two can never disagree. A visit billed late is priced at the tariff in force on the day it is billed. |

**D21h status note (FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1, 2026-10-17) — a
finding, not a decision.** No owner-supplied price list was found anywhere, so
D21h stays PENDING and no tariff was imported. The 11 tariffs production TLK1
already holds are the Sprint 19 `TariffSeeder` development defaults (identical
values, created in one second, no audit trail) — **they are not D21h data**.
Tariffs now carry approval provenance (rule 193): a tariff counts as approved
only when it arrived through the import with an owner-approval reference. When
the owner supplies the list, TLK1's seeded rows are either approved as-is (ATTEST,
price untouched) or replaced; `TRT-SC99` needs a price per branch (its name is
not a price) and `TRT-FREESC` waits for D21g.

## Pending decisions (unchanged)

D2, D3, D4, D5, D6, D8, D9, D11, D12, D13, D14, D15, D16, D17, D18, D20, D22, D23,
D24, D25, D26, D27 remain **PENDING**. D19 remains `APPROVED_SCOPE (rule 186)`.
See `docs/sprints/feature-rme-invoice-correction-receipt-foundation-1.md` §3 for
their text. Decision codes are per register — the KTP-OCR "D7" is a different decision.

## Activation still requires (rule 191 §20, unchanged order)

1. Recorded decisions — D1, D7, D10, D21 (revised 2026-10-20) recorded. Still
   open: D1a (who applies), D21c (discount policy), D21g (Rp0).
2. ~~D21 enforcement + tariffs per billing branch~~ — **no longer a prerequisite**
   (revised D21): prices are manual and correction is tariff-independent (rule 194).
3. `RoleSeeder` grant (D1 mapping above; D1a open).
4. Flag ON via the environment + config cache rebuilt as `daengtisiams`.
5. Authenticated maker/checker pilot with two distinct real accounts.
6. Financial reconciliation.

A missing step is NO_GO.
