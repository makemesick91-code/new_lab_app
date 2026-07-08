# UIX-13 — Owner Dashboard & KPI Polish

Branch: `feature/uix-13-owner-dashboard-kpi-polish`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Previous required GO: `uix-12-rme-cashier-receivable-follow-up-polish-go`

## Scope

Thirteenth UI/UX sprint — **presentation-only** polish of the Owner dashboard and
executive KPI surfaces onto the UIX-1 DaengtisiaMS design system. The owner
landing view becomes the reference executive-dashboard page and the branch-admin
dashboard is brought fully onto semantic tokens + foundation components.

UIX-2 already migrated the owner KPI block (`dashboards/owner-kpi.blade.php`) and
the shared `components/owner-dashboard/*` partials. UIX-13 completes the remaining
surfaces that UIX-2 did not touch: the owner landing (`dashboard.blade.php`)
branch drilldown tables, the read-only branch filter, empty/error states, section
hierarchy, and the branch-admin dashboard (`dashboards/branch-admin.blade.php`).

## Runtime UI changes

- `resources/views/dashboard.blade.php` (owner landing + fallback):
  - Header slot gray tokens → `text-navy` / `text-ink-soft`.
  - Read-only RME/Lab branch filter `<select>` → `x-ui.select` (same `GET`
    `branch_id` param, same `onchange="this.form.submit()"` semantics).
  - The two hand-rolled branch drilldown tables ("Ringkasan Per Cabang" and
    "Ringkasan Piutang per Cabang") → `x-ui.table` + `x-ui.empty-state`, with
    thead/tbody retokenized (`bg-navy-50`, `text-ink-soft`, `divide-hairline`).
  - "Piutang RME" / "Lihat Piutang" drilldown links → `x-ui.button variant="secondary" size="sm"`.
  - Executive KPI section heading, branch-performance empty state, "Akses Detail"
    quick links, and the no-owner/no-branch fallback section → semantic tokens,
    `x-ui.empty-state`, `x-ui.card`, and `x-ui.button`.
- `resources/views/dashboards/branch-admin.blade.php`:
  - Intro hero → `x-ui.card accent`; legacy `text-teal-700` → `text-brand-700`.
  - Daily-summary heading + work-queue cards + queue empty states → navy/ink/hairline tokens.
- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php`:
  - Added non-brittle UIX-13 rules — owner landing must use `x-ui.table` +
    `x-ui.empty-state` + `x-ui.select`; both dashboard views must be free of
    legacy palette/gray/hardcoded hex, must not use gold as a CTA, must stay
    read-only (no `POST/PUT/PATCH/DELETE` form or `@method` verb), and must never
    render KTP/NIK; UIX-13 evidence doc present (soft).

## Files changed

- `resources/views/dashboard.blade.php`
- `resources/views/dashboards/branch-admin.blade.php`
- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php`
- `docs/sprints/uix-13-owner-dashboard-kpi-polish.md` (new)
- `docs/ui_design_system.md` (dashboard/KPI standard note)
- `tests/Feature/Ui/OwnerDashboardKpiUixTest.php` (new)

## No-change confirmations

- **Dashboard read-only:** no create/update/delete mutation added; only `GET`
  filter forms and drilldown links remain. Governance asserts no mutating verb.
- **KPI calculation:** no `@php` KPI array, `OwnerDashboardKpiService`, controller,
  service, or repository logic changed — only Blade presentation.
- **Period/date filtering semantics:** unchanged — same `branch_id` `GET` param,
  same `route('dashboard')` action, same `onchange` submit.
- **Branch drilldown semantics:** unchanged — same rows, columns, values, links
  (`rme.cashier.receivables?branch_id=...`), same branch-scoped drilldown data.
- **Permission behavior:** unchanged — `@canany`/`@can` gates, `view_owner_dashboard`,
  branch operational permissions, and `Gate::before` untouched.
- **Supervisor RME no-bypass:** no permission or role gate changed; Supervisor RME
  gains no owner-dashboard access.
- **PII/KTP/NIK/scans/raw clinical notes:** none rendered; governance asserts no
  KTP/NIK attribute rendered on either dashboard view.
- **Heavy chart dependency:** none added — Blade + Tailwind + Alpine only.
- **Expensive query:** no query changed (presentation-only).

## Risk & rollback

Risk: low — presentation-only Blade + a read-only governance command extension.
All asserted dashboard strings (headings, KPI labels, table headers, empty-state
copy) preserved verbatim. Rollback: revert the branch / GO commit; no migration,
no schema, no data change.
