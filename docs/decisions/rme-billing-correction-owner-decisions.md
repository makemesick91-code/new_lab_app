# RME billing correction — owner decision register

This is the living register of the owner decisions that govern RME invoice
correction, price authority, payment and refund — and, since 2026-10-22, the
clinic-visit decision OD-1, recorded here because it fixes which branch owns a
Kontrol's invoice and payments. It supersedes, for the
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
| 2026-10-21 | **D1a — the checker applies, automatically at approval** | `OWNER_POLICY_APPROVED` | owner answers in `ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2` |
| 2026-10-21 | **D1b — Front Office holds request AND approve** (literal D1 on the merged role) | `OWNER_POLICY_APPROVED` | same |
| 2026-10-21 | **D1c — pilot scope: SPN4 (Cabang Sunu) only**, server-side allowlist, fail-closed | `OWNER_POLICY_APPROVED` | same |
| 2026-10-21 | **D1d — pilot accounts: maker user 29 (Admin Sunu), checker user 1 (IT Support, Super Admin)** | `OWNER_POLICY_APPROVED` | same |
| 2026-10-21 | **D1e — pilot IDENTITY RESTRICTION: only user 29 may submit; only user 1 may approve/reject; every other account denied** (narrows D1b inside the pilot; D1d becomes an enforced allowlist) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (runtime `f66c6c2a`) | owner instruction in `HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1` |
| 2026-10-22 | **OD-1 — cross-branch AND cross-doctor Kontrol allowed** | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-23, runtime `b76b0ed2`, tag `hardening-rme-active-user-patient-selection-1-go`; rule 200) | owner instruction in `HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1` |
| 2026-10-22 | **OD-1a — another branch's receivable is neither shown nor collected at this branch** (supersedes rule 197 §24) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-23, runtime `b76b0ed2`, tag `hardening-rme-active-user-patient-selection-1-go`; rule 200) | owner answer to the sprint's question, `HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1` |
| 2026-10-23 | **D28 — interim refusal of corrections on a Rp0 ORIGINAL invoice** (`ZERO_ORIGINAL_CORRECTION_GUARD: TEMPORARY_FAIL_CLOSED`; D21g and D22 stay PENDING) | `OWNER_TASK_INSTRUCTION`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-23, runtime `b3ec7133`, tag `fix-rme-invoice-correction-zero-original-guard-1-go`; rule 201) | owner task instruction for `FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1` |
| 2026-10-23 | **OPS-OQ-1 — an invoice known to be wrong is not collected, not modified unilaterally, escalated to the branch authority, kept financially unresolved; the patient is never held** (ALL branches; does not widen the SPN4 correction pilot) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-24, runtime `5b1d4262`, tag `implementation-rme-billing-dispute-escalation-ops-oq-1-go`; rule 203) | owner answer in the brief of `IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1` |
| 2026-10-23 | **OPS-OQ-1a — which branch-level account reviews/resolves a billing dispute** | ~~open owner question~~ → **ANSWERED 2026-10-24** (row below) | recorded by the same sprint |
| 2026-10-23 | **OPS-OQ-1b — may a control visit's own invoice be collected while its carry-over parent is disputed?** | ~~open owner question~~ → **ANSWERED 2026-10-24** (row below) | security review of the same sprint |
| 2026-10-24 | **OPS-OQ-1a — an EXPLICITLY designated branch responsible person may review and resolve that branch's billing disputes** (per account, per branch; never a role grant; Owner stays read-only; no financial power) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (runtime `9e37b855`, GO `hardening-rme-billing-dispute-branch-reviewer-control-payment-1-go`, deployed 2026-10-24; 0 designations in production — `REAL_OPERATOR_ACCEPTANCE: NOT_PERFORMED`; rule 204 §B) | owner instruction in the brief of `HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1` (no signed artefact; none fabricated) |
| 2026-10-24 | **OPS-OQ-1b — a Kontrol invoice may be paid ON ITS OWN while every outstanding origin invoice is disputed** (allocated to the Kontrol invoice only; origin receivable, status, version and dispute unchanged; the combined payment is still refused whole) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (runtime `9e37b855`, GO `hardening-rme-billing-dispute-branch-reviewer-control-payment-1-go`, deployed 2026-10-24; no Kontrol-only payment recorded yet — `REAL_OPERATOR_ACCEPTANCE: NOT_PERFORMED`; rule 204 §C) | owner instruction in the brief of `HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1` (no signed artefact; none fabricated) |
| 2026-10-25 | **PP-1 … PP-10 — post-payment correction policy** (separate default-OFF flag, SPN4, identities 29/1; checker approve = apply with a MANDATORY case-specific Owner approval reference; overpayment recorded as an atomic OPEN obligation, no refund; upward correction = ordinary balance at the invoice branch; issued receipts snapshotted, D7 extended to receipts; cash KPI unchanged, obligations reported separately; no invented threshold/deadline; lab-candidate invoices refused and no candidate generated by a correction; read-only obligation visibility; activation waits for a verified Sprint 2 closure) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` — **not** `TECHNICAL_ENFORCEMENT_DEPLOYED` (post-payment correction `NOT_IMPLEMENTED`; activation blocked by PP-10) | owner directive in the brief of `RATIFICATION-RME-POSTPAYMENT-OWNER-DECISIONS-PP1-PP10-1` ("PP-1 THROUGH PP-10 = OWNER APPROVED", against the canonical proposal text; no signed artefact; none fabricated) |
| 2026-10-31 | **Refund request & approval (Sprint 4/7): D4 via RF-1 — maker Front Office at its assigned working branch only; reviewers Super Admin (all branches), Supervisor RME (authorized scope), Kepala Cabang (own managed branch, narrow permission); MANDATORY case-specific Owner approval reference on every request, before processing, linked to invoice and obligation, never reused for another financial decision, validated in application and database; no self-approval. D18 via RF-2 = A (approval is not settlement; separation only). RF-4 = A (no partial refund). Audit permanent** | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` — nothing built, refund NOT_ACTIVE | owner statements in the brief of `FIX-CLINIC-OVERSIGHT-DATE-RANGE-TEST-DETERMINISM-1` and the Sprint 4 Owner Decision Addendum (2026-10-31); mapping in `docs/decisions/rme-refund-request-approval-owner-decision-package.md` §7 |
| 2026-10-31 | **Refund request & approval implemented (rule 215); Owner reference registry deployed (rule 214)** — RF-1 … RF-14, RF-1a and RF-8a enforced in code: Front Office maker at its working branch; reviewers by role and server-resolved branch (Supervisor RME's authorized scope resolves to every RME branch — the existing governance tier, recorded, not widened); maker ≠ checker per account; mandatory case-specific Owner reference consumed in the shared registry, also refused on a pre-payment correction once spent; patient request reference in its own registry; full refund only; dispute refuses submission and approval. Flag `rme.billing_refund_request` OFF; pilot SPN4 with an EMPTY identity allowlist; settlement NOT built | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` — refund: 2026-10-31, runtime `9826bd69`, tag `feature-rme-billing-refund-request-approval-1-implementation-go`, **flag OFF, identity allowlist EMPTY, not activated**; registry: 2026-10-31, runtime `61450801`, tag `hardening-rme-owner-reference-registry-1-go` | `FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1` and `HARDENING-RME-OWNER-REFERENCE-REGISTRY-1`; implementation decisions in `docs/sprints/feature-rme-billing-refund-request-approval-1.md` §3 |
| 2026-11-01 | **S6-D1 … S6-D8 — Konsultasi & Kontrol Rp0, "Selesai Tanpa Biaya" (Sprint 6/7)**: only Konsultasi Dokter and server-derived Kontrol are legitimately Rp0 (every free line of a Kontrol; consultation lines elsewhere); explicit action → terminal `NO_CHARGE`, no payment row, visit completed in the same transaction; invoice #36 left untouched; the branch cashier closes, eligibility system-checked, no second approval; D28 kept; reported as clinical visits with zero revenue, never unpaid/receivable/Lunas; vouchers, discounts, corrections, write-offs excluded; every RME branch; flag OFF with a separate activation decision. **Answers D21g and D22 for this closure scope only** | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (runtime `4520a276`, production-verified 2026-11-01) — flag `rme.zero_value_closure` **OFF**, not activated | owner answers recorded in-session for `FEATURE-RME-RP0-CONTROL-CONSULTATION-1` (decision dialog of 2026-11-01, no signed artefact; none fabricated); package `docs/decisions/rme-rp0-control-consultation-owner-decision-package.md` |
| 2026-11-01 | **HARDENING S6-Q1, S6-Q2, S6-Q3 (revised), S6-Q3a, S6-Q3b — Konsultasi & Kontrol Rp0 (Sprint 6/7 hardening)**: S6-Q1 (valid Kontrol parent: real, same patient, examined, not cancelled/deleted, server-derived) and S6-Q2 (no NO_CHARGE for a lab line; lab uses normal billing and lab-candidate generation) approved; S6-Q3 revised: a Kontrol may be Rp0, an additional normally paid treatment is billed > Rp0 and paid normally, a mixed invoice is never NO_CHARGE, a Kontrol link never makes a paid treatment free. S6-Q3a: approved free services = `consultation` category (any visit) + reserved `control` category (valid Kontrol only). S6-Q3b: the Rp0 issuance guard applies to Kontrol visits only and follows the flag. **Supersedes the S6-D1 sub-decision ("every free line of a Kontrol") and the S6-Q3 open option "creator ≠ closer"; S6-D3 preserved** | `OWNER_POLICY_APPROVED` (S6-Q1, S6-Q2) / `OWNER_POLICY_REVISED_AND_APPROVED` (S6-Q3), `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (runtime `ba33f9bb` (tree `2d1b684b`, PR #576), deployed 2026-11-01 04:34 UTC (12:34 WITA), production-verified, tag `hardening-rme-rp0-closure-owner-decisions-and-paid-treatment-1-go`) — flag `rme.zero_value_closure` **OFF**, not activated | owner brief of `HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1` (S6-Q1/Q2/Q3) and the in-session answers of 2026-11-01 (S6-Q3a, S6-Q3b); no signed artefact, none fabricated |

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

| Role | Correction permission (rule 195, `RoleSeeder`) | Status |
|---|---|---|
| Kasir | `request_rme_invoice_correction` | granted by `ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2` (0 production users hold Kasir) |
| Admin Klinik | `approve_rme_invoice_correction` | granted (0 production users hold Admin Klinik) |
| Front Office | `request_rme_invoice_correction` + `approve_rme_invoice_correction` | granted — D1b below |
| Super Admin | all three (wildcard) | yes (wildcard) |
| *(no operational role)* | `apply_rme_invoice_correction` | recovery path only — D1a below |

> The historical text of this table (2026-10-17) listed every operational grant
> as "no" and the applier as "open — see D1a". That was true until 2026-10-21.

**D1a — who APPLIES an approved correction — `OWNER_POLICY_APPROVED` (2026-10-21).**
The CHECKER applies, **automatically at approval**: approval and application are
one atomic act (`RmeInvoiceCorrectionService::approve()`, one transaction under the
invoice → correction lock pair). An approved-but-unapplied correction is never
visible to another transaction, so D10 cannot strand an invoice behind an approval
nobody applies. The authority is the APPROVE permission; no operational role is
granted `apply_rme_invoice_correction`, which now serves only the recovery path for
an APPROVED row that predates D1a (none exist in production: 0 corrections on
2026-10-21). The maker can never apply their own correction on any path, Super
Admin included (`SELF_APPLICATION_FORBIDDEN`, per account, under the lock).
Superseded text (2026-10-17): "PENDING_SUB_DECISION … not decided and is not assumed".

**D1b — Front Office holds request AND approve — `OWNER_POLICY_APPROVED` (2026-10-21).**
Production finding that forced the question: `Kasir` and `Admin Klinik` each have
**0 users**; all 8 front-desk operators hold `Front Office`, which `RoleSeeder` and
`FrontOfficeRoleTest` define as the EXACT union of the two. Granting D1 literally
therefore gives every Front Office account both halves. The owner chose that
literal reading. Maker ≠ checker stays a per-ACCOUNT rule: within one branch, Front
Office A may approve Front Office B's request, never their own. The alternatives
offered and NOT chosen: Front Office request-only with Super Admin as sole checker;
deferring until separate Kasir/Admin Klinik accounts are provisioned.

**D1c — pilot scope: SPN4 (Cabang Sunu) only — `OWNER_POLICY_APPROVED` (2026-10-21).**
The flag is global, so a server-side allowlist decides where an ON flag reaches:
`config/rme_invoice_correction.php` `pilot.branch_codes = ['SPN4']`, source-
controlled with NO environment read (widening is a reviewed commit), fail-closed
(empty = nothing), canonical exact-token matching (SUN4 → SPN4), MAIN never.
Submit, approve (= apply) and the recovery apply are gated; reject and cancel are
not (they only de-escalate, and are how a correction outside a narrowed pilot is
resolved). No other branch may be activated without a new owner decision.

**D1d — pilot accounts — `OWNER_POLICY_APPROVED` (2026-10-21).** Maker: user 29
(Admin Sunu, Front Office, pinned to SPN4). Checker: user 1 (IT Support, Super
Admin). Two distinct real accounts. These authorize the pilot; they are not the
only accounts the grant reaches (every Front Office account in SPN4 can act).
**Superseded in effect by D1e below:** D1d named the accounts; D1e makes them the
ONLY accounts that may act.

**D1e — pilot identity restriction — `OWNER_POLICY_APPROVED`,
`REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-21, runtime
`f66c6c2a`, tag `hardening-rme-invoice-correction-pilot-identity-payment-actor-1-go`;
rule 197).** Recorded from the owner's instruction
in `HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1` (no approval
timestamp beyond the date is invented). The production finding that prompted it:
the verification sprint measured that ALL 8 Front Office accounts (7, 8, 16, 17,
29, 30, 31, 32) could submit and approve SPN4 corrections, because D1b grants the
role both halves and only user 29 is branch-pinned. The owner narrowed the pilot
to the two D1d accounts:

