# UIX-18 — Performance & Asset Weight Audit

**Branch:** `feature/uix-18-performance-asset-weight-audit`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous required GO:** `uix-17-accessibility-error-empty-state-polish-go`
**Type:** Audit-heavy; one safe runtime dependency fix + governance/test/docs guardrails.

## Scope

Audit the UI/UX performance and asset weight after UIX-6 → UIX-17 and confirm the
modernization remains lightweight, Blade + Tailwind + Alpine only, and production-safe.
Implement a real fix where safe bloat / heavy dependency risk is proven; otherwise lock
guardrails. No speculative optimization.

## Measured audit findings

| # | Finding | Classification | Action |
| --- | --- | --- | --- |
| 1 | `@tailwindcss/vite@^4.0.0` declared but never wired into `vite.config.js`; app builds on Tailwind v3 via `postcss.config.js`. Pulled the whole unused Tailwind v4 native toolchain (`@tailwindcss/oxide` ~2.9 MB ×2, `@tailwindcss/node` ~1 MB, `@tailwindcss/vite` ~876 KB), 49 `package-lock.json` lines. | **Measured issue → safe fix** | Removed the devDependency. Build CSS/JS **byte-identical**; `npm ci` clean. |
| 2 | Built bundle: CSS 85.8 KB (13.8 KB gzip) + JS 96.5 KB (34.9 KB gzip), 56 modules, `public/build` ~192 KB. | Healthy | No action |
| 3 | Zero runtime `dependencies`; no React/Vue/SPA/chart/datatable/admin template anywhere in `resources`, `package.json`, or `vite.config.js`. | Healthy | No action |
| 4 | No CDN `<script src="http…">` in any Blade view (0 hits). All assets ship via Vite. | Healthy | No action |
| 5 | No `setInterval` polling. 7 `setTimeout` (one-shot: toast auto-dismiss, modal focus, canvas restore, profile-save notice) + 5 `fetch` (on-demand: KTP scan, patient/doctor lookups). | Healthy | No action |
| 6 | 14 `x-ui.*` components, 496 lines total — all lean, no accidental heavy markup / regression. | Healthy | No action |
| 7 | `lightningcss*` remains in the lockfile — it is a Vite optional CSS engine, pre-existing and unrelated to the removed plugin. | No action needed | Documented deferral |

## Asset baseline (before → after)

Removing the dead devDependency did **not** change the built output:

| Artifact | Before | After |
| --- | --- | --- |
| `app-*.css` | 85 806 bytes (`app-CbJg4c5p.css`) | 85 806 bytes (`app-CbJg4c5p.css`) |
| `app-*.js` | 96 544 bytes (`app-JStlj-rZ.js`) | 96 544 bytes (`app-JStlj-rZ.js`) |
| `public/build` total | ~192 KB | ~192 KB |
| `@tailwindcss/*` in `node_modules` | forms, node, oxide, oxide-linux-x64-gnu, oxide-linux-x64-musl, vite | forms only |
| lockfile v4-toolchain refs | 86 | 37 (lightningcss only, Vite-owned) |

## Runtime change

- `package.json`: removed `"@tailwindcss/vite": "^4.0.0"` (unused, Tailwind v4).
- `package-lock.json`: reconciled by `npm install` — pruned the v4 oxide/node/vite subtree.

No other runtime change. No controller / service / repository / route / policy / permission
/ query / migration / Vite-config / Tailwind-config / business-logic change.

## Governance / rules

- `ArchitectureUiGovernanceCheckCommand` (`architecture:ui-governance-check`) extended with
  non-brittle **UIX-18** rules: `package.json` declares no forbidden heavy frontend library
  (React/Vue/Svelte/Angular/jQuery/Bootstrap/Chart.js/ApexCharts/ECharts/Highcharts/Plotly/
  DataTables/ag-Grid/Handsontable/moment/select2/`@tailwindcss/vite`); no CDN
  `<script src="http…">` in `x-ui.*`; (soft) Vite manifest present, design doc documents
  UIX-18, and the sprint evidence doc exists.
- `docs/ui_design_system.md`: new **Performance & asset-weight standard (UIX-18)** section
  with the measured baseline and the lightweight-UI foundation rules.

## Tests

- New `tests/Feature/Ui/PerformanceAssetWeightUixTest.php` (6): no heavy frontend dep; the
  Tailwind v4 plugin is gone while v3 remains; no CDN script in `x-ui.*`; no chart/datatable/
  SPA import in `app.js`; built bundle within the ~512 KB budget when built; the governance
  command passes strict with no UIX-18 finding.

## Confirmations

- **No React/Vue/heavy dependency** added or present.
- **No new frontend dependency** added (one dead devDependency removed).
- **No query / data / business-logic change.**
- **No route / policy / permission / schema / migration change.**
- **UIX-16 responsive behavior preserved** (no table/overflow/stacking change).
- **UIX-17 accessibility semantics preserved** (no ARIA/role/label change).
- **No sensitive data exposure** (no KTP/NIK/scans/raw notes/secrets/env values touched).

## Risk & rollback

Low risk. The only runtime change removes a provably unused devDependency; build output is
byte-identical and `npm ci` is clean. Rollback: revert the merge commit (or restore the
`@tailwindcss/vite` line in `package.json` + `npm install`) and redeploy the prior GO tag
`uix-17-accessibility-error-empty-state-polish-go`.

## Next recommended sprint

UIX-19 — Navigation, Sidebar & Information Architecture Polish (awaiting explicit approval).
