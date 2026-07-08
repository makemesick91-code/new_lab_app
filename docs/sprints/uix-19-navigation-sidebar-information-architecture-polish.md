# UIX-19 — Navigation, Sidebar & Information Architecture Polish

**Branch:** `feature/uix-19-navigation-sidebar-information-architecture-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous GO:** `uix-18-performance-asset-weight-audit-go`

## Scope

Presentation-only polish of the single navigation shell (sidebar + topbar) and its
information architecture. Improves operator navigation clarity — semantic-token chrome,
consistent IA section labels, preserved active states — **without** changing any route,
permission, policy, Gate, BranchContext, workflow gate, or server-side authorization.

## Runtime navigation / sidebar / IA changes

- **Sidebar chrome → semantic tokens** (`resources/views/layouts/partials/sidebar.blade.php`):
  `border-gray-*` → `border-hairline`, `text-gray-900` → `text-navy`, `text-gray-500` →
  `text-ink-soft`, chevron `text-gray-400` → `text-ink-muted`. No legacy `teal-*`/`*-gray-*`
  chrome remains.
- **IA section labels** using the existing-but-unused `.menu-group-title` primitive
  (`px-3 py-2 text-xs font-bold uppercase tracking-wider text-ink-muted`): `Klinik & RME`,
  `Laboratorium`, `Inventaris & Pembelian`, `Keuangan & Laporan`, `Administrasi Sistem`.
  Each label is wrapped in a guard that is the **exact union** of its child links'
  `@can/@canany` guards (new `$showLabGroup` / `$showFinanceReportingGroup` / `$showAdminGroup`
  booleans, plus the existing `$showInventory*Group` booleans), so a label renders only when
  ≥1 of its links is visible — no empty header, no hidden label above visible links.
- The lone legacy inline "Laporan RME" label was converted to the `.menu-group-title` primitive.
- **Topbar chrome → tokens** (`resources/views/layouts/partials/topbar.blade.php`):
  `border-gray-*` → `border-hairline`, `text-gray-*` → `text-navy`/`text-ink`/`text-ink-soft`/
  `text-ink-muted`, `hover:bg-gray-50` → `hover:bg-navy-50`, `focus:ring-teal-500` →
  `focus:ring-brand-500`, `text-teal-700`/`bg-teal-50` → brand tokens.
- **Preserved:** every `@can`/`@canany`/`@role`/`@unless` guard (70 directives), every route
  name/path, the `menu-item-active`/`menu-subitem-active` active-state classes and their
  `routeIs(...)` matching, the `adlmsSidebar` Alpine collapsible mechanism +
  `data-sidebar-panel` localStorage persistence, the tested labels ("Dashboard RME", "Kunjungan",
  "Order Lab", "Kasir RME", "Master Data RME", etc.), and UIX-16 responsive + UIX-17 a11y semantics.

## Modules / groups affected

Navigation shell only: Klinik/RME, Laboratorium (lab orders / candidates / production / QC /
delivery), Inventaris & Pembelian (master data / stock ops / reports-analytics / procurement),
Keuangan & Laporan (finance / reporting), Administrasi Sistem (master-data-RME / settings /
developer console). No module page bodies were restyled.

## No-change confirmations

- **Route names/paths:** unchanged (no route added/renamed/removed).
- **Permission/role semantics:** unchanged (no permission/role/policy/Gate change).
- **`@can`/`@canany` guards:** all preserved; navigation stays permission-aware.
- **Server-side authorization:** unchanged — sidebar `@can` is presentation only; route
  middleware/policy remains authoritative.
- **Controller/service/repository/query/data/business logic:** unchanged.
- **Schema/migration:** none.
- **No hidden critical module; no unauthorized link; no React/Vue/heavy nav dependency.**
- **Sensitive data:** no KTP/NIK/scans/raw notes/secrets/env exposed.

## Files changed

- `resources/views/layouts/partials/sidebar.blade.php`
- `resources/views/layouts/partials/topbar.blade.php`
- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` (UIX-19 non-brittle rules)
- `docs/ui_design_system.md` (UIX-19 navigation/IA standard)
- `docs/ui/daengtisiams-ui-governance.md` (UIX-19 rules)
- `docs/sprints/uix-19-navigation-sidebar-information-architecture-polish.md` (this doc)
- `tests/Feature/Ui/NavigationSidebarUixTest.php` (new)

## Governance

`architecture:ui-governance-check --strict` extended with UIX-19 rules: shell files exist with
no legacy `teal-*`/`*-gray-*` chrome; sidebar uses the `menu-group-title` IA primitive; the
`menu-item-active`/`menu-subitem-active` active-state classes are preserved; sidebar permission
guards are preserved (non-brittle `@can` count threshold). Soft signals: UIX-19 sprint doc +
design-system documentation present.

## Tests

- `tests/Feature/Ui/NavigationSidebarUixTest.php` — IA section labels render for a privileged
  operator, clinical-only Doctor keeps the clinical label but not admin/lab/finance sections,
  the admin section label appears only when an admin module is reachable, and an unauthenticated
  visitor is redirected (navigation is not a security boundary).
- Regression: `--filter=Ui`, `--filter=Uix`, `--filter=SidebarPermissionVisibility`,
  `--filter=Permission`.

## Risk / rollback

Low risk — presentation-only Blade class + additive permission-guarded labels; no route/permission/
policy/query/schema change. Rollback: revert the branch merge commit (no migration, no data change).
