# PREPARATION-RME-BILLING-REFUND-REQUEST-APPROVAL-1

Program `DAENGTISIAMS-RME-BILLING-CORRECTION` · **Sprint 4/7 — refund request & approval (preparation)**
· 2026-10-31 (WITA) · type `DOCS_ONLY` · rule 213 · ADR 0013

| | |
|---|---|
| Branch | `docs/preparation-rme-billing-refund-request-approval-1` (cut from the remote base) |
| Canonical base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `f60d8232` |
| Production runtime | `fb3726c5`, tree `e25afb7a`, tag `post-audit-f4bc-release-1-go` (re-verified this task) |
| Runtime change | **none** — no code, migration, permission, route, flag, seeder or deploy |
| Owner decisions | written: D4, D5, D6, D18, D25, D26 **PENDING**, RF-1…RF-14 proposed. **Since 2026-10-31:** all answered in two owner statements — D4, D18, D5, D25 `OWNER_POLICY_APPROVED`, D6 and D26 `OWNER_POLICY_APPROVED_EXCLUSION`, RF-1 … RF-14, RF-1a, RF-8a approved with modifications (§3.9, §3.10) |

## Deliverables

| # | Deliverable | File |
|---|---|---|
| 1 | SPRINT-4-CURRENT-STATE-AUDIT | this document, §1 |
| 2 | SPRINT-4-OWNER-DECISION-PACKAGE | `docs/decisions/rme-refund-request-approval-owner-decision-package.md` |
| 3 | SPRINT-4-REFUND-REQUEST-APPROVAL-DESIGN | `docs/architecture/rme-refund-request-approval-design.md` (service architecture, role/permission matrix, Sprint 4 vs 5, rollback plan) |
| 4 | SPRINT-4-DATABASE-MIGRATION-PLAN | `docs/architecture/rme-refund-request-approval-migration-plan.md` |
| 5 | SPRINT-4-SECURITY-THREAT-MODEL | `docs/architecture/rme-refund-request-approval-threat-model.md` |
| 6 | SPRINT-4-TDD-MATRIX | `docs/architecture/rme-refund-request-approval-tdd-matrix.md` |
| 7 | SPRINT-4-FINANCIAL-RECONCILIATION-CONTRACT | `docs/architecture/rme-refund-financial-reconciliation-contract.md` |
| 8 | SPRINT-4-RELEASE-GATE-CHECKLIST | `docs/operations/rme-refund-request-approval-release-gate-checklist.md` |
| 9 | SPRINT-4-FINAL-PREPARATION-REPORT | this document, §3 |
| — | ADR | `docs/adr/0013-rme-refund-request-approval.md` |
| — | SOP draft | `docs/operations/rme-refund-request-approval-sop-draft.md` |
| — | Durable rule | `.cursor/rules/213-rme-billing-refund-request-approval-preparation.mdc` (+ one row in `.claude/rules/rme-billing.md`) |
| — | Register note | `docs/decisions/rme-billing-correction-owner-decisions.md` ("Pending decisions": RF package recorded, nothing approved) |
| — | Supersession banner | `docs/architecture/rme-billing-refund-technical-design.md` (2026-10-16 design, superseded in part) |

---

## 1. SPRINT-4-CURRENT-STATE-AUDIT

Method: source read on the canonical base; production read in a session forced read-only
(`default_transaction_read_only=on`) returning counts and schema facts only, plus read-only artisan
reports run as the application's runtime user. No production write; the application log byte size was
identical before and after (no new log line).

### 1.1 Production facts (2026-10-31)

