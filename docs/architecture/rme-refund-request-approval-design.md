# RME Refund Request & Approval — Technical Design (Sprint 4/7)

> **Status: DESIGN FOR APPROVED POLICY. Not implemented. Not activated.** Nothing here is production
> behaviour. Every element marked **[RF-n]** follows the owner decision of that code in
> `docs/decisions/rme-refund-request-approval-owner-decision-package.md`: on 2026-10-31 the owner answered
> RF-1 … RF-14, RF-1a and RF-8a (package §7.1 and §7.4, register entries D4, D5, D6, D18, D25, D26). Where
> the owner modified a recommendation, this design follows the modification.
>
> Prepared by `PREPARATION-RME-BILLING-REFUND-REQUEST-APPROVAL-1` against canonical base
> `f60d8232`, production runtime `fb3726c5` (tag `post-audit-f4bc-release-1-go`).

> **Update 2026-10-31:** implemented by `FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1` (rule 215) — It ships with the flag `rme.billing_refund_request` OFF and an EMPTY pilot identity allowlist: built, not activated. Where this document reads as future or proposed, it records the state before implementation; decisions taken while implementing are in `docs/sprints/feature-rme-billing-refund-request-approval-1.md` §3–§4b.

Companion documents: migration plan `docs/architecture/rme-refund-request-approval-migration-plan.md`,
threat model `docs/architecture/rme-refund-request-approval-threat-model.md`, TDD matrix
`docs/architecture/rme-refund-request-approval-tdd-matrix.md`, reconciliation contract
`docs/architecture/rme-refund-financial-reconciliation-contract.md`, ADR
`docs/adr/0013-rme-refund-request-approval.md`.

**Supersedes in part** `docs/architecture/rme-billing-refund-technical-design.md` (2026-10-16), which
was written before overpayment obligations existed: its §2.1 single refund table that carries request,
decision AND settlement, and its §4 refundable balance derived from `Σ payments − grand_total` minus
open commitments, are replaced below by an obligation-consuming request table and a separate Sprint 5
settlement record. Its findings in §1 (refund is a consumer of an overpayment; a payment-entry error is a
reversal) remain correct and are the premise of this design.

---

## 1. What already exists (verified 2026-10-31)

| Fact | Where |
|---|---|
| The only producer of an amount owed back is `RmeInvoiceCorrectionService::openObligation()`, run inside a POST_PAYMENT correction's apply transaction | `app/Modules/RmeInvoice/Services/RmeInvoiceCorrectionService.php` |
| `trx_rme_overpayment_obligations`: UNIQUE(`source_correction_id`), partial UNIQUE one `OPEN` per invoice, CHECK `amount > 0`, CHECK `status IN ('OPEN')`, FKs RESTRICT, no soft delete, `down()` refuses with rows | migration `2026_10_25_200002` |
| `RmeOverpaymentObligation` refuses every update except the one-time `obligation_number`, and every delete | model `booted()` |
| Ledger helper: `overpaidCents = max(0, paid − total)`, `paid = Σ trx_rme_payments.amount` | `Support/RmeInvoiceLedger.php` |
| PP-3: while an obligation is `OPEN` no further correction may be submitted or applied | `OVERPAYMENT_OBLIGATION_OPEN`, `CORRECTION_OVERPAYMENT_OBLIGATION_OPEN` |
| Every invoice-row mutator locks `trx_rme_invoices` `FOR UPDATE` — the correction and dispute services through `RmeInvoiceRepositoryInterface::findForUpdate()`, the payment service through `RmeInvoice::query()->lockForUpdate()` directly | correction, payment and dispute services |
| Post-payment correction refuses an Owner reference already recorded on a post-payment correction of **another** invoice, compared case-folded (`lower(owner_approval_reference)`); the check scans corrections only, so a refund consumer must be added to it in both directions. Its hardening is tracked separately as F-17 | `RmeInvoiceCorrectionRepository::postPaymentReferenceUsedOnOtherInvoice()` |
| Correction lock order invoice → correction → obligation | rules 190, 207 |
| Payment idempotency: HMAC intent bound to one invoice, immutable submission row with UNIQUE key, SHA-256 fingerprint, replay answered with the original | `Support/RmePaymentIntent.php`, rule 205 |
| Read-only obligation oversight, `ClinicOversightScope`, 404 out of scope | `OversightOverpaymentObligationController` |
| Refund: no table, route, permission, service or flag (code and production) | grep + production read-only, this task |
| Production: 0 obligations, 0 overpaid invoices; `rme.invoice_postpayment_correction` OFF | production read-only, this task |

