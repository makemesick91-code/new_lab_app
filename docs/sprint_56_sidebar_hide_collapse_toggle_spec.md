# Sprint 56 — UI Sidebar Hide / Collapse Toggle — Implementation Spec

**Branch:** `feature/sprint-56-ui-sidebar-hide-collapse-toggle`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Mode:** LIMIT SAVER 1 — UI-only, minimal patch.

## 1. Goal
Add a desktop sidebar hide/show toggle to the authenticated app shell so users can
collapse the sidebar (and reflow the main content) — useful for screenshots and owner
presentation. State persists across page loads via `localStorage`.

## 2. Non-goals
- No new dependency (no `@alpinejs/persist`).
- No change to mobile off-canvas behavior, permissions, policies, middleware, routes,
  schema, migrations, or role assignment.
- No change to the collapsible sidebar **group** state (`adlmsSidebar` / `adlms-sidebar-groups`).
- No "mini/icon-rail" collapse — scope is full hide/show on desktop (simpler, safe).

## 3. Files to inspect (done)
- `resources/views/layouts/app.blade.php` — body Alpine root, content wrapper offset.
- `resources/views/layouts/partials/topbar.blade.php` — header, mobile hamburger.
- `resources/views/layouts/partials/sidebar.blade.php` — `<aside>` translate binding.
- `resources/js/app.js` — `Alpine.store('sidebar', …)`, localStorage convention.

## 4. Files expected to change
1. `resources/js/app.js` — store owns `isExpanded` persistence (`init()` + `persistExpanded()`).
2. `resources/views/layouts/app.blade.php` — bind content wrapper margin to `isExpanded`;
   stop force-resetting `isExpanded` on resize (preserve user preference).
3. `resources/views/layouts/partials/sidebar.blade.php` — bind `<aside>` desktop translate
   to `isExpanded`.
4. `resources/views/layouts/partials/topbar.blade.php` — add desktop-only toggle button.
5. `tests/Feature/Navigation/SidebarCollapseToggleTest.php` — new regression test.

## 5. UX behavior — desktop (≥1280px / `xl`)
- Sidebar visible by default (`isExpanded = true`).
- Toggle button in topbar (left of mobile region, shown `hidden xl:inline-flex`).
- Click → `$store.sidebar.toggleExpanded()`: aside slides out (`xl:-translate-x-full`),
  content margin collapses (`xl:ml-0`), so main content expands full width.
- Click again → restores aside + `xl:ml-[290px]`.

## 6. UX behavior — mobile (<1280px)
- Unchanged. Off-canvas driven by `isMobileOpen`; hamburger still calls `toggleMobileOpen()`.
- All `isExpanded`-driven classes are `xl:`-prefixed, so they are inert below `xl`.
- Desktop toggle button is hidden on mobile (`hidden xl:inline-flex`).

## 7. Accessibility
- Toggle is a real `<button type="button">` (keyboard focusable, Enter/Space activate).
- `:aria-expanded="$store.sidebar.isExpanded"`.
- `:aria-label` / `:title` switch between "Sembunyikan sidebar" / "Tampilkan sidebar".
- Visible focus ring matches existing buttons (`focus:ring-2 focus:ring-teal-500`).
- Icon `aria-hidden="true"`.

## 8. Persistence
- Key: `adlms-sidebar-expanded` (matches existing `adlms-` namespace).
- Value: `"true"` / `"false"` string.
- Read in store `init()` (Alpine auto-calls store `init`); default `true` when unset.
- Written in `persistExpanded()`, wrapped in `try/catch` (private mode safe), mirroring the
  existing `adlmsSidebar` localStorage convention.

## 9. Test plan
`tests/Feature/Navigation/SidebarCollapseToggleTest.php` (Pest, `seedAccessControl()`):
- Authenticated dashboard render contains the toggle marker
  (`data-testid="sidebar-collapse-toggle"`) and `toggleExpanded()` wiring.
- Rendered content wrapper contains the conditional `xl:ml-0` binding.
- Static assertion that `resources/js/app.js` contains the `adlms-sidebar-expanded`
  localStorage key (covers persistence requirement, which is JS not HTML).
Commands: `php artisan test --filter=SidebarCollapseToggle`, then `./vendor/bin/pint --test`.

## 10. Risk checklist
- [ ] No permission slug / role / policy / middleware change.
- [ ] No route, controller, or schema/migration change.
- [ ] No new composer/npm dependency.
- [ ] Mobile off-canvas unchanged (bindings `xl:`-scoped).
- [ ] No KTP/WA/patient/secret data surface change (pure layout chrome).
- [ ] Resize no longer overrides stored desktop preference (intended).

## 11. Rollback plan
- Revert the 5 changed files (single focused commit). No data/state migration to undo;
  a stale `adlms-sidebar-expanded` localStorage value is harmless and ignored once the
  binding is gone.