| Fact | Value |
|---|---|
| PostgreSQL | 16.15 |
| HEAD / tree / tag | `fb3726c5` / `e25afb7a` / `post-audit-f4bc-release-1-go`; deploy status `exit=0`; one untracked runtime directory, no tracked change |
| Latest migration | `2026_10_25_200003_create_trx_rme_receipt_snapshots_table` (nothing pending since Sprint 3) |
| `rme.invoice_correction` | ON (resolved from the environment) — pre-payment pilot, `STATE=PILOT_ACTIVE`, SPN4, maker 29 / checker 1 |
| `rme.invoice_postpayment_correction` | OFF (default) — `POSTPAYMENT_STATE=NOT_ACTIVE` |
| Refund flag / tables / permissions | none / none / none |
| Invoices by status | PAID 6, PARTIAL 1, UNPAID 1 |
| Payments | 9 (0 with amount ≤ 0) |
| Payment submissions / receipt snapshots | 0 / 0 — no RME payment has been taken since those foundations deployed |
| Corrections | 1 (`PRE_PAYMENT`, `APPLIED`); document versions 2 |
| Overpayment obligations / events | **0 / 0** |
| Billing disputes | 0 |
| Overpaid invoices (`Σ payments > grand_total`) | **0**; overpaid without an OPEN obligation: **0** |
| Obligation constraints | `trx_rme_ovp_status_chk` = `status = 'OPEN'`; `trx_rme_ovp_amount_chk` = `amount > 0`; partial UNIQUE `trx_rme_ovp_one_open_uq` |
| `trx_rme_payments` CHECK constraints | none (amount > 0 is application-enforced) |

### 1.2 Area-by-area audit

| # | Area | State (VERIFIED) | Consequence for Sprint 4 |
|---|---|---|---|
| 1 | Invoices / items | Created only by `RmeInvoiceService::create()`; items/totals changed only by `RmeInvoiceCorrectionService`; no edit/void route (AIR-RMB-16) | read only |
| 2 | Payments | Single writer in `RmePaymentService` (paths `pay`, `allocateControlPayment`, `payControlInvoiceOnly`, `allocateVisitPayment`); model refuses update (except `patient_id`) and delete; amount capped at remaining under the invoice lock | a refund never writes one |
| 3 | Payment submissions | `RmePaymentSubmissionService::record()` in the payment transaction; UNIQUE `intent_key`; HMAC intent keyed on the application key, bound to an invoice id | pattern reused with a refund-specific context |
| 4 | Invoice corrections | One engine; lock order invoice → correction → obligation; maker ≠ checker in the service incl. Super Admin; POST_PAYMENT needs both flags and an Owner reference (`owner_approval_reference`, ≤ 120 chars, DB CHECK) | SOD and reference patterns reused |
| 5 | Document versions | Immutable SHA-256 snapshots written by the correction engine | untouched |
| 6 | Receipt snapshots | Written on every payment inside its transaction; reconstructed for old payments by a post-payment apply | untouched |
| 7 | Overpayment obligations | Single producer `openObligation()`; immutable row; status `OPEN` only; one OPEN per invoice; PP-3 refuses further correction while OPEN | **the only refundable source; never written in Sprint 4** |
| 8 | Billing disputes | Accept UNPAID/PARTIAL/PAID invoices; hold payment while active; resolve only `CORRECTION_APPLIED` or `ORIGINAL_CONFIRMED_CORRECT` | refund refused while a dispute is active [RF-7]; finding F-06 |
| 9 | Receivables | Keyed off invoice status/remaining; an overpaid invoice is `PAID`, never a negative receivable | unchanged |
| 10 | Finance / oversight | Obligations visible read-only (`OversightOverpaymentObligationController`, `ClinicOversightScope`, 404 out of scope); `DmoMetricService` net revenue = collected payments, no refund term | refund oversight extends the same area [RF-8]; netting is Sprint 5 |
| 11 | Branch isolation | `RmeWorkingBranchScope` from the persisted actor; invoice branch from the visit; out of scope 404 | branch from the obligation; per-branch authority |
| 12 | Maker-checker | Per account, in the service, under the lock, Super Admin included; pilot identity allowlist source-controlled | reused |
| 13 | Audit trail | `RmeFinancialAuditEvent` inside the fact's transaction; refusals after rollback; PII-free payloads; correction/obligation/dispute event tables append-only | new refund entity/actions |
| 14 | Payment idempotency | rule 205 live; replay answered with the original | reused |
| 15 | Refund foundation | Phase 0 prerequisites (rule 189) + obligations (rule 207); **no refund code** | this package |

### 1.3 Financial write-path inventory

