# Sprint 19 — Clinic Master Data Completion Summary

## Status

COMPLETE

## Branch

`feature/sprint-19-clinic-master-data`

## Final Commit

`0247d0a` — Add Sprint 19 phase 5 WA reminder template master data

## Tags

| Phase | Tag |
|---|---|
| Phase 3 — Tariff | `sprint-19-phase-3-tariff-master-data` |
| Phase 4 — Payment Method | `sprint-19-phase-4-payment-method-master-data` |
| Phase 5 — WA Reminder Templates | `sprint-19-phase-5-wa-reminder-template-master-data` |

---

## Completed Phases

| Phase | Feature |
|---|---|
| Phase 0–1 | Clinic Room Master Data (commit `cadba17`) |
| Phase 2 | Treatment Category & Treatment Master Data (commit `d605e0d`) |
| Phase 3 | Tariff Master Data (commit `42a9647`, tag `sprint-19-phase-3-tariff-master-data`) |
| Phase 4 | Payment Method Master Data (commit `b935949`, tag `sprint-19-phase-4-payment-method-master-data`) |
| Phase 5 | WA Reminder Templates Master Data (commit `0247d0a`, tag `sprint-19-phase-5-wa-reminder-template-master-data`) |

---

## Modules Added

| Module | Path | Scope |
|---|---|---|
| `ClinicRoom` | `app/Modules/ClinicRoom/` | Branch-scoped |
| `TreatmentCategory` | `app/Modules/TreatmentCategory/` | Global |
| `Treatment` | `app/Modules/Treatment/` | Global |
| `Tariff` | `app/Modules/Tariff/` | Branch-scoped |
| `PaymentMethod` | `app/Modules/PaymentMethod/` | Global |
| `WaReminderTemplate` | `app/Modules/WaReminderTemplate/` | Global |

Each module implements the full stack:
**Controller → FormRequest → Service → Interface → Repository → Model → Policy → Blade Views → Routes → Seeder/Factory/Test**

---

## Tables Added

| Table | Migration | Scope |
|---|---|---|
| `mst_clinic_rooms` | `2026_06_12_100000_create_mst_clinic_rooms_table.php` | Branch-scoped (`branch_id` FK) |
| `mst_treatment_categories` | `2026_06_13_100000_create_mst_treatment_categories_table.php` | Global |
| `mst_treatments` | `2026_06_13_100001_create_mst_treatments_table.php` | Global |
| `mst_tariffs` | `2026_06_13_100002_create_mst_tariffs_table.php` | Branch-scoped (`branch_id` FK) |
| `mst_payment_methods` | `2026_06_13_100003_create_mst_payment_methods_table.php` | Global |
| `mst_wa_reminder_templates` | `2026_06_13_100004_create_mst_wa_reminder_templates_table.php` | Global |

---

## Permission Model

| Permission | Purpose |
|---|---|
| `view_clinic_master_data` | Read access to all Sprint 19 master data modules |
| `manage_clinic_master_data` | Write access (create/update/delete) for all Sprint 19 master data modules |

Permissions follow the underscore convention. Both permissions are seeded in `PermissionSeeder`
and assigned in `RoleSeeder`. Super Admin receives both through the centralized `Gate::before`
bypass in `RepositoryServiceProvider`.

---

## Branch Scope Decisions

| Module | Scope | Reason |
|---|---|---|
| `ClinicRoom` | Branch-scoped | Physical rooms belong to a specific branch |
| `Tariff` | Branch-scoped | Branch-level pricing: `(branch_id, treatment_id, effective_date)` unique |
| `TreatmentCategory` | Global | Shared taxonomy across all branches |
| `Treatment` | Global | Shared catalogue; branch-specific pricing handled by Tariff module |
| `PaymentMethod` | Global | Shared across branches |
| `WaReminderTemplate` | Global | Template library shared across branches |

Branch-scoped modules use `BranchContext::requireId()` in services and `where('branch_id', $branchId)` in repositories. User-submitted `branch_id` is ignored; active branch is resolved server-side.

---

## Route Groups

All Sprint 19 routes are under the `settings.*` prefix and protected by `auth` middleware and
permission gates.

| Route Group | Controller |
|---|---|
| `settings.clinic-rooms.*` | `ClinicRoomController` |
| `settings.treatment-categories.*` | `TreatmentCategoryController` |
| `settings.treatments.*` | `TreatmentController` |
| `settings.tariffs.*` | `TariffController` |
| `settings.payment-methods.*` | `PaymentMethodController` |
| `settings.wa-reminder-templates.*` | `WaReminderTemplateController` |

Each group implements standard resourceful CRUD: `index`, `create`, `store`, `show`, `edit`,
`update`, `destroy`.

---

## Architecture Pattern

Sprint 19 modules follow the canonical ADLMS modular pattern:

```
Controller → FormRequest → Service → Interface → Repository → Model → Policy → Blade Views → Routes → Seeder/Factory/Test
```

