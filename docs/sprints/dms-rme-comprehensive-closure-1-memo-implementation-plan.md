# DMS-RME-COMPREHENSIVE-CLOSURE-1 — memo implementation plan, impact analysis and compatibility

**Purpose.** The owner's written decisions of 2026-11-17 (parent record [[dms-rme-comprehensive-closure-1]] §2c)
require, before any implementation: a check of every rule conflict and of canonical authority, an impact analysis
before changing rules already at GO, and a migration/compatibility plan with no destructive change to historical
data. This record holds those plans. Each plan was drafted by a read-only planning pass over the production code
(`cab3a4c1`) plus the open closure-wave PRs it builds on, then reviewed by the program author. Traceability:
[[dms-rme-comprehensive-closure-1-memo-traceability]].

**Rule numbers reserved** (to keep parallel PRs free of collisions): 238 memo alignment (PR8: payment totals,
worklist treatment, transfer reference), 239 storage usage report (PR9), 240 finalized-record lock and supervised
amendment (M3.3), 241 PTM double signature (M1.x), 242 clinical photos and consent template v-next (M4.4 + clause 8),
243 doctor encounter-scoped access, downloads and visible fields (M2.1/M2.2/M2.4/M3.1), 244 automatic RM number and
required patient fields (M4.1/M4.2).

**Common constraints.** Additive migrations only (CG-09); lock order visit first (rule 223 §6); service-layer
re-assertion that binds Super Admin where a rule must (CG-06, CG-07); `ClinicalClock` for clinical dates (CG-12);
`ClinicalEvidenceStorage` for clinical objects (rule 121, rule 224); ≤ 10 `app/*.php` files per PR
(`sprint:scope-audit --strict`); every new suite selected by an existing critical-gate token in both runner variants
and declared in `critical_gate_mandatory_suites` (CG-19); RED-first tests; independent review per PR; Full Suite
deferred (CG-18), never reported as passed.

## M3.3 — lock on finalize + supervised amendment (rule 240)

### Impact analysis — rules superseded or amended (GO'd marked)

| Rule | GO tag | Change |
|---|---|---|
| 109 §1, §2b (last bullet), §4 CORRECTIVE-03 | `fix-rme-exam-consent-odontogram-history-3-go` | superseded: a finalized record is no longer editable; §2b gains "an amendment is not current-RME authoring" |
| 134 §5, §2 matrix | `revision-rme-consent-odontogram-preconsent-edit-1-go` | §5 superseded; new matrix row "finalized chart = DENY, amendment only" |
| 135 | `bugfix-rme-preconsent-first-page-ui-gate-1-go` | the UI renders locked sheets, pages and charts as locked (UI mirrors the server) |
| 219 §6 (DPT-Q4) | `feature-rme-doctor-performed-treatments-1-implementation-go` | unchanged ("not built" for performed treatments); cross-reference |
| 223 §1.6 | program 5 | lock order extended: visit → sheet → book → odontogram → amendment |
| 224 §1–5, 121 §6 | program 6 | amendment image + row are one unit; version objects never purged |
| 228 §5 | 8C | requester and approver allowlists |
| AIR-RMC-16, -22, -36 (+ new -54/-55) | — | rewritten / cross-referenced |

