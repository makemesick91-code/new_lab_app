# Sprint 20 — RME Limited Pilot Summary

**Status:** Ready for single-branch pilot — UI modernization complete  
**Branch:** `feature/sprint-20-rme-core` → UI: `feature/ui-tailadmin-integration`  
**Tag:** `sprint-20-rme-limited-pilot-complete` · UI: `sprint-20-rme-ui-modernization-complete`  
**Date:** 2026-06-10 (core) · 2026-06-11 (UI modernization)

---

## Sprint 20 Purpose

Deliver a complete **Rekam Medis Elektronik (RME)** workflow for clinic visits at one branch:
queue management, SOAP medical record, odontogram, doctor handwriting, finalization, cashier
billing, full payment, and printable documents — without lab integration, cicilan, or PDF export.

---

## Completed Phases (1.2 → 1.12)

| Phase | Focus | Tag |
|---|---|---|
| 1.2 | RME Core Medical Record (SOAP, list, sidebar, widgets) | `sprint-20-phase-1-2-complete` |
| 1.3.1–1.3.3 | Odontogram placeholder, tooth map, finalize | `sprint-20-phase-1-3-3-odontogram-finalize` |
| 1.4 | Per-tooth notes | `sprint-20-phase-1-4-odontogram-per-tooth-notes` |
| 1.5 | Multi-condition per tooth | `sprint-20-phase-1-5-odontogram-multi-condition` |
| 1.6 | Odontogram print view | `sprint-20-phase-1-6-odontogram-print-view` |
| 1.7 | RME visit print bundle | `sprint-20-phase-1-7-rme-visit-print-bundle` |
| 1.7.1 | Test stability hardening | `sprint-20-phase-1-7-1-rme-test-stability` |
| 1.8 | Initial service + handwriting RME | `sprint-20-phase-1-8-rme-initial-service-handwriting` |
| 1.9 | RME finalization → cashier_pending | `sprint-20-phase-1-9-rme-finalization-workflow` |
| 1.10 | Cashier billing input | `sprint-20-phase-1-10-rme-cashier-billing-input` |
| 1.11 | Payment + receipt | `sprint-20-phase-1-11-rme-payment-receipt` |
| 1.12 | Limited pilot hardening + docs | `sprint-20-rme-limited-pilot-complete` |

---

## End-to-End Workflow

```
Admin creates visit (+ initial service)
    → Doctor: odontogram (draft → finalize)
    → Doctor: handwriting RME (PNG canvas)
    → Doctor: finalize RME
    → Visit: cashier_pending
    → Cashier: create invoice (treatment line items)
    → Cashier: record full payment
    → Invoice: PAID · Visit: completed
    → Print: RME bundle / odontogram / invoice / receipt
```

---

## Role-by-Role Workflow

### Admin / Front Office (`manage_clinic_visits`)

1. Open **RME → Kunjungan** (`rme.visits.index`).
2. Create visit: patient, doctor, room, **initial service** (required), optional note.
3. Transition visit: `registered` → `waiting` → `in_progress`.
4. May view all RME pages read-only if only `view_clinic_visits`.

### Doctor (`manage_clinic_visits`)

1. Open visit → **Odontogram** — mark teeth, conditions, notes → **Finalize odontogram**.
2. Open **Rekam Medis** — draw full **handwriting RME** on canvas (primary doctor input).
3. Save handwriting (PNG, mandatory before finalize).
4. **Finalize RME** — visit moves to `cashier_pending`.
5. Cannot edit RME or handwriting after finalization.

### Cashier (`manage_rme_billing`)

Pilot uses **Admin Klinik** (no dedicated Kasir role).

1. Open **RME → Kasir** (`rme.cashier.index`) — lists `cashier_pending` visits.
2. Create invoice: add treatment line items (qty, price, discount).
3. Record **full payment** (partial rejected).
4. Print receipt (`rme.cashier.receipt.show`).

### Owner / Manager (read-only)

Users with `view_clinic_visits` only:

- View visits, medical records, odontogram, print bundle.
- Cannot create, update, finalize, bill, or pay.

---

## Pilot Scope

- **One branch** (active branch via `BranchContext`).
- **Roles:** Admin Lab, Admin Klinik, Doctor, Super Admin.
- **Cashier:** Admin Klinik with `manage_rme_billing`.
- **Out of scope:** lab orders from RME, cicilan, server PDF, multi-branch RME reports.

---

## UAT Checklist

### Visit & Queue

- [ ] Admin creates visit with initial service
- [ ] Visit appears in queue with correct status
- [ ] Status transitions work (registered → waiting → in_progress)
- [ ] Cross-branch user cannot see/edit visit

### Odontogram

- [ ] Doctor can mark teeth and add conditions/notes
- [ ] Doctor can finalize odontogram
- [ ] Finalized odontogram cannot be edited
- [ ] Print odontogram opens without error

### Medical Record & Handwriting

- [ ] Doctor can save handwriting RME (PNG) — primary clinical input
- [ ] SOAP fields hidden from doctor UI (legacy data preserved if present)
- [ ] Finalize blocked without handwriting
- [ ] Finalized RME cannot be edited
- [ ] Handwriting read-only after finalization

### Cashier Billing

- [ ] Only `cashier_pending` visits appear in cashier index
- [ ] Cannot bill visit with draft (unfinalized) RME
- [ ] Invoice requires at least one line item
- [ ] Invoice totals calculate correctly
- [ ] Initial service unchanged after billing

### Payment & Receipt

- [ ] Full payment accepted
- [ ] Partial payment rejected
- [ ] Invoice status becomes PAID
- [ ] Visit status becomes completed
- [ ] Receipt prints via browser without error

### Print Views

- [ ] RME visit print bundle (`rme.visits.print`)
- [ ] Odontogram print (`rme.odontograms.print`)
- [ ] Invoice detail (`rme.cashier.show`)
- [ ] Receipt (`rme.cashier.receipt.show`)

---

## Route Checklist

| Route | Name | Role |
|---|---|---|
| `GET rme/visits` | `rme.visits.index` | view/manage visits |
| `POST rme/visits` | `rme.visits.store` | manage visits |
| `GET rme/visits/{id}` | `rme.visits.show` | view/manage visits |
| `POST rme/visits/{id}/transition` | `rme.visits.transition` | manage visits |
| `GET rme/visits/{id}/medical-record` | `rme.visits.medical-record.show` | view/manage |
| `POST .../medical-record` | `rme.visits.medical-record.store` | manage |
| `PATCH .../medical-record/{id}` | `rme.visits.medical-record.update` | manage |
| `POST .../finalize` | `rme.visits.medical-record.finalize` | manage |
| `POST .../handwriting` | `rme.visits.medical-record.handwriting.store` | manage |
| `GET rme/visits/{id}/odontogram` | `rme.visits.odontogram.show` | view/manage |
| `PATCH rme/odontograms/{id}` | `rme.odontograms.update` | manage |
| `POST rme/odontograms/{id}/finalize` | `rme.odontograms.finalize` | manage |
| `GET rme/odontograms/{id}/print` | `rme.odontograms.print` | view/manage |
| `GET rme/visits/{id}/print` | `rme.visits.print` | view/manage |
| `GET rme/medical-records` | `rme.medical-records.index` | view/manage |
| `GET rme/cashier` | `rme.cashier.index` | manage_rme_billing |
| `GET rme/cashier/{id}/billing/create` | `rme.cashier.create` | manage_rme_billing |
| `POST rme/cashier/{id}/billing` | `rme.cashier.store` | manage_rme_billing |
| `GET rme/cashier/{id}/billing/{inv}` | `rme.cashier.show` | manage_rme_billing |
| `GET .../payment/create` | `rme.cashier.payment.create` | manage_rme_billing |
| `POST .../payment` | `rme.cashier.payment.store` | manage_rme_billing |
| `GET .../receipt` | `rme.cashier.receipt.show` | manage_rme_billing |

