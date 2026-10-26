---
paths:
  - "app/Modules/LegacyRme/**"
  - "app/Console/Commands/LegacyRme*.php"
  - "config/legacy_rme*.php"
  - "resources/views/settings/rme/**"
  - "tests/Feature/LegacyRme/**"
---

# Legacy RME PDF archive — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

The programme is CLOSED (LEGACY-RME-PROGRAM-CLOSURE-1); migration is routine operations. The unlimited hub quota,
mass upload, batch review/publish, visit-bound date attestation and single-active-document rules live in
`.claude/rules/legacy-import.md`; they enter the canonical services below and never restate these rules.

## Invariants

### Archive boundary and dates

- **AIR-LRM-01** A legacy RME is immutable historical archive evidence, never native RME. Import, publish, view and
  void write only legacy/staging tables: no `ClinicVisit` (no `clinic_visit_id`, never `trx_medical_records`),
  `MedicalRecord`, handwriting row, invoice, payment, consent, odontogram, prescription, `LabOrder`,
  `LabCaseCandidate`, SATUSEHAT candidate, visit status or KPI. Prove it with count deltas. Source:
  `90-legacy-rme-pdf-archive.mdc`, `105-legacy-rme-program-closure.mdc`.
- **AIR-LRM-02** Dates are operator-declared from what the document shows: `selected_rme_date` = EARLIEST clinical
  date (representative, drives history), `latest_rme_date` = LATEST (persisted on staging and published rows).
  Never upload time, `created_at`, file date, PDF metadata or OCR. Blank latest = single-date document; a non-blank
  unparseable latest is an error, never collapsed onto the earliest. Source: `95-legacy-rme-migration-eligibility-rm-branch.mdc`.
