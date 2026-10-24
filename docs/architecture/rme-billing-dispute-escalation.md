# RME billing dispute & escalation (OPS-OQ-1, rule 203; OPS-OQ-1a/1b, rule 204)

**Owner policy:** OPS-OQ-1, approved for **all branches** — an RME invoice known to
be wrong is not collected at that amount, is not modified unilaterally, is escalated
to the responsible branch authority, stays financially unresolved until a legitimate
authorized resolution, is never marked PAID/CANCELLED/Rp0 merely because a dispute
exists, and the patient is never kept waiting for the invoice-correction pilot.

**Not in scope:** expanding the invoice-correction pilot (stays SPN4, maker 29,
checker 1), D21c/D21g/D22 (PENDING), D28 (unchanged), refund, payment reversal,
patient credit, Rp0 adjustment, post-payment correction.

## 1. Concepts

| Concept | What it is | What it is not |
|---|---|---|
| **Billing dispute** (`trx_rme_billing_disputes`) | A report that an issued invoice is known or suspected to be wrong, with a server-taken snapshot of the invoice at report time and an append-only timeline. | A correction. It changes no item, total, discount, status, version or payment. |
| **Payment hold** | While a dispute is active, every payment path refuses the invoice. | A payment block that can be switched off, or one that a correction decision releases. |
| **Correction** (rule 195–202, SPN4 only) | The maker/checker mechanism that changes an UNPAID invoice. | Something a dispute can trigger, widen or bypass. |
| **Resolution** | The only release of the hold, with an outcome the service verifies. | An override, waiver, or "close without evidence". |

## 2. State machine

```mermaid
stateDiagram-v2
    [*] --> OPEN: cashier reports (manage_rme_billing)
    OPEN --> UNDER_REVIEW: branch authority starts review
    OPEN --> ESCALATED: escalate to Owner (reason required)
    UNDER_REVIEW --> ESCALATED: escalate to Owner (reason required)
    OPEN --> RESOLVED: verified outcome
    UNDER_REVIEW --> RESOLVED: verified outcome
    ESCALATED --> RESOLVED: verified outcome
    RESOLVED --> [*]
    note right of OPEN
        OPEN, UNDER_REVIEW, ESCALATED = ACTIVE
        payment held on every path
    end note
```

The map lives in `RmeBillingDisputeStatus::TRANSITIONS`; RESOLVED is terminal and
enforced by the model. Notes can be added while active (reporter and reviewer); a
note is not a transition.

## 3. Payment hold — every path

```mermaid
flowchart TD
    A[Cashier submits payment] --> B[CreateRmePaymentRequest<br/>early courtesy check]
    B -->|disputed| R1[422 BILLING_DISPUTE_ACTIVE<br/>+ deduped audit]
    B -->|clean| C[RmePaymentService<br/>pay / allocateVisitPayment / allocateControlPayment]
    C --> D[lock every invoice the batch settles<br/>FOR UPDATE]
    D --> E{assertNoPaymentHold per invoice<br/>D10 correction + active dispute}
    E -->|any invoice held| F[whole batch rolled back<br/>no money redirected, nothing omitted]
    F --> G[audit RME_PAYMENT_REFUSED_ACTIVE_DISPUTE<br/>after rollback, deduped 15 min]
    E -->|none held| H[version binding rule 191<br/>then payment recorded]
```

The decisive check is the one under the lock: a report that committed before the
payment took the invoice lock holds it; one that rolled back does not (proven on
PostgreSQL, races B/C/J).

## 4. Authority

| Permission | Holder (as seeded) | May |
|---|---|---|
| `manage_rme_billing` | Kasir, Front Office, Admin Klinik… (existing) | report, add notes |
| `review_rme_billing_disputes` | **no operational role**; Super Admin via `'*'` | start review, escalate, resolve |
| `view_rme_billing_disputes` | Owner | read only |

Every action re-reads the persisted account under the invoice row lock
(`RmeBillingActorGuard::resolveUsable`) and the branch scope
(`RmeWorkingBranchScope`, server-resolved). Out-of-scope reads are 404. Which
branch-level account should review was **OPS-OQ-1a** — answered 2026-10-24 by an
explicit per-account, per-branch designation (§9).

## 5. Resolution evidence

| Outcome | Verified by the service | Self-resolution |
|---|---|---|
| `CORRECTION_APPLIED` | the latest APPLIED correction produced the invoice's current version, and that version is newer than the disputed one; reference = correction number (server-filled) | allowed — the correction itself passed maker ≠ checker |
| `ORIGINAL_CONFIRMED_CORRECT` | invoice version unchanged since the report; reference recorded (6–120 chars) | **refused** (`SELF_RESOLUTION_FORBIDDEN`), Super Admin included |

No outcome while a correction is in flight (`BILLING_DISPUTE_CORRECTION_ACTIVE`).
Outside SPN4 an invoice whose original is genuinely wrong has no correction path:
the dispute stays ESCALATED and the invoice stays unresolved — the policy, not a gap
filled by an invented override.

## 6. Concurrency — lock order and linearization

Lock order everywhere: **invoice row → dispute row** (payments and corrections lock
the invoice first too), so the feature introduces no deadlock cycle.

| Race | Order | Result |
|---|---|---|
| A | report ∥ report | one dispute; loser `BILLING_DISPUTE_DUPLICATE` (lock + partial unique index) |
| B | report commits ∥ payment | payment held, 0 payments, 1 audit row |
| C | report rolls back ∥ payment | payment accepted, 0 disputes |
| D | payment commits ∥ report | dispute snapshots PARTIAL / paid 100.000 |
| E | resolution commits ∥ payment | payment accepted |
| F | resolution rolls back ∥ payment | payment held |
| G | correction submitted ∥ resolution | `BILLING_DISPUTE_CORRECTION_ACTIVE` |
| H | resolution ∥ resolution | one resolution; loser `BILLING_DISPUTE_INVALID_TRANSITION` |
| I | deactivation commits ∥ report | `ACTOR_UNUSABLE`, nothing written, audited |
| J | report ∥ visit payment selecting that receivable | whole batch held, 0 payments on both |

