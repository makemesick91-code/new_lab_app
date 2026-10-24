# IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1

**Type:** FOUNDATION_SPRINT (audit level 3) · **Rule:** 203 · **Branch:**
`feature/rme-billing-dispute-escalation-ops-oq-1` · **Base:**
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @
`712ee0b2` (= production `b3ec7133` + three docs-only commits) · **GO tag (scoped):**
`implementation-rme-billing-dispute-escalation-ops-oq-1-go`

## 1. Status

| Item | Status |
|---|---|
| Owner policy OPS-OQ-1 | `OWNER_POLICY_APPROVED` (all branches), `REPOSITORY_POLICY_RECORDED` |
| Technical enforcement | `IMPLEMENTED` — becomes `TECHNICAL_ENFORCEMENT_DEPLOYED` only after production deploy + verification (§35) |
| Invoice-correction pilot | unchanged: `PILOT_ACTIVE`, SPN4, maker 29 / checker 1 — **FULL_INVOICE_CORRECTION_PILOT_GO: NOT_CLAIMED** |
| Open follow-ups | **OPS-OQ-1a** — which branch-level account reviews/resolves disputes; **OPS-OQ-1b** — may a control invoice be collected while its carry-over parent is disputed |

## 2. Owner policy (verbatim in substance)

1. Do not collect the amount known to be incorrect.
2. Do not modify the invoice unilaterally.
3. Escalate the dispute to the responsible branch authority.
4. Keep the invoice financially unresolved until a legitimate authorized resolution.
5. Do not mark it PAID, CANCELLED, or Rp0 merely because a dispute exists.
6. Do not keep the patient waiting merely to complete the Invoice Correction pilot.

Recorded as stated in the sprint brief received 2026-10-23. No separate signed
artefact, approval reference or timestamp exists; none was invented.

## 3. Scope and non-scope

**In:** dispute record + state machine, reporting, duplicate prevention, payment
hold on every path, in-app escalation, branch-authority workflow, verified
resolution, operator UI, status command, rules/docs.
**Out (unchanged):** correction pilot expansion, D21c/D21g/D22, refund, payment
reversal, patient credit, Rp0 adjustment, post-payment correction, any external
notification channel.

## 4. Baseline audit (read-only production, `default_transaction_read_only=on`)

- HEAD `b3ec7133`, tree `e7babf15`, tag `fix-rme-invoice-correction-zero-original-guard-1-go`.
- Invoices: PAID 5 (Rp 3.548.000), PARTIAL 1 (Rp 4.000.000), UNPAID 1 (Rp0 — the D28 case).
- Payments 8, sum Rp 5.548.000. Corrections 0. Document versions 0.
- `trx_rme_billing_disputes` absent. Permission `manage_rme_billing` present; no dispute permission.
- RME branches: ATG3, LDK2, SPN4, TLK1. Audit max id 1623.
- Fingerprints: invoices `8b5c43ae9c92c86c8233655ebef698d8`, payments `fb1e137ff249b59a2e82953f84dfb46e`.

## 5. Decisions preserved

D1, D1a, D1c, D1e, D7, D10, D21 (manual pricing), D28, OD-1, OD-1a — unchanged.
D21c, D21g, D22 — PENDING. Pinned by `RmeBillingDisputeCorrectionCompatibilityTest`
(pilot config shipped as SPN4 / [29] / [1]; D28 still refuses a disputed Rp0 invoice;
non-pilot branch still refused correction; maker ≠ checker preserved).

## 6. Dispute ≠ correction

A dispute writes only its own rows. Pinned by `rbdLedger()`: invoices, items,
payments, corrections, document versions, visits and medical records are
byte-identical across report, review, note, escalation and resolution.

## 7. State machine

`OPEN → UNDER_REVIEW → ESCALATED → RESOLVED`; OPEN may escalate or resolve directly;
RESOLVED terminal. Every illegal transition refused with
`BILLING_DISPUTE_INVALID_TRANSITION`; terminal/immutability also enforced in the
model (`LogicException`).

## 8. Reporting

