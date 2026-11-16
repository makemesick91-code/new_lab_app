---
paths:
  - "app/Modules/ClinicVisit/**"
  - "app/Modules/MedicalRecord/**"
  - "app/Modules/Odontogram/**"
  - "app/Modules/Consent/**"
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

Canonical flow: Pendaftaran → Antrian → Input Ruangan → Dokter "Mulai Pemeriksaan" (`in_progress`, odontogram
editable) → Persetujuan Tindakan Medis (RME + finish unlocked) → RME + Odontogram → Dokter "Selesai Pemeriksaan"
(`cashier_pending`) → Kasir / Pembayaran, no consent check (`completed`).

## Invariants

### Visit status machine

- **AIR-RMC-01** Keep `ClinicVisit::VALID_TRANSITIONS` as `registered→{waiting,cancelled}`,
  `waiting→{in_progress,cancelled}`, `in_progress→{cashier_pending,cancelled}`, `cashier_pending→{completed}`, with
  `completed`/`cancelled` terminal. Never re-add an `in_progress→completed` edge and never let
  `TransitionStatusRequest` accept `completed`. Source: archive Sprint 62.1; code `ClinicVisit`.
- **AIR-RMC-02** Reach `completed` only through `RmePaymentService`, from `cashier_pending`, after a cashier payment;
  `ClinicVisitService::transitionStatus()` refuses `completed` from any other status. Add no manual path. Payment
  rules live in `.claude/rules/rme-billing.md`. Source: rule 108 §11,
  rule 109 §1.
- **AIR-RMC-03** Pass an explicit, persisted, active `User $actor` to every actor-dependent `ClinicVisitService`
  method; `transitionStatus()` accepts a null actor only for `completed`. The service contains no `Auth::`, `auth()`,
  `request()`, `session()` or `BranchContext`; usability and abilities come from `ClinicVisitActorGuard` asking
  `ClinicVisitPolicy`. Source: rule 199 §B–C.
- **AIR-RMC-04** "Selesai Pemeriksaan" (`rme.visits.transition` to `cashier_pending`) requires
  `complete_rme_examination` (Doctor, Perawat, Supervisor RME; Super Admin via `Gate::before`) **and** a valid signed
  consent on that visit, both re-asserted in `ClinicVisitService::transitionStatus()`. Admin Klinik and Kasir never
  close an examination. Several buttons may post to the one guarded route; there is no second path. Source: rule 109
  §1–1a, rule 134 §5.
- **AIR-RMC-05** Never let a clinical document complete an examination: finalizing a medical record, saving
  handwriting, or saving/finalizing an odontogram leaves the visit `in_progress`. `MedicalRecordService` must not
  depend on `ClinicVisitService`. If the doctor never clicks "Selesai Pemeriksaan", `RmeInvoiceService::create()`
  (which requires `cashier_pending`) cannot run — that is intended. Source: rule 109 §1.

### Consent

- **AIR-RMC-06** Allow treatment-consent signing only while the visit is `in_progress`
  (`RmeVisitConsentService::assertSignable()`); refuse `registered`, `waiting`, `cashier_pending`, `completed` and
  `cancelled` server-side (supersedes rule 100 §2 "any non-terminal visit"). Source: rule 109 §2a.
- **AIR-RMC-07** Gate every current-RME write on positive authority,
  `RmeVisitConsentService::assertRmeAuthoringAllowedForPatient()`: exactly one current encounter
  (`ActiveEncounterResolver`), it is `in_progress`, the resolvable actor may work on it, and a valid signed consent
  exists for that same encounter. No encounter, two `in_progress` visits or a null actor fail closed. Covered writers:
  `MedicalRecordService::createDraft/updateDraft/finalize/getOrCreateCanonicalMedicalRecord`,
  `MedicalRecordDiagnosisService`, `DiagnosisRolloutService::grantOverride`, `MedicalRecordHandwritingController`.
  Source: rule 109 §2b.
- **AIR-RMC-08** Never derive authoring authority from `trx_medical_records.clinic_visit_id` (the canonical book
  belongs to an old, finished visit) or from the request (`{clinicVisit}`, `source_visit_id`, `?sheet=`). A cancelled
  or completed encounter never makes the canonical record writable again (cancel-then-author and
  complete-then-author were real reproducers). Source: rule 109 §2b.
