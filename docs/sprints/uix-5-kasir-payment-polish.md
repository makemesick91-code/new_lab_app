# UIX-5 — Kasir / Payment Polish (2026-07-07)

**Branch:** `feature/uix-5-kasir-payment-polish` (base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`; do NOT target main).

**Type:** Fifth UI/UX sprint — **presentation-only** polish of the Kasir / Pembayaran surface onto the UIX-1 luxury healthcare design system. It becomes the reference implementation for every financial workflow page (Kasir, Pembayaran, Invoice, Piutang).

## Baseline verification (before start)
- UIX-1 GO tag `uix-1-daengtisiams-luxury-healthcare-design-system-foundation-go` → `0d78f92`
- UIX-2 GO tag `uix-2-dashboard-owner-polish-go` → `91364ed`
- UIX-3 GO tag `uix-3-kunjungan-list-polish-go` → `9fce4ac`
- UIX-4 GO tag `uix-4-rme-odontogram-polish-go` → `6c28bab`
- Baseline gates all GO: `architecture:ui-governance-check --strict`, `foundation:security-compliance-check`, `foundation:cicd-enterprise-gate-check`, `foundation:enterprise-closure-check`. Worktree clean.

## Graphify / surface map
Cashier surface = `rme.cashier.*` (routes/web.php, `permission:manage_rme_billing`):
`cashier.index` · `cashier.handoff` · `cashier.receivables` (+ export/follow-ups) · `cashier.create` · `cashier.store` · `cashier.show` · `cashier.payment.create` · `cashier.payment.store` · `cashier.receipt.show`.
Views under `resources/views/rme/cashier/`: `index`, `show`, `create`, `payment/create`, `receipt/show`, `receivables`, `handoff`, `follow-ups/create`, `partials/clinical-summary`.

## Files changed
- `resources/views/rme/cashier/index.blade.php` — rebuilt on the UIX-3 list standard: `x-ui.page-header` (breadcrumb + Piutang RME action) + `x-ui.filter-bar` + `x-ui.input` + `x-ui.table` + `x-ui.badge` + `x-ui.button` + `x-ui.empty-state`. Same `search` GET param, same links, same pagination.
- `resources/views/rme/cashier/show.blade.php` — header → `x-ui.page-header`; all financial amounts retokenized (paid=success, remaining=warning, grand total=brand); invoice status badge/tone preserved.
- `resources/views/rme/cashier/create.blade.php` — header → `x-ui.page-header`; treatment hint box → `x-ui.alert variant="info"`. Item-row JS (`addItem`/`removeItem`/`recalculate`/`fillDescription`) and field names untouched.
- `resources/views/rme/cashier/payment/create.blade.php` — header → `x-ui.page-header`; **consent gate → `x-ui.alert variant="warning"` (calm but clear)** — checkbox field names (`consent_signed_by_patient`, `consent_signed_by_doctor`) and validation preserved; Alpine `x-data`/`x-ref`/`x-bind` payment + selectable-receivable logic untouched; amount `max`/step and default preserved.
- `resources/views/rme/cashier/receipt/show.blade.php` — screen-only header → `x-ui.page-header class="print:hidden"`; **`@media print` block, `background:#fff`, `window.print()`, `LUNAS` stamp, and all receipt content preserved**.
- `resources/views/rme/cashier/receivables.blade.php` — header → `x-ui.page-header`; filter form → `x-ui.filter-bar` + `x-ui.input`/`x-ui.select` (same 6 GET params); status → `x-ui.badge`; manual-WA notice → `x-ui.alert`. Aging/summary logic untouched.
- `resources/views/rme/cashier/handoff.blade.php` — header → `x-ui.page-header`; empty state → `x-ui.empty-state`. Existing badges/table/filter preserved.
- `resources/views/rme/cashier/follow-ups/create.blade.php` — header → `x-ui.page-header`; client-only manual WhatsApp helper preserved (server never sends/calls any API).
- Palette conversion (all 8 views): teal→brand, emerald→success, amber→warning, sky→info, rose/red→danger, blue/purple→brand/info, green(WA)→success, gray→navy/ink/hairline — via semantic tokens.
- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` — added non-brittle UIX-5 rules (see below).
- `docs/ui/daengtisiams-ui-governance.md`, `docs/ui/daengtisiams-implementation-checklist.md` — durable cashier/payment-page standard.
- `tests/Feature/Ui/CashierPaymentUixTest.php` — new (12 tests).

## Files intentionally NOT touched
- `resources/views/rme/cashier/partials/clinical-summary.blade.php` — shared clinical partial; neutral gray labels kept per the UIX-4 clinical-`<dl>` precedent (only cashier-included).
- Any controller/service/request/policy/route/migration.

## Governance rules added (UIX-5)
`architecture:ui-governance-check` now also asserts (read-only, non-brittle):
- cashier list + payment views exist;
- cashier list uses page-header/filter-bar/table/badge/button/empty-state;
- payment view uses page-header/card/badge/button/alert (consent gate);
- no legacy `teal-*`, no `variant="gold"` CTA, and no `->ktp/nik/identity_number` render across all 8 cashier views (clinical-summary partial excluded; hex not scanned because the receipt needs print-only `#fff`);
- UIX-5 evidence doc present (soft signal).

## Guarantees
- **Presentation-only.** No change to consent gate logic, payment calculation, invoice status, partial-payment / receivable calculation, payment method behavior, grand total / paid / remaining, or visit transitions.
- No controller/service/query/permission/policy/BranchContext/route name/schema/migration change.
- No new sensitive data exposed; KTP/NIK never rendered on any cashier surface.
- No new/heavy frontend dependency; Tailwind + Blade + Alpine only. No payment gateway/QRIS integration added.
- Print/kwitansi content and behavior preserved.

## Validation
- `tests/Feature/Ui/CashierPaymentUixTest.php` — 12 passed.
- Regression: `tests/Feature/Ui`, `--filter=Cashier`, `--filter=Payment`, `--filter=Receivable`, `--filter=Consent`, `tests/Feature/RME` — green (see PR/CI).
- `architecture:ui-governance-check --strict` → GO; `foundation:security-compliance-check` / `cicd-enterprise-gate-check` / `enterprise-closure-check` → GO.
- `npm run build`, `vendor/bin/pint --dirty`, `git diff --check`, `php artisan view:cache` — clean/pass.

## Deferred
- Payment gateway / QRIS integration.
- Deeper invoice/print/PDF polish (UIX-8).
- Advanced receivable dashboard polish.
- Cashier workflow logic improvements.

## Next UIX
UIX-6 Inventory · UIX-7 Lab pipeline · UIX-8 Reports/print/PDF.