## 2. Sprint 4 versus Sprint 5

| | Sprint 4 (this design) | Sprint 5 (outline only, §11) |
|---|---|---|
| Purpose | Decide **whether** an OPEN obligation will be paid back | **Pay it back** and prove it |
| Money moves | **Never** | Yes, once per approved request |
| New records | `trx_rme_refund_requests`, `trx_rme_refund_request_events` | settlement record, evidence, obligation `OPEN → SETTLED` |
| Obligation row | **Read and locked, never written** | Status transition through a narrow, enumerated path |
| Invoice, items, payments, receipts, versions | Read only | Read only (ledger gains a settled-refund term, §10) |
| KPIs | Unchanged | Model C netting by `settled_at` (WITA) |
| Printable document | None (screen label "DISETUJUI — BELUM DIBAYARKAN") | Refund voucher after `SETTLED` |
| Permission introduced | `request_rme_refund`, `approve_rme_refund` | `record_rme_refund_settlement` (not seeded in Sprint 4 — no orphan permission) |

Sprint 4 builds **no** settlement table, route, permission, button, service method or state. A test
asserts the route table holds no settlement route (TDD `RR-SEC-11`).

## 3. State machine (Sprint 4)

```mermaid
stateDiagram-v2
  [*] --> REQUESTED: submit (Front Office maker at its working branch; Owner reference; obligation OPEN; amount = obligation, server-derived)
  REQUESTED --> APPROVED: approve (reviewer ≠ maker, role + branch scope [RF-1]; Owner reference already on the request; re-validated under lock)
  REQUESTED --> REJECTED: reject (reviewer ≠ maker; note required) — obligation stays OPEN [RF-6]
  REQUESTED --> CANCELLED: withdraw (maker; reason)
  REQUESTED --> STALE: financial state changed since submit (detected under lock)
  APPROVED --> CANCELLED: revoke before settlement (reviewer ≠ maker; reason) [RF-6]
  APPROVED --> STALE: obligation no longer matches (defensive; cannot occur while PP-3 holds)
```

* Terminal in Sprint 4: `REJECTED`, `CANCELLED`, `STALE`. `APPROVED` is the waiting state that Sprint 5
  consumes; Sprint 5 adds its own terminal (`SETTLED`) additively.
* **`APPROVED` never means money left the clinic.**
* Every transition: one `DB::transaction`, locks in the §5 order, status re-read under the lock, illegal
  transition → refusal with a stable code, an exact repeat by the same actor with the same fingerprint →
  idempotent no-op returning the original result.
* A `STALE` transition **commits** (it is a fact about the request) while the action that discovered it
  is refused — the same pattern as correction staleness (rule 190 §2).
* A rejected, cancelled or stale request never returns to `REQUESTED`; a new request uses a new intent.

## 4. Amount and eligibility (server-derived, never from input)

Under the locks, for the obligation `O` of invoice `I`:

```
eligible(I, O) ⇔ flag ON
              ∧ pilot branch admits I.branch                                  [RF-3]
              ∧ O.status = OPEN ∧ O.rme_invoice_id = I.id ∧ O.branch_id = I.branch_id
              ∧ (for an existing request R) R.rme_overpayment_obligation_id = O.id = openForInvoice(I).id
              ∧ I.status = PAID ∧ I.correction_version = O.invoice_version
              ∧ overpaidCents(I) = cents(O.amount)                            (ledger agrees)
              ∧ no SUBMITTED/APPROVED correction on I                         (defensive; PP-3)
              ∧ no OPEN/UNDER_REVIEW/ESCALATED billing dispute on I           [RF-7]
              ∧ no REQUESTED/APPROVED refund request on O                     (one active request)
amount(request) = cents(O.amount)                                             [RF-4 option A]
```

