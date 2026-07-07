# UIX-2 — Dashboard Owner Polish

**Date:** 2026-07-07
**Branch:** `feature/uix-2-dashboard-owner-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
**Type:** UI/UX polish only — presentation layer. No business logic, schema, route, permission, or KPI-formula change.

## UIX-1 baseline (verified before start)
- GO tag: `uix-1-daengtisiams-luxury-healthcare-design-system-foundation-go` (`0d78f92` / merge `3139f98`)
- Deploy evidence commit: `0cf4cf0`
- Pre-edit governance: `architecture:ui-governance-check` GO · `foundation:enterprise-closure-check` 36/36 GO · `foundation:cicd-enterprise-gate-check` 10/10 GO
- Worktree clean on the stable base branch before branching.

## Goal
Polish the Owner dashboard to the DaengtisiaMS Luxury Healthcare Design System (UIX-1): off-white canvas, white surfaces, **blue** primary/active, **gold accent-only** on revenue KPI, warning=orange / danger=red / success=green / info=blue, readable numbers, balanced density, no heavy frontend deps.

## Data flow — touched vs not touched
- **NOT touched:** `HomeDashboardController`, `OwnerDashboardKpiService`, `OwnerDashboardRmeLabKpiService`, `OwnerDashboardRmeLabDrilldownService`, routes, policies, permissions, `BranchContext`, KPI formulas. All KPI values, drilldown links, branch scoping, and privacy (no KTP/NIK/scans/raw notes) are unchanged.
- **Touched (presentation only):** Owner dashboard Blade views + shared owner-dashboard components + the read-only UI governance check + docs/tests.

## Files changed
- `resources/views/dashboards/owner-kpi.blade.php` — Sprint 62.0 KPI block rebuilt on `x-ui.card`, `x-ui.kpi-card` (gold `:accent` on **Total Pendapatan** only), `x-ui.table`, `x-ui.badge` (receivable status), `x-ui.button`, `x-ui.input`/`x-ui.select` (period + branch filter), `x-ui.empty-state`. Trend bars: teal → `bg-brand-500` (visits) / `bg-success` (payments). All grays → ink/navy/hairline tokens. KPI cards become clickable drilldowns with a brand focus ring. Preserved required strings `Dashboard KPI Owner` and `Low Stock`.
- `resources/views/dashboard.blade.php` — Owner + operational-fallback sections retokenized: intro → `x-ui.card accent` + `x-ui.button`; RME/Lab monitoring panel border/bg/eyebrow → brand; branch filter select → tokens; drilldown buttons teal → brand; `text-amber-700` → `text-warning-700`; neutral `bg-gray-900` CTAs → `bg-navy`. No structural/data change.
- `resources/views/components/owner-dashboard/{owner-kpi-card,alert-panel,dashboard-section,pipeline-card,branch-performance-card,activity-timeline}.blade.php` — legacy palette (teal/amber/emerald/sky/rose/gray) → design tokens (brand/warning/success/info/danger/navy/ink/hairline; `ui-card`). Shared by branch-admin dashboard too — consistent design migration, no API/prop change.
- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` — added non-brittle UIX-2 rules: owner dashboard views exist, owner KPI block uses `x-ui.kpi-card`, no legacy `teal-*` in owner dashboard files, gold never a CTA in owner KPI view, UIX-2 evidence doc present (soft).
- `docs/ui/daengtisiams-ui-governance.md`, `docs/ui/daengtisiams-implementation-checklist.md` — UIX-2 rules + acceptance recorded.
- `tests/Feature/Ui/OwnerDashboardUixTest.php` — new UIX-2 assertions.

## Components used
`x-ui.card` · `x-ui.kpi-card` (accent=revenue) · `x-ui.table` · `x-ui.badge` · `x-ui.button` · `x-ui.input` · `x-ui.select` · `x-ui.empty-state` · existing `x-owner-dashboard.*` (retokenized).

## Design direction compliance
- Primary/active/link/focus = blue (`brand`). Gold = accent-only, revenue KPI rail only, never a CTA. warning=orange, danger=red, success=green, info=blue. Off-white canvas + white surfaces. No new tokens introduced; no hardcoded hex.

## Tests / build / governance
_(filled during execution)_
- `php artisan test tests/Feature/Ui` · `tests/Feature/Owner` · `--filter=UiFoundationTest`
- `php artisan architecture:ui-governance-check` · `security-compliance-check` · `cicd-enterprise-gate-check` · `enterprise-closure-check`
- `npm run build` · `vendor/bin/pint --dirty` · `git diff --check`

## Smoke
_(filled during execution)_ — `/login` 200 · `/dashboard` guest 302 · authenticated Owner `/dashboard` 200 with KPI cards · `/dev/ui-catalog` gated · `/health/live`+`/health/ready` 200 · no new Laravel errors · fresh CSS asset.

## Known limitations / deferred
- RME/Lab monitoring summary **tables** in `dashboard.blade.php` keep neutral gray thead styling (functional, readable); a deeper table pass can move them onto `x-ui.table` in a later UIX pass.
- No chart library added — trends stay lightweight CSS bars per governance.

## Next UIX roadmap
UIX-3 Kunjungan list · UIX-4 RME + Odontogram · UIX-5 Kasir/Payment · UIX-6 Inventory · UIX-7 Lab pipeline · UIX-8 Reports/Print/PDF.
