# UI Design System — Asia Dental Lab Management System

> **Purpose:** A lightweight, implementable design system that fixes the drift found in
> [docs/ui_ux_audit.md](ui_ux_audit.md) and gives every module one shared visual language.
> **Direction:** Modern SaaS · clean medical/laboratory feel · professional for clinic
> owners & branch admins · dense enough for daily operational users · mobile-responsive.
> **Stack:** Laravel Blade + Tailwind CSS + Alpine.js (only where already used). **No React, no Vue, no new UI library.**
> **Status:** Documentation only — no code is modified here. All snippets are reference implementations.

---

## 0. Principles

1. **Tokens before utilities.** Colors, type, and spacing are named once in `tailwind.config.js`; pages consume tokens, never raw hex or arbitrary values.
2. **Components before copy-paste.** Repeated markup (cards, tables, badges, filters) becomes a Blade component. One change propagates everywhere.
3. **Calm, clinical, legible.** Cool neutrals + one trustworthy primary + strictly semantic status colors. Color is information, not decoration.
4. **Dense but breathable.** Compact rows and 13px–14px data text for power users, with enough whitespace to stay scannable.
5. **Accessible by default.** Associated labels, visible focus, semantic HTML, status never by color alone.

---

## 1. Color Usage

A cool **slate** neutral ramp (clinical, less "warm office" than default gray) + a **teal** primary (medical, trustworthy, distinct from the generic indigo/blue) + a strict semantic set.

### 1.1 Proposed token extension (reference — `tailwind.config.js`)
```js
// theme.extend.colors
colors: {
  // Brand / primary action
  primary: {
    50:'#f0fdfa',100:'#ccfbf1',200:'#99f6e4',300:'#5eead4',400:'#2dd4bf',
    500:'#14b8a6',600:'#0d9488',700:'#0f766e',800:'#115e59',900:'#134e4a',
  },
  // Neutral surface/text ramp (slate)
  surface:  '#ffffff',
  canvas:   '#f8fafc', // app background (slate-50)
  ink:      '#0f172a', // primary text (slate-900)
  muted:    '#64748b', // secondary text (slate-500)
  line:     '#e2e8f0', // borders/dividers (slate-200)
}
```
Semantic status colors map to Tailwind palettes already available (no custom needed):

| Token | Meaning | bg / text |
|---|---|---|
| `success` | paid, passed, completed, OK | `bg-emerald-50` / `text-emerald-700` |
| `warning` | pending, partial, low stock, due soon | `bg-amber-50` / `text-amber-700` |
| `danger`  | overdue, rejected, remake, out of stock, cancelled-with-loss | `bg-rose-50` / `text-rose-700` |
| `info`    | received, issued, in-transit | `bg-sky-50` / `text-sky-700` |
| `primary` | active work (in production, in QC) | `bg-primary-50` / `text-primary-700` |
| `neutral` | draft, void, cancelled, inactive | `bg-slate-100` / `text-slate-600` |

### 1.2 Usage rules
- **Primary (teal)** = the single most important action on a screen (Create, Save, Apply) and active-work status. One primary button per view region.
- **Neutral/slate** = structure: backgrounds, borders, body and secondary text, secondary buttons.
- **Semantic** = state only (badges, KPI accents, alerts). Never use `success`/`danger` for decoration.
- **Money & metrics:** value text in `text-ink`; positive emphasis `text-emerald-700`, owed/overdue `text-rose-700`, watch `text-amber-700`.
- App background `canvas` (slate-50), content surfaces `surface` (white). Replaces today's `bg-gray-100`.

---

## 2. Typography Scale

Keep **Figtree** (already loaded via bunny.net — clean, modern, not an overused system font). Use **tabular numerals** for all tables/KPIs so digits align.