* The form shows the amount read-only and **declares** the obligation id, obligation amount (cents) and
  invoice version it was rendered with. The service compares each declaration to the locked rows; any
  difference refuses the request (`REFUND_OBLIGATION_CHANGED`, `REFUND_INVOICE_VERSION_CHANGED`) — it
  never caps, rounds or substitutes (the rule 205 §F posture).
* If RF-4 is decided as B (partial), the amount becomes an input bounded by
  `cents(O.amount) − Σ active request amounts`, and the obligation needs a partially-settled concept in
  Sprint 5. This design does not pre-build that.

## 5. Lock order and linearization

**Order (fixed, no exceptions): invoice → obligation → refund request.** It extends the established
invoice → correction → obligation order; a refund never locks a correction or payment row for update.

| Step | Statement |
|---|---|
| 1 | `trx_rme_invoices` `FOR UPDATE` (`RmeInvoiceRepositoryInterface::findForUpdate`) |
| 2 | `trx_rme_overpayment_obligations` `FOR UPDATE` — on submit, the invoice's open obligation (`openForInvoice(lock: true)`) and it must equal the declared obligation id; on approve/reject/cancel, **the request's own `rme_overpayment_obligation_id`** (locked by id), asserted to be the invoice's open obligation |
| 3 | `trx_rme_refund_requests` `FOR UPDATE` (active request for the obligation, or the target row) |
| 4 | `assertActor()` re-read through `ActiveAccountResolver` — the linearization point (rule 201 §G); never lock `users` |
| 5 | Eligibility and staleness evaluated against the locked rows |

Concurrent submissions serialize on step 1; the second observes the first's request and answers
`REFUND_ACTIVE_REQUEST_EXISTS` (or a replay). The partial unique index is the database backstop; a
unique violation is translated at the transaction boundary into the same code (PostgreSQL aborts the
transaction, so it is never caught and continued inside it).

## 6. Service architecture (CG-04)

Module: **`App\Modules\RmeInvoice`** — the refund shares the invoice/obligation locks and ledger, exactly as
corrections and disputes do. A separate module would have to reach `trx_rme_invoices` and the obligation
table across a module boundary.

| Layer | Class (proposed) | Responsibility |
|---|---|---|
| Route | `routes/web.php` group `rme.refund-requests.*` | `auth`, `permission:request_rme_refund\|approve_rme_refund`, flag guard → 404 when OFF |
| Controller | `RmeRefundRequestController` | thin: resolve actor, delegate, map refusal codes to messages |
| FormRequest | `StoreRmeRefundRequestRequest`, `DecideRmeRefundRequestRequest`, `CancelRmeRefundRequestRequest` | shape only (lengths, intent present); `authorize()` runs the policy and `belongsToVisit`-style ownership; never business rules |
| Policy | `RmeRefundRequestPolicy` | courtesy for UI; page controls come from `actionsFor()` (AIR-RMB-18) |
| Service | `RmeRefundRequestService` | `panelFor`, `actionsFor`, `submit`, `approve`, `reject`, `cancel`, `paginateFor`; every rule below |
| Repository | `RmeRefundRequestRepositoryInterface` → `RmeRefundRequestRepository` | `findForUpdate`, `activeForObligation(lock)`, `create`, `transition(row, allowlisted columns)`, `assignNumber`, `recordEvent`, `paginateForBranches` |
| Models | `RmeRefundRequest`, `RmeRefundRequestEvent` | no `status`/`decided_*`/`cancelled_*`/`owner_approval_reference` in `$fillable`; delete refused; event model refuses update |
| Support | `RmeRefundRequestStatus`, `RmeRefundRequestDenied` (codes), `RmeRefundRequestFeatureGuard`, `RmeRefundRequestPilotScope`, `RmeRefundRequestPilotIdentity`, `RmeRefundRequestIntent`, `RmeRefundRequestSnapshot` | constants, flag via `FeatureFlagService`, source-controlled pilot, HMAC intent with a refund-specific context string, snapshot hash |
| Audit | `RmeFinancialAuditEvent` (extended) | `ENTITY_REFUND_REQUEST`, `ACTION_REFUND_REQUEST_{SUBMITTED,APPROVED,REJECTED,CANCELLED,STALE,REFUSED}`; payload ids/codes/cents only |
| Oversight | `OversightRefundRequestController` | GET-only, `ClinicOversightScope` area billing-correction, 404 out of scope |
| Binding | `RepositoryServiceProvider` | repository + policy |