| Table | Writer(s) | Transaction / lock |
|---|---|---|
| `trx_rme_invoices` | `RmeInvoiceService::create`; `RmePaymentService` (status refresh); `RmeInvoiceCorrectionService::apply` (totals, status, version) | invoice `FOR UPDATE` (`findForUpdate`); visit `FOR NO KEY UPDATE` on create |
| `trx_rme_invoice_items` | `RmeInvoiceService::create` (calls `RmeInvoiceItem::create` directly — F-16); `RmeInvoiceCorrectionService::apply` (repository `createItem`/`updateItem`/`deleteItem`) | invoice lock |
| `trx_rme_payments` | `RmePaymentService` (one private create, four public paths) | invoice locks in fixed order |
| `trx_rme_payment_submissions` | `RmePaymentSubmissionService::record` | payment transaction |
| `trx_rme_receipt_snapshots` | `RmeReceiptSnapshotService::issueForPayment`, `::reconstructMissing` (post-payment apply) | payment / correction transaction |
| `trx_rme_invoice_corrections` + lines + events | `RmeInvoiceCorrectionService` (`submit`, `approve`, `reject`, `cancel`, `apply`) | invoice → correction |
| `trx_rme_invoice_document_versions` | `RmeInvoiceCorrectionService` (freeze) | correction transaction |
| `trx_rme_overpayment_obligations` + events | `RmeInvoiceCorrectionService::openObligation` only | invoice → correction → obligation |
| `trx_rme_billing_disputes` + events | `RmeBillingDisputeService` (no financial column) | invoice `FOR UPDATE` |
| `mst_rme_billing_dispute_reviewers` | `RmeBillingDisputeReviewerService` | — |

No console command writes these tables on production; `stress:seed-rme-history` and `loadtest:baseline-run`
are non-production harnesses.

### 1.4 PostgreSQL 16 compatibility

Production and CI run PostgreSQL 16. Every proposed constraint (CHECK, partial UNIQUE, regex CHECK on keys)
is PostgreSQL-only and created under a driver check, as the obligation and submission migrations already do.
The proposed concurrency tests must run on PostgreSQL 16 (SQLite compiles `lockForUpdate` to nothing). A
unique violation aborts the transaction on PostgreSQL and must be translated at the transaction boundary,
never caught and continued inside it.

### 1.5 Findings