| Action | Allowed accounts | Everyone else |
|---|---|---|
| Submit a correction | **user 29 only** | refused |
| Approve (= apply, D1a) | **user 1 only** | refused |
| Reject | **user 1 only** | refused |
| Self-approval | never (D1, per account, Super Admin included) | — |

- The restriction applies **regardless of role permissions** — other Front Office
  accounts, other Super Admins and every other role are refused. Super Admin's
  `Gate::before` bypass does not reach it: the SERVICE asserts it under the
  invoice row lock.
- Roles and general permissions are **unchanged** (RoleSeeder untouched, still 6
  correction pairs). Non-correction Front Office work is unaffected.
- Pilot scope stays **SPN4** (D1c). The identity list is source-controlled in
  `config/rme_invoice_correction.php` `pilot.identities`, no environment read,
  fail-closed (missing / malformed / overlapping → nobody may act).
- Implementation choices recorded with it (rule 197), none of which widens anyone:
  the **recovery apply** of a pre-D1a APPROVED row is the second half of an
  approval (D1a) and is confined to the checker identity — this NARROWS the apply
  permission, previously held by every Super Admin, to user 1 who already held it;
  **cancel** of one's OWN correction is not identity-gated — it is the maker's
  de-escalation and the release path that keeps a narrowed list from stranding an
  invoice behind the D10 payment block; an **empty** role list stops that role only
  (empty maker list = no new submissions while the checker still resolves filed
  ones), and SUBMIT requires at least one designated checker.
- `TECHNICAL_ENFORCEMENT_DEPLOYED` was added after the production verification:
  on runtime `f66c6c2a` the readiness probe reports `PILOT_IDENTITIES valid=yes
  maker=29 checker=1`, a non-designated pair (30/1) and the reversed pair (1/29)
  are refused, and the flag was restored ON only after that (`STATE=PILOT_ACTIVE`).
  It does not record that a real correction was performed — none has been.

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

> *Note, 2026-10-25 (`PREPARATION-RME-POSTPAYMENT-INVOICE-CORRECTION-1`, no decision
> changed):* the sentence "no issued receipt is ever invalidated" holds only while
> post-payment correction does not exist. Payment receipts are rendered from LIVE
> rows (`RmePaymentController::receipt`), not from a snapshot, so correcting a PAID
> invoice would change an issued receipt. Whether D7 extends to issued receipts is
> proposal **PP-5** (`PROPOSED`, below) — D7 itself is unchanged.
>
> *Ratified 2026-10-25 (PP-5, `OWNER_POLICY_APPROVED`):* **D7 extends to issued
> receipts.** The original principle — the original document is preserved, every
> revision is a new immutable version, nothing is overwritten — is unchanged; what PP-5
> adds is that a payment receipt is a document too, snapshotted when issued and
> re-rendered from that snapshot, stamped "DIREVISI — lihat versi ke-N" once the invoice
> moves past it. The technical note "because only UNPAID invoices are correctable, no
> issued receipt is ever invalidated" stays TRUE for pre-payment correction and is
> superseded only for post-payment correction, which may not be activated until receipt
> snapshots are deployed and verified. No historical row, version or receipt is
> rewritten to reconcile the two.

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

### D21 (CURRENT) — Manual cashier pricing — `OWNER_POLICY_APPROVED` (`APPROVED_REVISED`), `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-20, runtime `576e8aae`, tag `revision-rme-manual-pricing-authority-1-go`; rule 194)

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

#### D21c / D21g for the pre-payment activation (2026-10-21)

Neither was decided. `ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2` classified
both as **`SAFE_TO_DEFER_WITH_EXPLICIT_RESTRICTION`** for ordinary non-zero
pre-payment correction — a classification, never an approval:

- **D21c (discount policy) — PENDING.** No cap is invented. What bounds a corrected
  discount: `0 ≤ discount ≤ line gross`, no negative or zero line or total, 2-decimal
  precision, decimal(15,2) overflow refused, totals recomputed server-side — AND the
  whole proposal, discounts included, is a fingerprint a DIFFERENT account approves.
  A correction therefore grants no discount authority beyond the cashier's existing
  manual entry on the original invoice, and adds a second account's review.