Tests pinning the current behaviour are flipped to refusals, never deleted: `MedicalRecordFinalizationTest`
("finalized RME can still be edited via service updateDraft / HTTP PATCH", "updating a finalized RME does not
blank…"), `MedicalRecordTest`, `MedicalRecordHandwritingTest`, `OdontogramTest` (Sprint 59 "finalized odontogram can
update"), `OdontogramAdditionalFieldsTest`, `OdontogramSelectedResultsTableTest`, `RmeOdontogramAuthorityMatrixTest`;
suites that write after a finalize are checked one by one.

### Design

- **What locks.** A final sheet (SOAP fields, notes, structured diagnoses); every handwriting page of the book is
  sealed when a sheet is finalized (a new page in a later encounter stays writable until that encounter's sheet is
  finalized); a finalized odontogram (the whole chart). Prescriptions keep the signed-content lock (rule 237 §9),
  performed treatments keep rule 219, payment is out of scope.
- **Enforcement, three layers on locked rows.** Policy: `MedicalRecordPolicy::editSheet()` (scope first, then
  final ⇒ deny), `OdontogramPolicy::update` denies a finalized chart after the scope check. Service (binds Super
  Admin): `updateDraft`, `finalize` (seals pages), the diagnosis writes, the handwriting save (after the page-revision
  compare, before any object is written), the odontogram update/save — each decided on the row it locked.
- **Legacy pages** (no authored-visit stamp) are locked when a final sheet of the patient was finalized at or after
  the page's creation — fails closed, no backfill.
- **Amendment state machine.** `pending` → `cancelled` (requester), `rejected` (a different checker, with reason) or
  `applied` (approve and apply in one transaction, so the checker approves exactly what is applied). Requester: the
  doctor of record (or the finalizer). Approver: Supervisor RME, never the requester, never the doctor of record;
  Super Admin only through `Gate::before` and never on their own request; maker ≠ checker re-checked in the service
  inside the transaction and backstopped by a PostgreSQL CHECK. One pending amendment per subject (partial unique
  index). A stale base version is refused.
- **Version history.** `trx_rme_record_versions`: an immutable JSON snapshot per version (sheet fields, diagnoses,
  sealed page object keys with SHA-256; or the odontogram payload), hash-chained, UPDATE/DELETE refused by a
  PostgreSQL trigger and by the model. `finalize` writes v1; the first amendment of a record finalized before deploy
  writes a `baseline` v1. Prior page objects are never deleted.
- **Permissions.** `request_rme_amendments` (Doctor), `approve_rme_amendments` (Supervisor RME). Seeders, then
  `permission:cache-reset` on deploy.
- **Routes and views.** Request form, supervisor queue, before/after view with approve/reject, version history and
  version page images (policy-gated, `no-store`, object keys never rendered; out-of-scope answers 404); "Diamandemen
  n×" marker on the RME print and the odontogram print; `dontFlash` for the amendment canvas.
- **Audit.** `RME_AMENDMENT_REQUESTED / CANCELLED / REJECTED / APPLIED`, ids and the reason's length only;
  database notifications after commit, ids only.

### Migration and compatibility

Records and charts already final at deploy are locked at once (by status); legacy pages through the derived rule.
No data is changed. Drafts in progress are unaffected. The handwriting book keeps accepting new pages in later
encounters (OD-5-6 and R7-1 unchanged). Legacy RME and Legacy Odontogram archives are already immutable and are not
touched. The lock and the amendment path ship in the SAME release, so there is never a lock without a correction
path.

### PR split

1. Sheet and odontogram lock (10 files) + rule 240 §1 and the rule revisions.
2. Page seal (8 files; seal columns migration).
3. Version history (10 files; new module `RmeAmendment`, versions and marker migrations).
4. Request / cancel / reject / queue (10 files; permissions).
5. Approve and apply (7 files; print markers).

### Tests (RED first)

`MedicalRecordFinalizationLockTest`, `OdontogramFinalizedLockTest`, `MedicalRecordFinalizationPageSealTest`,
`MedicalRecordFinalizationVersionHistoryTest`, `MedicalRecordFinalizationAmendmentTest`,
`MedicalRecordFinalizationAmendmentConcurrencyTest` (two approvals, approve vs edit, approve vs finalize, stale
base), `OdontogramAmendmentTest`, `RmeClinicalWriteAuthorityAmendmentAuthorizationTest` (role matrix, 403/404) — all
selected by the existing `MedicalRecordFinalization`, `Odontogram` and `RmeClinicalWriteAuthority` tokens.

### Open questions (proposed defaults)

1. Approver: Supervisor RME as checker only; Super Admin never on their own request. [yes]
2. Requester: only the doctor of record or the finalizer; a departed doctor's record waits for a later decision. [yes]
3. Records final at deploy are locked immediately. [yes]
4. Appending a page to a finalized sheet after the encounter only as an amendment item. [yes]
5. Performed treatments and prescriptions are excluded from this amendment. [excluded]
6. The approver must not be the doctor of record. [yes]

### Verification of the six defaults against the merged code (owner order "Verifikasi keenam default secara lengkap sebelum implementasi", base `82cb6e13`)

| # | Default | Code fact checked | Verdict |
|---|---|---|---|
| 1 | Supervisor RME approves as checker only; Super Admin never on their own request | role `Supervisor RME` exists (`RoleSeeder`); `Gate::before` grants Super Admin every ability (`RepositoryServiceProvider`), so maker ≠ checker must be re-asserted in the service inside the transaction, as `approve_rme_invoice_correction` / `approve_rme_refund` already do | holds; service re-check mandatory |
| 2 | Requester = doctor of record or finalizer | `trx_medical_records.doctor_id` → `mst_doctors.user_id`; `finalized_by` → `users`; odontogram carries `created_by`, `finalized_by`, `medical_record_id`, `clinic_visit_id` | holds; a doctor row without a linked user leaves only the finalizer as requester |
| 3 | Records final at deploy are locked at once | `MedicalRecord::STATUS_FINAL = 'final'`, `Odontogram::STATUS_FINALIZED = 'finalized'` — the lock reads status, no backfill | holds |
| 4 | A page after the encounter only as an amendment item | page rows carry `clinic_visit_id` of the **book's** visit (R7-1: the controller stores a new page on the book's visit), not the authoring encounter; Sprint 59 comment "handwriting RM is revisable after finalization" is the code to supersede | holds, and confirms that PR2 needs its own seal columns; page visit ids cannot express the seal |
| 5 | Performed treatments and prescriptions excluded | prescriptions: `PrescriptionAuthoringAuthority::signedContentLocked()` (rule 237 §9); performed treatments: `RmePerformedTreatmentService::lockAuthorizedEncounter()` (rule 219) | holds; both keep their own locks |
| 6 | Approver ≠ doctor of record | resolvable through `doctor_id` → `mst_doctors.user_id` inside the approval transaction | holds |

