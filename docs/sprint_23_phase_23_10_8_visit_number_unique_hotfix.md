# Sprint 23 Phase 23.10.8 — Visit Number Unique Generator Hotfix

## Status
PASS — local targeted and RME regression tests passed.

## Root Cause
RME clinic visit creation generated `visit_number` from `queue_number`.

`queue_number` is scoped by:
- branch_id
- visit_date

But `visit_number` has a global unique constraint.

This allowed different RME branches to generate the same visit number, for example:

- Branch A queue 1 → `VIS-20260613-001`
- Branch B queue 1 → `VIS-20260613-001`

The second insert could fail with:

`SQLSTATE[23505]: duplicate key value violates unique constraint trx_clinic_visits_visit_number_unique`

## Fix
Changed the generated visit number format from:

`VIS-YYYYMMDD-NNN`

to:

`VIS-{BRANCHCODE}-{YYYYMMDD}-{NNN}`

Example:

- `VIS-RME1-20260613-001`
- `VIS-RME1-20260613-002`
- `VIS-LANDAK-20260613-001`

## Implementation Notes
- `queue_number` remains branch/date scoped.
- `visit_number` is generated with a branch code prefix.
- Branch code resolution uses the first available value:
  - `code`
  - `branch_code`
  - `slug`
  - `name`
  - fallback `BR{id}`
- Branch code is sanitized to uppercase alphanumeric characters.
- Branch code is limited to 8 characters for compact print-friendly identifiers.
- The generator checks existing visit numbers with the same branch/date prefix.
- The generator increments the numeric suffix and verifies uniqueness before insert.
- No database reset was performed.
- No data was deleted.
- No destructive migration was added.

## Files Changed
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php`
- `tests/Feature/RME/ClinicVisitTest.php`

## Test Coverage
Added/updated test coverage for:
- new visit number format: `VIS-{BRANCHCODE}-{YYYYMMDD}-{NNN}`
- next unique suffix generation when suffix `001` already exists
- existing clinic visit creation flow
- RME regression coverage

## Verification
Targeted test:

`php artisan test --filter=ClinicVisit`

Result:
- 67 tests passed
- 203 assertions passed

RME regression test:

`php artisan test --filter=RME`

Result:
- 550 tests passed
- 1551 assertions passed

Code style:

`./vendor/bin/pint --dirty`

Result:
- PASS
- 2 files checked/fixed

## Deployment Plan
Deploy to VPS after commit/tag/push using safe production steps:

1. Backup database.
2. Fetch hotfix branch and tag.
3. Check diff from deployed commit.
4. Switch VPS to hotfix branch.
5. Run migration only if a migration exists.
6. Rebuild Laravel cache.
7. Restart PHP-FPM and reload nginx.
8. Smoke test creating a new RME visit.
9. Verify new visit number uses branch-coded format.

## Final Decision
Ready for commit, tag, push, and VPS deploy.

This hotfix does not require database schema changes and does not modify existing visit data.
