---
paths:
  - "app/Modules/ClinicVisit/**"
  - "app/Modules/MedicalRecord/**"
  - "app/Modules/Odontogram/**"
  - "app/Modules/Consent/**"
  - "app/Modules/ClinicalPhoto/**"
  - "app/Modules/Prescription/**"
  - "app/Modules/PerformedTreatment/**"
  - "app/Modules/RME/**"
  - "app/Modules/RmeDashboard/**"
  - "app/Modules/Treatment/**"
  - "app/Modules/TreatmentCategory/**"
  - "resources/views/rme/**"
  - "tests/Feature/RME/**"
  - "tests/Feature/Clinical/**"
  - "tests/Feature/RmePerformedTreatment/**"
---

# RME clinical workflow — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

Canonical flow: Pendaftaran → Antrian → Input Ruangan → Dokter "Mulai Pemeriksaan" (`in_progress`) → PTM: pasien
(front office), lalu dokter countersign (RME, odontogram + finish unlocked) → RME + Odontogram → Dokter "Selesai
Pemeriksaan" (`cashier_pending`) → Kasir / Pembayaran, no consent check (`completed`).

## Invariants

### Visit status machine

- **AIR-RMC-01** `ClinicVisit::VALID_TRANSITIONS` stays `registered→{waiting,cancelled}`,
  `waiting→{in_progress,cancelled}`, `in_progress→{cashier_pending,cancelled}`, `cashier_pending→{completed}`;
  `completed`/`cancelled` terminal. Never an `in_progress→completed` edge; `TransitionStatusRequest` never accepts
  `completed`. Archive Sprint 62.1; code `ClinicVisit`.
- **AIR-RMC-02** Reach `completed` only through `RmePaymentService`, from `cashier_pending`, after a cashier payment;
  `ClinicVisitService::transitionStatus()` refuses `completed` from any other status. Add no manual path. Payment
  rules live in `.claude/rules/rme-billing.md`. Rule 108 §11,
  rule 109 §1.
- **AIR-RMC-03** Pass an explicit, persisted, active `User $actor` to every actor-dependent `ClinicVisitService`
  method; `transitionStatus()` accepts a null actor only for `completed`. The service contains no `Auth::`, `auth()`,
  `request()`, `session()` or `BranchContext`; usability and abilities come from `ClinicVisitActorGuard` asking
  `ClinicVisitPolicy`. Rule 199 §B–C.
- **AIR-RMC-04** "Selesai Pemeriksaan" (`rme.visits.transition` to `cashier_pending`) requires
  `complete_rme_examination` (Doctor, Perawat, Supervisor RME; Super Admin via `Gate::before`) **and** an EXECUTED
  consent (patient + treating-doctor countersignature) on that visit, both re-asserted in
  `ClinicVisitService::transitionStatus()`. Admin Klinik and Kasir never close an examination. Several buttons may
  post to the one guarded route; there is no second path. Rule 109 §1–1a, rule 134 §5.
- **AIR-RMC-05** Never let a clinical document complete an examination: finalizing a medical record, saving
  handwriting, or saving/finalizing an odontogram leaves the visit `in_progress`. `MedicalRecordService` must not
  depend on `ClinicVisitService`. If the doctor never clicks "Selesai Pemeriksaan", `RmeInvoiceService::create()`
  (which requires `cashier_pending`) cannot run — that is intended. Rule 109 §1.

### Consent

- **AIR-RMC-06** Allow treatment-consent signing only while the visit is `in_progress`
  (`RmeVisitConsentService::assertSignable()`); refuse `registered`, `waiting`, `cashier_pending`, `completed` and
  `cancelled` server-side (supersedes rule 100 §2 "any non-terminal visit"). Rule 109 §2a.
