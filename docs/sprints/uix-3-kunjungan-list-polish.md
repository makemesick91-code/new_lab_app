# UIX-3 — Kunjungan List Polish

**Date:** 2026-07-07
**Branch:** `feature/uix-3-kunjungan-list-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
**Type:** UI/UX polish only — presentation layer. No business logic, query, schema, route, permission, or `BranchContext` change.

## Baseline (verified before start)
- UIX-1 GO tag: `uix-1-daengtisiams-luxury-healthcare-design-system-foundation-go`
- UIX-2 GO tag: `uix-2-dashboard-owner-polish-go`
- Worktree clean on the stable base branch before branching.
- Pre-edit governance all GO: `architecture:ui-governance-check` · `foundation:security-compliance-check` · `foundation:cicd-enterprise-gate-check` · `foundation:enterprise-closure-check`.

## Goal
Polish the **Kunjungan** list page (`GET /rme/visits` → `rme.visits.index`) onto the DaengtisiaMS Luxury Healthcare Design System (UIX-1) and make it the **reference implementation for every list page** (RME list, Kasir list, Inventory list, Procurement list, Lab list, Report list): off-white canvas, white surfaces, **blue** primary/active, **gold accent-only**, readable typography, balanced density, mobile-first, no heavy frontend deps.

## Dependency map (Graphify + direct)
- **Route:** `Route::resource('visits')` (`index` only used here) under the group `permission:view_clinic_visits|manage_clinic_visits`, name `rme.visits.index`.
- **Controller:** `App\Modules\ClinicVisit\Controllers\ClinicVisitController@index` — authorizes `viewAny`, passes `visits` (paginated), `filters` (`search`/`status`/`visit_date`/`branch_id`), `statuses` (`ClinicVisit::STATUSES`), `rmeWidgets`, `rmeBranches`, `roomsByBranch`, `rmLookup`. **Not touched.**
- **View (target):** `resources/views/rme/visits/index.blade.php`.
- **Partial:** `rme.partials.cross-branch-rm-lookup` (unchanged).
- **Policy:** `ClinicVisitPolicy` (`viewAny`/`create`/`update`) — unchanged.
- **Per-row routes:** `rme.visits.show`, `rme.visits.edit`, `rme.visits.create`, `rme.visits.assign-room` (PATCH, `manage_clinic_visits`) — unchanged.
- **Sidebar:** `layouts/partials/sidebar.blade.php` "Kunjungan" — unchanged.

## Data flow — touched vs not touched
- **NOT touched:** controller, `ClinicVisitService`/repository, queries, pagination, request param names (`search`, `status`, `visit_date`, `branch_id`, `rm_lookup`), policies, permissions, `BranchContext`, RME workflow, assign-room logic. No migration/schema/route change.
- **Touched (presentation only):** the Kunjungan index Blade view + the read-only UI governance check + docs/tests.

## Files changed
- `resources/views/rme/visits/index.blade.php` — rebuilt on the design system:
  - Header → `x-ui.page-header` (breadcrumb "Rekam Medis Elektronik", `actions` = `+ Daftar Kunjungan`, gated by `@can('create')`).
  - KPI quick-jump widgets → `x-ui.kpi-card` wrapped in anchors with a brand focus ring (drilldowns preserve the existing `status`/`branch_id` params). No gold accent (revenue-only rule respected).
  - Filter → `x-ui.filter-bar` with `x-ui.input` (search, date) + `x-ui.select` (status, branch) + `actions` slot (`Terapkan` primary, `Atur Ulang` secondary when filters active). Same GET params.
  - New **quick status tabs** — presentation-only pills that drive the existing `status` param and preserve other filters. No backend change.
  - Table → semantic tokens (`bg-navy-50` header, `text-ink-soft`/`text-ink`/`text-navy`, `divide-hairline`, `hover:bg-navy-50`), wrapped in `overflow-x-auto`.
  - Status badge → `x-ui.badge :status="$visit->status"` (design-system status→tone map) with the Indonesian label as slot; added `cashier_pending` label ("Menunggu Kasir").
  - Inline room selector `Simpan` button → `x-ui.button size="sm"`; native compact select retokenized (teal → `border-hairline`/`focus:border-brand-500`).
  - Row actions → `x-ui.button size="sm"` (Detail secondary, Ubah primary).
  - Empty state → `x-ui.empty-state` with a `@can('create')` action.
- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` — added non-brittle UIX-3 rules (see Governance).
- `docs/ui/daengtisiams-ui-governance.md`, `docs/ui/daengtisiams-implementation-checklist.md` — list-page standard + UIX-3 acceptance recorded.
- `tests/Feature/Ui/KunjunganListUixTest.php` — new UIX-3 assertions.

## Components used
`x-ui.page-header` · `x-ui.filter-bar` · `x-ui.input` · `x-ui.select` · `x-ui.kpi-card` · `x-ui.card` · `x-ui.table` · `x-ui.badge` (`:status`) · `x-ui.button` · `x-ui.empty-state`.

## Governance (added to `architecture:ui-governance-check`, non-brittle)
For `resources/views/rme/visits/index.blade.php` (the reference list page):
- Uses `x-ui.page-header`, `x-ui.filter-bar`, `x-ui.table`, `x-ui.badge`, `x-ui.button`, `x-ui.empty-state`.
- Status badge resolves tone via the `:status` map.
- **No** legacy `teal-*` brand class.
- **No** hardcoded hex color.
- UIX-3 sprint evidence doc present (soft signal).
Check stays informational (not wired into the blocking `combinedDecision`).

## List-page standard (durable rule for all future list pages)
Every list/index page (RME, Kasir, Inventory, Procurement, Lab, Report) MUST use `x-ui.page-header`, `x-ui.filter-bar`, `x-ui.table`, `x-ui.badge` (`:status` for domain status), `x-ui.button`, `x-ui.empty-state`, and semantic design tokens — no table/badge/button built from scratch, no hardcoded color, no legacy teal.

## Validation
- Tests: `tests/Feature/Ui/KunjunganListUixTest.php` + `tests/Feature/Ui/OwnerDashboardUixTest.php` green; RME visits regression green.
- Governance: `architecture:ui-governance-check --strict` GO; `security-compliance-check` / `cicd-enterprise-gate-check` / `enterprise-closure-check` GO.
- Build: `npm run build` pass; `vendor/bin/pint --dirty` clean; `git diff --check` clean.

## Deferred
- **Doctor filter:** the controller does not currently expose a doctor option list; adding a doctor filter needs a controller/service change and is out of the polish scope — deferred to a future sprint (the Dokter column is retained).
- Remaining list pages (Kasir/Inventory/Procurement/Lab/Report) adopt this reference in their own UIX sprints.

## Risks
- Low. Presentation-only; the compiled Blade passes `view:cache`; no data/query/permission touched. Same request params → existing links/bookmarks keep working.