| ID | Severity | Finding | Disposition |
|---|---|---|---|
| F-01 | INFO | Refund absent in code and production; `refund_terms` is printed text only | as expected (AIR-RMB-35) |
| F-02 | **ACTIVATION BLOCKER** | No producer is active: 0 obligations, post-payment correction OFF, PP-10 blocks it while Sprint 2 is NO_GO | refund activation ordered after it (RF-3, RG-3) |
| F-03 | DESIGN CONSTRAINT | Obligation row immutable; status CHECK `OPEN` only | Sprint 4 never writes it; Sprint 5 widens narrowly |
| F-04 | DESIGN CONSTRAINT (Sprint 5) | `overpaidCents = paid − total` with append-only payments would still read "overpaid" after a settled refund | reconciliation contract RFS-1/RFS-2 |
| F-05 | DESIGN CONSTRAINT | Every invoice mutator locks the invoice `FOR UPDATE`; corrections lock invoice → correction → obligation | refund order invoice → obligation → request |
| F-06 | POLICY GAP | A dispute **filed after** an obligation opened can only resolve `ORIGINAL_CONFIRMED_CORRECT` until the obligation closes (PP-3 refuses further corrections); one filed before the source correction was applied can still resolve `CORRECTION_APPLIED` (the correction engine has no dispute check) | surfaced to the owner (RF-7); not changed |
| F-07 | LOW | Obligation numbers use `now()->format('Ym')` (UTC) rather than the `ClinicalClock` month | separate policy-free fix proposed (design §14); 0 rows exist |
| F-08 | LOW | `trx_rme_payments` has no DB CHECK `amount > 0` | separate hardening proposed (design §14); 0 violating rows |
| F-09 | REUSE + TRAP | Payment intent is bound to an invoice id and keyed on the application key; reused as-is it would validate for a refund form of the same invoice | refund-specific HMAC context (threat T-20) |
| F-10 | GOVERNANCE | Pilot pair 29/1 carries `HUMAN_SEPARATION=RED_FLAG_UNRESOLVED` from Sprint 2 | RF-1a; activation gate RG-3 |
| F-11 | VERIFIED ROLES | `request_rme_invoice_correction`: Kasir, Front Office; `approve_rme_invoice_correction`: Admin Klinik, Front Office; `apply_rme_invoice_correction`: no operational role; Finance Klinik / Data Analyst hold `view_billing_correction_oversight` + `export_oversight_data` and **not** `manage_rme_billing` (its line 750 occurrence is the Admin Lab revoke list) | role matrix proposal (design §8.1) |
| F-12 | OBSERVATION | 0 payment submissions and 0 receipt snapshots in production: no RME payment since those deploys; live issuance still NOT_YET_OBSERVED | unchanged |
| F-13 | SUPERSESSION | The 2026-10-16 refund design predates obligations (single table incl. settlement; balance from `Σ payments − grand_total`) | banner added; new design supersedes in part |
| F-14 | MAINTENANCE | 7 PostgreSQL race suites list `trx_rme_overpayment_obligations` in cleanup; `PatientOwnedRecordRegistry` lists obligations | migration plan §4 |
| F-15 | OBSERVATION | Printed terms: Kawat Gigi refund within 2×24 h; refund "returned at the end of the month" — not register policy | RF-9, RF-14 |
| F-16 | LOW (pre-existing) | `RmeInvoiceService::create` writes invoice items through the model (`RmeInvoiceItem::create`) rather than the repository — a CG-04 layering deviation; the correction path uses the repository | recorded, not changed; refund code must not copy it |
| F-17 | MEDIUM (pre-existing, latent) | The enforcement of the ratified PP-2 rule "an Owner reference is not reused on another invoice" needs strengthening before post-payment correction can be activated. The analysis is kept in a private security record, not in this public repository. Latent — post-payment correction is OFF and production holds 0 post-payment corrections | **separate security hardening** `HARDENING-RME-OWNER-REFERENCE-REGISTRY-1`, NOT_STARTED, before any post-payment activation (§4); **not fixed in runtime** |
| F-18 | INFO | Owner does **not** hold `view_billing_correction_oversight`; the billing-correction oversight route group is gated on it, so Owner cannot read obligation oversight today despite PP-9 naming Owner as a reader | surfaced as RF-8a |

---

## 2. Owner decisions — summary

Full text: `docs/decisions/rme-refund-request-approval-owner-decision-package.md`.

| Decision | Status |
|---|---|
| D4 (via RF-1, except RF-1a) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` (2026-10-31, §3.9) |
| D18 (via RF-2 = A, separation of approval from settlement only) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` (2026-10-31, §3.9) |
| D5, D6, D25, D26 | `PENDING_OWNER_DECISION` |
| D16, D24, D27 (dependencies) | `PENDING_OWNER_DECISION` |
| PP-2, PP-3, PP-6, PP-9, PP-10, OPS-OQ-1 (dependencies) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` |
| RF-1…RF-8 (block Sprint 4 implementation) | **RF-1, RF-2, RF-4 answered 2026-10-31**; RF-1a, RF-3, RF-5, RF-6, RF-7, RF-8, RF-8a proposed, not approved |
| RF-9…RF-11 (block Sprint 5) | proposed, **none approved** |
| RF-12…RF-14 (scope exclusions) | proposed, **none approved** |
| No self-approval; audit permanent (cross-cutting) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` (2026-10-31) |

---

## 3. SPRINT-4-FINAL-PREPARATION-REPORT

### 3.1 What was done

* Audited 15 areas against code and read-only production; inventoried every financial write path.
* Wrote the owner decision package, design, migration plan, threat model, TDD matrix, reconciliation
  contract, release gates, SOP draft, ADR 0013 and rule 213.
* Recorded in the register that a package exists and nothing is approved.

### 3.2 What was not done (deliberately)