- **AIR-RMC-07** Gate every current-RME write on positive authority,
  `RmeVisitConsentService::assertRmeAuthoringAllowedForPatient()`: exactly one current encounter
  (`ActiveEncounterResolver`), it is `in_progress`, the resolvable actor may work on it, and an EXECUTED consent
  (AIR-RMC-55) exists for that same encounter, re-decided on the visit row locked first inside the write. No
  encounter, two `in_progress` visits or a null actor fail closed. Covered writers:
  `MedicalRecordService::createDraft/updateDraft/finalize/getOrCreateCanonicalMedicalRecord`,
  `MedicalRecordDiagnosisService`, `DiagnosisRolloutService::grantOverride`, `MedicalRecordHandwritingController`;
  performed treatments use their own authority (AIR-RMC-36, AIR-RMC-55). Rule 109 §2b.
- **AIR-RMC-08** Never derive authoring authority from `trx_medical_records.clinic_visit_id` (the canonical book
  belongs to an old, finished visit) or from the request (`{clinicVisit}`, `source_visit_id`, `?sheet=`). A cancelled
  or completed encounter never makes the canonical record writable again (cancel-then-author and
  complete-then-author were real reproducers). Rule 109 §2b.
- **AIR-RMC-09** Never consent-gate a read: patient history, earlier sheets, previous odontograms and published
  Legacy RME/Odontogram archives stay readable with or without an encounter. Prescriptions are deliberately outside
  the consent gate. Rule 109 §2, §2c.
- **AIR-RMC-10** Read consent only from persisted evidence (`RmeVisitConsentService::hasValidConsent()`,
  `ClinicVisit::hasSignedConsentDocument()`), never from a request value. `trx_clinic_visits.consent_signed_by_patient`
  / `_doctor` are display-only history and authorize nothing. A list that calls a consent predicate per row eager-loads
  `consents`. Rule 100 §1, §1b.
- **AIR-RMC-11** Keep consent out of payment: `RmePaymentService`, `CreateRmePaymentRequest`, the payment page and
  the cashier handoff never consult, show or wait for consent, and historical receivables without consent stay
  collectible (supersedes the Sprint 20-era "consent is the payment gate"). Rule 109 §3, rule 100 §1a.
- **AIR-RMC-12** Treat a signed consent as immutable evidence: no update path, no hard delete; correction is void with
  a reason plus re-sign, and `validForVisit()` returns the latest non-voided consent by `signed_at`, then `id`.
  Snapshot wording from `config/rme_consent.php` and bump its `version` when substance changes; never rephrase the
  clinic's clause text. Clause 8 (v2026.2: clauses 8 and 9) is an explicit YA/TIDAK — required by the service, bound
  `nullable|boolean` by the request, never defaulted — and never blocks treatment or payment. Rule 100 §4–5,
  rule 109 §2b, rule 241 §6.
- **AIR-RMC-13** Gate consent routes on `manage_rme_consents` / `view_rme_consents` (capture: Front Office, Kasir,
  Admin Klinik, Supervisor RME fallback; the Doctor reads and countersigns, rule 241 §4), never on
  `manage_clinic_visits` (Kasir lacks it). Keep identity numbers and signature paths `$hidden`; only the consent
  document renders a full identity number; signatures stay on a private disk streamed through the consent policy.
  Rule 100 §6–7.

### Odontogram

- **AIR-RMC-14** Chart the ACTIVE odontogram only on an EXECUTED consent (SUPERSEDES the rule 134 pre-consent
  charting for writing). `RmeVisitConsentService::assertOdontogramAuthoringAllowed()` is the single authority (one
  `in_progress` encounter, actor may work on it, chart belongs to that encounter, executed consent re-decided on the
  visit row it locks before the chart), asserted before any insert by
  `OdontogramService::saveForVisit/updatePlaceholder/finalize`. Add no second consent check in a controller,
  FormRequest, Blade or JS. Saving never signs consent or changes visit status; reading stays open. Rule 241 §2.
- **AIR-RMC-15** Keep `GET rme/visits/{clinicVisit}/odontogram` write-free: `OdontogramController::show()` uses
  `OdontogramService::draftForVisit()` (saved chart or unsaved instance); the first row is created by
  `rme.visits.odontogram.store` → `saveForVisit()`. `getOrCreateForVisit()` keeps zero production callers (pinned by
  `RepositoryArtifactHygieneTest`). Branch on `$odontogram->exists` before `route()` with the model. Source:
  rule 110 §1.
