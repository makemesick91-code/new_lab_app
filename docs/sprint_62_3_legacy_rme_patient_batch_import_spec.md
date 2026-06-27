# Sprint 62.3 — Legacy RME Patient Batch Import

**Status:** IMPLEMENTED (2026-06-27) on branch `feature/sprint-62-3-legacy-rme-patient-batch-import`. Staging + preview + commit + rollback shipped with tests. No PR / no deploy yet. See "Implementation Notes" at the end of this file.
**Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`).
**Scope:** Safe, auditable, rollback-safe batch import of legacy RME patient data from an uploaded Excel/CSV file into the existing patient master — via a **staging + preview + commit** workflow. No HR scope. No weakening of existing RME workflow gates.

---

## 0. Design Summary (one paragraph)

The cashier/admin uploads a legacy patient spreadsheet. The system **parses → normalizes → validates → maps** every row into a **staging table** (`stg_legacy_patient_imports`) tied to an **import batch** (`stg_legacy_patient_import_batches`). Nothing touches `mst_patients` at upload time. The admin reviews a **preview screen** (valid / warning / error counts, masked KTP) and can **download an error report (CSV)**. Only on an explicit **Commit** action are validated rows inserted into `mst_patients` inside a single DB transaction, each stamped with `import_batch_id` so the whole batch is **rollback-safe** (soft-delete revert by batch). RM numbers follow the locked DaengtisiaMS rule `DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}` via the existing `PatientMedicalRecordNumberService`. KTP/NIK is never rendered in full (reuse `maskKtp()` → `****` + last 4).

---

## 1. Current Patient / RME Model Inspection

### 1.1 `Patient` (`mst_patients`) — `App\Modules\Patient\Models\Patient`
Fillable: `clinic_id`, `doctor_id`, `branch_id`, `medical_record_number`, `registered_at`, `manual_rm_number`, `ktp_number`, `name`, `gender`, `date_of_birth`, `phone`, `whatsapp_number`, `email`, `address`, `occupation`, `is_active`. Uses `SoftDeletes`.
- `branch_id` is **nullable** — a patient with no branch is "legacy" (`isLegacyWithoutBranch()`). Branch is never auto-rewritten (Sprint 23.10).
- `medical_record_number` is the final composed RM string; `manual_rm_number` stores the raw manual sequence the admin typed.
- `gender` enum on the create form is `Male|Female|Other`.

### 1.2 RM number rule — `PatientMedicalRecordNumberService` (Sprint 23.8, locked)
`compose(branchCode, year, manualRmNumber)` → `DG-{BRANCH_CODE_UPPER}-{YYYY}-{MANUAL}`.
- Prefix is exactly `DG`. Branch code uppercased/trimmed. Year must be 4 digits.
- Manual RM number is **trimmed but NOT auto-padded and NOT auto-generated** — leading zeros preserved verbatim.
- `exists(rm, ?ignoreId)` checks global uniqueness **including soft-deleted** patients.
- `composeForRegistration(branchCode, registeredAt, manual)` derives year from registration date.
- (Auto-sequence fallback lives in `PatientCodeGenerator`; **not used** for legacy import since the sheet carries a manual RM.)

### 1.3 `ClinicVisit` (`trx_clinic_visits`) — NOT created by this import
Statuses, `VALID_TRANSITIONS`, room gate (`requiresRoomBeforeExam()`), consent, doctor→cashier completion gate all unchanged. **This import creates patient master rows only — no visits, no medical records, no invoices.** (Visit/RM creation is explicitly out of scope; see §17 deferral.)

### 1.4 `MedicalRecord` (`trx_medical_records`) — NOT created by this import
Kept here only to document that legacy `Keluhan Utama` / `Tindakan Awal` from the sheet are **not** auto-promoted into RM/visit rows in this sprint (they are staged as advisory text only). Avoids weakening RME gates.

### 1.5 `Branch` (`mst_branches`)
`code`, `name`, `is_active`, `is_rme_enabled`. Scope `rmeEnabled()`. `MAIN_CODE = 'MAIN'` is head office and is **excluded** from RME branch selection (consistent with Sprint 61/62 audit filters).

### 1.6 `ClinicRoom` (`mst_clinic_rooms`)
Branch-scoped (`branch_id`), `code`, `name`, `type`, `status` (`active|inactive|maintenance`). MAIN never selectable. Rooms are operational/visit-stage data — for legacy patient master import the `Ruangan` column is **advisory only** (staged, not written to `mst_patients`, which has no room column).

### 1.7 `Doctor` (`mst_doctors`)
`clinic_id`, `code`, `name`, `is_active`. `Patient.doctor_id` is a FK to this table.

### 1.8 Existing import pattern (reuse, do not reinvent)
`Inventory\Services\ProductImportService` + `ProductImportController`:
- Native PHP CSV (`fgetcsv`) — **no `maatwebsite/excel` dependency in `composer.json`**.
- Header normalization (strip BOM, lowercase), strict header assertion, blank-row skip.
- Row-level `{row, field, message}` error collection; commit only when `errors === []`, inside `DB::transaction`.
- Template download via `response()->streamDownload`.
- **Difference for 62.3:** ProductImport is all-or-nothing with no staging. Legacy patient import **adds a persistent staging layer + preview + per-batch rollback** because patient data is higher-risk and larger.

### 1.9 Permission & privacy primitives
- Authorization gate: **`manage patients`** (note: Spatie permission name uses a space, per `PatientPolicy`). Import reuses this — **no new permission**.
- KTP masking: `PatientDataCompletenessService::maskKtp()` → `****` + last 4 digits (or all `*` when <4). Reuse verbatim; never render full KTP.
- KTP scans live on the private `local` disk (`PatientDocument`); the Excel import carries **no scan files** — KTP is a text column only.

---

## 2. Excel Column Mapping

Input sheet columns → target. The uploaded file is converted to **CSV** before upload (operator step) OR parsed with a lightweight reader; spec assumes **CSV** for parity with existing import infra (see §16 for the Excel-vs-CSV decision).

| # | Excel Column | Maps to | Target field | Notes |
|---|---|---|---|---|
| 1 | No. | — | (ignored) | Sheet row counter only |
| 2 | ID Pasien | staging `source_patient_id` | (not written to master) | Legacy external id, kept for audit/trace |
| 3 | Nomor KTP | normalize → `ktp_number` | `mst_patients.ktp_number` | Digits only, ≤16, masked everywhere in UI |
| 4 | Cabang | resolve → `branch_id` | `mst_patients.branch_id` | Match `mst_branches.code`/`name`, RME-enabled, not MAIN |
| 5 | Ruangan | staging `source_room_label` | (advisory only) | Not on patient master; resolved for report only |
| 6 | Timestamp | parse → `registered_at` | `mst_patients.registered_at` | Drives RM **year**; date cast |
| 7 | Nomor RM Manual | normalize → `manual_rm_number` | `mst_patients.manual_rm_number` + RM compose | Digits only (matches `StorePatientRequest` regex) |
| 8 | Dokter | resolve → `doctor_id` | `mst_patients.doctor_id` | Match `mst_doctors.name`/`code` |
| 9 | Nama Pasien | trim → `name` | `mst_patients.name` | **Required**, ≤150 |
| 10 | Ponsel | trim → `phone` | `mst_patients.phone` | ≤50 |
| 11 | Nomor WA | normalize → `whatsapp_number` | `mst_patients.whatsapp_number` | ≤50 |
| 12 | E-Mail | trim/lower → `email` | `mst_patients.email` | valid email or blank, ≤150 |
| 13 | Jenis Kelamin | map → `gender` | `mst_patients.gender` | `L/Laki-laki→Male`, `P/Perempuan→Female`, else `Other`/blank |
| 14 | Tanggal Lahir | parse → `date_of_birth` | `mst_patients.date_of_birth` | date or blank |
| 15 | Umur | — | (ignored / cross-check) | Derived; optionally warn if mismatches DOB |
| 16 | Alamat Lengkap | trim → `address` | `mst_patients.address` | ≤1000 |
| 17 | Pekerjaan | trim → `occupation` | `mst_patients.occupation` | ≤150 |
| 18 | Tindakan Awal | staging `source_initial_treatment` | (advisory only) | NOT written to master/visit (no gate bypass) |
| 19 | Keluhan Utama | staging `source_chief_complaint` | (advisory only) | NOT written to master/visit |
| 20 | TTD Surat Persetujuan — Dokter | staging `source_consent_doctor` | (advisory only) | Consent is a **visit-stage** gate; never seeded from import |
| 21 | TTD Surat Persetujuan — Pasien | staging `source_consent_patient` | (advisory only) | Same — consent gate not weakened |

Fixed/derived at commit (not from sheet): `clinic_id` (default active clinic / config), `is_active = true`, `medical_record_number` (composed), `import_batch_id`, `created_by`.

---

## 3. Required Fields (row rejected if missing/invalid)

1. **Nama Pasien** — non-empty, ≤150.
2. **Cabang** — must resolve to an existing, **active, RME-enabled, non-MAIN** branch.
3. **Nomor RM Manual** — non-empty, digits only (`^[0-9]+$`), ≤50. (Required because RM compose has no auto-sequence in this flow.)
4. **Timestamp / Tanggal Daftar** — parseable date; supplies the RM **year**. If blank → row error (year cannot be guessed safely).

> Rationale: these four are the minimum to compose a unique, rule-compliant RM and anchor the patient to a real RME branch. Everything else is optional.

---

## 4. Optional Fields (blank allowed; validated only if present)

- Nomor KTP (digits, ≤16, unique if present)
- Dokter (resolve if present; else `doctor_id = null` + warning)
- Ponsel, Nomor WA, E-Mail, Jenis Kelamin, Tanggal Lahir, Alamat Lengkap, Pekerjaan
- ID Pasien, Ruangan, Umur (staged for audit/report; not on master)
- Tindakan Awal, Keluhan Utama, both TTD columns (staged as advisory text only)

---

## 5. Validation Rules

Validation runs at **parse-to-staging** time and again (re-asserted) at **commit** time under lock. Each failure produces `{row, column, severity, message}`.

**Severity model:**
- `error` — blocks the row from commit.
- `warning` — row still committable, surfaced in preview/report (e.g. unresolved doctor, age/DOB mismatch, missing KTP).

| Field | Rule | Severity |
|---|---|---|
| Nama Pasien | required, string ≤150 | error |
| Cabang | required; resolves to active + RME-enabled + non-MAIN branch | error |
| Nomor RM Manual | required; `^[0-9]+$`; ≤50 | error |
| Timestamp | required; parseable to date | error |
| Composed RM | `DG-{CODE}-{YEAR}-{MANUAL}` globally unique (incl. soft-deleted) AND unique within this file | error |
| Nomor KTP | if present: digits, ≤16, unique in `mst_patients` (incl. trashed) AND unique within file | error |
| E-Mail | if present: valid email, ≤150 | error |
| Tanggal Lahir | if present: valid date, not in future | error |
| Jenis Kelamin | maps to Male/Female/Other; unmappable non-blank | warning |
| Dokter | if present but unresolved → `doctor_id = null` | warning |
| Umur vs Tanggal Lahir | derived age mismatch > 1yr | warning |
| Ponsel / WA / Alamat / Pekerjaan | length caps (50/50/1000/150) | error if exceeded |
| Duplicate (soft) | name + DOB or name + phone matches existing active patient | warning (see §6) |

Normalization before validation: trim all; strip BOM on header; KTP/RM keep digits only; email lowercased; WA/phone keep `+`/digits; gender mapped from Indonesian labels.

---

## 6. Duplicate Detection Strategy (critical)

Three layers, evaluated in order:

**A. Hard duplicates → `error` (block):**
1. **Composed RM collision** — `DG-{code}-{year}-{manual}` already exists in `mst_patients` (incl. soft-deleted) → block. This is the authoritative identity key.
2. **KTP collision** — `ktp_number` already exists (incl. soft-deleted) → block. (Mirrors `StorePatientRequest` unique rule.)
3. **In-file duplicates** — same composed RM or same KTP appearing twice within the upload → block both rows (or block the second; flag the pair).

**B. Soft duplicates → `warning` (review, not block):**
4. **Name + DOB** match against an existing active patient.
5. **Name + Phone/WA** match.
   Reuse the duplicate-risk logic already present in `CrossBranchPatientLookupService` / `PatientDataCompletenessService` rather than writing new fuzzy matching. Warnings are surfaced in preview with the masked existing-patient RM so the admin can decide.

**C. Re-check at commit under lock:** the RM/KTP uniqueness checks are re-run inside the commit transaction (with `lockForUpdate` on a candidate-existence query) so a concurrent registration between preview and commit cannot create a silent duplicate. A row that became a duplicate after preview is skipped and reported, never overwritten.

**Idempotency:** committing the same batch twice is prevented by the batch's `status` (a `committed` batch cannot be re-committed) and by the per-row uniqueness re-check.

---

## 7. RM Number Generation Strategy

- **Reuse `PatientMedicalRecordNumberService::composeForRegistration($branch->code, $registeredAt, $manualRmNumber)`** — do not reimplement the format.
- Branch code from the resolved `Cabang`. Year from the parsed `Timestamp` (`registered_at`). Manual from `Nomor RM Manual` (verbatim, leading zeros preserved, **no auto-padding, no auto-sequence**).
- Result format: `DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}` (e.g. `DG-TKM1-2024-0001`).
- Uniqueness enforced via `exists()` (incl. trashed) at validation and re-checked at commit.
- The legacy sheet **always** supplies the manual number → `PatientCodeGenerator` auto-sequence is intentionally **not** used. If a row lacks a manual RM it is an `error`, not an auto-generate trigger (avoids minting unintended sequences over legacy data).
- Both `manual_rm_number` (raw) and `medical_record_number` (composed) are written, matching the manual-create flow.

---

## 8. Branch / Dokter / Ruangan Mapping Strategy

**Branch (`Cabang`) — strict, required:**
- Resolve by exact match on `mst_branches.code` (uppercased) first, then case-insensitive `name`.
- Candidate set limited to `is_active = true AND is_rme_enabled = true AND code != 'MAIN'`.
- Build the lookup map **once per batch** (like ProductImport's `keyBy`) for performance.
- Unresolved/ambiguous/MAIN/disabled → `error`. Never auto-create a branch.

**Dokter (`Dokter`) — lenient, optional:**
- Resolve by `mst_doctors.code` then case-insensitive `name`.
- Unresolved → `doctor_id = null` + `warning` (legacy data often has free-text doctor names). Never auto-create a doctor.

**Ruangan (`Ruangan`) — advisory only:**
- `mst_patients` has no room column and rooms are visit-stage. Resolve (branch-scoped, active) only to validate the label for the report; **never written to patient master**. Unresolved → informational note, never blocks.

---

## 9. Staging Table Design

Two additive tables. **No changes to `mst_patients` schema** beyond a nullable trace column (see note).

### 9.1 `stg_legacy_patient_import_batches`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| uuid | uuid, unique | external/audit handle |
| original_filename | string | uploaded name |
| stored_path | string | upload parked on **private** disk |
| file_hash | string(64) | sha256 — detect re-upload of same file |
| status | string | `uploaded`,`parsed`,`previewed`,`committing`,`committed`,`rolled_back`,`failed` |
| total_rows | int | parsed data rows |
| valid_rows | int | committable (no error) |
| error_rows | int | |
| warning_rows | int | |
| committed_count | int | actually inserted |
| uploaded_by | bigint FK users | |
| committed_by | bigint FK users, null | |
| committed_at | datetime, null | |
| rolled_back_by | bigint FK users, null | |
| rolled_back_at | datetime, null | |
| timestamps, softDeletes | | |

### 9.2 `stg_legacy_patient_imports` (one row per sheet data row)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| import_batch_id | bigint FK → batches, indexed | |
| source_row_number | int | maps to sheet "No." / file line |
| source_patient_id | string, null | legacy `ID Pasien` |
| raw_payload | json | full original row, verbatim (audit) |
| ktp_number | string, null | normalized (stored; **never shown in full**) |
| name | string, null | |
| gender | string, null | mapped |
| date_of_birth | date, null | |
| phone | string, null | |
| whatsapp_number | string, null | |
| email | string, null | |
| address | text, null | |
| occupation | string, null | |
| registered_at | date, null | |
| manual_rm_number | string, null | |
| resolved_branch_id | bigint FK, null | |
| resolved_doctor_id | bigint FK, null | |
| source_room_label | string, null | advisory |
| source_initial_treatment | string, null | advisory |
| source_chief_complaint | text, null | advisory |
| source_consent_doctor | string, null | advisory |
| source_consent_patient | string, null | advisory |
| composed_rm_number | string, null | preview of final RM |
| row_status | string | `valid`,`warning`,`error`,`committed`,`skipped` |
| validation_messages | json | `[{column,severity,message}]` |
| created_patient_id | bigint FK → mst_patients, null | set at commit (trace + rollback) |
| timestamps | | |

Indexes: `(import_batch_id, row_status)`, `(composed_rm_number)`, `(ktp_number)`.

**Patient trace column (note / decision needed):** add nullable `mst_patients.import_batch_id` (FK, indexed) so committed patients are batch-attributable for rollback and reporting. This is the single touch to a production table and **is a migration** — flagged as the one schema change in §16. Alternative (no schema change): rely solely on `stg_legacy_patient_imports.created_patient_id` for rollback mapping. **Recommended: add the nullable column** (cleaner rollback, queryable provenance); fall back to staging-only mapping if the owner wants zero `mst_patients` changes.

---

## 10. Import Preview Workflow

1. **Upload** (`GET/POST .../legacy-patients/import`) — admin with `manage patients` uploads CSV. File stored on private disk; `file_hash` computed; warn if an identical hash was committed before.
2. **Parse → stage** — header asserted against the expected legacy template; each data row normalized, validated, mapped, and inserted into `stg_legacy_patient_imports` with `row_status` + `validation_messages`. Batch status → `parsed`/`previewed`. Nothing in `mst_patients`.
3. **Preview screen** (`GET .../import/{batch}/preview`):
   - Summary cards: total / valid / warning / error / will-be-committed.
   - Paginated row table: source row #, masked KTP (`****1234`), name, branch (resolved), composed RM, status badge, first message.
   - Filters: status (valid/warning/error), branch, search by name/RM.
   - Actions: **Download error report**, **Commit valid rows**, **Discard batch**.
   - KTP shown masked only; advisory consent/treatment columns shown read-only and clearly labeled "tidak diimpor".
4. **Commit gate** — Commit button enabled only when `valid_rows > 0` and batch status is `previewed` (not already committed).

---

## 11. Error Report / Download Workflow

- `GET .../import/{batch}/errors.csv` (or `.xlsx`) streams a report (reuse `streamDownload` + `fputcsv`).
- Columns: `source_row_number, name, branch, composed_rm_number, ktp_masked, row_status, column, severity, message`.
- One line per message (a row with 3 errors → 3 lines) for easy triage.
- **KTP masked** in the report too — never full digits.
- The operator fixes the source sheet and re-uploads as a **new batch** (no in-place row editing in v1 — keeps audit clean). Optional v2: inline fix of a single staged row.

---

## 12. Commit / Final Import Workflow

1. Admin clicks **Commit valid rows**; authorize `manage patients`; assert batch status `previewed` (idempotency).
2. Set batch `status = committing` (guards against double-submit / concurrent commit).
3. **Single `DB::transaction`:**
   - Iterate `row_status IN (valid, warning)` staged rows.
   - **Re-validate under lock:** re-check composed RM + KTP uniqueness (incl. trashed) with `lockForUpdate` on existence queries. If now duplicate → mark staged row `skipped` + message, continue (do not abort whole batch).
   - Insert into `mst_patients` with mapped fields + `medical_record_number` (composed) + `manual_rm_number` + `import_batch_id` + `is_active = true` + `created_by`.
   - Write back `created_patient_id` and `row_status = committed` on the staging row.
   - Increment `committed_count`.
4. On success: batch `status = committed`, `committed_by/at` stamped. Flash `"{n} pasien legacy berhasil diimpor."` Redirect to batch summary.
5. On unexpected exception: transaction rolls back fully; batch `status = failed`; nothing persisted in `mst_patients`; error surfaced. (All-or-nothing for the commit transaction; per-row `skipped` is the only partial outcome and it persists nothing for skipped rows.)
6. Post-commit hook is **none** — no visit/RM/invoice/lab generation (explicitly out of scope; RME gates untouched).

---

## 13. Rollback Strategy

- **Primary (batch revert):** `POST .../import/{batch}/rollback`, `manage patients`, allowed only while batch `status = committed`. Inside one transaction: **soft-delete** all `mst_patients` where `import_batch_id = {batch}` (via `created_patient_id` trace as cross-check), set batch `status = rolled_back`, stamp `rolled_back_by/at`. Soft delete (not hard) preserves audit and lets `withTrashed()` uniqueness checks still see the freed RM/KTP as taken until purged — **guard:** rollback is blocked if any imported patient already has a downstream `trx_clinic_visits` / `trx_medical_records` row (cannot silently delete a patient who has started a real visit). Such rows are listed for manual handling.
- **Discard (pre-commit):** `DELETE .../import/{batch}` while status `uploaded/parsed/previewed` — soft-delete the batch + staging rows; nothing in `mst_patients` was ever touched.
- **Transaction safety:** the commit itself is atomic, so a crashed commit needs no rollback action (DB rolls back).
- **Auditability:** batch + staging rows are retained (soft-deleted) after rollback so "who imported / who reverted / what was in the file" is fully reconstructable. `raw_payload` keeps the original row verbatim.

---

## 14. Privacy / Security Rules

- **Authorization:** every route gated by **`manage patients`** (reuse; no new permission). No public access.
- **KTP/NIK never exposed in full** anywhere — preview, error report, batch summary all use `maskKtp()` (`****` + last 4). Full KTP exists only in `stg_legacy_patient_imports.ktp_number` / `raw_payload` and committed `mst_patients.ktp_number`, never rendered.
- **Uploaded file on private disk** (`local`), not `public`; served only through authorized controller, never a public URL.
- **No scanned documents** in this import (text only). No KTP image handling.
- **No raw medical notes / SOAP / odontogram** imported; `Keluhan Utama` / `Tindakan Awal` are advisory staging text, not clinical records.
- **MAIN branch excluded**; branch set limited to active RME-enabled branches (consistent with audit/KPI scoping).
- **No HR scope.** No payment/receivable/invoice records created.
- **Audit:** `uploaded_by`, `committed_by`, `rolled_back_by` + timestamps on every batch; `raw_payload` retained.
- Consent (TTD) columns are advisory only — the visit-stage consent gate (`CreateRmePaymentRequest`) is **never** pre-satisfied from import.

---

## 15. Test Plan (`tests/Feature/Patient/LegacyPatientBatchImportTest.php`)

**Parsing & mapping**
1. Valid file parses to staging; no `mst_patients` writes at upload.
2. Header mismatch → rejected with clear message.
3. Indonesian gender labels (`L`,`P`,`Laki-laki`,`Perempuan`) map correctly; unknown → warning + `Other`/null.
4. KTP / RM digit normalization; leading zeros in manual RM preserved.

**Validation**
5. Missing name / branch / manual RM / timestamp → `error` rows, not committable.
6. Disabled/MAIN/unknown branch → error.
7. Invalid email / future DOB → error.
8. Unresolved doctor → warning, `doctor_id = null`, still committable.

**RM generation**
9. Composed RM equals `DG-{CODE}-{YEAR}-{MANUAL}` from `composeForRegistration`.
10. Year derived from Timestamp, not "today".

**Duplicates**
11. Existing RM (incl. soft-deleted) → error, row blocked.
12. Existing KTP → error.
13. In-file duplicate RM/KTP → error.
14. Soft duplicate (name+DOB) → warning, still committable.
15. Concurrent insert between preview and commit → row `skipped` at commit, not duplicated.

**Preview / report**
16. Preview summary counts correct; KTP rendered masked only.
17. Error report CSV streams, one line per message, KTP masked.

**Commit**
18. Commit inserts only valid/warning rows; sets `created_patient_id`, `import_batch_id`, composed RM, `is_active`.
19. Re-committing a `committed` batch is rejected (idempotency).
20. Authorization: user without `manage patients` is 403 on upload/preview/commit/rollback.

**Rollback**
21. Rollback soft-deletes the batch's patients; batch → `rolled_back`; freed RM still seen by uniqueness check (trashed).
22. Rollback blocked when an imported patient already has a visit/RM.
23. Pre-commit discard removes staging only; `mst_patients` untouched.

**Privacy / regression**
24. Full KTP never present in any HTML/CSV response body.
25. No `trx_clinic_visits` / `trx_medical_records` / invoice rows created by import (RME gates intact).
26. `vendor/bin/pint --test` and `git diff --check` clean.

---

## 16. Suggested Implementation Sprint Prompt

> **Sprint 62.3 — Legacy RME Patient Batch Import (implementation).**
> Base branch `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main). Follow `docs/sprint_62_3_legacy_rme_patient_batch_import_spec.md` exactly. Use `laravel-module-pattern` + `limit-saver`. Touch only Patient + Branch/Doctor/ClinicRoom lookups + a new staging layer.
>
> 1. **Migrations:** `stg_legacy_patient_import_batches`, `stg_legacy_patient_imports` (schema per §9), and a nullable indexed `mst_patients.import_batch_id` FK. Use `migrate` only — never `migrate:fresh`/`db:wipe`.
> 2. **Models:** `LegacyPatientImportBatch`, `LegacyPatientImportRow` under `App\Modules\Patient\Models` (SoftDeletes, status constants, relations).
> 3. **Service `LegacyPatientImportService`:** `parseAndStage(UploadedFile, batch)`, `validateAndMapRow()`, `previewSummary(batch)`, `commit(batch)`, `rollback(batch)`. Reuse `PatientMedicalRecordNumberService::composeForRegistration` for RM, branch/doctor `keyBy` lookup maps (RME-enabled, non-MAIN), and `maskKtp()`. CSV parse modeled on `ProductImportService` (BOM strip, header assert, blank-row skip). Commit = single `DB::transaction` + per-row `lockForUpdate` uniqueness re-check; rollback = soft-delete by `import_batch_id` with downstream-visit guard.
> 4. **Controller `LegacyPatientImportController`** (gate `manage patients` on every action): `create`, `template`, `store` (upload→stage), `preview`, `errors` (CSV stream, masked KTP), `commit`, `rollback`, `destroy` (discard). FormRequests for upload (mime csv/txt, max size) and commit.
> 5. **Routes:** under `/rme/patients/legacy-import` (or `/patients/legacy-import`), named `patients.legacy-import.*`. Sidebar item under Patient/RME gated by `manage patients`.
> 6. **Views:** `import/create`, `import/preview` (summary cards + filtered paginated table, masked KTP, advisory columns labeled "tidak diimpor"), reuse TailAdmin `x-ui.*`. No full KTP anywhere.
> 7. **Tests:** `tests/Feature/Patient/LegacyPatientBatchImportTest.php` per §15. Run `php artisan test --filter=Patient`, `tests/Feature/RME` regression, `vendor/bin/pint --test`, `git diff --check`. After code, `graphify update .`.
> 8. **Do NOT:** create visits/RM/invoices/consent from import; weaken any RME gate; render KTP in full; add new permissions; target main; deploy.
>
> **Excel vs CSV decision to confirm with owner before coding:** the existing import infra is native-PHP CSV (no `maatwebsite/excel`). Default to **operator exports the sheet to CSV** and import CSV (zero new dependency). Only if the owner requires direct `.xlsx`, add `openspout/openspout` (lighter than maatwebsite) behind the same service interface.

