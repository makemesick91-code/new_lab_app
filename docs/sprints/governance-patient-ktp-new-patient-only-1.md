# GOVERNANCE-PATIENT-KTP-NEW-PATIENT-ONLY-1 — Patient identity is global; KTP photo capture is new-patient-only

**Status:** classification **B** — runtime already compliant; governance documentation gap closed.
**Date:** 2026-10-11 (WITA, UTC+08:00)
**Branch:** `governance/patient-ktp-new-patient-only-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `3fb2bdd7`
**Production runtime (unchanged by this sprint):** `4034557e` (tree `2a413e76`), tag `security-patient-ktp-archive-branch-isolation-1-go`
**Durable rule:** `.cursor/rules/184-patient-global-identity-new-patient-only-ktp.mdc`
**Regression suite:** `tests/Feature/RME/PatientKtpNewPatientOnlyGovernanceTest.php`

## 1. Owner product decisions (binding)

1. **Master Data Pasien is global.** It is intentionally not branch-scoped; global patient visibility is a clinical requirement, not a vulnerability. Role and permission checks still apply.
2. **One identity across branches.** A patient registered at branch A who attends branch B is reused, never duplicated.
3. **KTP photo capture is for new patient registration.** Camera, field-based ROI OCR, live overlay OCR, scanner agent and manual file upload are preserved exactly as authorized today. OCR stays optional and pilot-gated. Typed manual registration is complete.
4. **Existing, repeat-visit, legacy-imported and cross-branch patients are never asked for a KTP photo.** No backfill, no upload campaign, no completion popup or score, no visit blocker. A missing archive never means an invalid identity or an incomplete record.
5. **Existing archives are preserved,** and the KTP **image** stays branch-isolated (rule 183): global patient visibility is not global image access.
6. **D7 consent** applies only where camera/OCR processing is actually used; declining keeps the manual path; consent is never fabricated or inferred for legacy patients.

## 2. Authoritative baseline (verified, not assumed)

| Fact | Value |
|---|---|
| Canonical integration branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (not `main`, which is at a June commit) |
| Base tip | `3fb2bdd7` = production + 2 docs files (`CLAUDE.md`, the previous sprint doc) |
| Production HEAD / tree | `4034557e` / `2a413e76`, `git describe --exact-match` → `security-patient-ktp-archive-branch-isolation-1-go` |
| Production working tree | clean except the pre-existing, previously audited `storage/runtime-home/` (not task-owned, left in place) |
| Deploy lock | free (`flock -n` succeeded) |

## 3. Read-only audit — what the code actually does

| Workflow | Server-side fact | UI fact |
|---|---|---|
| A. Master Data → Tambah Pasien | `StorePatientRequest`: `ktp_scan_token` and `ktp_number` `nullable`; attestation required only when OCR was applied | `_ktp-scan` partial included |
| B. Kunjungan → Pasien Baru | `StoreClinicVisitRequest`: same; attestation excluded unless the patient is new | partial included inside `data-mode-panel="new"` only |
| C. Kunjungan → Pilih Pasien Lama | `ClinicVisitController::store` attaches a KTP **only** when `$isNewPatient` | existing panel holds the patient combobox only |
| D. Follow-up / repeat visit | no KTP rule of any kind | — |
| E. Legacy patient visit | provenance `import_batch_id`; no KTP rule | — |
| F. Cross-branch lookup | global RME-enabled registry (rule 101) | — |
| G. PatientMerge | re-points `mst_patient_documents.patient_id` with a query-builder `update()` (writes nothing else) | — |
| H. Archive persistence | `KtpScanService::attachTempToPatient()` from both new-patient surfaces only | — |
| I. Archive retrieval | `PatientDocumentAccessService::decide()`; refusal = 404 | edit page shows the stored card only via `@can` |
| J. Camera OCR pilot gate | `KtpCameraOcrPilotGate::allows()` decides camera + parse endpoint | camera hidden when denied |
| K. D7 consent | `ParseKtpOcrRequest` refuses OCR text without the current consent version | consent panel precedes the camera |

The client save guard returns early for a hidden KTP section (`root.offsetParent === null`), so a stray new-panel photo can never hold an existing-patient visit — already pinned by `tests/js/ktp-camera-ocr-token.test.mjs` ("a hidden KTP section never blocks the save"). No `patient_type` column exists or is needed: classification comes from workflow context (`patient_mode`) and import provenance, never from whether an image exists.

### Production evidence (read-only transaction, aggregates only, 2026-10-11)

| Metric | Value |
|---|---|
| Patients (live) | 1,522 — 1,519 legacy-imported, 3 native, 0 merged |
| Live KTP documents | 1 (capture branch recorded) |
| Legacy patients with a KTP image | 0 |
| Visits | 7 — **6 for patients with no KTP archive**, 1 for a legacy patient |
| Patients with 2+ visits | 2 |
| Patients with visits at more than one branch | 0 (cross-branch reuse is therefore proven by tests, not by production data) |
| KTP temp-prune timer | enabled, active, last run `success` 2026-10-10 19:47 UTC |

The policy is already how production behaves: almost every visit to date was for a patient who has no KTP image.

## 4. Classification

**B — governance documentation gap.** No runtime defect was found. The gaps were: no durable rule recording the owner decisions; rule 182 and the CLAUDE.md archive record described "attach a KTP to an existing patient" as pending work ("next sprint"), which reads as a missing requirement; and several owner rules had no regression test (legacy visit, edit page offers no capture, cross-branch reuse with the archive kept and refused at another branch, merge keeping provenance, completeness ignoring the archive).

## 5. Governance compliance matrix

| # | Case | Expected | Actual | Result | Evidence |
|---|---|---|---|---|---|
| 1 | New patient, camera OCR | available only to the pilot, after D7 | pilot-gated; consent before camera and parse | PASS | `PatientKtpCameraOcrPilotTest`, `PatientKtpOcrConsentTest`, `PatientKtpCameraOcrTest` › shows camera capture, OCR and manual fallback when enabled |
| 2 | New patient, scanner | available | section on both new-patient surfaces | PASS | `PatientKtpScanTest` › shows the scanner-ready KTP section; `RmeVisitNewPatientKtpScanTest` › shows the KTP scan section on the RME visit create page |
| 3 | New patient, manual upload | available | same `upload-temp` endpoint as the scanner; fallback shown with OCR on and off | PASS | `PatientKtpScanTest` › stores an uploaded KTP scan on the private disk; `PatientKtpCameraOcrTest` › keeps only the existing scanner + manual flow when switched off |
| 4 | New patient, manual entry only | registers | registers with no token | PASS | `PatientKtpScanTest` › still creates a patient with no KTP scan token; `RmeVisitNewPatientKtpScanTest` › creates a new patient with no KTP scan token |
| 5 | New patient declines D7 | manual path complete | 0 OCR requests, registration completes | PASS | `PatientKtpOcrConsentTest` › keeps manual registration complete for a KTP holder who declines OCR |
| 6 | Existing patient, no archive | visit registers, no prompt | repeat visits register, no warning, 0 documents | PASS | new suite › registers repeat visits for an existing patient who has no KTP archive |
| 7 | Existing patient, with archive | archive preserved | untouched through a cross-branch visit and a merge | PASS | new suite › reuses the global patient…; keeps the capture branch… |
| 8 | Legacy patient, no archive | visit registers, record untouched | registers; NIK/RM/batch/branch unchanged; 0 documents | PASS | new suite › registers a visit for a legacy imported patient… |
| 9 | Existing patient at a new branch | reused, visit at operator branch, no recapture | same identity, no duplicate, visit at branch B, still 1 document | PASS | new suite › reuses the global patient at another branch…; `NewVisitGlobalPatientLookupTest` › creates the visit at the operator branch… |
| 10 | Global search | finds any RME branch's patient | yes | PASS | `NewVisitGlobalPatientLookupTest` (finds patients of every RME branch…) |
| 11 | PatientMerge | canonical identity kept, documents move | document moves, `captured_branch_id` and file path unchanged | PASS | new suite › keeps the capture branch of a KTP archive when a merge re-points it; `PatientMergeWorkflowTest` |
| 12 | Cross-branch KTP retrieval | 404, audited `branch_mismatch` | 404, audited, no NIK in audit; capture branch reads 200 | PASS | new suite (with control); `PatientKtpArchiveBranchIsolationTest` › refuses another branch operator… |
| 13 | Super Admin privileged retrieval | allowed | allowed | PASS | `PatientKtpArchiveBranchIsolationTest` › lets super admin read across branches… |
| 14 | Supervisor RME without grant | refused | refused | PASS | `PatientKtpArchiveBranchIsolationTest` › does not give supervisor rme the card because of the role name |
| 15 | Existing document retention | never deleted/rewritten | untouched by visit and merge | PASS | new suite (cases 7, 9, 11) |
| 16 | Temporary cleanup | stale temps pruned, finals never | dry-run deletes nothing; force deletes stale only | PASS | `PatientDocumentStorageGovernanceTest` › prune-temp…; production timer enabled + last run success |
| 17 | Registration validation failure | photo survives, withdrawable | kept across a failed submit | PASS | `PatientKtpArchivePersistenceTest` › keeps a photo across a failed submit, withdrawable before saving |
| 18 | Duplicate-patient rejection | duplicate NIK refused | exact message on both flows | PASS | `RmePatientFormatConsentTest` › rejects duplicate ktp through rme visit new patient flow… |
| 19 | Repeated Save submission | archived at most once | at most once, even mid-archive | PASS | `PatientKtpArchivePersistenceTest` › archives a photo at most once…; …even when a second request arrives mid-archive |
| 20 | Camera retake / preview clearing | form names the photo on screen | retake archived, cleared photo detached | PASS | `PatientKtpArchivePersistenceTest` › archives the retake…; `tests/js/ktp-camera-ocr-token.test.mjs` |

## 6. Changes

- **Added** `tests/Feature/RME/PatientKtpNewPatientOnlyGovernanceTest.php` — 10 tests, 55 assertions, fictional data and a synthetic 10×10 PNG only.
- **Declared** it in `config/ci_runner.php` `critical_gate_mandatory_suites` (read only by CI governance, not by any request path).
- **Added** rule 184; **cross-referenced** it from rules 101, 172, 180, 182 (its "known gap" reframed, not deleted) and 183.
- **CLAUDE.md**: the "(next sprint)" archive note annotated as CLARIFIED; this sprint's record appended.
- **No** change to `app/`, `routes/`, `resources/`, `database/`, `bootstrap/` or `scripts/`.

## 7. Verification

- New suite 10/10 under the CI environment (empty environment file + CI's public test key).
- **Mutation 9/9 KILLED, 0 not-applied**, each reverted by file copy and verified by SHA-256: capture added to the edit page; `required` token on the visit request; on the Master Data request; attestation demanded of an existing patient; capture moved into the existing-patient panel; visit refused for a patient without an archive; cross-branch read allowed; merge dropping `captured_branch_id`; completeness scoring the archive.
- The critical-gate filter selects all 10 tests (`pest --list-tests`); `CriticalGateSuiteCoverageTest` 14/14.

## 8. Findings recorded, deliberately not changed

1. **Merge bypasses the write-once guard.** `PatientMergeOwnershipRepository` re-points rows with `DB::table(...)->update([...])`, which skips the `PatientDocument` model's `updating` guard. It writes only `patient_id`, so provenance survives today; rule 183 already lists the bypass, and the new merge test is now the protection. A database-level guard would be a separate change.
2. **Typed NIK number in the Sprint 61.0 audit.** `PatientDataCompletenessService` scores `ktp_number` (the typed identity number) as an *optional* field; a patient without it shows as "incomplete" on that read-only audit page. That is the number, not the KTP image; it blocks nothing, and it is not touched here. Whether it should count is an owner question.
3. **Privacy notice and retention** remain owner-PENDING (rule 180). Nothing was written on the clinic's behalf.
4. Rule 182's other residual LOWs (auto-dismissing warning toast, silent drop of a new-panel photo after switching to existing mode, pre-release temps without checksum) are unchanged.

## 9. Deployment decision

`DEPLOYMENT_REQUIRED = NO`. Nothing that production executes changed. The GO tag for this sprint names the merge commit and is an evidence/governance tag; production keeps running `4034557e`, and this sprint does not claim otherwise.