- **AIR-RMC-16** Keep a previous visit's chart permanently read-only — no consent or pre-consent permission reopens
  it — and a finalized chart is locked on any encounter (AIR-RMC-57). Enforce in `OdontogramService`, not by hiding
  controls; `OdontogramPolicy::update` answers role/branch/patient scope, then denies only a finalized chart. Source:
  rule 109 §4, rule 134 §2, rule 240 §1.
- **AIR-RMC-17** Serve "Riwayat Odontogram Pasien" via `OdontogramService::patientHistoryForVisit()`: patient-centric,
  scoped to the RME branch set plus `DoctorPatientScopeService`, excluding the current visit and empty drafts
  (`Odontogram::hasRecordedTeeth()`), with no mutation control, rendered outside the
  `x-data="odontogramEditor(...)"` wrapper. Missing doctor or branch renders as an em dash. Rule 109 §4.

### Room, doctor scope and print denial

- **AIR-RMC-18** Room gate: `visit.room` (`EnsureVisitRoomAssigned`) guards medical-record and odontogram routes and
  redirects a roomless pre-exam visit to `rme.visits.show` (`cashier_pending` and terminal visits exempt). Only
  `ClinicVisitService::assignRoom()` (`rme.visits.assign-room`) writes the room; it supplies the doctor for a
  front-desk registration (which drops `doctor_id`); any other registrar names a doctor online at the visit's branch
  (`UserOnlineContextService::assertDoctorSelectableForVisit()`). Registration never writes `clinic_room_id`; the
  visit edit is the allow-list `ClinicVisitService::EDITABLE_FIELDS`. Hotfix Sprint 60.8; rule 199 §14a; rule 200
  §17–18.
- **AIR-RMC-19** Doctor room isolation only via `DoctorRoomScopeService` (RME module): the room comes from the
  doctor's live online context (`trx_user_online_contexts`), never the request; it restricts only
  `ClinicVisit::PRE_EXAM_STATUSES`; no resolvable room ⇒ no active visit; history stays readable. `applyRoomScope()`
  and `deniesActiveVisitOutsideRoom()` stay mirror images. Owner, Super Admin, Supervisor RME exempt
  (`DoctorPatientScopeService::shouldApplyDoctorScope()`). Never room-guard `completeExamination`. Rule 138 §1–5.
- **AIR-RMC-20** A scoped Doctor (rule 138) is denied `print` on `rme.visits.print`, `rme.visits.pdf`
  (`ClinicVisitPolicy::print`) and `rme.odontograms.print` (`OdontogramPolicy::print`; `@can('print')` hides the
  buttons, no UI-only check) and refused (403, audited) the PTM print `rme.consents.print` and legacy
  print/export/source (`DoctorRoomScopeService::assertClinicalPrintAllowed()` after the policy; oversight legacy doors:
  controller + service). Screen reading, prescription print and FO/Admin Klinik/Supervisor RME printing stay.
  `DOCTOR_ROOM_ACCESS_REJECTED`, `DOCTOR_PRINT_RME_REJECTED`, `DOCTOR_PRINT_ODONTOGRAM_REJECTED` are audited from
  controllers only, never a policy. Rule 138 §6–7; rule 243 §1.
- **AIR-RMC-21** Aggregate visit data (RME dashboard, visit widgets) over
  `ClinicVisitService::scopedBranchIdsFor($actor)`, never `BranchService::rmeEnabledIds()`;
  `patientVisitHistory()` stays cross-branch by design because callers authorize the anchor visit first. Rule
  199 §E. `adjacentVisits()` (prescription/odontogram arrows) takes the viewer and the page's own ability and offers
  only the nearest visit that viewer may open. Rule 227 §1.8c.

### Medical record workspace