* No refund code, table, permission, route, flag or seeder; no settlement of any kind.
* No production write, no financial test data in production, no deploy, no merge.
* No change to the application key, the environment file or its copies, custody media, SSH configuration,
  or the re-encryption command. G7-B status unchanged.
* The policy-free items in design §14 were proposed, not implemented.

### 3.3 Verification

| Check | Result |
|---|---|
| `sprint:manifest-check` | GO (base resolved from the remote, `f60d8232`) |
| `sprint:scope-audit --strict` | GO, 0 modules touched |
| `git diff --check` | clean |
| Instruction system, programme-closure contract and sprint tooling tests (`DevflowAiInstructionSystem\|LegacyRmeProgramClosureContract\|DevflowSprintTooling`) | 57 passed, 2385 assertions |
| Sensitive-literal scan of every changed file (environment-file literal, 13+ digit runs) | none |
| CI classifier for this change set | `unknown_high_risk` (rule and manifest are not Markdown) — every required gate would run |
| Full Suite | not run; deferred under the active temporary policy (CG-18); never reported as passed |
| Independent review | see §3.4 |

### 3.4 Independent review

An independent adversarial review (fresh context, given the files and the code, not this document's
conclusions) checked policy inference, factual accuracy against the code, financial-integrity design,
internal consistency, privacy and scope. Each load-bearing claim was re-verified against the code before
acting on it. **Every "Fixed" below is a change to the design documents. No runtime code was written or
changed; the pre-existing runtime gap F-17 is not fixed (§4).** Result and disposition:

| Severity | Finding | Disposition |
|---|---|---|
| **HIGH** | Owner-reference reuse: the first draft checked only other refunds and the source correction, with a case-sensitive index, so one Owner approval could authorise a correction on one invoice and a refund on another; the reverse direction was also open | **Fixed in the design documents** (no runtime change): case-folded comparison against every refund and every post-payment correction, both directions; `lower()` index; shared consumed-reference registry (migration plan §2a); TDD RR-REF-06…09; threat T-11, T-25; RFI-10; F-17 records the pre-existing correction-side gap |
| MEDIUM | Rule 213 labelled three proposals as VERIFIED (obligation-only source, lock order of a non-existent table, per-branch authority) | **Fixed**: verified facts separated from proposals; per-branch authority tagged RF-1 option A |
| MEDIUM | Owner does not hold `view_billing_correction_oversight`, so "no new permission" could not give Owner visibility | **Fixed**: design and TDD corrected; RF-8a added; F-18 |
| MEDIUM | Patient-reference "not reused" had no index, code or test | **Fixed**: reuse rule defined (other obligations, case-folded), code `REFUND_PATIENT_REFERENCE_REUSED`, RR-PAT-01…03; no index possible, stated |
| MEDIUM | Threat model cited non-existent test IDs and gate | **Fixed** (RR-ELIG-01/02, RG-3, RR-IDEM-08) |
| LOW | Payment path locks with `RmeInvoice::query()->lockForUpdate()`, not `findForUpdate()` | **Fixed** in design and rule (same lock, method claim corrected) |
| LOW | Intent signature without a nonce | **Fixed**: nonce included, as `RmePaymentIntent` does |
| LOW | Approve/revoke locked "the invoice's open obligation" instead of the request's own | **Fixed**: lock by the request's obligation id and assert it is the open one; RR-LIFE-10 |
| LOW | Dispute deadlock stated too broadly | **Fixed** (F-06 narrowed) |
| LOW | Correction config wrongly described as per-branch | **Fixed** |
| LOW | Settlement unique only per request | **Fixed**: also per obligation (RFS-3, migration §6) |
| LOW | Revoker not bound to the branch checker list | **Fixed** (design §7.4, RR-LIFE-09) |
| LOW | Replay/race outcomes inconsistent between design and TDD | **Fixed** (design §7.1 step 5, §7.2 step 3; RR-IDEM-05, RR-CONC-03) |
| LOW | Rollback "flag OFF" vs revoke needing the flag ON | **Fixed**: revoke first, then flag OFF (AIR-RMB-23 order) |
| LOW | PP-10 label incomplete; RG-3 rows untagged | **Fixed** |
| — | Privacy / public repository | none found |
| — | Scope (settlement, keys, environment file, custody) | none found |

