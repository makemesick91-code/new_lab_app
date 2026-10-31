# RME Refund Request & Approval — TDD Matrix (Sprint 4/7)

> **Status: CONTRACT for a future implementation.** Write each test first and watch it fail against the
> unmodified base. Design: `docs/architecture/rme-refund-request-approval-design.md`; threat IDs:
> `docs/architecture/rme-refund-request-approval-threat-model.md`.

> **Update 2026-10-31:** implemented by `FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1` (rule 215) — each row is implemented as a test of the same id in `tests/Feature/RmeRefundRequest/`. It ships with the flag `rme.billing_refund_request` OFF and an EMPTY pilot identity allowlist: built, not activated. Where this document reads as future or proposed, it records the state before implementation; decisions taken while implementing are in `docs/sprints/feature-rme-billing-refund-request-approval-1.md` §3–§4b.

## 1. Rules for this suite

* Synthetic data only, built through the canonical services — never production data, never a fabricated
  production record (CG-13). An OPEN obligation is produced in tests by driving a real POST_PAYMENT
  correction through `RmeInvoiceCorrectionService` (flags enabled in the test), not by inserting the row.
* Payment fixtures use `rmeFreshPaymentForm()` / `rmeDeclaredPaymentForm()` so payment races reach the
  lock (AIR-RMB-41).
* Locks, CHECKs, partial UNIQUE indexes and races are proven on **PostgreSQL 16** (CG-20). SQLite compiles
  `lockForUpdate` to nothing and does not create the PostgreSQL-only constraints.
* Clock pinned (`Carbon::setTestNow`, `clinicalToday()`) for numbering and month boundaries.
* One `php artisan test` process per worktree.
* CI selection (CG-19): directory `tests/Feature/RmeRefundRequest/`, class names beginning
  `RmeRefundRequest`; add the token `RmeRefundRequest` to **both** NSF-R011 critical-gate variants and
  declare the concurrency, authorization and ledger suites in `config/ci_runner.php`
  `critical_gate_mandatory_suites`. Verify selection with `--list-tests`, not by reading the filter.

## 2. Matrix

Layer: **F** = feature (SQLite + PostgreSQL), **PG** = PostgreSQL 16 only, **S** = structural/source scan,
**U** = unit. Expected = refusal code or outcome.

### Lifecycle

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-LIFE-01 | Maker files a request for an OPEN obligation | F | `REQUESTED`, amount = obligation, number `RMEREF-…`, one event, one audit row |
| RR-LIFE-02 | Authorised reviewer approves a request that carries a valid Owner reference | F | `APPROVED`; obligation, invoice, payments unchanged |
| RR-LIFE-03 | Checker rejects with a note | F | `REJECTED`; obligation still `OPEN`; a new request with a new intent is accepted |
| RR-LIFE-04 | Maker withdraws `REQUESTED` | F | `CANCELLED`; reason stored |
| RR-LIFE-05 | Authorised reviewer (≠ maker) revokes `APPROVED` | F | `CANCELLED`; obligation `OPEN` |
| RR-LIFE-06 | Maker tries to revoke own `APPROVED` | F | `REFUND_SELF_DECISION_FORBIDDEN` [RF-6] |
| RR-LIFE-07 | Every illegal transition from every state | F | `REFUND_INVALID_TRANSITION`; no event |
| RR-LIFE-08 | Event chain: last event `to_status` = row status for every state | F | holds |

### Idempotency and duplicates

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-IDEM-01 | **Duplicate request**: same intent, same maker, same form | F | original returned; 0 new rows |
| RR-IDEM-02 | Same intent, different form content | F | `REFUND_INTENT_REUSED` |
| RR-IDEM-03 | Different intent while a request is active | F | `REFUND_ACTIVE_REQUEST_EXISTS` |
| RR-IDEM-04 | **Duplicate approval**: same checker repeats approve | F | no-op, original result; 1 event |
| RR-IDEM-05 | **Rejected request replay**: re-submit the rejected request's exact form and intent | F | the original `REJECTED` request is returned; 0 new rows; status still `REJECTED`. Same intent with altered content → `REFUND_INTENT_REUSED` |
| RR-IDEM-06 | A payment intent submitted as a refund intent, and the reverse | U/F | `REFUND_INTENT_INVALID` / payment refused |
| RR-IDEM-07 | Missing or forged intent; a token whose nonce was swapped | F | `REFUND_INTENT_INVALID` |
| RR-IDEM-08 | Application key changed between render and submit | F | `REFUND_INTENT_INVALID`; re-rendered form succeeds; no stored value depends on the key |

