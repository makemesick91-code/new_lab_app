# ADLMS UI Design System

Version: 1.0
Last updated: June 2026
Status: **Official.** This is the canonical UI/UX standard for all future ADLMS modules.

This document governs every Blade view, component, and layout. It is subordinate to
[docs/architecture_rules.md](architecture_rules.md) and
[docs/ai_development_guide.md](ai_development_guide.md) — where this document and those
conflict, the architecture rules win. UI consistency never overrides branch isolation,
ledger-derived stock, permission-aware visibility, or the
Controller → Request → Service → Repository → Model flow.

It was extracted from the live codebase: `resources/views` (layouts, sidebar,
`settings-shell`, the `owner-dashboard`/`branch-dashboard`/`inventory` component families,
and the Sprint 12 inventory views), reconciled against the architecture and AI-development
guides. The Sprint 12 `inventory/products/index` view is treated as the **reference
implementation** of current conventions; older views (e.g. `inventory/stock/index`,
`invoices/index`) using indigo accents, `sm:rounded-lg` cards, and no mobile layout are
**legacy** and should converge on this standard when touched.

---

# Design Philosophy

ADLMS is a **dense, internal SaaS operations tool** for a multi-branch dental laboratory —
not a marketing site. Every screen exists to let an operator (clinic owner, branch admin,
technician, finance, courier) make a decision quickly and safely.

Principles:

1. **Operational, not promotional.** Build the working page. No hero blocks, no decorative
   gradients, no landing pages for internal tools.
2. **Decision density.** Show the data that drives the next action, compactly and scannably.
   Prefer information per scroll over whitespace for its own sake.
3. **Status is always visible.** Every record's state is shown with a semantic badge and text —
   never color alone, never ambiguous.
4. **Branch- and permission-truthful.** The UI only ever shows the active branch's data and
   only the actions the user is authorized for. Selectors never leak other branches.
5. **Honest about data.** Empty states say what is missing; the UI never fabricates numbers or
   implies fake data. Ledger-derived values are labeled as derived.
6. **Restrained palette.** One teal primary, slate neutrals, and a strict semantic status set.
   Color carries meaning, not decoration.
7. **Reuse before reinvention.** Use the existing component families and Breeze primitives
   before authoring new markup.

Aesthetic: clean medical/laboratory SaaS — calm, precise, trustworthy, readable at a glance
on both a branch desktop and a courier's phone.

## Color Foundation

| Role | Token | Tailwind | Use |
|---|---|---|---|
| Primary / brand | teal | `teal-700` (hover `teal-600`, ring `teal-500`) | primary actions, eyebrow labels, active accents |
| Neutral solid | gray | `gray-900` (hover `gray-800`) | filter "Apply", neutral solid buttons |
| Surface | white | `bg-white` | cards, tables |
| Canvas | gray | `bg-gray-100` | app background |
| Text | gray | `gray-900` ink / `gray-500` muted / `gray-400` faint | hierarchy |
| Border / divider | gray | `border-gray-200`, `divide-gray-100/200` | structure |
| Success | emerald/green | `emerald-50` / `emerald-700` | healthy, completed, paid, OK |
| Warning | amber | `amber-50` / `amber-700` | pending, low stock, due soon |
| Danger | rose/red | `rose-50` / `rose-700` | out of stock, overdue, failed, irreversible |
| Info | sky | `sky-50` / `sky-700` | received, issued, in-transit, scope chips |
| Draft / neutral state | gray | `gray-100` / `gray-600` | draft, cancelled, inactive, metadata |

> Legacy views use `indigo-*` for primary/focus and `bg-gray-800` for buttons. Treat indigo as
> deprecated; new and modified views use **teal** as the primary and focus color.

## Typography

Font: **Figtree** (loaded via bunny.net, configured in `tailwind.config.js`). Do not introduce
other font families.

| Token | Classes | Use |
|---|---|---|
| Eyebrow | `text-xs font-semibold uppercase tracking-wide text-teal-700` | section/page kicker |
| Page title | `text-xl font-semibold text-gray-900` | page H1 |
| Section title | `text-base font-semibold text-gray-900` | card/section H2 |
| Body | `text-sm text-gray-700` | default |
| Muted / helper | `text-sm text-gray-500` / `text-xs text-gray-500` | descriptions, captions |
| Numeric | add `tabular-nums` | all quantities, money, counts |

## Spacing & Shape

- Page rhythm: `space-y-6` between major blocks.
- Card padding: `p-4` (dense/list cards) to `p-6` (content cards).
- Table cells: `px-3 py-2` (compact) / `px-4 py-3` (primary list tables).
- Radius: `rounded-lg` for cards/inputs/buttons; `rounded-full` for badges/pills.
- Elevation: `shadow-sm` only for cards; reserve heavier shadows for overlays (modal, dropdown).
- Card frame (canonical): `rounded-lg border border-gray-200 bg-white shadow-sm`.

---

# Layout Standards

## App layout

Layout hierarchy (do not bypass):

```text
layouts/app.blade.php
  -> layouts/navigation.blade.php   (slim top bar: brand + user menu + mobile toggle)
  -> layouts/sidebar.blade.php      (permission-aware module navigation)
  -> components/settings-shell.blade.php   (universal module shell: header + flash + slot)
      -> module views
          -> module partials / components
```

Every module page renders inside `<x-settings-shell title="...">`. The shell provides the
sidebar, the page header band, and standard flash/validation rendering. Do **not** hand-roll a
new shell or re-include the sidebar manually.

## Content width

- Operational/table/dashboard pages: full content column inside the shell, max `max-w-7xl`,
  horizontal padding `px-4 sm:px-6 lg:px-8`.
- Single-column forms: constrain the form body to a readable width (`max-w-3xl`) even when the
  shell is wider.
- Inner content uses `space-y-6` as the vertical rhythm.

## Header structure

Every page opens with a header block:

```blade
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Inventory Products</p>
        <h2 class="mt-1 text-xl font-semibold text-gray-900">Product Stock Directory</h2>
        <p class="mt-1 text-sm text-gray-500">Branch-total stock is derived from the movement ledger.</p>
    </div>
    {{-- primary action(s), permission-gated --}}
    @can('manage_inventory')
        <a href="{{ route('inventory.products.create') }}"
           class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
            Create Product
        </a>
    @endcan
</div>
```

Required parts: **eyebrow** (context), **title**, **one-line operational description**, and
**primary action(s)** on the right. Actions must be permission-gated.

## Breadcrumbs

ADLMS uses a flat module structure; breadcrumbs are **optional** and only used for deep
detail pages (e.g. Product → Stock Card). When used:

- Place directly under/above the header, `text-sm text-gray-500`.
- Each crumb is a real link except the current page (`text-gray-700`, not linked).
- Use `aria-label="Breadcrumb"` on the `<nav>` and `aria-current="page"` on the last item.
- Never substitute breadcrumbs for the sidebar; the sidebar is the primary IA.

## Action bars

When a page has list-level actions beyond the header (bulk filters, export, secondary
navigation), group them in a single bar:

- Filters live in their own bordered card (see **Filter Standards**), not mixed with the header.
- Row-level actions live in the table's right-aligned final column, consistent order
  (`View` → workflow action → `Edit`), permission-gated.
- Destructive actions (cancel, void, adjust-out) are visually distinct (rose text) and confirmed
  via the `<x-modal>` component — never a native `prompt()`/`alert()`.