**Reused, not re-implemented:** `RmeBillingActorGuard` / `ActiveAccountResolver` (explicit non-nullable
`User` actor; no `Auth::`, `auth()`, `request()`, `session()`, `BranchContext` in the service),
`RmeWorkingBranchScope`, `RmeInvoiceLedger`, `RmeInvoiceCorrectionApprovalReference::normalize()` for the
reference format (6–120 chars, ≤ 12 digits per run across separators), `ClinicalClock` for the number's
month, `FeatureFlagService`, `AuditLogService`.

The intent must **not** reuse `RmePaymentIntent` as-is: a payment intent is bound to an invoice id and
would validate for a refund form of the same invoice. `RmeRefundRequestIntent` follows the same
construction — a fresh 128-bit nonce per rendered form and an HMAC (application key) over
`"rme-refund-request|v1|{nonce}|{obligation_id}"` — so the token changes with every form and neither a
payment token nor a refund token validates as the other. Signing without the nonce would give every form
of one obligation the same signature and make any nonce acceptable.

## 7. Algorithms

### 7.1 Submit
1. Flag guard (`REFUND_REQUEST_NOT_ENABLED`); resolve actor (persisted, active, not soft-deleted,
   effective `request_rme_refund`) **and the Front Office role** [RF-1, approved 2026-10-31] — a permission
   alone is not enough, because Super Admin holds every permission and Supervisor RME holds many, and
   neither is a maker ⇒ `REFUND_MAKER_NOT_AUTHORIZED`.
2. Intent: `keyFrom(token, obligationId)` → null ⇒ `REFUND_INTENT_INVALID`.
3. Transaction, §5 locks.
4. Branch: `I.branch_id ∈ RmeWorkingBranchScope(actor)` — for Front Office its assigned working branch
   [RF-1, approved] — else **404**; pilot scope [RF-3, approved: SPN4] else `REFUND_PILOT_SCOPE_DENIED`;
   pilot identity [RF-1a, approved: a source-controlled list, **empty by default**, never the Sprint 2 pair
   by inheritance] else `REFUND_PILOT_IDENTITY_DENIED`.
5. Replay: a request (in any state, terminal included) with the same `intent_key` → same requester + same
   fingerprint ⇒ return that request unchanged, whatever its current status, and write nothing; otherwise
   `REFUND_INTENT_REUSED`. A rejected request is therefore never re-filed by a replay; a new request
   needs a new intent (rule 205 semantics).
6. Eligibility (§4) on locked rows; each failure has its own code (§9).
7. Declarations vs locked rows (§4).
8. Insert `REQUESTED` with: amount, `obligation_amount_snapshot`, `invoice_version`, `request_snapshot`
   (jsonb: invoice id, version, grand total cents, paid cents, payment count, obligation id/amount/
   version) and its SHA-256, reason code `OVERPAYMENT_AFTER_CORRECTION`, reason note, patient request
   reference [RF-5 = B, approved: mandatory, 6–120 characters, no NIK — `REFUND_PATIENT_REFERENCE_REQUIRED` /
   `REFUND_PATIENT_REFERENCE_INVALID`], intent key, fingerprint.