| Token | Tailwind | Size / line | Use |
|---|---|---|---|
| Display | `text-2xl font-semibold` | 24 / 32 | KPI values, dashboard hero numbers |
| H1 / page title | `text-xl font-semibold text-ink` | 20 / 28 | `x-page-header` title |
| H2 / section | `text-base font-semibold text-ink` | 16 / 24 | card & form-section headings |
| H3 / subsection | `text-sm font-semibold text-ink` | 14 / 20 | sub-blocks |
| Body | `text-sm text-ink` | 14 / 20 | default UI text |
| Data / dense | `text-sm` (tables) | 14 / 20 | table cells, dense lists |
| Label | `text-sm font-medium text-ink` | 14 / 20 | form labels |
| Meta / helper | `text-xs text-muted` | 12 / 16 | helper text, captions, table sub-lines |
| Eyebrow | `text-xs font-semibold uppercase tracking-wide text-muted` | 12 | sidebar section headers, KPI labels |

**Numerals:** add `tabular-nums` (and `slashed-zero` if desired) to numeric cells/KPIs:
```html
<td class="px-3 py-2 text-right tabular-nums text-ink">{{ number_format($v, 2) }}</td>
```
Headings use `text-ink`; never pure black. Body never lighter than `text-muted` for primary content.

---

## 3. Spacing System

Tailwind's 4px base scale, constrained to a small, predictable set so rhythm is consistent.

| Context | Token | Value |
|---|---|---|
| Page vertical rhythm (between blocks) | `space-y-6` | 24px |
| Card padding (default) | `p-6` | 24px |
| Card padding (dense/table card) | `p-4` | 16px |
| Table cell | `px-3 py-2` | 12 / 8px |
| Form field gap (grid) | `gap-4` | 16px |
| Inline control gap (filter bar) | `gap-2` | 8px |
| Section heading → content | `mt-3` | 12px |
| Icon ↔ label | `gap-2` | 8px |

**Radius:** `rounded-lg` (8px) for cards/inputs/buttons, `rounded-full` for badges/avatars.
**Elevation:** one shadow only — `shadow-sm` for cards; `shadow-lg` reserved for overlays (modal, drawer, dropdown). No mixed shadow scales.
**Borders:** `border border-line` for structural separation; `divide-y divide-line` inside tables/lists.
**Content width:** `max-w-7xl mx-auto` for table-heavy/dashboard pages; `max-w-3xl` for single-column forms.

---

## 4. Page Layout Pattern

One shell for the whole app (replaces the dual `app-layout` + `settings-shell` paths). Sidebar lives in the shell; content is a single padded column.

```blade
{{-- resources/views/components/app-shell.blade.php (proposed) --}}
@props(['title' => null])
<x-app-layout>
    <div x-data="{ nav: false }" class="min-h-screen bg-canvas">
        {{-- top bar (slim): hamburger + brand + user menu --}}
        <x-app-topbar />

        <div class="lg:flex">
            <x-app-sidebar />               {{-- off-canvas < lg, pinned lg+ --}}

            <main class="flex-1 min-w-0">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                    @isset($title)
                        <x-page-header :title="$title">{{ $header ?? '' }}</x-page-header>
                    @endisset

                    <x-flash />              {{-- success/error/info alerts --}}

                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>
</x-app-layout>
```
**Page anatomy (top → bottom):** Top bar → Page header (title + actions) → Flash → Filter bar (if list) → Primary content (KPIs / table / form) → Pagination.

---

## 5. KPI Card Pattern

Compact metric tile. Label (eyebrow) + value (display) + optional delta/hint. Accent reserved for semantic meaning.

```blade
<div class="rounded-lg border border-line bg-surface p-4">
    <p class="text-xs font-semibold uppercase tracking-wide text-muted">Outstanding</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums text-rose-700">{{ number_format($v, 2) }}</p>
    <p class="mt-1 text-xs text-muted">{{ $hint ?? '' }}</p>
</div>
```
- Default value color `text-ink`; switch to `text-emerald-700/amber-700/rose-700` only when the metric *is* good/watch/bad.
- Grid: `grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6` (operational KPIs) or `lg:grid-cols-3` (financial).
- See `x-kpi-card` in §16.

---

## 6. Table Pattern

Dense, scannable, responsive. Always wrapped for horizontal scroll; semantic `<th scope>`; hover row state; right-aligned tabular numerics.

