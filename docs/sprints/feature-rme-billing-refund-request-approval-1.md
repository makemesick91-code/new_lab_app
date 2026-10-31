# FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 — Refund request & approval (Sprint 4/7)

**Status: IMPLEMENTED, FLAG OFF.** Rule 215. Built on the decision package
`docs/decisions/rme-refund-request-approval-owner-decision-package.md` (owner answers RF-1 … RF-14, RF-1a,
RF-8a of 2026-10-31) and the design documents `docs/architecture/rme-refund-request-approval-*.md`.
Depends on `HARDENING-RME-OWNER-REFERENCE-REGISTRY-1` (rule 214): the refund is the registry's second consumer.

## 1. What this sprint does — and does not

| Does | Does not |
|---|---|
| A Front Office maker files a request to pay back an OPEN overpayment obligation, for its full amount | Pay anything. No settlement, payout, reversal, patient credit, ledger or KPI change (Sprint 5) |
| A reviewer who is not the maker approves, rejects or (before settlement) revokes it | Write the obligation, invoice, items, payments, receipts or document versions |
| Oversight reads requests; approved-unsettled is shown apart from revenue, receivables, cash and bank | Print a refund voucher |
| Two reference registries guarantee one Owner approval per decision and one patient request per obligation | Activate anything: the flag ships OFF and the pilot identity allowlist ships EMPTY |

## 2. Built

| Layer | Files |
|---|---|
| Migrations (additive) | `2026_10_31_200001` `trx_rme_refund_requests` (12 PostgreSQL CHECKs, partial UNIQUE one-active-per-obligation, `lower(owner_approval_reference)` UNIQUE), `2026_10_31_200002` `trx_rme_refund_patient_references`, `2026_10_31_200003` `trx_rme_refund_request_events`; every `down()` refuses with rows |
| Models | `RmeRefundRequest` (decision columns and references not fillable; descriptive columns immutable; never deleted), `RmeRefundRequestEvent`, `RmeRefundPatientReference` (append-only) |
| Support | `RmeRefundRequestStatus`, `RmeRefundRequestRefused` (stable codes, 404 flag), `RmeRefundRequestFeatureGuard`, `RmeRefundRequestPilotScope`, `RmeRefundRequestPilotIdentity`, `RmeRefundRequestIntent`, `RmeRefundRequestSnapshot` |
| Repository | `RmeRefundRequestRepositoryInterface` → `RmeRefundRequestRepository` (allow-listed lifecycle writes; patient registry `ON CONFLICT DO NOTHING`); `RmeOverpaymentObligationRepository::findForUpdate()` added |
| Service | `RmeRefundRequestService` — submit / approve / reject / cancel, authority, eligibility, staleness, audit |
| HTTP | `RmeRefundRequestController` + `StoreRmeRefundRequestRequest` / `DecideRmeRefundRequestRequest` (shape only), routes `rme.refund-requests.*` (7), `RmeRefundRequestPolicy` (courtesy) |
| Oversight | `OversightRefundRequestController`, routes `oversight.billing-corrections.refund-requests.{index,show}` (GET only) |
| Views | `rme/refund-requests/{index,show,create}`, shared `partials/detail`, cashier panel `rme/cashier/partials/refund-request-panel`, oversight `refund-requests-{index,show}` |
| Config | `config/rme_refund.php` (pilot SPN4, identities EMPTY, no env), flag `rme.billing_refund_request` (default OFF, risk critical) |
| RBAC | `request_rme_refund` → Front Office only (`RoleSeeder::FRONT_OFFICE_ONLY_PERMISSIONS`); `approve_rme_refund` → Supervisor RME, Kepala Cabang (Super Admin via `'*'`); Owner += `view_billing_correction_oversight` (RF-8a) |
| Other | `PatientOwnedRecordRegistry` (+`trx_rme_refund_requests`), audit actions `RME_REFUND_REQUEST_*`, race child steps, cleanup lists of 7 PostgreSQL race suites, CI critical-gate token `RmeRefundRequest` + 4 mandatory suites |

