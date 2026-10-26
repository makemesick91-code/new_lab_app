---
paths:
  - "app/Modules/Patient/**"
  - "app/Modules/PatientMerge/**"
  - "resources/views/patient-merge/**"
  - "resources/views/settings/patients/**"
  - "resources/js/ktp-*.js"
  - "resources/js/patient-combobox.js"
  - "config/patient_*.php"
  - "tests/Feature/Patient/**"
  - "tests/Feature/PatientMerge/**"
---

# Patient identity, lookup, KTP OCR & duplicate resolution — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here (CG-05 branch isolation, CG-11 KTP/NIK masking and private clinical storage, CG-12 clinical
time, CG-13 never fabricate a patient, CG-20 PostgreSQL verification matter most). Authority: code > canonical
`.cursor/rules/` > this file > archive. On disagreement follow the code, then the canonical rule, and report the drift.

## Invariants

### Global identity, branch-scoped visits

- **AIR-PAT-01** `PATIENT IDENTITY != VISIT BRANCH AUTHORITY`. Patient identity is global across the active
  RME-enabled branches (plus legacy `branch_id` null patients; never MAIN or non-RME branches); the visit branch is the
  operator's authorized daily working context — never the patient's `branch_id`, the code inside the Nomor RM, or a
  request `branch_id`. Selecting a cross-branch patient reuses the master row: it never changes the operator's
  `DailyBranchContext`, rewrites the Nomor RM, moves `branch_id` or creates a duplicate. Source:
  `.cursor/rules/101-new-visit-global-patient-identity-lookup.mdc` §0–4, `184-patient-global-identity-new-patient-only-ktp.mdc` §1–5.
- **AIR-PAT-02** Global is not unrestricted: the lookup needs authentication and the ClinicVisit `create` ability; a
  context-bound operator with no valid working context reads nothing; Doctors are further narrowed by
  `DoctorPatientScopeService`. Never add a browse-all endpoint, blank-query results, a total count or registry
  pagination. Source: `101-…` §2, `100-new-visit-patient-search-combobox.mdc` §3.
- **AIR-PAT-03** Global identity grants no cross-branch clinical history, RME, odontogram, invoices, receivables or
  KTP images. Do not branch-scope patient master data or the lookup without a separate owner approval, and do not
  relax `RmeWorkingBranchScope` to satisfy a lookup requirement. Source: `101-…` §6–7, `184-…` §2–3, §19.

### New Visit patient search

- **AIR-PAT-04** "Kunjungan Baru" selects a patient through exactly one control, `<x-patient-combobox>`, backed by
  `GET rme/visits/patient-search` (`rme.visits.patient-search`). Never preload patients into the view or `<option>`
  elements. The endpoint accepts no `branch_id` and no `limit`. Source: `100-…` §1–3.
- **AIR-PAT-05** The search response carries exactly `id`, `name`, `medical_record_number`, `branch_label` (the test
  asserts the exact key set); `branch_label` is disambiguation only. `PatientSelectorSearchService::MIN_QUERY_LENGTH`
  (2) and `RESULT_LIMIT` (15) are server constants. Source: `100-…` §4–5, `101-…` §2–3.
- **AIR-PAT-06** `patient_id` is set only by selecting a returned result and is cleared by any later keystroke or by
  switching to "Pasien Baru"; `StoreClinicVisitRequest` re-asserts it through `PatientSelectorSearchService::isSelectable()`
  so search selectability equals submit selectability. `Rule::exists` alone is never enough. Source: `100-…` §6, §8,
  `101-…` §5.
- **AIR-PAT-07** Keep the combobox state machine in `resources/js/patient-combobox.js` (unit-tested by
  `tests/js/patient-combobox.test.mjs`): monotonic request sequence plus `AbortController`, re-checked after every
  `await` including `response.json()`, 300 ms debounce. Never move it back inline into `app.js`. Source: `100-…` §7,
  `101-…` §10.
- **AIR-PAT-08** The LIKE escape character is the named constant `PatientRepository::LIKE_ESCAPE` (`!`), read by both
  the `ESCAPE` clause and the escaping helper, and it escapes itself first. Never end a SQL literal on a backslash:
  PDO on PHP 8.3 with `pdo_pgsql` miscounts placeholders (`SQLSTATE[HY093]`), invisible on SQLite. Production PHP is
  the FPM 8.3 pool, not the host CLI. Source: `130-raw-sql-driver-parity-and-gate-selection.mdc` R1–R3, `101-…` §9.
