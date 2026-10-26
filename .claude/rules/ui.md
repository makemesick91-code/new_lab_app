---
paths:
  - "resources/views/**/*.blade.php"
  - "resources/css/**"
  - "tailwind.config.js"
  - "app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php"
  - "tests/Feature/Ui/**"
---

# UI/UX design system — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

Detailed standard (Indonesian/English): `docs/ui/daengtisiams-ui-governance.md`, `docs/ui/daengtisiams-design-tokens.md`,
`docs/ui_design_system.md`. Read the relevant section on demand.

## Invariants

### Stack and components

- **AIR-UI-01** Build UI with Blade + Tailwind (v3 via PostCSS) + Alpine only: no React/Vue/Svelte/SPA, no heavy
  chart/datatable/admin/icon library, no new frontend dependency without explicit approval, no framework plugin in
  `vite.config.*` (and no `@tailwindcss/vite`), and no CDN `<script src="http…">` in `x-ui.*` components or the app
  shell — assets ship through Vite. Source: `.cursor/rules/30-frontend-design.mdc`; archive UIX-1; governance doc
  UIX-18/UIX-21.
- **AIR-UI-02** Treat `x-ui.*` (`resources/views/components/ui/`: page-header, filter-bar, table, badge, button, card,
  input, select, textarea, alert, modal, empty-state, skeleton, kpi-card, restricted-notice) as the only design
  system. Extend components backward-compatibly (never rename or remove a public prop); keep business, permission and
  validation logic and hidden forms/routes out of generic components; domain badges such as `x-lab.status-badge` and
  `x-rme.invoice-summary` render through `x-ui.badge`. Add any new `x-ui.*` component to the catalog
  (`/dev/ui-catalog`, `developer-console.ui-catalog`). Source: archive UIX-1; governance doc UIX-15.
- **AIR-UI-03** Use the semantic tokens from `tailwind.config.js` (`navy`, `brand`, `gold`, `canvas`, `surface`,
  `ink`/`ink-soft`/`ink-muted`, `hairline`, `success`/`danger`/`warning`/`info`, `shadow-card`); `brand` blue is the
  primary/active/link/focus colour. Never hard-code `#hex` in screen Blade and never introduce legacy `teal-*` or
  indigo into a view you touch (supersedes the teal primary and `bg-gray-100` canvas in rule 30). Source: archive
  UIX-1; governance doc rule 1.
- **AIR-UI-04** Use gold as an accent only — revenue/KPI highlight (`x-ui.card :accent`, the revenue `x-ui.kpi-card`),
  accent rails and the `cashier_pending` badge tone. Gold is never a primary CTA: no `variant="gold"` call-to-action;
  primary CTA = `x-ui.button variant="primary"` (blue). Source: archive UIX-1, UIX-2; governance doc rule 3.
- **AIR-UI-05** Map colours only onto shades that exist, or the class silently renders nothing: `brand`
  50/100/200/500/600/700/800 (no 900 — hover uses 800), `success`/`danger`/`warning`/`info` 50/100/700/DEFAULT,
  `gold` 100/200/500/600/700. Never add a custom `theme.extend.ringColor.brand` string: it overrides the palette for
  ring utilities and breaks `ring-brand-100` inside `@apply` at build. Source: archive UIX-1, UIX-4, UIX-6; code
  `tailwind.config.js`.
- **AIR-UI-06** Render statuses with `x-ui.badge :status="…"`: lookup is case-insensitive, unknown values fall back to
  `neutral`, and canonical financial statuses (`unpaid`, `partial`, `void`, `paid`) must stay mapped. Add mappings
  additively; never change an existing tone. Source: archive UIX-3; governance doc UIX-15.
- **AIR-UI-07** Know the component slots: `x-ui.card` takes `title`, `description`, `padding`, `accent` and an
  `actions` slot — it has no `header` or `subtitle` slot, and passing one silently drops the content; its action group
  keeps `flex-wrap` (UIX-21 hard lock). `x-ui.table` takes `caption` and the default slot (no `head` slot) and wraps
  in `overflow-x-auto`. Form components read the error bag as `$errors ?? null`. Source: archive
  REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1, FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR1), UIX-1.