### Separation of duties and Super Admin

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-SOD-01 | **Maker = checker** (ordinary accounts) | F | `REFUND_SELF_APPROVAL_FORBIDDEN` |
| RR-SOD-02 | Maker = checker on reject | F | `REFUND_SELF_DECISION_FORBIDDEN` |
| RR-SOD-03 | **Super Admin bypass**: Super Admin who filed tries to approve | F | refused in the service before the database |
| RR-SOD-04 | DB backstop: forced row with `decided_by = requested_by` | PG | CHECK violation |
| RR-SOD-05 | Super Admin approves a request another account filed — no account list exists; its authority is the owner-approved role scope (RF-1, 2026-10-31) | F | `APPROVED` |

### Branch isolation

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-BR-01 | **Cross-branch**: request on another branch's obligation | F | 404, no row |
| RR-BR-02 | Cross-branch approve, reject, cancel, show | F | 404 each |
| RR-BR-03 | Submitted `branch_id` differing from the obligation's | F | ignored; branch = obligation's |
| RR-BR-04 | Reviewer acts on a branch outside its role scope (Supervisor RME outside its authorized scope, Kepala Cabang on another branch) | F | refused; 404 where existence must not leak |
| RR-BR-05 | Account holding `request_rme_refund` or `approve_rme_refund` through `'*'` or a wide grant but without the required role | F | refused (`REFUND_MAKER_NOT_AUTHORIZED` / `REFUND_REVIEWER_NOT_AUTHORIZED`) |
| RR-BR-06 | Context-bound cashier pinned to another working branch | F | 404 |

### Amounts and obligation

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-AMT-01 | **Invalid amount** declared (negative, zero, non-numeric) | F | refused before the transaction; no row |
| RR-AMT-02 | **Excessive refund**: declared amount > obligation | F | `REFUND_OBLIGATION_CHANGED` (never capped) |
| RR-AMT-03 | Declared amount < obligation [RF-4 A] | F | `REFUND_OBLIGATION_CHANGED` (no partial) |
| RR-AMT-04 | Request amount always equals obligation amount (no amount input honoured) | F | holds |
| RR-AMT-05 | DB backstop: `amount ≠ obligation_amount_snapshot`, `amount ≤ 0` | PG | CHECK violation |
| RR-OBL-01 | **Refund against non-existent obligation** | F | 404 |
| RR-OBL-02 | Obligation of another invoice than the route's | F | 404 |
| RR-OBL-03 | **Already settled obligation** (status ≠ OPEN) | U | `REFUND_OBLIGATION_NOT_OPEN` — repository double in Sprint 4 (the CHECK admits only `OPEN`); converted to a real-row PostgreSQL test when Sprint 5 widens the CHECK |
| RR-OBL-04 | Invoice with no obligation (ordinary PAID) | F | no action offered; POST refused |
| RR-OBL-05 | Any field naming a payment id is ignored; a payment cannot be the subject | F | holds |

