# AUDIT-PATIENT-KTP-ARCHIVE-PERSISTENCE-1

**Question:** after a successful registration, is a KTP photo captured through the
camera/OCR workflow permanently archived as the patient's private document?

**Answer:** yes in the normal path, proven on production; but the audit found a
**wrong-patient regression** in the browser and several silent failure paths on
the server. Classification **C — correctable persistence defect**, closed by a
corrective release. Durable rule: `.cursor/rules/182-patient-ktp-archive-persistence.mdc`.

Base authority: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
@ `a97d0c64` (one docs-only commit after production `6c4f3608` =
`revision-patient-ktp-live-field-overlay-ocr-1-go`, verified exact on the VPS).

## 1. Lifecycle as built

| Stage | Where | Verified |
|---|---|---|
| Capture / scan / file, operator confirms | `resources/js/ktp-camera-ocr.js` | code + JS tests |
| Compress + upload to private temp, token returned | `POST settings/patients/ktp-scan/upload-temp` → `KtpScanService::storeTempFromBase64()` (`tmp/patient-ktp-scans/{user}/{token}.{ext}` + `.json` meta) | code + feature tests |
| Token carried in hidden `ktp_scan_token` | `_ktp-scan.blade.php` (shared by both forms) | code + JS tests |
| Registration | `PatientController::store` (Master Data) and `ClinicVisitController::store` new-patient mode (Kunjungan) — **same** `attachTempToPatient()` | feature tests, both surfaces |
| Promotion to permanent | `patient-documents/{patient}/ktp-*.{ext}` on private `local` disk + `mst_patient_documents` row | feature tests + production audit |
| Retrieval | patient edit page "Lihat KTP" → `settings.patients.documents.show` | feature tests (fresh session, other user) |
| Temp cleanup | `daengtisiams-ktp-temp-prune.timer` → `patient-documents:prune-temp --force` daily as `daengtisiams` | production `systemctl` + journal |

Schema (`2026_06_27_100001`): `patient_id` FK → `mst_patients` (cascade), `document_type`,
`file_path`, `original_filename`, `mime_type`, `file_size`, `compressed_file_size`,
`checksum` (sha256), `uploaded_by` → `users` (null on delete), timestamps, soft
deletes; indexes on patient, type, uploader, (patient, type). **No branch column**
— branch follows the patient. No uniqueness on (patient, type): a patient may hold
several KTP rows; the edit page shows the latest.

## 2. Findings