- **D21g (Rp0) — PENDING.** A correction still refuses every new Rp0 line and Rp0
  total; historical Rp0 invoices are untouched; the dedicated Rp0 adjustment (rule
  188 §12) is not built. Production's only UNPAID invoice on 2026-10-21 is an Rp0
  legacy invoice (id 36): it is not a correction case.
  - *Clarification, 2026-10-23 (`VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-2`,
    no decision changed):* "untouched" is a procedure, not a code refusal. The
    correction service refuses a NEW Rp0 line or total, but it does not refuse a Rp0
    ORIGINAL — so it could raise #36 to a positive total. Doing so would settle the
    pending zombie-invoice question (D22, with D21g) by an operator act; it is
    forbidden by rule 196 §8/§23 and requires both pilot accounts (29 submits, 1
    approves). Whether to add a hard refusal while D21g/D22 are pending is an open
    owner question.
  - *Update, 2026-10-23 (`FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1`, rule 201):*
    the owner instructed that hard refusal as a TEMPORARY fail-closed boundary — see
    **D28** below. **D21g itself is still PENDING**; D28 decides nothing about Rp0
    pricing, consultation or control.

### OD-1 — Cross-branch and cross-doctor Kontrol — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-23, runtime `b76b0ed2`, tag `hardening-rme-active-user-patient-selection-1-go`)

Owner wording, verbatim (2026-10-22, brief of
`HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1`):

> "pasien bisa kontrol lintas cabang bahkan dengan dokter berbeda"

Status labels: `CROSS_BRANCH_CONTROL = APPROVED`, `CROSS_DOCTOR_CONTROL = APPROVED`.
This register holds no approval reference, timestamp or signature beyond the
wording above; none is invented. The question had been recorded as OPEN in
`docs/sprints/audit-rme-clinic-visit-branch-authority-1.md` §7 (rule 199 §16).

Required behaviour (rule 200):

| # | Rule |
|---|---|
| 1 | Patient identity is global (rule 101); a Kontrol never creates a duplicate patient. |
| 2 | The prior visit may belong to ANOTHER branch. |
| 3 | The prior visit's doctor may differ from the new visit's doctor. |
| 4 | The new Kontrol visit belongs to the branch where the patient now receives care — the registrar's working branch, never the prior visit's branch. |
| 5 | The new visit's doctor must be authorized for the new visit (online at the visit branch, or resolved from the treatment room); it is never inherited from the prior visit. |
| 6 | The prior visit is not changed. |
| 7 | The prior visit's RME (medical record, consent, odontogram) is not changed. |
| 8 | The prior visit's invoice and payments stay with their original branch. |
| 9 | The new invoice and its payments belong to the new visit's branch. |
| 10 | A cross-branch reference grants no archive access: history is shown through the existing clinical authorization only; the registration picker returns only what a choice needs. |
| 11 | Cross-branch history display respects the existing clinical authorization (rule 199 §15). |
| 12 | Security-relevant refusals are audited through the existing audit trail. |

#### OD-1a — another branch's receivable is not shown or collected here — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-23, runtime `b76b0ed2`)

Rule 197 §24 had recorded the Sprint 62.2 receivable carry-over as a
"cross-branch collection contract". That conflicted with OD-1 rules 8–9 and with
the cashier branch scope of rule 197 (FIX-03), so the owner was asked during the
sprint (2026-10-22) and answered, verbatim, with the option:

> "Hanya piutang B (Recommended)" — Kasir B hanya melihat & menagih tagihan
> Cabang B. Piutang Cabang A tidak tampil di layar kasir B dan tetap ditagih di
> Cabang A.

Policy: a patient's unpaid balance at another branch is NOT shown on, and NOT
collectable from, a payment taken at this branch; it remains that branch's
receivable, collected there. **Supersedes rule 197 §24.** Before rule 200 the
control-chain and receivable carry-over discovered every RME branch, so a
branch-B Kontrol payment was FIFO-allocated into branch A's invoice (booked to
branch A by a branch-B cashier) and branch A's invoice was shown to that cashier;
production held 0 such cases (read-only check, 2026-10-22). Collecting another
branch's debt would need a new owner decision and a cash-transfer policy.

Technical enforcement: `ClinicVisitRegistrationGuard` (prior-visit link checks
patient identity only — never branch or doctor equality),
`ClinicVisitService::create()` re-asserts it, and `RmeControlReceivableService`
limits carry-over to the paying visit's branch. `TECHNICAL_ENFORCEMENT_DEPLOYED`
is claimed only once the release that carries it is merged, deployed and verified
on production (sprint doc §Release evidence).

### D28 — Interim refusal of corrections on a Rp0 ORIGINAL invoice — `OWNER_TASK_INSTRUCTION`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-23, runtime `b3ec7133`, tag `fix-rme-invoice-correction-zero-original-guard-1-go`; rule 201)

`ZERO_ORIGINAL_CORRECTION_GUARD: TEMPORARY_FAIL_CLOSED`

- **Source:** the owner's task instruction for
  `FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1` (2026-10-23), which asked for a
  temporary fail-closed server-side guard preventing correction of an invoice whose
  authoritative original total is exactly zero, while D21g and D22 stay pending. No
  signature, timestamp or approval reference beyond that instruction exists; none
  is claimed.
- **What it is:** an enforcement boundary, not a commercial policy. Submit, approve
  (= apply, D1a) and the recovery apply refuse `CORRECTION_ORIGINAL_TOTAL_ZERO`
  (or `CORRECTION_ORIGINAL_TOTAL_UNVERIFIABLE` when v0 cannot be proven) under the
  invoice row lock, before any mutation. Reject and cancel stay open (D10 release).
- **The original total** is persisted data: the live invoice row while
  `correction_version = 0`, the frozen ORIGINAL (v0) document version above it.
- **What it is NOT:** an approval of D21g or D22; a new Rp0 product; a change to
  Rp0 invoice ISSUANCE (consultation, Kontrol, voucher invoices are still issued);
  postpayment correction; refund; a discount policy; a change to any historical
  invoice (invoice #36 stays Rp0 UNPAID; #41 stays PARTIAL).
- **Ends when:** the owner decides D21g/D22 and a reviewed code change replaces this
  guard. It is never relaxed by configuration or environment.
- **Not a pending decision** and not a resolution of one: D21g and D22 remain
  **PENDING** (list below).
- **`TECHNICAL_ENFORCEMENT_DEPLOYED`** was added after the production verification:
  PR #510 squash-merged as `b3ec7133` (tree `e7babf15`, identical to the CI-validated
  candidate), deployed on the VPS (`DEPLOY OK 20261023-071319`,
  `DEPLOY_HEAD_TARGET_MATCH=YES`, no migration), the guard verified present in the
  deployed service, the production financial fingerprint unchanged, no correction
  created to prove it (sprint doc §Release evidence).

### D4 — Refund approval authority (via RF-1) — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` (2026-10-31)

Owner statements of 2026-10-31 (brief of `FIX-CLINIC-OVERSIGHT-DATE-RANGE-TEST-DETERMINISM-1` and the Sprint 4
Owner Decision Addendum), recorded as stated and mapped by substance in the refund decision package §7:

* **Maker:** only **Front Office**, and only for its **assigned working branch**. Ordinary Kasir and Admin
  Klinik accounts never obtain maker authority through inherited permissions.
* **Reviewers (approve / reject):** **Super Admin** — all branches; **Supervisor RME** — branches within its
  legitimate authorized scope; **Kepala Cabang** — its own managed branch only, with narrow refund approval
  permissions and **no** broad RME access.
* **Owner approval reference:** every refund request carries a valid, auditable, case-specific Owner approval
  reference; the Owner approval **precedes** processing of the request; the reference is linked to the
  invoice and the authoritative overpayment obligation; it is never reused for a different financial
  decision under the approved uniqueness contract; it is validated in the application **and** the database.
* **No self-approval**; maker-checker separation is kept.

This is a role-based, branch-scoped answer — not option A (per-account designation), B, C or D of the
package. Still PENDING: **RF-1a** (pilot identities; the Sprint 2 `HUMAN_SEPARATION` red flag is
unresolved) and whether a request re-filed for the **same** obligation after rejection or revocation may
repeat its reference (decided with RF-6; until then a re-filed request needs a new reference).
*Both answered the same day — see "Refund request & approval — remaining RF answers" below: RF-1a = no
automatic reuse of the pilot pair, real human separation required; RF-6 = every new request carries a new
Owner reference.* F-17
(`HARDENING-RME-OWNER-REFERENCE-REGISTRY-1`) stays a separate hardening, NOT_STARTED. Nothing is built;
refund stays NOT_ACTIVE; post-payment correction stays OFF.

### D18 — Approval is not settlement (via RF-2 = A) — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` (2026-10-31)

Owner statement "settlement terpisah": an approved refund never moves money; paying it out is a separate
record, permission, action and actor (Sprint 5). Recorded **for the separation only** — who may pay (D25,
RF-10) and how (D5, RF-9) stay PENDING. *Answered as Sprint 5 policy the same day (entries D5 and D25
below); the settlement actor is still not appointed.*

### Refund amount — no partial refund (RF-4 = A) — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` (2026-10-31)

A refund request is for the whole OPEN overpayment obligation, computed by the server; no amount input and
no partial refund (options B and C refused).