- **AIR-LRM-03** Bounds, all strict: `latest_rme_date < ClinicalClock::today()` (today is refused); when a native RME
  exists, `latest < earliest native RME` (equal is refused); `date_of_birth <= earliest` only when a birth date
  exists (null skips the rule, never invented). The `config/legacy_rme.php` `dates.require_strictly_*` keys carry no
  `env()` on purpose — keep it that way. (Supersedes rule 100's "clinical_timezone is UTC on production".) Source:
  `105-legacy-rme-program-closure.mdc`, `92-canonical-clinical-timezone-date-boundary.mdc`.
- **AIR-LRM-04** A patient with NO native RME is eligible (`NO_NATIVE_REFERENCE` vs `BEFORE_NATIVE_RME`, evidence
  only). Never reintroduce `PATIENT_HAS_NO_NATIVE_RME` or a switch restoring it, and never manufacture a visit or
  medical record to create a boundary. (Supersedes LEGACY-RME-PDF-1A.) Source: `95-legacy-rme-migration-eligibility-rm-branch.mdc`.
- **AIR-LRM-05** The native cutoff is computed only by `PatientEarliestNativeRmeDateResolver`, from
  `trx_clinic_visits.visit_date` of the earliest non-cancelled visit owning a medical record; it excludes
  soft-deleted rows and every legacy row, and is deliberately NOT branch-scoped (do not "align" it with
  `PatientRmWorkspaceResolver`). Source: `90-legacy-rme-pdf-archive.mdc`.
- **AIR-LRM-06** Date rules live only in `LegacyRmeDateRuleService` with stable `LEGACY_DATE_*` codes; branch on the
  code, never the message. The cutoff is recomputed server-side and re-validated at publish over the whole range;
  `earliest_native_rme_date_snapshot` is evidence, never authority. Source: `90-legacy-rme-pdf-archive.mdc`, `95-legacy-rme-migration-eligibility-rm-branch.mdc`.

### Branch and patient identity

- **AIR-LRM-07** The archive branch is derived from the Nomor RM through
  `PatientMedicalRecordNumberService::parse()`/`branchCodeFrom()` and `LegacyRmeBranchResolver` only (no regex
  elsewhere). Missing/malformed/unknown/inactive/non-RME/MAIN/out-of-scope fails closed with no fallback to the
  actor's branch, `BranchContext` or a request value; a submitted `origin_branch_id` is only compared
  (`BRANCH_CONFLICT`). Deprecated `TKM1`/`SUN4` canonicalize to `TLK1`/`SPN4`. Re-derived at publish; a stale owner
  is refused. Source: `95-legacy-rme-migration-eligibility-rm-branch.mdc`, `92-telkomas-branch-code-canonical.mdc`.
- **AIR-LRM-08** `origin_branch_id` is a security/visibility scope (repositories filter on it, the policy scopes on
  it), never "just a note". Source: `95-legacy-rme-migration-eligibility-rm-branch.mdc`.
- **AIR-LRM-09** Capture the Nomor RM printed on the document (`source_rm_raw`, `source_rm_normalized`,
  `source_rm_resolution`) independently of the selected patient: never prefilled, copied or defaulted from it. The
  filename is never identity (`source_pdf_sha256` identifies the document); OCR may suggest, never populate.
  Normalization folds whitespace/dashes only and never changes, drops or pads a digit. Source: `90-legacy-rme-pdf-archive.mdc` (rules 65–69).
- **AIR-LRM-10** Identity is EXACT: the stored Nomor RM, or the whole manual segment, via
  `LegacyRmePatientResolutionAuditService::resolveIdentity()` (soft-deleted patients count). Fuzzy/one-digit/prefix/
  suffix matching exists only in the read-only `resolve()` diagnostic, stamped `bindable => false`, unreachable
  from any write path. Ambiguous and not-found both fail closed; no first-row-wins. Source: `90-legacy-rme-pdf-archive.mdc` (rules 53–57, 70).
- **AIR-LRM-11** `LegacyRmeSourcePatientBindingService` runs FIRST in `createFromUpload()` (before dates, branch,
  admission, capacity, operations gate, hashing, storage and quota), so a refusal leaves no row, file, job or quota
  delta. Every `SOURCE_RM_*` code fails closed with no override; the binding is re-checked on the locked row at
  review, publish and retry; CANCEL is never gated. Never backfill a source RM onto pre-enforcement rows
  (`SOURCE_RM_CAPTURE_MISSING` → cancel + re-import). A refusal never discloses whose Nomor RM it is. Source: `90-legacy-rme-pdf-archive.mdc` (rules 71–79).
- **AIR-LRM-12** Master-data repair and migration are separate: never create or correct a patient so a document can
  land; an unparseable live Nomor RM is `ARCHIVE_BRANCH_UNRESOLVABLE`, reported not repaired. Diagnose with
  `legacy-rme:patient-resolution-audit` / `legacy-rme:source-rm-binding-check` (read-only). Source:
  `90-legacy-rme-pdf-archive.mdc` (rules 58–61).

### Storage, processing and lifecycle

- **AIR-LRM-13** Bytes live only on the private disk `legacy_rme_private` (outside the `local` root, `serve` false,
  checked by `LegacyRmeStorageService::assertPrivateDisk()`), at opaque paths (integer id + server UUID). The
  original filename is a sanitized display label, never a path; no public disk, URL, signed link or `storage:link`.
  Source: `90-legacy-rme-pdf-archive.mdc` (rules 17, 21–22).
- **AIR-LRM-14** Serve bytes only through the policy-gated stream actions, which re-authorize every request,
  resolve through the caller's scope (out of scope → 404), honour the disk stored on the row (`diskFor()`), and send
  `nosniff` + `Cache-Control: private, no-store` without echoing a path or filename. Being listed is not
  authorization. Source: `90-legacy-rme-pdf-archive.mdc` (rules 31, 49), `104-legacy-rme-doctor-workspace-embedded-pdf.mdc`.
- **AIR-LRM-15** Validate a PDF from its own bytes (detected MIME, `%PDF-`, size, `pdfinfo` structure; encrypted
  refused) and compute SHA-256 server-side. Duplicates block against a PUBLISHED record or an in-flight row; a VOID
  record never blocks (that is the correction path) and a FAILED same-patient row points at Retry. Source: `90-legacy-rme-pdf-archive.mdc` (rules 25–26).
- **AIR-LRM-16** Rasterize only in the queued `ProcessLegacyRmePdfImport` on queue `legacy-rme-documents`, carrying
  the import id only, never in a request. Binaries run via `LegacyRmeProcessRunner` (argument array, timeout;
  diagnostics to the log, never `failure_message`). Keep `legacy_rme.processing.queue`, the `queue_governance`
  allowed queue names and the worker unit's `--queue=` in step — a missing consumer leaves imports at `QUEUED`
  silently. Source: `90-legacy-rme-pdf-archive.mdc`, `94-legacy-rme-controlled-rollout.mdc` (rule 22).
- **AIR-LRM-17** Failures land in `FAILED` with a stable `LegacyRmePdfFailure` code; retry is legal only from
  `FAILED` or a stale `PROCESSING`. Never hand-edit a stuck import forward or invent a `READY_FOR_REVIEW → QUEUED`
  edge. Discover `pdftoppm` output by scanning its zero-padded suffix; thumbnails use Poppler `-scale-to`, not GD.
  Source: `90-legacy-rme-pdf-archive.mdc`, `94-legacy-rme-controlled-rollout.mdc` (rule 23).
- **AIR-LRM-18** The only lifecycle is `READY_FOR_REVIEW → REVIEWED → PUBLISHED` (terminal); never widen
  `LegacyRmeImportStatus::TRANSITIONS`. Publish runs in one `DB::transaction` with `lockForUpdate`, is idempotent
  behind `UNIQUE(source_import_id)`, moves no bytes, refuses an incomplete archive (`RENDERED_PAGES_MISSING`,
  `PAGE_COUNT_MISMATCH`, …), and audits a refusal after the transaction unwinds (`LegacyRmePublishRefusal`). Source:
  `90-legacy-rme-pdf-archive.mdc` (rules 36–42).
- **AIR-LRM-19** Published records are immutable: no update/delete/republish route, `LegacyRmeRecordPolicy`
  `update`/`delete` hard-wired false, no repository update. Correction is VOID + fresh import. VOID
  (`rme.legacy-records.void`, `void_legacy_rme_imports`) writes only `status`/`voided_by`/`voided_at`/`void_reason`,
  requires a reason ≥ `LegacyRmeVoidService::MIN_REASON_LENGTH` in the FormRequest and the service, is terminal and
  idempotent, and audits `void_reason_length`, never the text. Source: `93-legacy-rme-void-clinical-read.mdc`.
- **AIR-LRM-20** A VOIDed record stops streaming source, pages, print and export, enforced in the controller (not
  only the policy) because `Gate::before` gives Super Admin every ability; the row stays readable. Every rule that
  must hold for Super Admin is enforced in the service/controller and tested as Super Admin. Source: `93-legacy-rme-void-clinical-read.mdc`, `90-legacy-rme-pdf-archive.mdc` (rule 37).

### Separation of duties

- **AIR-LRM-21** Review and publish require `uploaded_by != acting user`. `SeparatePublisherGuard` is the one home of
  the rule (read `SeparatePublisherGuard::enabled()`, never its config key; a test enforces it), re-asserted on the
  locked row in `LegacyRmePublishService`; `resolveEnabledFromEnv()` treats anything but explicit false/0/off/no as
  ON. Not retroactive; null uploader exempt; cancel/retry excluded. (Supersedes rule 101's default OFF.) Source:
  `102-legacy-rme-separation-of-duties.mdc`.
- **AIR-LRM-22** An enforced SOD rule must be STAFFED: readiness needs one maker with a distinct reviewer and a
  distinct publisher (`LegacyRmeSodStaffing`), else `FAIL` / `SOD_STAFFING_UNAVAILABLE`. Measure with effective
  `can()`, never direct permission rows or a policy probe against a terminal wave. Never switch accounts,
  impersonate the maker/checker or synthesize `patient_confirmation`/`date_confirmation`. Source:
  `106-legacy-rme-routine-batch-operations.mdc`, `100-legacy-rme-production-migration-wave.mdc`.
- **AIR-LRM-23** Keep the role split: the branch maker holds `create_`/`view_legacy_rme_imports` only; Supervisor
  RME is the checker (`review_`, `publish_`, `approve_legacy_rme_migration_wave`), never
  `manage_legacy_rme_migration_operations` or `create_`. `review_`/`publish_`/`void_legacy_rme_imports` are
  `LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS` and widen the holder to every RME branch — never grant them to a
  branch maker. Repair roles via `RoleSeeder` only. Source: `106-legacy-rme-routine-batch-operations.mdc`.

### Clinical read

- **AIR-LRM-24** The flag `rme.legacy_pdf_archive` (via `LegacyRmeFeatureGuard::migrationEnabled()` /
  `assertMigrationEnabled()`) governs MIGRATION only: upload, processing, retry, cancel, review, publish, void,
  admission and the whole `settings.rme.legacy-imports.*` workspace. Never add it to `LegacyRmePatientHistoryService`
  or the read actions of `LegacyRmeRecordController`; a read incident is contained by revoking read permissions or
  VOIDing. (Supersedes rules 93 §16 and 97-patient-centric §12.) Source: `98-legacy-rme-published-read-write-separation.mdc`.
- **AIR-LRM-25** A read requires PUBLISHED status, `LegacyRmeRecordPolicy::READ_PERMISSIONS`
  (`view_legacy_rme_imports` operator, `view_legacy_rme_archive` clinical reader) and server-resolved
  `LegacyRmeWorkspaceScope`: 404 outside branch scope, 403 in scope but not authorized. `view_legacy_rme_archive`
  never joins `GOVERNANCE_PERMISSIONS`. Source: `98-legacy-rme-published-read-write-separation.mdc`, `93-legacy-rme-void-clinical-read.mdc`.
- **AIR-LRM-26** A doctor reads only with BOTH a `DoctorPatientScopeService` treating relationship AND an origin
  branch in `DoctorClinicalBranchResolver` (`mst_doctor_branches` ∩ active RME-enabled branches). Same branch alone
  never authorizes; empty scope denies all; never `users.branch_id`, `BranchContext::forUser()` or an online
  context. A legacy-only patient with no visit is correctly invisible to doctors — never manufacture a visit or
  assignment. Source: `99-legacy-rme-doctor-clinical-branch-context.mdc`, `94-legacy-rme-controlled-rollout.mdc`.
- **AIR-LRM-27** Patient history comes from ONE merge in `LegacyRmePatientHistoryService` (PUBLISHED only, ordered by
  clinical date, tie-break date → kind → source id, range rendered only when latest > earliest, visibly
  `ARSIP RME LAMA`); the patient comes from the authorized visit, never a request `patient_id`. Source:
  `97-legacy-rme-patient-centric-clinical-history.mdc`.
- **AIR-LRM-28** In `RME Tulisan Tangan Lengkap`, each rendered legacy page is a read-only virtual page in the one
  navigator/swipe sequence: explicit `RmeWorkspacePage` type, no canvas or form on it, `+ Tambah Halaman RM` always
  native, archive resolved once per request via `publishedRecordsFor()`, bytes lazy, in-page overlay viewer. Source:
  `92-legacy-rme-inline-workspace-pages.mdc`, `104-legacy-rme-doctor-workspace-embedded-pdf.mdc`.
- **AIR-LRM-29** Print/export refuse VOID, carry "ARSIP RME LAMA — DOKUMEN HISTORIS (HANYA BACA)", use a generic
  filename, render no KTP/NIK (they DO render patient name and Nomor RM, so the printed archive stays attributable), stay
  dompdf table-based and capped (`MAX_EXPORT_PAGES`); print
  references pages through the policy-gated page route. Source: `93-legacy-rme-void-clinical-read.mdc`.

### Rollout and operations

- **AIR-LRM-30** Four independent gates, all required: capability ≠ admission (`legacy_rme_rollout.admission` via
  `LegacyRmeBranchAdmissionService`, exact canonical tokens, `approval_reference` + `approved_branch_codes` covering
  every admitted branch, MAIN forbidden) ≠ an ACTIVE ROLL-4 wave bound to it (`WAVE_BINDING_MISMATCH`) ≠ an operator
  assignment in `ops_rme_legacy_wave_operators`. The operations gate only narrows; never report "active" from a flag.
  Source: `96-legacy-rme-multi-branch-controlled-rollout.mdc`, `100-legacy-rme-activation-states.mdc`.
- **AIR-LRM-31** Diagnose an upload refusal in order capability → admission → wave → assignment → permission →
  policy, reading the refusal code (`CODE_OPERATOR_NOT_ASSIGNED` means layers 1–2 already passed; fix with
  `legacy-rme:wave-admin assign`). Admission values are captured while building `config/legacy_rme_rollout.php`;
  no `env()` in `app/`. Set them and rebuild config as the runtime user BEFORE `wave-admin register`. Source:
  `100-legacy-rme-production-migration-wave.mdc`, archive `FEATURE-LEGACY-IMPORT-HUB-1A`.
- **AIR-LRM-32** A routine batch is an existing ROLL-4 wave with a fresh approval and a bounded window enforced by
  `LegacyRmeBatchWindowRule` in `LegacyRmeWaveGovernanceService::createWave()` (CLI `--planned-start-date` /
  `--planned-end-date`, inclusive end, `ClinicalClock`). Extend only later via `wave-admin extend` (approver ability,
  separate approver); a lapsed window is a WATCH, not a write refusal. Never backfill old waves or reuse a cancelled
  code; `WAVE-3` is retired. Source: `106-legacy-rme-routine-batch-operations.mdc`, `100-legacy-rme-production-migration-wave.mdc`.
- **AIR-LRM-33** Quota counts ACCEPTED-INTO-STAGING inside the staging transaction (locked counter row); cancel never
  refunds, retries are not recharged, `NULL` ≠ `0`; pass `--per-branch-daily-quota` explicitly at `register`. Close
  only on a balanced reconciliation (`unexplained = 0`, `quota_drift = 0`, nothing in flight or unresolved) plus a
  sign-off; `cancel` is the honest abort. An empty queue or `failed_jobs = 0` is never evidence. Source:
  `97-legacy-rme-production-migration-operations.mdc`, `101-legacy-rme-import-lifecycle-cli.mdc`.
- **AIR-LRM-34** DRAIN (remove a branch from the allowlist) stops new intake only; capability OFF is the emergency
  stop for mutations. No state hides PUBLISHED read; never cancel, advance or hand-edit queued work. Backpressure
  (queue depth, stall, disk) refuses new uploads only. Resting state between batches: capability OFF, admission
  EMPTY, active batch NONE. No backup under 24 h → no batch; a routine batch earns no GO tag. Source:
  `96-legacy-rme-multi-branch-controlled-rollout.mdc`, `103-legacy-rme-steady-state-operations.mdc`.
- **AIR-LRM-35** Drive single imports only through `legacy-rme:import-admin {cancel|review|publish|retry} --import=
  --actor=` (real active account, dry run unless `--apply`, one import, no `--all`/`--branch`/`--wave`/`--force`,
  non-zero on any refusal). Gate order: flag → scope (`IMPORT_NOT_IN_SCOPE`) → permission → policy → SOD → service.
  HTTP, CLI and batch share `LegacyRmeImportLifecycleService`; controllers never call the processing/publish services
  directly; a new action is added to `LegacyRmeLifecycleAction` for both surfaces. Source: `101-legacy-rme-import-lifecycle-cli.mdc`.
- **AIR-LRM-36** Audit through the singleton `LegacyRmeAuditService` (channel `HTTP|CLI|BATCH` via `withChannel()`),
  filtered by `LegacyRmeAuditEvent::ALLOWED_METADATA_KEYS`: no patient name, KTP/NIK, filename, path, base64 or clinical
  text, and no raw operator transcription. The allow-list does carry `source_rm_normalized` and `source_rm_resolution`,
  which the SOURCE_RM refusal audit needs; free text by length; never echo an approval reference. Source:
  `90-legacy-rme-pdf-archive.mdc` (rule 16), `101-legacy-rme-import-lifecycle-cli.mdc`, `103-legacy-rme-steady-state-operations.mdc`.
- **AIR-LRM-37** Readiness is read-only and fails closed (`UNKNOWN` blocks like `FAIL`): `legacy-rme:rollout-readiness
  --expect=off|on --strict` around every switch, `legacy-rme:ops-readiness` before a batch, plus
  `migration-status` / `wave-status`. Run them as the runtime user `daengtisiams`; otherwise the disk probe reports
  UNKNOWN and fakes NO_GO. Source: `103-legacy-rme-steady-state-operations.mdc`, archive
  `REVISION-SUNU-LEGACY-IMPORT-UNLIMITED-ADMIN-ACCESS-1`.

### Test traps

- **AIR-LRM-38** `legacyRmeArchiveFlag()` rewrites the whole `feature_flags.flags` array (dotted key);
  `legacyRmeBranch()` admits and enrols its branch, so build patients before narrowing an allowlist; pin every
  compared date; state a doctor's practice branch on the doctor master; Doctor-role HTTP tests need
  `withoutMiddleware(EnsureRmeOnlineContext::class)`; give fixtures distinct PDF bytes. Source: archive
  `LEGACY-RME-PDF-1B`, `LEGACY-RME-PDF-HISTORY-1A`, `99-legacy-rme-doctor-clinical-branch-context.mdc`.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- Last recorded (2026-10-04, not re-measured): capability `rme.legacy_pdf_archive` ON since FEATURE-LEGACY-IMPORT-HUB-1A;
  admission `TLK1,LDK2,ATG3,SUN4` (`SUN4` canonicalizes to `SPN4` — not a defect); WAVE-4 ACTIVE, per-branch daily
  quota 100, window extended to 2026-10-31. After that date the owner must extend, drain/complete or cancel, then
  return to the resting state.
- `ops-readiness` WATCH on `batch_size_policy` is owner-accepted (WAVE-4 declares no wave-level quota).
- `LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER=true`; `LEGACY_RME_REQUIRE_SEPARATE_APPROVER=true` in production, but its
  code default is still `(bool) env(..., false)` in `config/legacy_rme_operations.php` — do not rely on the default.
- Standing follow-up: TLK1/ATG3 operator assignments were recorded as governance accounts; give each branch its own
  intake operator.
- Standing facts: `ROLL-4-WAVE-3` is SKIPPED / NOT REQUIRED; the Wave-2 source RM that matched no patient is
  `DOCUMENT_NOT_ELIGIBLE`. No OCR exists; measured throughput is not available.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/90-legacy-rme-pdf-archive.mdc` | Foundation: dates, storage, queue, publish, identity, source RM |
| `.cursor/rules/92-legacy-rme-inline-workspace-pages.mdc` | Legacy pages in the unified RME page sequence |
| `.cursor/rules/93-legacy-rme-void-clinical-read.mdc` | VOID, clinical read, print and export |
| `.cursor/rules/94-legacy-rme-controlled-rollout.mdc` | Pilot enablement, queue contract, doctor scope never wider |
| `.cursor/rules/95-legacy-rme-migration-eligibility-rm-branch.mdc` | No-native eligibility, date range, RM-derived branch |
| `.cursor/rules/96-legacy-rme-multi-branch-controlled-rollout.mdc` | Capability vs admission, exact tokens, drain vs stop |
| `.cursor/rules/97-legacy-rme-patient-centric-clinical-history.mdc` | Merged patient history (§12 superseded by 98) |
| `.cursor/rules/97-legacy-rme-production-migration-operations.mdc` | ROLL-4 waves, operators, quota, reconciliation |
| `.cursor/rules/98-legacy-rme-published-read-write-separation.mdc` | Migration flag never gates published read |
| `.cursor/rules/99-legacy-rme-doctor-clinical-branch-context.mdc` | Doctor practice-branch context for reads |
| `.cursor/rules/100-legacy-rme-production-migration-wave.mdc` | Running a live wave, diagnosis, window extension |
| `.cursor/rules/100-legacy-rme-activation-states.mdc` | Capability, admission, wave, usable: four states |
| `.cursor/rules/101-legacy-rme-import-lifecycle-cli.mdc` | `legacy-rme:import-admin`, CLI/HTTP convergence |
| `.cursor/rules/102-legacy-rme-separation-of-duties.mdc` | Separate publisher guard, fail-closed, one home |
| `.cursor/rules/103-legacy-rme-steady-state-operations.mdc` | Routine batches, resting state, `ops-readiness` |
| `.cursor/rules/104-legacy-rme-doctor-workspace-embedded-pdf.mdc` | Workspace document rail and in-page viewer |
| `.cursor/rules/105-legacy-rme-program-closure.mdc` | Closed programme: inherited permanent invariants |
| `.cursor/rules/106-legacy-rme-routine-batch-operations.mdc` | Batch window, SOD staffing, audit traps, roles |
| `.cursor/rules/92-canonical-clinical-timezone-date-boundary.mdc` | `ClinicalClock` today boundary |
| `.cursor/rules/92-feature-flag-runtime-override.mdc` | Flag environment capture at config build |
| `.cursor/rules/92-telkomas-branch-code-canonical.mdc` | `TLK1` canonical; shared alias mechanics |
| `.cursor/rules/136-sunu-branch-code-canonical.mdc` | `SPN4` canonical, `SUN4` alias |
| `.cursor/rules/171-legacy-batch-review-publish.mdc` | Batch review/publish over the canonical lifecycle |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/05-legacy-rme.part1.md` and `.part2.md` (activation, wave
window and batch work: `06-legacy-import-odontogram-patient.part1.md`). Grep by sprint id; never read a whole file.