Linearization points: the active-dispute read of a payment (under the invoice lock),
and the actor re-read of a dispute action (after the invoice lock).

## 7. Notifications

In-app (`database` channel) only, after commit: report → review holders reaching
the branch; escalation → review + view holders. Recipients are filtered to usable
accounts in scope, never the actor. No recipient → `RME_BILLING_DISPUTE_ESCALATION_UNROUTED`.
Delivery failure → `RME_BILLING_DISPUTE_NOTIFICATION_FAILED`. Neither unwinds the
dispute or its hold. Messages carry the dispute and invoice numbers only.

## 8. Operations

- `php artisan rme:billing-dispute-status [--json] [--strict]` — run as the runtime
  user, never root. FAIL when an active hold sits in a branch no reviewer can reach;
  WATCH for a branch without a reviewer or an active dispute older than 7 days.
- Rollback: safe only while the status command reports 0 active disputes; otherwise
  forward-fix — the previous runtime cannot honour a hold.

## 9. Branch reviewer designation (OPS-OQ-1a, rule 204)

`mst_rme_billing_dispute_reviewers` — one row = one ACCOUNT designated for one
BRANCH. Columns: `user_id`, `branch_id`, `granted_by`, `granted_at`,
`grant_reason`, `revoked_by`, `revoked_at`, `revoke_reason`. Database guarantees:
a partial unique index (one active row per account and branch), a CHECK that the
revocation triple is all-null or all-set, a CHECK `user_id <> granted_by`, and
`RESTRICT` foreign keys. The model refuses any update to a revoked row, any update
outside the revocation columns, and every delete.

| Concern | Rule |
|---|---|
| Who designates | `manage_rme_billing_dispute_reviewers` — no operational role; Super Admin via `'*'` |
| Eligible designee | usable account, RME workspace access, not Owner, not a counter role (Front Office, Kasir), not already holding `review_rme_billing_disputes`, not the governor itself — `isEligibleDesignee()` is checked at the grant AND on every use (policy, service, routing) |
| Independence | a designee may not confirm "original correct" on an invoice it issued (`cashier_id`), collected on, or requested / decided / applied a correction of (`RESOLVER_NOT_INDEPENDENT`); Super Admin's rule-203 path unchanged |
| Authority granted | review / note / escalate / resolve on THAT branch's disputes only |
| Also required on every action | usable persisted account + working scope reaching the branch (`RmeWorkingBranchScope`) |
| Never granted | invoice edit, correction maker/checker, refund, debt forgiveness, cancellation, manual PAID, guard override |
| Routing | the escalation service adds active designees of the branch to the in-app recipients |

Policy doors (`viewAny`, `noteAny`, `reviewAny`) are permission **or** an active
designation; the per-record abilities additionally require branch scope and, for a
designee, a designation for that record's branch. The service re-asserts everything
under the locks:

```
dispute action:  lock INVOICE → lock DISPUTE → read DESIGNATION FOR SHARE
revocation:      lock DESIGNATION FOR UPDATE   (no other lock → no cycle)
```

A revocation that commits first makes the designation invisible to the waiting
action; an action that holds the share lock first commits before the revocation.

## 10. Kontrol-only payment (OPS-OQ-1b, rule 204)

`RmePaymentService::payControlInvoiceOnly()` — reached only by the explicit
`payment_scope=control_only` + `confirm_control_only` form on the Kontrol invoice's
payment page (a separate form; the ordinary button is disabled while the exception
applies).

```
lock KONTROL invoice B            (same first lock as allocateControlPayment)
  assertInvoicePayable(B, cashier)   usable cashier, billing permission, B's branch
  assertFormVersion(B)               rule 191, PATH_CONTROL_ONLY
  assertNoPaymentHold(B)             D10 + a dispute on B itself
re-derive the Kontrol chain; lock each origin A FOR UPDATE   (same order as the ordinary path)
  outstanding = payable ∧ same branch ∧ remaining > 0
  eligible    = outstanding ≠ ∅ ∧ every outstanding origin has an ACTIVE dispute
amount ≤ remaining(B)
record ONE payment on B; audit RME_PAYMENT_CONTROL_ONLY_ORIGIN_DISPUTED (allocated_to_origin = 0)
refresh B; complete the Kontrol visit when B is settled
```

The origin is locked but never written: its status, totals, version, payments and
dispute are unchanged. The ordinary `allocateControlPayment()` and every visit batch
that would settle a disputed origin keep the rule-203 whole-batch refusal.

| Refusal | Code / message |
|---|---|
| no outstanding origin | `CONTROL_ONLY_PAYMENT_NOT_APPLICABLE` |
| an outstanding origin is not disputed (mixed chain) | `CONTROL_ONLY_PAYMENT_ORIGIN_NOT_DISPUTED` |
| B is disputed | `BILLING_DISPUTE_ACTIVE` |
| B has an active correction (D10) | the existing D10 message |
| stale B version | `INVOICE_VERSION_CHANGED` |
| cashier unusable / other branch / no permission | the existing payment-actor messages |
| amount above B's remaining | `amount` error — the origin is not collectable here |

Concurrency is proven by `RmeBillingDisputeBranchReviewerConcurrencyTest` (races A–N
on PostgreSQL 16).