```blade
<div class="rounded-lg border border-line bg-surface">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead>
                <tr class="text-left text-muted">
                    <th scope="col" class="px-3 py-2 font-medium">Invoice #</th>
                    <th scope="col" class="px-3 py-2 font-medium">Clinic</th>
                    <th scope="col" class="px-3 py-2 font-medium">Status</th>
                    <th scope="col" class="px-3 py-2 font-medium text-right">Outstanding</th>
                    <th scope="col" class="px-3 py-2 font-medium text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    <tr class="hover:bg-slate-50">
                        <td class="px-3 py-2 font-medium text-ink">{{ $row->number }}</td>
                        <td class="px-3 py-2 text-muted">{{ $row->clinic?->name }}</td>
                        <td class="px-3 py-2"><x-status-badge domain="invoice" :value="$row->status" /></td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink">{{ number_format($row->outstanding, 2) }}</td>
                        <td class="px-3 py-2 text-right">
                            <a href="#" class="font-medium text-primary-700 hover:text-primary-600">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty-state message="No invoices found." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
```
**Rules:** identifiers `font-medium text-ink`; secondary columns `text-muted`; numerics `text-right tabular-nums`; actions in the last, right-aligned column using primary link color; one row-hover (`hover:bg-slate-50`); pagination directly below the card.

---

## 7. Filter Bar Pattern

A single inline `GET` form: search → selects → Apply (primary) → Reset (quiet link). Wraps on mobile.

```blade
<form method="GET" action="{{ $action }}"
      class="flex flex-wrap items-end gap-2 rounded-lg border border-line bg-surface p-4">
    <div class="grow min-w-[12rem]">
        <label for="f-search" class="sr-only">Search</label>
        <input id="f-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}"
               placeholder="Search…"
               class="w-full rounded-lg border-line text-sm focus:border-primary-500 focus:ring-primary-500" />
    </div>
    {{ $slot }} {{-- additional selects --}}
    <button class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700
                   focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
        Apply
    </button>
    <a href="{{ $action }}" class="px-2 py-2 text-sm font-medium text-muted hover:text-ink">Reset</a>
</form>
```
Every select carries a real (possibly `sr-only`) label and the same `rounded-lg border-line` styling + primary focus ring. See `x-filter-bar` §16.

---

## 8. Form Pattern

Grouped into `x-form-section` blocks; labels associated with inputs (`for`/`id`); inline per-field errors; one language app-wide (recommend **Indonesian** for operational labels — pick one and commit).

```blade
<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    <x-form-section title="Order Details"
                    description="Klinik, dokter, dan pasien untuk order ini.">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label for="clinic_id" class="text-sm font-medium text-ink">Klinik</label>
                <select id="clinic_id" name="clinic_id"
                        class="mt-1 block w-full rounded-lg border-line text-sm focus:border-primary-500 focus:ring-primary-500">
                    …
                </select>
                @error('clinic_id')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </x-form-section>

    <div class="flex items-center justify-end gap-3">
        <a href="{{ $cancel }}" class="text-sm font-medium text-muted hover:text-ink">Batal</a>
        <button class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">Simpan</button>
    </div>
</form>
```
**Rules:** every control has an `id` + associated `<label for>`; required fields marked with `<span class="text-rose-600">*</span>`; helper text `text-xs text-muted`; inline `@error` under each field (keep the top summary only as a secondary aid); inputs use Tailwind Forms plugin (already installed) with the primary focus ring; destructive confirms use a modal, never `prompt()`.

---

## 9. Status Badge Pattern

**One** component, semantic mapping per domain. Replaces the four divergent inline implementations from the audit.

Shape: `inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium`.

| Domain | Value → token |
|---|---|
| **Lab Order** | DRAFT→neutral · RECEIVED→info · ASSIGNED/IN_PRODUCTION→primary · ON_HOLD→warning · QC_PENDING→warning · QC_PASSED/READY_FOR_DELIVERY→info · IN_DELIVERY→info · DELIVERED/COMPLETED→success · REMAKE→danger · CANCELLED→neutral |
| **Invoice** | DRAFT→neutral · ISSUED→info · PARTIALLY_PAID→warning · PAID→success · OVERDUE→danger · VOID→neutral |
| **Delivery** | READY_FOR_DELIVERY→info · IN_DELIVERY→primary · DELIVERED→success · COMPLETED→success · CANCELLED→neutral |
| **QC** | PENDING→warning · PASSED→success · REJECTED→danger |
| **Stock** | OK→success · LOW→warning · OUT→danger |
| **Active flag** | true→success ("Active") · false→neutral ("Inactive") |