Release constraint: PR1 (the lock) and PR5 (approve and apply) ship in the same release. No release is cut from a base
that contains PR1 without PR5; each M3.3 PR body states it.

### Risks

Deadlocks (keep the strict lock order); same-second timestamps in the legacy page rule (ties lock, fails closed);
a pre-consent odontogram finalize now needs an amendment (interacts with M1.x); the SATUSEHAT observer fires again on
an amended final record (gateway disabled; re-submission policy undecided); storage growth from retained versions
(reported by the M6.2 usage report); rebasing over #648, #650 and #653.

## M1.1–M1.3 + M4.4 — both PTM signatures gate writing (rule 241), consent template v2026.2 and clinical photos (rule 242)

Builds on #648 (one valid consent per visit, supervised void, signatures on the clinical disk) and #650.

### Impact analysis — rules superseded or amended (GO'd marked)

| Rule | GO tag | Change |
|---|---|---|
| 134 §1–§7 | `revision-rme-consent-odontogram-preconsent-edit-1-go` | superseded in full (pre-consent odontogram charting ends); kept: one authority method, "finalize ≠ examination completion", immutable history |
| 109 §0, §1a, §2 (odontogram bullet), §2b cond. 4, §2c | `fix-rme-exam-consent-odontogram-history-3-go` | amended: writing and finishing need an EXECUTED consent (patient + doctor); kept: §2a sign only `in_progress` after "Mulai Pemeriksaan", reads never gated, §3 payment |
| 100 §4–§7 | `fix-rme-consent-workflow-print-ux-2-go` | the doctor signature becomes a separate insert-only record; clause 8 split from v2026.2; permissions; the doctor-signature image stays private |
| 135 §2 | `bugfix-rme-preconsent-first-page-ui-gate-1-go` | "consent VALID" becomes "EXECUTED"; new "awaiting doctor" state |
| 223 §1.5–6, §2.9, §2.11, §3.13, §5.18 | `dms-rme-revision-program-5-go` | the doctor-signature write takes the visit lock first; two-state finish button and messages; front office keeps patient capture |
| 219 | performed-treatments GO | the consent condition becomes "executed" |
| 224 §8, 121, 227 §1, 228 §5 | program 6 / 8B / 8C | pointers; photos get a narrower read scope than 227 §1 |
| 237 §14.3 | not at GO | superseded ("odontogram unaffected") |
| AIR-RMC-04, -07, -12, -13, -14, -25, -34, -36, -38, -39 | — | rewritten / superseded |

Pinned tests rewritten in place (paths kept, they are mandatory suites): `RmeConsentOdontogramPreConsentEditTest`,
`RmeOdontogramAuthorityMatrixTest`, `RmePreConsentFirstPageUiGateTest`; updated: `PostRmeOdontogramStabilizationTest`,
`RmeVisitConsentSignatureBoundTest`, `RmeVisitConsentHttpTest`, `RmeVisitConsentGateTest`,
`RmeVisitConsentSupervisedVoidTest`, `RmeClinicalWorkflowIntegrityTest`, `RmeExamConsentOdontogramHistoryTest`,
`RmeExamConsentCorrective(3)Test`, `RmePerformedTreatmentRecordingTest`, `DoctorPatientScopeTest`, the role-permission
tests and two browser tests. The shared fixture `rmeSignedConsentFor()` will mean an EXECUTED consent; gate tests
build their state only through the service so the fixture cannot hide a gate gap.

### Design

- **Doctor signature step.** New insert-only table `trx_rme_visit_consent_doctor_signatures` (UNIQUE per consent,
  doctor, signer account, private image key, content SHA-256, clinic time). The consent row is never updated
  (AIR-RMC-12). `RmeVisitConsentService::countersign()`: bounded PNG decoded first; visit locked first, then the
  consent refreshed; visit `in_progress`, consent valid and not yet countersigned; the actor may work on the encounter
  (role, branch, room, patient scope); the actor's linked doctor equals the locked visit's doctor and the consent's
  doctor; the actor is not the account that captured the patient signature (binds Super Admin); savepoint insert with
  the unique violation as a refusal; audit `RME_CONSENT_DOCTOR_SIGNED`. Permission `countersign_rme_consents`
  (Doctor). The legacy optional doctor drawing on old consents stays display history and never counts as the step.
- **Gates.** RME authoring, handwriting, diagnoses, performed treatments, the odontogram (new condition, visit locked
  first, then the chart) and "Selesai Pemeriksaan" require `hasExecutedConsent`. Reads stay open. A void reopens the
  gate.
- **UI.** Front office captures the patient signature (no doctor canvas on that form; the queue shows the PTM state);
  the doctor signs from Ruang Perawatan ("Tanda Tangani PTM"). Blade service calls move into controllers (MEMO-F5).
  The print shows the doctor signature with name and time, or "Belum ditandatangani dokter".