### Refund audit permanence — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` (2026-10-31)

Refund requests and their events are permanent audit records: append-only, never deleted, never edited in
place; financial history stays immutable.

### Second owner answer of 2026-10-31 — all package recommendations approved, with modifications

Source: "Master Execution Prompt — Sprint 4/7 — Refund Request & Approval — Owner Decisions Approved"
(2026-10-31, WITA), section 7: the owner *"expressly approves the recommendations contained in the canonical
Sprint 4 refund decision package"*, with the per-code modifications below. Recorded as stated; no signature,
document number or timestamp is invented, and no earlier approval is created retroactively. Mapping and
contradiction report: refund decision package §7.4 and §7.5. A policy approval is **not**
`TECHNICAL_ENFORCEMENT_DEPLOYED`: nothing below is built, deployed or enabled.

### D5 — Refund method (via RF-9 = C) — `OWNER_POLICY_APPROVED`, `SPRINT_5_IMPLEMENTATION_PENDING` (2026-10-31)

Settlement methods are **bank transfer** and **cash**, each with its own proof. Bank-account data is
sensitive. Bank reference and payment evidence belong to the settlement workflow (Sprint 5). Sprint 4
implements no transfer, cash payout or settlement. End-of-month disbursement stays an operational SOP
unless an exact scheduling contract is approved separately.

### D6 — Patient credit balance (via RF-12) — `OWNER_POLICY_APPROVED_EXCLUSION` (2026-10-31)

No patient credit ledger, no cross-invoice offset, no use of an overpayment to pay another invoice. A
future patient-credit system requires its own authorization.

### D25 — Settler ≠ approver (via RF-10 = B) — `OWNER_POLICY_APPROVED`, `SETTLEMENT_ACTOR_PENDING` (2026-10-31)

The account that pays out a refund must differ from the account that approved it. The maker may be the
payer only if separately eligible under future settlement permissions and only after every control passes.
This appoints no payer and grants no settlement privilege to Front Office or any other role; the settlement
actor is resolved before Sprint 5 activation.

### D26 — Payment reversal (via RF-13) — `OWNER_POLICY_APPROVED_EXCLUSION` (2026-10-31)

A payment reversal is not a refund. A wrongly recorded or duplicated payment needs a separate reversal
workflow and authorization. Sprint 4 accepts only authoritative OPEN overpayment obligations created by the
eligible correction workflow — never a payment.

### Refund request & approval — remaining RF answers — `OWNER_POLICY_APPROVED` (2026-10-31)

* **RF-1a — `NO_AUTOMATIC_PILOT_PAIR_REUSE`, `REAL_HUMAN_SEPARATION_REQUIRED`.** Pilot accounts 29 and 1 are
  not reused automatically; historical case #42 does not satisfy the pre-authorization acceptance criteria
  and keeps its true audit chronology. A refund pilot needs a real assigned Front Office maker, an
  independently identified eligible reviewer, distinct accounts, verified human separation, real branch
  authorization, a real prior Owner approval and a current activation approval; otherwise
  `PILOT_ACTIVATION = NO_GO`. No fake operator assignment, no synthetic production pilot.
* **RF-3** — separate flag `rme.billing_refund_request`, default OFF, fail closed; pilot SPN4. Activation
  order: (1) Sprint 2 Full Prepayment Pilot GO; (2) F-17 deployed and verified; (3) Sprint 3 post-payment
  correction separately authorized and activated; (4) at least one real OPEN overpayment obligation;
  (5) authorized Front Office maker and independent reviewer confirmed; (6) separate Owner approval of
  refund activation. The flag is not enabled during Sprint 4 implementation and the pilot is not widened.
* **RF-5 = B** — a patient refund-request reference is mandatory in addition to the reason and the
  obligation: 6–120 characters, no NIK or obvious identity-number variant, no bank-account collection in
  Sprint 4. It is never reused for a **different** obligation; a legitimate re-filed request for the
  **same** obligation may repeat it. It is not an Owner approval reference — the two have different
  uniqueness contracts.
* **RF-6** — a rejection rejects only that request, needs a reason, keeps the original claim, leaves the
  obligation OPEN and neither forgives nor settles it. The maker may withdraw only a `REQUESTED` request,
  with an audited reason. An `APPROVED`, unsettled request may be revoked only through a checker-level
  workflow, by an account other than the maker, in the right branch scope, with an audited reason. Once
  settled (Sprint 5) there is no ordinary cancellation or revocation. **Every new request after a
  rejection, withdrawal or revocation carries a NEW Owner approval reference**; an earlier reference never
  authorizes a new refund decision. (PP-2's same-invoice resubmission rule for post-payment corrections is
  a different contract and is unchanged.)
* **RF-7** — refund submission **and** approval are refused while the source invoice has an active billing
  dispute (`REFUND_INVOICE_DISPUTED`). OPS-OQ-1 behaviour is preserved; nothing settles the dispute,
  releases a payment hold or changes correction state. The resolution dependency (a dispute filed after the
  obligation opened can only resolve as `ORIGINAL_CONFIRMED_CORRECT` while PP-3 holds) is recorded, not
  engineered away.
* **RF-8** — refund requests are visible read-only in oversight; approved-but-unsettled refunds are shown
  apart from revenue, receivables, cash and bank balances; approval moves no KPI; attribution = source
  invoice branch; no revenue netting on approval.
* **RF-8a** — Owner receives only the existing `view_billing_correction_oversight` (not
  `export_oversight_data`, and no maker, reviewer, settlement, pricing, invoice-correction or general RME
  write permission). Re-verified 2026-10-31: the permission gates 9 GET routes, 4 of which also require
  `export_oversight_data`. The grant is applied by the implementation sprint after a production-vs-seeder
  diff; until then Owner still lacks it.
* **RF-11** — settlement timestamps are a Sprint 5 matter: authoritative server time, WITA reporting dates,
  never future-dated, and **no user-entered backdating** unless separately authorized (no window is
  defined, so none is invented). D24/D27 (incoming-payment `paid_at`) are unchanged.
* **RF-14** — printed refund conditions (Kawat Gigi, end of month) stay operational SOP; no system deadline
  is invented or enforced in Sprint 4; printed contractual text is not removed without a separate decision.

With RF-1 … RF-8 and RF-8a answered, the decision package's §6 precondition for Sprint 4 implementation is
met **on the owner side**. Implementation still waits on repository gates (the date-determinism fix PR and
the decision package merged through legitimate review) and on the correction side of the F-17 registry
being deployed before refund becomes its second consumer. Refund stays NOT_IMPLEMENTED and NOT_ACTIVE.

### S6-D1 … S6-D8 — Konsultasi & Kontrol Rp0 ("Selesai Tanpa Biaya") — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` with the flag OFF (2026-11-01)

**Provenance.** The owner answered the eight questions of
`docs/decisions/rme-rp0-control-consultation-owner-decision-package.md` §6 in the session of
`FEATURE-RME-RP0-CONTROL-CONSULTATION-1` on 2026-11-01 (WITA), choosing among presented options
(the recommended option each time, except the Kontrol sub-decision of S6-D1). No signature,
timestamp or approval reference beyond those answers exists; none is claimed. The Owner's
prior instruction to defer Sprint 2 and begin Sprint 6 is a prioritisation, recorded as such and
**not** as an approval of anything.

| Code | Owner answer (normalised) | Register mapping |
|---|---|---|
| S6-D1 | Only **Konsultasi Dokter** (`TRT-CONS`, treatment category `consultation`) and **Kontrol** (`visit_type = control` derived server-side from `follow_up_of_visit_id`) may be legitimately Rp0. **Sub-decision:** ~~on a Kontrol visit EVERY free line is eligible~~ — **SUPERSEDED 2026-11-01 by S6-Q3 (revised), see below**; on any other visit only consultation lines. Eligibility comes from `treatment_id` + category, never from a description. | Answers **D21g** for the closure scope; answers the D11 question "Rp0 per item" for closure (every line must be free). |
| S6-D2 | Explicit **"Selesai Tanpa Biaya"** action; server checks eligibility; invoice → terminal **`NO_CHARGE`** ("Tanpa Biaya", NOT "Lunas"), no payment row; visit `cashier_pending → completed` in the same transaction. Zero balance ≠ examination done ≠ visit done ≠ money received. Historical invoice **#36 stays as it is**. | Answers **D22** (Rp0 zombie completion) for this scope. |
| S6-D3 | The **branch cashier** (`manage_rme_billing` + working-branch scope) closes; eligibility is system-checked; audited; **no second approval**. Super Admin's bypass never reaches eligibility. | New. |
| S6-D4 | **D28 stays.** A `NO_CHARGE` invoice can never be corrected; any path to charge a patient after a Rp0 is a separate sprint and decision. | D28 unchanged. |
| S6-D5 | Counted as a **clinical visit** and reported separately (Rp0 Konsultasi / Rp0 Kontrol); **never** "belum dibayar", never a receivable, revenue +0; doctor performance shows "Tanpa Biaya" beside "Lunas"; closed periods unchanged. | Answers D8/D9 for Rp0 visits only. |
| S6-D6 | **Excluded:** vouchers (`TRT-FREESC`, on any visit, Kontrol included), charity, complimentary procedures, **discounts** (a Rp0 reached by discount is not free), corrections, write-offs. | D21c (discount policy) stays PENDING. |
| S6-D7 | **Every RME branch**; the invoice branch is always the visit branch, never a request value; correction/refund pilot restrictions are not inherited. | New. |
| S6-D8 | Shipped with flag **`rme.zero_value_closure` OFF**; production use needs a **separate Owner activation decision** after verification and a cashier SOP. | New. |

