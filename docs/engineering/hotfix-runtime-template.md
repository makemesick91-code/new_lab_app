# DaengtisiaMS — Hotfix Runtime Template

Inherits everything in `sprint-runtime-template.md`. A hotfix is the **smallest
coherent scope** that fixes one runtime defect.

## Hotfix rules (type = HOTFIX)

- **Audit level 1 (Scoped)**: inspect only the changed call-site, its route,
  its policy, direct dependencies, and the existing tests for that code.
  `sprint:audit-plan` prints the list.
- **No wide refactor.** `sprint:scope-audit` reports NO-GO if a hotfix carries a
  broad refactor or spans unrelated modules (`allow_refactor=false`,
  `max_modules=2`).
- **Tests**: focused + related regression (from `sprint:test-plan`). Full
  foundation audit is not required unless a shared foundation is touched (which
  auto-escalates).
- **Deploy** when `runtime_change=true`; otherwise no deploy and no GO tag.
- **Rollback** target must exist (`scripts/rollback-vps.sh`).
- **Split** anything larger than a single defect fix into its own sprint. A
  data repair, a docs change, or a foundation change does not ride along.

## Flow

```
php artisan sprint:new FIX-XYZ --type=HOTFIX --module=<M> --runtime --deploy
php artisan sprint:prepare
php artisan sprint:manifest-check
php artisan sprint:audit-plan
php artisan sprint:test-plan
php artisan sprint:test --all-required
php artisan sprint:scope-audit --strict
php artisan sprint:release-check
scripts/sprint-release.sh --dry-run      # then --apply after CI green + merge
```