- **Template v2026.2.** Clause 8 = clinical documentation (YA/TIDAK), clause 9 = publication / promotion (YA/TIDAK;
  refusing never affects care); additive nullable `publication_consent`; historical consents keep their version,
  snapshot and single clause-8 answer and render unchanged.
- **Clinical photos** (new module `ClinicalPhoto`, feature flag `rme.clinical_photos`, default OFF — turning it on
  is a separate owner decision). Upload needs the executed consent with clinical documentation = YA, decided under the
  visit lock; Doctor and Perawat only (service allowlist binds Super Admin); the image is normalized BEFORE the
  transaction (type/size checks, decompression-bomb guard, EXIF orientation applied, re-encoded JPEG ≤ 2560 px, so
  EXIF/GPS is stripped; a failed decode refuses); object through `ClinicalEvidenceStorage`, row and object one unit
  (rule 224); void with a 10–1000 character reason (no delete path); read follows the RME clinical read scope and the
  doctor-patient scope (404 when out of scope), never Front Office, Kasir or Admin Klinik even with a runtime grant;
  every view audited; `private, no-store`, nosniff.

### Migration and compatibility

Additive migrations only, no existing row updated. A visit `in_progress` at deploy with a patient-only consent becomes
"awaiting doctor": what is written stays; further writes and finishing wait for the treating doctor's signature (the
old optional drawing is not bound to an account, so it is not grandfathered). Production is a testing database
(CLAUDE.md), so no operational encounter is disrupted. Historical consents are never countersigned afterwards; payment
never consults consent (AIR-RMC-11). Deploy: `PermissionSeeder` → `RoleSeeder` → `permission:cache-reset`.

### PR split

1. Doctor signature capture, no gate change yet (9 files).
2. Both signatures gate writing + the two dashboards (7 files; rule 241, rule amendments, `rme-clinical.md`).
3. Template v2026.2 (3 files).
4. Photos backend, flag OFF (10 files; rule 242).
5. Photos UI (2 files).

PR 1–3 ship in one release.

### Tests (RED first)

`RmeVisitConsentDoctorCountersignTest`, `RmeVisitConsentCountersignConcurrencyTest`,
`RmeVisitConsentTwoSignatureGateTest`, `RmeVisitConsentTemplateVersionSplitTest` (token `RmeVisitConsent`);
`RmeOdontogramTwoSignatureGateTest` (token `Odontogram`); `RmeClinicalEvidencePhotoUploadTest`,
`…PhotoReadScopeTest`, `…PhotoConcurrencyTest`, `…PhotoGalleryUiTest` (token `ClinicalEvidence`). Races on
PostgreSQL: two countersigns, countersign vs void / finish / cancel, odontogram save vs void, RME write vs countersign,
photo upload vs void and finish (no orphan object), two photo voids. Authorization negatives for every role named in
the design, crafted ids, out-of-scope 404.

### Open questions (proposed defaults)

1. Who captures the patient signature: Front Office, Kasir, Admin Klinik, with Supervisor RME as fallback; the Doctor
   role loses `manage_rme_consents`. [yes]
2. Prescriptions stay outside the two-signature gate (rule 109 §2c, rule 226). [yes]
3. Exact wording of clauses 8 and 9 in v2026.2: a draft from the existing clause 8, approved word for word by the
   owner (rule 100 §4: wording is a product decision). [owner approves the text]
4. Photo originals: keep only the cleaned JPEG plus the original's SHA-256 (the original would keep GPS). [yes]

### Risks

The odontogram is blocked until both signatures (the circular-workflow concern rule 134 §3 had removed; a wrong
consent text needs a void and re-sign); a doctor reassigned after the patient signs cannot countersign until void and
re-sign; about 20 test files change; photos and signatures are not in the database-only daily backup (M6.2); upload
size must fit nginx and PHP limits; voided photos stay on disk (purge is a separate owner phase); rebase over #648 and
#650.

## M2.1/M2.2 + M2.4 + M3.1 — doctor encounter scope, no doctor downloads, doctor-visible fields (rule 243)

Builds on #650–#653. Facts that drive the design: room placement is the only producer of doctor assignments (every
live row is `auto_visit`); `assignRoom` is the only path that changes a visit's doctor; every published legacy
record has rendered page images (so doctors can be served images, never PDF bytes); a saved RM page 1 has the
patient's address, phone and email drawn into its pixels; the Android pilot is a WebView with no clinical JSON API.
Today another clinician may co-author on an encounter in the same room (OD-5-1, rule 228); the owner's M2.1 decision
("never edit another doctor's encounter") now closes that explicitly.

### Impact analysis — rules superseded or amended (GO'd marked)