---

## 17. GO / NO-GO Criteria

**GO when:**
- Upload writes only to staging; `mst_patients` untouched until explicit Commit.
- Preview shows accurate valid/warning/error counts with **masked** KTP and a downloadable error report.
- Composed RM follows `DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}` via the existing service; uniqueness enforced incl. soft-deleted, at validate + commit.
- Hard duplicate (RM/KTP, in-file and vs DB) blocks; soft duplicate warns.
- Commit is a single atomic transaction; every committed patient carries `import_batch_id` + `created_patient_id` trace.
- Rollback soft-deletes a committed batch's patients, blocked when downstream visit/RM exists; pre-commit discard leaves master untouched.
- Every route gated by `manage patients`; full KTP never appears in any response; uploaded file on private disk.
- No visits/RM/invoices/consent created; no RME gate weakened; MAIN excluded; no HR scope.
- Tests green (`--filter=Patient` + `tests/Feature/RME` regression), `pint --test` + `git diff --check` clean.

**NO-GO if any of:**
- Any path writes to `mst_patients` before Commit, or commits invalid/error rows.
- Full KTP/NIK rendered or exported anywhere.
- Duplicate RM/KTP can be created (race at commit, or soft-deleted collision missed).
- RM format deviates from the locked rule or auto-generates a sequence over legacy data.
- A visit/medical-record/invoice/consent row is created, or any RME gate (room, consent, doctor→cashier, full-payment) is bypassed/weakened.
- Rollback hard-deletes patients or silently removes patients that already have downstream visits.
- New permission added, MAIN branch importable, branch auto-created, or base/target branch is `main`.
- `migrate:fresh`/`db:wipe` used anywhere in the workflow.

