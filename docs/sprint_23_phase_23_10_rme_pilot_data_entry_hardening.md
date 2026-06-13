# Sprint 23 Phase 23.10 — RME Pilot Data Entry Hardening

## 1. Status

- **Status:** Complete (local only — no VPS deploy, no push)
- **Branch:** `feature/sprint-23-phase-23-10-rme-pilot-data-entry-hardening`
- **Base commit:** `4034078` (tag `sprint-23-phase-23-9-5-vps-smoke-closure-documentation`)
- **Commit:** _(see `Harden RME pilot data entry flow`)_
- **Tag:** `sprint-23-phase-23-10-rme-pilot-data-entry-hardening`

## 2. Background

- **Klinik = Cabang RME.** Operational RME branches are `mst_branches` where
  `is_active = true AND is_rme_enabled = true` (TKM1, LDK2, ATG3). MAIN
  (`is_rme_enabled = 0`) is a **technical fallback only** and must never appear
  in RME operational flows.
- Phase 23.9.1 made the visit branch follow the selected Cabang RME and made
  `clinic_id` nullable. Phase 23.9.3/23.9.4 fixed the **visit list** to span all
  active RME branches instead of a single `BranchContext` fallback branch.
- This phase extends that same multi-branch correction to the **rest of the
  pilot data-entry flow** (doctor odontogram + medical record, cashier billing,
  payment, and lab candidate generation), which were still scoped to a single
  `BranchContext::requireId()` / `BranchContext::id()`. In the pilot that
  fallback resolves to MAIN, so those flows would have been silently empty or
  forbidden for every real RME-branch visit.

## 3. Pilot Flow Hardened

Supported flow now verified end-to-end against the active RME branch set:

- **New patient** — register + create visit; patient and visit share the selected
  Cabang RME; final RM `RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}`;
  manual RM preserves leading zeros; MAIN rejected as a branch option.
- **Existing patient** — visit created under the selected Cabang RME; legacy
  (branch_id null) patients allowed; `patient.branch_id` never rewritten.
- **Visit list** — all active RME branches by default; branch filter narrows;
  branch label shown; patient RM shown.
- **Visit detail** — opens with `clinic_id` null; Cabang RME shown as
  `{code} — {name}`; patient RM shown.
- **Odontogram** — `getOrCreateForVisit` / update / finalize now allowed for any
  active RME-branch visit (was MAIN-only).
- **Medical record** — create draft / update / finalize / list / counts now span
  the active RME branch set (was MAIN-only).
- **Treatment/tariff** — cashier billing create page renders active treatments
  for an RME-branch visit without `clinic_id`.
- **Cashier/payment** — pending queue lists RME-branch visits; invoice create +
  full payment succeed for the visit's own RME branch (no MAIN fallback);
  invoice branch is taken from the visit, never from `BranchContext`.
- **Lab candidate** — generated post-payment with the invoice's RME `branch_id`
  and visit context; no longer skipped by a MAIN branch-context mismatch.

## 4. Existing Patient Behavior (explicit)

- The **selected Cabang RME becomes `visit.branch_id`** in existing-patient mode.
- **`patient.branch_id` is never rewritten automatically** when an existing
  patient is given a visit in another branch (verified by test).
- **Legacy patients with `branch_id` null remain allowed** to create visits under
  a selected Cabang RME (verified by test).
- **Legacy `clinic_id` is preserved** and never used for RME location scoping.
- Patient selectors/tables now show `RM — Name (Cabang | Legacy / belum ada
  cabang pasien)` so admins/cashiers can disambiguate patients.

## 5. Null clinic_id Hardening

- RME views (visit list/detail, cashier queue) use `visit.branch` as the primary
  location label; `clinic` is only a secondary fallback for display.
- All branch scoping in the RME doctor + cashier stack uses `branch_id` membership
  in the active RME set — never `clinic_id`. `clinic_id` null does not break any
  supported RME page (verified across list/detail/odontogram/medical-record/
  cashier-create tests).

## 6. Legacy Patient Backfill

- **No automatic backfill was performed.** Old RM values and `clinic_id` are
  preserved untouched.
- A **read-only** preview helper was added (no mutation):
  - `PatientRepository::legacyWithoutBranch()` /
    `PatientService::legacyWithoutBranch()` — lists patients with `branch_id`
    null for a future controlled migration.
- **Suggested future backfill phase:** preview/export first (CSV of legacy
  patients), owner-reviewed branch + RM mapping, then a guarded, additive,
  reversible migration. No destructive operations.

## 7. Tests

Commands run (local):