**Still PENDING after S6:** Rp0 **issuance** governance (who may price a line at Rp0 when the
invoice is created — unchanged, D21g for issuance), vouchers, any Rp0 correction path (D28 holds),
the discount policy (D21c), and the completion of a Rp0 Kontrol whose visit is completed by an
unrelated parent-receivable payment (the #36 pattern — the invoice stays UNPAID; recorded as a
residual, not decided). *Update 2026-11-01:* Rp0 issuance on a **Kontrol** is now governed (S6-Q3b) and
the #36 pattern is resolved for every closable invoice — see the hardening section below; issuance on any
other visit stays D21g, PENDING.

**Engineering narrowings after the independent security review** — recorded here at the time as NOT owner
decisions, PENDING owner confirmation; **S6-Q1 and S6-Q2 were APPROVED and S6-Q3 REVISED AND APPROVED by the
Owner on 2026-11-01 (section below).** The original text is kept unchanged as history: Both fail closed (they only refuse more), so they stay inside the approved policy; relaxing
either needs an explicit owner answer:

- **S6-Q1** — a Kontrol qualifies only when its parent visit exists, is not deleted, belongs to the same
  patient and was examined (`cashier_pending` or `completed`). Registration accepts a link to a cancelled
  or never-examined visit; such a "Kontrol" is refused (`ZERO_VALUE_CONTROL_PARENT_INVALID`).
- **S6-Q2** — a free line whose treatment `requires_lab` is refused even on a Kontrol
  (`ZERO_VALUE_LAB_LINE_EXCLUDED`), because `NO_CHARGE` creates no lab candidate and the lab work would be
  silently dropped. The S6-D1 sub-decision ("every free line of a Kontrol") did not consider lab lines.
- **S6-Q3 (open, no code)** — the review's MEDIUM-1: price and the Kontrol link remain operator-controlled
  under D21 and S6-D3, so a single branch account can still label a non-consultation treatment as a free
  Kontrol. Options for the Owner: a per-cashier NO_CHARGE drilldown, a time window on the parent visit,
  or creator ≠ closer. None is decided.

### S6-Q1, S6-Q2, S6-Q3 (revised), S6-Q3a, S6-Q3b — hardening of Konsultasi & Kontrol Rp0 — `OWNER_POLICY_APPROVED` / `OWNER_POLICY_REVISED_AND_APPROVED`, `REPOSITORY_POLICY_RECORDED` (2026-11-01)

**Provenance.** S6-Q1, S6-Q2 and the revised S6-Q3 are stated in the owner brief of
`HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1` (2026-11-01, WITA). S6-Q3a and S6-Q3b are the
owner's in-session answers of the same day, each the recommended option among those presented. No signed
artefact exists; none is claimed.

| Code | Status | Owner answer (normalised) |
|---|---|---|
| S6-Q1 | `OWNER_POLICY_APPROVED` | A Kontrol is valid only with a real, same-patient, examined (`cashier_pending`/`completed`), non-cancelled, non-deleted parent visit, derived server-side. Otherwise it is not a valid Kontrol for Rp0 closure. |
| S6-Q2 | `OWNER_POLICY_APPROVED` | A lab line is never closed by `NO_CHARGE`; lab work uses normal billing and lab-candidate generation, even on a Kontrol. |
| S6-Q3 | `OWNER_POLICY_REVISED_AND_APPROVED` | A Kontrol may be Rp0. An additional treatment that is normally paid must be > Rp0, included in the total and paid normally. A mixed Rp0 + paid invoice uses regular payment and is never `NO_CHARGE`. A Kontrol link never makes a paid treatment free. Rp0 on a normally paid treatment without separate special authorization is not allowed. |
| S6-Q3a | `OWNER_POLICY_APPROVED` | Approved free services = the `consultation` category on any visit, plus the reserved `control` category (the Kontrol service) on a valid Kontrol only. No master data is created by the sprint. |
| S6-Q3b | `OWNER_POLICY_APPROVED` | The Rp0 issuance guard applies to Kontrol visits only and follows `rme.zero_value_closure`. Rp0 issuance on any other visit stays D21g, PENDING. |

**Supersessions (dated, explicit).** S6-Q3 supersedes the S6-D1 sub-decision "on a Kontrol visit every
free line is eligible". **The final S6-Q3 revision also supersedes the earlier unconditional maker–closer
separation recommendation (the "creator ≠ closer" option of the open S6-Q3) for Kontrol visits that contain
other treatments**: the control is eligibility, not a second account. **S6-D3 is preserved** — the branch
cashier closes, eligibility is system-checked, no second approval.

**Unchanged:** S6-D2, S6-D4 (D28), S6-D5, S6-D6, S6-D7, S6-D8 (flag OFF, separate activation decision); D21
manual pricing (no tariff mandate — a paid treatment's price is typed by the authorized cashier, it must only
be > Rp0); D21c; Sprint 2 NO_GO; Sprint 3–5 not activated; invoices #36, #42, #43 untouched.