---

## Appendix A — Graphify Data-Flow Graph

```
Excel/CSV upload (manage patients)
        │  (private disk, file_hash)
        ▼
Parse rows  ──► normalize (trim/BOM/digits/gender map)
        │
        ▼
Validate + map ──► Branch(active,RME,non-MAIN) / Doctor(opt) / RM compose (DG-CODE-YEAR-MANUAL)
        │                         │
        │                         └─► duplicate check (RM, KTP, in-file, soft name+DOB)
        ▼
Staging rows  (stg_legacy_patient_imports ← stg_legacy_patient_import_batches)
   row_status: valid | warning | error            [mst_patients UNTOUCHED]
        │
        ▼
Preview  ──► summary cards + masked KTP + filters
   │                 │
   │                 └─► Error report CSV (masked KTP, 1 line/message)
   ▼
Commit (DB::transaction + lockForUpdate re-check)
        │   skip-on-race → row_status: skipped
        ▼
Patient/RME records  (mst_patients: composed RM, import_batch_id, created_by)
        │   created_patient_id written back to staging
        ▼
Audit / report  (batch status: committed)
        │
        └─► Rollback (soft-delete by import_batch_id, guarded by downstream visit/RM)
                → batch status: rolled_back   [auditable, reversible]
```

---

**End of Sprint 62.3 design specification.**