### Page standards

- **AIR-UI-08** Build every list/index page from `x-ui.page-header` + `x-ui.filter-bar` (existing GET parameter names
  unchanged) + `x-ui.table` + `x-ui.badge :status` + `x-ui.button` + `x-ui.empty-state` (title + description saying
  what is missing). No from-scratch table, badge or button. Source: archive UIX-3; rule 30.
- **AIR-UI-09** Build clinical pages (RME detail, odontogram, medical record, prescription) from page-header, card,
  badge (`:status`), button and alert. Never render `->ktp_number`, `->ktp`, `->nik` or `->identity_number` in them.
  Keep the odontogram's clinical status colours (caries red, missing gray, filling blue, crown amber, PSA sky, normal
  green) and DMF-T tallies — they are clinical semantics that must match print/PDF, not legacy palette. Clinical
  controls follow the server capability (`.claude/rules/rme-clinical.md`, rule 135). Source: archive UIX-4;
  `.cursor/rules/135-rme-authoring-ui-capability-mirror.mdc`.
- **AIR-UI-10** On cashier/financial pages, restyling never changes payment field names, Alpine payment/receivable
  logic, validation or routes, and Blade computes no money — invoice amounts come from `RmeInvoiceDocument`. The
  payment page carries no consent block (supersedes UIX-5's consent alert). Source: archive UIX-5;
  `.cursor/rules/185-rme-visit-detail-invoice-registration-ux.mdc` §B7;
  `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc` §3.
- **AIR-UI-11** On inventory pages keep semantic stock-status colours (empty/low/overstock/normal, in/out) and never
  assign a mutable stock attribute (`->current_stock =`, `->stock_quantity =`) from a view; stock stays
  ledger-derived (CG-10). Source: archive UIX-6, UIX-9.
- **AIR-UI-12** Report screens use the list standard; preserve labels that tests assert verbatim (renaming
  `Tanggal Kunjungan Dari/Sampai` broke `RmeReportFilterTest`). Source: archive UIX-8.
- **AIR-UI-13** Keep visual order equal to source order: never reorder content with CSS `order-*`/`order:`, because
  focus and screen-reader order follow the source (e.g. Detail Kunjungan renders Odontogram → Rekam Medis → Resep in
  Blade source). Source: rule 185 §A2.

### Print and PDF

- **AIR-UI-14** Keep print/PDF templates dompdf-safe: `<table>` layout with no flex or grid, continuation via
  `thead{display:table-header-group}` and `tr{page-break-inside:avoid}`, and an `@page` box declared in every print
  template. Inline brand hex and print-only `#fff` are allowed in print templates only (the governance hex scan
  excludes them). Source: archive UIX-8, UIX-5, Sprint 63.1; `.cursor/rules/100-rme-consent-payment-gate-print-ownership.mdc` §10.
- **AIR-UI-15** Avoid the recorded dompdf traps: never nest a table inside a two-cell row (it cannot split, page 1
  empties), never float a block (it intrudes into the next page's rows), and embed images and logos as data URIs —
  dompdf has no session, so a linked private image exports blank. Source: rule 185 §B14–15;
  `.cursor/rules/121-clinical-evidence-private-storage.mdc` rule 4.
- **AIR-UI-16** Never clip, hide or shrink financial data to fit a page (`overflow:hidden`, `display:none` on money,
  unreadable type); a long document continues with the header repeated. One shared partial feeds both the browser
  print and the PDF — change it once, never fork. Source: rule 100 §10; rule 185 §B6.
- **AIR-UI-17** The RME print carries no `Data Pasien & Kunjungan` card or its cells but keeps the wrapper
  `<title>`, footer and logo; a composition change never touches data. Assert on cell markup (`<dt>Klinik</dt>`) and
  absent values, not a bare word the logo `alt` also contains. Source:
  `.cursor/rules/127-rme-print-composition-no-duplicate-patient-card.mdc`.

### Shell, navigation, permissions

- **AIR-UI-18** Render every controller page inside a sidebar-bearing shell (`x-settings-shell` → `x-app-layout` →
  `layouts/app.blade.php`); only auth screens (guest shell) and print templates are exempt, and each exemption must
  prove its kind. Source: code `tests/Feature/Ui/SidebarCoverageTest.php`.
- **AIR-UI-19** Keep `resources/views/layouts/sidebar.blade.php` (menu content in
  `layouts/partials/sidebar.blade.php`) the one sidebar: group by workflow, wrap each
  group/item in `@can`/`@canany` (presentation only — route middleware and policies are the boundary), drive active
  state from `routeIs(...)` with `menu-item-active`/`menu-subitem-active`, label sections with `menu-group-title`,
  use no dead `#` links, and keep sibling route-name prefixes disjoint so two menus never light up together. Source:
  `.cursor/rules/frontend-design.mdc`; governance doc UIX-19; archive FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1.
- **AIR-UI-20** Never leave a silent absence or an exposed unauthorized action: where an authorized operator meets a
  guarded action, show `x-ui.restricted-notice` (non-submitting, no form) or a disabled control with its reason;
  never leave an enabled control that relies on the server refusing it. Source: governance doc UIX-20; rule 135 §5.

### Responsive, accessibility, performance

- **AIR-UI-21** Build mobile-first: no body horizontal scroll, wide tables through `x-ui.table`, filter-bar and
  page-header stack on narrow widths, action groups wrap, detail grids start at `grid-cols-1`, no critical data hidden
  without a scroll/stack alternative. Source: governance doc UIX-16.
- **AIR-UI-22** Tie every label to its input (`x-ui.input`/`select`/`textarea`), show errors next to the field in the
  danger token, mark required fields, expose `aria-disabled` on disabled buttons, keep `role="alert"` on
  `x-ui.alert`, route destructive flows through `variant="danger"` or `x-ui.modal` (never `prompt()`/`alert()`), add
  ARIA only where native HTML lacks the semantics, and never claim a WCAG/Lighthouse audit that was not performed.
  Operator-facing text is Bahasa Indonesia. Source: rule 30; governance doc UIX-17, UIX-21.
- **AIR-UI-23** No polling (`setInterval`); use a one-shot `setTimeout` or on-demand `fetch`. Any design-system,
  `x-ui.*`, Tailwind-config or JS/CSS entrypoint change records `npm run build` CSS/JS sizes in the sprint doc, and new
  Tailwind utilities in Blade need the asset build at deploy. Source: governance doc UIX-18; archive
  REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1.
- **AIR-UI-24** Keep non-trivial Alpine state in a `resources/js/*.js` factory registered with `Alpine.data()`, and
  never trust a declarative binding (`:value`) at the instant of submission — write the field synchronously in the
  same block as `form.submit()`. Source: archive BUGFIX-LEGACY-BATCH-REVIEW-DECISION-NOT-SUBMITTED-1,
  REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1.

### Blade traps and verification

- **AIR-UI-25** Never put a Blade comment `{{-- --}}` inside an `@php` block (it is parsed as PHP).
  `layouts/partials/sidebar.blade.php` uses inline php directives (no closing token); never add a closing php token
  anywhere below one, not even in a comment — Blade pairs raw blocks before stripping comments and swallows the
  markup between.
  Source: rule 121 traps; archive REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
- **AIR-UI-26** A view that uses `@push('scripts')` needs `@stack('scripts')` in its layout; a missing stack fails
  silently (the button looks live, the handler never loads). `layouts/app.blade.php` and `layouts/guest.blade.php`
  both render it — keep it in any new layout. Source: `.cursor/rules/93-doctor-pwa-webauthn.mdc` §40.
- **AIR-UI-27** Run `php artisan view:cache` after Blade edits, but prove rendering with a test: `view:cache`
  succeeded while escaped quotes in an attribute (`[data-selectable=\'1\']`) compiled to invalid PHP and 500'd at
  render. Source: archive FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2); rule 121.
- **AIR-UI-28** Assert dynamic text with `assertSee()` (it escapes); a raw-body or `assertSee(…, false)` assertion on a
  dynamic value must wrap it in `e()`. Blade renders `&` as `&amp;`, so match a safe substring. Source: archive UIX-6,
  CICD-BASELINE-REVERIFY-1.
- **AIR-UI-29** Keep `php artisan architecture:ui-governance-check` read-only and non-brittle: `errors` → FAIL,
  `warnings` → WATCH (non-zero with `--strict`), `advisories` (gitignored/generated state such as `public/build`)
  never decide; it is informational, not part of the blocking combined decision. It scans Blade component source
  **including comments**, so never write a banned literal (`variant="gold"`, `teal-*`) even in a comment. New rules
  lock stable invariants — no app-wide legacy-colour sweep, no "every view uses `x-ui.*`" mandate, no asset-hash
  check. Source: archive UIX-1; governance doc UIX-21, UIX-22 and signal classes.
- **AIR-UI-30** UI polish changes presentation only: no route, permission, policy, `BranchContext`, query, schema,
  RME finalize/consent, cashier or ledger behaviour. Polish never adds exposure of KTP/NIK, a scan or a raw note;
  surfaces authorized to show them (the RME consent document, the policy-gated KTP archive viewer) stay as they are. Adopt the
  standard incrementally — converge a legacy view when you modify it, never in a one-sprint mass migration. Source:
  governance doc rules 11–12; rule 30; archive UIX-2…UIX-8.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- The UIX-1 → UIX-22 foundation is CLOSED (UIX-22); no polish cycle is open. Evidence suites live in
  `tests/Feature/Ui/` (e.g. `UiRulesEnforcementGovernanceLockUixTest`, `EnterpriseCleanUiUxFinalClosureUixTest`).
- `architecture:ui-governance-check --strict` enforces three hard locks (Vite framework plugin, app-shell CDN script,
  `x-ui.card` action wrap) plus soft documentation signals.
- Font is Figtree (`tailwind.config.js`).
- Shell primitives `resources/views/components/app/{sidebar,topbar}.blade.php` exist but are not wired; the live
  layout includes `layouts/sidebar` and `layouts/partials/topbar`.
- Drift to report, not fix silently: `.cursor/rules/30-frontend-design.mdc` still names teal primary and a
  `bg-gray-100` canvas; `resources/views/settings/rme/legacy-imports/show.blade.php` polls with `setInterval`
  against the UIX-18 no-polling rule.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/30-frontend-design.mdc` | Stack, list/form/empty-state/accessibility patterns (colours superseded by UIX-1) |
| `.cursor/rules/frontend-design.mdc` | Sidebar grouping, active state, dashboard principles |
| `.cursor/rules/127-rme-print-composition-no-duplicate-patient-card.mdc` | RME print composition and shared print partial |
| `.cursor/rules/100-rme-consent-payment-gate-print-ownership.mdc` | One-page receipt, `@page`, no clipped financial data |
| `.cursor/rules/185-rme-visit-detail-invoice-registration-ux.mdc` | Source-order cards, dompdf invoice traps, money from `RmeInvoiceDocument` |
| `.cursor/rules/121-clinical-evidence-private-storage.mdc` | Inline print images; Blade comment-in-`@php` trap |
| `.cursor/rules/135-rme-authoring-ui-capability-mirror.mdc` | Controls mirror server capability; disabled-with-reason |
| `.cursor/rules/93-doctor-pwa-webauthn.mdc` | `@push`/`@stack` requirement for authentication layouts |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/10-ui.md` (UIX-1/2/3/4/5/8); UIX-6 and UIX-9 are in
`docs/sprints/claude-md-archive/09-inventory.md`; UIX-10…UIX-22 have sprint docs `docs/sprints/uix-*.md`. Grep by
sprint id; never read a whole file.
