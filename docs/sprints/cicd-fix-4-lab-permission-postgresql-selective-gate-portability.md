# CICD-FIX-4 — Lab & Permission PostgreSQL Selective-Gate Portability (HANDOVER, NOT STARTED)

**Status:** diagnosed, not implemented. Written by CICD-FIX-3 so the next corrective
sprint starts from evidence rather than rediscovery.

**Proposed base:** `feature/cicd-fix-3-ui-governance-fresh-checkout-build-artifact-contract`

---

## Why this is a separate sprint

CICD-FIX-3's mandate was the UI governance fresh-checkout failure. That is closed. The
Selective Module Gate still fails, but on **two unrelated root causes in different
modules**, both of the same family CICD-FIX-2 addressed: SQLite-only assumptions that
only surface now that the gate actually runs against authoritative PostgreSQL.

Folding them into CICD-FIX-3 would repeat the scope-creep this stacked-sprint process
exists to prevent.

---

## Proven pre-existing

Identical filters, identical conditions (PostgreSQL 16.14, no `public/build`), run at the
CICD-FIX-2 tip `b79d0ee` and at CICD-FIX-3 `81eda01`:

| Step | base `b79d0ee` | CICD-FIX-3 | Delta |
|---|---|---|---|
| `--filter='Lab'` | 19 failed | 18 failed | −1, the UI governance strict-mode test CICD-FIX-3 fixed |
| `--filter='Permission\|AccessControl'` | 4 failed | 2 failed | −2, the UIX-14 and UIX-20 governance tests CICD-FIX-3 fixed |

Every failure that remains under CICD-FIX-3 is present at base. CICD-FIX-3 introduces
none and removes three.

---

## Root cause A — `trx_lab_orders.branch_id` foreign key (18 failures)

```
SQLSTATE[23503]: Foreign key violation
insert or update on table "trx_lab_orders"
violates foreign key constraint "trx_lab_orders_branch_id_foreign"
DETAIL: Key (branch_id)=(1) is not present in table "mst_branches".
```

`tests/Feature/LabWorkflow/LabOperationalAnalyticsMetricTest.php:32` — the shared
`opV2Order()` helper hardcodes `'branch_id' => 1`, and the suite only calls
`seedAccessControl()`, so no branch with id 1 ever exists. SQLite did not enforce the
constraint; PostgreSQL does.

The same file already demonstrates the correct pattern at lines 209–210, which create real
branches and use `$a->id` / `$b->id`.

Affected files (all LAB-PROD-2):

- `tests/Feature/LabWorkflow/LabOperationalAnalyticsMetricTest.php` (helper + line 73)
- `tests/Feature/LabWorkflow/LabOperationalKpiAuditCommandTest.php`
- `tests/Feature/LabWorkflow/LabOperationalAnalyticsAccessTest.php`

One further failure (`SQLSTATE[25P02]`, in_failed_sql_transaction) is a cascade of the
same violation inside a transaction, not an independent defect.

**Likely fix:** create a real `Branch` in the fixture and use its id, matching the pattern
already present in the same file. Verify the analytics assertions still scope correctly —
some assert cross-branch behaviour, so the branch identity matters to the expectations.

---

## Root cause B — unordered pagination (1 failure)

`tests/Feature/AccessControl/PermissionManagementTest.php:12`

```php
$this->actingAs(userWith(['manage permissions']))
    ->get(route('settings.permissions.index'))
    ->assertOk()
    ->assertViewIs('settings.permissions.index')
    ->assertSee('manage users');
```

The page renders correctly (`assertOk` and `assertViewIs` both pass); the row is simply
not on the page being asserted. Without an explicit `ORDER BY`, PostgreSQL and SQLite
return rows in different orders, so `manage users` falls on a different page.

**Likely fix:** give the permissions listing a deterministic order. Prefer fixing the
*query* (a stable `ORDER BY`) over weakening the test — an unordered paginated list is a
real UX defect, not just a test artifact. Confirm before assuming: the ordering may
already be intended elsewhere and only missing here.

---

## Also worth deciding (not a failure today)

`tests/Feature/Inventory/InventoryUnifiedBranchMasterHotfixTest.php:136` skips on any
non-SQLite driver:

```php
$this->markTestSkipped('Foreign-key introspection assertion targets the sqlite test connection.');
```

It is the one skip in the otherwise-clean 1586-test Inventory step. It uses
`PRAGMA foreign_key_list()` — a twelfth PRAGMA site that CICD-FIX-2 guarded rather than
ported. Consequence: the `branch_id → mst_branches` foreign-key contract is verified
*only* on SQLite and never on the authoritative driver — which is precisely the contract
root cause A just violated undetected. Porting it to `Schema::getForeignKeys()` (the
approach CICD-FIX-2 established with `tests/Support/Database/SchemaFacts`) would have
caught root cause A.

---

## Target

`--filter='Lab'`, `--filter='Ui'`, `--filter='Permission|AccessControl'` all exit 0 on
PostgreSQL, with the Inventory step's 1586/0-failure result preserved.