No CRITICAL finding. No HIGH finding remains open.

### 3.5 Final gate

```
SPRINT_4_PREPARATION            = GO (all deliverables written; review HIGH fixed in the design documents; checks in §3.3 and §3.7 pass)
OWNER_DECISION_STATUS           = PENDING (D4, D5, D6, D18, D25, D26; RF-1…RF-14 proposed, none approved)
SPRINT_4_IMPLEMENTATION         = BLOCKED_BY_OWNER_DECISION (RF-1…RF-8)
IMPLEMENTATION_SAFE_TO_START    = NO
REFUND_REQUEST_APPROVAL_READY   = NO
REFUND_SETTLEMENT               = NOT_AUTHORIZED
PRODUCTION_FINANCIAL_MUTATION   = NONE
PRODUCTION_DEPLOYMENT           = NOT_EXECUTED
G7_B_STATUS                     = UNCHANGED
```

Sprint 4 is **not** complete: only its preparation is. *(The owner-decision line above is superseded in part
by §3.9: on 2026-10-31 the owner answered RF-1, RF-2, RF-4 and two cross-cutting rules. Implementation stays
blocked.)*

### 3.6 Owner decisions required, in order

1. RF-1 (D4) approval authority, incl. RF-1a pilot identities and the human-separation red flag.
2. RF-2 (D18) approval ≠ settlement.
3. RF-3 scope, flag, pilot, activation order.
4. RF-4 full vs partial amount.
5. RF-5 evidence at request.
6. RF-6 rejection and cancellation semantics.
7. RF-7 refund refused while a dispute is active (and awareness of finding F-06).
8. RF-8 visibility and reporting, incl. RF-8a (grant Owner `view_billing_correction_oversight`?).
9. Before Sprint 5: RF-9 (D5) method, RF-10 (D25) settler ≠ approver, RF-11 settlement instant.
10. Confirm exclusions RF-12 (D6), RF-13 (D26), RF-14.

### 3.7 Documentation review (2026-10-31)

A second pass, before any publication, re-checked the package for policy inference, runtime-fix claims,
the registry's safety, the Owner oversight grant, test-ID consistency and public-repository safety.

