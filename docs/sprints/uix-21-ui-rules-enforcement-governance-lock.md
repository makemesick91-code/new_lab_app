# UIX-21 — UI/UX Rules Enforcement & Governance Lock

**Branch:** `feature/uix-21-ui-rules-enforcement-governance-lock`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous required GO:** `uix-20-permission-aware-ui-consistency-polish-go`
**Type:** Governance-lock sprint (targeted runtime enforcement only) — **no broad visual
change, no business-logic change.**

## Goal

Lock the DaengtisiaMS UI/UX foundation rules created from UIX-6 → UIX-20 into enforceable,
non-brittle governance so future UI work consistently follows the established design system,
component foundation, responsive rules, accessibility rules, performance rules, navigation
rules, and permission-aware rules. This is achieved by closing three concrete enforcement
gaps in the existing `architecture:ui-governance-check` command and capturing the enforced
standard durably in `docs/ui_design_system.md` and `docs/ui/daengtisiams-ui-governance.md`.

## Scope

Presentation/governance only. **No** route/controller/service/repository/permission/policy/
Gate/Spatie/BranchContext/schema/migration/query/financial/stock/RME/Lab/dashboard/branch/
master-data change. Blade + Tailwind + Alpine only; no new frontend dependency.

## Governance lock changes

Additions to `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` (a new
`--- UIX-21 ---` block appended immediately before the decision computation; every UIX-1 →
UIX-20 rule left intact):

### Rules added (hard — FAIL)

1. **vite.config framework-plugin lock (H1)** — `vite.config.*` must exist and must not
   reference a React/Vue/Svelte/Angular/Solid Vite plugin (`@vitejs/plugin-react`,
   `@vitejs/plugin-vue`, `plugin-svelte`, `plugin-solid`, `@analogjs/`, `@sveltejs/`, …).
   Complements the UIX-18 `package.json` dependency guard, which cannot see the build config.
2. **App-shell CDN-script lock (H2)** — no remote `<script src="http…">` in the navigation/
   layout shell foundation files (`layouts/app.blade.php`, `layouts/sidebar.blade.php`,
   `layouts/partials/sidebar.blade.php`, `layouts/partials/topbar.blade.php`). Extends the
   UIX-18 CDN scan, which previously only covered `resources/views/components/ui/*`.
3. **`x-ui.card` action-group wrap lock (H3)** — when the card renders an actions slot it
   must keep `flex-wrap` so header actions never overflow on narrow widths (aligns the card
   with the UIX-16 filter-bar/page-header responsive-action guarantee).

### Rules added (soft — WATCH)

- `docs/ui_design_system.md` documents the UIX-21 lock.
- `docs/ui/daengtisiams-ui-governance.md` keeps its **"No formal WCAG"** honest-limitation
  disclaimer (locks the honesty statement so a future edit cannot silently over-claim).
- UIX-21 sprint evidence doc exists (this file).

## Rules intentionally avoided (brittle)

- App-wide legacy-color/token sweeps across all views (would false-fail on legacy module
  bodies outside the scoped foundation).
- A "every view must use `x-ui.*`" mandate.
- Asset-hash / generated-filename requirements (governance must not depend on build output).
- Browser-required checks (CI has no browser).
- WCAG/Lighthouse over-claim keyword scans (false-positive-prone; the positive honesty
  disclaimer is locked instead).
- Scanning docs for the literal terms `KTP`/`NIK` (the docs legitimately state the privacy
  rule).

## Files changed

- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` — UIX-21 lock block.
- `docs/ui_design_system.md` — UIX-21 lock section.
- `docs/ui/daengtisiams-ui-governance.md` — UIX-21 section.
- `docs/sprints/uix-21-ui-rules-enforcement-governance-lock.md` — this evidence doc.
- `tests/Feature/Ui/UiRulesEnforcementGovernanceLockUixTest.php` — UIX-21 lock tests.

## Preservation confirmation

- **UIX-6 → UIX-20 preserved** — no existing rule removed or weakened; the UIX-21 block is
  purely additive and runs before the same decision computation.
- **No visual broad rewrite** — no view restyled; only the governance command + docs + a test.
- **No runtime behavior change** — the governance command is read-only; no application code
  path changed.
- **No route/policy/permission/query/data change; no permission/role semantic change.**
- **No new frontend dependency; no React/Vue/heavy dependency.**
- **No frontend-only authorization** — server-side authorization remains authoritative.
- **No sensitive data exposure** — no KTP/NIK/scan/raw-note/secret/env rendered or logged.

## Governance false-positive risk

Low. All three hard rules assert stable, positive invariants on foundation files that
already satisfy them on a clean tree (`vite.config.js` has only the laravel plugin; the
shell files carry no CDN script; `x-ui.card` already uses `flex-wrap`). No dependency on
asset hashes, timestamps, local paths, or a browser. Known accepted behavior: the command
scans Blade component source including comments, so foundation-component comments must avoid
literal banned tokens (documented in both docs).

## Validation

- `php artisan architecture:ui-governance-check --strict` → GO
- `php artisan foundation:roadmap-check --strict` → GO (next: MON-1)
- `php artisan foundation:security-compliance-check` → GO
- `php artisan test --filter=Ui`
- `npm run build`, `./vendor/bin/pint --dirty`, `php artisan view:cache`, `git diff --check`

## Next recommended sprint

UIX-22 — Enterprise-Clean UI/UX Final Closure (awaiting explicit user approval).
