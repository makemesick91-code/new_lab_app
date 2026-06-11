# Developer Notes — RME Smoke Test Data (Sprint 22 Phase 22.2)

## Seeder design

**File:** `database/seeders/RmeSmokeTestSeeder.php`

**Command:**

```bash
php artisan db:seed --class=RmeSmokeTestSeeder
```

### Why idempotent

- Uses `firstOrCreate` / `updateOrCreate` on stable natural keys: email, `visit_number`, `medical_record_number`, `code`, `clinic_visit_id`.
- Re-running does not duplicate patients, visits, users, medical records, or odontograms.
- Password hash is only set on first user creation (`firstOrCreate`); re-run does not reset passwords.

### Why safe for pilot/VPS

- No `delete`, `truncate`, or `migrate:fresh`.
- No sequence resets.
- Does not modify unrelated production records.
- Auto-calls `PermissionSeeder` + `RoleSeeder` only if required roles are missing.
- **Not** registered in `DatabaseSeeder` — must be invoked explicitly.

### Records created

| Entity | Key / label |
|--------|-------------|
| Branch | `MAIN` — Klinik Gigi Daengtisia Pusat |
| Clinic | `CLN-SMOKE-TEST` |
| Doctor (master) | `DOC-SMOKE-TEST` — DOKTER SMOKE TEST |
| Patient | `MRN-SMOKE-TEST-RME` — PASIEN SMOKE TEST RME |
| Visit (clinical) | `VIS-SMOKE-TEST-RME` — `in_progress` + draft MR + draft odontogram |
| Visit (cashier) | `VIS-SMOKE-CASHIER-RME` — `cashier_pending` + final MR |
| Users | dokter / perawat / kasir / owner `@pilot-test.local` |

### Records intentionally NOT created

- RME invoice / payment (operator creates during kasir smoke test).
- Lab case candidates / lab orders.
- Handwriting PNG (doctor adds during manual test if needed).
- HR data.
- Additional branches beyond `MAIN`.

---

## Models / factories

| Model | Usage in seeder |
|-------|-----------------|
| `Branch` | `firstOrCreate` via `Branch::MAIN_CODE` |
| `Clinic` | `firstOrCreate` by `code` |
| `Doctor` | `firstOrCreate` by `code` |
| `Patient` | `firstOrCreate` by `medical_record_number` |
| `ClinicVisit` | `firstOrCreate` by `visit_number` |
| `MedicalRecord` | `firstOrCreate` by `clinic_visit_id` |
| `Odontogram` | `firstOrCreate` by `clinic_visit_id` (clinical visit only) |
| `User` | `firstOrCreate` by `email` + Spatie role assignment |

Factories are used in Pest tests elsewhere; seeder uses direct model `firstOrCreate` for deterministic keys.

---

## Routes covered (smoke-test route tests)

| Route name | Purpose |
|------------|---------|
| `rme.visits.index` | Visit queue |
| `rme.visits.show` | Visit detail |
| `rme.visits.create` | Perawat visit creation |
| `rme.visits.medical-record.show` | Doctor MR view |
| `rme.visits.medical-record.update` | Doctor-only edit (Kasir denied) |
| `rme.visits.odontogram.show` | Doctor odontogram view |
| `rme.medical-records.index` | MR index |
| `rme.cashier.index` | Kasir queue |
| `rme.cashier.create` | Kasir billing handoff |
| `dashboard` | Owner dashboard (Phase 22.1) |

---

## Role / permission assumptions (Phase 22.1)

| Role | Smoke-test access |
|------|-------------------|
| **Owner** | `view_owner_dashboard`, read-only RME visits; no cashier |
| **Kasir** | `view_clinic_visits` + `manage_rme_billing` only |
| **Perawat** | `manage patients`, `view/manage_clinic_visits`; no cashier/lab |
| **Doctor** | `view/manage_clinic_visits`; no cashier/lab |
| **Admin Klinik / Admin Lab** | `view_branch_dashboard` (not seeded as smoke users; use existing admin accounts) |

---

## Verification commands

**Lightweight (Cursor terminal OK):**

```bash
git status --short
php artisan optimize:clear
php artisan test --filter=RmeSmokeTestSeeder
php artisan test --filter=RmeSmokeTestRoute
./vendor/bin/pint --dirty
```

**Heavy (Ubuntu Terminal only):**

```bash
cd ~/Projects/new_lab_app   # or /mnt/DATA/new_lab_app
php artisan test --filter=Pilot
php artisan test --filter=RME
php artisan test
```

---

## RME → Lab end-to-end validation (Phase 22.4)

Validasi alur RME sampai kandidat lab dan konversi lab order didokumentasikan di:

- Operator: `docs/pilot/rme_lab_candidate_e2e_operator_checklist.md`
- Developer: `docs/pilot/rme_lab_candidate_e2e_developer_notes.md`
- Tests: `tests/Feature/Pilot/RmeLabCandidateE2EValidationTest.php`

`RmeSmokeTestSeeder` tidak diubah di Phase 22.4 — operator memilih tindakan `requires_lab` saat uji kasir manual.

---

## Known limitations / Phase 22.3 follow-up

1. Smoke users have no `branch_id` column assignment — `BranchContext` falls back to `MAIN` (current schema).
2. Handwriting RM PNG not pre-seeded; doctor must draw/save during manual test before finalize.
3. Cashier visit has final MR but no pre-built invoice — kasir creates invoice in manual step.
4. Owner dashboard KPIs may still be placeholder metrics.
5. VPS pilot users must be mapped to new roles manually if not using smoke-test accounts.
6. Super Admin still lands on branch-admin dashboard when holding operational permissions (deferred audit item).
