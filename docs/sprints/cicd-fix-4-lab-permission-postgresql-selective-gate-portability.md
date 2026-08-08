# CICD-FIX-4 — PostgreSQL Fixture Integrity & Deterministic Pagination Recovery

**Status:** Debt A and Debt B closed. Selective Module Gate still red on a **third,
unrelated pre-existing defect** that is deliberately out of scope (see below).

**Branch:** `feature/cicd-fix-4-postgresql-fixture-integrity-deterministic-pagination`
**Base:** `feature/cicd-fix-3-ui-governance-fresh-checkout-build-artifact-contract` @ `7cba697`

This file began as a handover written by CICD-FIX-3. Four of its hypotheses turned out to
be wrong once measured; they are corrected below rather than deleted, because a future
sprint reading only the original text would be misled.

---

## Entry condition

CICD-FIX-3 had **no authoritative CI run at all** — the workflow auto-triggers only on the
canonical base, and no `workflow_dispatch` had ever been fired for that branch. One was
dispatched at `7cba697` (run `31231320053`) to establish the baseline:

| Gate | Result |
|---|---|
| CICD-CTRL Gate Classifier | success |
| NSF-R012 Quality Gate | **success** |
| NSF-R011 Critical Test Gate | **success** |
| CICD-CTRL Selective Module Gate | failure |
| NSF-9 Release Safety & Automated Smoke | success |
| NSF-10 Release Evidence Gate | success |
| NSF-R011 Full Suite Gate | skipped (standing pattern) |

The second `NSF-R011 Critical Test Gate` row reported `skipped`: that is the *self-hosted*
variant, correctly skipped because `runner_mode=github-hosted`. Exactly one variant runs by
design — it is not a dropped gate.

Authoritative Selective breakdown at `7cba697`:

| Step | Result |
|---|---|
| `--filter='Inventory'` | 1586, **0 failures** |
| `--filter='Lab'` | **18 failed** |
| `--filter='Ui'` | **never ran** |
| `--filter='Permission\|AccessControl'` | **never ran** |

The steps are sequential, so the Lab failure aborts the job before Ui and Permission. Any
Permission figure quoted in the original handover therefore came from a local run, not CI.

---

## Root cause A — dangling foreign keys in the LAB-PROD-2 fixtures

```
SQLSTATE[23503]: Foreign key violation
insert or update on table "trx_lab_orders"
violates foreign key constraint "trx_lab_orders_branch_id_foreign"
DETAIL: Key (branch_id)=(1) is not present in table "mst_branches".
```

**Correction to the handover.** It stated "SQLite did not enforce the constraint;
PostgreSQL does." That is false. Both drivers declare the foreign key and both enforce it —
the SQLite test connection runs with `foreign_keys=1`, and `Schema::getForeignKeys()`
reports `branch_id -> mst_branches` on each.

The actual cause is a **surrogate-id assumption**:

```
LabOrder::factory() -> Doctor::factory() -> Branch::factory()
```

`DoctorFactory` creates a branch as a side effect. Under SQLite every test runs inside a
transaction that is rolled back, so rowids restart and that incidental branch lands on
id 1 — making `branch_id => 1` resolve by accident. PostgreSQL does not roll sequences
back. Probing the CI database showed the state exactly:

```
mst_branches rows=0  min_id=-  seq_last=94
```

Zero surviving rows, sequence already at 94 — id 1 is unobtainable, so the row is
correctly rejected.

Ten dangling literals across three files, in **two** FK families:

| File | Column(s) | FK target |
|---|---|---|
| `LabOperationalAnalyticsMetricTest.php` | `branch_id` ×2, `changed_by`, `analyzed_by` ×2, `created_by` | `mst_branches`, `users` |
| `LabOperationalKpiAuditCommandTest.php` | `branch_id` ×2, `changed_by` | `mst_branches`, `users` |
| `LabOperationalAnalyticsAccessTest.php` | `branch_id` | `mst_branches` |

`trx_lab_orders.created_by`, `trx_lab_order_status_logs.changed_by`,
`trx_lab_model_analyses.analyzed_by` and `trx_lab_external_dispatches.created_by` are all
**NOT NULL** FKs to `users`; only `branch_id` is nullable.

**Fix.** Two shared helpers in `tests/Pest.php` — `labOpsBranch()` and `labOpsActor()` —
extending the existing lab fixture helpers rather than duplicating setup across three
files. Resolved by code/email so they survive `RefreshDatabase` and so every
default-scoped order in one test still shares ONE branch, preserving the single-branch
grouping the analytics fixtures assume. No constraint dropped, deferred or disabled; no
sequence manipulated to conjure id 1; ids are whatever the database assigns.

New `LabOperationalAnalyticsFixtureIntegrityTest` pins it: the FK must be declared on every
supported driver, a valid parent must be accepted, a dangling `branch_id` must be
**rejected** (asserted through a savepoint so PostgreSQL's aborted-transaction state cannot
leak into the rest of the test), and the fixtures must hold for any id — not just 1.

---

## Root cause B — collation-dependent pagination, not a missing `ORDER BY`

**Correction to the handover.** It stated "Without an explicit `ORDER BY`, PostgreSQL and
SQLite return rows in different orders." That is false.
`PermissionRepository::paginate()` already ordered by `name`.

The defect is that `ORDER BY name` is **collation dependent**. With 142 seeded permissions
at 50 per page:

| Collation | `manage users` rank | Page |
|---|---|---|
| SQLite `BINARY` | 36 | 1 |
| PostgreSQL `en_US.utf8` | 66 | 2 |