- **AIR-RMC-22** Handwriting RM is the primary clinical input; SOAP stays hidden from the doctor UI (old values show
  only as the read-only "Data SOAP (Legacy)" card) — never re-open SOAP input. Finalization requires handwriting
  (`MedicalRecordService::hasRequiredHandwriting()`); a final sheet is locked (AIR-RMC-57). Source: archive Sprint 20
  closure; rule 109 §2b; rule 240 §1.
- **AIR-RMC-23** Resolve the patient-centric book with `PatientRmWorkspaceResolver`: one sheet per visit
  (`UNIQUE(clinic_visit_id)`), canonical anchor = earliest non-cancelled RME visit, any other visit redirects to the
  canonical URL with an IDOR-validated `?source_visit_id=`; the handwriting book is the earliest self-anchored sheet
  (`bookOn()`, rule 237 §16). Per-visit actions use the active sheet's visit. Archive Sprint 64.0; rule 100 §8.
- **AIR-RMC-24** Keep Medical Record page order Informasi Kunjungan → RME Tulisan Tangan → Dokumen RME Pasien, with
  exactly one Finalisasi and one Cetak RME, both in its header. Cetak RME is not on Detail Kunjungan (front office
  uses the Rekam Medis link); moving a button never adds a print endpoint. Rule 100 §8–9.
- **AIR-RMC-25** RME authoring controls follow the capability, not a permission: the controller passes
  `RmeVisitConsentService::canAuthorRmeForPatient()` (odontogram page: `canAuthorOdontogramFor()`); never
  `can('create', MedicalRecord)` alone, never consent re-derived in Blade. A not-yet-allowed "Buat Halaman RM Pertama"
  renders disabled with its reason and no `<form>`. A UI/server mismatch resolves to UI DENY + server DENY, never a
  relaxed service. Rule 135; rule 134 §6.
- **AIR-RMC-26** `/rme/medical-records` is a read-only unified index: one row per patient with a readable native
  record or a PUBLISHED legacy RME/odontogram, each source under its own canonical scope (composed, never copied) plus
  `DoctorPatientScopeService`, paginated after the union. A legacy archive never becomes a `MedicalRecord` or a visit;
  `rme.medical-records.patients.show` answers 404 outside the actor's index. Rule 173.

### Print and evidence

- **AIR-RMC-27** `rme.visits.print` and `rme.visits.pdf` share `rme.visits.partials.print-body` (change once, never
  fork): no `Data Pasien & Kunjungan` card or cells, no odontogram (standalone `rme.odontograms.print`), the wrapper
  `<title>`, footer and logo kept, no empty `section-block`/`info-grid`. "Remove X from the print" never touches data,
  schema or the detail screen. Rule 127; rule 100 §9.
- **AIR-RMC-28** Print/PDF embeds handwriting as inline base64 (`orderedHandwritingPages(true)`), never a link (dompdf
  cannot authenticate). Handwriting and prescription/doctor-signature canvases are written only through
  `App\Support\Storage\ClinicalEvidenceStorage` (never `disk('public')` or `storeAs(..., 'public')`) and read through
  policy-gated controllers, with no fallback disk. Rule 121.
- **AIR-RMC-29** Render the structured odontogram print through `OdontogramPrintFormatter` and
  `rme/visits/odontogram/partials/structured-print-template.blade.php`: HTML table cells, no flexbox, DMF-T from
  `Odontogram::dmftCounts()` (an unknown status never inflates it). Archive Sprint 63.1.

### Registration, visit detail, dates, prescriptions

- **AIR-RMC-30** "Jenis Kunjungan" is server-derived: `StoreClinicVisitRequest::prepareForValidation()` overwrites any
  client `visit_type` — Kontrol only with an explicitly selected `follow_up_of_visit_id`, else Baru. An invalid,
  cross-patient, non-RME or soft-deleted prior visit is rejected, never downgraded; history alone never makes Kontrol;
  `continued_treatment`/`emergency` stay in data but are not creatable. Rule 185 §C.
- **AIR-RMC-31** Render Detail Kunjungan clinical cards in Blade source order Odontogram → Rekam Medis → Resep (never
  CSS `order-*`), each keeping its own `@can`; front-office-only accounts see none of them. Rule 185 §A.