Accessibility: the human-readable status text is always the badge content (color is reinforcement, not the only signal). See `x-status-badge` §16.

---

## 10. Empty State Pattern

Consistent, helpful: muted icon + message + optional CTA. Used inside table bodies (`colspan`) or standalone.

```blade
<div class="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center">
    <svg class="h-8 w-8 text-slate-300" …></svg>
    <p class="text-sm text-muted">{{ $message ?? 'No data yet.' }}</p>
    @isset($cta)<div class="mt-1">{{ $cta }}</div>@endisset
</div>
```
Lists that can be populated by the user should pass a CTA (e.g. "+ Buat Lab Order"). See `x-empty-state` §16.

---

## 11. Alert / Flash Message Pattern

Semantic, dismissible, icon + message. One `x-flash` reads `session('status'|'error'|'info')` and validation summary.

```blade
@if (session('status'))
    <div role="status"
         class="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
        <svg class="mt-0.5 h-5 w-5 shrink-0" …></svg>
        <p>{{ session('status') }}</p>
    </div>
@endif
@if ($errors->any())
    <div role="alert"
         class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
        <p class="font-medium">Periksa kembali isian berikut:</p>
        <ul class="mt-1 list-disc list-inside space-y-0.5">
            @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif
```
Variants: `success` (emerald), `error` (rose, `role="alert"`), `info` (sky), `warning` (amber). Success/info are dismissible via Alpine (`x-data="{show:true}" x-show="show"`).

---

## 12. Sidebar Navigation Pattern

Permission-gated, icon + label, grouped by eyebrow section headers. Off-canvas drawer `< lg`, pinned `lg+`. No dead `#` links, no internal sprint labels.

```blade
<aside :class="nav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 transform border-r border-line bg-surface
              transition-transform lg:static lg:z-auto">
    <nav class="space-y-1 p-4 text-sm">
        <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-muted">Operations</p>

        <a href="{{ route('lab-orders.index') }}"
           @class([
               'flex items-center gap-2 rounded-lg px-3 py-2',
               'bg-primary-50 text-primary-700 font-medium' => request()->routeIs('lab-orders.*'),
               'text-muted hover:bg-slate-50 hover:text-ink' => ! request()->routeIs('lab-orders.*'),
           ])>
            <svg class="h-4 w-4" …></svg> Lab Orders
        </a>
    </nav>
</aside>
{{-- < lg backdrop --}}
<div x-show="nav" @click="nav=false" x-cloak class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"></div>
```
**Rules:** active item = `bg-primary-50 text-primary-700`; inactive = `text-muted hover:bg-slate-50`. Each section wrapped in `@canany`. Hamburger in top bar toggles `nav` with `aria-expanded` / `aria-controls`. Disambiguate the three "Dashboard" labels → "Overview", "Reports", "Inventory".

---

## 13. Dashboard Card Pattern

A titled content card (chart/list/table) with an optional header action. The building block for dashboard grids alongside KPI cards.

```blade
<section class="rounded-lg border border-line bg-surface p-6">
    <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold text-ink">Stock by Location</h2>
        <a href="#" class="text-sm font-medium text-primary-700 hover:text-primary-600">View all</a>
    </div>
    <div class="mt-3">{{ $slot }}</div>   {{-- table / mini-bars / list --}}
</section>
```
Dashboard grid: `grid gap-6 lg:grid-cols-2` (two-up panels) with a full-width KPI row above. For "charts", prefer inline CSS/SVG bars (Alpine-friendly, no library) over raw number tables:
```blade
<div class="h-2 rounded-full bg-slate-100">
    <div class="h-2 rounded-full bg-primary-500" style="width: {{ $pct }}%"></div>
</div>
```
**Post-login landing** should be a real Overview: KPI row + "needs attention" panels (overdue invoices, QC pending, orders due today) — not the Breeze placeholder.