---

## Implementation Notes (2026-06-27)

Implemented on `feature/sprint-62-3-legacy-rme-patient-batch-import` (base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`, HEAD `298a440`; not targeting main).

**Migrations (additive, `migrate` only — no `migrate:fresh`/`db:wipe`):**
- `stg_legacy_patient_import_batches` — staging header (uuid, file_hash, status, counts, audit who/when, softDeletes).
- `stg_legacy_patient_imports` — staging detail (raw + normalized payload JSON, status, errors/warnings JSON, matched branch/doctor/room, masked KTP, display fields, advisory columns, `committed_patient_id`).
- `mst_patients.import_batch_id` — nullable indexed FK trace.
- `mst_patients.doctor_id` made **nullable** (one extra production-table change beyond the spec's single flagged column — required because an unresolved legacy doctor maps to `doctor_id = NULL`; mirrors the Sprint 23.9.1 `clinic_id` relaxation; additive, non-destructive).

**Code:** `LegacyPatientImportBatch` / `LegacyPatientImportRow` models; `LegacyPatientImportService` (parseAndStage / validateAndMapRow / commit / rollback / discard / template / errorReport); `LegacyPatientImportController` + `UploadLegacyPatientCsvRequest`; routes `settings.patients.import.*` under the `manage patients` group; views `settings/patients/import/{create,preview}`; sidebar "Impor Pasien Legacy".

**Behavior:** CSV parse mirrors `ProductImportService`. RM via `PatientMedicalRecordNumberService::composeForRegistration` (year from Timestamp). Hard block: composed-RM collision (incl. trashed), KTP collision (incl. trashed), in-file dup RM/KTP. Soft warning: name+DOB match, unresolved doctor, gender unmappable, age/DOB mismatch. Commit is one `DB::transaction` with `lockForUpdate` RM/KTP re-check (race → row `skipped`). Idempotent (committed batch re-commit is a no-op). Rollback soft-deletes the batch's patients, blocked when any has a downstream visit/RM. KTP masked everywhere (`PatientDataCompletenessService::maskKtp`); full KTP only in JSON payload columns, never rendered. clinic_id left NULL (branch-anchored, per RME model). No visit/RM/invoice/consent/odontogram created. MAIN excluded; branch set = active + RME-enabled.

**Tests:** `tests/Feature/Patient/LegacyPatientBatchImportTest.php` (24 passed).
