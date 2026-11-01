---
paths:
  - "app/Modules/RmeInvoice/**"
  - "app/Modules/Tariff/**"
  - "app/Modules/ClinicOversight/**"
  - "app/Modules/PaymentMethod/**"
  - "app/Modules/Reporting/**"
  - "resources/views/rme/cashier/**"
  - "resources/views/rme/billing-disputes/**"
  - "resources/views/oversight/**"
  - "config/rme_invoice.php"
  - "config/rme_invoice_correction.php"
  - "config/rme_refund.php"
  - "resources/views/rme/refund-requests/**"
  - "tests/Feature/RmeRefundRequest/**"
  - "config/rme_refund_settlement.php"
  - "resources/views/rme/refund-settlements/**"
  - "tests/Feature/RmeRefundSettlement/**"
  - "config/rme_tariff_authority.php"
  - "tests/Feature/RmeBillingDispute/**"
  - "tests/Feature/RmeInvoiceCorrection/**"
  - "tests/Feature/RmeManualPricing/**"
  - "tests/Feature/RmePaymentIdempotency/**"
  - "tests/Feature/RmeTariffAuthority/**"
  - "tests/Feature/ClinicOversight/**"
---

# RME billing, receivables, invoice correction & oversight — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

## Invariants

### Ledger, gate and payment basics

- **AIR-RMB-01** RME billing (`trx_rme_invoices`, `_items`, `trx_rme_payments`, module `RmeInvoice`) is a separate
  domain from lab billing (`trx_invoices`, `trx_payments`, lab `Finance` role). Never mix them or count lab payments as
  RME revenue. Source: rule 188 §1, rule 186 §18.