### Staleness and eligibility

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-STALE-01 | **Stale invoice**: declared invoice version ≠ locked version | F | `REFUND_INVOICE_VERSION_CHANGED` |
| RR-STALE-02 | **Modified obligation**: snapshot hash differs at approval | U/F | request → `STALE` (committed), approval refused `REFUND_REQUEST_STALE` |
| RR-STALE-03 | Ledger disagrees: `overpaidCents(invoice) ≠ obligation` | U/F | `REFUND_LEDGER_MISMATCH` |
| RR-STALE-04 | `STALE` is terminal; new request with a new intent accepted once state is consistent | F | holds |
| RR-ELIG-01 | Flag OFF: routes | F | 404 |
| RR-ELIG-02 | Flag OFF: service called directly | F | `REFUND_REQUEST_NOT_ENABLED` |
| RR-ELIG-03 | Active billing dispute on the invoice | F | `REFUND_INVOICE_DISPUTED` [RF-7] |
| RR-ELIG-04 | Active correction on the invoice (defensive) | U | `REFUND_CORRECTION_ACTIVE` |
| RR-ELIG-05 | Branch outside the pilot | F | `REFUND_PILOT_SCOPE_DENIED` |
| RR-ELIG-06 | Invoice not `PAID` | U | `REFUND_INVOICE_NOT_PAID` |
| RR-ELIG-07 | Flag ON, pilot branch, but the pilot identity list is empty (shipped default) or does not name the actor [RF-1a] | F | `REFUND_PILOT_IDENTITY_DENIED`; the Sprint 2 pair is not admitted by inheritance |
| RR-ELIG-08 | Dispute opened after submit, before approval [RF-7: submission **and** approval] | F | approval refused `REFUND_INVOICE_DISPUTED`; dispute, hold and correction state unchanged |

### Owner reference

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-REF-01 | **Missing Owner approval** reference **at submit** | F | `REFUND_OWNER_REFERENCE_REQUIRED`; no row, no registry row |
| RR-REF-02 | Malformed / > 12 digits in one run (NIK-shaped, also split by separators) | F | `REFUND_OWNER_REFERENCE_INVALID` |
| RR-REF-03 | Reference reused from another refund | F + PG | `REFUND_OWNER_REFERENCE_REUSED`; `lower()` partial UNIQUE backstop |
| RR-REF-04 | Reference equal to the source correction's reference | F | `REFUND_OWNER_REFERENCE_REUSED` |
| RR-REF-05 | DB backstop: any request row without a reference (any status) | PG | NOT NULL / CHECK violation |
| RR-REF-06 | Reference already recorded on a post-payment correction of **another** invoice | F | `REFUND_OWNER_REFERENCE_REUSED` |
| RR-REF-07 | Case variant of a used reference (`REF-1` vs `ref-1`), on a refund and on a correction | F + PG | refused; backstop holds |
| RR-REF-08 | Reverse direction and race: a post-payment correction submitted with a reference already used by a refund; two concurrent submissions on different invoices with one reference | F + PG | `CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED`; exactly one consumer wins (registry UNIQUE) |
| RR-REF-09 | A rejected, withdrawn or revoked request's reference offered for the re-filed request | F | `REFUND_OWNER_REFERENCE_REUSED` [RF-6, approved 2026-10-31: a new request carries a NEW Owner reference] |
| RR-REF-10 | **PP-2 preserved**: a post-payment correction resubmitted on the **same** invoice after `REJECTED`/`STALE` with the same reference (and a case variant of it) | F | accepted; registry row unchanged (scope = that invoice) |
| RR-REF-11 | Two sessions, two invoices, case variants of one reference, submitted concurrently | PG | exactly one scope owns the key; the loser is refused with an application exception, never `25P02`; no deadlock |
| RR-REF-12 | Consuming transaction rolled back after the registry insert (refusal later in the same transaction) | PG | no registry row remains; the reference can be used by its legitimate scope afterwards |
| RR-REF-13 | DB backstop: registry row with an unfolded key (`REF-1`) | PG | CHECK violation |
| RR-REF-14 | Registry helper under REPEATABLE READ | PG | refuses (fails closed), never accepts on a stale snapshot |
| RR-REF-15 | A reference sent with the approval form | F | ignored; the request's own reference stays the recorded one |
| RR-REF-16 | At approval the registry row for the request's reference is scoped to another request (tampered) | PG | `REFUND_OWNER_REFERENCE_INVALID`; no transition |