| # | Finding | Severity | Introduced | Status |
|---|---|---|---|---|
| F1 | **Wrong-patient attachment.** "Hapus Preview", a retake, a new scan or a newly chosen/captured photo left the PREVIOUS token in the form while the preview showed nothing or a different image. Saving then archived the earlier photo — possibly a different KTP holder — under the patient being registered. Before `aa627cdb` (REVISION-REGISTRATION-KTP-CAMERA-OCR-1, 2026-10-09) clearing set `tokenEl.value = ''`; the rewrite dropped it and documented the token as "staying referenced". | HIGH (privacy / wrong patient) | camera OCR revision | **Fixed** |
| F2 | **Silent drop.** A submitted token that could not be attached (expired, pruned, another operator's, failed) produced "Pasien berhasil dibuat." with no KTP — the operator believed it was archived. | MEDIUM | Sprint 61.1 | **Fixed** (warning on both surfaces) |
| F3 | **Empty archive.** With `throw => false` an unreadable temp read returned `''`, which was written and recorded as a zero-byte KTP document. | MEDIUM | Sprint 61.1 | **Fixed** |
| F4 | **Record without file.** A failed archive write returned `false` and the row was still inserted, referencing a missing file; the temp (the only copy) was then deleted. A directory that cannot be created threw instead — a **500 after the patient was already saved**. | MEDIUM | Sprint 61.1 | **Fixed** |
| F5 | **File without record.** A failed row insert propagated as a 500 and left an orphan archive file (no compensation; a transaction cannot roll back a file). | MEDIUM | Sprint 61.1 | **Fixed** |
| F6 | **Identity unverified.** Nothing tied the archived bytes to the bytes the operator confirmed. | LOW | Sprint 61.1 | **Fixed** (sha256 in temp meta, checked on attach; archive read back) |
| F7 | Document stream sent `Cache-Control: no-cache, private` (cacheable on a shared front-desk browser) and no `nosniff`. | LOW | Sprint 61.1 | **Fixed** (`private, no-store` + `nosniff`) |
| F8 | **Late scanner upload re-attached a cleared photo.** The scanner path never set `busy`, so "Hapus Preview" during its upload succeeded and the upload then re-filled the token (and re-ran OCR for a photo no longer on screen). Found by the adversarial review of the first fix. | MEDIUM | camera OCR revision (pre-existing) | **Fixed** (scanner holds `busy`; binding refuses a stale result via a generation counter) |
| F9 | **Photo on screen, nothing archived, no warning.** Saving with an unconfirmed photo, a failed upload or an upload in flight archived nothing and the server could not warn about a token it never received. | MEDIUM | pre-existing, partly by F1's fix | **Fixed** (form refuses to save until the photo is stored or cleared; hidden section exempt) |
| F10 | **Concurrent double submit** could archive one token twice (both requests passed the meta checks before either deleted it). | LOW | Sprint 61.1 | **Fixed** (atomic claim by rename; restored on every failure) |
| G1 | KTP retrieval is gated by `manage patients`, which is **not branch-scoped** (patient master data is global by an existing product decision; the edit page already shows the full KTP number to the same roles). | — | design | **Open owner decision**, not changed |
| G2 | No way to attach/replace a KTP on an existing patient (edit page: view/delete only). | — | design | **Next sprint**, not built |

Defects F1–F5 were reproduced against the unfixed code before any fix: 3 JS
wiring tests failed on their assertions, and 9 of 13 PHP tests failed (missing
warning on every silent-drop path, a 500 on insert failure, `no-cache, private`).
F8–F10 came from an independent adversarial review of the first fix commit and
were reproduced against that commit: 4 new JS tests failed there, and the
mid-archive race test archived the same photo twice.

## 3. Corrective change

- `resources/js/ktp-camera-ocr.js` — `createKtpTokenBinding()`; every cleared or
  replaced preview detaches the token and remembers it as superseded so the next
  upload still discards it server-side; a token carried over after a validation
  error can be withdrawn with "Hapus Preview"; clearing says the photo will not be
  attached.
- `KtpScanService::attachTempToPatient()` — own-folder path check on the meta;
  non-empty temp; checksum match; archive written then read back and compared
  (catching both `false` and the directory exception); row inserted in a nested
  transaction with the archive file deleted again on failure; temp released only
  after both exist; `Log::warning('patient_ktp_document_not_attached')` with
  reason + ids only. `storeTempFromBase64()` records the checksum.
- `PatientController::store`, `ClinicVisitController::store` — flash
  `KtpScanService::NOT_ATTACHED_WARNING` when a submitted token was not archived.
- `PatientDocumentController::show` — `Cache-Control: private, no-store`,
  `X-Content-Type-Options: nosniff`.
- Review follow-ups: the scanner upload holds `busy`; the binding refuses an
  upload that resolves after its photo was detached; the form refuses to save
  while a photo is on screen that will not be archived; the attach claims the
  photo by an atomic rename of its meta and restores it on every failure.

No migration, no permission, no route, no pilot/env change. D7 consent, the pilot
gate, OCR behaviour and the scanner/manual fallbacks are unchanged.

## 4. Failure modes

| # | Mode | Result | Evidence |
|---|---|---|---|
| 1 | Registration succeeds but KTP missing | PASS — now warned | feature test (expired token, both surfaces) |
| 2 | KTP uploaded, registration fails (validation/duplicate) | PASS | token re-rendered via `old()`; nothing archived until a save succeeds |
| 3 | Validation error after capture | PASS | carried-token notice; withdrawable (JS test) |
| 4 | Browser refresh after upload | PASS (by design) | token lost with the page; temp pruned ≤ 24h; nothing archived |
| 5 | Session expiry before submit | NOT TESTED | 419 loses input; temp pruned ≤ 24h |
| 6 | Network disconnect during upload | PASS | upload error keeps no token attached and the save is refused until the photo is stored or cleared (JS tests) |
| 7 | Duplicate patient rejection | PASS | existing `still blocks a duplicate NIK` + token carried |
| 8 | Multiple registration clicks | PASS | sequential: second save warned, 1 document; concurrent: atomic claim (mid-archive race test) |
| 9 | OCR retry duplicates files | PASS | OCR writes nothing (rule 177); only submit archives |
| 10 | Retake during upload | PASS | `blockedWhileBusy`; retake archives the retake, discards the first |
| 11 | Temp pruned too early | PASS | prune 24h ≫ token use; existing "keeps a live registration scan" test |
| 12 | DB insert fails after file move | PASS — compensated | feature test |
| 13 | File move fails after DB insert | PASS — row never written first | feature test (write failure) |
| 14 | Record references missing file | PASS | read-back verification; production `missing_files_count=0` |
| 15 | Wrong patient receives document | PASS — fixed F1 | JS tests + cross-user feature test |
| 16 | Cross-branch access | SEE G1 | authorized by `manage patients` by design |
| 17 | Retrieval after a later visit | PASS | archive independent of session/visit (fresh-session read test) |
| 18 | File replaced during another request | PASS | checksum mismatch refused |

## 5. Production read-only evidence (2026-10-11, aggregates only)

| Metric | Value |
|---|---|
| Patients (live) | 1,522 — 1,519 legacy import, 3 registered |
| Registered since the SPN4 camera pilot | 1 — **with** a KTP document |
| KTP documents | 1 (camera/manual source, Front Office, 2026-10-11 06:11 WITA, same second as the patient) |
| Missing files / orphan files / checksum, mime, size mismatches | 0 / 0 / 0, 0, 0 |
| Duplicate KTP rows per patient / on trashed or missing patients | 0 / 0 |
| Stale temp files | 0 |
| Prune timer | enabled + active; last run 2026-10-11 03:47 WITA, success, 0 deleted |

Source: `php artisan patient-documents:audit --json` and SELECT-only SQL in a
read-only transaction. No path, name, KTP number or image was read or printed.

## 6. Tests

New: `tests/Feature/RME/PatientKtpArchivePersistenceTest.php` (15) and
`tests/js/ktp-camera-ocr-token.test.mjs` (13, driving the real `initKtpScan()`
through a fake DOM). One existing source-shape assertion
(`tests/js/ktp-ocr-consent.test.mjs`, "Hapus" handler) was narrowed to the
property it protects (plain `clearAll()` first, consent reset) because the handler
now also sets a status line. Mutation: 18/18 killed (worktree diff identical
before and after). Regression: 491 PHP tests (KTP, patient, visit, merge, CI
suite-coverage) 0 failed; JS 212/212.

Residual LOWs (recorded in rule 182 "Known gaps"): the warning toast
auto-dismisses after 4 s; a photo taken in the visit form's new-patient panel is
dropped silently when the operator switches to an existing patient; temps
uploaded before this release carry no checksum; nothing fsyncs.

## 7. Release evidence (2026-10-11)

| Gate | Evidence |
|---|---|
| Candidate CI | PR #473, candidate `66ed17dd`, run `38094500097` success: quality gate JS 212/212; critical gate 5,440 passed / 27,987 assertions, exit 0 (PostgreSQL 16), `PatientKtpArchivePersistenceTest` selected |
| Merge | squash `bbf200d0`, tree `4af46d7d` == candidate tree, parent `a97d0c64` |
| Post-merge CI | run `38100148950` success on `bbf200d0`: critical 5,440 passed, exit 0 |
| Full Suite | **SKIPPED** — `full_suite_authorized=false`, `TEMPORARY_FULL_SUITE_POLICY_ACTIVE`; job never executed. Not a pass. |
| Deploy | `scripts/deploy-vps-runner.sh start` executed ON the VPS: `exit=0`, `DEPLOY OK: 20261011-005705`, `DEPLOY_HEAD_TARGET_MATCH=YES (bbf200d0)`, snapshot cleaned, runtime isolation 70 GO / 0 FAIL, `Nothing to migrate.` |
| Backup | `pre_auto_deploy_20261011-005705.sql`, 32,168,303 bytes, mode 640 `daengtisiams`, NSF-10 backup-verify 9/9 GO |
| Deploy smoke | 6/6 command smoke GO; the `http://127.0.0.1/login` probe WATCH is the known co-tenant loopback shadow (verified over the domain instead) |
| Production | HEAD/tree `bbf200d0`/`4af46d7d`, clean tracked tree; `https://daengtisia.online` `/login` `/health/live` `/health/ready` `/health/lb` 200; `/storage/...` 403; guest document route 302 → login; manifest `app-a1MlZPYA.js` served 200 and contains the clear message, the submit guard and the generation guard; php8.3-fpm / nginx / postgresql / queue worker active; 0 failed jobs; debug OFF, maintenance OFF; `laravel.log` byte-identical before and after (1,437,201 bytes, 162 ERROR) — 0 new errors |
| Document store after deploy | 1 record, 1 file, 0 missing / orphan / checksum / duplicate |
| Pilot | unchanged: ARMED, operator 29, SPN4, 2026-10-10..16, consent `D7-2026-10-10` usable |
| GO tag | `audit-patient-ktp-archive-persistence-1-go`, tag object `fa61fed0`, target `bbf200d0` (exact match at VPS HEAD) |

**Abandoned temp captures (observed, not touched).** One operator folder holds
four unattached temp captures from the pilot day (2026-10-10 00:00, 00:04, 12:15,
21:51 UTC). The audit's `stale_temp_files_count` moved from 0 to 2 during this
session only because the two oldest crossed the 24h line. They are within design
retention; `daengtisiams-ktp-temp-prune.timer` deletes each on the first daily run
after it is 24h old. No production file was deleted by hand and no image was
opened.

**Not exercised on production:** an authenticated KTP retrieval (needs an
operator session; proven by the feature suite, including a read from a fresh
session by a different authorized user) and the browser flows on a real device
(proven by the DOM-level JS suite).