`POST rme/cashier/{visit}/billing/{invoice}/disputes` (`manage_rme_billing`). Reason
category from a closed list; description 10–1000 chars; any 13+ digit run (NIK)
refused. Reportable: UNPAID, PARTIAL, PAID. DRAFT/VOID refused
(`BILLING_DISPUTE_INVOICE_STATUS_UNSUPPORTED`). Snapshot taken under the invoice lock.

## 9. Duplicate prevention

Locked check + partial unique index `trx_rme_billing_dispute_one_active_uq`; a
unique violation from a racing report is translated to `BILLING_DISPUTE_DUPLICATE`.
A resolved dispute does not block a new report.

## 10. Payment blocking — every path

All five call sites in `RmePaymentService` (pay, visit allocation current + selected
receivables, control allocation parent + control invoice) call
`assertNoPaymentHold()`; the FormRequest adds an early courtesy refusal. Direct
service calls are covered (tests call the service, not only HTTP).

## 11. Whole-batch atomicity

A held invoice anywhere in a batch rolls the whole batch back; nothing is redirected
or omitted. Consequence documented: a disputed carry-over parent holds the forced
control payment.

## 12. No financial mutation by the dispute

See §6. No status PAID/CANCELLED/VOID/Rp0 is ever written by the dispute module.

## 13. Branch escalation

Report → in-app notification to every usable `review_rme_billing_disputes` holder
whose server-resolved scope reaches the branch (actor excluded). No holder →
`RME_BILLING_DISPUTE_ESCALATION_UNROUTED` (dispute and hold stand).

## 14. Notifications

`database` channel only; after commit; message carries dispute + invoice numbers
only; internal relative URL. Delivery failure → `RME_BILLING_DISPUTE_NOTIFICATION_FAILED`.
No WhatsApp, no e-mail.

## 15. Branch authority workflow

Start review (optional note), escalate (reason required), add notes, resolve.
No financial power: the reviewer cannot change the invoice, create a correction or
record a payment from the dispute.

## 16. Owner case-specific approval

Never fabricated. `ORIGINAL_CONFIRMED_CORRECT` requires a typed reference; the code
does not verify an Owner signature and does not pretend to.

## 17. Correction pilot compatibility

Pilot unchanged (SPN4, 29/1). A dispute grants no correction power; the reviewer
holds no correction permission; correction outside SPN4 still refused.

## 18. Correction state interactions

Hold survives REJECTED / CANCELLED / APPLIED corrections; D10 still holds when a
resolve is refused over an active correction; a dispute can be filed while a
correction is SUBMITTED; resolution refused while one is in flight.

## 19. Resolution contract

`CORRECTION_APPLIED` — system-verified (latest APPLIED correction produced the
current version, newer than the disputed one; reference/correction id server-filled).
`ORIGINAL_CONFIRMED_CORRECT` — invoice unchanged, reference required, resolver ≠
reporter. No other outcome; no override.

## 20. Closure authority (conservative, fail-closed)

`review_rme_billing_disputes` granted to no operational role; Super Admin only.
Missing policy recorded as **OPS-OQ-1a**. Outside SPN4 a genuinely wrong original
stays ESCALATED until the owner decides — policy, not an omission.

## 21. Patient not held

Reporting changes no visit status (pinned). The SOP and incident matrix row Q say
the patient may leave; an existing correction need not be cancelled.

## 22. PARTIAL / PAID

Reportable; no reversal, no refund; further collection held.

## 23. D28

A disputed Rp0 original is still refused correction (`CORRECTION_ORIGINAL_TOTAL_ZERO`).

## 24. Audit trail

`RME_BILLING_DISPUTE_REPORTED / REVIEW_STARTED / ESCALATED / NOTE_ADDED / RESOLVED /
REFUSED / ESCALATION_UNROUTED / NOTIFICATION_FAILED`, `RME_PAYMENT_REFUSED_ACTIVE_DISPUTE`.
Ids, numbers, statuses, codes and lengths only — never free text, patient, NIK.
Denials audited after rollback; payment refusals de-duplicated.