---

# Navigation Standards

## Sidebar rules

`layouts/sidebar.blade.php` is the canonical module navigation.

- **Permission-aware:** every link/section is wrapped in `@can`, `@canany`, or `@role`. Never
  render a link to a route the user cannot access (architecture rule).
- Gate each item with the **same permission used by its route**.
- No dead links (`href="#"`) and no internal/sprint jargon ("(Sprint 5)") in production nav.
- Width `w-64`, `border-r border-gray-200 bg-white`. On mobile it must be reachable (see
  Responsive Rules) — never leave a fixed sidebar covering the content with no toggle.
- Icons are encouraged (inline SVG, `h-4 w-4`) but text labels are mandatory.

## Menu grouping

Group links under uppercase eyebrow section headers, ordered by operational frequency:

```blade
<p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Inventory</p>
```

Canonical groups: **Dashboard/Overview**, **Operations** (Lab Orders, Production, QC, Deliveries),
**Inventory** (Dashboard, Products, Locations, Suppliers, Stock), **Finance** (Invoices),
**Reports**, **Master Data**, **Settings**. Disambiguate repeated labels — qualify the three
"Dashboard" entries as "Overview", "Inventory", "Reports".

## Active states

Mark the active item with `request()->routeIs(...)`, matching the whole module sub-tree:

```blade
<a href="{{ route('inventory.products.index') }}"
   @class([
       'flex items-center gap-2 rounded-lg px-3 py-2',
       'bg-teal-50 text-teal-700 font-medium' => request()->routeIs('inventory.products.*'),
       'text-gray-600 hover:bg-gray-50 hover:text-gray-900' => ! request()->routeIs('inventory.products.*'),
   ])>Products</a>
```

Active = `bg-teal-50 text-teal-700 font-medium`. Inactive = `text-gray-600 hover:bg-gray-50`.

---

# Dashboard Standards

ADLMS has three dashboard surfaces, each with its own **existing component family**. Reuse these
components before creating new markup.

## Owner Dashboard

Audience: clinic owner / group leadership — a **cross-branch**, decision-oriented overview.
Component family (`resources/views/components/owner-dashboard/`):

- `owner-dashboard.owner-kpi-card` — top group-level KPIs.
- `owner-dashboard.dashboard-section` — titled section wrapper.
- `owner-dashboard.branch-performance-card` — per-branch comparison.
- `owner-dashboard.pipeline-card` — workflow pipeline status.
- `owner-dashboard.alert-panel` — items needing attention.
- `owner-dashboard.activity-timeline` — recent group activity.

The owner dashboard is the **only** surface allowed to compare multiple branches, and only for
branches the user is authorized to see (see Filter Standards → Branch filters).

## Branch Dashboard

Audience: branch admin / operational staff — scoped to the **active branch**. Component family
(`resources/views/components/branch-dashboard/`):

- `branch-dashboard.daily-summary-card`
- `branch-dashboard.queue-card`
- `branch-dashboard.workload-widget`
- `branch-dashboard.quick-action-panel`
- `branch-dashboard.inventory-alert-widget`
- `branch-dashboard.finance-alert-widget`

All data is resolved through `BranchContext` server-side; the branch dashboard shows no branch
selector.

## Inventory Dashboard

Audience: anyone with inventory access — branch-scoped, location-aware. Component family
(`resources/views/components/inventory/`):

- `inventory.kpi-card`
- `inventory.dashboard-section`
- `inventory.stock-value-card`
- `inventory.location-card`
- `inventory.low-stock-widget`
- `inventory.movement-timeline`

## Rules for dashboard building blocks

### KPI Cards
- Use the family's KPI component (`owner-kpi-card`, `inventory.kpi-card`); do not hand-roll.
- Anatomy: muted label (eyebrow) → large value (`text-2xl font-semibold tabular-nums`) → optional
  hint/delta.
- Default value color `text-gray-900`; switch to `text-emerald-700` / `text-amber-700` /
  `text-rose-700` only when the metric itself is good / watch / bad.
- Grid: `grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6` (operational) or
  `lg:grid-cols-3` (financial).

### Charts
- No charting library is permitted (no React/Vue/new deps). Render trends with inline
  CSS/SVG bars driven by Blade/Alpine, e.g. a `bg-teal-500` fill on a `bg-gray-100` track.
- If a true visualization is not feasible, present a compact, clearly-labeled summary **table**
  instead of a fake chart. Never label a table "chart" ambiguously.
- Every chart/visualization needs an empty state.

### Tables (on dashboards)
- Use compact summary tables inside `inventory.dashboard-section` / `dashboard-section`.
- Right-align and `tabular-nums` all numerics; primary identifier first.
- Keep dashboard tables short (top-N) and link to the full list ("View all").

### Summary blocks
- Use a section wrapper component (`*.dashboard-section`) with a title and optional header link.
- Summaries must be branch-scoped and label derived values ("Branch Total · derived from ledger").
- Pair every summary with an empty state describing what populates it.

---

# Card Standards

Canonical frame: `rounded-lg border border-gray-200 bg-white shadow-sm`.

## Header
Optional but standard for content/list cards:

```blade
<div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
    <div>
        <h3 class="text-base font-semibold text-gray-900">Products</h3>
        <p class="text-sm text-gray-500">{{ number_format($products->total()) }} in branch scope.</p>
    </div>
    <span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-xs font-medium text-sky-700">Branch Total Stock</span>
</div>
```
Header holds: title, optional count/subtitle, optional scope chip or header action.

## Body
- Padding `p-4`–`p-6`; or, for tables, the table sits flush and the body padding is zero with the
  table providing its own cell padding.
- One logical concern per card. Avoid nesting decorative cards inside cards; nested framing is
  allowed only for repeated item tiles inside a framed tool.

## Footer
- Used for pagination, totals, or footer actions: `border-t border-gray-200 px-4 py-3`.
- Pagination: `{{ $items->links() }}` in the footer; filters must be preserved
  (`->withQueryString()` server-side).

## Spacing requirements
- Between cards: `space-y-6` (or grid `gap-6`).
- Inside a card: `space-y-4` for stacked sub-blocks.
- Card header/footer: `px-4 py-3`. Card body content: `p-4`–`p-6`.

---

# Table Standards

## Desktop tables
```blade
<div class="hidden overflow-x-auto md:block">
  <table class="min-w-full divide-y divide-gray-200 text-sm">
    <thead class="bg-gray-50">
      <tr class="text-left text-gray-500">
        <th scope="col" class="px-4 py-3 font-medium">Product</th>
        <th scope="col" class="px-3 py-3 text-right font-medium">Current Stock - Branch Total</th>
        <th scope="col" class="px-3 py-3 font-medium">Stock Status</th>
        <th scope="col" class="px-4 py-3 text-right font-medium">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <tr class="hover:bg-gray-50"> ... </tr>
    </tbody>
  </table>
</div>
```
Rules: `<th scope="col">` on every header; `bg-gray-50` thead; primary identifier first;
`tabular-nums` + `text-right` on numerics; `hover:bg-gray-50` rows; wrap in `overflow-x-auto`;
row-level status tinting via `@class` (`bg-rose-50/40` out, `bg-amber-50/40` low,
`bg-gray-50/60` inactive) plus an optional colored left accent bar.

## Mobile tables
The desktop table is hidden on small screens (`hidden md:block`) and replaced by a **stacked card
list** (`md:hidden`), one `<article>` per row:

```blade
<div class="divide-y divide-gray-100 md:hidden">
  <article class="p-4">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-xs uppercase tracking-wide text-gray-500">{{ $product->code }}</p>
        <h3 class="mt-1 text-base font-semibold text-gray-900">{{ $product->name }}</h3>
      </div>
      @include('inventory._low-stock-badge', [...])
    </div>
    <div class="mt-4 grid grid-cols-2 gap-3 text-sm"> ...key figures in ring-1 tiles... </div>
    <div class="mt-4 flex flex-wrap gap-2"> ...actions as bordered buttons... </div>
  </article>
</div>
```
This **dual desktop-table / mobile-card pattern is mandatory** for primary list tables. Dense,
secondary tables may instead rely on horizontal scroll, but must never overlap text on mobile.

## Sortable tables
- Sorting is server-side; sort state lives in the query string and is preserved through
  pagination (`->withQueryString()`).
- Sortable headers render as buttons/links inside the `<th>`, with the current sort indicated by
  an arrow glyph and `aria-sort="ascending|descending"` on the `<th>`.
- Default ordering must be deterministic and documented per page (e.g. stock card =
  `movement_date ASC, id ASC`).

## Status columns
- Status is always a **badge** (see Badge Standards), produced by a shared partial/component —
  never an inline ad-hoc span per page.
- Inventory uses `@include('inventory._status-badge', ...)` (active/inactive) and
  `@include('inventory._low-stock-badge', ...)` (OK/Low/Out). Cross-module status (order, invoice,
  delivery, QC) should use a shared `<x-status-badge>` rather than per-page ternaries.
- Always pair color with text; provide an empty state row (`colspan` spanning all columns).

---

# Form Standards

Forms follow Controller → Form Request → Service. Views only render fields and errors; **no
business logic in Blade**. Use the Breeze primitives (`x-input-label`, `x-text-input`,
`x-input-error`, `x-primary-button`) themed to teal.

## Labels
- Every control has an associated label: `<label for="id">` ↔ input `id`, or `<x-input-label
  for="...">`. No label-less inputs; visually-hidden labels use `sr-only`.
- Label style: `text-sm font-medium text-gray-700`.

## Inputs
- Tailwind Forms plugin styling: `rounded-lg border-gray-300 text-sm focus:border-teal-500
  focus:ring-teal-500`. Full-width in single-column forms (`block w-full`).
- Selects for branch/location-sensitive data must list **only active records from the active
  branch** (server-prepared); never `::all()` in the view.
- Date inputs use `type="date"`; numeric inputs use `type="number"` with `step`/`min` and
  `tabular-nums` where displayed.

## Validation errors
- Inline, per field, immediately below the control:
  ```blade
  @error('clinic_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
  ```
- The shell's top-of-page error summary is a secondary aid, not the only signal.
- Repopulate with `old(...)` on every field.

## Required fields
- Mark required labels with a rose asterisk: `<span class="text-rose-600">*</span>`.
- Communicate requirement in the label, not by color alone.

## Sections
- Group related fields in a titled section card (`x-form-section`-style): bordered card with a
  section title + optional description, fields in a responsive grid (`grid gap-4 sm:grid-cols-2/3`).
- Submit/cancel actions are predictable and visible: primary (teal) submit on the right, ghost
  cancel link to its left.