## 3. Decisions taken in implementation (stricter than, or a reading of, the design)

1. **The maker must be context-bound.** An account exempt from the working context (Owner, Super Admin,
   Supervisor RME) has no assigned working branch, so even with the Front Office role it is never a maker.
2. **The Owner reference is checked against corrections of every type**, not only post-payment ones; the
   registry remains the guarantee across scopes.
3. **Reviewer authority is decided on the account before any record is read**, so a non-reviewer gets
   `REFUND_REVIEWER_NOT_AUTHORIZED` and an out-of-scope reviewer gets a 404 — neither learns another
   branch's record exists.
4. **RR-CONC-03, approval wins:** the maker's late withdraw meets an APPROVED request and is refused with
   `REFUND_SELF_DECISION_FORBIDDEN` (the RR-LIFE-06 rule: only a reviewer other than the maker may revoke), not
   `REFUND_INVALID_TRANSITION` as the matrix row guessed. The safety property — exactly one outcome, the
   other refused — is what the test asserts.
5. **RR-AMT-01:** a malformed or non-positive declared amount is refused with `REFUND_OBLIGATION_CHANGED`
   before any transaction opens (the declaration cannot match the obligation).
6. **RR-REF-14** (REPEATABLE READ) is proven by the registry's own G4 in
   `RmeOwnerReferenceRegistryConcurrencyTest`; **RR-PRIV-02** has no CSV half because no refund export exists.

## 4. Tests

| Suite | Cases |
|---|---|
| `RmeRefundRequestLifecycleTest` | 48 — RR-LIFE-01…10, RR-IDEM-01…08, RR-AMT-01…04, RR-OBL-01…05, RR-STALE-01…04, RR-ELIG-01…08, RR-LEDGER-01…04, RR-CLK-01 |
| `RmeRefundRequestAuthorityTest` | 34 — RR-SOD-01/02/03/05, RR-BR-01…06, RR-ROLE-01…12, RR-ACT-01/02, RR-OVS-01…05, RR-PRIV-01/03, RR-SEC-07/11/12 |
| `RmeRefundRequestReferenceTest` | 33 — RR-REF-01…10/15/16, RR-PAT-01…05, RR-PRIV-02 |
| `RmeRefundRequestConcurrencyTest` | 12 — RR-CONC-01…06, RR-REF-03/08/11/12/13, RR-PAT-06/07, RR-SOD-04, RR-AMT-05, RR-REF-05 (PostgreSQL 16 only, REAL service in a second session) |

SQLite: 115 passed (the 12 PostgreSQL cases skip). PostgreSQL 16.15: 127 passed, 405 assertions; the race
cleanup leaves no refund, registry or user row behind.

Changed existing tests: `FrontOfficeRoleTest` (the union now names `FRONT_OFFICE_ONLY_PERMISSIONS` as its one
exception), `SupervisorRmeRolePermissionTest` (+`approve_rme_refund`), the cleanup lists of 7 race suites.

## 5. Production posture after deploy

* Three new tables, all empty. Flag OFF ⇒ every refund route 404, every service call refused, the cashier
  page unchanged (no query).
* Permissions seeded, but nobody can act: the pilot identity allowlist is empty and the flag is off.
* Activation is a separate decision with the RF-3 order: Sprint 2 GO → F-17 deployed and verified →
  post-payment correction activated → a real OPEN obligation → a real Front Office maker and an
  independently identified reviewer with verified human separation → the Owner's activation approval.

## 6. Rollback

Before activation: leave the flag OFF (nothing to undo — no rows). After activation: withdraw or revoke every
REQUESTED/APPROVED request through the service, then flag OFF. Code rollback: redeploy the previous GO tag;
the additive tables stay and `down()` refuses while they hold rows. No money has moved in any state, so
every rollback is non-financial.
