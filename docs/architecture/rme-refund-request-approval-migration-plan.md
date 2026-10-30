# RME Refund Request & Approval — Database Migration Plan (Sprint 4/7)

> **Status: PLAN. No migration exists.** Do not create these migrations until the owner has answered
> RF-1…RF-8 (`docs/decisions/rme-refund-request-approval-owner-decision-package.md`). Columns marked
> **[RF-n]** change with that decision. Design: `docs/architecture/rme-refund-request-approval-design.md`.

## 1. Principles (CG-09, AIR-RMB-41)

* Additive only. Production runs `php artisan migrate --force` through the deploy runner after its
  backup; destructive schema commands are never run on the VPS (-- never).
* No backfill: there are no refund rows to migrate, and production holds 0 obligations.
* No column on an existing table is altered or dropped in Sprint 4. **`trx_rme_overpayment_obligations`
  is not touched** (its status CHECK stays `IN ('OPEN')`; widening it is Sprint 5).
* Database invariants are backstops; the service decides first and refuses with a stable code.
* PostgreSQL-only constraints (CHECK, partial UNIQUE) are created under
  `if (DB::connection()->getDriverName() === 'pgsql')`, as in the obligation migration; the SQLite
  suite cannot prove them, so PostgreSQL 16 tests are mandatory (TDD matrix §3).
* `down()` refuses while any row exists — a refund request is a financial fact.

## 2. Migration 1 — `trx_rme_refund_requests`

| Column | Type | Rule |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | uuid | UNIQUE |
| `request_number` | string(40) nullable | UNIQUE; assigned once after insert, `RMEREF-{Ym ClinicalClock}-{id:06d}` |
| `branch_id` | FK `mst_branches` RESTRICT | **= obligation/invoice branch**; never the requester's |
| `rme_invoice_id` | FK `trx_rme_invoices` RESTRICT | |
| `rme_overpayment_obligation_id` | FK `trx_rme_overpayment_obligations` RESTRICT | |
| `clinic_visit_id` | FK `trx_clinic_visits` RESTRICT | denormalised from the obligation |
| `patient_id` | FK `mst_patients` RESTRICT | denormalised; **register in `PatientOwnedRecordRegistry`** |
| `amount` | decimal(15,2) | server-derived = obligation amount [RF-4] |
| `obligation_amount_snapshot` | decimal(15,2) | obligation amount when filed |
| `invoice_version` | unsigned int | invoice `correction_version` when filed |
| `request_snapshot` | jsonb | ids, versions, cents only (no identity, no free text) |
| `request_snapshot_sha256` | char(64) | |
| `reason_code` | string(40) | `OVERPAYMENT_AFTER_CORRECTION` (the only basis in Sprint 4) |
| `reason_note` | text | 10–1000 chars; never copied into audit payloads |
| `patient_request_reference` | string(120) nullable | [RF-5]; same format rules as the Owner reference |
| `status` | string(30) | |
| `intent_key` | char(32) | UNIQUE |
| `request_fingerprint` | char(64) | |
| `requested_by` | FK users RESTRICT | |
| `requested_at` | timestamptz | |
| `decided_by` | FK users RESTRICT nullable | |
| `decided_at` | timestamptz nullable | |
| `decision_note` | text nullable | required for `REJECTED` |
| `owner_approval_reference` | string(120) nullable | required for `APPROVED` [RF-1] |
| `cancelled_by` | FK users RESTRICT nullable | |
| `cancelled_at` | timestamptz nullable | |
| `cancel_reason` | text nullable | required for `CANCELLED` |
| `stale_reason_code` | string(60) nullable | required for `STALE` |
| `staled_at` | timestamptz nullable | |
| `created_at`, `updated_at` | timestamps | **no soft delete** |

Indexes: `(branch_id, status)`, `(status, requested_at)`, `rme_invoice_id`,
`rme_overpayment_obligation_id`, `requested_by`.

PostgreSQL constraints:

```sql
CHECK (status IN ('REQUESTED','APPROVED','REJECTED','CANCELLED','STALE'))
CHECK (amount > 0)
CHECK (amount = obligation_amount_snapshot)                                  -- [RF-4 A]
CHECK (decided_by IS NULL OR decided_by <> requested_by)                     -- maker ≠ checker backstop
CHECK (status NOT IN ('APPROVED','REJECTED') OR (decided_by IS NOT NULL AND decided_at IS NOT NULL))
CHECK (status <> 'APPROVED' OR owner_approval_reference IS NOT NULL)        -- [RF-1 A]
CHECK (status <> 'REJECTED' OR decision_note IS NOT NULL)
CHECK (status <> 'CANCELLED' OR (cancelled_by IS NOT NULL AND cancel_reason IS NOT NULL))
CHECK (status <> 'STALE' OR stale_reason_code IS NOT NULL)
CHECK (intent_key ~ '^[0-9a-f]{32}$')
CHECK (request_fingerprint ~ '^[0-9a-f]{64}$' AND request_snapshot_sha256 ~ '^[0-9a-f]{64}$')
CREATE UNIQUE INDEX trx_rme_refund_req_one_active_uq
  ON trx_rme_refund_requests (rme_overpayment_obligation_id)
  WHERE status IN ('REQUESTED','APPROVED');
CREATE UNIQUE INDEX trx_rme_refund_req_owner_ref_uq
  ON trx_rme_refund_requests (lower(owner_approval_reference))
  WHERE owner_approval_reference IS NOT NULL;                                  -- non-reuse, case-folded [RF-1]
```