| Severity | Finding | Disposition |
|---|---|---|
| **HIGH** (in this package's own design) | The first registry design (migration plan §2a) wrote every reference to a plain UNIQUE at correction submit. PP-2 as enforced lets the **same** invoice reuse its reference on resubmission after `STALE`/`REJECTED`, so the registry would have refused a ratified, legitimate path; it also relied on catching a unique violation, which aborts the PostgreSQL transaction | **Fixed in the design documents**: folded key bound to one scope (invoice for a correction, refund request for a refund), `INSERT … ON CONFLICT DO NOTHING` + scope check after all row locks, rollback-safe, isolation asserted; concurrency table in migration plan §2a; TDD RR-REF-10…14 |
| LOW | Review dispositions said "Fixed" without saying "in the design documents" | wording made explicit (§3.4) |
| — | RF-8a evaluated against code: 9 GET-only routes, `ClinicOversightScope` (all RME branches except MAIN), export needs a separate permission, no write path | package RF-8a rewritten with the evaluation; recommended grant is view-only |
| — | Approval-claim scan of every changed file | only the pre-existing, genuinely approved PP-3 row and a gate that *requires* a future register entry; no RF/D shown as approved |

Verification after this pass:

| Check | Result |
|---|---|
| Governance tests (`DevflowAiInstructionSystem\|LegacyRmeProgramClosureContract\|DevflowSprintTooling`), counted from the JUnit log | 57 testcases: 57 passed, 0 failures, 0 errors, 0 skipped |
| TDD matrix | 88 IDs (83 + RR-REF-10…14 added by this pass), all unique, 0 dangling references across every changed file |
| Public-repository scan of every added line (1 484 non-empty lines against base `f60d8232`) | 0 IP addresses, hostnames, connection commands, database names, credentials, key material, identity numbers, phone numbers, e-mail addresses, bank data, URLs or personal names. Kept by decision: PostgreSQL `16.15` (already in 44 files of the repository) and aggregate production counts (invoices by status, payments, corrections — precedent in the 2026-10-16 design; no patient linkage). F-17 is described publicly only as an outstanding hardening item; its analysis is kept in a private security record outside the repository (PR review, 2026-10-31) |

### 3.8 Publication review (2026-10-31)

Before this branch was published, the repository was confirmed **public**. The description of finding F-17
in this package was reduced to what the release sequence needs: a pre-existing rule needs a
database-backed guarantee, tracked as `HARDENING-RME-OWNER-REFERENCE-REGISTRY-1`, which must ship before
post-payment correction is activated. The mechanism, preconditions and detection query moved to a private
security record outside every repository. The F-17 hardening is **not** fixed in runtime, and no RF item
was approved by this review.

| Check | Result |
|---|---|
| Changed files against base `f60d8232` | 15, all documentation, rules or the sprint manifest; no `app/`, `config/`, `database/`, `routes/`, `tests/`, `.github/` or `scripts/` path |
| Public-repository scan re-run after the edit | 1 498 non-empty added lines: 0 IP addresses, e-mail addresses, URLs, runs of 13+ digits, phone numbers, host or server identifiers, database names, environment-file references or personal names. Remaining keyword hits reviewed one by one and all benign: 7 "token" hits are the refund intent-token design (6) and one CI filter-token name (1); 4 bank hits are privacy rules (no account number is collected); 2 SSH hits state that SSH configuration is not changed |
| Owner decisions | D4, D5, D6, D18, D25, D26 and RF-1…RF-14 still PENDING |

### 3.9 Owner decision reconciliation (2026-10-31)

Two owner statements arrived on 2026-10-31 (WITA) in task briefs: the decisions section of the
`FIX-CLINIC-OVERSIGHT-DATE-RANGE-TEST-DETERMINISM-1` brief, and the Sprint 4 Owner Decision Addendum
(Decision A — Owner approval reference; Decision B — refund approval scope). They were mapped to this
package **by substance**, not by number (the first statement numbered its items "RF-1 … RF-6" differently
from this package). Full mapping: decision package §7; register entries D4, D18, "Refund amount" and
"Refund audit permanence". No signature, reference, date or approval evidence was invented.

| Owner statement | Package item | Status |
|---|---|---|
| Maker = Front Office, assigned working branch only; never Kasir or Admin Klinik through inherited permissions | RF-1, maker side | `OWNER_POLICY_APPROVED` |
| Reviewers = Super Admin (all branches), Supervisor RME (authorized scope), Kepala Cabang (own managed branch, narrow permission, no broad RME access) | RF-1 (D4), reviewer side — role-based, branch-scoped (not option A, B, C or D) | `OWNER_POLICY_APPROVED` |
| Mandatory case-specific Owner approval reference on every request, before processing, linked to invoice and obligation, never reused for another financial decision, validated in application and database | RF-1, reference = option A | `OWNER_POLICY_APPROVED` |
| No self-approval; maker-checker kept | RF-1 clause (the same separation would bind a revocation, whose existence is RF-6, PENDING) | `OWNER_POLICY_APPROVED` |
| Settlement separate | RF-2 (D18) = A, separation only | `OWNER_POLICY_APPROVED` |
| No partial refund | RF-4 = A | `OWNER_POLICY_APPROVED` |
| Permanent audit | design invariant (append-only, no delete) | `OWNER_POLICY_APPROVED` |

**Design changes that follow** (written into the design, migration plan, threat model, TDD matrix, ADR 0013,
SOP draft, release gate and rule 213): the Owner reference moves from approval to **submit** (column
`NOT NULL`, consumed in the shared registry at submit; the reviewer verifies, never supplies one); maker
authority is the **Front Office role** at the actor's working branch, checked in the service (Super Admin
and Supervisor RME hold wide permissions and are not makers); reviewer authority is **role + server-resolved
branch scope**; two new refusal codes `REFUND_MAKER_NOT_AUTHORIZED`, `REFUND_REVIEWER_NOT_AUTHORIZED`; two
new threats T-26, T-27; TDD grows from 88 to **102** IDs (RR-REF-15, RR-REF-16, RR-ROLE-01…12), all unique.