- **AIR-RMC-09** Never consent-gate a read: patient history, earlier sheets, previous odontograms and published
  Legacy RME/Odontogram archives stay readable with or without an encounter. Prescriptions are deliberately outside
  the consent gate. Source: rule 109 §2, §2c.
- **AIR-RMC-10** Read consent only from persisted evidence (`RmeVisitConsentService::hasValidConsent()`,
  `ClinicVisit::hasSignedConsentDocument()`), never from a request value. `trx_clinic_visits.consent_signed_by_patient`
  / `_doctor` are display-only history and authorize nothing. A list that calls a consent predicate per row eager-loads
  `consents`. Source: rule 100 §1, §1b.
- **AIR-RMC-11** Keep consent out of payment: `RmePaymentService`, `CreateRmePaymentRequest`, the payment page and
  the cashier handoff never consult, show or wait for consent, and historical receivables without consent stay
  collectible (supersedes the Sprint 20-era "consent is the payment gate"). Source: rule 109 §3, rule 100 §1a.
- **AIR-RMC-12** Treat a signed consent as immutable evidence: no update path, no hard delete; correction is void with
  a reason plus re-sign, and `validForVisit()` returns the latest non-voided consent by `signed_at`, then `id`.
  Snapshot wording from `config/rme_consent.php` and bump its `version` when substance changes; never rephrase the
  clinic's clause text. Clause 8 is an explicit YA/TIDAK validated `present|boolean` and never blocks treatment or
  payment. Source: rule 100 §4–5, rule 109 §2b.
- **AIR-RMC-13** Gate consent routes on `manage_rme_consents` / `view_rme_consents` (Doctor, Kasir, Admin Klinik,
  Supervisor RME), never on `manage_clinic_visits` (Kasir lacks it). Keep identity numbers and signature paths
  `$hidden`; only the consent document renders a full identity number; signatures stay on a private disk streamed
  through the consent policy. Source: rule 100 §6–7.

### Odontogram

- **AIR-RMC-14** Let the authorized doctor chart the ACTIVE odontogram from "Mulai Pemeriksaan", before consent.
  `RmeVisitConsentService::assertOdontogramAuthoringAllowed()` is the single authority (one `in_progress` encounter,
  actor may work on it, chart belongs to that encounter; deliberately no consent condition), asserted before any
  insert by `OdontogramService::saveForVisit/updatePlaceholder/finalize`. Add no second consent check in a
  controller, FormRequest, Blade or JS. Saving never signs consent or changes visit status (supersedes rule 109
  CORRECTIVE-03 and rule 110 §2 for the odontogram). Source: rule 134 §1–4.
- **AIR-RMC-15** Keep `GET rme/visits/{clinicVisit}/odontogram` write-free: `OdontogramController::show()` uses
  `OdontogramService::draftForVisit()` (saved chart or unsaved instance); the first row is created by
  `rme.visits.odontogram.store` → `saveForVisit()`. `getOrCreateForVisit()` keeps zero production callers (pinned by
  `RepositoryArtifactHygieneTest`). Branch on `$odontogram->exists` before `route()` with the model. Source:
  rule 110 §1.
- **AIR-RMC-16** Keep a previous visit's chart permanently read-only — no consent or pre-consent permission reopens
  it — while a finalized chart on the live encounter stays revisable. Enforce in `OdontogramService`, not by hiding
  controls; `OdontogramPolicy::update` keeps answering only role/branch/patient scope. Source: rule 109 §4, rule 134 §2.
- **AIR-RMC-17** Serve "Riwayat Odontogram Pasien" via `OdontogramService::patientHistoryForVisit()`: patient-centric,
  scoped to the RME branch set plus `DoctorPatientScopeService`, excluding the current visit and empty drafts
  (`Odontogram::hasRecordedTeeth()`), with no mutation control, rendered outside the
  `x-data="odontogramEditor(...)"` wrapper. Missing doctor or branch renders as an em dash. Source: rule 109 §4.

### Room, doctor scope and print denial

