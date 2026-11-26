# DMS-RME-COMPREHENSIVE-CLOSURE-1 — internal memo requirements traceability

**Source:** "Internal Memo — Hasil Demo, Evaluasi, dan Tindak Lanjut Pengembangan Aplikasi RME Daengtisia",
2026-10-17 (13.45–15.33 WITA), PT Daengtisia Grup Indonesia. The owner supplied it on 2026-11-17 as a binding
requirements source and closure evidence for this program. The memo itself is held by the owner and is not committed:
this repository is public, so participants are recorded by role only (operations director, finance director, IT
partner, marketing intelligence, customer officer).

**Parent record:** [[dms-rme-comprehensive-closure-1]]. **Code audited:** `cab3a4c1`, the release-1 code deployed in
production (deploy `20261116-141906`). Open closure-wave PRs are named where they change a requirement.

**Method.** Each requirement was traced read-only in routes, middleware, policies, services, requests, views,
seeders and migrations, matched to the canonical rules (`.cursor/rules`) and to the tests that pin the behaviour. The
key claims were re-checked in code by the program author before classification. A module existing is never counted
as a requirement met.

**Production evidence.** Release 1 proves only that this code is what production runs, and that production is
healthy (read-only checks, §9.3 of the parent record). No requirement below was exercised in production: the
production database is a testing database, production verification is read-only, and no test identity was used.
Behaviour is proven by the named tests on PostgreSQL 16 in CI.

## 1. Coverage

| Classification | Count | Requirements |
|---|---|---|
| IMPLEMENTED | 2 | M2.3, M4.3 |
| PARTIAL | 8 | M1.1, M1.2, M2.4, M2.5, M3.2, M3.3, M5.1, M6.2 |
| MISSING | 2 | M3.1, M4.4 |
| CONFLICTING (memo vs canonical rule or owner decision) | 4 | M1.3, M2.1, M2.2, M4.1 |
| REQUIRES OWNER DECISION | 4 | M4.2, M5.2, M6.1 (organizational), C4 (organizational) |
| **Total** | **20** | requirements fully met: 2 of 20 (10 %) |

Memo follow-up C1 (the IT partner adjusts features: PTM flow, access limits, automatic numbering, clinical
documentation, cross-branch integration) is the umbrella of M1–M4; C2 is M6.1; C3 is M6.2.

## 2. Traceability matrix