**Technical enforcement is a separate status.** `TECHNICAL_ENFORCEMENT_DEPLOYED`: runtime `ba33f9bb` (tree `2d1b684b`, PR #576), deployed 2026-11-01 04:34 UTC (12:34 WITA), production-verified, tag `hardening-rme-rp0-closure-owner-decisions-and-paid-treatment-1-go`. It was recorded only after
the runtime of `HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1` was merged, deployed and verified
on production (rule 217 §9.7). The flag stays OFF either way.

## Pending decisions (unchanged)

D2, D3, D4, D5, D6, D8, D9, D11, D12, D13, D14, D15, D16, D17, D18, D20, D22, D23,
D24, D25, D26, D27 remain **PENDING**. D19 remains `APPROVED_SCOPE (rule 186)`.
*Since 2026-10-25:* D2, D8, D9, D12, D13, D14, D15, D17 and D23 are **answered for
post-payment correction only** by the ratified PP-2 … PP-8, and D19 is extended to
overpayment obligations by PP-9 (mapping in "Ratification of PP-1 … PP-10"). For every
other scope they remain PENDING. D4, D5, D6, D18, D25 and D26 (refund, settlement,
reversal, patient credit) are untouched and remain PENDING.
D28 (above) is an interim enforcement of an owner instruction, not a decision of
D21g or D22 — both stay PENDING.

*Since 2026-10-31 (`PREPARATION-RME-BILLING-REFUND-REQUEST-APPROVAL-1`, rule 213):* a decision
package for refund request & approval (Sprint 4/7) is written in
`docs/decisions/rme-refund-request-approval-owner-decision-package.md` as proposals **RF-1 … RF-14**
(RF-1 ↔ D4, RF-2 ↔ D18, RF-9 ↔ D5, RF-10 ↔ D25, RF-12 ↔ D6, RF-13 ↔ D26; the others are new
questions). **None was approved when the package was written.** D4, D5, D6, D18, D25 and D26 stayed
`PENDING_OWNER_DECISION` until the owner answers recorded the same day (next paragraph). The task brief's Sprint 4/Sprint 5 split is an owner task instruction about
scope and is not recorded as an approval of D18.
*Since 2026-10-31 (owner answers, see the D4 and D18 entries above):* **D4** is answered for refund request &
approval (RF-1, role-based and branch-scoped, mandatory Owner reference; RF-1a still PENDING); **D18** is
answered for the separation of approval from settlement (RF-2 = A). **D5, D6, D25 and D26 remain
`PENDING_OWNER_DECISION`**, as do RF-3, RF-5, RF-6, RF-7, RF-8, RF-8a, RF-11 and RF-14. Refund remains
NOT_IMPLEMENTED and NOT_ACTIVE; Sprint 4 implementation stays blocked until RF-1 … RF-8 are answered.
*Since the second owner answer of 2026-10-31 (entries above):* **D5** `OWNER_POLICY_APPROVED`
(`SPRINT_5_IMPLEMENTATION_PENDING`), **D6** and **D26** `OWNER_POLICY_APPROVED_EXCLUSION`, **D25**
`OWNER_POLICY_APPROVED` (`SETTLEMENT_ACTOR_PENDING`); RF-1a, RF-3, RF-5, RF-6, RF-7, RF-8, RF-8a, RF-11 and
RF-14 approved. Still PENDING in this register: D2, D3, D8, D9, D11–D17, D20, D22–D24, D27 for every scope
not already answered (D21c, D21g, D22 unchanged). Refund remains NOT_IMPLEMENTED and NOT_ACTIVE.
*Since 2026-11-01 (S6 entry above):* **D21g** and **D22** are answered **for the Rp0 Konsultasi /
Kontrol closure scope only** (S6-D1, S6-D2, S6-D6); for Rp0 issuance governance, vouchers and any Rp0
correction they remain PENDING, and D28 is unchanged.
See `docs/sprints/feature-rme-invoice-correction-receipt-foundation-1.md` §3 for
their text. Decision codes are per register — the KTP-OCR "D7" is a different decision.

## Activation requires (rule 195 §20, supersedes rule 191 §20)

1. Recorded decisions — D1, D7, D10, D21 (revised), **D1a, D1b, D1c, D1d** recorded
   2026-10-21. D21c and D21g stay PENDING, classified safe-to-defer (above).
2. ~~D21 enforcement + tariffs per billing branch~~ — not a prerequisite (rule 194).
3. `RoleSeeder` grant per the D1 table above, run on production AFTER the deploy,
   `PermissionSeeder` first, then `permission:cache-reset`.
4. Flag ON via the environment + config cache rebuilt as `daengtisiams`; the pilot
   allowlist (source-controlled) confines it to SPN4.
5. Production authorization probe `rme:invoice-correction-readiness --maker=29
   --checker=1` = GO.
6. Authenticated maker/checker pilot with the two D1d accounts — performed by those
   operators; never simulated by resetting a password or opening a REPL.
7. A real correction on an authorized positive UNPAID case + financial
   reconciliation — only when such a case exists; none is fabricated.

**Status 2026-10-21 (production `61a29795`):** steps 1–4 **DONE**. Step 5 reads
`STATE=PILOT_ACTIVE`, pair `VALID=yes`, `LIVE_NOW=no`, Decision **WATCH**; the
maker (user 29) was off shift (rule 195 §28b: off shift is a WATCH, never a FAIL).
A GO needs both accounts on shift in SPN4. Steps 6–7 are **NOT_PERFORMED**:
production holds 0 positive UNPAID invoices in SPN4. Scoped tag
`activation-rme-invoice-correction-prepayment-2-pilot-readiness-go`; the full tag
does not exist.

Steps 1–5 are technical readiness (scoped GO). Steps 6–7 are operational
verification; until both are evidenced the full activation GO tag is NOT created.

**Procedure recorded, 2026-10-23 (`PREPARATION-RME-INVOICE-CORRECTION-PILOT-OPERATIONAL-READINESS-1`,
rule 202; no decision made or changed).** Steps 6–7 now have a written counter
procedure in `docs/operations/rme-invoice-correction-*.md` (master SOP, case-specific
Owner approval form, maker/checker guide, reconciliation form, incident matrix,
operator handoff). Production re-measured that day: unchanged — `PILOT_ACTIVE`,
probe WATCH (`LIVE_NOW=no`), 0 positive UNPAID SPN4 invoices, 0 corrections.
D21c, D21g and D22 remain PENDING; D28 remains the interim guard.

- **OPS-OQ-1 — ANSWERED by the Owner (sprint brief received 2026-10-23; see "OPS-OQ-1 — owner policy" below).** As
  recorded on 2026-10-23 before the answer: *open owner question, not a decision.*
  The repository recorded no authorized clinic procedure for a patient whose
  invoice is known to be wrong while Owner approval of the correction is still
  pending (pay the issued amount under dispute, or leave with the invoice UNPAID),
  nor whether such an invoice may be corrected later. That text is kept as history;
  it no longer describes the system.

## OPS-OQ-1 — owner policy: an invoice known to be wrong (all branches)

**Status:** `OWNER_POLICY_APPROVED` · `REPOSITORY_POLICY_RECORDED` ·
`TECHNICAL_ENFORCEMENT_DEPLOYED` (2026-10-24, runtime `5b1d4262`, tag
`implementation-rme-billing-dispute-escalation-ops-oq-1-go`; rule 203).
**Approved by:** Owner, for **ALL branches**, as stated in the
`IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1` sprint brief received
2026-10-23. No separate signed artefact, approval reference or timestamp exists in
the repository and none is invented here. **Implemented by:**
`IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1` (rule 203).

The owner's answer, verbatim in substance:

1. Do not collect the amount known to be incorrect.
2. Do not modify the invoice unilaterally.
3. Escalate the dispute to the responsible branch authority.
4. Keep the invoice financially unresolved until a legitimate authorized resolution.
5. Do not mark it PAID, CANCELLED, or Rp0 merely because a dispute exists.
6. Do not keep the patient waiting merely to complete the Invoice Correction pilot.

**What it does NOT authorize.** It does not expand the invoice-correction pilot:
correction stays SPN4 only, maker 29 / checker 1 (D1c, D1e). It changes no
pending decision: D21c, D21g and D22 remain PENDING and D28 remains the interim
Rp0-original guard. It authorizes no refund, payment reversal, patient credit or
Rp0 adjustment. It is not a case-specific Owner approval of any invoice.

**How the system enforces it (rule 203).** A billing dispute
(`trx_rme_billing_disputes`) is a report, not a correction. While it is active the
invoice accepts no payment on any path (whole-batch refusal), the invoice is never
changed by it, the case is routed in-app to the branch authority, and only a
RESOLVED dispute with a verifiable outcome releases the hold — an applied correction
newer than the report (SPN4 only, through the existing maker/checker flow), or a
second account's confirmation that the unchanged original is correct.

**Consequence recorded, not a new decision.** Outside SPN4 there is no correction
path. An invoice there whose original amount is genuinely wrong therefore stays
disputed (ESCALATED) — financially unresolved, exactly as the policy says — until
the owner decides a correction path for that branch. No override was invented.

- **OPS-OQ-1a — open owner question, not a decision** *(as recorded 2026-10-23; ANSWERED 2026-10-24 — see the entry below).* Which branch-level account
  should hold `review_rme_billing_disputes` (start review, escalate, resolve)? Until
  the owner names one, the permission is granted to **no operational role**; only
  Super Admin (the existing `'*'` grant) can review and resolve, and the Owner has
  read-only `view_rme_billing_disputes`. Granting it to Admin Klinik would extend it
  to every Front Office account (FrontOfficeRole is the exact union) and let
  front-desk peers clear disputes raised at their own counter. Never answered in code.
- **OPS-OQ-1b — open owner question, not a decision** *(as recorded 2026-10-23; ANSWERED 2026-10-24 — see the entry below).* A control (follow-up) visit's
  payment must settle the carry-over parent invoice first (existing control-chain
  rule), so while that parent is disputed the control visit's OWN invoice cannot be
  collected and the visit stays at the cashier — whole-batch refusal, no silent
  omission. The patient is not physically held (they may leave), but the follow-up
  visit stays financially open. Should a control invoice be collectable on its own
  while its parent is disputed? Until the owner decides, the current behaviour stands
  and nothing is changed in code.

### OPS-OQ-1a and OPS-OQ-1b — answered (2026-10-24, rule 204)

Provenance: the owner's instruction in the brief of
`HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1`. No signed
artefact, approval reference or timestamp exists and none was created.

- **OPS-OQ-1a.** An explicitly designated branch responsible person may review and
  resolve branch-scoped billing disputes with legitimate resolution evidence.
  Implemented as one row per (account, branch) in
  `mst_rme_billing_dispute_reviewers`, created and revoked by an account holding
  `manage_rme_billing_dispute_reviewers` — a permission granted to **no operational
  role** (Super Admin via `'*'`). It is never a role grant, so it cannot reach the
  Front Office accounts through the Admin Klinik + Kasir union. Super Admin keeps its
  authority; the Owner stays read-only and cannot be designated; a designee is
  limited to the designated branch, re-checked as an active account working in that
  branch on every action, and gains no invoice edit, correction, refund, debt
  forgiveness, cancellation, manual PAID or guard override. **No designation is
  created by the deployment** — the owner names the person and Super Admin records it.
  Fail-closed interpretations recorded (not new authority, per the brief's §8 rule
  "if an additional policy decision is required, fail closed"): a **counter**
  account (Front Office, Kasir) is never designatable (brief §13: Front Office
  cannot review or resolve); eligibility is re-checked on every use (brief §12:
  revalidate on a role change); and a designee's "original is correct" must be
  **independent** — not on an invoice they issued or collected on (brief §14:
  independent confirmation). Should the owner ever want a counter account to review,
  that is a new decision.
- **OPS-OQ-1b.** A legitimate Kontrol invoice may be paid independently while its
  originating visit invoice is under an active billing dispute. Implemented as the
  separate, explicitly confirmed `payControlInvoiceOnly()` path: eligible only while
  EVERY outstanding same-branch origin is disputed (a mixed chain fails closed), the
  payment is allocated to the Kontrol invoice only, and the origin's receivable,
  status, version, history and dispute are unchanged. The ordinary combined payment
  and every batch that would settle a disputed origin are still refused whole. D10,
  D28, version binding (rule 191), OD-1 and OD-1a are preserved.

## Post-payment correction — owner decisions PP-1 … PP-10 — `OWNER_POLICY_APPROVED` (ratified 2026-10-25; proposed the same day)

**Ratified 2026-10-25** by `RATIFICATION-RME-POSTPAYMENT-OWNER-DECISIONS-PP1-PP10-1` (see "Ratification of PP-1 … PP-10" below). *Text as recorded by `PREPARATION-RME-POSTPAYMENT-INVOICE-CORRECTION-1` (rule 206), kept as history:* ~~**None of
these is approved.**~~ They are proposals for the owner, written in full (options,
recommendation, consequences) in
`docs/decisions/rme-postpayment-correction-owner-decision-proposals.md`. A proposal
becomes policy only when the owner states it and it is recorded here with
`OWNER_POLICY_APPROVED`. Until then post-payment correction stays
`NOT_IMPLEMENTED` / `NOT_ACTIVE`.

| Code | Subject | Related register codes | Status |
|---|---|---|---|
| PP-1 | Scope and enablement: separate default-OFF flag, SPN4, identities 29/1 | D1c, D1e | `OWNER_POLICY_APPROVED` |
| PP-2 | Post-payment approval authority, approve = apply, case-specific Owner approval | D2, D1a | `OWNER_POLICY_APPROVED` |
| PP-3 | Downward correction below money received: atomic overpayment obligation, no refund | D12, D4–D6, D18, D25 | `OWNER_POLICY_APPROVED` |
| PP-4 | Upward correction: ordinary outstanding balance at the invoice branch | D12, OD-1a | `OWNER_POLICY_APPROVED` |
| PP-5 | Issued receipts: snapshot + "DIREVISI" (applies D7 to receipts) | D7 | `OWNER_POLICY_APPROVED` |
| PP-6 | Reporting: cash KPI unchanged, obligations separate, invoice-branch attribution | D8, D9, D13 | `OWNER_POLICY_APPROVED` |
| PP-7 | Limits: no invented threshold or deadline; D21c/D28 unchanged | D14, D15, D17, D21c, D28 | `OWNER_POLICY_APPROVED` |
| PP-8 | Lab candidates: invoices with candidates stay refused; a correction does not generate candidates | D23 | `OWNER_POLICY_APPROVED` |
| PP-9 | Obligation visibility: read-only oversight; no write authority to anyone | D19 | `OWNER_POLICY_APPROVED` |
| PP-10 | Sequencing: activation waits for a verified Sprint 2 closure | — | `OWNER_POLICY_APPROVED` |

D2, D8, D9, D12, D13, D14, D15, D17, D23 remain **PENDING**; D21c, D21g and D22 remain
**PENDING**; D28 remains the interim guard.

## Production observation, 2026-10-25 — recorded, not verified

Read-only measurement by `PREPARATION-RME-POSTPAYMENT-INVOICE-CORRECTION-1` (psql,
`default_transaction_read_only=on`, no REPL, no write) found the first real
correction in production:

- `RMECOR-202610-000001` — invoice #42 (SPN4), `PRE_PAYMENT`, `APPLIED`;
  Rp6.000.000 → Rp5.000.000; submitted by user 29 at 2026-10-25 03:20:52 UTC,
  approved and applied by user 1 at 03:23:22 UTC (one transaction, D1a); document
  versions v0 ORIGINAL and v1 REVISION frozen; payment #50 Rp5.000.000 followed
  (invoice PAID), then a lab case candidate.
- The first dispute-reviewer designation also exists: user 11 for SPN4, recorded by
  user 1 at 03:21:58 UTC.

This observation does **not** mark Activation steps 6–7 done, does **not** state that
the case carried a case-specific Owner approval (the correction reason carries no
recognisable approval reference), and does **not** change Sprint 2's status. Only a
Sprint 2 closure verification may evaluate it.

**Evaluated 2026-10-25 (19:45–20:30 WITA) by `VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-REAL-CASE-42-1`
(rule 196 §9; read-only, no decision made or changed).** Evidence:
`docs/sprints/verification-rme-invoice-correction-prepayment-pilot-real-case-42-1.md`.

- The case ran on runtime `9e37b855` (the rule-205 release `20303a5b` began deploying
  at 04:08:31 UTC, after it).
- Financial reconciliation: **PASS (RECONSTRUCTED)** — v0/v1 integrity hashes hold,
  the fingerprint at request equals v0, the live invoice minus its payment equals v1,
  and every global delta since the 2026-10-24 baseline is attributed row by row.
  Payment #50 followed the apply (server time 03:24:00 > 03:23:22; the form carried
  the post-apply version).