- **AIR-RMC-18** Keep the room gate: `visit.room` (`EnsureVisitRoomAssigned`) guards medical-record and odontogram
  routes and redirects a roomless pre-exam visit to `rme.visits.show`; `cashier_pending` and terminal visits are
  exempt. The room is written only by `ClinicVisitService::assignRoom()` (`rme.visits.assign-room`). The doctor comes from
  `assignRoom()` for a front-desk registration (which drops `doctor_id`); any other registrar must name a doctor online
  at the visit's branch (`UserOnlineContextService::assertDoctorSelectableForVisit()`);
  registration never writes `clinic_room_id`; the visit edit is the allow-list `ClinicVisitService::EDITABLE_FIELDS`.
  Source: archive Hotfix Sprint 60.8; rule 199 §14a; rule 200 §17–18.
- **AIR-RMC-19** Route doctor room isolation only through `App\Modules\RME\Services\DoctorRoomScopeService`: the room
  comes from the doctor's live online context (`trx_user_online_contexts`), never the request; it restricts only
  `ClinicVisit::PRE_EXAM_STATUSES`; no resolvable room ⇒ no active visit; history stays readable. Keep
  `applyRoomScope()` and `deniesActiveVisitOutsideRoom()` mirror images. Owner, Super Admin and Supervisor RME are
  exempt via `DoctorPatientScopeService::shouldApplyDoctorScope()`. Do not room-guard `completeExamination`. Source:
  rule 138 §1–5.
- **AIR-RMC-20** Deny `print` to a scoped Doctor on `rme.visits.print`, `rme.visits.pdf` (`ClinicVisitPolicy::print`)
  and `rme.odontograms.print` (`OdontogramPolicy::print`); the existing `@can('print')` hides the buttons, so add no
  UI-only check. Prescription print keeps working. Audit `DOCTOR_ROOM_ACCESS_REJECTED`, `DOCTOR_PRINT_RME_REJECTED`,
  `DOCTOR_PRINT_ODONTOGRAM_REJECTED` from controllers only, never from a policy. Source: rule 138 §6–7.
- **AIR-RMC-21** Aggregate visit data (RME dashboard, visit widgets) over
  `ClinicVisitService::scopedBranchIdsFor($actor)`, never `BranchService::rmeEnabledIds()`;
  `patientVisitHistory()` and `adjacentVisits()` stay cross-branch by design because callers authorize the anchor
  visit first. Source: rule 199 §E.

### Medical record workspace

- **AIR-RMC-22** Handwriting RM is the primary clinical input; SOAP stays hidden from the doctor UI (old values show
  only as the read-only "Data SOAP (Legacy)" card) — never re-open SOAP input. Finalization requires handwriting
  (`MedicalRecordService::hasRequiredHandwriting()`); a finalized record stays editable inside an authorized,
  consented encounter. Source: archive Sprint 20 closure; rule 109 §2b.
- **AIR-RMC-23** Resolve the patient-centric book with `PatientRmWorkspaceResolver`: one sheet per visit
  (`UNIQUE(clinic_visit_id)`), canonical anchor = earliest non-cancelled RME visit, any other visit redirects to the
  canonical URL with an IDOR-validated `?source_visit_id=`; the handwriting book is the earliest self-anchored sheet
  (`bookOn()`, rule 237 §16). Per-visit actions use the active sheet's visit. Source: archive Sprint 64.0; rule 100 §8.
- **AIR-RMC-24** Keep Medical Record page order Informasi Kunjungan → RME Tulisan Tangan → Dokumen RME Pasien, with
  exactly one Finalisasi and one Cetak RME, both in its header. Cetak RME is not on Detail Kunjungan (front office
  uses the Rekam Medis link); moving a button never adds a print endpoint. Source: rule 100 §8–9.
- **AIR-RMC-25** Gate every RME authoring control on the capability, not a permission: the controller passes
  `RmeVisitConsentService::canAuthorRmeForPatient()` (odontogram page: `canAuthorOdontogramFor()`); never use
  `can('create', MedicalRecord)` alone and never re-derive consent in Blade. A not-yet-allowed "Buat Halaman RM
  Pertama" renders disabled with the reason and no `<form>`. Resolve a UI/server mismatch toward UI DENY + server DENY,
  never by relaxing the service. Source: rule 135; rule 134 §6.