8a. Owner approval reference [RF-1, approved 2026-10-31]: required on **every** request
   (`REFUND_OWNER_REFERENCE_REQUIRED`), valid (`RmeInvoiceCorrectionApprovalReference::normalize()`, else
   `REFUND_OWNER_REFERENCE_INVALID`) and — compared **case-folded** — never recorded before on **any** refund
   request or on **any** post-payment correction (else `REFUND_OWNER_REFERENCE_REUSED`). It is stored on the
   row (`NOT NULL`), linked to the invoice and obligation the row names, and consumed in the shared registry
   with scope (`REFUND_REQUEST`, this request's id) in the same transaction, after all row locks (§7.2
   step 7 explains the registry). The Owner approval therefore precedes any processing of the request.
8b. Patient request reference [RF-5 = B, approved]: consumed in the patient-reference registry with scope =
   **this obligation** (migration plan §2b), after the Owner reference, in the same transaction: the same
   obligation may repeat it on a re-filed request; any other obligation ⇒ `REFUND_PATIENT_REFERENCE_REUSED`.
9. Number `RMEREF-{Ym of ClinicalClock}-{id:06d}` assigned after insert (unique; no lock-free MAX+1).
10. Event `null → REQUESTED`; audit `ACTION_REFUND_REQUEST_SUBMITTED` inside the transaction.
11. Refusal: audited after rollback with its code (AIR-RMB-08).

### 7.2 Approve
1. Flag, actor (effective `approve_rme_refund`). The reviewer supplies **no** reference.
2. Transaction, §5 locks on the request's invoice and obligation.
3. Status must be `REQUESTED`. `APPROVED` by the same decider with the same fingerprint ⇒ no-op (original
   result); `APPROVED`/`REJECTED` otherwise ⇒ `REFUND_REQUEST_ALREADY_DECIDED`; `CANCELLED`/`STALE` ⇒
   `REFUND_INVALID_TRANSITION`.
4. **Maker ≠ checker per account, in the service, Super Admin included:**
   `request.requested_by !== actor.id` ⇒ `REFUND_SELF_APPROVAL_FORBIDDEN`. DB CHECK is the backstop.
5. Reviewer authority for **the invoice's** branch [RF-1, approved 2026-10-31] (§8): the actor holds a
   reviewer role and that branch lies in the role's server-resolved scope — Super Admin: every active RME
   branch; Supervisor RME: its legitimate authorized scope; Kepala Cabang: its own managed branch — else
   `REFUND_REVIEWER_NOT_AUTHORIZED` (out of every scope ⇒ 404). Pilot scope and identity [RF-3, RF-1a, approved].
6. Re-validate §4 and recompute the snapshot hash. Mismatch ⇒ transition to `STALE` (committed) and
   refuse with `REFUND_REQUEST_STALE`.
7. Owner reference [RF-1, approved]: the request's own reference, captured at submit (§7.1 step 8a), is
   present and its registry row is scoped to **this** request (defensive re-check) ⇒ else
   `REFUND_OWNER_REFERENCE_INVALID`. It was checked at submit, case-folded, against **any** refund request
   and **any** post-payment correction (any invoice, the source correction included). One Owner approval
   authorises exactly one decision. The same check must be added in the other direction: a
   post-payment correction must refuse a reference already recorded on a refund (today its check scans
   corrections only). Backstop: one shared registry of consumed Owner references, folded key UNIQUE, each
   reference bound to one scope — the invoice for a post-payment correction (so PP-2's same-invoice
   resubmission after `STALE`/`REJECTED` still works), the refund request for a refund approval — consumed
   with `INSERT … ON CONFLICT DO NOTHING` followed by a scope comparison, after all row locks, in the same
   transaction (migration plan §2a). A revoked or rejected request does not release its reference; a
   re-filed request needs a new one [RF-6, approved 2026-10-31: every new request after a rejection,
   withdrawal or revocation carries a NEW Owner reference].