## 25. TDD evidence

RED first: payment hold 17 failed / 4 passed (payment accepted on a disputed
invoice). GREEN 21/21. Governance RED 4 failed (register, rule, operator docs) then
GREEN 8/8. The first RED attempt failed for the wrong reason (missing route inside
notification dispatch) and exposed a real defect: notification failure could break
reporting — fixed by isolating dispatch.

## 26. Test matrix

| Suite | Tests |
|---|---|
| `RmeBillingDisputePaymentHoldTest` | 21 |
| `RmeBillingDisputeLifecycleTest` | 26 |
| `RmeBillingDisputeAccessTest` | 12 |
| `RmeBillingDisputeCorrectionCompatibilityTest` | 8 |
| `RmeBillingDisputeGovernanceTest` | 10 |
| `RmeBillingDisputeConcurrencyTest` (PostgreSQL) | 10 |
| **Total** | **87** — SQLite 77 passed + 10 skipped (PostgreSQL-only); PostgreSQL 16.15 **87 passed / 482 assertions** |

## 27. PostgreSQL concurrency (16.15, real services in a child process)

Races A–J (see architecture doc §6) all pass, each with a measured lock wait > 1 s;
re-run after the security fixes with the whole directory: 87 passed / 482 assertions.
The first run caught a fixture defect (pilot identity allowlist fail-closed with an
empty checker list). Linearization points: the active-dispute read of a payment
(under the invoice lock) and the actor re-read of a dispute action (after it).

## 28. Mutation testing

Copy-based harness (never `git checkout`); each mutant must change the file or it is
reported NOT-APPLIED; restore verified by sha256. **25 attempted (23 single + 2
compound) — KILLED 23, SURVIVED 0 (real), EQUIVALENT 2, NOT-APPLIED 0.**

| Mutant | Result |
|---|---|
| M01 hold check no-op · M02 visit-receivable call site · M03 control-parent call site · M04 ESCALATED not active | KILLED |
| M06 self-confirm · M07 invoice-changed check · M08 pre-report correction accepted · M09 in-flight correction ignored | KILLED |
| M10 branch scope · M11 stale actor trusted · M12 routing failure escapes · M14 refusal audit not de-duplicated | KILLED |
| M15 policy view scope · M17 FormRequest early check · M18 snapshot mutable · M19 RESOLVED not terminal | KILLED |
| M20 escalation without reason · M21 NIK in free text · M22 message built outside guard (finding A) · M23 rolled-back reach removed (finding B) | KILLED |
| M13 escalation notifies its own actor | first **SURVIVED** — a real test gap; test added (`never notifies the reviewer of their own escalation`) → KILLED |
| M05 locked duplicate check removed | **EQUIVALENT** — the partial unique index refuses the duplicate and is translated to the same code; compound **M05b** (check + translation removed) KILLED |
| M16 FormRequest visit/invoice pairing removed | **EQUIVALENT** — the controller's `assertReportable()` re-checks it; compound **M16b** (both removed) KILLED |

## 29. Security review

