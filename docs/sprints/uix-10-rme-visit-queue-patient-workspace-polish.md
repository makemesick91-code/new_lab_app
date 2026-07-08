# UIX-10 — RME Visit, Queue & Patient Workspace Polish

**Branch:** `feature/uix-10-rme-visit-queue-patient-workspace-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Type:** Presentation-only UI/UX polish (no business/clinical/workflow logic change).

## Scope

Applied the DaengtisiaMS UIX-1 design system to the RME **daily operator**
surfaces that were still on the legacy teal palette. The Kunjungan list
(`rme.visits.index`, UIX-3) and visit detail (`rme.visits.show`, UIX-4) were
already polished; this sprint covers the remaining operator pages.

The **Antrian Pasien** queue becomes the reference RME queue list page, and the
room worklist reuses the same list standard.

## Runtime UI changes

- **`rme/patient-queue/index.blade.php`** (reference queue page) — rebuilt on
  `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.input`/`x-ui.select` (same GET
  params `search`/`room_status`/`status`/`visit_date`) + `x-ui.table` +
  `x-ui.badge :status` + `x-ui.button` + `x-ui.empty-state`. The per-row
  assign-room form (`rme.visits.assign-room`) is unchanged; its submit button is
  now `x-ui.button size="sm"` and the "Menunggu Penempatan Ruangan" chip is a
  warning-tone badge.
- **`rme/visits/room-worklist.blade.php`** — same list standard (page-header +
  filter-bar + table + badge + empty-state).
- **`rme/visits/create.blade.php`** / **`edit.blade.php`** — standardized
  `x-ui.page-header`; the edit form now uses `x-ui.select`/`x-ui.textarea`. Field
  names, the status list, and the assign-room-from-queue note are unchanged.
- **`rme/visits/_form.blade.php`** — palette normalization only (teal→brand,
  rose→danger, gray→ink tokens). All field names, the patient-mode / follow-up /
  online-doctor / RM-preview Alpine + fetch logic, and the KTP input (never
  rendered back) are byte-for-byte preserved.
- **`rme/partials/cross-branch-rm-lookup.blade.php`** — RM lookup input →
  `x-ui.input`; warning notices → `x-ui.alert`; result table retokenized. Still
  privacy-safe: no KTP/WhatsApp/address/clinical data rendered.
- **Workspace/nav partials** `rm-sheet-nav.blade.php`, `visit-nav-arrows.blade.php`,
  `visit-workflow-nav.blade.php` — palette→tokens (swipe/prev-next/tab and the
  room-gate lock state on the workflow nav are unchanged).
- **`rme/dashboard/index.blade.php`** — `x-ui.page-header` + `x-ui.filter-bar` +
  `x-ui.kpi-card` grid + tokenized shortcuts.
- **`rme/online-context/select.blade.php`** — `x-ui.page-header` + `x-ui.alert`
  for the flash error + tokenized selects (Alpine `x-model`/`@change`/`:disabled`
  bindings preserved).

## Governance

`app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` extended with
non-brittle UIX-10 rules: the queue view uses page-header/filter-bar/table/badge/
button/empty-state and `:status`; the room worklist uses page-header/filter-bar/
table; visit create/edit use page-header; and across all polished RME operator
files there is no legacy `teal-*`, no `variant="gold"` CTA, and no rendered
`->ktp/nik/identity_number`. UIX-10 evidence doc presence is a soft signal.

## Tests

- New `tests/Feature/Ui/RmeVisitQueueWorkspaceUixTest.php` (9): pages render/
  authorize, queue empty state, filter param preserved, design-system markers
  present, no legacy teal / rendered KTP across operator files, governance GO.

## No-change confirmations

- **RME workflow / ClinicVisit status transitions** — unchanged.
- **RM / odontogram finalization** — unchanged (no clinical view touched).
- **Cashier / payment / receivable** — unchanged (out of scope).
- **Room gate (`visit.room`)** — not bypassed; workflow-nav lock state preserved.
- **Doctor → cashier gate** — unchanged; doctor cannot complete a visit directly.
- **KTP/NIK** — never rendered/exported; create form only *inputs* KTP as before.
- **BranchContext / policy / permission / route / schema / migration** — none changed.

## Rollback

Revert the branch merge commit; all changes are Blade views + one console
command + one test + this doc. No migration, no data change.