- **AIR-RMC-32** Clinical dates come from `ClinicalClock` (CG-12): `visit_date` (it also drives queue and visit
  numbers), the RM year in `PatientMedicalRecordNumberService::composeForRegistration()`, the RM pre-check in
  `StoreClinicVisitRequest`. A supplied date is used verbatim; an existing patient is never renumbered. Daftar
  Kunjungan defaults to the clinical day. Rule 110 §4, rule 108 §13–14, rule 92.
- **AIR-RMC-33** WhatsApp a prescription only as an explicit, confirmed operator action under
  `send_prescription_whatsapp` (`rme.prescriptions.whatsapp.send`): Meta Cloud API, approved utility template, HTTPS
  host allowlist, no redirects, token never logged or stored, idempotent per prescription + recipient + template, no
  diagnosis or KTP/NIK in the message; a failure never changes the prescription or visit. Rule 108 §15–22.

### Testing

- **AIR-RMC-34** Prove these gates server-side (service/HTTP outcome and the rendered form, never a label). Active-RME
  fixtures build a real encounter (`rmeActiveConsentedEncounter()` / `rmeConsentedOdontogramVisit()`);
  `rmeSignedConsentFor()` builds an EXECUTED consent (fixture countersignature) but alone authorizes nothing;
  two-signature gate tests build state through the services; consent tests go through `RmeVisitConsentService`. Pinned
  suites stay in `critical_gate_mandatory_suites` (CG-19). Rule 109 §6, rule 134 §7, rule 135 §7.
- **AIR-RMC-35** Consent identity cannot be borrowed: the treating doctor comes from the visit, never from the request,
  and "Saya sendiri" copies the patient's `mst_patients` identity, which typed input cannot override. Rule 100 §3.
- **AIR-RMC-36** Performed treatments only via `RmePerformedTreatmentService` (rule 219): treating doctor,
  `in_progress`, EXECUTED consent (rule 241 §2), visit row locked first, append-only rows voided with a reason. Empty =
  `NOT_RECORDED`; only "Tidak Ada Tindakan" is operational KONTROL: never rewrites `visit_type`, creates a parent, makes
  anything free (rule 217) or transitions the visit. A covering doctor must first be the assigned doctor; no
  post-completion edit. Flag `rme.doctor_performed_treatments` default OFF: ask `enabledFor($visit)` (fail-closed
  `RmePerformedTreatmentPilotScope` allowlist), never the bare flag, re-decided on the LOCKED visit. Pilot posture,
  DPT-Q2…Q10: rule 219 §14–§15. Consent void = `RmeVisitConsentService::void()`.
- **AIR-RMC-37** Governed RME route keys (`{clinicVisit}`, `{medicalRecord}`, `{rmePrescription}`, `{consent}`,
  `{odontogram}`, `{diagnosis}`, `{handwriting}`, `{handwritingPage}` = the page ROW key) are constrained globally by
  `App\Support\Routing\DatabaseRouteKey` (first in `AppServiceProvider::boot()`): a non-bigint segment answers 404,
  never a PostgreSQL 500, for any signed-in account. Never a per-route `where`/`whereNumber` for them; a generic name
  is constrained per route. Rule 222 §1.
- **AIR-RMC-38** Decide a visit's status, room and consent window on the LOCKED row:
  `transitionStatus()`, `assignRoom()` and `RmeVisitConsentService::sign()` take
  `ClinicVisitRepositoryInterface::lockForStatusDecision()` (FOR NO KEY UPDATE, never FOR UPDATE: FK inserts must not
  wait or deadlock) and re-check the map / `isTerminal()` / `assertSignable()` on that row before writing. Lock the
  visit row first, always. Rule 223 §1.
- **AIR-RMC-39** Detail Kunjungan "Buat Rekam Medis" (`canCreateRecordHere`), the Rekam Medis page "Selesai
  Pemeriksaan" (`transition` + `completeExamination` + EXECUTED consent), the handwriting editor and the diagnosis forms
  (`canAuthorRme`) follow the capability, never a permission alone. Rule 223 §2.
