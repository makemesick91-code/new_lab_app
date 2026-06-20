# Sprint 57 — Cross-Branch Nomor RM Lookup — Implementation Spec

Branch: `feature/sprint-57-cross-branch-rm-lookup`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`

## 1. Goal
Add a small, read-only "Cek Nomor RM Seluruh Cabang" lookup on the **Kunjungan**,
**Rekam Medis**, and **Kasir** pages so staff can check whether an exact Nomor RM
already exists in **any** branch (duplicate-registration prevention + identify the
patient's origin branch).

## 2. Non-goals
- No broad cross-branch listing. Normal index lists stay scoped exactly as today.
- No name "contains" search across branches. Exact RM match only.
- No editing/creating visits/payments for another-branch patient from the lookup.
- No new permission slugs, no role changes, no policy/middleware rewrite.
- No schema/migration changes. No new dependencies. No `.env` changes.

## 3. Business rule
Exact `medical_record_number` match across all branches. Because the column is
`unique`, an exact match returns at most one patient; we still `limit(5)` and flag
duplicates defensively. Result shows whether the patient is in the **current**
branch ("Pasien ditemukan di cabang saat ini") or **another** branch
("Pasien ditemukan di cabang lain").

## 4. Privacy rule
Result exposes ONLY: Nomor RM, patient name, branch (code — name), active status,
latest visit date. NEVER: `ktp_number`, `whatsapp_number`, `phone`, `email`,
`address`, diagnosis, treatment, private notes, tokens/.env. Read-only.

## 5. Branch isolation rule
This is a **deliberate, explicit cross-branch exception** isolated in one named
service method. Existing branch-scoped index/show/create/payment queries are
untouched. The lookup never mutates data and never grants cross-branch actions.

## 6. Files to inspect (done)
- `app/Modules/Patient/Models/Patient.php` — RM field = `medical_record_number`, `branch()` relation, `ktp_number` present (hide).
- `app/Modules/ClinicVisit/Models/ClinicVisit.php` — `patient_id`, `visit_date`.
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` `index()`.
- `app/Modules/MedicalRecord/Controllers/MedicalRecordController.php` `index()`.
- `app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php` `index()`.
- `app/Modules/Branch/Services/BranchContext.php` — `id()` for current-branch flag.
- Views: `rme/visits/index`, `rme/visits/medical-record/index`, `rme/cashier/index`.
- `routes/web.php` L200-271 (no route change needed).

## 7. Files expected to change
- NEW `app/Modules/Patient/Services/CrossBranchPatientLookupService.php`
- NEW `resources/views/rme/partials/cross-branch-rm-lookup.blade.php`
- EDIT 3 controllers' `index()` — read `rm_lookup` param, pass results to view.
- EDIT 3 index blades — include the partial.
- NEW `tests/Feature/RME/CrossBranchRmLookupTest.php`
- NEW this spec doc.

## 8. Actual Nomor RM field discovered
`mst_patients.medical_record_number` (string(50), nullable, **unique**). The
manual suffix `manual_rm_number` is NOT used for matching.

## 9. Route/controller/service design
**No new route / no permission divergence.** The lookup is handled inline on each
existing index route via a `?rm_lookup=` GET query param, so each page's existing
permission gate applies automatically (`view_clinic_visits|manage_clinic_visits`
for Kunjungan/Rekam Medis; `manage_rme_billing` for Kasir).

Service `App\Modules\Patient\Services\CrossBranchPatientLookupService`:
- `lookupByMedicalRecordNumberAcrossBranches(?string $rm): array`
  - Trim; empty/blank → return `[]`.
  - `Patient::query()->select(['id','name','medical_record_number','branch_id','is_active'])`
    `->where('medical_record_number', $rm)->with('branch:id,code,name')->limit(5)->get()`.
  - **Deliberately NO branch_id filter** — documented exception.
  - Map each to a safe array: `medical_record_number`, `name`, `branch_label`,
    `is_active`, `latest_visit_date` (max `visit_date` from `trx_clinic_visits`),
    `is_current_branch` (compare `branch_id` to `BranchContext::id()`).
  - `is_duplicate` flag when result count > 1.

## 10. UI design
Shared partial `rme/partials/cross-branch-rm-lookup.blade.php`, `x-ui.card`,
TailAdmin/Tailwind style matching existing filter cards:
- Heading "Cek Nomor RM Seluruh Cabang".
- GET form (action = current route, preserves nothing else): input `rm_lookup`
  placeholder "Masukkan Nomor RM", button "Cek RM", reset link.
- Results: compact table (RM, Nama, Cabang, Status, Kunjungan Terakhir, note badge).
- Empty state when searched + no result: "Nomor RM tidak ditemukan di cabang mana pun."
- Privacy note: "KTP dan data sensitif tidak ditampilkan."
Included near the top of all three pages: Kunjungan, Rekam Medis, Kasir.

## 11. Result fields allowed
Nomor RM, patient name, branch code+name, active status, latest visit date,
current-vs-other-branch note.

## 12. Result fields forbidden
`ktp_number`, `whatsapp_number`, `phone`, `email`, `address`, diagnosis,
treatment, notes, passwords/tokens/.env, any clinical detail.

## 13. Test plan (`tests/Feature/RME/CrossBranchRmLookupTest.php`)
- Finds patient from ANOTHER branch by exact RM; result includes branch name.
- Flags current-branch patient as current branch.
- Result payload does NOT contain `ktp_number` / whatsapp / address.
- Empty `rm_lookup` returns empty result.
- Unknown RM returns empty result (safe empty state).
- Partial/substring of an RM does NOT match (exact only).
- Kunjungan / Rekam Medis / Kasir index lists remain branch-scoped (no leakage of
  other-branch rows into the normal list when `rm_lookup` is absent).

## 14. Risk checklist
- [ ] No permission slug / role / policy / middleware change.
- [ ] No migration / schema change.
- [ ] No new dependency.
- [ ] No `.env` change.
- [ ] Existing index queries unchanged (branch isolation intact).
- [ ] No KTP / WA / address / clinical data exposed.
- [ ] Read-only (GET), no mutation paths added.
- [ ] Pint clean; targeted tests pass.

## 15. Rollback plan
Feature is additive and isolated. Rollback = `git revert` the single commit (or
delete branch). No data migration to undo, no schema change, no config change.
