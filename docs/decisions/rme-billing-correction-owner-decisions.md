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

## Pending decisions (unchanged)

D2, D3, D4, D5, D6, D8, D9, D11, D12, D13, D14, D15, D16, D17, D18, D20, D22, D23,
D24, D25, D26, D27 remain **PENDING**. D19 remains `APPROVED_SCOPE (rule 186)`.
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
