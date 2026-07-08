# UIX-14 — Settings, Master Data & Access Control Polish

Branch: `feature/uix-14-settings-master-data-access-control-polish`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Previous GO: `uix-13-owner-dashboard-kpi-polish-go`

## Scope

Presentation-only polish of the Settings, master-data, and access-control surfaces
onto the DaengtisiaMS UIX-1 design system. The settings/master-data/access-control
**index pages become the reference admin list surface**, and `clinic-rooms/_form`
becomes the reference master-data form (adopting the `x-ui.*` form controls).

**No** controller / service / repository / route / permission / policy / Gate /
Spatie role-permission / BranchContext / schema / migration / master-data semantics /
financial / RME / Lab / inventory / dashboard-KPI change. Blade + Tailwind + Alpine only.

## Runtime UI changes

- **10 index pages rebuilt** on the list standard (`x-ui.filter-bar` + `x-ui.table` +
  `x-ui.badge` + `x-ui.button` + `x-ui.empty-state`, semantic tokens, `x-ui.card` shell):
  clinic-rooms, treatment-categories, treatments, tariffs, payment-methods, branches,
  wa-reminder-templates, users, roles, permissions. Status/role/flag badges now use the
  shared `x-ui.badge` tone map; danger actions (Hapus) use `variant="danger"`; activate/
  deactivate use `success`/`warning`; edit uses `secondary`.
- **WA reminder template list** keeps its manual-only safety notice (rendered via
  `x-ui.alert variant="warning"`) and variable-reference note (`x-ui.alert variant="info"`).
  WA remains a **manual copy-paste SOP** — no auto-send, no WhatsApp API, no new automation.
- **Reference master-data form** `clinic-rooms/_form` adopts `x-ui.input` / `x-ui.select` /
  `x-ui.textarea` (inline validation via the shared error bag); `clinic-rooms/create` +
  `clinic-rooms/edit` adopt the `x-ui.card` form shell + standardized save/cancel action
  hierarchy (`x-ui.button` primary submit + `ghost` Batal).
- **Palette normalization** of the remaining create/edit/_form partials for
  treatment-categories, treatments, tariffs, payment-methods, branches,
  wa-reminder-templates, users, roles (legacy indigo/teal → brand, red action → danger,
  amber → warning, yellow → warning, blue → info, legacy card shell → tokens). All backend
  logic (password fields, permission-matrix Alpine, danger sections) preserved verbatim.

## Governance

`ArchitectureUiGovernanceCheckCommand` extended with **non-brittle UIX-14 rules**:

- The 10 settings list views exist and use `x-ui.filter-bar` / `x-ui.table` / `x-ui.badge` /
  `x-ui.button` / `x-ui.empty-state`; no legacy palette (teal/indigo/emerald/amber/rose/sky/
  purple), no legacy gray, no hardcoded hex, no `variant="gold"` CTA, no `->ktp/nik/
  identity_number` rendered.
- The `clinic-rooms/_form` master-data form reference uses `x-ui.input`/`select`/`textarea`.
- A set of settings/access-control forms stay off the legacy teal/indigo palette, free of
  hardcoded hex, gold-CTA-free, and never render KTP/NIK.
- The WA reminder template list must keep the manual-only safety notice
  ("belum mengirim WhatsApp otomatis").

## Security / RBAC / master-data invariants preserved

- Permission semantics: **no change** (no permission added/removed/renamed).
- Role semantics: **no change** (no role added/removed/renamed).
- Spatie Permission / `Gate::before` / policy / route-middleware behavior: **no change**
  (every `@can`/`@canany` gate and route middleware unchanged).
- BranchContext: **not bypassed**; no `branch_id` trusted from request; branch/master data
  exposure unchanged.
- Master-data semantics (treatment/tariff/payment-method/clinic-room/branch, tariff
  uniqueness & effective-date): **no change**.
- WA reminder: **manual master-template only** — no auto-send, no WA API/vendor.
- Sensitive data: no full KTP/NIK, scans, raw notes, secrets, tokens, or env values exposed.
- Route / schema / migration: **no change**.

## Risk / rollback

Low risk — presentation-only Blade changes plus a read-only governance rule addition.
Rollback: revert the branch/PR merge commit; no data or schema migration to reverse.
