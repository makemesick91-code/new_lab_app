# RME Refund Request & Approval — Security Threat Model (Sprint 4/7)

> **Status: PROPOSED** for a design that is not implemented. Each threat names the control the
> implementation must carry and the test that proves it (`docs/architecture/rme-refund-request-approval-tdd-matrix.md`).
> Design: `docs/architecture/rme-refund-request-approval-design.md`.

> **Update 2026-10-31:** implemented by `FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1` (rule 215) — every control named here is implemented and tested, and an independent security review found no CRITICAL or HIGH issue. It ships with the flag `rme.billing_refund_request` OFF and an EMPTY pilot identity allowlist: built, not activated. Where this document reads as future or proposed, it records the state before implementation; decisions taken while implementing are in `docs/sprints/feature-rme-billing-refund-request-approval-1.md` §3–§4b.

## 1. Assets and trust boundaries

| Asset | Why it matters |
|---|---|
| Money owed back to a patient (OPEN obligation) | Approving a refund is the authorisation for money to leave the clinic in Sprint 5 |
| The append-only payment ledger | Every balance, KPI and obligation is derived from it (AIR-RMB-06) |
| The obligation row | Immutable proof of what is owed; produced only by an approved, applied correction |
| Maker/checker separation | The only control between one account and an approved payout |
| Patient identity | NIK, name, RM number, phone; bank details in Sprint 5 |

Boundaries: browser → route middleware → FormRequest/policy → **service (authority)** → repository →
PostgreSQL. Anything above the service is a courtesy. `Gate::before` makes every policy answer "yes" for
Super Admin, so no control that must bind Super Admin may live only in a policy or middleware.

## 2. Threats (STRIDE-oriented)

