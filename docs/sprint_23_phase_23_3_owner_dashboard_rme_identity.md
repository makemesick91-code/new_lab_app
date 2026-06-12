# Sprint 23 Phase 23.3 — Owner Dashboard Access/Menu/Role Enablement + RME Patient Identity Hardening

## 1. Summary

- **Branch:** `feature/sprint-23-phase-23-3-owner-dashboard-rme-identity` (cut from `sprint-22-release-candidate`, commit `a2d400e`)
- **Commit:** see Part H / sprint_history (tag `sprint-23-phase-23-3-owner-dashboard-rme-identity`)
- **Status:** PASS
- **Main blockers addressed (from Phase 23.2 "GO WITH WATCH"):**
  1. Owner Dashboard role/permission/menu confirmed wired and now test-locked + Owner enablement command added (blockers #1, #2, #3).
  2. RME patient new/old foundation: auto-generated, configurable patient code (blockers #4, #5).
  3. Master Data Klinik + Pengadaan sidebar icons added (blocker #7).
  4. Batch sidebar entry: already present under Inventory — documented, not rebuilt (blocker #6).

> **Important context:** Most Owner Dashboard backend wiring (route gate, `view_owner_dashboard` permission, `Owner`/`Kasir`/`Perawat` roles, "Dasbor" + "Klinik / RME" sidebar entries) already shipped in Sprint 22 Phase 22.1. Phase 23.2 reported these as "not available" on the VPS pilot — that was a **deployment/data** condition (seeders / role assignment not applied to the pilot account), not missing code. This phase therefore focuses on (a) a safe Owner pilot **enablement command**, (b) **test locks** so the access rules cannot regress, and (c) the genuinely new RME **patient identity** foundation.

## 2. Owner Dashboard Enablement

- **Route:** `dashboard` → `HomeDashboardController@index`, already gated by
  `middleware('permission:view dashboard|view_owner_dashboard')`. No duplicate route created.
- **Permission:** `view_owner_dashboard` already in `PermissionSeeder` (Sprint 22 Phase 22.1). Verified — not duplicated.
- **Role:** `Owner` already in `RoleSeeder` with `view_owner_dashboard` + read-only report permissions. Super Admin retains access via `*`.
- **Sidebar behavior:** "Dasbor" link gated by `@canany(['view dashboard', 'view_owner_dashboard'])`. The Owner **KPI panels** (`Dasbor Owner`, Kartu KPI, Pipeline, Pusat Peringatan, Performa Cabang) render only for non-operational owner/report users via `HomeDashboardController::shouldLoadOwnerRmeLabPilot()`.
- **Access rules (verified by tests):**
  - Owner → sees `Dasbor Owner` KPI content.
  - Super Admin → reaches dashboard route (operational, so KPI panel intentionally suppressed).
  - Operational role (e.g. Courier) → reaches route but does NOT see `Dasbor Owner`.
  - Unauthenticated → redirected to `login`.

## 3. Owner Pilot User Support

- New artisan command: **`php artisan pilot:assign-owner {email}`**
  (`app/Console/Commands/AssignOwnerRoleCommand.php`, registered in `bootstrap/app.php`).
- Behavior: assigns the existing `Owner` role to an **existing** user by email.
  - Does NOT create users.
  - Does NOT touch or print passwords/secrets.
  - Idempotent (no-op if already Owner).
  - Fails safely with a clear message if the user or the `Owner` role is missing.
- **Pilot enablement steps (no secrets):**
  1. Ensure roles are seeded: `php artisan db:seed --class=RoleSeeder` (additive, safe).
  2. Create/identify the pilot user through the normal app user flow (password set by the human operator, never in code/docs).
  3. Grant access: `php artisan pilot:assign-owner owner@clinic.example`.

## 4. RME Role/Menu Hardening

- **Roles checked:** Doctor, Kasir, Perawat, Admin Klinik, Super Admin, Owner.
- **Permissions:** project uses existing clinical naming (`view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing`) — NOT `view_rme/manage_rme`. Per the "use existing naming style" rule, **no new RME permission names were introduced** (avoids duplication / confusion). Existing permissions already cover the required coverage.
- **Sidebar/menu behavior (already wired, now regression-covered by existing `SidebarPermissionVisibilityTest`):**
  - Doctor → Klinik / RME (Kunjungan, Rekam Medis); no Kasir RME.
  - Kasir / Admin Klinik → Kasir RME.
  - Perawat → Klinik / RME without cashier/settings.
  - Owner → read-only RME + reporting, no lab operations.
  - Super Admin → all.
  - Technician/Courier → no RME group.

## 5. RME Patient Identity Hardening

- **Existing state found:** `mst_patients.medical_record_number` already exists — `string(50)`, **nullable, unique, indexed** (migration `2026_06_03_030003`). **No schema change required** (no additive migration needed).
- **New vs old patient handling:**
  - Clinic visit / RME registration selects an **existing** patient by `patient_id` (returning patient → keeps its stored code).
  - **New** patient is created via the Patient master flow → now auto-receives a generated code when none is entered.
- **Changes:**
  - `config/patient.php` — configurable code format (`auto_generate`, `prefix`, `period_format`, `seq_length`, `separator`) with env overrides.
  - `App\Modules\Patient\Services\PatientCodeGenerator` — collision-safe, sequence-incrementing generator.
  - `PatientService::create()` — generates a code only when `medical_record_number` is blank and auto-generate is enabled. Explicit codes (legacy/old patients) are preserved untouched.
  - UI: RME visit patient `<select>` now shows `code — name`; patient form shows an auto-generate hint for new patients.
- **Temporary patient code format (PENDING owner approval):**
  `RM-{YYYYMM}-{SEQ6}` → e.g. `RM-202606-000001`.
  Documented as temporary in `config/patient.php`; final business format is **not** locked and can be changed via config/env without code changes.

## 6. UI Polish

- **Icons added** to the `Pengadaan` and `Master Data Klinik` sidebar group headers (matching the existing leading-icon pattern used by Klinik / RME). No broken icons.
- **Batch sidebar entry:** already exists as "Batch & Lot" under Inventory (`inventory.batches.index`), gated by `viewAny` on `InventoryBatch`. **Not rebuilt** — no new batch module created (per scope). If Phase 23.2 referred to a *clinical/RME* batch concept, that route/module does not exist and is **deferred**.

## 7. Tests

Commands run (local):

```bash
php artisan test tests/Feature/MasterData/PatientCodeGenerationTest.php tests/Feature/Pilot/OwnerEnablementTest.php   # 13 passed
php artisan test --filter='Owner|Dashboard|Patient|Sidebar|ClinicVisit|RolePermission|PilotRoute'                    # 233 passed (932 assertions)
./vendor/bin/pint --dirty   # passed
npm run build               # built OK
```

New tests:
- `tests/Feature/MasterData/PatientCodeGenerationTest.php` (6) — generation, uniqueness, collision skip, configurable format, disable, legacy code preserved.
- `tests/Feature/Pilot/OwnerEnablementTest.php` (7) — assign command (assign/idempotent/missing-user), Owner KPI access, Super Admin route access, operational-role suppression, guest redirect.

Filter notes:
- `--filter=Rme` / `--filter=RME` (heavy) not run in this pass to keep the loop fast; related RME tests ran under the combined filter and passed. No RME business logic changed.
- `--filter=Permission` covered indirectly via `RolePermission`/`PilotRoute` in the combined filter.

No known failures.

## 8. Files Changed

**Owner enablement**
- `app/Console/Commands/AssignOwnerRoleCommand.php` (new)
- `bootstrap/app.php` (register command)

**Patient identity**
- `config/patient.php` (new)
- `app/Modules/Patient/Services/PatientCodeGenerator.php` (new)
- `app/Modules/Patient/Services/PatientService.php` (auto-generate hook)
- `resources/views/rme/visits/_form.blade.php` (patient select shows code)
- `resources/views/settings/patients/_form.blade.php` (auto-generate hint)

**UI polish**
- `resources/views/layouts/partials/sidebar.blade.php` (Pengadaan + Master Data Klinik icons)

**Tests**
- `tests/Feature/MasterData/PatientCodeGenerationTest.php` (new)
- `tests/Feature/Pilot/OwnerEnablementTest.php` (new)

**Docs**
- `docs/sprint_23_phase_23_3_owner_dashboard_rme_identity.md` (this file)
- `docs/sprint_history.md` (Phase 23.3 entry)

## 9. Risks / Watch Items

- **No schema/data migration in this phase.** `medical_record_number` already existed; existing pilot patients with `NULL` codes are unaffected (codes are generated only on new creation). A **backfill** for existing NULL-code patients is optional and deferred until the owner confirms the final code format.
- **Patient code format is temporary** — do not advertise `RM-YYYYMM-NNNNNN` as final to the owner until confirmed. Changeable via config/env.
- **VPS deploy notes (next phase):** roles must be (re)seeded and the pilot owner account granted via `pilot:assign-owner` on the VPS. Follow the standing VPS rules — backup DB first, `php artisan migrate --force` only, **never** `migrate:fresh`/`db:wipe`, reset `storage`/`bootstrap/cache` permissions after deploy. No new migration ships in this phase.
- Existing-patient duplicate prevention is partial: creation does not yet dedupe by name+DOB. Practical safeguard (unique code) is in place; stronger duplicate detection deferred.

## 10. Next Recommended Phase

**Sprint 23 Phase 23.4 — VPS Deploy + Owner Dashboard/RME Identity Smoke**
- Deploy this branch to the VPS pilot following the Phase 21.7 checklist.
- Re-seed roles, run `pilot:assign-owner` for the owner account.
- Smoke: Owner login → Dasbor Owner KPI, branch filter, drilldown; new patient gets a code; sidebar icons present.
- Decide final patient code format with the owner; plan optional backfill.