| Id | Memo requirement (short) | Implementation at `cab3a4c1` | Rule | Tests | Status |
|---|---|---|---|---|---|
| M1.1 | Patient AND doctor sign the PTM before RME filling | The patient signature gates every RME write and "Selesai Pemeriksaan" (`RmeVisitConsentService::assertRmeAuthoringAllowedForPatient`, `hasValidConsent`); the doctor signature is optional (`StoreRmeVisitConsentRequest`: `doctor_signature` nullable; form label "Dokter (opsional)") and gates nothing | rule 109 §2b, AIR-RMC-07 agree for the patient; no rule requires the doctor's | `RmeConsentOdontogramPreConsentEditTest` (authoring denied while unsigned), `RmeExamConsentOdontogramHistoryTest` | PARTIAL |
| M1.2 | Patient signs through FO; unsigned → FO cannot save or finalize RME | FO holds `manage_rme_consents` (so do Doctor, Kasir, Admin Klinik, Supervisor RME); signing only while the visit is `in_progress`, from Detail Kunjungan or the RM page, no FO-list entry; FO never authors RME (front-desk tier refused) | rule 223 §13 agrees (FO keeps consent signing); capture at registration would conflict with rule 109 §2a | `RmeClinicalWorkflowIntegrityTest` CW5-05b/05f; `RmeVisitConsentHttpTest` (Kasir path); no FO-signing test | PARTIAL |
| M1.3 | Doctor signs on the doctor dashboard; no odontogram or RME before the signature | No separate doctor-signing step; a signed consent is immutable (no countersign); RME pages always open (reads never gated); the odontogram may be charted and finalized before consent | rule 134 §1 (pre-consent odontogram), rule 109 §2c (never gate a read), AIR-RMC-12 (immutable) — **conflict** | `RmeConsentOdontogramPreConsentEditTest` pins the opposite ("lets the doctor SAVE/CREATE/FINALIZE the active odontogram while consent is unsigned") | CONFLICTING + MISSING |
| M2.1 | Doctor sees only patients FO assigned that service day | Access = any visit with that doctor (any date) or an assignment never ended (`DoctorPatientScopeService::doctorHasPatientAccess`); room placement creates a permanent `auto_visit` assignment; no day window. Finding MEMO-F1: moving a pre-exam visit to another room leaves the first doctor's assignment active | rule 99 §1, rule 97 §5, AIR-DAC-40 (treating relationship, no time limit) — **conflict** | `DoctorPatientScopeTest` ("keeps doctor a read access after unassign"); no day-window test | CONFLICTING |
| M2.2 | Doctor may not see the full history beyond the current service | Patient-level check: a linked doctor sees every visit of the patient, other doctors' and other branches' included, plus legacy archives and odontogram history; the narrower `authorizeSpecificVisitAccess` exists but is unused | rule 138 §3 ("history is never restricted"), rule 109 ("reads are never gated") — **conflict** | `DoctorPatientScopeTest` ("lets doctor with prior patient visit history open another doctors visit detail") pins the opposite | CONFLICTING |
| M2.3 | All branches integrated; cross-branch RME for authorized staff | Cross-branch clinical read for Doctor, Perawat, Supervisor RME, Super Admin, Owner (`RmeWorkingBranchScope`); FO, Admin Klinik, Kasir read their working branch only; doctors additionally patient-scoped | rule 227, rule 228 §5.2 agree | `RmeClinicalDocumentReadScopeAllowlistTest` A-02..A-04, `CrossBranchRmLookupTest` | IMPLEMENTED (FO single-branch: ACCEPTED BY DESIGN, owner to confirm) |
| M2.4 | No RME download on the doctor dashboard | Native RME print / PDF / odontogram print denied to a doctor (`ClinicVisitPolicy::print`, `DoctorRoomScopeService::authorizeClinicalPrint`); still reachable: the legacy archive print and PDF export (`rme.legacy-records.export`), the PTM print, the prescription print, and inline archive PDF viewing | rule 138 §6 agrees for native RME and keeps prescription print; rule 93 §12 allows legacy print/export — **conflict** for the archive | `DoctorRoomScopedAccessAndPrintDenyTest` (four deny tests); `LegacyRmeClinicalReadPrintTest` ("lets a doctor print their own branch archive") | PARTIAL |
| M2.5 | FO dashboard restricted per account and authority (patient data, payment information) | FO = Admin Klinik + Kasir, working-branch bound, permission-gated reports. Finding MEMO-F2: the RME dashboard (`view_clinic_visits` only) shows "Pembayaran Hari Ini" and "Piutang RME Aktif" to Doctor and Perawat, across all RME branches for an unlocked doctor. Any `manage patients` holder can browse the patient register with an empty search | CG-11, AIR-PAT-02/03; no rule on dashboard payment totals | `FrontOfficeRoleTest`, `RmeDashboardPageTest`; no test refusing payment totals to a doctor | PARTIAL |
| M3.1 | Doctor sees only name, place and date of birth, occupation, treatment type | Doctor views show name, MRN, phone, WhatsApp, age (visit detail); date of birth, occupation, address, gender, phone, email, MRN (RM page); the full NIK on the consent document. No place-of-birth column exists | no rule limits doctor-visible fields; CG-11 allows the full NIK on the consent document | none | MISSING |
| M3.2 | Planned treatment shown directly to the doctor | "Tindakan Awal" is required at registration (`initial_treatment_id`) and shown on Detail Kunjungan and the RM page; not on the doctor's room worklist (the landing list) | no rule | `ClinicVisitInitialServiceTest` ("initial_treatment_id is required on create", "doctor can see initial service on RME page") | PARTIAL |
| M3.3 | After finalizing, the doctor cannot change treatment or payment alone; may the doctor edit again? | During the encounter a finalized RM sheet and odontogram stay editable (Sprint 59); after "Selesai Pemeriksaan" RME writes are refused and treatments read-only; the doctor never edits payment; invoice correction is maker-checker (Kasir/FO request, FO/Admin Klinik approve), SPN4 pilot, flag-gated; no supervised clinical amendment (DPT-Q4 not built) | rule 219 §6 agrees; Sprint 59 editing of finalized records differs from the memo | `MedicalRecordFinalizationTest`, `RmeExamConsentCorrectiveTest`, `RmeInvoiceCorrectionLifecycleTest` | PARTIAL (needs owner decision) |
| M4.1 | "RME Baru" shows the next RM number automatically, unique, no manual typing | The number part is typed by FO and never generated (`PatientMedicalRecordNumberService`: "NOT auto-generated and NOT auto-padded", Sprint 23.8 owner-locked format); master form also accepts a free override (CC1-PAT-1); a fallback `DG-YYYYMM-NNNNNN` generator uses UTC time and no lock; DB unique index, no unique-violation handling | AIR-PAT-11 / rule 110 §4 (format) agree; the Sprint 23.8 manual-number decision — **conflict** | `PatientMedicalRecordNumberTest` ("does not auto-generate or auto-increment the manual number") pins the opposite | CONFLICTING (MISSING) |
| M4.2 | All administrative identity fields mandatory | Required: name (+ branch and number on the visit form); optional: gender, date of birth, phone, WhatsApp, NIK, email, occupation, address | rule 184 §10 forbids making a KTP field required — **conflict** for NIK only | `PatientKtpNewPatientOnlyGovernanceTest` | REQUIRES OWNER DECISION |
| M4.3 | KTP scan/upload stays optional | `ktp_scan_token` nullable on both create requests; capture only for a new patient | AIR-PAT-30 / rule 184 §10 agree | `PatientKtpNewPatientOnlyGovernanceTest` ("registers repeat visits for an existing patient who has no KTP archive") | IMPLEMENTED |
| M4.4 | Before–after clinical photos with access control | None in RME (lab orders have case photos on the clinical disk, tied to a lab order). The private-disk and policy-gated streaming pattern exists (rule 121, rule 224) | rules agree; Foundation-First lock: a new feature is post-foundation backlog unless the owner authorizes it | none | MISSING |
| M5.1 | FO/Kasir edit the admin and payment part, lab fees, transfer instalments without a visit | Instalments without a new visit and Bank Transfer payments work ("Bayar Cicilan"); the transfer reference is optional; no lab-fee line category; an issued invoice is immutable and changes only through maker-checker correction (pre-payment, SPN4 pilot, flag OFF by default; post-payment correction OFF) | rules 188/189/194 — direct editing **conflicts**; instalments and transfer agree | `RmePaymentTest` (partial, second payment, method), `RmeManualPricingInvoiceTest` (no edit route), `RmeInvoiceCorrectionLifecycleTest` | PARTIAL |
| M5.2 | Payment/cashier workflow needs further discussion | Canonical workflow: rules 162, 188, 189, 194, 199, 200, 205, 207; corrections 190–202; refunds 213–216 (flags OFF); Rp0 217–219 (flag OFF); pending owner decisions D21c, D21g/D22, D23, D24, PP-10 | organizational | — | REQUIRES OWNER DECISION |
| M6.1 | Clarify who migrates the old paper records (IT partner with a dentist) | Organizational. The legacy RME program provides the tooling (rule 100; WAVE-4 confirmations are D5) | — | — | REQUIRES OWNER DECISION (organizational) |
| M6.2 | Storage capacity and possible extra cost | Free-space thresholds only: `pilot:performance-snapshot` (`disk_free_gb`), `foundation:health-check` (`storage_free_percent`), legacy ingestion stop below 2 GiB; external alerting OFF; no usage by category; the daily backup covers the database only; object storage OFF | rule 112 (AIR-MON-22), rule 121, STORAGE-R001..R005 agree | `PilotPerformanceSnapshotResourceSectionTest` | PARTIAL (cost: owner decision) |
| C4 | Management and IT evaluate and test before full rollout | Organizational; clinic reopening stays a separate owner decision (CG-14); D4 device ceremony BLOCKED_EXTERNAL | — | — | REQUIRES OWNER DECISION (organizational) |