```
php artisan test --filter=RmePilotDataEntryHardeningTest   # 26 passed
php artisan test tests/Feature/RME --filter="ClinicVisit|Patient|Odontogram|MedicalRecord|Rme|CashierBilling|LabIntegration|LabCaseCandidate|PilotBackup"
                                                            # 434 passed (1112 assertions)
php artisan test --filter="Permission|Sidebar|Branch"       # 526 passed (1605 assertions)
./vendor/bin/pint --dirty                                   # clean
npm run build                                               # built in ~2s
```

New file: `tests/Feature/RME/RmePilotDataEntryHardeningTest.php` (26 tests) covers
new-patient, existing-patient, null-clinic_id, doctor odontogram/medical-record,
cashier create/queue/payment, lab candidate, regression (RME vs non-RME vs MAIN),
patient label helpers, and the legacy preview helper.

Existing branch-isolation tests updated to the new rule (isolation = non-RME
branch, not a single BranchContext branch): `CashierBillingTest`,
`RmePaymentTest`, `LabIntegrationTest`, `RmeLabWorkflowPolishTest`,
`OdontogramTest` (×10), `MedicalRecordTest` (×3), `MedicalRecordFinalizationTest`.

> Full suite was not run; focused suites above were used.

## 8. Files Changed

**Services / repositories / policies / controllers**
- `app/Modules/RmeInvoice/Services/RmeInvoiceService.php` — cashier queue + invoice
  create scoped to RME set; invoice branch = visit branch.
- `app/Modules/RmeInvoice/Services/RmePaymentService.php` — payment scoped to RME set.
- `app/Modules/RmeInvoice/Services/RmeLabIntegrationService.php` — candidate
  generation scoped to RME set.
- `app/Modules/RmeInvoice/Policies/RmeInvoicePolicy.php` — RME-set branch check.
- `app/Modules/RmeInvoice/Repositories/RmeInvoiceRepository.php` +
  `Interfaces/RmeInvoiceRepositoryInterface.php` — `paginateCashierPendingForBranches`.
- `app/Modules/Odontogram/Services/OdontogramService.php` +
  `Policies/OdontogramPolicy.php` — RME-set branch check.
- `app/Modules/MedicalRecord/Services/MedicalRecordService.php` +
  `Policies/MedicalRecordPolicy.php` — RME-set branch check; multi-branch list/counts.
- `app/Modules/MedicalRecord/Repositories/MedicalRecordRepository.php` +
  `Interfaces/MedicalRecordRepositoryInterface.php` — multi-branch paginate/count.
- `app/Modules/Patient/Models/Patient.php` — `isLegacyWithoutBranch()`,
  `branchLabel()`, `selectorLabel()`.
- `app/Modules/Patient/Repositories/PatientRepository.php` +
  `Interfaces/PatientRepositoryInterface.php` +
  `Services/PatientService.php` — read-only `legacyWithoutBranch()`.
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` — eager-load
  patient branch for the create form.

**Views**
- `resources/views/rme/visits/_form.blade.php` — patient selector shows RM +
  branch/legacy indicator.
- `resources/views/rme/visits/index.blade.php` — patient RM in list.
- `resources/views/rme/visits/show.blade.php` — patient RM in detail.
- `resources/views/rme/cashier/index.blade.php` — Klinik/Cabang column + patient RM.

**Tests**
- New `tests/Feature/RME/RmePilotDataEntryHardeningTest.php`.
- Updated isolation tests (listed in §7).

**Docs**
- This file + `docs/sprint_history.md`.

## 9. Out of Scope

- Automatic patient RM / branch backfill (preview-only helper added instead).
- Destructive data cleanup; `mst_clinics` removal; `clinic_id` column removal.
- Full cashier redesign (partial/cicilan still deferred — full-payment-only holds).
- Full treatment pricing / tariff redesign.
- VPS deploy and any production database operation.

## 10. Watch Items

- **Browser smoke still required** before VPS deploy (doctor odontogram/medical
  record + cashier billing now reachable on RME branches — verify in UI).
- **Old patient backfill still pending** — legacy patients (branch_id null) keep
  their original RM and `clinic_id`; a controlled backfill phase is recommended.
- **Node VPS v18 vs Tailwind oxide ≥20** — keep building assets on a compatible
  Node locally and shipping `public/build`.
- **npm audit vulnerabilities** — unchanged by this phase; review separately.
- The dead `RmeInvoiceService::findInBranch` / `ClinicVisitService::find` still
  use `BranchContext::requireId()` but are not called by any RME route.

## 11. Next Recommended Phase

**Sprint 23 Phase 23.10.1 — VPS Deploy + RME Pilot Data Entry Smoke.** Deploy this
branch following the Phase 21.7 checklist (backup DB; `migrate --force` only;
never `migrate:fresh`/`db:wipe`), then run a full browser smoke of the hardened
flow (new/existing patient → odontogram → medical record → cashier billing →
payment → lab candidate) across TKM1/LDK2/ATG3.
