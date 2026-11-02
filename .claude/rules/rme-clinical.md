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
  rules live in `.claude/rules/rme-billing.md`. Source: `.cursor/rules/108-clinic-ops-working-branch-context.mdc` §11,
  `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc` §1.
- **AIR-RMC-03** Pass an explicit, persisted, active `User $actor` to every actor-dependent `ClinicVisitService`
  method; `transitionStatus()` accepts a null actor only for `completed`. The service contains no `Auth::`, `auth()`,
  `request()`, `session()` or `BranchContext`; usability and abilities come from `ClinicVisitActorGuard` asking
  `ClinicVisitPolicy`. Source: `.cursor/rules/199-rme-clinic-visit-branch-authority.mdc` §B–C.
- **AIR-RMC-04** "Selesai Pemeriksaan" (`rme.visits.transition` to `cashier_pending`) requires
  `complete_rme_examination` (Doctor, Perawat, Supervisor RME; Super Admin via `Gate::before`) **and** a valid signed
  consent on that visit, both re-asserted in `ClinicVisitService::transitionStatus()`. Admin Klinik and Kasir never
  close an examination. Several buttons may post to the one guarded route; there is no second path. Source: rule 109
  §1–1a, `.cursor/rules/134-odontogram-pre-consent-authoring.mdc` §5.
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
  `consents`. Source: `.cursor/rules/100-rme-consent-payment-gate-print-ownership.mdc` §1, §1b.
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
  `.cursor/rules/110-post-rme-odontogram-stabilization.mdc` §1.
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
  Source: archive Hotfix Sprint 60.8; rule 199 §14a; `.cursor/rules/200-rme-active-user-patient-selection-cross-branch-control.mdc` §17–18.
- **AIR-RMC-19** Route doctor room isolation only through `App\Modules\RME\Services\DoctorRoomScopeService`: the room
  comes from the doctor's live online context (`trx_user_online_contexts`), never the request; it restricts only
  `ClinicVisit::PRE_EXAM_STATUSES`; no resolvable room ⇒ no active visit; history stays readable. Keep
  `applyRoomScope()` and `deniesActiveVisitOutsideRoom()` mirror images. Owner, Super Admin and Supervisor RME are
  exempt via `DoctorPatientScopeService::shouldApplyDoctorScope()`. Do not room-guard `completeExamination`. Source:
  `.cursor/rules/138-doctor-room-scoped-access-and-print-deny.mdc` §1–5.
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
  (`UNIQUE(clinic_visit_id)`), canonical anchor = earliest non-cancelled RME visit with a medical record, any other
  visit redirects to the canonical URL with an IDOR-validated `?source_visit_id=`, and the handwriting book lives on
  the canonical record. Per-visit actions use the active sheet's visit (`$activeSheet->clinicVisit`). Source: archive
  Sprint 64.0; rule 100 §8.
- **AIR-RMC-24** Keep Medical Record page order Informasi Kunjungan → RME Tulisan Tangan → Dokumen RME Pasien, with
  exactly one Finalisasi and one Cetak RME, both in its header. Cetak RME is not on Detail Kunjungan (front office
  uses the Rekam Medis link); moving a button never adds a print endpoint. Source: rule 100 §8–9.
- **AIR-RMC-25** Gate every RME authoring control on the capability, not a permission: the controller passes
  `RmeVisitConsentService::canAuthorRmeForPatient()` (odontogram page: `canAuthorOdontogramFor()`); never use
  `can('create', MedicalRecord)` alone and never re-derive consent in Blade. A not-yet-allowed "Buat Halaman RM
  Pertama" renders disabled with the reason and no `<form>`. Resolve a UI/server mismatch toward UI DENY + server DENY,
  never by relaxing the service. Source: `.cursor/rules/135-rme-authoring-ui-capability-mirror.mdc`; rule 134 §6.
- **AIR-RMC-26** Treat `/rme/medical-records` as a read-only unified index: one row per patient holding a readable
  native record or a PUBLISHED legacy RME/odontogram, each source under its own canonical scope (composed, never
  copied) plus `DoctorPatientScopeService`, paginated after the union. Never convert a legacy archive into a
  `MedicalRecord` or create a visit; `rme.medical-records.patients.show` answers 404 outside the actor's index.
  Source: `.cursor/rules/173-unified-medical-record-index.mdc`.

### Print and evidence

- **AIR-RMC-27** `rme.visits.print` and `rme.visits.pdf` share `rme.visits.partials.print-body`; change it once and
  never fork. It carries no `Data Pasien & Kunjungan` card or its cells and no odontogram (standalone
  `rme.odontograms.print`), keeps the wrapper `<title>`, footer and logo, and leaves no empty
  `section-block`/`info-grid`. "Remove X from the print" never touches data, schema or the detail screen. Source:
  `.cursor/rules/127-rme-print-composition-no-duplicate-patient-card.mdc`; rule 100 §9.
- **AIR-RMC-28** Embed handwriting in print/PDF as inline base64 (`orderedHandwritingPages(true)`), never a link —
  dompdf cannot authenticate. Write handwriting and prescription/doctor-signature canvases only through
  `App\Support\Storage\ClinicalEvidenceStorage` (never `disk('public')` or `storeAs(..., 'public')`), read through
  policy-gated controllers, with no fallback disk. Source: `.cursor/rules/121-clinical-evidence-private-storage.mdc`.
- **AIR-RMC-29** Render the structured odontogram print through `OdontogramPrintFormatter` and
  `rme/visits/odontogram/partials/structured-print-template.blade.php`: HTML table cells, no flexbox, DMF-T from
  `Odontogram::dmftCounts()` (an unknown status never inflates it). Source: archive Sprint 63.1.