| Rule | GO tag | Change |
|---|---|---|
| 99 §1, §2, §11; AIR-LRM-25/26 | `legacy-rme-pdf-history-1b-…-go` | legacy archive read needs a live service window; a doctor outside the scope gets 404 |
| 97 §5, §6; AIR-LRM-27 | `legacy-rme-pdf-history-1-…-go` | access derives from encounters, not from assignment rows |
| 138 §3, §6; AIR-DAC-05/06, AIR-RMC-20 | `feature-doctor-trusted-android-device-lock-1-phase-1-go` | "history never restricted" now applies only within the room scope; §6 adds legacy print/export/source and PTM print to the doctor deny list |
| 109 §2 reads; AIR-RMC-09/17 | `fix-rme-exam-consent-odontogram-history-3-go` | reads stay ungated by consent, but doctor reads follow the encounter scope |
| 93 §12; AIR-LRM-29 | `legacy-rme-pdf-1d-…-go` | doctors lose legacy print/export; the Front Office path is unchanged |
| 104 | — | doctors lose the source PDF; page images keep the zoom requirement |
| AIR-DAC-40 | `hotfix-sprint-66-2-2-patient-wide-clinical-access-go` | rewritten (patient-wide access ends) |
| AIR-RMC-13 | — | the consent view shows a masked NIK to doctors (CG-11 is a ceiling, not an obligation) |
| 228 OD-5-3, 227 §1.4 | 8C / 8B | room-only move extended to `in_progress`; meaning narrowed |

About 15 test files that pin today's behaviour change (notably `DoctorPatientScopeTest`, `LegacyRmeClinicalReadPrintTest`
and the legacy doctor workspace suites); none is deleted.

### Design

- **A. Encounter scope** (feature flag `rme.doctor_encounter_scope`, default OFF; flag OFF = today's behaviour
  exactly). `DoctorEncounterWindow`: a visit is "in service" when its status is open (registered, waiting,
  in_progress, cashier_pending) or it was completed today by `ClinicalClock`; a cancelled visit never opens a window.
  `DoctorPatientScopeService` stays the single authority: read mode NONE / OWN / HISTORY per patient (memoised per
  routed request, as #651); a doctor reads their own visits at any date, and the patient's other non-pre-exam visits,
  sheets, odontograms, diagnoses, masked consent, prescriptions and published legacy archives only while an own
  in-service encounter exists; writes only on the doctor's own in-service encounter (403 "ditangani dokter lain"
  otherwise); out-of-scope reads answer 404. Lists mirror the per-record checks. Assignment rows no longer grant
  access. The third layer re-asserts in `actorMayWorkOnEncounter` and `PrescriptionAuthoringAuthority::permits`.
- **Revocation.** `assignRoom` changes the doctor only while the visit is registered or waiting (an `in_progress`
  visit moves room only); when the doctor changes, the old doctor's `auto_visit` assignment is ended with a reason and
  audited (ids only); a cancel does the same.
- **B. No doctor downloads.** `DoctorRoomScopeService` stays the single print-deny authority: doctors are refused
  (and audited) on legacy print, export and source PDF, and on PTM print; doctors read archives as inline page
  images (`private, no-store`, `nosniff`); the source link renders only under `@can`. Native RME / odontogram print
  stays denied; prescription print stays allowed; Front Office, Admin Klinik and Supervisor RME keep printing. Honest
  limit: pixels on screen can always be photographed.
- **C. Doctor-visible fields.** `PatientIdentityPresenter::forViewer()` returns a view DTO; the doctor audience gets
  name, RM number, place and date of birth, age, occupation and planned treatment (place of birth from the M4.2
  additive column); the doctor's consent view masks the NIK and hides addresses; old page-1 images are streamed to
  doctors with the biodata band redacted; scanned legacy archives cannot be redacted (recorded).

### Migration and compatibility

A ships behind the flag, OFF; turning it on follows the merge of all A-PRs, a reviewed reconcile dry run and doctor UAT
(owner decision). `rme:doctor-assignments-reconcile` (dry run default, counts only; `--apply --confirm-plan=<digest>`
with owner approval) ends stale `auto_visit` rows — it sets `unassigned_at` only. Doctors mid-encounter keep full
access. Legacy archives, cross-branch reads and the branch lock are unchanged. Nothing is deleted or overwritten.

### PR split

1. B — downloads (6–8 files). 2. A1 core scope (9 files; flag, index migration). 3. A2 legacy scope (6 files).
4. A3 after-service workspace (4 files). 5. A4 revocation + reconcile command (6 files; migration, seeder).
6. C1 identity presenter (6 files). 7. C2 page-1 redaction (2 files).

### Tests (RED first)

`DoctorPatientScopeEncounterWindowTest`, `DoctorPatientScopeReassignmentTest`, `DoctorPatientScopeReconcileCommandTest`,
`DoctorPatientScopePostServiceWorkspaceTest`, `DoctorPatientScopeIdentityFieldsTest` (token `DoctorPatientScope`);
`DoctorRoomScopedArchiveAndConsentPrintDenyTest` (`DoctorRoomScoped`); `LegacyRmeDoctorEncounterScopeTest`
(`LegacyRme`); `LegacyOdontogramDoctorEncounterScopeTest` (`LegacyOdontogram`).

### Open questions (proposed defaults)