- **AIR-RMC-26** Treat `/rme/medical-records` as a read-only unified index: one row per patient holding a readable
  native record or a PUBLISHED legacy RME/odontogram, each source under its own canonical scope (composed, never
  copied) plus `DoctorPatientScopeService`, paginated after the union. Never convert a legacy archive into a
  `MedicalRecord` or create a visit; `rme.medical-records.patients.show` answers 404 outside the actor's index.
  Source: rule 173.

### Print and evidence

- **AIR-RMC-27** `rme.visits.print` and `rme.visits.pdf` share `rme.visits.partials.print-body`; change it once and
  never fork. It carries no `Data Pasien & Kunjungan` card or its cells and no odontogram (standalone
  `rme.odontograms.print`), keeps the wrapper `<title>`, footer and logo, and leaves no empty
  `section-block`/`info-grid`. "Remove X from the print" never touches data, schema or the detail screen. Source:
  rule 127; rule 100 §9.
- **AIR-RMC-28** Embed handwriting in print/PDF as inline base64 (`orderedHandwritingPages(true)`), never a link —
  dompdf cannot authenticate. Write handwriting and prescription/doctor-signature canvases only through
  `App\Support\Storage\ClinicalEvidenceStorage` (never `disk('public')` or `storeAs(..., 'public')`), read through
  policy-gated controllers, with no fallback disk. Source: rule 121.
- **AIR-RMC-29** Render the structured odontogram print through `OdontogramPrintFormatter` and
  `rme/visits/odontogram/partials/structured-print-template.blade.php`: HTML table cells, no flexbox, DMF-T from
  `Odontogram::dmftCounts()` (an unknown status never inflates it). Source: archive Sprint 63.1.

### Registration, visit detail, dates, prescriptions

- **AIR-RMC-30** Derive "Jenis Kunjungan" server-side: `StoreClinicVisitRequest::prepareForValidation()` overwrites
  any client `visit_type` — Kontrol only when `follow_up_of_visit_id` is explicitly selected, Baru otherwise. An
  invalid, cross-patient, non-RME or soft-deleted prior visit is rejected, never downgraded; history alone never makes
  Kontrol; `continued_treatment`/`emergency` stay in data but are not creatable. Source:
  rule 185 §C.
- **AIR-RMC-31** Render Detail Kunjungan clinical cards in Blade source order Odontogram → Rekam Medis → Resep (never
  CSS `order-*`), each keeping its own `@can`; front-office-only accounts see none of them. Source: rule 185 §A.
- **AIR-RMC-32** Stamp clinical dates from `ClinicalClock` (CG-12): `visit_date` (it also drives queue and visit
  numbers), the RM year in `PatientMedicalRecordNumberService::composeForRegistration()`, and the RM pre-check in
  `StoreClinicVisitRequest`. Use a supplied date verbatim; never renumber an existing patient. Daftar Kunjungan
  defaults to the clinical day. Source: rule 110 §4, rule 108 §13–14,
  rule 92.
- **AIR-RMC-33** Send a prescription by WhatsApp only as an explicit, confirmed operator action gated by
  `send_prescription_whatsapp` (`rme.prescriptions.whatsapp.send`): Meta Cloud API with an approved utility template,
  HTTPS host allowlist, no redirects, token never logged or persisted, idempotent per prescription + recipient +
  template, no diagnosis or KTP/NIK in the message; a failure never mutates the prescription or visit. Source: rule 108
  §15–22.

### Testing

- **AIR-RMC-34** Prove these gates server-side (service/HTTP outcome and the rendered form, never a label). Active-RME
  fixtures build a real encounter (`rmeActiveConsentedEncounter()` / `rmeConsentedOdontogramVisit()`);
  `rmeSignedConsentFor()` alone authorizes nothing; consent tests go through `RmeVisitConsentService`. Pinned suites
  stay in `critical_gate_mandatory_suites` (CG-19). Source: rule 109 §6, rule 134 §7, rule 135 §7.
- **AIR-RMC-35** Consent identity cannot be borrowed: the treating doctor comes from the visit, never from the request,
  and "Saya sendiri" copies the patient's `mst_patients` identity, which typed input cannot override. Source:
  rule 100 §3; `RmeVisitConsentService`.