- Show a **warning banner** adjacent to destructive/irreversible operations; disable or explain
  the form when required active records are absent (e.g. no active location → can't receive stock).

---

# Filter Standards

Filters live in their own bordered card above the list, as a single `GET` form that preserves
state in the query string.

```blade
<form method="GET" action="{{ route('...') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
  <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_auto_auto] md:items-end"> ... </div>
</form>
```
Submit = `bg-gray-900` "Apply"; Reset = bordered ghost link to the base route. Every control has
an associated label.

## Search
- `type="search"`, name `search`, descriptive placeholder ("Code, name, or category").
- Server-side, search is case-insensitive (`LOWER(...) LIKE`) and scoped to the active branch.

## Branch filters
- **Operational pages do NOT show a branch selector.** Branch is resolved server-side by
  `BranchContext`; a user must never choose or submit `branch_id` for branch-owned data.
- The **only** exception is multi-branch surfaces (owner dashboard / group reports). There a
  branch selector may appear, but it must list only branches the user is authorized for and be
  re-validated server-side — never trust the raw submitted value as the source of truth.

## Date filters
- Use `date_from` / `date_to` (`type="date"`); server validates the range via a Filter Request.
- Default sensible ranges for reports; show the active range in the header.

## Status filters
- Render the domain's enum as a `<select>` ("All status" + each status). Values come from model
  constants (e.g. `StockOpname::STATUSES`), never hard-coded in the view.
- The chosen status is reflected back in the control (`@selected(...)`).

---

# Badge Standards

Shape: `inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium`. Always
text + color; never color alone. Produced by shared partials/components, not per-page spans.

| Variant | Classes | Applies to |
|---|---|---|
| **Success** | `bg-emerald-50 text-emerald-700` | Active, OK, Paid, Passed, Delivered |
| **Warning** | `bg-amber-50 text-amber-700` | Pending, Low stock, Partially paid, Due soon |
| **Danger** | `bg-rose-50 text-rose-700` | Out of stock, Overdue, Rejected, Remake |
| **Info** | `bg-sky-50 text-sky-700` | Received, Issued, In delivery, scope chips |
| **Draft** | `bg-gray-100 text-gray-600` | Draft, not-yet-issued |
| **Completed** | `bg-emerald-50 text-emerald-700` | Completed, Finalized (success family) |
| **Cancelled** | `bg-gray-100 text-gray-600` | Cancelled, Void, Inactive (neutral family) |

Reference implementations: `inventory/_status-badge.blade.php` (Active/Inactive) and
`inventory/_low-stock-badge.blade.php` (OK/Low/Out). For cross-module workflow status (Lab Order,
Invoice, Delivery, QC), standardize on a shared `<x-status-badge :domain :value>` that maps each
status to a variant above — replacing the legacy inline blue/indigo badges in
`lab-orders/index`, `production/board`, and `invoices/index`.

Color semantics (architecture rule): green/teal = healthy/positive, amber = warning, red/rose =
danger/irreversible, gray/slate = neutral metadata.

---

# Empty State Standards

Every list, table, dashboard widget, and timeline must have an empty state. It must state what is
missing, avoid implying fake data, avoid blaming the user, and offer an authorized next action
only when appropriate.

Canonical (table, in a full-span row):
```blade
<tr>
  <td colspan="7" class="px-4 py-12">
    <div class="mx-auto max-w-sm text-center">
      <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
        <span class="text-lg font-semibold">0</span>
      </div>
      <p class="mt-3 text-sm font-medium text-gray-900">No products found.</p>
      <p class="mt-1 text-sm text-gray-500">Create materials before receiving stock or recording opening balances.</p>
      {{-- optional, permission-gated next action --}}
    </div>
  </td>
</tr>
```
Structure: muted icon/marker → **bold what-is-missing line** → **muted explanatory line** →
optional authorized CTA. Dense secondary tables may use a single centered muted line
(`px-3 py-6 text-center text-gray-400`), but primary lists use the full structure above.

---

# Inventory UI Standards (Sprint 12 conventions)

Sprint 12 established the most current UI conventions; `inventory/products/index` is the
reference. New inventory UI must follow these:

- **Shell & header:** `<x-settings-shell>` + eyebrow (`text-teal-700`) + title + derived-stock
  description + permission-gated primary action.
- **Cards:** `rounded-lg border border-gray-200 bg-white shadow-sm` with `border-b`/`border-t`
  header/footer bands.
- **Lists:** the mandatory dual **desktop table (`hidden md:block`) + mobile card list
  (`md:hidden`)** pattern, with `<th scope="col">`, `bg-gray-50` thead, `tabular-nums` numerics,
  `hover:bg-gray-50`, and status row-tinting.
- **Stock display is ledger-derived and labeled.** Columns/figures show "Current Stock - Branch
  Total" / "derived from the movement ledger". Never present a stored stock column; there are none
  (architecture rule: no mutable stock columns).
- **Location awareness:** stock, stock card, and operations are filtered by
  `inventory_location_id`; selectors list only active locations in the active branch. Location
  filters must never leak another branch's stock rows or stock card.
- **Status badges:** `_low-stock-badge` (OK/Low/Out → emerald/amber/rose) and `_status-badge`
  (Active/Inactive). Inactive products/locations are visually de-emphasized and their stock
  operations are hidden/disabled with an explanation.
- **Stock operation forms** (opening, receive, adjust-in, adjust-out) must show: product summary,
  operation type, required location selector, quantity guidance, cost guidance (opening/receive),
  a **warning banner for adjustment-out**, and a ledger-derived stock explanation. Adjustment-out
  UI must make insufficient-stock failures clear (the rule is enforced in the service).
- **Dashboard:** use the `inventory.*` component family (`kpi-card`, `dashboard-section`,
  `stock-value-card`, `location-card`, `low-stock-widget`, `movement-timeline`).
- **Legacy to migrate:** `inventory/stock/index` still uses indigo focus, `bg-gray-800` buttons,
  `sm:rounded-lg` cards, no mobile cards, and a thin empty state — converge it on this standard
  when next touched.

---

# Responsive Rules

Mobile-first. Base classes target small screens; add `sm:`/`md:`/`lg:` upward. Do not use
viewport-based font scaling.

## Desktop (`lg`, ≥1024px)
- Sidebar pinned (`lg:static`). Content `max-w-7xl`.
- KPI grids up to `lg:grid-cols-6`; dashboard panels `lg:grid-cols-2`.
- Full desktop tables visible (`md:block`).

## Tablet (`sm`–`md`, 640–1023px)
- KPI grids `sm:grid-cols-3`; form grids `sm:grid-cols-2/3`.
- Tables: from `md` up show the desktop table; below `md` show stacked cards.
- Filter bars wrap (`flex-wrap` / responsive grid).

## Mobile (`< sm`, <640px)
- Sidebar is off-canvas/toggleable — never a fixed panel covering content with no way to reach the
  page. Action buttons stay reachable; form controls go full-width.
- Primary lists render as the **stacked card layout** (`md:hidden`); dense secondary tables use
  horizontal scroll inside `overflow-x-auto` with no text overlap.
- KPI grids collapse to `grid-cols-2`.

---

# Accessibility Rules

## Contrast
- Body text `text-gray-700`/`gray-900` on white/`gray-50`; never lighter than `text-gray-500` for
  meaningful content. Badge fg/bg pairs use `-700` on `-50` (meet WCAG AA).
- Never communicate state or risk by color alone — always include text/iconography.

## Keyboard navigation
- All interactive elements reachable and operable by keyboard; logical tab order.
- Visible focus on everything: `focus:ring-2 focus:ring-teal-500 focus:ring-offset-2` (buttons),
  `focus:border-teal-500 focus:ring-teal-500` (inputs). Never remove an outline without a
  replacement.
- Disclosure controls (sidebar toggle, dropdowns, modals) set `aria-expanded`/`aria-controls`;
  modals trap focus and close on `Esc`. Use real `<button>`/`<a>` elements.

## Labels
- Every form control has an associated `<label for>` (or `sr-only` label). Icon-only controls have
  `aria-label`. Tables use `<th scope="col">`; sortable headers use `aria-sort`. Provide a
  skip-to-content link before `<main>`. `<nav>` landmarks are labeled.

---

# UX Rules

## Always
- **Show context.** Every page states where you are (eyebrow + title) and the active scope
  (branch/location), and labels derived values as derived.
- **Show status.** Every record exposes its state via a semantic badge + text; lists and
  dashboards make "what needs attention" obvious.
- **Show validation.** Surface field-level errors inline, repopulate input, and warn before
  destructive/irreversible actions.

## Never
- **Hide critical actions.** Primary and workflow actions are visible (permission-gated), not
  buried; destructive actions are clearly labeled, never disguised.
- **Create ambiguous statuses.** No status without a defined badge variant; never two visually
  identical badges for different states (e.g. PAID vs OVERDUE must differ); never label a table a
  "chart" or imply fake/derived numbers as stored truth.

---

# AI UI Implementation Checklist

Before building UI:
- [ ] Read this document, `architecture_rules.md` §UI, and `ai_development_guide.md` §UI/UX.
- [ ] Confirm scope: UI-only? Which module/views? Any new component needed, or does a family
      component already exist (`owner-dashboard.*`, `branch-dashboard.*`, `inventory.*`, Breeze)?
- [ ] Identify the active-branch scope; confirm no branch selector on operational pages.
- [ ] Identify required permissions for every action to gate (`@can`/`@canany`/`@role`).

While building UI:
- [ ] Page rendered inside `<x-settings-shell title="...">`.
- [ ] Header = eyebrow + title + description + permission-gated action(s).
- [ ] Cards use `rounded-lg border border-gray-200 bg-white shadow-sm`; teal primary; no indigo.
- [ ] Lists use the dual desktop-table + mobile-card pattern; `<th scope>`, `tabular-nums`,
      `hover:bg-gray-50`, status tinting.
- [ ] Status shown via shared badge partial/component (not inline ad-hoc spans).
- [ ] Every table/widget/timeline has a compliant empty state.
- [ ] Forms: associated labels, inline `@error`, `old()` repopulation, required `*`, teal focus,
      destructive warning banner; selects show only active branch records.
- [ ] Filters in a bordered `GET` card; query string preserved through pagination.
- [ ] No business logic, repository, or service calls in Blade; no `::all()`/N+1 in views.
- [ ] Responsive verified at mobile / tablet / desktop; sidebar reachable on mobile.
- [ ] Accessibility: focus rings, labels, `scope`, keyboard operability, contrast.

Before finishing:
- [ ] Add/adjust UI tests when Blade output changes (`tests/Feature/<Module>/...UiTest.php`).
- [ ] Run requested gates (`php artisan test`, `npm.cmd run build`, `.\vendor\bin\pint`); report
      honestly.

# AI UI Review Checklist

- [ ] Uses existing shell, sidebar, and component families; no parallel shell or duplicated nav.
- [ ] Permission-aware: no links/actions to unauthorized routes; actions gated with the route's
      permission.
- [ ] Branch-truthful: operational pages have no branch selector; selectors show only active
      branch records; no cross-branch leakage in lists/dashboards/exports.
- [ ] Teal primary + semantic palette; no stray indigo or `bg-gray-800` in new code.
- [ ] Cards/tables/badges/empty states match the standards above; numerics `tabular-nums`.
- [ ] Primary lists implement the dual desktop/mobile pattern; tables wrapped for overflow.
- [ ] Every status uses a defined badge variant; no ambiguous or color-only statuses.
- [ ] Forms validate via Form Requests; inline errors; destructive actions warned; no `prompt()`.
- [ ] No business logic / queries / service calls in Blade; no N+1 from views.
- [ ] Accessibility: labels, `scope`, focus, keyboard, contrast; no color-only signaling.
- [ ] Inventory: stock presented as ledger-derived and labeled; no stored-stock assumption;
      location filters scoped; adjustment-out warning present.
- [ ] Responsive on mobile/tablet/desktop; UI tests updated; gates run and reported.

---

# Future Sprint UI Guidance

General rule for all future sprints: reuse this design system, the existing component families,
and the architecture flow. Branch is resolved server-side; stock stays ledger-derived; status is
always badged; every list has desktop + mobile layouts and an empty state.

## Stock Opname (Sprint 13)
- **List** (`inventory/stock-opnames/index`): dual desktop/mobile list of opname documents —
  columns Opname #, Location, Date, Status badge, Items count, Actions. Status badges: DRAFT →
  Draft(gray), COUNTING → Warning(amber), COMPLETED → Completed(emerald), CANCELLED →
  Cancelled(gray). Branch-scoped; location filter + status filter; no branch selector.
- **Create/Count** (`.../create`, `.../edit`): header with location selector (active locations in
  active branch only); item lines showing **System Qty (derived, read-only)**, **Counted Qty
  (input)**, and live **Variance** (counted − system) with sign coloring
  (emerald positive / rose negative / gray zero). System Qty is labeled "derived from ledger" and
  never editable.
- **Finalize:** a clearly-warned, irreversible action (`<x-modal>` confirm + warning banner)
  explaining that finalizing posts `ADJUSTMENT_IN/OUT` ledger movements for each variance. Draft
  counts must visibly **not** be a stock source of truth. Disable finalize when no active location
  or no counted lines.
- **Detail/show:** read-only document with per-line variance and the resulting movements once
  finalized.

## Purchasing (Sprint 15)
- Purchase Order list + form scoped to active branch; supplier selector lists only active
  suppliers in the active branch. PO line items with qty and expected cost; PO totals
  `tabular-nums`.
- **PO does not change stock.** UI must make explicit that a PO is intent only — show a status
  badge (Draft / Issued / Received / Cancelled) and never display a PO as increasing inventory.
  Stock increases happen only at Goods Receipt (a separate ledger movement). Warn on
  void/cancel of an issued PO.

## HR
- A separate **HR** module surface (own sidebar group, permission-gated). Employee directory as a
  dual desktop/mobile list; branch-owned employees scoped via `BranchContext` with `is_active`
  lifecycle (deactivate, don't delete). Employee detail uses titled section cards (personal,
  employment, branch assignment). No coupling shown to production/payroll except via clearly
  labeled related-records panels.

## Attendance
- Branch- and employee-aware. Present attendance as an **event log** (immutable records), not an
  editable daily-summary grid — daily/period summaries are clearly labeled as **aggregates**
  derived from the log, mirroring the ledger-derived inventory principle.
- Day/period views: read-only summary cards + a scrollable event table (clock-in/out events) with
  status badges (Present / Late / Absent / Leave). Filters: date range, employee, status. No
  branch selector for branch staff.

## Payroll
- Most sensitive surface — strict permission gating, prominent scope/context, and auditable
  presentation. Payroll runs shown as **locked period snapshots**: a run has a status badge
  (Draft / Calculated / Approved / Paid) and, once approved/paid, is read-only.
- Payslip/run detail uses section cards with `tabular-nums` money columns and visible references
  to the source attendance period (without mutating attendance). Calculations must be presented as
  reproducible and auditable; warn clearly before approving/locking a run. No fabricated figures.

---

*This design system is documentation only. It defines how ADLMS UI must look and behave; it does
not itself change application code. Implementations must reuse the existing layout shell, sidebar,
component families, and Breeze primitives, and stay within the Laravel + Blade + Tailwind +
Alpine stack with no new front-end framework.*

## Inventory analytics & workflow-form standard (UIX-9)

Deferred inventory analytics/chart, workflow form, and workflow detail surfaces follow the same
canonical component set as the list (UIX-3) and cashier (UIX-5) standards:

- **Analytics / dashboard pages** — `x-ui.page-header` for the header, `x-ui.card` (or the shared
  `x-inventory.dashboard-section`) to group each block, `x-inventory.kpi-card` for KPI tiles,
  `x-ui.alert` for methodology/notes, `x-ui.button` for actions. Charts stay HTML/table + Tailwind
  (no heavy chart dependency). Gold is reserved for revenue accents only — never an inventory CTA.
- **Workflow detail pages** (PR/PO/GR/transfer/opname show) — `x-ui.page-header` with the action
  cluster in the `actions` slot as `x-ui.button` variants (submit/approve=success, submit-for-review
  =warning, send/receive=primary, cancel/void=danger, edit/back=secondary); status via the domain
  `_status-badge` partial (which routes through `x-ui.badge`); "does-not-change-stock" notes via
  `x-ui.alert`.
- **Workflow form pages** (`create`/`edit`/`_form`) — `x-ui.page-header`, form wrapped in
  `x-ui.card`, fields via `x-ui.input`/`x-ui.select`/`x-ui.textarea` (auto validation-error
  display), footer actions as `x-ui.button`. Custom widgets like
  `x-inventory.searchable-product-select` are preserved.
- **Ledger rule (non-negotiable):** presentation views never write a mutable stock attribute
  (`current_stock`, `qty_on_hand`, …). Stock stays ledger-derived; the governance command scans for
  this. Semantic stock-status colours (empty/low/overstock, masuk/keluar) are preserved.

`architecture:ui-governance-check --strict` enforces the reference surfaces
(`inventory/analytics/index`, `inventory/executive-dashboard`, `inventory/purchase-orders/show`,
`inventory/products/_form`) and the no-teal / no-gold-CTA / no-mutable-stock invariants.

## RME daily operator surfaces (UIX-10)

The RME operator pages (queue, room worklist, visit create/edit, dashboard,
online-context, RM lookup, workspace nav) follow the shared standards:

- **Queue / worklist list pages** — `x-ui.page-header` + `x-ui.filter-bar`
  (`x-ui.input`/`x-ui.select`, same GET params) + `x-ui.table` +
  `x-ui.badge :status` + `x-ui.button` + `x-ui.empty-state`. `rme/patient-queue/index`
  (Antrian Pasien) is the reference RME queue page.
- **Visit forms** (`create`/`edit`) — `x-ui.page-header`; the edit form uses
  `x-ui.select`/`x-ui.textarea`. The large `_form.blade.php` partial keeps its
  Alpine (patient-mode / follow-up / online-doctor / RM-preview) and field names;
  it is palette-normalized only.
- **Privacy (non-negotiable):** no RME operator view renders a full KTP/NIK/
  identity number. The visit create form may *input* a KTP field (never rendered
  back). The governance command scans for `->ktp/nik/identity_number` echoes and
  legacy `teal-*` / `variant="gold"` CTAs across these files.
- **Workflow safety:** room-gate lock state on `visit-workflow-nav`, the
  assign-room form, and every status/transition control are presentation-only —
  no RME workflow, doctor→cashier gate, or room-gate behavior is changed here.

`architecture:ui-governance-check --strict` enforces the RME reference surfaces
(`rme/patient-queue/index`, `rme/visits/room-worklist`, `rme/visits/create`,
`rme/visits/edit`) and the no-teal / no-gold-CTA / no-rendered-KTP invariants.

## RME clinical documentation & print bundle (UIX-11)

The doctor-facing RME clinical documentation surfaces (medical record list/
detail/empty, odontogram detail) and the print/PDF bundle follow the shared
standards. This is **presentation-only** — none of the clinical workflow rules
below may be changed by a UI sprint.

- **Clinical detail pages** (`rme/visits/medical-record/show`,
  `rme/visits/odontogram/show`) — `x-ui.page-header` (breadcrumb + status badge /
  actions in the actions slot) + `x-ui.card` context blocks + `x-ui.badge` +
  `x-ui.button` + `x-ui.alert` for every state banner (finalized notice,
  follow-up, finalize gate, opened-from-later-visit). Medical record detail is
  the reference clinical documentation page.
- **Clinical list page** (`rme/visits/medical-record/index`) — the UIX-3 list
  standard (`x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` + `x-ui.badge` +
  `x-ui.button` + `x-ui.empty-state`, same GET params).
- **Handwriting area / canvas** — presentation-only. The Alpine canvas `<script>`
  bakes the official Daengtisia RM paper template (neutral ink hex) into the saved
  PNG; **never retokenize the canvas drawing/template colors** — they drive print
  parity and the saved image. Capture / save / page-nav / finalize-guard logic is
  untouched.
- **Odontogram tooth map / DMF-T** — the FDI visual and DMF-T tally are generated,
  read-only outputs of the saved table. **Clinical status colours are preserved
  on purpose** (karies=red, tambalan=blue, hilang=gray, crown=amber, PSA=sky,
  normal=green) for distinguishability and print parity — they are NOT legacy teal
  and must stay.
- **Print / PDF bundle** (`rme/visits/print`, `rme/visits/print-pdf`,
  `rme/visits/odontogram/print`) — table-based / dompdf-safe; chrome teal hex
  (`#0f766e`/`#0d9488`) → brand hex (`#1D4ED8`/`#1E40AF`); semantic status badge
  colours preserved. Print templates are the only clinical surface where inline
  brand hex is expected (UIX-8 precedent). No flexbox-only PDF layout.
- **Privacy & workflow (non-negotiable):** no clinical view / print / PDF renders
  a full KTP/NIK/identity number; medical-record and odontogram **finalization
  logic**, the **handwriting-PNG-required-before-finalize** rule, the **SOAP-hidden**
  rule, the **doctor-cannot-complete-visit-directly** rule (visit `completed` is
  cashier/payment-driven), and the **room gate (`visit.room`)** are all unchanged.

`architecture:ui-governance-check --strict` enforces the clinical reference
surfaces and the no-teal / no-gold-CTA / no-rendered-KTP invariants (including a
residual-teal-hex scan on the print templates).

## RME cashier / receivable financial-summary standard (UIX-12)

The RME financial operator surfaces (cashier list/detail, payment, receivable
list, carry-over, follow-up) follow the shared standards, and the *money state*
of an invoice is presented through one canonical card:

- **`x-rme.invoice-summary`** (`components/rme/invoice-summary.blade.php`) — the
  standardized financial summary row: **Total Tagihan / Sudah Dibayar / Sisa
  Tagihan / Status Tagihan**. It is **presentation-only** — it reads existing
  invoice accessors (`grand_total`, `paidAmount()`, `remainingAmount()`,
  `status`) and never recomputes, allocates, or mutates a value. Reuse it on
  every invoice/payment/receivable detail surface so the remaining amount is
  always visible and partial payment is always clearly labeled. Status maps all
  five states — `DRAFT` (info), `UNPAID`/`PARTIAL` (warning), `PAID` (success),
  `VOID` (danger) — via `x-ui.badge`. Money is `tabular-nums`; a settled
  (remaining Rp 0) balance reads calm/positive, an outstanding balance reads as
  a warning.
- **Financial list pages** (cashier index, receivables) — the UIX-3 list
  standard (`x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` +
  `x-ui.badge :status` + `x-ui.button` + `x-ui.empty-state`, same GET params).
  Every invoice-status badge maps all five statuses (`PARTIAL` included).
- **Consent gate** — surfaced via `x-ui.alert variant="warning"`; the field
  names (`consent_signed_by_patient` / `consent_signed_by_doctor`) and
  server-side validation are **never** changed by a UI sprint.
- **Financial rules (non-negotiable):** no cashier/receivable/follow-up UI sprint
  may change invoice/payment calculation, invoice-status behavior (`DRAFT`,
  `UNPAID`, `PARTIAL`, `PAID`, `VOID`), partial-payment behavior, remaining-amount
  calculation, receivable/aging logic, carry-over allocation, the consent gate,
  Lab billing (no `trx_payments` from RME), or the cashier/payment-driven visit
  completion (doctor cannot complete a visit directly). No full KTP/NIK is ever
  rendered/exported/logged on a financial surface.

`architecture:ui-governance-check --strict` enforces the `x-rme.invoice-summary`
component, its reuse on the invoice detail (`rme/cashier/show`) and payment
(`rme/cashier/payment/create`) surfaces, and the no-teal / no-gold-CTA /
no-rendered-KTP invariants across the cashier surfaces.

## Owner dashboard & executive-KPI standard (UIX-13)

The Owner landing (`dashboard.blade.php`) is the reference **executive dashboard**
and the branch-admin dashboard (`dashboards/branch-admin.blade.php`) follows the
same foundation. Every owner/executive/branch dashboard surface is **strictly
read-only** and built from the shared components + semantic tokens:

- **KPI cards** — the owner KPI block uses `x-ui.kpi-card` (UIX-2); domain KPI
  grids reuse the existing `x-owner-dashboard.owner-kpi-card` /
  `x-branch-dashboard.daily-summary-card` partials. Gold (`accent`) is reserved
  for the revenue KPI only and is **never** a CTA/button variant.
- **Read-only period / branch filter** — the branch (and any period/date) filter
  is a `GET` form on `x-ui.select` / `x-ui.input` that submits to the same route;
  the `branch_id` (and period) request semantics are never changed by a UI sprint,
  and `branch_id` is never trusted from the request (branch scope stays server-side).
- **Branch drilldown summaries** — hand-rolled dashboard tables are replaced by
  `x-ui.table` (thead `bg-navy-50` / `text-ink-soft`, tbody `divide-hairline`,
  money/counts `tabular-nums`) and `x-ui.empty-state` for no-data states.
- **Executive summary / hero / fallback** — `x-ui.card` (`accent` for the hero),
  `x-ui.button` for read-only drilldown affordances, `x-ui.empty-state` for
  empty/error sections.
- **Dashboard rules (non-negotiable):** no dashboard UI sprint may change any KPI
  calculation, `OwnerDashboardKpiService` / controller / service / repository
  behavior, period/date filtering semantics, branch drilldown semantics, branch
  isolation / cross-branch read rules, or owner-dashboard permission checks
  (`view_owner_dashboard` / `view dashboard`); Supervisor RME gains no
  owner-dashboard bypass. The dashboard stays read-only (no create/update/delete
  mutation, no mutating form). No PII / full KTP/NIK / scans / raw clinical notes
  are ever rendered. No heavy chart/BI dependency is added — Blade + Tailwind +
  Alpine only. No expensive query is changed without evidence + tests.

`architecture:ui-governance-check --strict` enforces the owner landing +
branch-admin views on `x-ui.table` / `x-ui.empty-state` / `x-ui.select`, and the
no-legacy-palette / no-gray / no-hardcoded-hex / no-gold-CTA / read-only (no
mutating form) / no-rendered-KTP invariants across both dashboard surfaces.

## Settings, master-data & access-control standard (UIX-14)

The Settings / master-data / access-control **index pages** are the reference admin
list surface (`settings/clinic-rooms/index` for master data, `settings/users/index`
for access control), and `settings/clinic-rooms/_form` is the reference master-data
form. Every settings/admin list + form page renders inside `<x-settings-shell>` (the
shell provides the page-header band + flash/validation) and is built from the shared
components + semantic tokens:

- **Filters** — a `GET` `x-ui.filter-bar` wrapping `x-ui.input` / `x-ui.select`
  fields (same request params as before) with `Terapkan`/`Cari` + `Atur Ulang`
  (`x-ui.button variant="ghost"`) actions. Filter/search request semantics are never
  changed by a UI sprint.
- **Tables** — `x-ui.card` (`p-0`) + a header row (title + permission-gated
  `+ Tambah…` action) + `x-ui.table` (thead `bg-navy-50` / `text-ink-soft`, tbody
  `divide-hairline`). Status / role / flag columns use the shared `x-ui.badge` tone
  map (never inline ad-hoc spans). Every list has an `x-ui.empty-state` (with the
  permission-gated create action) for the no-data state.
- **Action / danger hierarchy** — `x-ui.button variant="secondary"` for Ubah,
  `variant="danger"` for Hapus/destroy (wrapped in a POST + `@method('DELETE')` +
  `confirm()` form, unchanged), `success`/`warning` for activate/deactivate. Save/
  cancel forms use a primary `x-ui.button` submit + `ghost` Batal, separated by a
  `border-t border-hairline` footer. Gold is **never** a settings CTA.
- **Forms** — the reference master-data form uses `x-ui.input` / `x-ui.select` /
  `x-ui.textarea` (inline validation via the shared error bag; the shell also renders
  the aggregate `$errors` band). Complex forms (role permission matrix, user password)
  keep all field names, Alpine logic, and danger sections verbatim — polish is
  palette/token only (legacy indigo/teal → brand, red action → danger, amber →
  warning).
- **WA reminder templates** — the list keeps its **manual-only** safety notice
  (`x-ui.alert variant="warning"`, "belum mengirim WhatsApp otomatis") and the
  variable-reference note (`x-ui.alert variant="info"`). WA is a manual copy-paste
  SOP: no auto-send, no WhatsApp API/vendor, no new automation.
- **RBAC / master-data rules (non-negotiable):** no settings/admin UI sprint may
  change any permission or role (add/remove/rename), Spatie Permission behavior,
  `Gate::before`, policy, or route middleware; branch scope stays server-side and
  `branch_id` is never trusted from the request. Master-data semantics
  (treatment/tariff/payment-method/clinic-room/branch, tariff uniqueness &
  effective-date) are unchanged, and no controller/service/repository/route/schema/
  migration is touched. No full KTP/NIK, scans, raw notes, secrets, tokens, or env
  values are ever rendered.

`architecture:ui-governance-check --strict` enforces the 10 settings list views on
`x-ui.filter-bar` / `x-ui.table` / `x-ui.badge` / `x-ui.button` / `x-ui.empty-state`,
the clinic-rooms form reference on `x-ui.input`/`select`/`textarea`, the
no-legacy-palette / no-gray / no-hardcoded-hex / no-gold-CTA / no-rendered-KTP
invariants across the list views (plus a teal/indigo/hex/gold-CTA/KTP scan on the
settings/access forms), and that the WA reminder list keeps its manual-only notice.

## Global component foundation hardening (UIX-15)

UIX-15 hardened the shared `x-ui.*` foundation built across UIX-1 → UIX-14 so it
stays the **single** design system. Foundation-hardening only — no page rewrite, no
mass conversion, no business/permission/route/controller/service/schema change,
Blade + Tailwind + Alpine only, no new UI/JS/CSS dependency, no PII/KTP/NIK/secret
exposure. All changes are additive and **backward compatible** — no public prop was
renamed or removed.

### Canonical status badge — `x-ui.badge`

`x-ui.badge` is the one place that maps a domain status to a semantic tone. As of
UIX-15 the `status` lookup is **case-insensitive** (the value is lowercased before the
map lookup), so uppercase lifecycle codes resolve to the same tone as their lowercase
form — a `strtolower(...)` wrapper at the call site is no longer required. Unknown
statuses still fall back to the safe `neutral` tone.

Canonical status → tone map (extended; none of the pre-existing mappings changed):

| Tone | Statuses |
| --- | --- |
| `neutral` | `draft`, `normal`, *(unknown)* |
| `warning` | `waiting`, `pending`, `qc`, `low_stock`, `expired_soon`, `unpaid`, `partial`, `overstock` |
| `info` | `in_progress`, `info`, `registered`, `submitted` |
| `gold` (accent) | `cashier_pending` |
| `success` | `paid`, `approved`, `completed`, `delivered`, `success`, `posted`, `received` |
| `danger` | `cancelled`, `rejected`, `out_of_stock`, `expired`, `void`, `danger` |

Use `:status` (semantic, preferred) whenever the value is one of these lifecycle
codes; use `tone` only for a bespoke one-off. Gold stays reserved for the
`cashier_pending` financial handoff — it is never a CTA.

### Foundation table tokens — `x-ui.table`

`x-ui.table` now uses the `divide-hairline` divider and `text-navy` caption tokens
(the residual legacy `divide-gray-200` / `text-gray-900` drift was removed) so the
foundation table matches the tokens the list pages already use around it.

### Domain component boundaries (documented, kept domain-specific)

Some components are intentionally **domain-specific** and must not be folded into
`x-ui.*`, but they **route through the foundation** rather than fork a second system:

- **`x-lab.status-badge`** (UIX-7) — maps the Lab pipeline's uppercase
  lifecycle/priority/QC/delivery codes to an Indonesian label + tone, then renders
  through `x-ui.badge`. Presentation-only, no lifecycle logic.
- **`x-rme.invoice-summary`** (UIX-12) — the standard Total / Sudah Dibayar / Sisa
  Tagihan / Status financial-summary grid. Reads existing invoice accessors
  (`grand_total`, `paidAmount()`, `remainingAmount()`, `status`) and renders the
  status through `x-ui.badge`; it never computes, mutates, or changes any
  payment/receivable/invoice value.
- **`x-inventory.*`** (UIX-6/UIX-9) — inventory KPI/alert/timeline widgets that use
  foundation tokens; they never write a mutable stock attribute (stock stays
  ledger-derived).

Rule: a domain component may add domain labels/tones/layout, but the **visual tone of
danger / success / warning / status and the button/badge/alert primitives always come
from the `x-ui.*` foundation** — no forked palette, no second design system.

### Governance (UIX-15)

`architecture:ui-governance-check --strict` additionally enforces: `x-ui.badge`
resolves status case-insensitively and maps the canonical financial statuses
(`unpaid`/`partial`/`void`/`paid`); `x-ui.table` carries no legacy gray class and
uses `divide-hairline`; and the domain badge components (`x-lab.status-badge`,
`x-rme.invoice-summary`) render through `x-ui.badge`. Print/PDF stays dompdf
table-safe (no flex layout in print templates), unchanged by this sprint.

## Responsive, tablet & operator standard (UIX-16)

DaengtisiaMS is operated on laptops and tablets (landscape 1024px and portrait
768px) as well as desktops (1366px+). The foundation must stay usable at those
widths without a horizontal body scrollbar or lost actions. UIX-16 hardens the
shared `x-ui.*` foundation and the highest-frequency operator surfaces to a fixed,
durable set of responsive rules. **These are presentation-only: no route, policy,
query, permission, schema, financial, RME, Inventory, Lab, dashboard, or master-data
behaviour changes with responsive polish.**

### Responsive foundation rules (must hold in every future UI sprint)

- **Table overflow rule.** Every data table renders through `x-ui.table`, which wraps
  the table in an `overflow-x-auto` scroll container. Wide tables scroll *inside their
  own container* — they never widen or break the page body. Never emit a raw `<table>`
  outside this container on an operator surface.
- **Filter-bar wrapping rule.** `x-ui.filter-bar` stacks its fields (`flex-col`) on
  narrow widths and lays them out horizontally from `md` up (`md:flex-row md:flex-wrap`);
  its action group wraps (`flex-wrap`) so submit/reset buttons never overflow.
- **Page-header action wrapping rule.** `x-ui.page-header` stacks the title and action
  slot (`flex-col`) on narrow widths and goes horizontal from `sm` up (`sm:flex-row`);
  the action group wraps (`flex-wrap`) so multiple actions never overflow the header.
- **Button group wrapping rule.** Action/button groups (`x-ui.card` actions, filter
  actions, page-header actions) wrap on narrow widths rather than forcing a fixed row.
- **Card grid / detail-grid stacking rule.** Detail and summary grids (`<dl>` label/value
  pairs, KPI/summary cards) use a `grid-cols-1` base and only widen from `sm`/`md` up
  (e.g. `grid grid-cols-1 gap-3 text-sm sm:grid-cols-2`). Never ship a fixed
  `grid-cols-2`/`grid-cols-3` *text-sm* detail grid with no stacking base — it crowds
  and overflows on tablet-portrait and phone widths. Desktop/tablet (≥640px) appearance
  is preserved because the wider column count still applies from `sm` up.
- **Form stacking rule.** Form fields use the `x-ui.input`/`x-ui.select`/`x-ui.textarea`
  primitives with `w-full` inside stacking containers so forms stack cleanly on narrow
  widths.
- **No hidden critical data.** Responsive polish never hides critical operational data
  (amounts, statuses, actions) without a safe responsive alternative (scroll container,
  stacked layout, or a wrapped action). Actions are never removed to “fit”.

### Constraints

- Tailwind responsive utilities only (`sm:`/`md:`/`lg:`/`xl:`) — **no** heavy responsive
  framework, datatable library, chart library, or new JS dependency. Blade + Tailwind +
  Alpine only.
- No sensitive-data exposure: KTP/NIK/scans/raw clinical notes/secrets/env values are
  never surfaced by a responsive change.

### Governance (UIX-16)

`architecture:ui-governance-check --strict` additionally enforces: `x-ui.table` keeps
`overflow-x-auto`; `x-ui.filter-bar` stacks (`flex-col` + `md:flex-row`) and wraps its
actions (`flex-wrap`); `x-ui.page-header` stacks (`flex-col` + `sm:flex-row`) and wraps
its actions (`flex-wrap`); and the representative operator surfaces (RME cashier
show/payment, inventory products/stock-card, lab case-candidate detail) carry no fixed
non-stacking `grid-cols-2`/`grid-cols-3` *text-sm* detail grid.

## Accessibility, error state & empty state standard (UIX-17)

DaengtisiaMS is operated all day by front-office, cashier, nurse, doctor, and
warehouse staff. Forms, validation, actions, and no-data states must be clear and
safe — including for keyboard and screen-reader users. UIX-17 hardens the shared
`x-ui.*` foundation with a fixed, durable accessibility contract. **These are
additive presentation/semantics changes only: no route, policy, permission, Gate,
BranchContext, query, schema, validation-rule, financial, RME, Inventory, Lab,
dashboard, or master-data behaviour changes. Server-side validation is never
replaced by frontend-only validation.**

### Accessibility foundation rules (must hold in every future UI sprint)

- **Explicit labels & field association.** Every field uses `x-ui.input`/`x-ui.select`/
  `x-ui.textarea` with a `label`; the label is tied to the control via `for`/`id`.
- **Validation error visibility.** Validation errors render close to the field, in the
  danger token, and are programmatically associated with the control via
  `aria-describedby="{id}-error"`; the invalid state is exposed with `aria-invalid="true"`.
  The form components resolve the error automatically from the `$errors` bag by `name`.
- **Required marker.** Backend-required fields carry a visible danger asterisk and
  `aria-required="true"`. The marker never invents a requirement the backend does not have.
- **Helper text.** Optional `help` text renders in the soft-ink token and is associated
  via `aria-describedby="{id}-help"` when there is no error. Helper text supplements — it
  never replaces the label.
- **Disabled / loading clarity.** `x-ui.button` disables + dims on `disabled`/`loading`,
  exposes `aria-disabled`, and on `loading` shows a spinner plus `aria-busy="true"` and a
  screen-reader-only "Memproses…" label so assistive tech announces the busy state.
- **Danger / destructive action clarity.** Destructive actions use `x-ui.button
  variant="danger"` (or a `danger` alert), so delete/void/cancel reads as high-risk.
- **Empty / no-data copy.** No-data states use `x-ui.empty-state` with a `title` and a
  `description` that explains what happened and what the operator can do next — without
  inventing actions the user is not allowed to take. Table no-data rows use a clear,
  full-width message.
- **Alert semantics.** Operator messages use `x-ui.alert` with a semantic variant
  (`info`/`success`/`warning`/`danger`) and keep `role="alert"` so they are announced.
- **Heading hierarchy.** Pages lead with `x-ui.page-header` (`<h1>`); section titles stay
  logically ordered and screen-reader-friendly.
- **No fake/noisy ARIA.** Add ARIA only when it adds semantics native HTML does not
  already provide. Never duplicate native behaviour or alter keyboard/submission behaviour.
- **No sensitive-data exposure.** Accessibility copy never surfaces KTP/NIK/scans/raw
  clinical notes/secrets/env values.
- **No formal WCAG claim.** These are pragmatic accessibility improvements; DaengtisiaMS
  does not claim formal WCAG conformance unless a real audit is performed.

### Constraints

- Blade + Tailwind + Alpine only — **no** accessibility framework, modal/form/datatable
  library, or new JS dependency.
- No business/validation/permission/query/data/route/policy/schema change.

### Governance (UIX-17)

`architecture:ui-governance-check --strict` additionally enforces: `x-ui.input`,
`x-ui.select`, and `x-ui.textarea` associate their error/help text via
`aria-describedby` with a stable `-error`/`-help` id and expose `aria-invalid` +
`aria-required`; `x-ui.button` exposes `aria-busy` with an `sr-only` loading label;
`x-ui.alert` keeps `role="alert"`; and `x-ui.empty-state` keeps a `description` so
no-data states are explained.