- **AIR-RMC-40** The front-desk-only tier (`FrontOfficeRole::isVisitFrontDeskOnly()`: Admin Klinik / Front Office
  without a clinical role) never authors the active RME or odontogram; the refusal sits in
  `RmeVisitConsentService::actorMayWorkOnEncounter()`. Perawat/Supervisor RME authoring and front-office exam start
  are owner decisions, not settled here. Rule 223 §3.
- **AIR-RMC-41** Store a new sheet's `source_visit_id` only after `PatientRmWorkspaceResolver::resolveSourceVisit()`;
  never a raw request id. Rule 223 §4.
- **AIR-RMC-42** A handwriting page or prescription canvas row and its private object are one unit: validate first,
  then one transaction (draft if needed, authorize, lock the parent FOR NO KEY UPDATE, write a fresh random-suffixed
  object, write the row); a failure removes only this call's object. Never draft before validation, build a key from
  request input, or delete committed evidence. Rule 224 §1–5.
- **AIR-RMC-43** Name a controller's route-model argument after its route segment (`{rmePrescription}` →
  `$rmePrescription`); a mismatch injects an empty model. `ClinicalEvidenceStorage::dataUri()` is null for an absent,
  unreadable or non-PNG object and print renders "Gambar halaman ini tidak tersedia." instead of an empty `<img>`.
  Rule 224 §6–7.
- **AIR-RMC-44** A racing insert that may lose a unique index runs in a nested `DB::transaction()` (SAVEPOINT); catch
  `UniqueConstraintViolationException` outside it and re-read the winner, never inside the aborted transaction
  (25P02). Visit numbers: `lockVisitSequence($branchId)` (advisory 92_001), then `withTrashed()` MAX; a row lock
  cannot serialise `MAX + 1`. Rule 225 §1–4.
- **AIR-RMC-45** A prescription write (create, update, R/ canvas, doctor-signature canvas) follows the clinical
  authoring authority: never the front-desk-only tier, only in the actor's working branch
  (`RmeWorkingBranchScope::allows()`), doctor-patient scope for a Doctor. `PrescriptionAuthoringAuthority` is the one
  predicate, read by the policy and re-asserted by `RmePrescriptionService` before any lock or canvas write. No
  consent rule (room: AIR-RMC-50; lock, signer: AIR-RMC-53). Rule 226 §1.
- **AIR-RMC-46** Clinical documents (MR + handwriting images, prescription page/canvas/print/history, odontogram) are
  read under `RmeWorkingBranchScope::allowsClinicalDocument()`: Kasir / Admin Klinik / Front Office only in their
  working branch (none without one); clinicians and Owner keep the RME set; never wider. Rule 227 §1.
- **AIR-RMC-47** The printed prescription dentist and patient are server-derived (visit on create, the prescription's
  own doctor/patient on update); never request fields; no doctor means refused. Rule 227 §2.
- **AIR-RMC-48** Prescription authoring = `manage_clinic_visits` + a role in `PrescriptionAuthoringAuthority::
  AUTHORING_ROLES` (an allowlist; runtime roles do not inherit it) + working branch, re-asserted in the service. Test
  authors use `rmeClinicalAuthor()`. Rule 227 §3.
- **AIR-RMC-49** RME invoice / payment / consent numbers are read after `lockNumberSequence($month)` (advisory 92_002 /
  92_003 / 92_004, objid YYYYMM, never renumbered) and invoice/payment MAX counts soft-deleted rows. Rule 227 §4.
- **AIR-RMC-50** The doctor room scope (rule 138) binds prescriptions and the live-encounter write predicate
  (`actorMayWorkOnEncounter`): never an ACTIVE visit of another room; history unaffected. Rule 228 §1–§2.
- **AIR-RMC-51** Only `ClinicVisitPolicy::startExamination()` moves a visit to `in_progress` (front desk keeps cancel); a
  `cashier_pending` room move keeps the doctor. Rule 228 §3–§4.
