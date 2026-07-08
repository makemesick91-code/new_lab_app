# UIX-20 — Permission-Aware UI Consistency Polish

**Branch:** `feature/uix-20-permission-aware-ui-consistency-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous GO:** `uix-19-navigation-sidebar-information-architecture-polish-go`

## Scope

Presentation-only polish that makes permission-aware UI **consistent**: where an
authorized operator sees a guarded action, a view-only operator now gets a clear, canonical
explanation instead of a silent empty gap. This is a UI consistency sprint, **not** an
authorization redesign. No permission/role/policy/Gate/route-middleware/BranchContext/
business-logic change.

## Runtime permission-aware UI changes

- **New canonical component** `resources/views/components/ui/restricted-notice.blade.php` —
  permission-aware "restricted action" notice. Intentionally **non-submitting** (renders no
  submit control and no form), carries `role="note"` (UIX-17 aligned), semantic tokens only
  (no `teal-*`, no hardcoded hex). Props: `title` (default "Akses terbatas"), `description`,
  plus a default slot. It is copy/clarity — it performs no authorization itself and is only
  rendered inside the `@else` branch of an existing real `@can`/`@canany` guard.
- **Wired additively** as the `@else` companion of the existing permission-guarded
  empty-state action slots on four representative surfaces spanning Inventory / RME / Lab /
  Settings — the existing `@can` **conditions are untouched**:
  - `resources/views/inventory/products/index.blade.php`
  - `resources/views/rme/visits/index.blade.php`
  - `resources/views/lab-orders/index.blade.php`
  - `resources/views/settings/clinic-rooms/index.blade.php`
- **Dev component catalog** (`resources/views/dev/ui-catalog.blade.php`) registers the new
  `x-ui.restricted-notice` for discoverability (gated by the existing `view_developer_console`
  permission — unchanged).

## Permission boundaries inspected (no change)

- `@can('create', …)` policy guards on the four surfaces (Product / ClinicVisit / LabOrder /
  ClinicRoom policies) — unchanged; UIX-20 only adds an `@else` presentation branch.
- `ClinicRoomPolicy::create` → `manage_clinic_master_data`; `canView` → `view_clinic_master_data`
  or `manage_clinic_master_data`. Used to prove authorized-vs-view-only rendering in tests.
- Sidebar/topbar permission guards (UIX-19) preserved; no navigation guard weakened.

## No-change confirmations

- Route names / paths / methods: unchanged.
- Permission names / role names: unchanged.
- Permission-to-role assignments: unchanged.
- `@can` / `@canany` / `@cannot` guard conditions: unchanged (only additive `@else` presentation).
- Policies / `Gate::before` / route middleware / Spatie Permission: unchanged.
- BranchContext: unchanged; request `branch_id` never trusted.
- Controllers / services / repositories / queries / models / schema / migrations: none touched.
- Business / financial / stock / RME / Lab / dashboard / report logic: unchanged.
- No unauthorized link/action exposed; no authorized action hidden; no frontend-only authorization.
- No React/Vue/SPA/heavy permission/menu UI dependency added.
- No PII/KTP/NIK/scans/raw clinical notes/secrets/env values exposed.

## Governance

`ArchitectureUiGovernanceCheckCommand` extended with non-brittle UIX-20 rules: canonical
`restricted-notice` exists, stays non-submitting, keeps `role="note"`, uses semantic tokens;
the four representative surfaces keep their real `@can` guards and render the restricted
companion. Soft signals: this sprint doc and the design-system UIX-20 section.

## Tests

`tests/Feature/Ui/PermissionAwareUiUixTest.php` (7): non-submitting canonical component +
`role="note"` + copy; surface-specific description; **real HTTP** authorized (`manage_clinic_
master_data`) sees the create action and not the restricted notice; view-only
(`view_clinic_master_data`) sees the restricted notice and not the create action; guests
redirected to login; representative surfaces keep `@can` guards + companion; governance check
`--strict` GO.

## Docs / rules updated

- `docs/ui_design_system.md` — Permission-Aware UI Consistency (UIX-20) standard + governance.
- `docs/ui/daengtisiams-ui-governance.md` — UIX-20 section.
- `docs/sprints/uix-20-permission-aware-ui-consistency-polish.md` — this doc.

## Risk / rollback

Low risk: additive `@else` presentation branches and one new non-submitting component; no
backend/route/policy/permission/query/schema change. Rollback: revert the branch merge commit;
no migration or data change to unwind.

## Next recommended sprint

UIX-21 — UI/UX Rules Enforcement & Governance Lock (awaiting explicit approval).