Independent, read-only review (no tests, no database access) of the full change set.
**Result: no CRITICAL, no HIGH, no MEDIUM.** Verified clean: exactly one writer of
`trx_rme_payments` (`RmePaymentService::recordPayment`), reached only through
`settleUnderVersionBinding`, and every one of its five callers locks the invoice row
and calls `assertNoPaymentHold()` before writing; lock order invoice → dispute matches
the correction service; out-of-scope reads 404; no request field trusted; actor
re-read under the lock (Super Admin's `Gate::before` cannot skip it); outcomes cannot
be forged; no `{!! !!}`; all queries bound; audit/log/notification payloads PII-free.

| # | Severity | Finding | Disposition |
|---|---|---|---|
| A | LOW | The invoice-number lookup that builds the notification message ran outside the dispatch guard; a DB blip there would answer 500 after the dispute committed (operator might retry into "duplicate"). | **Fixed** — the message is built inside the guard. RED test first (`keeps the dispute and answers normally when building the notification itself fails`), now GREEN; mutant M22 pins it. |
| B | LOW | Deciding whether ANOTHER account reaches a branch walks the canonical working-branch scope, which expires a stale online context — a write to another account's row from the "read-only" status command, the detail page and notification routing. Unreachable today (only Super Admin reviews) but reachable once OPS-OQ-1a grants review to a context-bound role. | **Fixed** — reach is evaluated inside an always-rolled-back transaction. The canonical scope still *issues* its update; nothing persists. Two RED tests first (both genuinely red after a vacuity fix in the first), now GREEN; mutant M23 pins it. |
| C | LOW (availability) | A disputed carry-over parent holds the forced control payment, so the follow-up visit's own invoice cannot be collected until the dispute is resolved. | **Recorded, not changed** — open owner question **OPS-OQ-1b**. The patient is not physically held. |

Accepted by design: any `manage_rme_billing` holder in scope can place a hold; only
Super Admin can lift it while OPS-OQ-1a is open.

## 30. UI and stable error codes

Cashier invoice page: "Ketidaksesuaian Tagihan" card + report button; payment page
and invoice page show the hold and disable submission. Codes:
`BILLING_DISPUTE_ACTIVE`, `BILLING_DISPUTE_DUPLICATE`,
`BILLING_DISPUTE_INVOICE_STATUS_UNSUPPORTED`, `BILLING_DISPUTE_BRANCH_NOT_RME`,
`BILLING_DISPUTE_INVALID_TRANSITION`, `BILLING_DISPUTE_NOT_FOUND`,
`BILLING_DISPUTE_CORRECTION_ACTIVE`, `BILLING_DISPUTE_RESOLUTION_EVIDENCE_MISSING`,
`BILLING_DISPUTE_INVOICE_CHANGED`; denials `ACTOR_UNUSABLE`, `PERMISSION_MISSING`,
`BRANCH_OUT_OF_SCOPE`, `SELF_RESOLUTION_FORBIDDEN`.

## 31. Observability

`rme:billing-dispute-status [--json] [--strict]` — read-only, PII-free, refuses root.

## 32. Migration

`2026_10_24_100001_create_trx_rme_billing_disputes_table` — additive; partial unique
index; PostgreSQL CHECKs (status, reason, outcome, resolution consistency, totals).
No backfill; no dispute inferred from an existing invoice.

## 33. Financial regression

SQLite, one process, JUnit-counted: `tests/Feature/RmeInvoiceCorrection` + `tests/Feature/RME`
+ `tests/Feature/AccessControl` + `tests/Feature/Auth` + `tests/Feature/RmeBillingDispute`
— **2617 tests, 0 failures, 0 errors, 43 skipped** (PostgreSQL-only and GD guards).
Covers every payment path, the correction pilot, D10, version binding, explicit
actors, receivable carry-over, branch scope and role pins. Full Suite: governed by the
global temporary Full-Suite policy (not run locally; CI result recorded in §35).

## 34. Rules sync

New rule 203. Rule 202 frontmatter, §16, §19 updated. Register: chronology rows +
OPS-OQ-1 policy section + OPS-OQ-1a. Operator docs (SOP SPN4 §8 + §9.1, maker-checker
guide, incident matrix A/H/I/Q + new S/T, handoff) point at rule 203. New
`docs/operations/rme-billing-dispute-sop.md`, architecture doc, CLAUDE.md section.

## 35. DEVFLOW, CI, merge, deploy, production

See §35a (filled from measured evidence only).

## 36. Residuals and rollback

- OPS-OQ-1a open: no branch-level reviewer exists; only Super Admin can resolve.
- Outside SPN4 there is no correction path; genuinely wrong originals stay ESCALATED.
- A disputed carry-over parent holds the forced control payment (open owner question
  **OPS-OQ-1b**; behaviour unchanged until answered).
- Notification reach depends on review holders; none ⇒ UNROUTED audit (status FAIL).
- Rollback: only while `rme:billing-dispute-status` reports 0 active disputes;
  otherwise forward-fix. The migration's `down()` drops the tables and must not be
  run while any dispute exists.