- **Case-specific Owner approval: `UNVERIFIED`.** No approval reference in the reason
  ("salah input, karena beda harga"), an empty checker note, nothing in this register
  or any repository branch, and the clinic channel that holds rule-202 forms is not
  accessible to the task. The checker account holding the Owner role does not merge
  the Owner's authorization with the checker's verification. No approval was
  requested or recorded retroactively.
- Activation steps 6–7 therefore stay **NOT DONE**; Full Prepayment Pilot GO =
  **NO_GO** (`OPERATIONAL_OR_EVIDENCE_BLOCKED`); the full tag does not exist.
- **Human separation: `RED_FLAG_UNRESOLVED`.** The maker account acted from the same
  browser family and version the checker account had used for months, on a public IP
  the two accounts also shared on five earlier days. This proves nothing either way;
  the Owner is asked to establish who operated each account. Account separation
  (29 ≠ 1) holds.

## Production observation, 2026-10-31 — second real correction, evaluated (no decision made or changed)

Read-only measurement by `VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-CLOSURE-FINAL-1`
(rule 196 §10). Evidence:
`docs/sprints/verification-rme-invoice-correction-prepayment-pilot-closure-final-1.md`.

- `RMECOR-202610-000002` — invoice #43 (SPN4), `PRE_PAYMENT`, `APPLIED`; Rp9.000.000 →
  Rp12.000.000 (upward); submitted by user 29 at 2026-10-31 14:38:52 UTC, 25 s after the
  invoice; approved and applied by user 1 at 14:41:11–12 UTC (D1a); payment #51 Rp12.000.000
  at 14:41:32 UTC; runtime `a550e00d`. The run began measuring at 14:49:11 UTC.
- Financial reconciliation: **PASS (RECONSTRUCTED)** — v0/v1 integrity hashes hold, the
  fingerprint at request equals v0, v1 equals the proposal and the live invoice, every global
  delta since the Sprint 5 baseline is attributed.
- **Case-specific Owner approval: `UNVERIFIED`** — no `owner_approval_reference`, an empty
  decision note, and a reason ("salah harga pada card rate") without a reference. The branch
  holds no tariff row for the treatment, so "card rate" cannot be checked in the system.
- **Human separation: `RED_FLAG_UNRESOLVED`** — maker, checker and treating-doctor sessions on
  one network address; zero verifier SSH sessions to the VPS during the case window.
- Warning signs recorded for the Owner (not adjudicated): visit-to-payment in 6 min 21 s,
  correction 25 s after the invoice, 22:35 WITA on a Saturday, completion just before the
  closure task began.
- Activation steps 6–7 stay **NOT DONE**; Full Prepayment Pilot GO = **NO_GO**
  (`OPERATIONAL_OR_EVIDENCE_BLOCKED`); the full tag does not exist. No approval was requested or
  recorded retroactively.

### Addendum 2026-10-31 — Owner clarification for invoice #43 (`UPDATE-SPRINT2-CASE43-OWNER-CLARIFICATION-1`)

Status: `OWNER_RETROSPECTIVE_ATTESTATION` — recorded as stated, made after the submit
(14:38:52 UTC). It is **not** a written approval from before the submit and is not entered
into the correction's reason, note or reference fields. No decision is made or changed.

The Owner states:

1. The patient visit was real; the patient was physically present.
2. The pricing error came from using a different rate card.
3. Owner approval was given before 2026-10-31 22:38:52 WITA.
4. Account identities: user 29 = Admin Klinik; user 1 = IT; user 21 = drg. Aisyah.

Recorded result (evidence: §19 of
`docs/sprints/verification-rme-invoice-correction-prepayment-pilot-closure-final-1.md`):

- Visit: `OWNER_CONFIRMED_REAL`.
- Pricing: `OWNER_EXPLAINED_RATE_CARD_DIFFERENCE`. SPN4 holds no tariff row and no rate-card
  document is in the system; no tariff is set or inferred from this statement.
- Pre-submission approval: `OWNER_ATTESTED_EVIDENCE_PENDING_VERIFICATION`. The original record
  from the clinic's official channel, with that channel's own timestamp, the invoice reference,
  Rp9.000.000 → Rp12.000.000 and the change, is still required (rule 196 §32, §49–§50). The
  invoice existed only from 22:38:27 WITA, 25 s before the submit.