---

## 14. Responsive Rules

| Breakpoint | Behavior |
|---|---|
| `< sm` (mobile) | Sidebar off-canvas (hidden, hamburger). KPIs `grid-cols-2`. Filter bar wraps; full-width controls. Tables scroll horizontally inside `overflow-x-auto`. Forms single column. |
| `sm` (≥640) | KPIs `sm:grid-cols-3`. Form grids `sm:grid-cols-2/3`. |
| `lg` (≥1024) | Sidebar pinned (`lg:static`). KPIs up to `lg:grid-cols-6`. Dashboard panels `lg:grid-cols-2`. Content `max-w-7xl`. |

**Rules:** mobile-first — base classes target small screens, add `sm:`/`lg:` for larger. Never rely on the sidebar being visible to reach a page (every destination also reachable after opening the drawer). Touch targets ≥ 40px (`py-2` + adequate width). No fixed pixel widths on content; use `min-w-0` on flex children so tables can shrink/scroll.

---

## 15. Accessibility Rules

1. **Labels:** every input/select/textarea has an associated `<label for>`; visually-hidden ones use `sr-only` (never label-less).
2. **Focus:** visible focus on all interactive elements — `focus:ring-2 focus:ring-primary-500` (forms) / `focus-visible:outline` (buttons/links). Never remove outlines without a replacement.
3. **Semantics:** real `<table>/<thead>/<th scope="col">`, `<nav>`, `<main>`, `<button>` for actions, `<a>` for navigation. Add a skip-to-content link before `<main>`.
4. **Status not by color alone:** badges always include text; alerts include an icon + text and `role="status"`/`role="alert"`.
5. **Interactive components:** hamburger/disclosure buttons set `aria-expanded` + `aria-controls`; drawers/modals trap focus and close on `Esc`; icon-only controls have `aria-label`.
6. **Contrast:** body text `text-ink` on `surface`/`canvas` and badge fg/bg pairs meet WCAG AA (the `-700` text on `-50` bg pairs above qualify). Avoid text lighter than `text-muted` for meaningful content.
7. **Motion:** keep transitions short; respect `motion-reduce:transition-none`.
8. **No blocking native dialogs** (`prompt`/`alert`) for app flows — use accessible modals.

---

## 16. Proposed Reusable Blade Components

All live in `resources/views/components/` and are usable as `<x-…>`. Props shown; markup follows the patterns above.

### `<x-page-header>`
```blade
@props(['title', 'subtitle' => null])
<div class="flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold text-ink">{{ $title }}</h1>
        @isset($subtitle)<p class="mt-0.5 text-sm text-muted">{{ $subtitle }}</p>@endisset
    </div>
    @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
</div>
```
Usage: `<x-page-header title="Lab Orders"><x-slot:actions><a …>+ Buat</a></x-slot:actions></x-page-header>`

### `<x-kpi-card>`
```blade
@props(['label', 'value', 'tone' => 'default', 'hint' => null])
@php($tones = ['default'=>'text-ink','success'=>'text-emerald-700','warning'=>'text-amber-700','danger'=>'text-rose-700'])
<div class="rounded-lg border border-line bg-surface p-4">
    <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums {{ $tones[$tone] }}">{{ $value }}</p>
    @isset($hint)<p class="mt-1 text-xs text-muted">{{ $hint }}</p>@endisset
</div>
```

