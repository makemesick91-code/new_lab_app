# Hotfix NSF-2.2 — Full Suite Remaining Failure Burn-down

## Objective

Reduce full suite failures from 18 to 0 with minimal safe compatibility fixes. No new product features.

## Environment

| Item | Value |
|------|-------|
| Starting HEAD | `c75ecd25db6cef2ec094da443d05f5d72d23d9a9` (includes NSF-2.1 evidence) |
| Branch | `hotfix/nsf-2-2-full-suite-remaining-failure-burndown` |
| Laravel | 12.61.0 |
| PHP | 8.5.4 |
| Local default DB | pgsql |
| Test DB | sqlite in-memory (`phpunit.xml`) |

## Graphify Map (failing areas)

- **Profile** — `ProfileController` missing `Redirect` facade import → 500.
- **RME workspace** — `MedicalRecordController@show` authorized before non-RME 404 check → 403 vs 404.
- **Stress seed** — `StressSeedRmeHistoryCommand` uses PostgreSQL `RIGHT()` on SQLite.
- **Inventory disposal report** — `ilike` in disposal report/repo → SQLite syntax error.
- **Inventory UI/tests** — dashboard quick-actions label + sidebar regrouping (Sprint 68.28) stale assertions.
- **Sprint 68.31** — batch option `value="N"` false positive vs branch `<option>`.
- **Sidebar collapse** — Admin Klinik redirected by `EnsureRmeOnlineContext` before dashboard renders.

## Before Full Suite

```
php artisan test
Tests: 18 failed, 5 skipped, 3572 passed (15296 assertions)
Duration: ~1383s
```

Raw: `storage/app/performance/nsf2-2-full-suite-before.txt`

## Failure Classification

| Category | Test | Symptom | Root cause | Fix | Risk | Status |
|----------|------|---------|------------|-----|------|--------|
| App bug | ProfileTest (3) | 500 `Class Redirect not found` | Missing facade import | Add `use Illuminate\Support\Facades\Redirect` | Low | Fixed |
| App bug | PatientCentricRmWorkspaceTest | 403 vs 404 non-RME | `authorize` before workspace null check | Reorder show() checks | Low | Fixed |
| SQLite compat | StressSeedRmeHistoryCommandTest (5) | `no such function: RIGHT` | PG-only SQL | Driver-aware `SUBSTR` fallback | Low | Fixed |
| SQLite compat | InventoryBatchDisposalReportTest | `near "ilike"` | PG-only operator | `LOWER() LIKE` pattern | Low | Fixed |
| Test stale | InventoryBatchLotUiVisibilityHotfixTest | Route list mismatch | New batch workflow routes | Update expected route names | Low | Fixed |
| Test stale | InventoryPermissionHardeningTest (3) | Sidebar labels | Sprint 68.28 regrouping | Update labels (`Operasional Stok`, `Pembelian`, `Laporan & Analitik`) | Low | Fixed |
| Test stale | InventoryUiTest | Missing quick-actions title | UI copy change | Assert `Aksi Cepat Harian Gudang` | Low | Fixed |
| Test stale | Sprint6831… | `value="2"` false positive | Batch id collides with branch option value | Drop fragile id assertion; keep batch label check | Low | Fixed |
| Test stale | SidebarCollapseToggleTest (2) | 302 on dashboard | Admin Klinik needs online context | Use `userWith(['view dashboard'])` | Low | Fixed |

## Changed Files

- `app/Http/Controllers/ProfileController.php`
- `app/Modules/MedicalRecord/Controllers/MedicalRecordController.php`
- `app/Console/Commands/StressSeedRmeHistoryCommand.php`
- `app/Modules/Inventory/Services/InventoryBatchDisposalReportService.php`
- `app/Modules/Inventory/Repositories/InventoryBatchDisposalRequestRepository.php`
- `tests/Feature/Inventory/InventoryUiTest.php`
- `tests/Feature/Inventory/InventoryBatchLotUiVisibilityHotfixTest.php`
- `tests/Feature/Inventory/InventoryPermissionHardeningTest.php`
- `tests/Feature/Inventory/Sprint6831InventoryReportsBranchScopedDependentFiltersTest.php`
- `tests/Feature/Navigation/SidebarCollapseToggleTest.php`
- `docs/sprints/hotfix-nsf-2-2-full-suite-remaining-failure-burndown.md`

## Rollback Plan

Revert hotfix commit(s). No migrations added. No database rollback required.

## Risk Assessment

**Low** — import fix, SQLite/PG search parity, authorize ordering for non-RME 404, test expectation alignment with intentional UI/sidebar changes.

## GO/NO-GO (Local)

**GO** — Full suite green after fixes.

### After Full Suite

```
php artisan test
Tests: 5 skipped, 3590 passed (15335 assertions)
Duration: 1424.84s
```

Raw: `storage/app/performance/nsf2-2-full-suite-after.txt`

### Targeted Regression

| Filter | Result |
|--------|--------|
| Nsf21 | passed |
| SlowQuery | passed |
| Nsf2 | passed |
| Receivable | passed |
| Cashier | passed |
| Profile | passed |

### Build / Style

- `./vendor/bin/pint --dirty` — passed
- `npm ci && npm run build` — passed