1. Manual/shared assignments grant no read window; shared care is done by assigning the encounter. [no window]
2. Front Office moving an `in_progress` visit moves the room only. [yes]
3. RM page 1 drawn with the allowed fields only (the Front Office print of a new page 1 loses address/phone); old
   page-1 images redacted for doctors. [yes, yes]
4. Legacy odontogram source refused to doctors too; no watermark and no Android `FLAG_SECURE` in this wave. [yes; not now]
5. Doctors also see gender (not religion or marital status). [gender only]
6. A doctor outside the scope gets 404 instead of 403 (revises rule 99 §2, AIR-LRM-25). [404]

### Risks

Doctors lose history for returning patients outside a service (intended; today's queue opens a window); stale open
visits keep a window open (reported by the dry run); the window predicate and its SQL twin, and the list scope and the
per-record check, must stay mirror images; the #651 query budget; contact data remains in scanned legacy images; about
15 test files change; rebase conflicts in `ClinicVisitService`, `PatientRmWorkspaceResolver`, `DoctorIdentityResolver`.

## M4.1 + M4.2 — automatic RM number and required patient fields (rule 244)

Builds on #650. Facts: every patient is created through `PatientService::create()` (master form and the visit's
"Pasien Baru"); `applyMedicalRecordNumber()` decides the number (free override, else branch + typed number, else the
UTC `PatientCodeGenerator` fallback); an edit can rebuild the number today; production has no Admin Klinik account and
Front Office must stay the exact union of Admin Klinik + Kasir; the legacy patient CSV import stores typed numbers;
MAIN can be RME-enabled on a fresh seed (CC1-PAT-3).

### Impact analysis

| Rule | GO tag | Change |
|---|---|---|
| Sprint 23.8 manual number (`PatientMedicalRecordNumberService` docblock) | — | replaced explicitly by rule 244 |
| 110 §4; AIR-PAT-11 | GO | request pre-checks go; `registered_at` stamped by the server from `ClinicalClock` for new patients; format and "never renumber" kept; the allocator added |
| AIR-PAT-21, 177 §5, 179 | GO | place of birth stops being display-only |
| 184 §9/§15/§16 | GO | owner-revision note: a complete NEW registration means the core fields; never a visit blocker, never applied to legacy patients; §10 (KTP never required) kept |
| 168 | GO | the import commit takes the number lock (and the paper-card permission, if approved) |
| 237 §4.2 | — | CC1-PAT-1, CC1-PAT-2 (store path), CC1-PAT-4 (duplicate → 500) resolved |

About 20 test files change (a `newPatientPayload()` helper limits the churn); `PatientCodeGenerationTest` is retired
with the generator; `PatientKtpNewPatientOnlyGovernanceTest`, `FrontOfficeRoleTest` and the legacy import suites must
stay green.

### Design

- **Allocator.** New table `mst_patient_rm_sequences` (branch, year, last number; UNIQUE(branch, year)).
  `PatientRmNumberAllocator::allocate(branch, year)`: inside the registration transaction, lock (or create) the
  branch-year row FOR UPDATE, take the next number, skip any number already taken in any spelling (alias codes
  TKM1/TLK1 and SUN4/SPN4, any zero padding, soft-deleted rows included), store it, compose
  `DG-{CODE}-{YEAR}-{NNNN}` (4-digit padding). The year comes from `ClinicalClock`. MAIN is refused. A unique
  violation on insert is retried up to 3 times under the held lock, then answers 422 — never 500.
- **Overflow.** After 9999 the number widens to 10000 (5 digits) instead of stopping registration; a warning is
  logged from 9000; a 9-digit cap refuses cleanly.
- **Retired.** The free override and the UTC fallback generator (CG-12 deviation) are removed; existing rows are not
  touched. An edit never changes the RM number, branch or registration date.
- **Paper-card exception.** An old paper-card number may be entered only with `register_patient_paper_card_rm`
  (proposed: Supervisor RME), with a reason, numeric-duplicate check under the same lock, audited without the number.
- **Core fields (new patients only).** Gender, place of birth (new additive column), date of birth, phone or
  WhatsApp, address, occupation — one shared rule builder for both forms; NIK and KTP scan optional (16 digits when
  given). Updates keep every field optional, so legacy and incomplete records are never forced.
- **No-phone exception.** A reason code (no phone / dependant without phone / other with a note) under
  `register_patient_without_phone`, audited without values, cleared when a number is recorded later.
- **Views.** The forms show "Nomor RM: otomatis DG-{KODE}-{TAHUN}-####"; the number is shown after saving (no
  preview, which two operators would see identically); KTP OCR may suggest the place of birth for the operator to
  confirm.

### Migration and compatibility

1. Owner runs the read-only measurement (below) before the automatic-number PR merges — DONE 2026-11-17, recorded in the sprint
   record.
2. Deploy the dormant foundation PR; `php artisan patient:rm-sequence-seed` dry run (counts only) as the runtime user;
   `--apply` only with owner approval (sets each counter to GREATEST(existing, computed), never lowers, idempotent,
   one audit row). A missing branch-year row is seeded on first use by the same function.