## 3. Findings raised by the memo audit (defects or drift under the current rules)

| Id | Finding | Sev | Disposition |
|---|---|---|---|
| MEMO-F1 | A doctor keeps patient access through an `auto_visit` assignment after FO moves the pre-exam visit to another room | M | with the M2.1 decision |
| MEMO-F2 | RME dashboard payment totals visible to Doctor and Perawat (no billing permission check) | M | proposed fix: show the two cards only with `manage_rme_billing` or `view_rme_payment_reports` |
| MEMO-F3 | The fallback patient code generator derives its month from UTC time with no lock (CG-12) | L | the patient-identity sprint (with CC1-PAT) |
| MEMO-F4 | `RmeVisitConsentService::sign()` comment justifies the optional doctor signature with "the doctor may not be at the cashier", which predates consent at `in_progress`; the consent policy docblock says "every non-terminal visit" | I | doc drift, corrected with the M1 decision |
| MEMO-F5 | `rme/visits/show.blade.php` queries `$visit->consents()` in Blade (CG-04) | L | next consent UI change |
| MEMO-F6 | `RoleSeeder` comment cites `Sprint66FrontOfficeRoleTest`; the file is `tests/Feature/AccessControl/FrontOfficeRoleTest.php` | I | doc drift |

## 4. Technical work versus organizational follow-up