### Registration, visit detail, dates, prescriptions

- **AIR-RMC-30** Derive "Jenis Kunjungan" server-side: `StoreClinicVisitRequest::prepareForValidation()` overwrites
  any client `visit_type` — Kontrol only when `follow_up_of_visit_id` is explicitly selected, Baru otherwise. An
  invalid, cross-patient, non-RME or soft-deleted prior visit is rejected, never downgraded; history alone never makes
  Kontrol; `continued_treatment`/`emergency` stay in data but are not creatable. Source:
  `.cursor/rules/185-rme-visit-detail-invoice-registration-ux.mdc` §C.
- **AIR-RMC-31** Render Detail Kunjungan clinical cards in Blade source order Odontogram → Rekam Medis → Resep (never
  CSS `order-*`), each keeping its own `@can`; front-office-only accounts see none of them. Source: rule 185 §A.
- **AIR-RMC-32** Stamp clinical dates from `ClinicalClock` (CG-12): `visit_date` (it also drives queue and visit
  numbers), the RM year in `PatientMedicalRecordNumberService::composeForRegistration()`, and the RM pre-check in
  `StoreClinicVisitRequest`. Use a supplied date verbatim; never renumber an existing patient. Daftar Kunjungan
  defaults to the clinical day. Source: rule 110 §4, rule 108 §13–14,
  `.cursor/rules/92-canonical-clinical-timezone-date-boundary.mdc`.
- **AIR-RMC-33** Send a prescription by WhatsApp only as an explicit, confirmed operator action gated by
  `send_prescription_whatsapp` (`rme.prescriptions.whatsapp.send`): Meta Cloud API with an approved utility template,
  HTTPS host allowlist, no redirects, token never logged or persisted, idempotent per prescription + recipient +
  template, no diagnosis or KTP/NIK in the message; a failure never mutates the prescription or visit. Source: rule 108
  §15–22.

### Testing

- **AIR-RMC-34** Prove these gates server-side (service/HTTP outcome and the rendered submittable form, never a
  label). Fixtures that write the active RME build a real encounter with `rmeActiveConsentedEncounter()` /
  `rmeConsentedOdontogramVisit()`; `rmeSignedConsentFor()` alone authorizes nothing and suits non-consent tests only;
  tests about consent go through `RmeVisitConsentService`. Keep the pinned suites (e.g.
  `RmeConsentOdontogramPreConsentEditTest`, `RmePreConsentFirstPageUiGateTest`, `DoctorRoomScopedAccessAndPrintDenyTest`,
  `UnifiedMedicalRecordIndexTest`) in `critical_gate_mandatory_suites` (CG-19). Source: rule 109 §6, rule 134 §7, rule
  135 §7; archive FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3.
- **AIR-RMC-35** Consent identity cannot be borrowed: the treating doctor comes from the visit, never from the request,
  and "Saya sendiri" copies the patient's `mst_patients` identity, which typed input cannot override. Source:
  `.cursor/rules/100-rme-consent-payment-gate-print-ownership.mdc` §3; `RmeVisitConsentService`.

- **AIR-RMC-36** Record performed treatments only through `RmePerformedTreatmentService` (rule 219): the treating
  doctor of the visit (linked active `mst_doctors` = `visit.doctor_id`), `in_progress`, signed consent, visit row
  locked first, append-only rows voided with a reason. An empty list is `NOT_RECORDED`; only an explicit
  "Tidak Ada Tindakan" is operational KONTROL, which never rewrites `visit_type`, never creates a parent visit and
  never makes anything free (rule 217 decides Rp0). The record never transitions the visit. Flag
  `rme.doctor_performed_treatments` default OFF. DPT-Q2…Q10 ratified 2026-11-02 (rule 219 §15): pilot SPN4 ONLY,
  behind a fail-closed server-side branch allowlist (a global ON alone is never a pilot); declaration optional;
  cross-check detective; covering doctor must first be the visit's assigned doctor; no post-completion edit path.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- Live in production: pre-consent odontogram charting, consent-gated RME authoring and "Selesai Pemeriksaan",
  consent-free payment, room gate, doctor room isolation and doctor RME/odontogram print denial.
- No HTTP route voids a consent (`RmeVisitConsentService::void()` exists at service level only); a mistaken consent
  is superseded by re-signing during the examination.
- WhatsApp prescription delivery is OFF (`DisabledWhatsAppGateway` bound): HOLD / BLOCKED_EXTERNAL_DEPENDENCY until
  Meta credentials and an approved template exist.
- Doctor branch lock (`doctor.branch_lock`) is OFF in production, so doctor reach is bounded by patient and room
  scope, not by branch (see `.claude/rules/doctor-access.md`).
- Cross-branch and cross-doctor Kontrol is approved (owner decision OD-1, rule 200); receivable carry-over is
  branch-local (OD-1a) — billing detail in `.claude/rules/rme-billing.md`.
- Known deliberate leftovers: `ClinicVisitController::resolvePrintViewData()` still eager-loads `doctor`, `branch`,
  `clinic`, `initialTreatment` (rule 127); the Full Suite stays deferred (CG-18).

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
| `.cursor/rules/219-rme-doctor-performed-treatments.mdc` | Doctor "Tindakan Dilakukan": structured record, "Tidak Ada Tindakan" = operational KONTROL ≠ NO_CHARGE, detective |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/01-rme-clinical.md` (Sprint 62.1 and the partial-payment
hotfix are in `docs/sprints/claude-md-archive/02-rme-billing-reporting.md`). Grep by sprint id; never read a whole
file.
