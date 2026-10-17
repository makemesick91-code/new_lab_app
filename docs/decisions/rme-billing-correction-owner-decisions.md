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

### D21 — Price authority — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`

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

#### D21 sub-decisions — PENDING_SUB_DECISION

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

1. Recorded decisions — D1, D7, D10, D21 now recorded (D1a, D21a–h still open).
2. D21 enforcement + tariffs per billing branch — the mechanism ships here; the
   tariff data does not exist yet for SPN4, the only branch that bills.
3. `RoleSeeder` grant (D1 mapping above; D1a open).
4. Flag ON via the environment + config cache rebuilt as `daengtisiams`.
5. Authenticated maker/checker pilot with two distinct real accounts.
6. Financial reconciliation.

A missing step is NO_GO.
