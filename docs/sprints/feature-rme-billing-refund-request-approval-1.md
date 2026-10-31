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
| Migrations (additive) | `2026_10_31_200001` `trx_rme_refund_requests` (13 PostgreSQL CHECKs — incl. maker ≠ checker and "the maker never revokes a decided request" — partial UNIQUE one-active-per-obligation, `lower(owner_approval_reference)` UNIQUE), `2026_10_31_200002` `trx_rme_refund_patient_references`, `2026_10_31_200003` `trx_rme_refund_request_events`; every `down()` refuses with rows |
| Models | `RmeRefundRequest` (decision columns and references not fillable; descriptive columns immutable; status changes only along `RmeRefundRequestStatus::TRANSITIONS`; never deleted), `RmeRefundRequestEvent`, `RmeRefundPatientReference` (append-only) |
| Support | `RmeRefundRequestStatus`, `RmeRefundRequestRefused` (stable codes, 404 flag), `RmeRefundRequestFeatureGuard`, `RmeRefundRequestPilotScope`, `RmeRefundRequestPilotIdentity`, `RmeRefundRequestIntent`, `RmeRefundRequestSnapshot` |
| Repository | `RmeRefundRequestRepositoryInterface` → `RmeRefundRequestRepository` (allow-listed lifecycle writes; patient registry `ON CONFLICT DO NOTHING`); `RmeOverpaymentObligationRepository::findForUpdate()` added |
| Service | `RmeRefundRequestService` — submit / approve / reject / cancel, authority, eligibility, staleness, audit |
| HTTP | `RmeRefundRequestController` + `StoreRmeRefundRequestRequest` / `DecideRmeRefundRequestRequest` (shape only), routes `rme.refund-requests.*` (7), `RmeRefundRequestPolicy` (courtesy) |
| Oversight | `OversightRefundRequestController`, routes `oversight.billing-corrections.refund-requests.{index,show}` (GET only) |
| Views | `rme/refund-requests/{index,show,create}`, shared `partials/detail` (the case — requester, reason, both references — on the operational page only), cashier panel `rme/cashier/partials/refund-request-panel`, oversight `refund-requests-{index,show}`, sidebar "Refund RME" (flag + permission gated, outside the RME group so Kepala Cabang reaches it) |
| Config | `config/rme_refund.php` (pilot SPN4, identities EMPTY, no env), flag `rme.billing_refund_request` (default OFF, risk critical) |
| RBAC | `request_rme_refund` → Front Office only (`RoleSeeder::FRONT_OFFICE_ONLY_PERMISSIONS`); `approve_rme_refund` → Supervisor RME, Kepala Cabang (Super Admin via `'*'`); Owner += `view_billing_correction_oversight` (RF-8a) |
| Other | `RmeOwnerApprovalReferenceRegistry::isClaimed()` + a pre-payment correction guard (a reference a registry consumer already spent is refused), `PatientOwnedRecordRegistry` (+`trx_rme_refund_requests`), audit actions `RME_REFUND_REQUEST_*`, race child steps, cleanup lists of 7 PostgreSQL race suites, CI critical-gate token `RmeRefundRequest` + 4 mandatory suites |

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
7. **Supervisor RME's "authorized scope" is every active RME branch.** The account is exempt from the working
   context, so `RmeWorkingBranchScope` answers the whole RME set — the existing governance-tier model, the same
   reach Super Admin has. Narrowing it is an owner decision, not a code change made here.
8. **Oversight refund pages are not gated by the flag**, like the correction and obligation oversight pages:
   records stay auditable after a rollback. The flag description says so.
9. **Order for every decision and for filing: account authority → scope (404) → input.** A note, reason or
   form field is never validated before the scope check, so no error reveals another branch's record.
10. **Type `FOUNDATION_SPRINT`**, not `MODULE_SPRINT`: the change touches four modules (RmeInvoice,
    AccessControl, ClinicOversight, PatientMerge), and the stricter cross-module profile applies.

## 4. Tests

