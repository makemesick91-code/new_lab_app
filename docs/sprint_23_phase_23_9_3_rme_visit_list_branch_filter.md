# Sprint 23 Phase 23.9.3 — RME Visit List Branch Filter Fix

## 1. Summary

- **Status:** Implemented locally, tests passing. Not deployed.
- **Branch:** `feature/sprint-23-phase-23-9-3-rme-visit-list-branch-filter`
- **Base:** `sprint-23-phase-23-9-1-rme-clinic-branch-source` (commit `cf1f591`)
- **Tag (after tests pass):** `sprint-23-phase-23-9-3-rme-visit-list-branch-filter`
- **Scope:** Daftar Kunjungan RME now lists visits across **all active RME-enabled
  branches** by default, with an optional **Cabang RME** filter. No clinic_id
  filtering, no `users.branch_id`, MAIN excluded. Branch shown as `{code} — {name}`.

Business rule: **Klinik = Cabang RME**. RME branch source is `mst_branches`
where `is_active = true AND is_rme_enabled = true`.

## 2. Bug

A new patient (Megasanti) and visit (VIS-20260613-001) were saved correctly at
branch ATG3, but **Daftar Kunjungan did not show the visit**. The index query was
forced to the single `BranchContext` fallback branch; when the fallback was not
ATG3, ATG3 visits were silently hidden.

## 3. Evidence

Patient:
`Megasanti | RM DG-ATG3-2026-14023 | branch_id=3 | ATG3 | clinic_id=null`

Visit:
`VIS-20260613-001 | branch_id=3 | ATG3 | clinic_id=null | status=waiting`

An all-branch DB query found the visit; the UI (scoped to the fallback branch)
did not.

## 4. Root Cause

- `ClinicVisitService::paginate()` called `$this->visits->paginate($this->branchContext->requireId(), …)`.
- `ClinicVisitRepository::paginate()` then filtered `->where('branch_id', $branchId)`.
- The list scope therefore equalled the BranchContext fallback branch, not the
  operational RME branch set. ATG3 visits disappeared whenever the fallback
  resolved to TKM1 or MAIN.
- `ClinicVisitPolicy::belongsToActiveBranch()` had the same single-branch
  assumption, which could also block opening an ATG3 visit.

## 5. Fix

- **List scope = active RME-enabled branch set** (`BranchService::rmeEnabledIds()`),
  via new repository method `paginateForBranches(array $branchIds, …)`.
- **Optional `branch_id` filter** ("Cabang RME"): when the value is a valid RME
  branch the list narrows to it; any other value is ignored and the full RME
  scope is used.
- **No `clinic_id` filter** for the RME visit list.
- **No `users.branch_id`** reliance.
- **MAIN excluded** (it is not RME-enabled).
- **Branch column** displayed as `{code} — {name}` (em-dash), `—` when missing.
- **Dashboard counts** (`visitsTodayCount`, `waitingCount`, `inProgressCount`)
  now accept an optional `?int $branchId` and align with the list scope: full RME
  set by default, selected branch when filtered.
- **Policy** `belongsToActiveRmeBranch()` replaces `belongsToActiveBranch()` for
  ClinicVisit — a visit is viewable/printable/editable when its branch is one of
  the active RME-enabled branches. Non-RME branch visits remain forbidden.

## 6. Tests

### Commands run

```
php artisan test --filter=RmeVisitListBranchFilterTest   # 16 passed
php artisan test --filter=ClinicVisit                     # 66 passed
php artisan test --filter=Rme                             # 470 passed
php artisan test --filter=Permission                      # 156 passed
php artisan test --filter=Sidebar                         # 42 passed
php artisan test --filter="Dashboard|Patient|Branch"      # 455 passed
./vendor/bin/pint --dirty                                 # passed
npm run build                                             # built
```

### New test file

`tests/Feature/RME/RmeVisitListBranchFilterTest.php` (16 tests): default all-RME
scope; ATG3 visible regardless of BranchContext fallback (TKM1/MAIN, mocked); no
clinic_id requirement; MAIN/inventory-only absent from filter options; filter by
ATG3 / TKM1; non-RME filter ignored; search/status/date filters across RME
branches; counts for default and filtered scope; show an ATG3 visit; existing +
new patient visits both listed.

### Updated tests (old BranchContext-only behaviour)

The "another branch" in these isolation tests is now explicitly **non-RME**
(`is_rme_enabled => false`) so the scope boundary stays meaningful:

- `tests/Feature/RME/ClinicVisitTest.php`
  - `lists visits across RME branches but excludes non-RME branches` (was "only lists visits from active branch")
  - `prevents updating visits from a non-RME branch`
  - `user cannot transition a visit from a non-RME branch`
  - `user cannot open the print view of a non-RME branch visit`
- `tests/Feature/RME/RmePdfPrintHardeningTest.php`
  - `cross branch user cannot open rme visit print page`
  - `rme visit pdf route enforces branch isolation`

### Known limitations

- Full suite not run (focused suites only, per task). No schema/migration change.

## 7. Files Changed

### Service / repository / controller

- `app/Modules/Branch/Services/BranchService.php` — add `rmeEnabledIds(): array`.
- `app/Modules/ClinicVisit/Interfaces/ClinicVisitRepositoryInterface.php` — add
  `paginateForBranches`, `countTodayByBranches`, `countByBranchesStatus`.
- `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php` — implement the
  branch-set methods (eager-load `branch`).
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php` — inject `BranchService`;
  `paginate()` + counts use `scopeBranchIds()` over the RME set.
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` — read `branch_id`
  filter, scope counts, pass `rmeBranches` to the view.

### Policy

- `app/Modules/ClinicVisit/Policies/ClinicVisitPolicy.php` — `belongsToActiveRmeBranch()`.

### Views

- `resources/views/rme/visits/index.blade.php` — Cabang RME filter dropdown,
  Klinik/Cabang column, reset condition + widget links preserve `branch_id`.

### Tests

- `tests/Feature/RME/RmeVisitListBranchFilterTest.php` (new).
- `tests/Feature/RME/ClinicVisitTest.php` (4 isolation tests updated).
- `tests/Feature/RME/RmePdfPrintHardeningTest.php` (2 isolation tests updated).

### Docs

- `docs/sprint_23_phase_23_9_3_rme_visit_list_branch_filter.md` (this file).
- `docs/sprint_history.md` (entry appended).

## 8. Watch Items

- **VPS deploy requires a DB backup first.** Use `php artisan migrate --force`
  only. **Never** `migrate:fresh` / `db:wipe` / `migrate:refresh` on VPS.
- Browser smoke must confirm **Megasanti / VIS-20260613-001** appears in Daftar
  Kunjungan (branch ATG3) after deploy.
- Confirm the **Cabang RME** filter lists ATG3 / LDK2 / TKM1 (and **not** MAIN),
  and that filtering narrows the list correctly.
- Confirm **both existing-patient and new-patient** visits appear.
- Confirm **no 500** on the visit index, show, and PDF/print routes.

## 9. Next Recommended Phase

**Sprint 23 Phase 23.9.4 — VPS Deploy + Visit List Branch Filter Smoke**
(backup → pull → `migrate --force` → reset storage/cache permissions → browser
smoke per the watch items above).