- **AIR-RMB-02** Bill a visit only when it is `cashier_pending` AND **this visit's** medical record exists AND is
  `final` — fail closed on a missing record (never `if ($record && …)`). `RmeInvoiceService::create()` locks the visit
  with `->lock('for no key update')` and every gate reads the locked row; never "upgrade" it to `lockForUpdate()`
  (deadlocks 40P01 with the diagnosis writer's FK share locks). Source: rule 162 SGL-R1, rule 189 §4.
- **AIR-RMB-03** Only a holder of `complete_rme_examination` (Doctor, Perawat, Supervisor RME) moves
  `in_progress → cashier_pending`; front office (Admin Klinik, Kasir, Front Office) never does; `completed` is reachable only from
  `cashier_pending` and only through `RmePaymentService`. Never re-add the `in_progress → completed` edge. Source:
  archive Sprint 62.1, rule 199 §5, rule 108 §10, rule 109 §1.
- **AIR-RMB-04** Any payment > 0, partial or full, completes the current `cashier_pending` visit; the invoice becomes
  `PARTIAL`/`PAID` and the remainder stays active piutang. Receivable reports key off invoice status/remaining, never
  visit status (supersedes Sprint 20 "full payment only"). Exception: on the Kontrol paths (`allocateControlPayment`,
  `payControlInvoiceOnly`) the Kontrol visit completes only when its own invoice has no remaining balance
  (`completeControlVisitIfSettled`), so a Kontrol visit with a `PARTIAL` invoice stays `cashier_pending` (rule 207 §C7). Source: archive Hotfix RME Partial Payment Completes Visit,
  rule 188 §3.
- **AIR-RMB-05** Carry-over collects the patient's older UNPAID/PARTIAL invoices without merging debt into the new
  invoice: server intersection of eligible ∩ submitted ids, FIFO, one payment row per invoice under a shared
  `payment_batch_uuid`, discovered only at the paying visit's own branch (`payingBranchIds()`, owner OD-1a). Another
  branch's receivable is never shown or collected. Source: archive Sprint 62.2, rule 200 §A2/§B9.
- **AIR-RMB-06** Payments are append-only: `RmePayment` refuses update (except `patient_id`) and soft/force delete.
  Never re-amount, re-date, re-batch, re-branch or delete a payment to effect a correction; corrections and refunds are
  their own records. Source: rule 189 §6, rule 188 §9.
- **AIR-RMB-07** Amount is capped at the remaining balance under the invoice lock (Σ payments ≤ grand_total).
  `normalizePaidAt()` refuses blank/unparseable `paid_at`, converts offsets to the app timezone, and refuses
  `> now + 60 s`; never widen that into a backdating window. Source: rule 189 §5.
- **AIR-RMB-08** Every financial writer audits through `RmeFinancialAuditEvent` inside the fact's transaction
  (`RME_INVOICE_CREATED`, `RME_PAYMENT_RECORDED`); refusals are audited after rollback. Payloads: ids, codes, cents —
  never patient name, NIK, RM number, item description, note or reference text. Source: rule 189 §3, rule 203 §25.
- **AIR-RMB-09** The payment path has no consent check (`assertConsentVerified()` was deleted): consent gates finishing
  the examination, not collection, so old receivables stay collectible. Source: rule 109, archive
  FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3.
- **AIR-RMB-10** Lab case candidates are generated only after the payment commits and only when the invoice is `PAID`
  (`RmeLabIntegrationService::generateForPaidInvoice()`); a failure is logged and never rolls back the payment. A
  payment never creates a `LabOrder` or lab payment. Source: archive Sprint 21 Phase 21.2.

### Actor and branch authority

- **AIR-RMB-11** `RmeInvoiceService`/`RmePaymentService` take a non-nullable explicit `User` actor resolved through
  `RmeBillingActorGuard` → `ActiveAccountResolver` (persisted, active, not soft-deleted, `manage_rme_billing` on the
  persisted row). No `Auth::`, `auth()`, `request()`, `session()` or `BranchContext` in either service (tests pin it).
  Unusable actor → empty list, null lookup, or 403 on create. Source: rule 197 §E, rule 198.
- **AIR-RMB-12** Cashier scope = `RmeWorkingBranchScope` asked of the persisted actor; the invoice branch is the
  visit's, a submitted `branch_id` only narrows. Issuing is branch-bounded (`RmeInvoicePolicy::create(User,
  ?ClinicVisit)` + service `allows()` on the locked visit). A read out of scope answers 404; issuing an invoice out of
  scope is refused with 403. Never pass `null` to
  `branchIdsFor()` — it answers every RME branch. Source: rule 194 §27a, rule 198 §30.

### Pricing (owner D21 revised: manual cashier pricing)

- **AIR-RMB-13** The cashier types every original line price; `RmeInvoicePricingService::price()` is MANUAL for every
  branch and reads, compares or prefills no tariff. Provenance `MANUAL_CASHIER_ENTRY` / `MANUAL_FREE_TEXT` /
  `CORRECTION_MANUAL_ENTRY`, `tariff_id` NULL, never `TARIFF_MATCHED`. Free-text lines stay allowed (supersedes the
  branch tariff authority of rules 192/193). Source: rule 194 §3/§7.
- **AIR-RMB-14** Server-side money validation in integer cents: unit price required (malformed →
  `INVOICE_AMOUNT_INVALID`), qty 1..`MAX_QTY` 2 147 483 647 (storage bound, not a business cap),
  0 ≤ discount ≤ line gross, decimal(15,2) overflow refused before multiplying, totals recomputed, submitted totals
  ignored, named treatment active. Never invent a discount cap or approver (D21c pending). Source: rule 194 §6/§8.
- **AIR-RMB-15** `mst_tariffs` is optional reference and never blocks billing. `strict_branch_codes` is a
  source-controlled `[]` and `price_exceptions_enabled` a literal `false`, neither env-overridable. Coverage ≠ approval:
  amounts come only from an owner-approved list via the digest-bound `rme:tariff-import --approval-reference`; never
  invent or copy prices across branches. Source: rules 193, 194 §4.
- **AIR-RMB-16** Issued invoices are never edited directly: no edit/update/delete route; only
  `RmeInvoiceCorrectionService` may call `updateItem`/`deleteItem`/`createItem`/`applyCorrectionTotals`. Creation
  writes `UNPAID`; nothing writes `DRAFT`/`VOID`; never call the buggy `recalculateTotals()`. Source: rule 194 §9,
  rule 188 §2.

### Invoice correction (pre-payment pilot)

- **AIR-RMB-17** One engine, `RmeInvoiceCorrectionService`: `SUBMITTED → APPROVED → APPLIED` plus terminal
  `REJECTED`/`CANCELLED`/`STALE`; at most one active correction per invoice (`trx_rme_inv_corr_one_active_uq` + invoice
  lock); lock order invoice → correction → obligation, staleness checked after the locks; approval applies atomically
  (D1a) and a refused apply leaves no approval; replay is a no-op. Source: rule 190 §2, rule 195 §2.
- **AIR-RMB-18** Maker ≠ checker per ACCOUNT, in the service under the lock, Super Admin included
  (`SELF_APPROVAL_FORBIDDEN`, `SELF_APPLICATION_FORBIDDEN`). Policies are courtesy; page controls come from
  `actionsFor()`, never a bare `@can`. Source: rule 195 §3, rule 197 §B8.
- **AIR-RMB-19** Pilot scope (`config/rme_invoice_correction.php` `pilot.branch_codes`, `pilot.identities`) is
  source-controlled, no `env()`, enforced after the lock by `RmeInvoiceCorrectionPilotScope`/`…PilotIdentity`,
  fail-closed (bad or overlapping list voids it; MAIN never). Widen only by owner decision + reviewed commit, never via
  roles or pins. Reject and own-cancel are the release paths. Source: rule 195 §4, rule 197 §C/§D.
- **AIR-RMB-20** Pre-payment eligibility: `UNPAID`, zero payments, visit not cancelled, no lab candidate (D23), version
  unchanged. A correction never adds a Rp0 line/total (`CORRECTION_RP0_NOT_PERMITTED`), never re-attributes an
  existing line's doctor and never clears its treatment. Source: rule 190 §2, rule 194 §17.
- **AIR-RMB-21** D28: an invoice whose ORIGINAL total (live row at version 0, else the integrity-checked frozen
  ORIGINAL document) or current total is Rp0 is never corrected — `CORRECTION_ORIGINAL_TOTAL_ZERO` / `_UNVERIFIABLE` at
  submit, approve and recovery apply, implemented once in the service. Rp0 issuance is untouched. Source: rule 201.
- **AIR-RMB-22** `assertActor()` re-reads the actor via `ActiveAccountResolver` on every correction mutation, cancel
  included (`ACTOR_UNUSABLE`); that re-read after the invoice lock is the linearization point. Never lock `users`.
  Source: rule 201 §G/§H.
- **AIR-RMB-23** D10: a SUBMITTED/APPROVED correction blocks payment on every path (`assertNoActiveCorrection()`,
  `[INVOICE_CORRECTION_ACTIVE]`, whole batch rolled back). It is NOT flag-gated — reject/cancel every active correction
  before switching the flag OFF. Forms carry `invoice_versions[id]` = `correction_version`, compared on locked rows
  (`[INVOICE_VERSION_CHANGED]`). Source: rule 191 §3, rule 192 §6.
- **AIR-RMB-24** Document versions (v0 ORIGINAL, vN REVISION) are immutable SHA-256 snapshots rendered only from the
  snapshot, with no patient identity; corrections, lines and events are immutable and undeletable. Source: rule 190 §3.
- **AIR-RMB-25** Pilot proof follows the rule-196 ladder; never collapse rungs. A real case is caught, never created;
  the case-specific Owner approval must pre-exist the submit; a checker holding the Owner role does not merge the two
  controls; never act as an operator to manufacture `PILOT_LIVE`. The only full-closure tag is
  `verification-rme-invoice-correction-prepayment-pilot-1-go`. Unobserved real cases (#42, #43) reconcile as
  `PASS (RECONSTRUCTED)` but never accumulate into a GO; a probe reading GO after a case is state, not observation.
  An Owner's after-the-fact statement is `OWNER_RETROSPECTIVE_ATTESTATION`, never the approval itself.
  Source: rules 196 (§9, §10), 202.

### Billing disputes (OPS-OQ-1, all branches)

- **AIR-RMB-26** A known-wrong amount is not collected — the counter files a billing dispute, which is a report, not a
  correction (it changes no item, total, status or version). While `OPEN`/`UNDER_REVIEW`/`ESCALATED`, every payment
  path refuses via `assertNoPaymentHold()` under the invoice lock, whole batch; no flag or env key disables it; it is
  independent of D10; only `RESOLVED` releases it. The patient is never held. Source: rule 203 §A/§C.
- **AIR-RMB-27** Resolution only as `CORRECTION_APPLIED` (server-verified: the latest applied correction produced the
  current, newer version) or `ORIGINAL_CONFIRMED_CORRECT` (invoice unchanged, 6–120 char reference, resolver ≠
  reporter, Super Admin included); none while a correction is in flight; no override. Never roll back below rule 203
  while `rme:billing-dispute-status` shows an active dispute. Source: rule 203 §E/§F.
- **AIR-RMB-28** Review authority is a per-(account, branch) row in `mst_rme_billing_dispute_reviewers`, never a role
  grant (`review_rme_billing_disputes` is held by no operational role). `isEligibleDesignee()` runs at grant AND on
  every use (never Owner/Front Office/Kasir; needs RME workspace access); the working scope must reach the branch; a
  designee who issued, collected or corrected the invoice cannot confirm it (`RESOLVER_NOT_INDEPENDENT`). Source:
  rule 204 §B.
- **AIR-RMB-29** `payControlInvoiceOnly()` runs only with explicit `payment_scope=control_only` +
  `confirm_control_only` (the service requires it too), only while every outstanding same-branch origin is actively
  disputed; one payment on the Kontrol invoice, the origin locked but never written. The ordinary combined payment that
  would settle a disputed origin stays refused whole. Source: rule 204 §C.

### Payment idempotency and allocation

- **AIR-RMB-30** Every payment form carries a server-issued `payment_intent` (`RmePaymentIntent`, HMAC over the invoice
  id); missing/forged → refused. A committed submission writes one immutable `trx_rme_payment_submissions` row
  (`UNIQUE(intent_key)`) in the payment transaction. Same intent + cashier + invoice + fingerprint = replay writing
  nothing; anything else = `PAYMENT_INTENT_REUSED`. Never deduplicate in JS/cache alone. Source: rule 205 §B–§D.
- **AIR-RMB-31** Decide under the lock: summary read inside the transaction, every plan invoice locked in the
  unchanged order, a plan/balance conflict refuses the WHOLE payment (`PAYMENT_PLAN_CHANGED`,
  `PAYMENT_BALANCE_CHANGED`), full allocation asserted in cents — never skip or `min()`-cap silently. Check order:
  authority → replay → collectable → version → D10/dispute → plan → balances → amount. A retried transaction re-derives
  every model it mutates. Source: rule 205 §F.

### Post-payment correction, receipts, refund

- **AIR-RMB-32** POST_PAYMENT needs BOTH `rme.invoice_correction` and `rme.invoice_postpayment_correction`
  (`RmeInvoicePostPaymentCorrectionFeatureGuard`); never enable it by editing `RmeInvoiceCorrectionType::ENABLED`
  (stays `[PRE_PAYMENT]`). Type comes from the ledger on the locked row; requires `PARTIAL`/`PAID`, a completed visit,
  no lab candidate, no OPEN obligation, and a mandatory, non-reused owner approval reference (DB CHECK). Source:
  rule 207 §B–§D.
- **AIR-RMB-33** Post-payment status = `RmeInvoiceLedger::statusAfterMoneyReceived()` (the helper
  `refreshInvoiceStatus()` uses). Downward below paid → one OPEN `trx_rme_overpayment_obligations` row (server amount)
  — no refund, credit or negative receivable; upward → outstanding, visit never reopened. A correction never writes a
  payment or generates lab work. Source: rule 207 §E.
- **AIR-RMB-34** Every payment issues exactly one immutable `RmeReceiptSnapshot` inside its transaction (via
  `refreshInvoiceStatus()`), not gated by any switch; a failure aborts the payment — never add a skip-if-table-missing
  bypass. An older-version receipt renders from its snapshot stamped "DIREVISI"; integrity failure → 409. Source:
  rule 207 §F.
- **AIR-RMB-35** Refund REQUEST & approval (rule 215, flag `rme.billing_refund_request` OFF) decides whether an OPEN
  obligation is paid back and never moves money; APPROVED ≠ paid. Refund SETTLEMENT (rule 216, flag
  `rme.billing_refund_settlement` OFF, needs the request flag too) records that the money left: settler ≠ approver,
  bank/cash with evidence, server time, full amount only. `settle_rme_refund` is held by NO role (Super Admin passes
  `can()` only via Gate::before) and `pilot.settlers` ships EMPTY (deny-all) — the designation is the gate. Net received = payments − SETTLED refunds
  (`RmeInvoiceLedger::netReceived*`) in EVERY balance reader; payments are never written. No credit, partial,
  reversal or bank API; a payment-entry error is a reversal (D26). Source: rule 189 §1–§2, rules 215–216.

### Oversight and reports

- **AIR-RMB-36** `Finance Klinik` and `Data Analyst` are read-only, distinct from lab `Finance`. `ClinicOversight` is
  GET-only and `ClinicOversightScope` its only branch authority (active, RME-enabled, not MAIN; `branch_id` only
  narrows; out of scope → 404). Never reuse `view_clinic_visits` or operational controllers; export needs area +
  `export_oversight_data`; never grant `manage_*`/`create_*`/`void_*`/`manage_rme_billing`. Source: rule 186.
- **AIR-RMB-37** Oversight KPIs use the WITA day from `ClinicalClock`: Omzet = `SUM(trx_rme_payments.amount)` by
  `paid_at`, invoice-branch attribution, never `grand_total`; transactions = distinct `payment_batch_uuid` (NULL →
  `'payment:'||id`); PIUTANG = current balance, never date-filtered. Ranges: inclusive WITA dates, exclusive UTC upper
  bound (never `23:59:59`/`whereDate()`), ≤ 366 days, reversed or one-sided rejected, order checked in
  `withValidator()` (a field-reference rule 500s). Source: rule 186 §5, rule 187.
- **AIR-RMB-38** RME patient/payment reports (index, export, print) take their period only from `RmeReportDateScope`:
  missing, reset or invalid → `ClinicalClock` today (strict `!Y-m-d` round trip); memoize on the request, never on the
  controller; a date filter never widens branch scope; report date = `trx_clinic_visits.visit_date`. Source: rule 129.
- **AIR-RMB-39** `DoctorPerformanceReportService` resolves identity via `DoctorIdentityResolver`
  (`mst_doctors.user_id`): a linked doctor sees own data only (a forged `doctor_id` is ignored), an unlinked one gets a
  clear 403, never auto-linking; totals from RME payments, never lab; no KTP/NIK. Source: archive HOTFIX FIX-PRE-68-45
  (403, Treatment Date Table).

### Operations and testing traps

- **AIR-RMB-40** Run `rme:invoice-correction-readiness` and `rme:billing-dispute-status` only as the runtime user
  (`runuser -u daengtisiams …`); off shift is WATCH, never cleared by opening an operator session. Before `RoleSeeder`,
  diff production grants against `RoleSeeder::ROLE_PERMISSIONS` — `syncPermissions` removes what is missing. Source:
  rule 195 §21/§28a–b, archive IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1.
- **AIR-RMB-41** Prove locks and races on PostgreSQL 16 (SQLite compiles `lockForUpdate` to nothing). Build payment
  test forms with `rmeFreshPaymentForm()`/`rmeDeclaredPaymentForm()` or the race never reaches the lock. Add every new
  table with an FK to payments/corrections to every PG race cleanup list, and register every new FK to `mst_patients`
  in `PatientOwnedRecordRegistry` (else every merge refuses). Source: rule 205 §K, rule 207 §G, archive
  FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1.
- **AIR-RMB-42** Production verification is read-only: never create an invoice, payment, correction, dispute or
  designation to prove behaviour. Take the financial fingerprint immediately before deploying — real payments land
  during CI. Source: rule 205 §J, archive FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1.
- **AIR-RMB-43** Every route that binds both `{clinicVisit}` and `{rmeInvoice}` answers 404 unless
  `RmeInvoice::belongsToVisit()` holds; a new route of that shape adds the same guard, or its printout shows another
  patient's name, Nomor RM and phone. Source: `.cursor/rules/185-rme-visit-detail-invoice-registration-ux.mdc` §16a.
- **AIR-RMB-44** A receipt is never stamped LUNAS while a balance remains or the reconciliation fails: a remaining
  balance prints DIBAYAR SEBAGIAN, a mismatch prints a visible warning. `CreateRmePaymentRequest::authorize()` runs the
  `pay` policy and `belongsToVisit` before validation, so a cashier at another branch learns nothing about a
  correction. Source: rule 185 §8, §11; `.cursor/rules/191-rme-invoice-correction-prepayment-activation.mdc` §9a.

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
  DETECTIVE clinical vs billing cross-check is recorded with every NO_CHARGE closure and shown in oversight; it never
  refuses; MEDIUM-1 `PARTIALLY_MITIGATED`, structured doctor input deferred to its own sprint. **Not built:** reversal (D26 excluded), patient credit (D6 excluded). **PENDING owner decisions:** D21c
  discount policy, D21g/D22 for Rp0 **issuance**, vouchers and any Rp0 correction (answered for the closure scope
  only; D28 unchanged), D24 `paid_at` backdating. The historical Rp0 UNPAID SPN4 invoice #36 stays untouched (S6-D2).
- Open owner questions: a correction settling a PARTIAL invoice leaves no LUNAS receipt; whether a pre-payment approval
  reference becomes mandatory.
- Residuals: `branchIdsFor(null)` still answers every RME branch (`RmeInvoiceController::rmeBranches()` and
  `RmeReportController` rely on `auth`); the lab-candidate failure log carries the raw exception message.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/129-rme-report-default-clinical-today.mdc` | RME report period defaults to clinical today |