**Still PENDING:** RF-1a (pilot identities, Sprint 2 `HUMAN_SEPARATION` red flag), reference reuse on a
re-filed request (default: a new reference, decided with RF-6), RF-3, RF-5, RF-6, RF-7, RF-8, RF-8a, RF-9 …
RF-14, and D5, D6, D25, D26. Implementation stays **BLOCKED_BY_OWNER_DECISION** (package §6 needs RF-1 …
RF-8). F-17 stays a separate hardening, NOT_STARTED; post-payment correction stays OFF; refund stays
NOT_ACTIVE.

### 3.10 Second owner answer (2026-10-31)

A third owner statement the same day — the Sprint 4 "Master Execution Prompt", section 7 — approved every
recommendation of the decision package, with modifications. Mapping: decision package §7.4; contradiction
report §7.5 (9 points, 0 unresolved, `OWNER_DECISION_SYNC = PASS`); register entries D5, D6, D25, D26 and
"Refund request & approval — remaining RF answers". Nothing invented: no signature, document number,
timestamp or retroactive approval.

| Area | What changed in the documents |
|---|---|
| RF-1a | refund does not inherit the Sprint 2 pair; pilot identity list ships empty; verified human separation or `PILOT_ACTIVATION = NO_GO`; new threat-model wording T-21, test RR-ELIG-07 |
| RF-3 | activation order now includes F-17 verified and staff confirmed (release gate RG-3 gains a pilot-identity row) |
| RF-5 = B | patient request reference `NOT NULL` + CHECK; its own registry `trx_rme_refund_patient_references` scoped to one obligation (migration plan §2b); new code `REFUND_PATIENT_REFERENCE_REQUIRED`; threat T-28; tests RR-PAT-04…07 |
| RF-6 | a new request after rejection, withdrawal or revocation carries a NEW Owner reference (RR-REF-09 now asserts the approved rule, not a default) |
| RF-7 | refused at approval as well as submit (design §7.2 step 7a, RR-ELIG-08) |
| RF-8 / RF-8a | Owner gets `view_billing_correction_oversight` only; route set re-verified on code (9 GET routes, 4 also need `export_oversight_data`); threat T-29; tests RR-OVS-04, RR-OVS-05 |
| RF-9 … RF-14 | Sprint 5 policy and scope exclusions recorded; nothing built |

TDD grows from 102 to **110** IDs, all unique. The owner-side precondition of package §6 is met; the build
is still sequenced by repository gates and by the F-17 registry (package §7.6). Nothing is built, deployed
or enabled.

---

## 4. Separate security hardening — F-17

| Field | Value |
|---|---|
| ID | `HARDENING-RME-OWNER-REFERENCE-REGISTRY-1` |
| Type | security hardening (runtime change + one additive migration) — **not** part of Sprint 4 |
| Status | **NOT_STARTED** |
| Sequencing | must be merged, deployed and verified **before any activation of `rme.invoice_postpayment_correction`** (task instruction of the 2026-10-31 documentation review; not an owner register decision) |
| Problem | the enforcement of PP-2's "no reuse on another invoice" needs a database-backed guarantee; analysis kept in the private security record, not in this public repository |
| Policy needed | none — it enforces the already-ratified PP-2 rule and preserves its same-invoice resubmission |
| Scope | the scope-keyed registry of migration plan §2a with the correction as its first consumer; the friendly application check stays; refund consumption is **not** included (Sprint 4 implementation adds it later) |
| Acceptance | RR-REF-08, RR-REF-10…RR-REF-14 adapted to corrections, on PostgreSQL 16; same-invoice resubmission still accepted; a case variant on another invoice refused; no `25P02`; existing correction suites green |
| Data | production holds 0 post-payment corrections, so no backfill; the migration refuses to run if one exists without a registry row |
| Exposure today | latent: `rme.invoice_postpayment_correction` is OFF in production and 0 post-payment corrections exist |