The index is on `lower(...)` because the correction engine compares references case-folded; an index on
the raw column would admit `REF-1` and `ref-1` as two approvals.

Cross-table rules (enforced in the service, tested; §2a backstops the first):
the refund Owner reference has never been recorded — case-folded — on any post-payment correction or any
other refund, and a post-payment correction must likewise refuse a reference already recorded on a refund;
`request.branch_id = obligation.branch_id = invoice.branch_id`; the cancelling account of an `APPROVED` row
is not the maker and is on the branch checker list [RF-6]; the patient request reference [RF-5] has not been
used — case-folded — by a request for a **different** obligation (a re-filed request for the same
obligation is the same patient request and may repeat it), which no single index can express, so it has no
database backstop.

## 2a. Migration 1a — shared registry of consumed Owner references (recommended) [RF-1]

The correction engine today refuses a post-payment reference reused on another invoice
(`postPaymentReferenceUsedOnOtherInvoice()`, case-folded), and it scans corrections only. Once refunds
exist, a check that sees only corrections would let one Owner approval authorise a correction on one
invoice and a refund on another — a second outflow of money from one approval. A shared, database-backed
registry closes that for refunds, and it also provides the guarantee required by the separate hardening
item F-17 (analysis kept in a private security record, not in this public repository).

Table `trx_rme_owner_approval_references` (name proposed):

| Column | Type | Rule |
|---|---|---|
| `id` | bigint PK | |
| `reference_folded` | string(120) | **UNIQUE**; CHECK `reference_folded ~ '^[a-z0-9._:/-]{6,120}$'` — the database refuses an unfolded value |
| `scope_type` | string(40) | CHECK IN (`POST_PAYMENT_CORRECTION_INVOICE`, `REFUND_REQUEST`) |
| `scope_id` | bigint | the invoice id (correction scope) or the refund request id (refund scope) |
| `first_consumed_by` | FK users RESTRICT | |
| `first_consumed_at` | timestamptz | |
| `created_at` | timestamp | no `updated_at`; append-only; no soft delete; `down()` refuses with rows |

**Folding.** `RmeInvoiceCorrectionApprovalReference::normalize()` trims and admits only the ASCII pattern
`[A-Za-z0-9._:/-]{6,120}`, so `strtolower()` of the normalized value is the complete fold — no Unicode or
collation case rules are involved, and `REF-1`/`ref-1`/` REF-1 ` all map to one key. Separators are
**not** canonicalized: `OWN-001` and `OWN_001` are different keys, because stripping separators would make
unrelated references collide (`A-12` vs `A1-2`); the owner issuing references from one sequence is the
governance control (residual, threat model §3).

**Scope — why a plain UNIQUE written at every submit is wrong.** PP-2 as enforced today lets the **same
invoice** reuse its reference when a post-payment correction is resubmitted after `STALE`/`REJECTED`
(`RmeInvoiceCorrectionService`, comment at the reference check). A registry that refused every second
occurrence would break that ratified behaviour. The registry therefore binds each reference to one scope:

| Consumer | Scope | Effect |
|---|---|---|
| Post-payment correction submit | (`POST_PAYMENT_CORRECTION_INVOICE`, invoice id) | resubmission on the same invoice is accepted; any other invoice is refused |
| Refund approval | (`REFUND_REQUEST`, refund request id) | a re-filed request after revoke needs a new reference; a correction reference can never approve a refund, and the reverse |

**Consumption algorithm** — one helper used by both engines, inside the consuming transaction, **after all
domain row locks are held**, at most one reference per transaction:

```sql
INSERT INTO trx_rme_owner_approval_references (reference_folded, scope_type, scope_id, first_consumed_by, first_consumed_at, created_at)
VALUES (:folded, :scope_type, :scope_id, :actor, now(), now())
ON CONFLICT (reference_folded) DO NOTHING;
SELECT scope_type, scope_id FROM trx_rme_owner_approval_references WHERE reference_folded = :folded;
-- same (scope_type, scope_id) → accepted; anything else → REUSED, the service throws, the transaction rolls back
```