| `.cursor/rules/162-sunu-go-live-blocker-closure.mdc` | Billing requires this visit's final record |
| `.cursor/rules/186-finance-klinik-data-analyst-role-foundation.mdc` | Read-only oversight roles, scope, KPI formulas |
| `.cursor/rules/187-oversight-dashboard-date-range-foundation.mdc` | Oversight dashboard inclusive WITA date range |
| `.cursor/rules/188-rme-billing-correction-financial-integrity-foundation.mdc` | Billing foundation audit, findings BCF-01…14 |
| `.cursor/rules/189-rme-billing-refund-phase0-financial-prerequisites.mdc` | Audit log, invoice race lock, `paid_at`, payment immutability |
| `.cursor/rules/190-rme-invoice-correction-receipt-foundation.mdc` | Correction model, document versions, oversight area |
| `.cursor/rules/191-rme-invoice-correction-prepayment-activation.mdc` | Payment bound to invoice `correction_version` |
| `.cursor/rules/192-rme-branch-tariff-authority-financial-pricing.mdc` | D10 payment block; tariff authority mostly superseded |
| `.cursor/rules/193-rme-branch-tariff-provisioning-governance.mdc` | Tariff provenance and governed import (hygiene) |
| `.cursor/rules/194-rme-manual-pricing-authority.mdc` | CURRENT price authority: manual cashier pricing |
| `.cursor/rules/195-rme-invoice-correction-prepayment-pilot-activation.mdc` | Pilot: D1a auto-apply, SPN4 allowlist, RBAC |
| `.cursor/rules/196-rme-invoice-correction-prepayment-pilot-verification.mdc` | Pilot verification ladder and closure tag |
| `.cursor/rules/197-rme-invoice-correction-pilot-identity-payment-actor.mdc` | Pilot identity allowlist; payment explicit actor |
| `.cursor/rules/198-rme-invoice-branch-scope-explicit-actor.mdc` | Invoice explicit actor, `RmeBillingActorGuard` |
| `.cursor/rules/199-rme-clinic-visit-branch-authority.mdc` | Visit explicit actor; `completed` transition rule |
| `.cursor/rules/200-rme-active-user-patient-selection-cross-branch-control.mdc` | Active accounts; OD-1 Kontrol; OD-1a receivables |
| `.cursor/rules/201-rme-invoice-correction-zero-original-guard.mdc` | Rp0-original correction guard (D28); correction actor |
| `.cursor/rules/202-rme-invoice-correction-pilot-operational-readiness.mdc` | Counter procedure, reconciliation deltas, evidence privacy |
| `.cursor/rules/203-rme-billing-dispute-escalation-ops-oq-1.mdc` | Billing dispute and payment hold, all branches |
| `.cursor/rules/204-rme-billing-dispute-branch-reviewer-control-payment.mdc` | Designated reviewers; Kontrol-only payment |
| `.cursor/rules/205-rme-payment-submission-idempotency-financial-integrity.mdc` | Payment intent, replay, decide-under-lock |
| `.cursor/rules/206-rme-postpayment-invoice-correction-preparation.mdc` | Post-payment foundations; PP-1…PP-10 ratified |
| `.cursor/rules/207-rme-postpayment-invoice-correction.mdc` | Post-payment correction, obligations, receipt snapshots |
| `.cursor/rules/213-rme-billing-refund-request-approval-preparation.mdc` | Refund request/approval preparation; owner answered RF-1…RF-14 on 2026-10-31 (D4/D5/D6/D18/D25/D26). Implemented by rule 215 |
| `.cursor/rules/214-rme-owner-reference-registry.mdc` | Owner reference registry: one reference, one decision scope, enforced by the database |
| `.cursor/rules/215-rme-billing-refund-request-approval.mdc` | Refund request & approval (Sprint 4/7): decides, never pays; Front Office maker, role+branch reviewer, flag OFF |
| `.cursor/rules/216-rme-refund-settlement.mdc` | Refund settlement (Sprint 5/7): records money out; settler ≠ approver; flag OFF, settler list EMPTY |
| `.cursor/rules/217-rme-rp0-consultation-control.mdc` | Konsultasi & Kontrol Rp0 (Sprint 6/7): "Selesai Tanpa Biaya" → `NO_CHARGE`, no payment; flag OFF; §9 hardening (S6-Q1/Q2/Q3) |
| `.cursor/rules/218-rme-clinical-billing-consistency.mdc` | Clinical vs billing cross-check of Rp0 closures: detective only (CB-Q2), never CONSISTENT/CONFIRMED, audit-snapshotted |
| `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc` | Consent gates exam finish, not payment |
| `.cursor/rules/92-feature-flag-runtime-override.mdc` | Flag environment overrides captured at config build |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/02-rme-billing-reporting.md`; related
`01-rme-clinical.md` (Sprint 20, lab candidates, consent) and `03-branch-context.md` (Sunu go-live, visit authority,
active accounts). Grep by sprint id; never read a whole file.
