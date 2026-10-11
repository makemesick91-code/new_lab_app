# GOVERNANCE-PATIENT-KTP-NEW-PATIENT-ONLY-1 — Patient identity is global; KTP photo capture is new-patient-only

**Status:** classification **C** — server side already compliant; ONE client-side violation found by independent review and fixed minimally; governance gap closed.
**Date:** 2026-10-11 (WITA, UTC+08:00)
**Branch:** `governance/patient-ktp-new-patient-only-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `3fb2bdd7`
**Production runtime before this sprint:** `4034557e` (tree `2a413e76`), tag `security-patient-ktp-archive-branch-isolation-1-go`
**Durable rule:** `.cursor/rules/184-patient-global-identity-new-patient-only-ktp.mdc`
**Regression suites:** `tests/Feature/RME/PatientKtpNewPatientOnlyGovernanceTest.php`, `tests/js/ktp-camera-ocr-token.test.mjs` (attestation cases)

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

The client save guard returns early for a hidden KTP section (`root.offsetParent === null`), so a stray new-panel **photo** can never hold an existing-patient visit — pinned by `tests/js/ktp-camera-ocr-token.test.mjs` ("a hidden KTP section never blocks the save"). **That guard did not cover the OCR attestation, see §4.** No `patient_type` column exists or is needed: classification comes from workflow context (`patient_mode`) and import provenance, never from whether an image exists.

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

**C — minor runtime inconsistency (client side), plus a governance documentation gap.**

My first reading was **B** (no runtime defect). The independent adversarial review refuted it, and the finding was verified against the source before acting:

- `resources/js/ktp-camera-ocr.js` set `required = true` on the `ktp_ocr_verified` box when OCR values were applied, and nothing ever cleared it; after a failed submit, Blade re-rendered it with `@required` from `old('ktp_ocr_applied')`.
- The box lives in the visit form's `data-mode-panel="new"`. Switching to **Pasien Terdaftar** only adds `hidden`; the form has no `novalidate`.
- Browsers apply constraint validation to `display:none` controls (they are not barred), and refuse the submit with a console-only "An invalid form control … is not focusable". The existing JS submit guard never runs.

**Reachable path:** an OCR operator (today: the armed pilot, user 29 @ SPN4) starts a new registration, scans and applies the KTP, the duplicate check shows the patient already exists, they switch to *Pasien Terdaftar* and press Save — and nothing happens. That violates owner rule 13 (a repeat visit is never held up by KTP state) and contradicted the existing test's claim that "a hidden KTP section never blocks the save". The server was never at fault (`StoreClinicVisitRequest` excludes the attestation unless the patient is new), which is why HTTP tests could not see it.

**Minimal fix:** the attestation moved from a native `required` into the existing submit guard in `ktp-camera-ocr.js`, which already skips a hidden section; the Blade `required` attribute was dropped. For a visible section the operator is still stopped (same message as the server, focus moved to the box); the server's `accepted` rule for a new patient is unchanged and authoritative.

**Governance gaps also closed:** no durable rule recorded the owner decisions; rule 182 and the CLAUDE.md archive record described "attach a KTP to an existing patient" as pending work ("next sprint"); several owner rules had no regression test.

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
| 21 | OCR applied in the new panel, then *Pasien Terdaftar* | existing-patient visit saves | **FAIL before this sprint** (silent browser refusal); saves after the fix | PASS (after fix) | `ktp-camera-ocr-token.test.mjs` › applied but unattested OCR never blocks the save of a hidden KTP section; › the attestation is never a native required constraint; new suite › never renders the OCR attestation as a native required control… |

## 6. Changes

- **Fixed** `resources/js/ktp-camera-ocr.js` — the OCR attestation is enforced by the submit guard (skips a hidden section) instead of a native `required`.
- **Fixed** `resources/views/settings/patients/_ktp-scan.blade.php` — the attestation box is never rendered `required`.
- **Added** `tests/Feature/RME/PatientKtpNewPatientOnlyGovernanceTest.php` — 11 tests, 63 assertions, fictional data and a synthetic 10×10 PNG only.
- **Extended** `tests/js/ktp-camera-ocr-token.test.mjs` — 6 attestation tests driving the real `initKtpScan()`.
- **Declared** it in `config/ci_runner.php` `critical_gate_mandatory_suites` (read only by CI governance, not by any request path).
- **Added** rule 184; **cross-referenced** it from rules 101, 172, 180, 182 (its "known gap" reframed, not deleted) and 183.
- **CLAUDE.md**: the "(next sprint)" archive note annotated as CLARIFIED; this sprint's record appended.
- **No** change to `app/`, `routes/`, `database/`, `bootstrap/` or `scripts/`; no migration, permission or policy.

## 7. Verification

- New PHP suite 11/11 (63 assertions) under the CI environment (empty environment file + CI's public test key); JS suite 218/218; `npm run build` passes.
- **Mutation 17/17 KILLED, 0 not-applied**, each reverted by file copy and verified by SHA-256: capture added to the edit page; `required` token on the visit request; on the Master Data request; attestation demanded of an existing patient; capture moved into the existing-patient panel; capture moved **outside both** panels; visit refused for a patient without an archive; cross-branch read allowed; merge dropping `captured_branch_id`; completeness scoring the archive; Blade re-rendering the attestation `required`; JS setting native `required`; JS guard ignoring an unattested OCR; JS attestation evaluated before the hidden exemption; the guard focusing an attestation box that stays hidden; `Rule::requiredIf` on the Master Data token; pipe-string `required` on the patient-update NIK.
- **Honest limit:** node cannot emulate browser constraint validation, so the behavioural "hidden section" JS test passes on the old code too. The guard against *this* defect class is the comment-stripped source assertion (no native `required` in the module) plus the Blade render assertion — both proven by mutants M10/M11.
- The visit-page test decides by the real element tree (`DOMDocument` ancestry: the capture root's only `data-mode-panel` ancestor is `new`), not string positions.
- The critical-gate filter selects the suite (`pest --list-tests`); `CriticalGateSuiteCoverageTest` 14/14.

## 8. Findings recorded, deliberately not changed

1. **Merge bypasses the write-once guard.** `PatientMergeOwnershipRepository` re-points rows with `DB::table(...)->update([...])`, which skips the `PatientDocument` model's `updating` guard. It writes only `patient_id`, so provenance survives today; rule 183 already lists the bypass, and the new merge test is now the protection. A database-level guard would be a separate change.
2. **Typed NIK number in the Sprint 61.0 audit.** `PatientDataCompletenessService` scores `ktp_number` (the typed identity number) as an *optional* field; a patient without it shows as "incomplete" on that read-only audit page. That is the number, not the KTP image; it blocks nothing, and it is not touched here. Whether it should count is an owner question.
3. **Privacy notice and retention** remain owner-PENDING (rule 180). Nothing was written on the clinic's behalf.
4. Rule 182's other residual LOWs (auto-dismissing warning toast, silent drop of a new-panel photo after switching to existing mode, pre-release temps without checksum) are unchanged.
5. **Second independent review** (of the fix commit only) could not refute it — no CRITICAL/HIGH/MEDIUM, no new path that saves a new patient unattested, no other native `required` on a KTP surface. Its one actionable LOW was a regression introduced by the fix itself: a back/reload form-state restore can bring the hidden "applied" flag back while the box re-renders hidden, and the guard then focused an invisible box. Fixed (the guard shows the box before focusing it; mutant M17 killed). Accepted LOWs: without JavaScript the attestation is checked one round trip later by the server (OCR cannot be applied without JS anyway); `ktp_ocr_applied` is client-controlled — pre-existing self-attestation, not a security boundary.
6. Independent-review LOWs addressed in this sprint: the rule scan now reads every Request recursively, each KTP-keyed entry to its top-level comma (pipe strings, `Rule::requiredIf`, double quotes), with the two capture endpoints named and pinned as the only exclusions; the scan cannot see `withValidator()`/`Validator::make()` and rule 184 says so; the edit-page test now also refuses prompt text; rule 184 #2 is scoped to identity lookup/visibility and #15 to the KTP photo/archive.

## 9. Deployment decision

`DEPLOYMENT_REQUIRED = YES`. One JS module and one Blade partial changed (asset rebuild); no migration, no seeder, no permission. Deployed with `scripts/deploy-vps-runner.sh` **on the VPS**; the GO tag targets the verified production runtime commit after deployment.

## 10. Shipped, deployed and verified (2026-10-11 WITA)

| Item | Evidence |
|---|---|
| PR | #477, squash-merged as `042078f7` (tree `137cc0f2` == CI-tested candidate `6877c637`, parent `3fb2bdd7`) |
| PR CI | run `38132468556` on `6877c637` — every required gate success; critical **5,505 passed / 0 failed** (28,272 assertions) incl. the new suite; JS **218/218** in the quality gate; classifier `unknown_high_risk` |
| Post-merge CI | push run `38138907490` on `042078f7` — success; critical 5,505 passed / 0 failed |
| Full Suite | **SKIPPED** (PR: `FULL_SUITE_NOT_ENABLED_FOR_EVENT`; push: `TEMPORARY_FULL_SUITE_POLICY_ACTIVE`) — not a pass |
| Deploy | `scripts/deploy-vps-runner.sh` **on the VPS**: `exit=0`, `DEPLOY OK: 20261011-122128`, `DEPLOY_HEAD_TARGET_MATCH=YES`, target pinned, snapshot trusted and cleaned; nothing to migrate; assets rebuilt (`app-BfCKqrV8.js`, 121.62 kB) |
| Backup | `pre_auto_deploy_20261011-122128.sql` (32,193,790 bytes, `0640 daengtisiams`, valid pg_dump header), backup-verify GO |
| Runtime diff vs previous production `4034557e` | exactly `config/ci_runner.php`, `resources/js/ktp-camera-ocr.js`, `resources/views/settings/patients/_ktp-scan.blade.php` |
| Production | `https://daengtisia.online`: `/login`, `/health/live`, `/health/ready`, `/health/lb` 200; `/storage/*` 403; patient create / visit create / KTP document routes 302 for a guest (no 500). Served bundle `app-BfCKqrV8.js` contains the new attestation guard and **0** native `required` assignments; the deployed partial has 0 `@required` |
| Unchanged posture | KTP camera OCR pilot `ARMED`, consent `D7-2026-10-10` usable; 0 failed jobs; postgresql / nginx / php8.3-fpm / queue worker active; environment pilot, debug OFF, maintenance OFF |
| Log delta | `laravel.log` byte-identical before and after (1,437,201 bytes, 162 ERROR lines) — **0 new errors** |
| GO tag | `governance-patient-ktp-new-patient-only-1-go` → `042078f7` (the deployed runtime); local / remote / VPS exact match |
| Graphify | rebuilt on tree `137cc0f2` (AST): 37,709 nodes / 54,741 edges / 3,828 communities; promoted to the canonical cache |

**Not claimed:** an authenticated end-to-end browser session on production (no operator credentials or real KTP were used). The fix is proven by the mutation-tested suites, the served bundle and the deployed partial; the operator-facing path (apply OCR → switch to *Pasien Terdaftar* → save) should be exercised once by the pilot operator on site.

This section is recorded in a docs-only evidence commit after the GO tag; that commit is not deployed and is not the tag target.