3. Deploy the automatic-number and paper-card PRs in one release; then `PermissionSeeder` → `RoleSeeder` →
   `permission:cache-reset`. Deploy outside clinic hours (old processes during a rolling deploy compose typed numbers
   without the lock).
4. Legacy: the CSV import still stores typed numbers; archive branch derivation and alias folding are unchanged;
   generator-format and free-text rows are reported, not repaired. Rollback: revert the automatic-number PR; the
   sequence table becomes inert.

### PR split

1. Foundation, dormant (8 files; migration, seed command). 2. Automatic number (8 files; migration, views).
3. Paper card + CSV commit lock (6 files; seeders). 4. Core fields + no-phone (8 files; migration, views, seeders).
5. OCR place-of-birth suggestion (JS only). 6. Records.

### Tests (RED first)

`ClinicVisitRegistrationConcurrencyPatientRmSequenceTest` (committed-writer + NOWAIT probe, PostgreSQL),
`FrontOfficePatientRmAutoNumberTest` (incl. 00:30 WITA on 1 January, 9999 → 10000, injected unique violation → 422),
`LegacyPatientRmAutoNumberCompatibilityTest`, `FrontOfficePatientRmSequenceSeedCommandTest`,
`FrontOfficePatientRmPaperCardExceptionTest`, `FrontOfficeNewPatientCoreFieldsTest`, `PatientKtpPlaceOfBirthTest`.

### Production measurement (owner, read-only, counts and aggregates only)

The combined query (the §10.2 identity counts, number shapes, per branch-year aggregates with the maximum's digit
count only, core-field completeness, and the unique index check) runs in one `BEGIN READ ONLY … ROLLBACK` as the
runtime user. No patient identifier is selected.

### Open questions (proposed defaults)

1. Who may enter old paper-card numbers: Supervisor RME. [Supervisor RME]
2. No-phone exception: Front Office with a reason, audited and reportable. [Front Office]
3. Unusually high numbers in the current year: continue after the highest. [continue]
4. Legacy CSV import commit gated by the paper-card permission (revises part of rule 168). [yes]
5. No correction tool for existing numbers this sprint (frozen on edit). [none now]

### Risks

Test churn; stale browser tabs after deploy (422 with a reload message); serialised registrations per branch-year
(milliseconds); 5-digit numbers sort before 4-digit ones as strings (cosmetic); rule 184 needs the explicit owner
note; a large override count from the measurement would make reconciliation a separate owner decision.

## Owner answers on the plans (2026-11-17, structured question tool)

| Plan | Owner answer |
|---|---|
| M3.3 lock on finalize + supervised amendment | **Accept all defaults** |
| M1.x PTM + v2026.2 + clinical photos | **Accept defaults; the owner sends the clause 8/9 wording** — the template PR waits for the owner's text; the doctor-signature and gate PRs do not depend on it |
| M2.1/M2.2/M2.4/M3.1 doctor scope, downloads, fields | **Accept all defaults** (encounter scope behind its flag, OFF until a reviewed dry run, doctor UAT and an owner decision) |
| M4.1/M4.2 RM number + required fields | **Accept all defaults** (the production measurement runs before the automatic-number PR merges) |

### Applied during implementation (2026-11-17)

