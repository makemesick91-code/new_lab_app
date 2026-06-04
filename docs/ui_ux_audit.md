# UI/UX Audit — Asia Dental Lab Management System

> **Scope:** Read-only audit of the Blade/Tailwind/Alpine front-end across all modules
> (Lab Order, Production, Quality Control, Delivery, Invoice & Payment, Reporting,
> Multi-Branch, Inventory + Locations, and Settings/Master Data).
> **Constraint:** Documentation only — no Blade, route, or backend changes were made.
> **Date:** 2026-06-04

---

## 0. How the UI is assembled (current architecture)

| Layer | File | Notes |
|---|---|---|
| HTML shell | `layouts/app.blade.php` | Breeze default. Figtree font, `bg-gray-100`, optional `$header` slot. |
| Top nav | `layouts/navigation.blade.php` | Breeze default. **Only one link ("Dashboard")** + user dropdown + mobile hamburger. |
| Real nav | `layouts/sidebar.blade.php` | The actual app menu (permission-gated, `w-64`). Manually `@include`-d. |
| Module shell | `components/settings-shell.blade.php` | Wraps `app-layout` + sidebar + flash/error blocks. **Used by ~64 of all module pages**, not just Settings. |
| Design tokens | `tailwind.config.js` | Only adds the Figtree font. **No custom colors, spacing, or component layer.** |
| CSS | `resources/css/app.css` | Bare `@tailwind base/components/utilities`. No `@layer components`. |

**Core structural finding:** there is **no shared design system**. Every page is hand-assembled from raw Tailwind utility strings that are copy-pasted between files. Only Inventory introduced reusable partials (`_status-badge`, `_low-stock-badge`). This is the root cause of most consistency issues below.

---

## 1. Current UI Problems (by category)

### 1.1 Layout consistency — **Medium**
- **Two competing layout entry points.** `dashboard.blade.php` uses `<x-app-layout>` + a manual `@include('layouts.sidebar')`, while every other module uses `<x-settings-shell>` (which itself includes the sidebar). The sidebar is therefore included via two different code paths and is **not** part of the base layout.
- **Misleading component name.** `settings-shell` is the universal shell for Lab Orders, Production, Inventory, Reports, Invoices, etc. — the name implies it is Settings-only and discourages reuse/discovery.
- **Content width drift.** `settings-shell` constrains content to `max-w-5xl`; the bare `app-layout` header uses `max-w-7xl`. Wide tables (Invoices has 9 columns) are squeezed into `max-w-5xl`.
- **Positive:** card pattern (`bg-white shadow-sm sm:rounded-lg`), table structure, and filter-bar layout are genuinely consistent across modules.

### 1.2 Sidebar / navigation clarity — **High**
- **Redundant dual navigation.** The Breeze top bar still renders a logo + a single "Dashboard" link + user dropdown, duplicating the sidebar's Dashboard entry and wasting the entire top strip.
- **Stale placeholder / dead links leaking to users.** The sidebar still contains:
  - `Quality Control <span>(Sprint 5)</span>` → `href="#"` (for the QC role)
  - `My Orders <span>(Sprint 3)</span>` → `href="#"` (for the Doctor role)
  - A header comment describing items as "ROLE-BASED PLACEHOLDERS … point to #". Internal sprint jargon and dead anchors are visible in production navigation.
- **Ambiguous duplicate labels.** Three menu items are literally named "Dashboard" (top-level, Reports ▸ Dashboard, Inventory ▸ Dashboard) with no icon or qualifier to disambiguate.
- **No icons.** Text-only menu makes scanning a long, multi-section list (Settings, Master Data, Operations, Inventory, Finance, Reports) slow.
- **No Branch UI in nav.** Multi-Branch exists in the domain but has no navigation entry (consistent with it being a foundation, but worth flagging for the roadmap).
- **Permission-name inconsistency.** Menu gates mix conventions: space-style (`manage users`, `manage clinics`) vs snake_case (`view_lab_orders`, `view_inventory`, `view_invoice`). Observational, but a maintenance trap.

### 1.3 Mobile responsiveness — **High**
- **The sidebar is not responsive.** `settings-shell` renders `<div class="flex"> [sidebar w-64] [content] </div>` with no breakpoint handling. On phones the 256px sidebar permanently consumes ~70% of a 360px viewport, and it **cannot be collapsed** — the Breeze hamburger only toggles the top bar (which contains just "Dashboard"). Every module page is effectively unusable on mobile.
- **Positive:** within the cramped content column, the building blocks *are* responsive — KPI grids (`grid-cols-2 sm:grid-cols-3 lg:grid-cols-6`), filter bars (`flex flex-wrap`), and tables (`overflow-x-auto`) all adapt. The single blocker is the sidebar.

