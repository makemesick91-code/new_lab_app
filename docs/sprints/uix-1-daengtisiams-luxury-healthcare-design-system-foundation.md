# UIX-1 — DaengtisiaMS Luxury Healthcare Design System Foundation

**Branch:** `feature/uix-1-daengtisiams-luxury-healthcare-design-system-foundation`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Type:** UI/UX foundation (design system, tokens, shell, reusable components, governance, docs). **Not** a full-page rewrite.

## ENT foundation gate (must pass before UIX-1)
Verified GO before any file changed:
- Tags present: `enterprise-foundation-go`, `ent-16-enterprise-foundation-closure-go-no-go-go`, `post-ent-foundation-runtime-hardening-queue-worker-deploy-timeout-go`.
- ENT-16 merged (PR #203 `d0c472e`) + deploy evidence `e5d3378`; POST-ENT merged (PR #204 `a38d051`).
- `php artisan foundation:enterprise-closure-check` → **GO** (36/36 checks, 0 errors).
- `php artisan foundation:roadmap-check` → **GO**, ENT sequence complete, `next_recommended_sprint = MON-1`, not stale.

## Design direction (locked)
Luxury healthcare SaaS · putih/off-white background · **biru** untuk aksi/focus/active/link/**primary CTA** · **gold** hanya aksen premium terbatas (bukan CTA). Optimized for busy clinics: cepat dibaca, cepat dipakai.

## Design references stored
- `docs/ui/daengtisiams-design-canvas.html` — layar & komponen.
- `docs/ui/daengtisiams-developer-guide.html` — panduan developer (tokens/tipografi/pola/print/aksesibilitas).
- Plus markdown: `daengtisiams-ui-foundation.md`, `daengtisiams-design-tokens.md`, `daengtisiams-ui-governance.md`, `daengtisiams-implementation-checklist.md`.

> Kedua HTML diauthor in-repo sebagai refleksi kanonik dari file Claude Design (upload asli tidak terlampir pada sesi implementasi). Timpa dengan versi asli bila tersedia; pertahankan token & governance.

## Tokens implemented (`tailwind.config.js` + `resources/css/app.css`)
Semantic colors: `navy` (text/brand dark), `brand` (blue primary/active/link/focus), `gold` (accent only), `canvas` (off-white bg), `surface`, `ink`/`ink-soft`/`ink-muted`, `hairline`, status `success`/`danger`/`warning`/`info`. Plus `shadow-card`, `rounded-xl`. Sidebar active-state + shared `.ui-card`/`.ui-table-shell` retheme teal→blue via tokens (class names unchanged, no structural change).

## Components (`resources/views/components/ui/*`)
Extended (backward-compatible APIs preserved): `badge` (added `:status` domain→tone map + kept `tone`), `button` (primary→blue, added `success`/`warning`/`ghost`/`gold` variants + `size`/`loading`/`disabled`), `card` (+`accent`, `actions` slot), `table` (token shell). New: `input`, `select`, `textarea`, `alert`, `modal`, `empty-state`, `skeleton`, `page-header`, `filter-bar`, `kpi-card`. Reusable shell primitives: `components/app/sidebar.blade.php`, `components/app/topbar.blade.php` (available for future adoption; live layout unchanged structurally).

Badge status map covers: draft, waiting, in_progress, cashier_pending, paid, cancelled, low_stock, expired_soon, normal, out_of_stock, pending, approved, rejected, completed, delivered, qc, info, warning, danger, success.

## Layout changes (safe)
`layouts/app.blade.php` body + main background → `bg-canvas`, text → `text-navy`. `settings-shell` eyebrow teal→brand. Existing sidebar/topbar partials + routes untouched structurally.

## Component catalog
Dev-only route `GET /dev/ui-catalog` (`developer-console.ui-catalog`), gated by `permission:view_developer_console` (Super Admin), read-only, no mutation. View `resources/views/dev/ui-catalog.blade.php` showcases every component + states.

## Governance
`docs/ui/daengtisiams-ui-governance.md` + new read-only command `php artisan architecture:ui-governance-check` (`--json`, `--strict`): verifies docs exist, x-ui components exist, tokens present, and gold-is-not-primary-CTA holds. Informational; not wired into blocking `combinedDecision`.

## Tests / build
- New `tests/Feature/Ui/UiFoundationTest.php` — **7 passed / 22 assertions** (catalog auth, blue-not-gold primary, badge status mapping, all component render, governance GO).
- Regression: `tests/Feature/RME` + `tests/Feature/Owner` — **974 passed / 2968 assertions** (shared badge/button/card consumers, no regression).
- `architecture:ui-governance-check --json` → **GO**.
- Release gates after change: `security-compliance-check`, `cicd-enterprise-gate-check`, `enterprise-closure-check` → all **GO**.
- `npm run build` → **pass** (app CSS 87.4 kB / 14 kB gzip). `vendor/bin/pint --dirty` clean; `git diff --check` clean.

## Hard rules honored (no regression)
No business-logic rewrite · no migration/schema change · no route rename (one additive dev-only route only) · permissions/BranchContext/RME finalize/cashier consent/inventory ledger untouched · no heavy UI libs (Tailwind+Blade+Alpine only) · gold never a primary CTA · no hardcoded random colors in new UI.

## Known limitations / intentionally deferred
- Live layout still uses existing `layouts/sidebar.blade.php` + `layouts/partials/topbar.blade.php`; new `components/app/*` shell primitives are available but not yet wired (deferred to avoid risk).
- Module pages (RME detail, odontogram, kasir, inventory, lab, reports) inherit the new palette via shared primitives but are **not** individually re-laid-out this sprint.
- Node 18 environment; build passes (no oxide-blocking error observed).

## Next UIX sprint recommendation
UIX-2 Dashboard Owner polish → UIX-3 Kunjungan list → UIX-4 RME + Odontogram → UIX-5 Kasir/payment → UIX-6 Inventory → UIX-7 Lab pipeline → UIX-8 Reports/print/PDF.