7a. Active billing dispute on the invoice ⇒ `REFUND_INVOICE_DISPUTED`, re-checked here under the invoice lock
   as well as at submit [RF-7, approved]; nothing resolves the dispute or releases a hold.
8. Transition `APPROVED`; event; audit. **No write to obligation, invoice, items, payments, receipts.**

### 7.3 Reject
`REQUESTED → REJECTED` by a reviewer authorised as in §7.2 step 5 that is not the maker
(`REFUND_SELF_DECISION_FORBIDDEN`), decision note required (10–1000 chars, never in audit payloads).
The obligation stays `OPEN` and nothing is forgiven [RF-6, approved].

### 7.4 Cancel
* `REQUESTED → CANCELLED` by the maker, reason required.
* `APPROVED → CANCELLED` by a reviewer that is not the maker **and is authorised for the invoice's branch**
  (§7.2 step 5), reason required [RF-6, approved]. The service enforces both; there is no database CHECK on
  `cancelled_by` (it may legitimately equal the maker for a `REQUESTED` withdrawal).
  (Sprint 5 adds: refused once a settlement exists.)

## 8. Authorization and branch isolation

Three layers (CG-06): route middleware, policy, **re-assertion in the service**. The service is the only
layer that binds Super Admin, because `Gate::before` grants Super Admin every ability.

| Rule | Enforced where |
|---|---|
| Effective permission (`$actor->can(...)`) | service (necessary, **not sufficient**: true for Super Admin by `Gate::before`) |
| Branch of the action = the obligation's branch = the invoice's branch; never a request `branch_id` | service, from locked rows |
| Actor's `RmeWorkingBranchScope` contains that branch; out of scope ⇒ 404 (existence does not leak) | service + controller |
| **Role + branch authority** [RF-1, `OWNER_POLICY_APPROVED` 2026-10-31]: maker = Front Office at its assigned working branch; reviewer = Super Admin (all active RME branches), Supervisor RME (its authorized scope) or Kepala Cabang (its own managed branch), the scope always resolved server-side from the persisted account | service |
| Maker ≠ checker per account, Super Admin included | service under lock + DB CHECK |
| Owner reference mandatory on every request, before processing [RF-1, approved] | service + `NOT NULL` + shared registry |

**Multi-branch access is not authority** (task brief). Authority comes only from the owner-approved role
and its scope (RF-1, 2026-10-31): Super Admin reviews for every active RME branch because the owner
granted that, not because it can see them; Supervisor RME and Kepala Cabang review only inside their
scope. Being able to *see* an invoice of another branch never admits an action, and holding a permission
through `'*'` or a wide grant never makes an account a maker. Owner, Finance Klinik and Data Analyst read
oversight only.

### 8.1 Role/permission matrix [RF-1, RF-3, RF-8, RF-8a — approved 2026-10-31; not yet seeded]

| Capability | Permission | Held by (proposed) | Effective authority |
|---|---|---|---|
| File a request | `request_rme_refund` | **Front Office only** [RF-1, approved] — never Kasir or Admin Klinik, and never derived from `manage_rme_billing` | + Front Office role checked in the service + assigned working branch |
| Approve / reject / revoke | `approve_rme_refund` | Supervisor RME, **Kepala Cabang** (this permission and a narrow review page only — no other RME permission), Super Admin through `'*'` [RF-1, approved] | + reviewer role + server-resolved branch scope; the Owner reference is already on the request |
| Withdraw own request | `request_rme_refund` | as above | maker of that row only |
| Read on the invoice | existing `manage_rme_billing` | cashier roles | invoice in working scope |
| Oversight read | existing `view_billing_correction_oversight` (+ `export_oversight_data`) | Finance Klinik, Data Analyst (they hold it today). **Owner does not** — the billing-correction oversight route group is gated on that permission, so Owner visibility requires granting Owner the permission (RF-8a), which also exposes the existing correction and obligation oversight pages to Owner. **Approved grant [RF-8a, 2026-10-31]:** that one permission only — not `export_oversight_data`, no write permission. Re-verified 2026-10-31: 9 routes, all GET; 4 of them (CSV, list print, per-correction print and PDF) also require `export_oversight_data`, so Owner reaches 5 read routes | `ClinicOversightScope` (all RME branches except MAIN), GET-only |
| Settlement | — | **none in Sprint 4** | — |

