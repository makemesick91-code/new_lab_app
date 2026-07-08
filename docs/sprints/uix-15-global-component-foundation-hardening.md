# UIX-15 — Global Component Foundation Hardening

**Branch:** `feature/uix-15-global-component-foundation-hardening`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous GO:** `uix-14-settings-master-data-access-control-polish-go`
**Date:** 2026-07-08

## Mission

Harden the shared `x-ui.*` component foundation built across UIX-1 → UIX-14 so future
DaengtisiaMS UI/UX work stays consistent. **Foundation-hardening, not a page rewrite.**
Additive and backward compatible — no public component prop was renamed or removed.

## Runtime component changes

1. **`resources/views/components/ui/badge.blade.php`**
   - `status` resolution is now **case-insensitive** (`Str::lower(...)` before the map
     lookup), so uppercase lifecycle codes (invoice `PAID`/`UNPAID`/`VOID`/`PARTIAL`,
     procurement `POSTED`/`SUBMITTED`, lab `DELIVERED`, …) resolve to the same tone as
     their lowercase form. A `strtolower(...)` wrapper at the call site is no longer
     required. Unknown statuses still fall back to `neutral`.
   - Canonical status map extended (additive — no pre-existing mapping changed tone):
     `unpaid`/`partial`/`overstock` → warning; `registered`/`submitted` → info;
     `posted`/`received` → success; `void` → danger.

2. **`resources/views/components/ui/table.blade.php`**
   - Tokenized residual legacy drift: `divide-gray-200` → `divide-hairline`,
     caption `text-gray-900` → `text-navy`. The foundation table now matches the tokens
     the list pages already use around it.

No other `x-ui.*` component was changed. Domain components (`x-lab.status-badge`,
`x-rme.invoice-summary`, `x-inventory.*`) were **not** modified — they already route
through the foundation; their boundaries are now documented.

## Governance (`architecture:ui-governance-check`)

Non-brittle UIX-15 rules added:
- `x-ui.badge` must resolve `status` case-insensitively and map the canonical financial
  statuses (`unpaid`/`partial`/`void`/`paid`).
- `x-ui.table` must carry no legacy gray class and use `divide-hairline`.
- Domain badge components (`x-lab.status-badge`, `x-rme.invoice-summary`) must render
  through `x-ui.badge` (no forked design system).
- Soft signals: design-system doc documents UIX-15; sprint evidence doc exists.

## Backward compatibility

- All pre-existing `x-ui.badge` tone/status mappings are unchanged.
- Case-insensitive resolution only affects statuses that previously fell through to
  `neutral` (uppercase codes) — now they resolve to their correct semantic tone. This is
  a deliberate, tested convergence with `x-lab.status-badge`, not a regression.
- The `divide-hairline` table token is visually equivalent to the removed
  `divide-gray-200` and matches the surrounding list pages.
- No public prop renamed or removed; existing component calls are unaffected.

## Files changed

- `resources/views/components/ui/badge.blade.php` — case-insensitive + expanded status map
- `resources/views/components/ui/table.blade.php` — token drift removed
- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` — UIX-15 rules
- `docs/ui_design_system.md` — canonical foundation section (UIX-15)
- `docs/ui/daengtisiams-ui-governance.md` — UIX-15 governance rules
- `docs/sprints/uix-15-global-component-foundation-hardening.md` — this doc
- `tests/Feature/Ui/GlobalComponentFoundationUixTest.php` — component compatibility tests

## Tests

- `GlobalComponentFoundationUixTest` (8) — badge case-insensitivity, backward compat,
  new canonical statuses, neutral fallback, explicit tone, table tokens, lab domain
  badge routing through the foundation, governance GO under `--strict`.
- Existing `UiFoundationTest` unaffected.

## Confirmations

- **No broad view rewrite** — only 2 foundation components + governance + docs + 1 test.
- **No React/Vue/SPA/heavy dependency** — Blade + Tailwind + Alpine only.
- **No business logic in components** — badge/table are presentation-only.
- **No permission logic in generic components.**
- **No route/controller/service/repository/schema/migration change.**
- **No business-rule change** (payment/stock/RME/Lab/dashboard/branch unchanged).
- **No PII/KTP/NIK/scans/raw notes/secrets/env exposure.**
- **dompdf print templates unchanged** — stay table-safe.

## Risk & rollback

- **Risk:** low. Additive/backward-compatible; the only visible change is uppercase
  domain statuses now rendering with their semantic tone (previously neutral gray).
- **Rollback:** revert the merge commit; deploy the previous GO tag
  `uix-14-settings-master-data-access-control-polish-go` via the VPS deploy runner.

## Next recommended sprint

UIX-16 — Responsive, Tablet & Operator Smoke Polish (awaiting explicit approval).