- **AIR-PAT-09** Keep three search states distinct end to end — results, empty results (`200` rendered as "Tidak ada
  pasien yang sesuai."), and a real runtime error (the only case for "Gagal mencari pasien"). Never relax authorization, scope, the ceiling or the
  least-disclosure fields to make a search return rows. Source: `130-…` R5 and closing section.
- **AIR-PAT-10** `CrossBranchPatientLookupService` is duplicate detection: it returns no patient id and must never
  become a selection source. Source: `100-…` §9.

### Nomor RM

- **AIR-PAT-11** Compose and parse the Nomor RM `DG-{KODE_CABANG}-{TAHUN}-{NOMOR}` only through
  `PatientMedicalRecordNumberService`. The RM year and the registration date come from `ClinicalClock`
  (`composeForRegistration()`, `PatientService::resolveRegisteredAt()`, and the `StorePatientRequest`,
  `UpdatePatientRequest`, `StoreClinicVisitRequest` pre-checks); a supplied date is used verbatim. Never renumber an
  existing patient. Source: `110-post-rme-odontogram-stabilization.mdc` §4.
- **AIR-PAT-12** `branchCodeFrom()` reports the canonical code (`DG-TKM1-…` → `TLK1`), `literalBranchCodeFrom()` the
  printed one; `canonicalizeBranchCode()` changes only the branch segment and returns null for a non-canonical number.
  An old-card number is found by widening the search with `equivalentNumbers()`, never by a second patient row or a
  string replace. Source: `92-telkomas-branch-code-canonical.mdc` §5–6, §9.

### Duplicate resolution and merge

- **AIR-PAT-13** A merge reassigns ownership and deletes no clinical, financial, document or legacy history. There is
  no automatic merge (scores only rank candidates for a human), and an unresolved critical identity conflict (name,
  NIK, DOB, gender) blocks it. Source: `172-patient-duplicate-resolution-merge.mdc` §1, §3–5.
- **AIR-PAT-14** A merge is one transaction with locks in this order: legacy slot advisory locks for both archive
  types and both patients (sorted), then patient rows `FOR UPDATE`; blockers and the identity fingerprint are
  re-checked under the locks and conservation is proven over the two patients only (never whole-table totals).
  Source: `172-…` §6, §18.
- **AIR-PAT-15** Every table with a foreign key to `mst_patients` must appear in `PatientOwnedRecordRegistry`
  (REASSIGN or PRESERVE with a reason) or every merge refuses (`OWNERSHIP_REGISTRY_INCOMPLETE`). A non-FK patient
  reference must be added as an explicit blocker, as `SATUSEHAT_IDENTIFIER_PRESENT` was. Never run
  `UPDATE … SET patient_id` outside the registry. Source: `172-…` §12, §16, §19.
- **AIR-PAT-16** Blockers fail closed and are never auto-resolved: `ACTIVE_ENCOUNTER` (registered / waiting /
  in_progress / cashier_pending visit on either patient), an in-flight legacy import or two active legacy archives of
  one type, the legacy date rule on the combined timeline, and identity changed since submission. Source: `172-…`
  §13, §17, §20–21.
- **AIR-PAT-17** Maker-checker lives in the merge services (Super Admin passes policies via `Gate::before`): every
  author is refused approval and rejection, only the requester resolves, submits or withdraws, and a reversal is
  executed by a different reviewer and is automatic only when provably nothing happened since. Source: `172-…` §10–11, §22.
- **AIR-PAT-18** A merged source patient is never deleted and receives no new clinical activity (enforced in services:
  visit registration re-reads the patient `FOR SHARE`, patient edit/activate/delete, legacy intake). The selector
  excludes `merged_into_patient_id IS NOT NULL`; the old Nomor RM resolves through `mst_patient_rm_aliases`, and an
  alias is revoked, never deleted. Source: `172-…` §2, §7, `101-…` (merged patients).
- **AIR-PAT-19** Disclose the minimum: a final NIK held by a third patient fails closed without naming that patient;
  a registration duplicate warning outside the actor's scope shows only name, Nomor RM and branch; a merged-patient
  redirect answers like an unknown patient when the actor cannot open the canonical workspace. Source: `172-…` §9,
  §24–25.
- **AIR-PAT-20** Merge involvement protects a patient from legacy batch rollback
  (`LegacyPatientImportService::rollback()` refuses it). Legacy patient staging is not importing, one ERROR refuses
  the whole batch, blank KTP/NIK is stored as `null` (never a sentinel), and duplicate checks read soft-deleted rows
  too (a rolled-back batch still reserves its NIKs and RMs). Never weaken a duplicate check to unblock an import.
  Source: `172-…` §23, `168-legacy-patient-staged-verification-cancel.mdc` §2, §12–13,
  `169-legacy-patient-rollback-identity-reservation.mdc`.

### KTP camera OCR

- **AIR-PAT-21** OCR output is a suggestion: nothing is written without the operator ticking it and
  `ktp_ocr_verified` accepted; missing or invalid values are null; the NIK only cross-checks (never derive DOB or
  gender from it) and is exactly 16 digits, never padded; disagreement between the whole-card and field-box reads is a
  `conflict` the operator resolves, never by confidence; a retry never overwrites an operator value. Only existing
  `mst_patients` columns are applied. Source: `177-ktp-camera-ocr-suggestions.mdc` §1–5, `179-ktp-field-based-roi-ocr.mdc` §7–9, §12.
- **AIR-PAT-22** OCR runs only in the browser with self-hosted tesseract.js from `public/build/ocr/` — no CDN fallback,
  no external OCR, AI or MCP service. The parse endpoint (`ParseKtpOcrRequest`) stores, logs and audits nothing and
  performs no patient lookup or NIK existence check; OCR text reaches the DOM only via `.value` / `.textContent`.
  Source: `177-…` §6–8, `179-…` §11, §14–16.
- **AIR-PAT-23** Two gates, both required: the flag `patient.ktp_camera_ocr` and `KtpCameraOcrPilotGate`, the only
  reader of `config/patient_ktp_ocr_pilot.php`. The flag alone enables nobody; there is no "everyone" mode; the branch
  is `RmeWorkingBranchScope::activeBranchId()`, exactly one non-MAIN branch; ceilings are committed in the config;
  the device requirement fails safe ON (check `env()`'s boolean before any string cast). Denial is a 404 from the parse
  endpoint. Source: `178-ktp-camera-ocr-supervised-pilot.mdc` §1–8.
- **AIR-PAT-24** Measure pilot posture with `php artisan patient:ktp-ocr-pilot-status --json` (`--user=` for
  eligibility; no arming option). `ARMED` describes configuration, not today's eligibility — it stays `ARMED` after
  `ends_on`. Activation is an environment change in a supervised window, never a deploy. Source: `178-…` §10–11, §16.
- **AIR-PAT-25** D7 consent precedes the camera and OCR on any image source: the wording is canonical in
  `config/patient_ktp_ocr_consent.php` (version `D7-2026-10-10`, no `env()`), read only by `KtpOcrConsent`;
  `ParseKtpOcrRequest` refuses text without accepted consent and the current version (after the pilot gate, so consent
  is never an oracle). Never invent a consent record or borrow `trx_rme_visit_consents`; declining keeps manual
  registration complete. Source: `180-ktp-ocr-consent-d7.mdc` §1–8.
- **AIR-PAT-26** The live overlay starts only after consent and re-checks `liveTrackingAllowed()` every tick; it is a
  separate SVG layer never drawn into the photo; capture geometry comes from the captured pixels; field boxes come
  only from the versioned `KTP_TEMPLATE`; Tailwind classes for indicator states must exist in Blade. Source:
  `181-ktp-live-field-overlay-ocr.mdc` §1–8, §20.

### KTP archive

- **AIR-PAT-27** Capture is not archive: only a `mst_patient_documents` row whose file exists on the private `local`
  disk is archived, written by `KtpScanService::attachTempToPatient()` from the submitting operator's own temp folder,
  verified by sha256, claimed at most once per token, with compensation on failure. Clearing or replacing the preview
  detaches `ktp_scan_token`. Never `Storage::url()` or `temporaryUrl()` a KTP. Source:
  `182-patient-ktp-archive-persistence.mdc` §1–7, §9.
- **AIR-PAT-28** `PatientDocumentAccessService::decide()` is the only KTP access decision: `manage patients` admits,
  then Super Admin or a DIRECT per-account `view_cross_branch_patient_ktp_documents` grant (a role grant is ignored),
  else the working branch must equal the daily-committed branch and the document's `captured_branch_id`. Unknown
  provenance and Perawat are refused; a denial is a 404 identical to a missing document. Source:
  `183-patient-ktp-archive-branch-isolation.mdc` (decision, §1–6, §9).
- **AIR-PAT-29** `captured_branch_id` lives on the document, is written by the server at archive time and is
  write-once; a merge moves `patient_id` only, through a query-builder write that bypasses the model guard, so only the
  regression test protects it. Never hand-edit or re-home it. Document route keys stay `whereNumber`. Source: `183-…`
  §7–8, §11, `184-…` §21, `172-…` (preamble).
- **AIR-PAT-30** KTP photo capture is new-patient registration only (Master Data → Tambah Pasien and Kunjungan → Pasien
  Baru). A missing photo never blocks registration: `ktp_scan_token` stays nullable, no Request `rules()` may make a
  KTP field `required*` or `present`, and no KTP control may be a native `required`. No backfill, campaign, completion
  score or visit blocker for existing, legacy or cross-branch patients. Source: `184-…` §6–16.
- **AIR-PAT-31** Patient merge is branch-scoped through `RmeWorkingBranchScope` (via `PatientMergeScope`): both
  patients of a case must be in the actor's scope for every action, an out-of-scope patient reads as not found, and
  resolving a cross-branch case needs `approve_patient_merge`. Source:
  `.cursor/rules/172-patient-duplicate-resolution-merge.mdc` §8, §14; `PatientMergeCasePolicy`.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- `patient.ktp_camera_ocr` pilot was armed for one SPN4 Front Office operator with the device requirement waived, for
  2026-10-10..2026-10-16; the period has ended, so nobody is eligible while the verdict may still read `ARMED`.
  Disarm or record a fresh owner decision. Real-camera and real-KTP results are PENDING; wider rollout is NO-GO.
- Clinic privacy notice and KTP image retention policy are owner-PENDING; never write them on the clinic's behalf.
- `view_cross_branch_patient_ktp_documents` is granted to no role; Supervisor RME reads no KTP without a direct grant
  (`rbac:patient-ktp-cross-branch-access`).
- Patient master data is unscoped by owner decision (the typed NIK on the edit form is a recorded open finding);
  scoping it needs a new owner decision.
- Merge permissions: `view_patient_duplicate_resolution` and `request_patient_merge` (Admin Klinik, Front Office,
  Supervisor RME), `approve_patient_merge` (Supervisor RME).
- No attach-KTP-to-existing-patient feature is planned.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/100-new-visit-patient-search-combobox.mdc` | One combobox, least disclosure, bounded search |
| `.cursor/rules/101-new-visit-global-patient-identity-lookup.mdc` | Global identity, visit branch from operator |
| `.cursor/rules/130-raw-sql-driver-parity-and-gate-selection.mdc` | LIKE escape, PostgreSQL/PHP 8.3 parity, result states |
| `.cursor/rules/172-patient-duplicate-resolution-merge.mdc` | Merge atomicity, blockers, maker-checker, aliases |
| `.cursor/rules/177-ktp-camera-ocr-suggestions.mdc` | OCR is a suggestion, self-hosted, parse endpoint |
| `.cursor/rules/178-ktp-camera-ocr-supervised-pilot.mdc` | Pilot gate, cohort, status command |
| `.cursor/rules/179-ktp-field-based-roi-ocr.mdc` | Hybrid field-box read, conflicts, template version |
| `.cursor/rules/180-ktp-ocr-consent-d7.mdc` | D7 consent before capture and parsing |
| `.cursor/rules/181-ktp-live-field-overlay-ocr.mdc` | Live overlay geometry and consent |
| `.cursor/rules/182-patient-ktp-archive-persistence.mdc` | Temp-to-archive lifecycle, private storage |
| `.cursor/rules/183-patient-ktp-archive-branch-isolation.mdc` | KTP access decision, capture-branch provenance |
| `.cursor/rules/184-patient-global-identity-new-patient-only-ktp.mdc` | Global identity, new-patient-only KTP capture |
| `.cursor/rules/110-post-rme-odontogram-stabilization.mdc` | Clinical calendar owns RM year |
| `.cursor/rules/92-telkomas-branch-code-canonical.mdc` | RM branch-code aliases and canonicalization |
| `.cursor/rules/168-legacy-patient-staged-verification-cancel.mdc` | Legacy patient CSV staging and commit |
| `.cursor/rules/169-legacy-patient-rollback-identity-reservation.mdc` | Rolled-back batches still reserve NIK and RM |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/04-patient-identity.md`. Grep by sprint id; never read a whole
file.