Never granted: `approve_rme_refund` to Owner, Finance Klinik, Data Analyst, Doctor, Perawat, Kasir,
Front Office, Admin Klinik; `request_rme_refund` to anyone but Front Office;
any refund permission to the lab `Finance` role (separate domain, AIR-RMB-01). Before seeding, diff
production grants against `RoleSeeder::ROLE_PERMISSIONS` (`syncPermissions` removes what is missing);
seed `PermissionSeeder` → `RoleSeeder` → `permission:cache-reset`.

Staffing: measure, per pilot branch, how many active accounts can be maker and checker before arming
(an enforced-but-unstaffable control is a stop-the-line finding). Account separation is verifiable;
human separation is not and is never claimed (CG-07). The Sprint 2 pair carries
`HUMAN_SEPARATION=RED_FLAG_UNRESOLVED`; refund does **not** inherit it [RF-1a, approved 2026-10-31: a real
assigned Front Office maker and an independently identified reviewer with verified human separation, or
`PILOT_ACTIVATION = NO_GO`].

## 9. Refusal codes (stable, machine-readable)

`REFUND_REQUEST_NOT_ENABLED`, `REFUND_MAKER_NOT_AUTHORIZED`, `REFUND_REVIEWER_NOT_AUTHORIZED`,
`REFUND_PILOT_SCOPE_DENIED`, `REFUND_PILOT_IDENTITY_DENIED`,
`REFUND_ACTOR_UNUSABLE`, `REFUND_INTENT_INVALID`, `REFUND_INTENT_REUSED`, `REFUND_OBLIGATION_NOT_FOUND`
(rendered 404), `REFUND_OBLIGATION_NOT_OPEN`, `REFUND_OBLIGATION_CHANGED`, `REFUND_INVOICE_VERSION_CHANGED`,
`REFUND_LEDGER_MISMATCH`, `REFUND_INVOICE_NOT_PAID`, `REFUND_CORRECTION_ACTIVE`, `REFUND_INVOICE_DISPUTED`,
`REFUND_ACTIVE_REQUEST_EXISTS`, `REFUND_REQUEST_ALREADY_DECIDED`, `REFUND_INVALID_TRANSITION`,
`REFUND_SELF_APPROVAL_FORBIDDEN`, `REFUND_SELF_DECISION_FORBIDDEN`, `REFUND_REQUEST_STALE`,
`REFUND_OWNER_REFERENCE_REQUIRED`, `REFUND_OWNER_REFERENCE_INVALID`, `REFUND_OWNER_REFERENCE_REUSED`,
`REFUND_REASON_REQUIRED`, `REFUND_PATIENT_REFERENCE_REQUIRED`, `REFUND_PATIENT_REFERENCE_INVALID`,
`REFUND_PATIENT_REFERENCE_REUSED` [RF-5 = B, approved]
(the patient request reference is compared case-folded against every other refund request; a re-filed
request for the same obligation after `REJECTED`/`CANCELLED`/`STALE` may carry the same patient reference,
because it is the same patient request — the per-obligation uniqueness contract of RF-5, package §7.5 K-1).

## 10. Ledger and reporting impact

Sprint 4 changes **no** ledger term and **no** KPI: `Σ payments`, invoice totals, status and version,
obligation rows and receipt snapshots are byte-identical before and after any refund lifecycle (contract
`RFI-8`). Oversight shows "Refund disetujui — belum dibayar" as a separate figure, never deducted from
Omzet or added to piutang [RF-8]. The ledger change that Sprint 5 needs is fixed in the reconciliation
contract §3 so that Sprint 4 does not design itself into a corner.