- **AIR-RMC-52** RME/odontogram authoring needs a `FrontOfficeRole::CLINICAL_ROLES` role; cross-branch document read is
  `CROSS_BRANCH_DOCUMENT_READ_ROLES` (clinical + Owner), else none. Rule 228 §5.
- **AIR-RMC-53** A completed/cancelled visit's prescription is read-only (`documentLocked()`: policy, and the service
  under the visit lock); each write logs `RME_PRESCRIPTION_CREATED|UPDATED` (field names only); canvases and consent
  signatures are bounded; only the prescription's own doctor (`maySign()`) saves the signature. Rule 228 §8–§13.
- **AIR-RMC-54** The doctor countersigns the PTM only via `RmeVisitConsentService::countersign()`: own insert-only
  row (UNIQUE per consent), visit locked first, `in_progress`, live consent, linked active doctor = the visit's and the
  consent's doctor (binds Super Admin), never the patient-signature capturer. Rule 241 §1; the gate is AIR-RMC-55.
- **AIR-RMC-55** Gate every clinical write — RME sheet/page/diagnosis/override, performed treatments, odontogram,
  "Selesai Pemeriksaan" — on an EXECUTED consent (`hasExecutedConsent()`: live consent + treating-doctor
  countersignature) inside the single authorities, re-decided on the visit row locked first; reads, payment,
  prescriptions stay outside; a void reopens it. The Doctor no longer captures the patient signature. Rule 241 §2–§4.
  A supervised amendment stays outside (it corrects an existing record; its page shows the visit's PTM read-only;
  never fake a consent); a new act takes exam + PTM. Rule 240 §6.11.
- **AIR-RMC-56** Sign a NEW consent on template v2026.2 (clause 8 documentation, clause 9 publication: two explicit
  YA/TIDAK) only with flag `rme.consent_template_v2026_2` on AND wording `approved` without the draft marker; clause 9
  gates nothing; history unchanged. Source: rule 241 §6.
- **AIR-RMC-57** A final sheet (notes, diagnoses, override) and a finalized odontogram are locked:
  `MedicalRecordPolicy::editSheet` / `OdontogramPolicy::update` (scope first), and the service decides on the row it
  locked after the visit (binds Super Admin), answering before a missing PTM (AIR-RMC-55); correction only by
  supervised amendment, which ships in the same release. Rule 240 §1, §1.8.
- **AIR-RMC-58** Clinical photos only via `RmeClinicalPhotoService` behind `rme.clinical_photos` (OFF: 404, refused):
  Doctor/Perawat allowlist (binds Super Admin), live encounter, EXECUTED PTM with `allowsClinicalDocumentation()`
  (never clause 9), re-encoded before any write, visit locked first; no-store reads in the clinical scope, never
  FO/Kasir/Admin Klinik. Rule 242.

## Current posture (snapshot 2026-11-17 — verify before relying on it)

- Live: pre-consent odontogram charting (ends with the rule 241 §2 release, AIR-RMC-55), consent-gated RME
  authoring and "Selesai Pemeriksaan", consent-free payment, room gate, doctor room isolation, doctor RME/odontogram
  print denial; performed treatments ARMED for SPN4 only (rule 219 §14).
- In code since 2026-11-17 (check the deployed SHA): one valid consent per visit and a supervised void
  (`rme.consents.void`, `void_rme_consents`); consent signatures on `ClinicalEvidenceStorage`; readers keep a temporary
  read-only fallback to the old disk until `rme:consent-signatures-migrate` is applied and verified (rule 237 §14.5a).
- WhatsApp prescription delivery OFF (`DisabledWhatsAppGateway`); `doctor.branch_lock` OFF, so doctor reach is patient
  and room scope (`.claude/rules/doctor-access.md`).
- Cross-branch, cross-doctor Kontrol approved (OD-1, rule 200); receivable carry-over branch-local (OD-1a,
  `.claude/rules/rme-billing.md`).