- **AIR-RMC-36** Performed treatments only through `RmePerformedTreatmentService` (rule 219): the visit's treating
  doctor, `in_progress`, signed consent, visit row locked first, append-only rows voided with a reason. Empty =
  `NOT_RECORDED`; only "Tidak Ada Tindakan" is operational KONTROL: never rewrites `visit_type`, never creates a
  parent, never makes anything free (rule 217), never transitions the visit. A covering doctor must first be the
  assigned doctor; no post-completion edit. Flag `rme.doctor_performed_treatments` default OFF; ask
  `enabledFor($visit)` (fail-closed allowlist via `RmePerformedTreatmentPilotScope`), never the bare flag, re-decided
  on the LOCKED visit. Pilot posture and DPT-Q2…Q10: rule 219 §14–§15. Consent void = `RmeVisitConsentService::void()`.

- **AIR-RMC-37** The governed RME route keys (`{clinicVisit}`, `{medicalRecord}`, `{rmePrescription}`, `{consent}`,
  `{odontogram}`, `{diagnosis}`, `{handwriting}`, `{handwritingPage}` = the page ROW key) are constrained globally by
  `App\Support\Routing\DatabaseRouteKey` (first in `AppServiceProvider::boot()`): a non-bigint segment answers 404,
  never a PostgreSQL 500, for any signed-in account. Never add a per-route `where`/`whereNumber` for them; a generic
  name is constrained per route. Source: rule 222 §1.
- **AIR-RMC-38** Decide a visit's status, room and consent window on the LOCKED row:
  `transitionStatus()`, `assignRoom()` and `RmeVisitConsentService::sign()` take
  `ClinicVisitRepositoryInterface::lockForStatusDecision()` (FOR NO KEY UPDATE, never FOR UPDATE: FK inserts must not
  wait or deadlock) and re-check the map / `isTerminal()` / `assertSignable()` on that row before writing (a stale
  copy let a cancelled visit come back billable). Lock the visit row first, always.
  Source: rule 223 §1.
- **AIR-RMC-39** Detail Kunjungan "Buat Rekam Medis" (`canCreateRecordHere`), the Rekam Medis page "Selesai
  Pemeriksaan" (`transition` + `completeExamination` + signed consent), the handwriting editor and the diagnosis forms
  (`canAuthorRme`) follow the capability, never a permission alone. Source: rule 223 §2.
- **AIR-RMC-40** The front-desk-only tier (`FrontOfficeRole::isVisitFrontDeskOnly()`: Admin Klinik / Front Office
  without a clinical role) never authors the active RME or odontogram; the refusal sits in
  `RmeVisitConsentService::actorMayWorkOnEncounter()`. Perawat/Supervisor RME authoring and front-office exam start
  are owner decisions, not settled here. Source: rule 223 §3.
- **AIR-RMC-41** Store a new sheet's `source_visit_id` only after `PatientRmWorkspaceResolver::resolveSourceVisit()`;
  never a raw request id. Source: rule 223 §4.
- **AIR-RMC-42** A handwriting page or prescription canvas row and its private object are one unit: validate first,
  then one transaction (draft if needed, authorize, lock the parent FOR NO KEY UPDATE, write a fresh random-suffixed
  object, write the row); a failure removes only this call's object. Never draft before validation, never build a key
  from request input, never delete committed evidence. Source: rule 224 §1–5.
- **AIR-RMC-43** Name a controller's route-model argument after its route segment (`{rmePrescription}` →
  `$rmePrescription`); a mismatch injects an empty model. `ClinicalEvidenceStorage::dataUri()` is null for an absent,
  unreadable or non-PNG object and print renders "Gambar halaman ini tidak tersedia." instead of an empty `<img>`.
  Source: rule 224 §6–7.
- **AIR-RMC-44** A racing insert that may lose a unique index runs in a nested `DB::transaction()` (SAVEPOINT); catch
  `UniqueConstraintViolationException` outside it and re-read the winner, never inside the aborted transaction
  (25P02). Visit numbers: `lockVisitSequence($branchId)` (advisory 92_001), then `withTrashed()` MAX; a row lock
  cannot serialise `MAX + 1`. Source: rule 225 §1–4.