---

## Permission Checklist

| Permission | Grants |
|---|---|
| `view_clinic_visits` | Read visits, RME, odontogram, print views |
| `manage_clinic_visits` | Create/edit/finalize visits, RME, odontogram |
| `manage_rme_billing` | Cashier index, invoice, payment, receipt |

**Seeded roles:**

- **Admin Lab:** all three RME permissions
- **Admin Klinik:** all three RME permissions
- **Doctor:** `view_clinic_visits`, `manage_clinic_visits`
- **Super Admin:** all permissions

---

## Data Integrity Rules

1. **Branch isolation** — all RME data scoped to `BranchContext::requireId()`.
2. **Initial service** — triage only; never creates invoice or payment.
3. **Handwriting mandatory** — finalize blocked until PNG saved.
4. **Finalized RME immutable** — no handwriting edits after `finalized_at`.
5. **Handwriting RM primary** — SOAP fields remain optional legacy structured data in DB; hidden from doctor-facing UI.
6. **Cashier gate** — invoice only when visit is `cashier_pending` AND RME is `final`.
7. **Full payment only** — partial payments rejected in pilot.
8. **Payment completion** — full pay → invoice `PAID`, visit `completed`.
9. **No lab payment bleed** — RME payments do not touch lab-order payment tables.
10. **Odontogram finalize** — one-way `draft → finalized`, immutable after.

---

## Known Limitations

- Browser `window.print()` only — no server-side PDF.
- Full payment only — no cicilan/installment.
- No lab order creation from RME.
- No dedicated Kasir role (Admin Klinik serves as cashier).
- Finance role has lab billing only, not RME billing.
- Single-branch pilot; no cross-branch RME analytics.

---

## Sprint 21 Backlog

1. Kasir role with least-privilege `manage_rme_billing`.
2. Partial payment / cicilan with outstanding balance.
3. Lab order from finalized RME visit.
4. Optional DomPDF export for RME bundle and receipt.
5. RME operational dashboard (queue, unpaid, daily revenue).
6. Owner read-only RME reports without manage permissions.

---

## UI Modernization (Sprint 20 Phase 2 — Post-Core)

**Branch:** `feature/ui-tailadmin-integration`  
**Tag:** `sprint-20-rme-ui-modernization-complete`  
**Date:** 2026-06-11

All RME views have been modernized to TailAdmin-style UI components. No business logic,
routes, permissions, field names, or workflow was changed.

### Views Modernized

| View | File |
|---|---|
| Kunjungan — daftar | `rme/visits/index.blade.php` |
| Kunjungan — detail | `rme/visits/show.blade.php` |
| Rekam Medis — daftar | `rme/visits/medical-record/index.blade.php` |
| Rekam Medis — detail | `rme/visits/medical-record/show.blade.php` |
| Odontogram — detail | `rme/visits/odontogram/show.blade.php` |
| Kasir — antrian | `rme/cashier/index.blade.php` |
| Kasir — buat tagihan | `rme/cashier/create.blade.php` |
| Kasir — detail tagihan | `rme/cashier/show.blade.php` |
| Kasir — bayar | `rme/cashier/payment/create.blade.php` |
| Kasir — kwitansi | `rme/cashier/receipt/show.blade.php` |

### Components Used

- `x-settings-shell` — page shell/layout
- `x-ui.card` — content panels
- `x-ui.table` — data tables
- `x-ui.badge` — status labels
- `x-ui.button` — actions

### Intentional Raw HTML Exceptions

- **Receipt body table** (`cashier/receipt/show.blade.php`): raw `<table>` inside `#receipt-body` for print-friendly layout.
- **Workflow transition buttons** (`visits/show.blade.php`): raw `<button>` required for dynamic PHP `$transitionStyle` class interpolation per status.

### Quality Gates Passed

- `php artisan test`: 1842 passed, 6290 assertions
- `./vendor/bin/pint --dirty`: no changes
- `npm run build`: success