| ID | Threat | Category | Control | Test |
|---|---|---|---|---|
| T-01 | Double click, browser retry or resubmitted form files two requests | Tampering | refund intent (HMAC, obligation-bound) + UNIQUE `intent_key`; exact repeat returns the original; partial UNIQUE one active request per obligation | RR-IDEM-01…04 |
| T-02 | Same account files and approves | Elevation | service check `requested_by ≠ actor` under the locks, **Super Admin included**; DB CHECK backstop | RR-SOD-01…04 |
| T-03 | Super Admin files or approves anything through `Gate::before` | Elevation | `can()` necessary, not sufficient; the service checks the maker role (Front Office — Super Admin is never a maker) and the reviewer role + branch scope; Super Admin reviews all active RME branches only because the owner granted it (RF-1, 2026-10-31), never its own request | RR-SOD-03, RR-SOD-05, RR-BR-05, RR-ROLE-03 |
| T-04 | Cross-branch IDOR: acting on another branch's obligation by id | Information disclosure / Elevation | branch derived from the locked obligation; `RmeWorkingBranchScope`; per-branch authority; out of scope answers **404** | RR-BR-01…05 |
| T-05 | Multi-branch account approves outside its scope | Elevation | authority is the owner-approved role and its server-resolved branch scope, never "can see the branch" and never a request `branch_id` | RR-BR-04, RR-ROLE-07, RR-ROLE-09, RR-ROLE-10 |
| T-06 | Amount tampering (larger, smaller, rounding) | Tampering | amount is server-derived = obligation amount; declared amount/version mismatch refuses; no input field for amount [RF-4 A] | RR-AMT-01…05 |
| T-07 | Stale view (TOCTOU): obligation or invoice changed after the form rendered | Tampering | declarations compared to locked rows; snapshot hash recomputed at approval; mismatch → `STALE` committed + refusal | RR-STALE-01…04 |
| T-08 | Refund against a non-existent, foreign or closed obligation | Tampering | lookup by invoice under lock; status must be `OPEN`; mismatch → 404 / `REFUND_OBLIGATION_NOT_OPEN` | RR-OBL-01…04 |
| T-09 | Refund against a payment directly (reversal disguised as refund) | Repudiation / Tampering | the only accepted subject is an OPEN obligation; no field accepts a payment id [RF-13] | RR-OBL-05 |
| T-10 | Replaying a rejected/cancelled request | Tampering | terminal states never return to `REQUESTED`; an exact replay returns the original (rejected) request and writes nothing; altered content → `REFUND_INTENT_REUSED`; a new request needs a new intent | RR-IDEM-05 |
| T-11 | Approval without, or with a reused, Owner reference — **one Owner approval authorising two outflows** (a correction on one invoice and a refund on another, or two refunds; also `REF-1` vs `ref-1`) | Elevation | required, format-checked (≤ 12 digits per run); compared **case-folded** against every refund and every post-payment correction, in **both** directions; `lower()` partial UNIQUE on refunds; shared consumed-reference registry, folded key UNIQUE, one scope per reference (invoice for a correction — preserving PP-2 same-invoice resubmission — or refund request), consumed with `ON CONFLICT DO NOTHING` + scope check (migration plan §2a); DB CHECK for presence | RR-REF-01…16 |
| T-12 | Unauthorized settlement / money movement in Sprint 4 | Elevation | no settlement route, permission, method or state exists; structural test over the route table and service | RR-SEC-11 |
| T-13 | Historical payment, invoice or obligation modified | Tampering | Sprint 4 writes none of them; model guards; ledger fingerprint identical before/after every lifecycle | RR-LEDGER-01…04 |
| T-14 | Concurrent approvals or approve-vs-cancel race | Tampering | fixed lock order invoice → obligation → request; status re-read under lock; one winner | RR-CONC-01…05 (PostgreSQL 16) |
| T-15 | Refund while the invoice is disputed or a correction is in flight | Tampering | `REFUND_INVOICE_DISPUTED`, `REFUND_CORRECTION_ACTIVE` evaluated under the invoice lock [RF-7] | RR-ELIG-03, RR-ELIG-04 |
| T-16 | Account deactivated or demoted mid-flow | Elevation | `assertActor()` re-read through `ActiveAccountResolver` after the locks (linearization point); never lock `users` | RR-ACT-01, RR-ACT-02 |
| T-17 | Flag OFF bypassed by a direct POST | Elevation | routes 404 when OFF **and** service refuses `REFUND_REQUEST_NOT_ENABLED` | RR-ELIG-01, RR-ELIG-02 |
| T-18 | Mass assignment of `status`, `decided_by`, `owner_approval_reference` | Tampering | not in `$fillable`; repository transitions write an explicit column allowlist | RR-SEC-07 |
| T-19 | PII in audit, events, logs, oversight export | Information disclosure | payload allowlist (ids, cents, codes); notes and references never in payloads; NIK masked; CSV formula-injection safe | RR-PRIV-01…03 |
| T-20 | Payment intent replayed as a refund intent (or vice versa) | Tampering | distinct HMAC context string; token bound to the obligation id | RR-IDEM-06 |
| T-21 | Human separation faked with two accounts held by one person | Repudiation | **not verifiable by software**; never claimed. RF-1a (approved 2026-10-31): no automatic reuse of the Sprint 2 pair; a refund pilot needs a real assigned Front Office maker and an independently identified reviewer with **verified** human separation, else `PILOT_ACTIVATION = NO_GO`; the pilot identity list ships empty | RR-ELIG-07; governance, release gate RG-3 |
| T-22 | Number/date manipulation via clock (month boundary, UTC vs WITA) | Tampering | request number month from `ClinicalClock`; instants stay UTC | RR-CLK-01 |
| T-23 | Abandoned `REQUESTED` rows block refunds forever | Denial of service | maker withdraw, checker reject; no invented auto-expiry (PP-7); visible in the queue | RR-LIFE-04 |
| T-24 | Dependency on the application key | Availability | the intent HMAC uses the application key, so rotating it (G7-B) invalidates in-flight forms only — they are re-rendered; Sprint 4 stores **no** value encrypted with the key, so no re-encryption scope is added | RR-IDEM-08 |
| T-25 | Pre-existing finding F-17: the post-payment correction's Owner-reference non-reuse rule (PP-2) needs a database-backed guarantee (analysis kept in a private security record, not in this public repository) | Elevation | scope-keyed registry (migration plan §2a), shipped first as `HARDENING-RME-OWNER-REFERENCE-REGISTRY-1` before any post-payment activation; refunds reuse it | RR-REF-08, RR-REF-10…RR-REF-14 (PostgreSQL) |
| T-26 | A maker obtained through an inherited or wide permission (Kasir, Admin Klinik, Supervisor RME, Super Admin) | Elevation | `request_rme_refund` granted to Front Office only, never derived from `manage_rme_billing`; the service checks the Front Office role and its working branch | RR-ROLE-01…05 |
| T-27 | Kepala Cabang over-granted (broad RME access through the refund grant) or reviewing another branch | Elevation | only `approve_rme_refund` + a narrow review page; branch = its own managed branch, server-resolved | RR-ROLE-06…08 |
| T-28 | One patient request form used to back refunds on two obligations (two patients' money behind one request), also via a case variant or a race | Tampering | patient reference mandatory [RF-5 = B]; consumed in its own registry, folded key UNIQUE, scope = one obligation, `ON CONFLICT DO NOTHING` + scope check, taken after the Owner-reference key in a fixed order | RR-PAT-01…07 |
| T-29 | Owner granted more than read oversight (export, write, refund roles) when RF-8a is applied | Elevation / Information disclosure | only `view_billing_correction_oversight`; production grants diffed against `RoleSeeder` before seeding; export routes keep their separate permission | RR-OVS-01, RR-OVS-04 |

## 3. Residual risks (accepted only by the owner)

* **Human separation** of maker and checker cannot be proven (T-21).
* **Dispute deadlock**: a dispute **filed after** an obligation opened can only resolve as
  `ORIGINAL_CONFIRMED_CORRECT` until the obligation closes, because PP-3 refuses every further correction
  (RF-7). A dispute filed **before** the correction that produced the obligation can still resolve as
  `CORRECTION_APPLIED` (the correction engine does not check disputes, and the resolution accepts an
  applied correction whose version is newer than the reported one). Refund refuses while any dispute is
  active, so money cannot leave on a contested amount; the cost is that the patient waits.
* **Separator variants of an Owner reference** (`OWN-001` vs `OWN_001`) are distinct registry keys; only
  case and surrounding whitespace are folded. Canonicalizing separators would make unrelated references
  collide. Control: the owner issues references from one sequence.
* **No payment-amount CHECK** on `trx_rme_payments` (application-enforced only). Not exploitable through
  any route; recommended as separate hardening (design §14).
* **Obligation number month in UTC** (design §14): cosmetic numbering drift at a month boundary between
  00:00 and 08:00 WITA; no financial effect.

## 4. G7-B isolation

This design changes nothing in the application key, the environment file, its copies, custody media,
SSH configuration, or the re-encryption command, and adds no encrypted column. Billing Correction does
not alter G7-B status.