| Suite | Cases |
|---|---|
| `RmeRefundRequestLifecycleTest` | 48 — RR-LIFE-01…10, RR-IDEM-01…08, RR-AMT-01…04, RR-OBL-01…05, RR-STALE-01…04, RR-ELIG-01…08, RR-LEDGER-01…04, RR-CLK-01 |
| `RmeRefundRequestAuthorityTest` | 44 — RR-SOD-01/02/03/05, RR-BR-01…06, RR-ROLE-01…12, RR-ACT-01/02, RR-OVS-01…05, RR-PRIV-01/03, RR-SEC-07/11/12, RR-NAV-01/02, RR-SR-01…08 |
| `RmeRefundRequestReferenceTest` | 36 — RR-REF-01…10/15…19, RR-PAT-01…05, RR-PRIV-02 |
| `RmeRefundRequestConcurrencyTest` | 12 — RR-CONC-01…06, RR-REF-03/08/11/12/13, RR-PAT-06/07, RR-SOD-04, RR-AMT-05, RR-REF-05 (PostgreSQL 16 only, REAL service in a second session) |

SQLite: 127 passed, 13 skipped (the 12 PostgreSQL race cases and the PostgreSQL CHECK case RR-SR-07).
PostgreSQL 16.15: all 140 pass; the race cleanup leaves no refund, registry or user row behind. Financial
regression on PostgreSQL 16.15 (refund, owner registry, invoice correction, billing dispute, payment
idempotency, clinic oversight, access control, patient merge, auth): **1181 passed, 0 failed, 0 skipped**.

Changed existing tests (each pinned the "no refund yet" state and now pins the new contract):
`FrontOfficeRoleTest` (the union names `FRONT_OFFICE_ONLY_PERMISSIONS`; 27 → 28), `FrontOfficeMigrationTest`
(27 → 28), `SupervisorRmeRolePermissionTest` (+`approve_rme_refund`), `ClinicOversightGuardLayerTest` (Owner
holds exactly the Billing Correction oversight area, never export — RF-8a), `RmeInvoiceCorrectionGovernanceTest`
(Owner among the oversight readers; 11 GET-only oversight routes; the only refund names are the two oversight
pages and the only refund permissions are request and approve; still no reversal, credit, settle, payout or
disburse route), and the cleanup lists of 7 race suites.

### 4a. Mutation

31 mutants on the service, controller, request, model, views, sidebar and the pre-payment guard, each applied
to a copy of the worktree, run against the refund suites and restored by copy with a sha256 check:
**31 killed, 0 survived, 0 not applied.** The first run left M18 (the application-level reuse check) alive:
a pre-payment correction may carry a reference the registry never records, and no test exercised it.
RR-REF-18 closes the gap.

### 4b. Independent security review

An adversarial review of the change (not shown the implementation's own conclusions) reported **CRITICAL 0,
HIGH 0, MEDIUM 1, LOW 6** and confirmed that no path moves money, bypasses maker ≠ checker, widens branch
authority from request input or deadlocks against the correction, payment, dispute or merge paths. Disposition:

| Finding | Disposition |
|---|---|
| M — the checker saw only an amount, not the case | Fixed: the decision page shows requester, reason, both references and the decision/cancellation notes (RR-SR-01); oversight still shows no free text |
| L — some refusals fired before the scope check (existence oracle) | Fixed: account authority → scope → input, in the controller, the store request and the service (RR-SR-02…05) |
| L — oversight pages not flag-gated | Kept on purpose (records auditable after rollback, as for corrections and obligations); flag description and rule 215 corrected |
| L — Supervisor RME scope = every RME branch | Recorded as decision 7 (existing governance tier); narrowing is an owner decision |
| L — state machine enforced only by the service | Fixed: model `updating` guard (RR-SR-06) and a 13th CHECK (RR-SR-07) |
| L — a pre-payment correction could record a reference a refund spent | Fixed: `isClaimed()` guard (RR-REF-19); the truly concurrent case stays a recorded residual in rule 214 D2 |
| L — an unknown unique violation labelled as "active request"; a replay said "filed" | Fixed: re-thrown; the message names the recorded status (RR-SR-08) |

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