### Maker and reviewer authority [RF-1, `OWNER_POLICY_APPROVED` 2026-10-31]

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-ROLE-01 | Kasir files a request | F | `REFUND_MAKER_NOT_AUTHORIZED`; no row |
| RR-ROLE-02 | Admin Klinik files a request | F | `REFUND_MAKER_NOT_AUTHORIZED`; no row |
| RR-ROLE-03 | **Super Admin** (`Gate::before`) files a request | F | `REFUND_MAKER_NOT_AUTHORIZED`, refused in the service |
| RR-ROLE-04 | Supervisor RME files a request | F | `REFUND_MAKER_NOT_AUTHORIZED` |
| RR-ROLE-05 | Front Office files for an invoice outside its assigned working branch | F | 404; no row |
| RR-ROLE-06 | Kepala Cabang approves a request of its own managed branch | F | `APPROVED` |
| RR-ROLE-07 | Kepala Cabang approves another branch's request | F | 404 / `REFUND_REVIEWER_NOT_AUTHORIZED` |
| RR-ROLE-08 | Kepala Cabang's grant is narrow: no visit, medical-record, cashier or billing route or permission is reachable | F + S | 403/404 on each; seeded permission set equals the expected list |
| RR-ROLE-09 | Supervisor RME approves inside / outside its authorized scope | F | `APPROVED` / `REFUND_REVIEWER_NOT_AUTHORIZED` |
| RR-ROLE-10 | Super Admin approves at a branch that is not an active RME branch (incl. MAIN) | F | 404 |
| RR-ROLE-11 | Front Office, Kasir, Admin Klinik, Owner, Finance Klinik, Data Analyst try to approve or reject | F | `REFUND_REVIEWER_NOT_AUTHORIZED` |
| RR-ROLE-12 | One account holding Front Office **and** Kepala Cabang files, then approves its own request | F | `REFUND_SELF_APPROVAL_FORBIDDEN` |

### Patient request reference [RF-5]

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-PAT-01 | Reference used by a request for a different obligation (also case variant) | F | `REFUND_PATIENT_REFERENCE_REUSED` |
| RR-PAT-02 | Same reference on a re-filed request for the same obligation after `REJECTED` | F | accepted |
| RR-PAT-03 | NIK-shaped / malformed reference (also split by separators; < 6 or > 120 characters) | F | `REFUND_PATIENT_REFERENCE_INVALID` |
| RR-PAT-04 | **Missing** patient reference [RF-5 = B: mandatory] | F | `REFUND_PATIENT_REFERENCE_REQUIRED`; no request row, no registry row |
| RR-PAT-05 | Same patient reference on a re-filed request for the same obligation after `CANCELLED` or `STALE` (per-obligation contract, package §7.5 K-1) while the Owner reference is new | F | accepted; patient-reference registry row unchanged |
| RR-PAT-06 | Two sessions, two obligations, case variants of one patient reference, concurrently | PG | exactly one obligation owns the key; the loser is refused with an application exception, never `25P02`; no deadlock with the Owner-reference registry (fixed order) |
| RR-PAT-07 | DB backstop: request row without a patient reference, or with one shorter than 6 characters; registry row with an unfolded key | PG | NOT NULL / CHECK violation |

### Concurrency (PostgreSQL 16, two real sessions)

| ID | Case | Expected |
|---|---|---|
| RR-CONC-01 | **Concurrent requests** for one obligation | exactly one `REQUESTED`; the other `REFUND_ACTIVE_REQUEST_EXISTS` |
| RR-CONC-02 | **Concurrent approvals** by two checkers | exactly one `APPROVED`; the other `REFUND_REQUEST_ALREADY_DECIDED` |
| RR-CONC-03 | Approve vs maker withdraw | one wins; if the withdraw wins the approver gets `REFUND_INVALID_TRANSITION`; if the approval wins the withdraw gets `REFUND_INVALID_TRANSITION` |
| RR-CONC-04 | Approve vs dispute filed on the invoice | serialized on the invoice lock; never approved while a dispute is active |
| RR-CONC-05 | Request vs a payment attempt on the same invoice | payment refused (remaining 0); refund unaffected; no deadlock (40P01) |
| RR-CONC-06 | Unique-violation path: transaction aborted, translated at the boundary, no `25P02` leak | holds |