- Deliberate leftovers: `resolvePrintViewData()` eager-loads (rule 127); Full Suite deferred (CG-18).

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/10-laravel-modular-monolith.mdc` | Module layering, forbidden controller patterns |
| `.cursor/rules/92-canonical-clinical-timezone-date-boundary.mdc` | `ClinicalClock`, clinical vs technical dates |
| `.cursor/rules/100-rme-consent-payment-gate-print-ownership.mdc` | Consent evidence, permissions, PII; MR print ownership |
| `.cursor/rules/108-clinic-ops-working-branch-context.mdc` | Exam completion, visit list date, WhatsApp |
| `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc` | Workflow, consent timing, authoring authority, history |
| `.cursor/rules/110-post-rme-odontogram-stabilization.mdc` | Odontogram GET writes nothing; RM clinical year |
| `.cursor/rules/121-clinical-evidence-private-storage.mdc` | Private-disk evidence; inline print embedding |
| `.cursor/rules/127-rme-print-composition-no-duplicate-patient-card.mdc` | Print composition, shared print-body |
| `.cursor/rules/134-odontogram-pre-consent-authoring.mdc` | SUPERSEDED for writing by rule 241 §2; one authority |
| `.cursor/rules/135-rme-authoring-ui-capability-mirror.mdc` | Controls mirror server capability |
| `.cursor/rules/138-doctor-room-scoped-access-and-print-deny.mdc` | Doctor room isolation, print denial |
| `.cursor/rules/173-unified-medical-record-index.mdc` | Unified native + legacy medical-record index |
| `.cursor/rules/185-rme-visit-detail-invoice-registration-ux.mdc` | Visit detail order, invoice document, visit type |
| `.cursor/rules/199-rme-clinic-visit-branch-authority.mdc` | Explicit-actor visit service, fail-closed reads |
| `.cursor/rules/200-rme-active-user-patient-selection-cross-branch-control.mdc` | Active accounts, registration guard, edit allow-list |
| `.cursor/rules/219-rme-doctor-performed-treatments.mdc` | "Tindakan Dilakukan"; "Tidak Ada Tindakan" = KONTROL ≠ NO_CHARGE |
| `.cursor/rules/222-rme-route-key-constraint-and-failure-log-redaction.mdc` | Route keys (404, never 500); redacted failure logs |
| `.cursor/rules/223-rme-clinical-workflow-integrity.mdc` | Locked-row decisions; mirrored controls; front office never authors |
| `.cursor/rules/224-rme-clinical-evidence-write-integrity.mdc` | Evidence row + object one unit; canvas binding |
| `.cursor/rules/225-rme-concurrent-first-write-and-void-staging-integrity.mdc` | Savepoint racing first writes; visit number lock |
| `.cursor/rules/226-rme-prescription-authoring-authority-and-blade-php-pairing.mdc` | Rx writes follow the authoring authority; Blade PHP pairing |
| `.cursor/rules/227-rme-clinical-document-read-scope-printed-identity-numbering.mdc` | Read scope; printed Rx identity; authoring allowlist; numbers |
| `.cursor/rules/228-rme-clinical-write-authority-and-prescription-integrity.mdc` | Room scope on writes; exam start; role allowlists; Rx lock/signer |
| `.cursor/rules/237-rme-comprehensive-closure.mdc` | Clinic-month numbers; no-store; page bounds; signed Rx; consent integrity; lease; one RM book |
| `.cursor/rules/240-rme-finalized-record-lock-and-amendment.mdc` | Final sheet and finalized odontogram locked; supervised amendment |
| `.cursor/rules/243-rme-doctor-encounter-scope-downloads-fields.mdc` | Doctor encounter scope (flagged), no doctor downloads, doctor-visible fields |
| `.cursor/rules/241-rme-ptm-double-signature.mdc` | PTM double signature: countersignature (§1); both signatures gate writing, UI states, capture roles (§2–§5); consent template v2026.2, inactive until approved wording (§6) |
| `.cursor/rules/242-rme-clinical-photos.mdc` | Clinical photos (flag OFF) |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/01-rme-clinical.md` (Sprint 62.1 and the partial-payment
hotfix are in `docs/sprints/claude-md-archive/02-rme-billing-reporting.md`). Grep by sprint id; never read a whole
file.