## 11. Sprint 5 outline (not designed here)

* `trx_rme_refund_settlements`: one per `APPROVED` request (UNIQUE), amount = request amount, method and
  evidence per RF-9, `settled_by` ≠ `decided_by` per RF-10, `settled_at` per RF-11, no soft delete.
* Obligation `OPEN → SETTLED`: widen the status CHECK additively and relax the model guard to one
  enumerated transition with its own event — preferred over deriving resolution, because the one-OPEN
  partial index and `openForInvoice()` (which gates PP-3) both key off the status column.
* Ledger: `net_received = Σ payments − Σ settled refunds`; `overpaid' = max(0, net_received − total)`.
* Model C reporting by `settled_at` (WITA), invoice-branch attribution, closed periods never restated.

## 12. UI (Blade + Tailwind + Alpine, `x-ui.*`)

* Invoice panel (cashier): obligation status, active request status, "Ajukan Refund" only when
  `actionsFor()` says so (courtesy; the service refuses independently).
* `GET /rme/refund-requests` — branch-scoped queue (Menunggu Persetujuan / Disetujui — belum dibayar /
  Ditolak / Dibatalkan / Kedaluwarsa); detail with event history; approve/reject/cancel forms.
* No print or PDF in Sprint 4: a printed "approved refund" invites being treated as a payout voucher.
* No patient bank account, NIK or other identity beyond what the invoice screen already shows (masked).

## 13. Rollback plan

| Situation | Action | Data effect |
|---|---|---|
| Defect found before activation | Leave flag OFF; fix forward | none (no rows) |
| Defect after activation | First withdraw/revoke every `REQUESTED`/`APPROVED` row through the service (the release paths need the flag ON), then flag OFF (routes 404, service refuses). If rows must stay, they remain inert while OFF; no money has moved — the same order AIR-RMB-23 requires for active corrections | none; obligations untouched |
| Need to roll code back | Redeploy previous GO tag; migrations stay (additive). `down()` refuses while rows exist | none |
| Wrongly approved request | Revoke `APPROVED → CANCELLED` (authorised reviewer ≠ maker, reason) before any settlement exists | event appended; obligation stays OPEN |

Because Sprint 4 never moves money and never writes the obligation, invoice or payments, every rollback
is non-financial. Never delete or edit a refund row or event to "undo" a decision.

## 14. Policy-free work that may proceed separately (flag stays OFF; not part of this task)

| Item | Why it needs no new policy |
|---|---|
| `READ-ONLY-RME-OBLIGATION-RECONCILIATION-PROBE-1`: a read-only command asserting `RFI-1…RFI-3` against the live ledger | asserts existing PP-3 invariants; writes nothing |
| `FIX-RME-OVERPAYMENT-OBLIGATION-NUMBER-CLINICAL-MONTH-1`: obligation numbers use `now()->format('Ym')` (UTC) instead of the `ClinicalClock` month | CG-12 conformance; 0 obligations exist, so no stored number changes |
| `HARDENING-RME-PAYMENT-AMOUNT-CHECK-1`: `trx_rme_payments` has no DB CHECK `amount > 0` (application-enforced only; production holds 0 non-positive rows) | additive backstop for an existing rule |
| `HARDENING-RME-OWNER-REFERENCE-REGISTRY-1` (F-17, separate security hardening): the post-payment correction's "reference not reused on another invoice" rule (PP-2) needs a database-backed guarantee (analysis kept in a private security record); the scope-keyed consumed-reference registry (migration plan §2a) provides it. **Must ship before any activation of post-payment correction** (task instruction, 2026-10-31); the refund later becomes a second consumer of the same registry | enforces the already-ratified PP-2 non-reuse rule, preserves its same-invoice resubmission; production holds 0 post-payment corrections |

Refund tables are **not** in this list: building the schema before RF-1…RF-8 are answered would create
a dormant financial surface whose shape the owner has not decided.