- Accounts: `OWNER_IDENTIFIED`, consistent with the system — 29 "Admin Sunu" (Front Office),
  1 "IT Support" (Super Admin + Owner), 21 "drg Aisyah" (Doctor, the visit's doctor). Account
  separation is system-verified; human separation for #43 is `OWNER_ATTESTED_DISTINCT`, not
  system-verifiable. A shared network address is not read as evidence either way.
- Role note for the Owner: the IT account (user 1) holds the `Owner` role; its checker approval
  is not the Owner's case approval. Whether it should keep that role is the Owner's
  least-privilege decision; no role was changed.
- Unchanged: financial reconciliation `PASS_RECONSTRUCTED`; live observation not performed;
  case #42's `RED_FLAG_UNRESOLVED`; Full Prepayment Pilot GO = **NO_GO**; no tag.

## Production observation, 2026-11-01 — case #44 decided under observation; S2-V1, S2-V2

Evidence: `docs/sprints/verification-rme-invoice-correction-prepayment-pilot-case-44-1.md`;
rule 196 §11 (§53–§62). Changes no runtime behaviour.

Correction `RMECOR-202611-000003` (invoice #44 SPN4, Rp6.500.000 → Rp7.000.000, +Rp500.000,
reason "salah ketik", `owner_approval_reference` NULL) was submitted by account 29 at 09:59:19 WITA,
44 s after the invoice, and decided by account 1 at 15:41:11 WITA (APPROVED and APPLIED in one
transaction) under observation. Decision-step reconciliation: PASS (observed). Full Prepayment
Pilot GO = **NO_GO** (`NO_GO_OPERATIONAL_BLOCKED`); no tag.

### Owner statements on case #44 — `OWNER_RETROSPECTIVE_ATTESTATION` (in session, 2026-11-01)

Recorded as stated, after the submit. Not entered into any correction field.

1. A written Owner approval for this case existed before the submit (09:59:19 WITA).
2. The Owner instructed IT to approve; IT held the approval "to use it to close Sprint 2".

Recorded result:

- Pre-submission approval: `OWNER_ATTESTED_EVIDENCE_PENDING_VERIFICATION` (rule 196 §32, §50).
  The approval note entered by the checker is the field label "Catatan Persetujuan", not a
  reference (§58).
- The hold kept the patient's payment blocked by D10 for 5 h 41 min 52 s. Rule 196 §8 / §21 and
  Owner directive item 16 forbid delaying a payment to keep a case in the pilot window, so case #44
  is `NOT_ELIGIBLE_FOR_CLOSURE` (§53). This is a statement about the case, not about any person.

### S2-V1 — authorized verifier — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` (in session, 2026-11-01)

Question: who is the authorized verifier that observes the checker's decision on a pilot case
(Owner directive item 15)? Answer chosen: **the Owner witnesses directly**; IT captures the
server-side evidence (probe, fresh baseline, login and session rows, reconciliation) over the
canonical SSH path. The verifier is engaged before the submit for any case meant to close the
pilot. IT acting as checker (account 1) is never the Owner's case approval (directive item 14).

### S2-V2 — deploys while a correction is active — `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` (in session, 2026-11-01)

Question: may an unrelated runtime deploy proceed while a pilot correction is `SUBMITTED` or
`APPROVED`? Answer chosen: **only in a window coordinated with the checker** — the checker does
not act during the deploy; the correction's status and `active_corrections` are confirmed
immediately before and after; the runtime SHA is recorded on both sides. With no active correction
the window is free. Rule 196 §60.

## Ratification of PP-1 … PP-10 (2026-10-25)

**Status:** `OWNER_POLICY_APPROVED` · `REPOSITORY_POLICY_RECORDED`. **Not**
`TECHNICAL_ENFORCEMENT_DEPLOYED`: post-payment correction is `NOT_IMPLEMENTED` /
`NOT_ACTIVE` until `FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1` ships, and its
activation is blocked by PP-10 because Sprint 2 is still NO_GO.

**Provenance.** The owner directive in the brief of
`RATIFICATION-RME-POSTPAYMENT-OWNER-DECISIONS-PP1-PP10-1`: *"PP-1 THROUGH PP-10 = OWNER
APPROVED"*, applying to the canonical proposal text in
`docs/decisions/rme-postpayment-correction-owner-decision-proposals.md` where it is
unambiguous and does not contradict a higher financial rule. No signature, approval
reference or approval time was invented. This approval is not a case-specific Owner
approval of any invoice (rule 196 §9).

### Mapping — what was approved, and which register codes it answers

| PP | Approved option (canonical "Usulan") | Answers | Scope limit |
|---|---|---|---|
| PP-1 | A — separate default-OFF flag `rme.invoice_postpayment_correction`, SPN4 allowlist and identities 29/1, source-controlled | extends D1c, D1e to post-payment | activation is a separate task |
| PP-2 | A — maker 29, checker 1, maker≠checker per account (Super Admin included), approve = apply, **case-specific Owner approval reference mandatory** | **D2**; extends D1a | post-payment only |
| PP-3 | A — downward correction below money received records an atomic OPEN overpayment obligation; no money leaves the system; further correction refused while OPEN. **B was not elected** (the proposal offered it only "if the Owner wants it staged"); C not elected | **D12** (downward) | no refund, no negative receivable, no offset, no patient credit |
| PP-4 | A — upward correction creates an ordinary balance, status re-derived (PAID → PARTIAL), collected at the invoice branch by a normal payment; no automatic billing; visit not reopened | **D12** (upward), with OD-1a | — |
| PP-5 | A — receipts snapshotted at issuance; old receipts rendered from the snapshot with "DIREVISI — lihat versi ke-N"; obligation document "KELEBIHAN BAYAR — BELUM DIKEMBALIKAN"; an **activation prerequisite** | extends **D7** to issued receipts (resolves its CONFLICTING technical note) | — |
| PP-6 | as proposed — cash KPI unchanged; "billed" uses the current version with corrections visible; closed periods not restated; OPEN obligations reported separately, not deducted from revenue until a refund is SETTLED; attribution to the invoice branch | **D8**, **D9** (correction reporting), **D13** | refund netting itself belongs to the refund sprints |
| PP-7 | as proposed — no invented threshold or deadline; supporting document optional, approval reference mandatory; discount bound same as pre-payment; D28 stays | **D14**, **D15**, **D17** | D21c stays PENDING |
| PP-8 | as proposed — (a) invoices with lab candidates refused; (b) a correction never generates candidates | **D23** (post-payment) | — |
| PP-9 | as proposed — obligations read-only to the Owner and oversight roles (`ClinicOversightScope`); the invoice-branch cashier sees obligation status on the invoice; nobody gets write authority; dispute reviewers gain no financial power | extends **D19** | — |
| PP-10 | as proposed — activation waits for a verified Sprint 2 closure (RG-0); flag-OFF implementation may proceed | sequencing | Sprint 2 = NO_GO on 2026-10-25 → **activation blocked** |

Every proposal names one recommended option, so no mutually exclusive choice was left
open and nothing had to be selected by the implementer. Still PENDING and **not**
touched: D21c, D21g, D22 (D28 stays the interim Rp0-original guard); D4, D5, D6, D18,
D25, D26 (refund, settlement, reversal, patient credit — Sprint 4/7 and 5/7).

### Policy conflict review

| Area | Finding | Resolution |
|---|---|---|
| D7 / immutable snapshots | The technical note "no issued receipt is ever invalidated" assumed UNPAID-only correction | PP-5 extends D7 to receipts; the note stays true for pre-payment and is superseded only for post-payment (D7 note above). Historical snapshots are kept; a revision is a new document. Not a substantive policy change |
| Pre-payment correction | `applyLocked()` asserts UNPAID + no payment | stays for PRE_PAYMENT; post-payment is a separate type branch in the same engine (rule 206 §4, §10) |
| Receipt rendering | receipts render LIVE today | PP-5 makes the snapshot an activation prerequisite; legacy receipts without a snapshot must never be presented as an identical reprint |
| Approval authority | PP-2 vs D1e | consistent: same two accounts, same per-account maker≠checker |
| Financial adjustment | — | a correction never writes a payment, refund, reversal or settlement row (rule 189/206) |
| Overpayment | PP-3 vs "no negative receivable" (rule 206 §12) | consistent: an OPEN obligation is separate from the receivable |
| Refund request/approval | D4, D5, D18 PENDING *(2026-10-25; since 2026-10-31 D4, D5 and D18 are answered — see their entries)* | PP-3 creates the obligation only; no refund path (Sprint 4/7) |
| Refund settlement | D25 PENDING *(2026-10-25; since 2026-10-31 answered as Sprint 5 policy, settlement actor pending)* | none (Sprint 5/7) *(since 2026-10-31: implemented and deployed, rule 216, runtime `a550e00d`, flag OFF, settler list EMPTY — the settler designation is still the Owner's)* |
| Laboratory | PP-8 vs D23 pre-payment refusal | consistent: refused on both types |
| Branch visibility | PP-6/PP-9 vs OD-1a, `ClinicOversightScope` | consistent: invoice branch owns correction, obligation and balance; oversight read-only |
| Activation order | PP-10 vs Sprint 2 NO_GO | activation blocked; implementation with the flag OFF allowed |
| Human separation (Sprint A) | `RED_FLAG_UNRESOLVED` on accounts 29/1, the same pair PP-1 names | not a policy conflict; an Owner follow-up that must be resolved before any activation of either correction type relies on the pair |

**Implementation (2026-10-25):** `FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1` built
PP-1…PP-9 behind `rme.invoice_postpayment_correction` (default **OFF**, rule 207).
Technical status `IMPLEMENTED_FLAG_OFF`; `FEATURE_ENABLED = NO`; activation (PP-10)
remains BLOCKED while the pre-payment pilot is NO_GO. No decision in this register is
changed by the implementation; D21c, D21g, D22 stay PENDING.

**Result: `OWNER_DECISION_RATIFICATION = GO`** — all ten decisions map unambiguously
and consistently; no blocker in the ratification itself.