### 1.4 Table readability — **Medium**
- **No row affordances.** Tables have no `hover:bg-*` row state and no zebra striping, so the eye loses its place across wide rows (Invoices = 9 columns, Production = 8).
- **Inconsistent numeric emphasis.** Money columns are plain `text-gray-700`; nothing visually separates totals/outstanding from metadata.
- **Action columns are text links** ("View", "Edit", "Cancel", "Manage") with no icons or grouping convention — fine, but `Cancel` triggers a raw `prompt()` (see 1.9).
- **Positive:** header styling, padding (`px-3 py-2`), `divide-y`, right-aligned numerics, and `overflow-x-auto` wrappers are consistent and correct.

### 1.5 Form usability — **Medium/High**
- **Labels are not associated with inputs.** Across sampled forms (e.g. `lab-orders/_form`, `reports/dashboard` filters) labels are plain `<label>` with no `for`/`id` pairing — clicking a label doesn't focus its field, and screen readers can't map them.
- **No inline, field-level validation.** Errors render only as a single bulk list at the top of `settings-shell`. On the long Lab Order form (8+ fields plus a dynamic item repeater) the user must hunt for which field failed. Breeze's `<x-input-error>` exists but is unused in module forms.
- **Bilingual mixing.** The Lab Order form mixes English labels ("Clinic", "Order Date") with Indonesian ("Nomor RM", helper "Masukkan nomor rekam medis pasien"). No single language convention.
- **Unused Breeze form components.** `<x-text-input>`, `<x-input-label>`, `<x-primary-button>`, `<x-secondary-button>` all exist but module forms hand-roll raw `<input>`/`<select>`/`<button>` instead, so focus rings and styling drift (search inputs get `focus:ring-indigo-500`; selects and date inputs often don't).
- **Positive:** the Alpine-driven Lab Order item repeater (add/remove rows, `old()` repopulation) is well built and genuinely usable.

### 1.6 Empty states — **Low/Medium**
- Consistent but minimal: every empty table renders a single centered `text-gray-400` line ("No lab orders found.", "No stock movements yet.", "No data."). No icon, no explanation, and **no call-to-action** (e.g. an empty Lab Orders list should offer "+ Create Lab Order"). Functional, not helpful.

### 1.7 Status badges — **High** (most visible inconsistency)
There are **four different, uncoordinated** badge implementations for what should be one system:

| Where | Implementation | Problem |
|---|---|---|
| `lab-orders/index` | inline ternary: `CANCELLED → gray`, **everything else → blue** | 13 distinct order statuses collapse to a single blue. Can't distinguish IN_PRODUCTION from DELIVERED from REMAKE. |
| `production/board` | inline: **all statuses → blue** | Zero differentiation. |
| `invoices/index` | inline: **all statuses → indigo** | PAID, OVERDUE, and VOID look identical. Overdue/unpaid invoices don't stand out — a real finance risk. |
| `inventory/_status-badge`, `_low-stock-badge` | reusable partials, semantic colors (green/amber/red) | **Correct approach — but only Inventory has it.** |

Only Inventory communicates state through color. Operational and financial workflows — where status *is* the primary scanning task — do not.

### 1.8 KPI cards — **Medium**
- The Reports dashboard and Inventory dashboard render visually identical KPI cards, but the markup is **duplicated inline** in each file (no `<x-kpi-card>`). Color semantics are applied ad hoc (`text-green-700` revenue, `text-amber-700` warning, `text-red-700` danger) with no shared mapping.
- **"Charts" are not charts.** The Reports dashboard section labeled *Charts* renders six plain two-column HTML tables (Orders by Status, Revenue by Month, etc.). There is no visualization — a notable gap for a reporting dashboard.
- The post-login **landing `dashboard.blade.php` is still the Breeze placeholder** ("Welcome… You're logged in", "Feature menus in the sidebar are placeholders. They are wired up in later sprints."). The first screen every user sees is outdated boilerplate with no KPIs or actionable widgets, while the real dashboards live under Reports/Inventory.

### 1.9 Interaction / destructive actions — **Medium**
- Lab Order cancellation uses a native `onsubmit` **`prompt()`** to collect the reason. It is blocking, unstyled, un-themeable, inaccessible, and inconsistent with the app's look. A `<x-modal>` component already exists and is unused for this.

### 1.10 Accessibility — **High**
- **Almost no ARIA/semantics.** `aria-*`, `role`, and `sr-only` appear in essentially one file across the entire view layer. The mobile hamburger button has no `aria-label`/`aria-expanded`; icon-only/link actions have no accessible names beyond text.
- **Labels not programmatically associated** with controls (see 1.5).
- **Some filter controls have no label at all** (e.g. the `invoice_date` date input on the Invoices list).
- **No skip-to-content link**, and the `<main>` is not focus-managed.
- **Inconsistent focus indicators** — present on text search inputs, frequently missing on `<select>`/`<input type=date>`/links.
- **Positive:** status is always backed by a text label (not color alone), and tables use real `<table>/<thead>/<th>` semantics (though `<th>` lacks `scope`).

### 1.11 Tailwind consistency — **High** (systemic root cause)
- **No design tokens.** `tailwind.config.js` extends only the font. There is no brand palette, semantic color scale, or `@layer components`, so colors are chosen per-page from the default palette and have already drifted:
  - **Buttons:** filter/secondary actions use `bg-gray-800`; primary CTAs use `bg-indigo-600`; both are inline and Breeze's button components are bypassed → two parallel button languages.
  - **Links:** `text-indigo-600` (inventory dashboard) vs `text-gray-500` (reports filter reset) vs `text-indigo-600`/`text-gray-600`/`text-red-600` (table actions).
  - **Status accents:** blue (orders/production) vs indigo (invoices) vs green/amber/red (inventory).
- Utility strings for cards, tables, badges, and inputs are duplicated verbatim across dozens of files, so any change must be made in many places — guaranteeing future inconsistency.

---

## 2. Severity Summary

| # | Problem | Severity | Effort | Type |
|---|---|---|---|---|
| 1.3 | Sidebar non-responsive → modules unusable on mobile | **High** | M | Layout |
| 1.7 | Fragmented, non-semantic status badges | **High** | S | Component |
| 1.11 | No Tailwind design tokens / drift | **High** | M | System |
| 1.10 | Accessibility gaps (labels, ARIA, focus) | **High** | M | A11y |
| 1.2 | Stale/dead nav links, dual nav, no icons | **High** | S–M | IA |
| 1.5 | Labels unassociated, no inline errors, bilingual | **Med/High** | M | Forms |
| 1.8 | Duplicated KPI cards; "charts" are tables; placeholder landing | **Medium** | M | Dashboard |
| 1.1 | Two layout paths; misnamed shell; width drift | **Medium** | S | Layout |
| 1.4 | No row hover/zebra; numeric emphasis | **Medium** | S | Tables |
| 1.9 | `prompt()` for destructive cancel | **Medium** | S | Interaction |
| 1.6 | Bland empty states, no CTA | **Low/Med** | S | Content |

Effort: S ≈ <½ day · M ≈ 1–3 days (relative, per area).

---

## 3. Recommended Improvements

### 3.1 Establish a design-token foundation (unblocks everything else)
In `tailwind.config.js`, add semantic tokens so colors stop being chosen ad hoc:
- **Brand palette** (a clinical, trustworthy direction suits a dental lab — e.g. a deep teal/slate primary with a warm neutral surface), exposed as `primary`, `surface`, `muted`.
- **Semantic status scale:** `success`, `warning`, `danger`, `info`, `neutral`, each with a `-bg`/`-fg` pair — the single source of truth for badges, KPI accents, and alerts.
- Add an `@layer components` block in `app.css` for `.btn`, `.btn-primary`, `.btn-secondary`, `.card`, `.table`, `.badge` so utility strings live in one place.

### 3.2 Standardize status into one badge component
Create a single `<x-status-badge :status="…" :map="…">` that maps each domain status to a semantic token:
- Lab Order: RECEIVED→info, IN_PRODUCTION→primary, QC_*→warning, READY/DELIVERED/COMPLETED→success, REMAKE→danger, CANCELLED→neutral.
- Invoice: DRAFT→neutral, ISSUED→info, PARTIALLY_PAID→warning, PAID→success, OVERDUE→danger, VOID→neutral.
- Generalize the Inventory badges to consume the same tokens. Replace all four inline implementations.

### 3.3 Make the shell responsive and singular
- Move the sidebar into the base layout and make it an Alpine off-canvas drawer on `< lg` (slide-in over content with a backdrop), pinned on `lg+`. Drive it from the existing hamburger; retire the near-empty Breeze top-nav link list (keep the user dropdown in a slim top bar).
- Rename `settings-shell` → `app-shell` (or `module-shell`) and route the landing dashboard through it too, eliminating the dual include path. Reconsider `max-w-5xl` → `max-w-7xl` for table-heavy pages.

### 3.4 Clean up navigation & IA
- Remove the `(Sprint 5)` / `(Sprint 3)` placeholder items and all `href="#"` dead links; delete the "placeholder" comment.
- Add lightweight inline SVG icons per section; disambiguate the three "Dashboard" labels ("Overview", "Reports", "Inventory").
- Standardize permission naming convention (track separately as a backend cleanup).

### 3.5 Forms & accessibility pass
- Adopt `<x-input-label for>` + `<x-text-input id>` + `<x-input-error>` everywhere; bind labels with `for`/`id`; render field-level errors inline.
- Pick one UI language (recommend Indonesian app-wide given the domain, or English — but commit) and align all labels and helper text.
- Add `aria-label`/`aria-expanded` to the hamburger and any icon-only control, `scope="col"` on `<th>`, a skip-to-content link, and a consistent `focus-visible` ring via the component layer.
- Replace the `prompt()` cancel flow with the existing `<x-modal>` + a reason `<textarea>`.

### 3.6 Dashboard & data presentation
- Build a real post-login overview: replace the Breeze placeholder with role-aware KPI cards + "needs attention" lists (overdue invoices, QC pending, due-today orders) using a shared `<x-kpi-card>`.
- Extract `<x-kpi-card>` and a `<x-data-table>`/`<x-empty-state>` (icon + message + optional CTA slot).
- Upgrade Reports "charts" from tables to actual visualizations. Per the "no new framework" constraint, this can be done with inline SVG/CSS bars driven by Blade/Alpine (no charting library required), or formally accept tables and relabel the section.
- Add `hover:bg-gray-50` row state to all data tables.

---

## 4. Priority Order (recommended sequence)

1. **P0 — Design tokens + component layer** (§3.1). Foundation; everything else depends on it. Low risk, documentation→config only.
2. **P0 — Responsive shell / off-canvas sidebar** (§3.3) and **remove dead nav links** (§3.4). Highest user-visible impact; mobile is currently broken.
3. **P1 — Unified `<x-status-badge>`** (§3.2). Small effort, high readability gain across every list.
4. **P1 — Accessibility & forms pass** (§3.5). Labels, focus, ARIA, modal-based cancel, single language.
5. **P2 — Shared `<x-kpi-card>` / `<x-empty-state>` / table hover** and the **real landing dashboard** (§3.6).
6. **P3 — Reports visualizations** (§3.6) and broader polish (numeric emphasis, iconography).

---

## 5. Pages to Redesign First

| Order | Page | Why first |
|---|---|---|
| 1 | `layouts/sidebar.blade.php` + `components/settings-shell.blade.php` | Fixes mobile (the biggest defect), dead links, and the dual-layout problem in one place; every page inherits the win. |
| 2 | `dashboard.blade.php` (landing) | First screen after login is currently Breeze boilerplate — worst first impression, highest visibility. |
| 3 | `lab-orders/index` + `production/board` + `invoices/index` | Highest-traffic operational/finance lists; prove the unified status badge + table hover here. |
| 4 | `lab-orders/_form` | Longest, most complex form; best showcase for associated labels, inline errors, and language consistency. |
| 5 | `reports/dashboard` + `inventory/dashboard` | Validate `<x-kpi-card>` and decide tables-vs-charts. |

---

## 6. Components to Standardize (extract once, reuse everywhere)

| Component | Replaces (today) | Source of truth |
|---|---|---|
| `<x-app-shell>` (rename of `settings-shell`) | dual layout includes | one shell, responsive sidebar |
| `<x-status-badge>` | 3 inline badges + 2 inventory partials | semantic status tokens |
| `<x-kpi-card>` | duplicated card markup in 2 dashboards | KPI color map |
| `<x-data-table>` / `<x-empty-state>` | hand-rolled `<table>` + bland empty rows | table styling + CTA-capable empty state |
| `<x-btn>` (primary/secondary/danger) | inline `bg-gray-800` vs `bg-indigo-600` | one button language (consume Breeze buttons) |
| `<x-form.field>` (label+input+error) | raw `<label>/<input>` + bulk error list | associated labels, inline validation |
| `<x-filter-bar>` | repeated GET filter forms | consistent filter/reset UX |
| `<x-confirm-modal>` | `prompt()` cancel flow | accessible destructive actions (reuse `<x-modal>`) |

---

## 7. What is already good (preserve)

- Consistent card surface, table structure, padding, and filter-bar composition across modules.
- Inventory's reusable badge partials — the right pattern; generalize them rather than discard.
- Responsive KPI/filter grids and `overflow-x-auto` table wrappers.
- The Alpine Lab Order item repeater (add/remove, `old()` repopulation).
- Permission-gated menu sections — the access model is sound; only the presentation needs work.

---

*Audit only — no Blade, route, or backend files were modified. All recommendations stay within the existing Laravel + Blade + Tailwind + Alpine stack (no new front-end framework).*