**Concurrency (PostgreSQL 16, READ COMMITTED — Laravel's default):**

| Case | Outcome |
|---|---|
| Same folded reference, two scopes, concurrent | T2's `INSERT … ON CONFLICT` waits on T1's uncommitted index entry. T1 commits → T2 inserts nothing, its next statement sees T1's row, scopes differ → refused. T1 rolls back → T2's insert proceeds and T2 wins. Exactly one scope ever owns a reference |
| Case variants (`REF-1` / `ref-1`) | same key after folding → same as above |
| Same scope (resubmission), concurrent | already serialized by the invoice row lock taken first; the second sees its own scope → accepted |
| Unique violation | cannot occur — `ON CONFLICT DO NOTHING` never raises, so the transaction is never aborted (`25P02`) and the refusal is an ordinary application exception |
| Deadlock | none: the registry key is the last lock taken, no domain lock is taken after it, and a transaction consumes one reference, so no wait cycle can form |
| Consuming fact rolled back (refusal elsewhere, failure) | the registry row is in the same transaction and rolls back with it — a refused submission never burns the reference |
| Isolation assumption | the `SELECT` must see rows committed after the transaction began; that holds under READ COMMITTED. The helper asserts the isolation level and fails closed otherwise |

Retention: a reference stays consumed for its scope whatever later happens to the correction or refund
(rejected, stale, cancelled) — the same as today's correction check, which scans post-payment corrections of
every status.

Existing data: production holds 0 post-payment corrections (2026-10-31), so no backfill is needed; the
migration must assert that and refuse to run if any post-payment correction carries a reference without a
registry row.

**Sequencing.** The correction half of this registry is the separate hardening
`HARDENING-RME-OWNER-REFERENCE-REGISTRY-1` (finding F-17), to ship **before** any activation of
post-payment correction (task instruction, 2026-10-31). The refund implementation then becomes the second
consumer of the same registry; it does not introduce it. The refund table's `lower()` partial UNIQUE index
stays as an independent backstop among refunds.

The CHECK on `decided_by <> requested_by` is a **backstop**, not the control: the service refuses first
with `REFUND_SELF_APPROVAL_FORBIDDEN`, and the test proves the service refusal for Super Admin before the
database is reached.

## 3. Migration 2 — `trx_rme_refund_request_events` (append-only)

| Column | Type | Rule |
|---|---|---|
| `id` | bigint PK | |
| `rme_refund_request_id` | FK RESTRICT | |
| `from_status` | string(30) nullable | null only for the first event |
| `to_status` | string(30) | |
| `actor_id` | FK users RESTRICT | |
| `occurred_at` | timestamptz | |
| `metadata` | jsonb nullable | ids, cents, codes; never names, NIK, notes, references |
| `created_at` | timestamp | **no `updated_at`** |

Index `(rme_refund_request_id, occurred_at)`. Model refuses update and delete. An optional PostgreSQL
`BEFORE UPDATE OR DELETE` trigger raising an exception would make append-only a database fact; the
codebase has no trigger precedent, so it is offered for review rather than assumed.

## 4. Non-schema changes that must ship with the migrations

| Change | Why |
|---|---|
| `PatientOwnedRecordRegistry`: add `trx_rme_refund_requests` (`patient_id`) | otherwise every patient merge refuses (AIR-RMB-41) |
| PostgreSQL race-suite cleanup lists: add both tables to every list that already names `trx_rme_overpayment_obligations` (7 files today) and to any list that names `trx_rme_payments` | otherwise RESTRICT FKs fail cleanup on PostgreSQL only |
| `RepositoryServiceProvider` bindings | CG-04 |
| `config/feature_flags.php`: `rme.billing_refund_request` default OFF, risk `critical`, env override captured at config build | CG-08, rule 92 |
| `config/rme_refund.php`: source-controlled pilot branches and **per-branch** maker/checker lists, no `env()`, fail-closed | RF-1, RF-3; follows the source-control and fail-closed conventions of `config/rme_invoice_correction.php`, but that file holds one global maker list and one global checker list, so the per-branch structure is new |
| `PermissionSeeder` (+ grouping), `RoleSeeder` | permissions `request_rme_refund`, `approve_rme_refund` only |
| `.claude/rules/rme-billing.md` paths | add the new test directory and config file |

## 5. Production verification after deploy (read-only)

1. `migrate:status` shows both migrations ran; no pending migration.
2. `information_schema`/`pg_constraint`/`pg_indexes`: both tables, every CHECK above, both partial
   unique indexes present.
3. Row counts: both tables 0 (nothing is created by deploying).
4. Global financial fingerprint (payments count/sum, invoices by status, obligations by status,
   corrections by type/status, receipt snapshots) **identical** before and after the deploy, taken
   immediately before the deploy (real payments land during CI — AIR-RMB-42).
5. Flag `rme.billing_refund_request` resolves OFF.

## 6. Sprint 5 migrations (outline only)

* Widen `trx_rme_overpayment_obligations` status CHECK to `IN ('OPEN','SETTLED')` (drop and re-add the
  named constraint inside one migration; additive in meaning).
* Add `SETTLED` to the request status CHECK if the request row records settlement completion.
* `trx_rme_refund_settlements` with UNIQUE per request **and** UNIQUE per obligation (an obligation is paid
  back at most once — the database backstop, independent of the obligation status transition), evidence
  storage on a private disk declared per rule 209, method per RF-9.