`en_US.utf8` de-weights the space/underscore separators, so `manage_lab_pickups` compares
as `managelabpickups` and sorts **before** `manageusers`. PostgreSQL ranks 34–37 interleave
`manage_invoice` with `manage invoices`, and `manage lab orders` with `manage_lab_orders` —
something SQLite never does, because BINARY groups every space-name first (0x20 < 0x5F).

Two separate things were wrong, so both were fixed:

- **Application.** `name` is unique only together with `guard_name`
  (`$table->unique(['name','guard_name'])`), so two rows may legitimately share a name and
  `ORDER BY name` alone leaves their order undefined — a row could be served on two pages
  or none. `id` added as the stable unique tie-breaker. The business ordering stays
  alphabetical. Forcing a specific collation was deliberately **not** done: that would
  degrade the real user-facing ordering purely to suit a test.
- **Test.** Which page a row lands on is a collation detail, not a product contract. The
  page-1 `assertSee` is replaced by asserting the contract — the page renders exactly the
  rows the paginator resolved — and the reachability the old assertion was really after is
  now covered properly by walking every page and proving `manage users` is served exactly
  once. A further test proves two identically-named permissions still page stably and
  repeatably.

Nothing was relaxed, and nothing is sorted inside the assertion to hide query
nondeterminism.

---

## Twelfth PRAGMA site — ported

`InventoryUnifiedBranchMasterHotfixTest` asserted "every branch-scoped inventory table has
a `branch_id` foreign key to `mst_branches`" using raw `PRAGMA foreign_key_list()`, and
called `markTestSkipped()` on any non-SQLite driver. So on the authoritative connection the
branch-master FK contract was **never verified** — the guard reported green by skipping.
That is the same blind spot that let root cause A survive.

Ported to the CICD-FIX-2 `SchemaFacts` helper (new `foreignKeys()` /
`foreignKeyTargetFor()` reading Laravel's database-agnostic `Schema::getForeignKeys()`) — no
second introspection mechanism. Probed first: all 12 tables really do declare
`branch_id -> mst_branches` on PostgreSQL, so the guard now passes by asserting rather than
by skipping, and adds no new debt.

---

## Root cause C — NOT fixed here, reported under §19

**Correction to the handover.** It stated the `SQLSTATE[25P02]` failure "is a cascade of the
same violation inside a transaction, not an independent defect." That is false. It is an
independent, third pre-existing defect and it still fails after root cause A is fully fixed.

```
tests/Feature/LabWorkflow/LabWorkflowV2NotificationsLegacyTest.php:103
it('never blocks the workflow when notification insert fails')

SQLSTATE[25P02]: In failed sql transaction: current transaction is aborted,
commands ignored until end of transaction block
(SQL: select * from "trx_lab_orders" where "id" = ... limit 1)
```

The test calls `Schema::drop('notifications')` to force a notification-insert failure, then
asserts the workflow still advances. SQLite tolerates a failed statement inside a
transaction and lets subsequent queries run. PostgreSQL aborts the entire transaction until
rollback, so the later `$order->refresh()` dies. Same 25P02 family CICD-CTRL-3 already
recorded for `Dq1AuditService`.

Evidence that it is pre-existing and not caused by CICD-FIX-4:

- present in the authoritative CICD-FIX-3 baseline (`25P02` ×1 in run `31231320053`)
- the file is untouched by CICD-FIX-4
- fails identically when run in isolation
- passes on SQLite (11 passed) and fails only on PostgreSQL

It is a different root cause requiring a different technique (savepoint isolation or
restructuring the deliberate-failure step), so per the mandate's §19 it is **reported, not
absorbed**. No CICD-FIX-5 has been created.

Consequence: because the Selective steps are sequential, this single failure still aborts
the Lab step and Ui/Permission are still not reached in authoritative CI — even though both
pass locally.

---

## Measured results — PostgreSQL 16.14, exact CI filters

| Step | Before (base `7cba697`) | After CICD-FIX-4 |
|---|---|---|
| `--filter='Inventory'` | 1586 passed, 0 failed (8048 assertions) | **1586 passed, 0 failed (8096 assertions)** |
| `--filter='Lab'` | 18 failed | **1 failed** (root cause C only), 580 passed, 9 skipped |
| `--filter='Ui'` | not reached in CI | **585 passed** |
| `--filter='Permission\|AccessControl'` | 1 failed, 328 passed | **331 passed** |

The three LAB-PROD-2 suites go 18 failed → **32 passed / 78 assertions**.
`PermissionManagementTest` reports **6 passed / 76 assertions on both drivers**.
Inventory assertions rise 8048 → 8096 because the ported FK guard now asserts on
PostgreSQL instead of skipping.

The 9 Lab skips are the documented GD-extension evidence tests plus the `pg_stat_statements`
probe — unchanged, not introduced here.

Regression checks: repo-wide `vendor/bin/pint --test` passed; `git diff --check` clean;
zero `Vite manifest not found`, zero `JsonException`, zero `42601` PRAGMA errors across all
four module logs; `GoodsReceiptSchemaTest`, `PurchaseOrderSchemaTest`,
`InventoryDecimalQuantityTest`, `SchemaFactsPortabilityTest`, `InventoryUixTest` all PASS;
`architecture:ui-governance-check --strict` → GO, exit 0.

---

## Target for closure

`--filter='Lab'` cannot exit 0 until root cause C is addressed by its own sprint. Until
then the Selective Module Gate stays red and CICD-FIX-4 is **WATCH, not GO** — the stack
merge in the mandate's §22 must not proceed on this evidence.