### `<x-status-badge>`
```blade
@props(['domain', 'value'])
@php
    $maps = [
        'order' => ['IN_PRODUCTION'=>'primary','ASSIGNED'=>'primary','RECEIVED'=>'info','QC_PASSED'=>'info',
            'READY_FOR_DELIVERY'=>'info','IN_DELIVERY'=>'info','ON_HOLD'=>'warning','QC_PENDING'=>'warning',
            'DELIVERED'=>'success','COMPLETED'=>'success','REMAKE'=>'danger','CANCELLED'=>'neutral','DRAFT'=>'neutral'],
        'invoice' => ['DRAFT'=>'neutral','ISSUED'=>'info','PARTIALLY_PAID'=>'warning','PAID'=>'success','OVERDUE'=>'danger','VOID'=>'neutral'],
        'delivery'=> ['READY_FOR_DELIVERY'=>'info','IN_DELIVERY'=>'primary','DELIVERED'=>'success','COMPLETED'=>'success','CANCELLED'=>'neutral'],
        'qc'      => ['PENDING'=>'warning','PASSED'=>'success','REJECTED'=>'danger'],
        'stock'   => ['OK'=>'success','LOW'=>'warning','OUT'=>'danger'],
    ];
    $tone = $maps[$domain][$value] ?? 'neutral';
    $classes = [
        'success'=>'bg-emerald-50 text-emerald-700','warning'=>'bg-amber-50 text-amber-700',
        'danger'=>'bg-rose-50 text-rose-700','info'=>'bg-sky-50 text-sky-700',
        'primary'=>'bg-primary-50 text-primary-700','neutral'=>'bg-slate-100 text-slate-600',
    ][$tone];
@endphp
<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $classes }}">
    {{ str($value)->replace('_',' ')->title() }}
</span>
```

### `<x-filter-bar>`
```blade
@props(['action'])
<form method="GET" action="{{ $action }}"
      class="flex flex-wrap items-end gap-2 rounded-lg border border-line bg-surface p-4">
    {{ $slot }} {{-- search + selects --}}
    <button class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700">Apply</button>
    <a href="{{ $action }}" class="px-2 py-2 text-sm font-medium text-muted hover:text-ink">Reset</a>
</form>
```

### `<x-data-table>`
```blade
@props(['headers' => []]) {{-- ['Label' or ['label'=>'Total','align'=>'right'] …] --}}
<div class="rounded-lg border border-line bg-surface">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead>
                <tr class="text-left text-muted">
                    @foreach ($headers as $h)
                        @php($label = is_array($h) ? $h['label'] : $h)
                        @php($align = is_array($h) && ($h['align'] ?? '')==='right' ? 'text-right' : '')
                        <th scope="col" class="px-3 py-2 font-medium {{ $align }}">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">{{ $slot }}</tbody>
        </table>
    </div>
    @isset($footer)<div class="border-t border-line p-3">{{ $footer }}</div>@endisset
</div>
```

### `<x-empty-state>`
```blade
@props(['message' => 'No data yet.', 'icon' => true])
<div class="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center">
    @if($icon)<svg class="h-8 w-8 text-slate-300" …></svg>@endif
    <p class="text-sm text-muted">{{ $message }}</p>
    @isset($cta)<div class="mt-1">{{ $cta }}</div>@endisset
</div>
```

### `<x-form-section>`
```blade
@props(['title' => null, 'description' => null])
<section class="rounded-lg border border-line bg-surface p-6">
    @if($title || $description)
        <div class="mb-4">
            @isset($title)<h2 class="text-base font-semibold text-ink">{{ $title }}</h2>@endisset
            @isset($description)<p class="mt-0.5 text-sm text-muted">{{ $description }}</p>@endisset
        </div>
    @endif
    {{ $slot }}
</section>
```

---

## 17. Adoption Path (suggested, non-binding)

1. Add color/type tokens to `tailwind.config.js` + a small `@layer components` (`.btn`, `.card`, `.badge`) in `app.css`.
2. Build the seven components above; convert one list page (Invoices) + one form (Lab Order) as the reference implementation.
3. Roll `x-status-badge` across all lists (highest readability win, lowest risk).
4. Replace the dual layout with `x-app-shell` + responsive sidebar; retire `prompt()` via `x-confirm-modal`.
5. Replace the placeholder landing dashboard with a real Overview using `x-kpi-card` + `x-dashboard-card`.

> Sequencing mirrors the priority order in [docs/ui_ux_audit.md](ui_ux_audit.md) §4.

---

*Documentation only — no Blade, config, route, or backend files were modified. Every snippet targets the existing Laravel + Blade + Tailwind + Alpine stack with no new front-end dependency.*