- **Technical, can proceed once the owner decides:** M1.1–M1.3, M2.1, M2.2, M2.4, M2.5 (MEMO-F2), M3.1, M3.2,
  M3.3, M4.1, M4.2, M4.4, M5.1 (transfer reference, lab-fee line), M6.2 (a read-only usage report).
- **Organizational, needs people:** M5.2 (payment workflow discussion), M6.1 (migration responsibility between the
  IT partner and the clinic's dentist), M6.2 cost and budget, C4 (management testing before rollout), and the
  measurements only the owner can run (CC1-PAT count, disk usage, §10 of the parent record).

## 5. Owner decisions

Recorded in the parent record §2 as they are given. No canonical workflow changes before its decision (CG-22).

## 6. Reconciliation at production `417d788c` (2026-11-27, rule 251)

**Status:** accepted by the owner (decision "RULE 251 MERGE AND NEXT CRITICAL PATH", 2026-11-27) at its documented
evidence level. §1–§2 above are kept unchanged as the release-1 audit at `cab3a4c1`. This section supersedes their
status column for progress reporting under rule 251 (`docs/governance/mandatory-progress-reporting-policy.md`).

**Evidence base:**
- **Code:** the four program releases are ancestors of the production SHA `417d788c`. Each was verified with `verify`
  → `DEPLOY RUNNER OK` (parent record §9.3, §9.6, §9.8, §9.10):
  - release 1, `cab3a4c1`;
  - release 2, `0f3c3bb3`: #655 and #656;
  - release 3, `4f59c347`, memo wave 1: #660 and #662–#668;
  - release 4, `988c1a9a`, memo wave 2: #669–#671.
- **No change since release 4:** the application changes after `988c1a9a` (#689, #690, #699, #702) do not change memo
  behaviour.
- **Flags:** as stated by the release 4 record (parent record §9.10), **not independently re-verified on the
  production host**:
  - `rme.doctor_encounter_scope` OFF;
  - `rme.clinical_photos` OFF;
  - consent template v2026.2 inactive.
- **User acceptance:** the owner-run production UAT of 2026-11-19, Phase 1, run with test identities only.
  - Its verdict is "executed, NOT GO". Items are cited by number. The record is held by the owner and is not
    published.
  - Items 1, 2, 4 and 5 are REVIEW REQUIRED (the front-office test account held another role when they ran).
  - Items 12–14 are NOT RUN (Phase 2).
  - A test is never counted as user acceptance.

**Counting:**
- **Implemented:** code and pinned tests exist for the owner-approved scope (parent record §2c).
- **Deployed:** code present in the verified production SHA.
- **Active:** deployed and enabled. An item behind an OFF flag is deployed but not active.
- **UAT:** every behaviour of the requirement PASS in the production UAT. REVIEW REQUIRED, NOT RUN and partial coverage
  count 0.
- **Closed:** deployed and UAT PASS, or an auditable owner disposition. None exists yet.
- **Denominator:** 20 for every measure. Nothing is excluded.

| Id | Implemented | Evidence | Deployed / active | UAT | Closed |
|---|---|---|---|---|---|
| M1.1 | yes | rule 241, #663–#665 | yes / yes | PASS (items 7, 9) | yes |
| M1.2 | yes | rule 241 | yes / yes | PASS (items 6, 8; front-office role) | yes |
| M1.3 | yes | rule 241 | yes / yes | PASS (items 7, 9). Limit: the odontogram page itself was not opened; the gate message names both | yes |
| M2.1 | **partial** | rule 243 §2, #668, #671. Two code changes that rule 243 lists as "Preconditions for ON" are still open: (a) a book amendment's requester is locked out once out of service, because `MedicalRecordPolicy::viewAnchorContent` still starts from the patient-level `view`; (b) the author-stamp filter covers only the book | no (partial) / **no** (encounter-scope flag OFF) | NOT RUN: doctor UAT is a precondition of turning the flag on | no |
| M2.2 | **partial** | rule 243 §2; the same two open preconditions | no (partial) / **no** (same flag) | NOT RUN | no |
| M2.3 | yes | rules 227, 228 §5.2; the front-office single-branch part is ACCEPTED BY DESIGN, with owner confirmation not recorded | yes / yes | NOT RUN | no |
| M2.4 | yes | rule 243 §1, #660 (not flag-gated) | yes / yes | NOT RUN | no |
| M2.5 | yes | rule 238, #655 | yes / yes | NOT RUN | no |
| M3.1 | yes | rule 243, #666, #669. The full doctor biodata (birth place, date and age; occupation; gender) is on the medical-record page; the visit page shows name, record number and age, without contact data | yes / yes | PARTIAL: items 10 and 16 PASS. Item 10's expected result named the visit page, but those fields render on the medical-record page, so re-test there; occupation and planned treatment not checked | no |
| M3.2 | yes | rule 238, #655 | yes / yes | NOT RUN | no |
| M3.3 | yes | rule 240, #667 | yes / yes | PARTIAL: items 11 and 18 PASS; amendment request and approval (items 12–14) NOT RUN | no |
| M4.1 | yes | rule 244, #654, #666 | yes / yes | REVIEW REQUIRED (items 1, 2) | no |
| M4.2 | yes | rule 244, #666, #669 | yes / yes | item 3 PASS; items 2 and 4 REVIEW REQUIRED | no |
| M4.3 | yes | pre-existing (KTP scan optional) | yes / yes | NOT RUN | no |
| M4.4 | yes | rule 242, #670 | yes / **no** (clinical-photo flag OFF; consent template inactive) | NOT RUN: item 15 shows only the absence of the section with the flag OFF | no |
| M5.1 | **partial** | instalments and transfer reference (#655). The lab-fee line depends on RME-LAB-FEE-APPROVAL-1 (Gate 1 NO-GO, rules 245–249 not implemented); invoice editing stays immutable by owner decision | no | NOT RUN | no |
| M5.2 | **no** | REQUIRES OWNER DECISION (payment-workflow discussion) | no | NOT RUN | no |
| M6.1 | **no** | organizational; no approval evidenced | no | NOT RUN | no |
| M6.2 | **partial** | read-only usage report (rule 239, #656). The report has no clinical-photo category, which rule 239 §2 requires of the change that writes photos; the cost from a verified rate is an owner action | no | NOT RUN | no |
| C4 | **no** | organizational; management UAT not held | no | NOT RUN | no |

| Measure (rule 251) | Completed / Total | % |
|---|---|---|
| Implementation | 13 / 20 | 65.0 |
| Production deployment (code present in production) | 13 / 20 | 65.0 |
| Active production (supplementary: deployed and enabled) | 12 / 20 | 60.0 |
| User acceptance | 3 / 20 | 15.0 |
| Overall closure | 3 / 20 | 15.0 |

**Historical progress:** the release-1 audit at `cab3a4c1` (§1) recorded 2 of 20 IMPLEMENTED (10.0 %). The rise to
13 of 20 is actual implementation progress of **+55.0 percentage points** (releases 2–4), not a correction of the count.

**ERRATUM (2026-11-27, before this section was merged):** the figures first presented to the owner counted M2.1 and
M2.2 as implemented. That gave implementation and deployment 15/20 (75.0 %) and the historical rise +65.0 pp. A
code-level check found the two rule 243 "Preconditions for ON" still open, so both are partial. The corrected figures
are 13/20 (65.0 %) and +55.0 pp. Active production (12/20), user acceptance (3/20) and closure (3/20) are unchanged.
This is a correction of the count, not a regression. The owner's acknowledgement of the corrected figures is
pending.

**Remaining dependencies:**
- **Re-runs and Phase 2:** re-test UAT items 1, 2, 4 and 5 with the front-office role; run Phase 2 (items 12–14).
- **Missing UAT:** items for M2.3, M2.4, M2.5, M3.2 and M4.3, and the missing parts of M3.1.
- **M2.1 and M2.2:** the two open rule 243 "Preconditions for ON", each with its test, before any flag decision.
- **Flags:** separate owner decisions to enable the encounter scope (M2.1, M2.2; after the preconditions, a reviewed
  dry run and doctor UAT, rule 243 §2) and clinical photos (M4.4). A disabled feature is never counted as
  user-accepted.
- **M5.1:** the payment discussion, and the LAB_FEE approval program. Its Gate 1 NO-GO is not bypassed.
- **M5.2:** the payment-workflow discussion.
- **M6.2:** the clinical-photo category in the usage report (rule 239 §2), and the verified storage rate.
- **M6.1 and C4:** the organizational approvals.