- **Controller:** Thin — delegates to service, authorizes via policy.
- **FormRequest:** Input validation and authorization check.
- **Service:** Business logic, `BranchContext::requireId()` for branch-scoped modules.
- **Interface + Repository:** Query layer; interface bound in `RepositoryServiceProvider`.
- **Model:** Eloquent, soft deletes, `is_active` lifecycle.
- **Policy:** Permission + branch ownership check; registered in `RepositoryServiceProvider`.
- **Blade Views:** Follow `docs/ui_design_system.md` — teal primary, bordered cards, dual
  responsive table/mobile layout.
- **Seeder:** Idempotent `firstOrCreate`; seeds default data for immediate operational use.

---

## Sidebar Registration

Sprint 19 modules appear under the existing **Pengaturan / Settings** section in
`resources/views/layouts/sidebar.blade.php`, guarded by
`@canany(['view_clinic_master_data', 'manage_clinic_master_data'])`:

- Master Ruangan (`settings.clinic-rooms.index`)
- Master Kategori Perawatan (`settings.treatment-categories.index`)
- Master Perawatan (`settings.treatments.index`)
- Master Tarif (`settings.tariffs.index`)
- Master Metode Pembayaran (`settings.payment-methods.index`)
- Template Reminder WA (`settings.wa-reminder-templates.index`)

---

## RepositoryServiceProvider Bindings

All Sprint 19 interface-to-repository bindings registered in `RepositoryServiceProvider`:

```php
ClinicRoomRepositoryInterface::class         => ClinicRoomRepository::class,
TreatmentCategoryRepositoryInterface::class  => TreatmentCategoryRepository::class,
TreatmentRepositoryInterface::class          => TreatmentRepository::class,
TariffRepositoryInterface::class             => TariffRepository::class,
PaymentMethodRepositoryInterface::class      => PaymentMethodRepository::class,
WaReminderTemplateRepositoryInterface::class => WaReminderTemplateRepository::class,
```

---

## Explicit Out-of-Scope

Sprint 19 is **master data only**. The following are explicitly **not** implemented:

- No RME (Rekam Medis Elektronik) transaction workflow
- No patient visit / encounter workflow
- No odontogram
- No doctor handwritten tablet note feature
- No cashier payment transaction workflow (Tariff exists as master price only)
- No payment installment transaction
- No WhatsApp sending / API integration
- No WhatsApp scheduler / job for reminders
- No automatic notification delivery (WA templates are library records only)
- No changes to Inventory ledger stock rules
- Tariff is not yet wired to a full cashier/RME billing flow (deferred to future sprint)

---

## Quality Gate Checklist

| Gate | Command | Result |
|---|---|---|
| Fresh migration + seed | `php artisan migrate:fresh --seed` | **PASS** — all 61 migrations, all 15 seeders |
| Sprint 19 focused tests | `php artisan test tests/Feature/ClinicMasterData` | **PASS — 129 tests, 444 assertions** |
| Full test suite | `php artisan test` | **PASS — 1565 tests, 5601 assertions** |
| PHP formatting | `./vendor/bin/pint` | **PASS — no changes** |
| Frontend build | `npm run build` | **PASS** |
| Git status | `git status` | **Clean — nothing to commit** |

---

## Test Result Summary

Quality gate run date: **2026-06-09**

### Sprint 19 Focused Suite (`tests/Feature/ClinicMasterData`)

| Test Class | Tests | Assertions |
|---|---|---|
| `ClinicMasterDataPermissionTest` | 4 | — |
| `ClinicRoomTest` | 18 | — |
| `PaymentMethodTest` | 22 | — |
| `TariffTest` | 22 | — |
| `TreatmentCategoryTest` | 13 | — |
| `TreatmentTest` | 15 | — |
| `WaReminderTemplateTest` | 35 | — |
| **Total** | **129 passed** | **444 assertions** |

Duration: 26.87s

### Full Test Suite

- **1565 tests passed, 5601 assertions**
- Duration: 283.11s
- 0 failures, 0 skipped

---

## Known Limitations

- Sprint 19 delivers **master data only**. Transaction/RME workflows are deferred to a future sprint.
- `WaReminderTemplate` stores message body templates but does **not** send any WhatsApp message.
  Sending requires a future WhatsApp API integration sprint.
- `Tariff` represents a branch-level master price per treatment, but is **not yet connected** to a
  cashier payment or RME billing workflow unless separately implemented elsewhere.
- `TreatmentCategory` and `Treatment` are global (no branch isolation); future branch-specific
  treatment catalogues would require a schema extension.

---

## Next Recommended Sprint Options

| Option | Description |
|---|---|
| **Sprint 20** | RME Patient Registration & Queue Foundation |
| **Sprint 20A** | Patient Visit / Encounter Foundation |
| **Sprint 20B** | Cashier Payment Workflow (wire Tariff to billing) |
| **Sprint 20C** | WhatsApp Reminder Engine (connect WaReminderTemplate to sending) |