| Plan | Point | Applied |
|---|---|---|
| M4.1 | default 3 "continue after the highest" | applied literally after the PR-2 review: a successful claim (CSV import, later the paper card) raises the branch-year counter to the claimed number under the same lock (oversized numbers excluded as in the seed); the skip loop stays as a safety net. A skip-only design made the result depend on timing, walked claimed blocks under the lock and could reissue a purged number |
| M4.1 | CC1-PAT-2 | the master-form branch must be allowed by `RmeWorkingBranchScope` (the request may only narrow, CG-05), fixed in PR-2 |
| M2.4 | OD-MEMO-B-1 (residual, LOW) | a Doctor who also holds Finance Klinik / Data Analyst can still print NATIVE RME / odontogram through `oversight.rme.visits.*` (rule 138 §6 surface, pre-existing); the oversight doctor refusal on the legacy doors is one layer (controller + service). Follow-up: a dedicated oversight ability |
| M3.3, M1.x, M4.1 | release constraint | each stacked series (M3.3 PR1–PR5, PTM PR1–PR3, RM PR-2–PR-3) merges back-to-back only after the whole series is reviewed and green, so the base never carries half a series |
| M4.1 | RM claim cap (owner, "RM NUMBER SAFETY DECISIONS", 2026-11-17) | **Refuse jump > +1000**: a current-year claim may raise the branch-year counter by at most +1000 and never above 9999; larger claims are refused with a clear message and escalated to Supervisor RME; never accept numbers beyond the MRN format; never reuse a number; a previous-year claim never changes the current-year counter; database locking + uniqueness; negative tests for exhaustion, concurrency and extreme claims |
| M4.1 | paper-card reason (owner, same decision) | **Store text, restricted**: a structured reason code where possible plus a bounded note; readable only by Supervisor RME and Owner; entered by whoever holds the paper-card permission; never in logs, general exports or widely accessible audit payloads; no unnecessary patient data; immutable rows with a retention rule; authorization and privacy tests |
| M4.1 | claim-cap scope (owner, structured question, 2026-11-17) | **Per batch**: one legacy-CSV import or pilot-backup run may raise a branch-year counter by at most +1000 in total, measured from the counter when the batch starts; paper-card registrations stay per claim |
| M4.1 | paper-card reason storage | delivered as RM PR-3b (the reason storage exceeds the 10-app-file cap of PR-3), shipping in the same release as PR-2/PR-3 |
| M3.3 | handwriting amendment page (owner, structured question, 2026-11-17) | **Build amendment page first**: a stacked M3.3 PR6 lets an amendment carry one new handwriting page image (validated PNG, private object + row as one unit), appended on approval as a new sealed page; nothing overwritten; the series PR1–PR6 ships together. Without it a sealed page could never be corrected after the visit completes |
| M4.2 | paper-card registration (owner, "OWNER DECISIONS", 2026-11-17) | **Exempt paper-card path**: the core fields are not required on a legitimate registration of a historical paper RM card (the server-validated paper-card path); the exemption never extends to an ordinary new-patient registration; the Supervisor RME permission, NIK format validation, the audit trail and existing MRNs are kept (RM PR-4 review F1) |
| M4.2 | no-phone reason note (owner, same decision) | **Restrict like paper-card**: only Supervisor RME and Owner read the full note; other authorized staff see the reason code; never in logs, session flash, general exports or widely readable audit payloads; privacy and authorization regression tests (RM PR-4 review F3) |
| M2.4 | #660 (owner, same decision) | squash-merged at tested head `e2eeab80` → `41fecc06`; legitimate clinical reads, the doctor download/print restriction and prescription printing kept |
| CC1-ADJ-1 | #662 (owner, same decision) | merge pinned to `ec274cf5` only after every required gate passes; a failed gate or a changed head means re-test before any merge |
| M1.x/M4.4 | PTM merges (owner, "OWNER DECISIONS", 2026-11-17, second) | **Approve once green**: #663 → #664 → #665 squash-merged in order, each pinned to its head, only after every required gate passes on that exact head; a changed head or failed gate means stop and re-verify. #663 merged at `b204b2d6` → `fd2bf03e` |
| M3.3 × M1.x | amendment vs PTM (owner, same decision) | **No PTM gate, record it**: a supervised amendment corrects an existing record and stays outside the PTM gate; append-only amendment, immutable original, doctor requester, separate Supervisor RME approver, written reason, audit trail, and the original PTM state shown for information are kept; no historical consent is created or faked; a new medical act goes through the examination workflow and a valid PTM. Recorded in AIR-RMC-55, rule 241 §2.3 and rule 240 §6 |
| program | release plan (owner, same decision) | **Two waves**: release 3 after all of wave 1 (PTM 1–3, M3.3 1–7, RM 2–4b, doctor scope A1–A3 flag OFF, #660/#662) is merged and green; release 4 after wave 2 (A4, C1, C2, clinical photos flag OFF, RM-5, SATUSEHAT PR6). Each release: exact-SHA approval, Direct Signed Release Approval Contract 3, owner signature, VPS-only deploy, pre-deploy database backup, production verification. The final GO tag only after release 4 and every mandatory program gate; release 3 is not a GO |
| program | CI workflow scope (owner, same decision) | the owner grants the `workflow` scope through GitHub's own sign-in in their terminal; the assistant never asks for or reads the token, uses the scope only for reviewed CI changes, then pushes SATUSEHAT PR6 and runs required CI |
| program | merge shape (owner, structured question, 2026-11-17) | **One PR per series**: each already-reviewed stacked series (M3.3 PR1–PR7, RM PR-2–PR-4b, doctor scope A1–A3b) goes up as ONE squash PR listing every sub-PR and its review, required CI on the exact head, owner approval per series merge. Reason: after a squash merge every later stacked PR must be updated and re-tested on a new head, sequentially (about 15 extra CI runs for wave 1). PTM #664/#665 continue as separate PRs; #664 was updated by merging the base with strategy `ours` (tree byte-identical to the tested head) after a force-push was refused, and is re-tested before merge |
| M4.4 | clinical photos — voided photo (owner, structured question, 2026-11-18) | **Hide and restrict**: a voided photo leaves the normal gallery; its image is viewable only by holders of `void_rme_clinical_photos` (Supervisor RME), each view audited; the evidence row and object are kept, never deleted |
| M4.4 | clinical photos — size cap (owner, same) | **Raise to 50 MP** (edges ≤10000 px), still re-encoded to a 2560 px long edge; 15 MB file cap kept |
| M4.4 | clinical photos — preconditions (owner, same) | **Accepted**: a backup of the private clinical-evidence disk plus a tested restore is a hard precondition before the photos flag is turned ON; the Owner role needs an explicit permission grant to read photos; 403 for accounts without the permission while the flag is OFF (repo pattern) |