### Ledger, immutability and settlement absence

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-LEDGER-01 | **Historical payment immutability**: payment rows byte-identical across every lifecycle | F | holds |
| RR-LEDGER-02 | Invoice totals, status, version, items unchanged | F | holds |
| RR-LEDGER-03 | Obligation row and its events unchanged by any refund action | F | holds |
| RR-LEDGER-04 | Receipt snapshots and document versions unchanged | F | holds |
| RR-SEC-07 | Mass assignment of `status`, `decided_by`, `owner_approval_reference` ignored | F | holds |
| RR-SEC-11 | **Unauthorized settlement**: no route, permission, service method or status for settlement exists | S | holds |
| RR-SEC-12 | Service files contain no `Auth::`, `auth()`, `request()`, `session()`, `BranchContext` | S | holds |

### Actor, privacy, clock, oversight

| ID | Case | Layer | Expected |
|---|---|---|---|
| RR-ACT-01 | Actor deactivated between render and submit | F | `REFUND_ACTOR_UNUSABLE` |
| RR-ACT-02 | Permission removed between render and approve | F | refused |
| RR-PRIV-01 | Audit and event payloads contain no name, NIK, RM number, note or reference text | F | holds |
| RR-PRIV-02 | Oversight list/export: NIK masked, CSV formula-injection safe | F | holds |
| RR-PRIV-03 | Error messages leak no other branch's existence | F | holds |
| RR-CLK-01 | Request number month from `ClinicalClock` across 00:00–08:00 WITA on the 1st | F | WITA month |
| RR-OVS-01 | Finance Klinik, Data Analyst and Owner (Owner holds `view_billing_correction_oversight` by RF-8a, approved 2026-10-31): GET-only; every write route 403/404 | F | holds |
| RR-LIFE-09 | Revoke `APPROVED` by an account that is not a reviewer for the invoice's branch | F | `REFUND_REVIEWER_NOT_AUTHORIZED` |
| RR-LIFE-10 | Approve/reject/cancel lock the request's own obligation; a request whose obligation id differs from the invoice's open obligation is refused | U/F | `REFUND_OBLIGATION_CHANGED` |
| RR-OVS-02 | Out-of-scope oversight detail | F | 404 |
| RR-OVS-03 | KPI Omzet and piutang unchanged by `APPROVED` requests | F | holds |
| RR-OVS-04 | Owner after the RF-8a grant: CSV, list print, per-correction print and PDF routes refused (no `export_oversight_data`); every refund write route refused; Owner holds no maker, reviewer, settlement, pricing or invoice-correction permission | F | 403 / 404; permission set equals the seeder plus the one grant |
| RR-OVS-05 | "Disetujui — belum dibayar" shown apart from revenue, receivables, cash and bank figures; attribution = source invoice branch [RF-8] | F | holds |

## 3. PostgreSQL 16 obligations

`RR-SOD-04`, `RR-AMT-05`, `RR-REF-03` (backstop), `RR-REF-05`, `RR-REF-07` (backstop), `RR-REF-08`, `RR-REF-11`…`RR-REF-14`, `RR-PAT-06`, `RR-PAT-07`, all `RR-CONC-*`, and the migration
constraint presence test run on PostgreSQL 16. Add the three new tables to every race-suite cleanup list that
already names `trx_rme_overpayment_obligations` or `trx_rme_payments`.

## 4. Mutation targets (minimum)

Remove each and require at least one failing test: the maker ≠ checker comparison; the Front Office
maker-role check; the reviewer role + branch-scope check (each of Super Admin, Supervisor RME, Kepala
Cabang); the Owner reference requirement at submit; the case-folding of the reference comparison; the cross-engine
(correction ↔ refund) reference check in each direction; the registry insert; the patient reference requirement and its per-obligation scope check; the pilot identity check; the declared-amount comparison; the snapshot hash comparison;
the dispute check; the flag guard; the lock on the obligation; the `STALE` commit. Revert by file copy,
never `git checkout --`.