- **AIR-RMC-45** A prescription write (create, update, R/ canvas, doctor-signature canvas) follows the clinical authoring
  authority: never the front-desk-only tier, only inside the actor's working branch (`RmeWorkingBranchScope::allows()`),
  doctor-patient scope for a Doctor. `PrescriptionAuthoringAuthority` is the single predicate; the policy reads it and
  `RmePrescriptionService` re-asserts it before any lock or canvas write. No consent rule (room: AIR-RMC-50; lock, signer: AIR-RMC-53). Source: rule 226 §1.
- **AIR-RMC-46** Clinical documents (MR + handwriting images, prescription page/canvas/print/history, odontogram) are
  read under `RmeWorkingBranchScope::allowsClinicalDocument()`: Kasir / Admin Klinik / Front Office only in their
  working branch (none without one); clinicians and Owner keep the RME set; never wider. Source: rule 227 §1.
- **AIR-RMC-47** The printed prescription dentist and patient are server-derived (visit on create, the prescription's
  own doctor/patient on update); never request fields; no doctor means refused. Source: rule 227 §2.
- **AIR-RMC-48** Prescription authoring = `manage_clinic_visits` + a role in `PrescriptionAuthoringAuthority::
  AUTHORING_ROLES` (an allowlist; runtime roles do not inherit it) + working branch, re-asserted in the service. Test
  authors use `rmeClinicalAuthor()`. Source: rule 227 §3.
- **AIR-RMC-49** RME invoice / payment / consent numbers are read after `lockNumberSequence($month)` (advisory 92_002 /
  92_003 / 92_004, objid YYYYMM, never renumbered) and invoice/payment MAX counts soft-deleted rows. Source: rule 227 §4.
- **AIR-RMC-50** The doctor room scope (rule 138) binds prescriptions and the live-encounter write predicate
  (`actorMayWorkOnEncounter`): never an ACTIVE visit of another room; history unaffected. Rule 228 §1–§2.
- **AIR-RMC-51** Only `ClinicVisitPolicy::startExamination()` moves a visit to `in_progress` (front desk keeps cancel); a
  `cashier_pending` room move keeps the doctor. Rule 228 §3–§4.
- **AIR-RMC-52** RME/odontogram authoring needs a `FrontOfficeRole::CLINICAL_ROLES` role; cross-branch document read is
  `CROSS_BRANCH_DOCUMENT_READ_ROLES` (clinical + Owner), else none. Rule 228 §5.
- **AIR-RMC-53** A completed/cancelled visit's prescription is read-only (`documentLocked()`: policy, and the service
  under the visit lock); each write logs `RME_PRESCRIPTION_CREATED|UPDATED` (field names only); canvases and consent
  signatures are bounded; only the prescription's own doctor (`maySign()`) saves the signature. Rule 228 §8–§13.
- **AIR-RMC-54** A scoped Doctor (rule 138 definition) is refused, 403 and audited, the PTM print `rme.consents.print`
  and legacy print/export/source (`DoctorRoomScopeService::assertClinicalPrintAllowed()` after the policy); reading on
  screen stays; prescription print unchanged; FO, Admin Klinik, Supervisor RME keep printing. Rule 243 §1.

## Current posture (snapshot 2026-11-17 — verify before relying on it)

- Live: pre-consent odontogram charting, consent-gated RME authoring and "Selesai Pemeriksaan", consent-free payment,
  room gate, doctor room isolation, doctor RME/odontogram print denial; performed treatments ARMED for SPN4 only
  (rule 219 §14).
- In code since 2026-11-17 (check the deployed SHA): one valid consent per visit and a supervised void
  (`rme.consents.void`, `void_rme_consents`); consent signatures on `ClinicalEvidenceStorage` with no fallback, older
  objects copied by `rme:consent-signatures-migrate` (rule 237 §14).
- WhatsApp prescription delivery OFF (`DisabledWhatsAppGateway`); `doctor.branch_lock` OFF, so doctor reach is patient
  and room scope (`.claude/rules/doctor-access.md`).
