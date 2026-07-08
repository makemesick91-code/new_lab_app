# UIX-17 — Accessibility, Error State & Empty State Polish

**Branch:** `feature/uix-17-accessibility-error-empty-state-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous GO:** `uix-16-responsive-tablet-operator-smoke-polish-go`
**Type:** Presentation/semantics-only UI hardening (additive accessibility).

## Scope

Improve accessibility, validation visibility, error states, disabled/loading states,
and empty states across high-frequency DaengtisiaMS surfaces by hardening the shared
`x-ui.*` foundation. Because the foundation cascades to every form/table/action already
migrated in UIX-3..UIX-16, a small, safe set of component-level changes lifts
accessibility across RME, Cashier, Inventory, Lab, Reports, and Settings at once.

**No** business/validation/permission/policy/Gate/BranchContext/route/query/schema/
migration/financial/stock/RME/Lab/dashboard/master-data behaviour changes. Server-side
validation is untouched — nothing is replaced by frontend-only validation.

## Component-level changes (cascading)

- **`x-ui.input` / `x-ui.select` / `x-ui.textarea`** — the visible error/help text is now
  programmatically associated with the control:
  - error `<p>` gets `id="{id}-error"`, help `<p>` gets `id="{id}-help"`;
  - the control gets `aria-describedby` pointing at whichever is shown (error takes
    precedence over help — matching the existing display logic);
  - `aria-invalid`/`aria-required` and the existing `$errors`-bag resolution are unchanged.
  - Only wired when the field has an `id` (native semantics; no fake ARIA on anonymous fields).
- **`x-ui.button`** — the loading state now carries a screen-reader-only "Memproses…" label
  alongside the existing spinner + `aria-busy="true"` + `disabled`/`aria-disabled`.
- **`x-ui.alert`** — keeps `role="alert"` (verified by governance).
- **`x-ui.empty-state`** — keeps `title` + `description` (explain what happened / what next),
  verified by governance.

These changes are additive: no prop was renamed/removed, no field name/method/action/hidden
input/CSRF/Alpine behaviour changed, and keyboard/submission behaviour is identical.

## Representative pages covered

The foundation change cascades to every high-frequency surface already built on `x-ui.*`:
- RME: visit create/edit, patient queue, medical record/odontogram, cashier/payment/receivable.
- Inventory: products/forms, stock/batches, PR/PO/GR/transfer/opname forms.
- Lab: lab order / case-candidate forms & details.
- Reports: filter bars & no-data states.
- Settings: clinic rooms, treatments, tariffs, payment methods, users/roles/permissions.

No broad page rewrite was performed (baseline already strong from UIX-3..16); the sprint is
deliberately component-first so the improvement is consistent and low-risk.

## Governance (`architecture:ui-governance-check --strict`)

New non-brittle UIX-17 rules:
- `x-ui.input`/`select`/`textarea` must wire `aria-describedby`, give error/help text a
  stable `-error`/`-help` id, and expose `aria-invalid` + `aria-required`.
- `x-ui.button` must expose `aria-busy` + an `sr-only` loading label.
- `x-ui.alert` must keep `role="alert"`.
- `x-ui.empty-state` must keep a `description`.
- Soft signals: design doc documents UIX-17; this sprint evidence doc exists.

## Tests

- New `tests/Feature/Ui/AccessibilityErrorEmptyStateUixTest.php` (group `AccessibilityErrorEmptyStateUix`)
  asserts the component contracts via `Blade::render` (error/help association, invalid/required
  exposure, button busy + sr-only, alert role, empty-state description) and that
  `architecture:ui-governance-check --strict` returns GO.

## Confirmations

- No validation semantics change.
- No logic/route/policy/query/data/business-rule change.
- No hidden critical data; no actions removed.
- No React/Vue/SPA/heavy dependency — Blade + Tailwind + Alpine only.
- No sensitive data (KTP/NIK/scans/raw notes/secrets/env) exposed.
- No formal WCAG conformance claimed (no formal audit performed).

## Rollback

Revert the merge commit / roll back to `uix-16-responsive-tablet-operator-smoke-polish-go`.
Changes are additive Blade/semantics + governance/docs/test only; no data or schema impact.