- Cross-branch, cross-doctor Kontrol approved (OD-1, rule 200); receivable carry-over branch-local (OD-1a,
  `.claude/rules/rme-billing.md`).
- Deliberate leftovers: `resolvePrintViewData()` eager-loads (rule 127); Full Suite deferred (CG-18).

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/10-laravel-modular-monolith.mdc` | Module layering, scaffolding order, forbidden controller patterns |
| `.cursor/rules/92-canonical-clinical-timezone-date-boundary.mdc` | `ClinicalClock`, clinical vs technical dates, boundary diagnosis |
| `.cursor/rules/100-rme-consent-payment-gate-print-ownership.mdc` | Consent evidence, permissions, PII; MR page and print ownership |
| `.cursor/rules/108-clinic-ops-working-branch-context.mdc` | Examination-completion authority, visit list date, WhatsApp delivery |
| `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc` | Canonical workflow, consent timing, positive authoring authority, history |
| `.cursor/rules/110-post-rme-odontogram-stabilization.mdc` | Odontogram GET writes nothing; RM year from clinical calendar |
| `.cursor/rules/121-clinical-evidence-private-storage.mdc` | Clinical evidence private-disk only; inline print embedding |
| `.cursor/rules/127-rme-print-composition-no-duplicate-patient-card.mdc` | RME print composition, shared print-body partial |
| `.cursor/rules/134-odontogram-pre-consent-authoring.mdc` | Active odontogram editable before consent; single authority method |
| `.cursor/rules/135-rme-authoring-ui-capability-mirror.mdc` | RME controls mirror server capability, not permission |
| `.cursor/rules/138-doctor-room-scoped-access-and-print-deny.mdc` | Doctor room isolation and doctor print denial |
| `.cursor/rules/173-unified-medical-record-index.mdc` | Unified native + legacy medical-record index |
| `.cursor/rules/185-rme-visit-detail-invoice-registration-ux.mdc` | Visit detail order, invoice document, server-derived visit type |
| `.cursor/rules/199-rme-clinic-visit-branch-authority.mdc` | Explicit-actor clinic visit service, aggregates, fail-closed reads |
| `.cursor/rules/200-rme-active-user-patient-selection-cross-branch-control.mdc` | Active accounts, registration guard, edit allow-list, OD-1 |
| `.cursor/rules/219-rme-doctor-performed-treatments.mdc` | "Tindakan Dilakukan": structured record; "Tidak Ada Tindakan" = KONTROL ≠ NO_CHARGE |
| `.cursor/rules/222-rme-route-key-constraint-and-failure-log-redaction.mdc` | RME route keys constrained (404, never 500); redacted failure logs |
| `.cursor/rules/223-rme-clinical-workflow-integrity.mdc` | Locked-row decisions; capability-mirrored controls; front office never authors |
| `.cursor/rules/224-rme-clinical-evidence-write-integrity.mdc` | Evidence row + object as one unit; canvas binding; honest print |
| `.cursor/rules/225-rme-concurrent-first-write-and-void-staging-integrity.mdc` | Savepoint-adopt racing first writes; per-branch visit number lock |
| `.cursor/rules/226-rme-prescription-authoring-authority-and-blade-php-pairing.mdc` | Prescription writes follow the clinical authoring authority; Blade PHP pairing |
| `.cursor/rules/227-rme-clinical-document-read-scope-printed-identity-numbering.mdc` | Document read scope; printed prescription identity; authoring allowlist; serialised numbers |
| `.cursor/rules/228-rme-clinical-write-authority-and-prescription-integrity.mdc` | Room scope on writes; clinical exam start; role allowlists; prescription lock/signer |
| `.cursor/rules/237-rme-comprehensive-closure.mdc` | Clinic-month numbers; no-store; page bounds; signed Rx lock; consent integrity; lease grace; one RM book |
| `.cursor/rules/243-rme-doctor-encounter-scope-downloads-fields.mdc` | Doctor encounter scope (flagged), no doctor downloads, doctor-visible fields |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/01-rme-clinical.md` (Sprint 62.1 and the partial-payment
hotfix are in `docs/sprints/claude-md-archive/02-rme-billing-reporting.md`). Grep by sprint id; never read a whole
file.
