---
paths:
  - "app/Modules/LegacyImport/**"
  - "app/Modules/LegacyOdontogram/**"
  - "app/Support/Legacy/**"
  - "app/Modules/Patient/Services/LegacyPatientImport*.php"
  - "config/legacy_*.php"
  - "resources/views/legacy/**"
  - "resources/views/settings/legacy-*/**"
  - "tests/Feature/LegacyOdontogram/**"
  - "tests/Feature/LegacyImportHub/**"
  - "tests/Feature/LegacyMassUpload/**"
  - "tests/Feature/LegacyBatchReview/**"
  - "tests/Feature/LegacyBatchPublish/**"
  - "tests/Feature/LegacyCompleteness/**"
---

# Legacy import hub, legacy odontogram, legacy patient import, mass upload & batch review/publish — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift. Legacy RME archive internals live in `legacy-rme.md`.

## Invariants

### Hub, quota and activation

- **AIR-LIM-01** Keep the three importers separate capabilities: `legacy_patient` (`settings.patients.import.*`, no
  flag), `legacy_rme` (`settings.rme.legacy-imports.*`, flag `rme.legacy_pdf_archive`), `legacy_odontogram`
  (`settings.rme.legacy-odontograms.*`, flag `rme.legacy_odontogram_archive`). They share only the sidebar group and
  the report page `settings.legacy-imports.index`, which decides nothing and grants no authority. Source:
  `128-legacy-import-hub.mdc`.
- **AIR-LIM-02** The business daily quota defaults to `NULL` (no ceiling) for all three types. `NULL` declines to limit,
  `0` admits nothing; never a sentinel like `999999`. With no ceiling `LegacyImportDailyQuotaService` writes no bucket
  (do not "fix" that). Restore one with an integer `LEGACY_IMPORT_HUB_DAILY_LIMIT` or per-type override plus a config
  rebuild; it is clamped to `max_declarable_daily` (500). Source: `166-legacy-import-unlimited-quota.mdc` (supersedes
  rule 128's 100/day).
- **AIR-LIM-03** When a ceiling is declared, charge only ACCEPTED records, inside the transaction that writes them,
  with `FOR UPDATE` on a pre-existing `ops_legacy_import_daily_quotas` row; per server-resolved branch, per
  `ClinicalClock` day, per type. Legacy Patient is charged per COMMITTED row at `commit()`, rollback never refunds, a
  multi-branch batch is all-or-nothing, and `reserveMany()` sorts branch ids. Source: `128-legacy-import-hub.mdc`.
- **AIR-LIM-04** Removing the quota authorizes nobody, and technical backpressure survives it: MIME/size/page/render
  caps, queue depth, worker concurrency, free-disk floor, chunked dispatch. A chunk must let the operator continue
  immediately, never behave as "N per day". Never cite "the quota is unlimited" to widen another gate. Source:
  `166-legacy-import-unlimited-quota.mdc`.
- **AIR-LIM-05** Lock order is fixed: slot advisory lock, then hub bucket, then ROLL-4 wave buckets (branch-keyed,
  ordered by `branch_id`); legacy intake re-reads the patient `FOR SHARE` after the slot lock, and patient merge takes
  slot locks before patient rows. Inverting any forms a deadlock. Source: `170-….mdc`, `128-….mdc`,
  `172-patient-duplicate-resolution-merge.mdc`.
- **AIR-LIM-06** Never report legacy RME active on a flag alone: capability ON ≠ admission ≠ wave ≠ usable. Hub status
  is `belum_dibuka` while a gate is shut; `LegacyRmeActivationStateService` composes the real gates (verdict from
  `LegacyRmeBranchAdmissionService::decideForBranchCode()`) and an evaluation exception returns `STATE_UNAVAILABLE`,
  `open = false`, never `null`. Source: `100-legacy-rme-activation-states.mdc`.
- **AIR-LIM-07** A ROLL-4 wave must cover its admitted branches with its approved set, keep MAIN forbidden in both
  `decide()` and `admittedBranchCodes()`, and assign an operator per branch (permission ≠ assignment). A holder of
  `manage_legacy_rme_migration_operations` (including Super Admin through `Gate::before`) counts as assigned without a
  row; rule 100 says otherwise and is drift. Register the wave with `--per-branch-daily-quota=100` explicitly; never
  rely on a default (`quota.default_branch_daily` reads `LEGACY_RME_BRANCH_DAILY_QUOTA`). Activating a
  wave imports nothing. Source: `100-legacy-rme-activation-states.mdc`.
- **AIR-LIM-08** Run legacy readiness as the runtime user (`runuser -u daengtisiams -- php artisan
  legacy-rme:rollout-readiness --expect=on`); as another user `private_disk_writable` reads UNKNOWN and fakes NO_GO.
  A `SUN4` allowlist entry is canonicalized to `SPN4` by `BranchCodeAlias` and is not a lockout. Source:
  `166-legacy-import-unlimited-quota.mdc`.

### Legacy odontogram

- **AIR-LIM-09** Native odontogram is optional: no meaningful native chart ⇒ archive allowed with no cutoff; otherwise
  the EARLIEST meaningful native date is the bound and the archive date must be strictly earlier (equal is REJECT).
  `PATIENT_HAS_NO_NATIVE_ODONTOGRAM`, its refusal branch and the `require_native_odontogram_reference` switch were
  deleted; never reintroduce them anywhere. Source: `132-legacy-odontogram-native-optional.mdc` (supersedes the
  eligibility gate of rule 111 §2).
- **AIR-LIM-10** Only `Odontogram::hasRecordedTeeth()` decides whether a native chart counts: content, not workflow
  status, and a `normal` tooth counts. Never restore `whereHas('odontogram')`. The only portable SQL half is
  `whereNotNull('tooth_map_payload')`; `whereJsonLength()`/`jsonb_array_length()` are forbidden because `teeth` is a
  JSON object keyed by FDI number. Source: `111-legacy-odontogram-native-reference-cutoff.mdc`.
- **AIR-LIM-11** Never wrap `PatientEarliestNativeOdontogramDateResolver` →
  `LegacyOdontogramNativeReferenceRepositoryInterface` in try/catch or coalesce a failure to `null` (it would read as
  "no cutoff"); never fabricate a cutoff. Future/before-birth/invalid date rules still run for a no-native patient.
  Source: `132-legacy-odontogram-native-optional.mdc`.
- **AIR-LIM-12** Never satisfy a cutoff by creating an empty native odontogram, and never create, edit or re-number a
  patient from the archive path. A change broadening odontogram eligibility owes a read-only production impact report
  (`SET default_transaction_read_only = on`). Keep the resolver un-branch-scoped. Source: `132-….mdc`, `111-….mdc`.
- **AIR-LIM-13** The odontogram upload lookup takes the Nomor RM (`rm`; `patient_id` still accepted) and returns a
  `LegacyOdontogramPatientLookup` state (IDLE/FOUND/AMBIGUOUS/NOT_FOUND/TOO_SHORT/TOO_MANY/ERROR), never `?Patient`;
  a thrown lookup is ERROR (logged without the identifier). Use `FILTER_VALIDATE_INT`; `intval()`/`Request::integer()`
  are forbidden for patient identifiers here. Source: `131-legacy-odontogram-patient-lookup.mdc`.
- **AIR-LIM-14** FOUND is not authorization: on submit the server re-resolves `patient_id` through the scoped
  repository and `LegacyOdontogramBranchBindingService` re-derives the branch. Views get only
  `LegacyOdontogramPatientIdentity`; extend `IDENTITY_COLUMNS` only with a privacy decision, and never remove
  `date_of_birth` from it: the before-birth date rule skips a null DOB, so removing it fails open. Source: `131-….mdc`.
- **AIR-LIM-15** `LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS` is exactly `review_legacy_odontogram_imports`,
  `publish_legacy_odontogram_imports`, `void_legacy_odontogram_records`; never add a `create_`/`view_` permission (that
  widened every read surface once). Branch-scoped operators read and file only for their `BranchContext::forUser()`
  branch; an empty set matches nothing; one scope serves every door. Out-of-scope is 404; an in-scope non-treating
  doctor is 403. Source: `174-legacy-odontogram-read-scope.mdc`.
- **AIR-LIM-16** Odontogram has no `SeparatePublisherGuard` and no source-RM binding re-check; its duty separation is
  permission-based (Admin Klinik `view_`+`create_`; Supervisor RME `view_`+`review_`+`publish_`; `void_` Super Admin
  only; pinned by `LegacyOdontogramFoundationTest`). Neither add identity SOD for symmetry nor weaken it without an
  owner decision applied to every surface. Source: `167-….mdc`, `171-….mdc`, `128-legacy-import-hub.mdc`.

### Single active document and visit-bound ingestion

- **AIR-LIM-17** Per patient, per type, allow at most one active/non-VOID legacy lifecycle; the RME and odontogram slots
  are independent (no patient-wide "has any legacy document" predicate). Occupancy spans staging and archive with the
  RECORD consulted first; `FAILED` occupies; a soft delete never releases (`withTrashed()`); only audited CANCEL or VOID
  release. Occupancy is deliberately not branch-scoped. Source:
  `170-legacy-single-active-document-per-patient.mdc`.
- **AIR-LIM-18** `LegacySingleActiveDocumentService::assertAvailableForNewLifecycle()` is the gate: it runs inside the
  intake transaction (refuses at `DB::transactionLevel() < 1`) under `pg_advisory_xact_lock(type namespace,
  patient_id)`. Only `createFromUpload()` creates a lifecycle; retry (`queue(…, isRetry: true)`) never reaches the
  guard. Check the slot after exact-PDF duplicate detection, branch on `REASON_*` codes, never auto-VOID or supersede.
  Source: `170-….mdc`.
- **AIR-LIM-19** Visit-preverified means DATE preverified, not SOD bypassed or self-published. It needs a real visit
  (routes under `visits/{clinicVisit}/legacy-archive/…`; never create one); patient, branch and visit date come from
  the visit row; `origin_branch_id` stays RM-derived; attestation is checked in the FormRequest and again in
  `App\Support\Legacy\LegacyVisitBindingService`; only Admin Klinik and Front Office hold
  `verify_legacy_dates_at_ingestion`. Source: `167-legacy-visit-bound-preverified-ingestion.mdc`.
- **AIR-LIM-20** A visit-attested legacy date must be STRICTLY before the visit date (`LEGACY_DATE_NOT_BEFORE_VISIT`;
  same-day uses the standard review path), evaluated independently of the native bound. `verified_source_sha256`
  binds the attestation; finalization re-validates under the row lock and refuses drift rather than self-healing; the
  checker sees dates read-only. There is no config switch for the visit bound and no visit-bound carve-out or
  dormant bypass of SOD (SOD's own switch is `LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER`; keep it on). Source: `167-….mdc`.

### Legacy patient import

- **AIR-LIM-21** Uploading a legacy patient CSV is staging: `mst_patients` is written only by
  `LegacyPatientImportService::commit()`, and ONE ERROR row refuses the whole batch (497 valid + 3 errors imports 0).
  WARNING is not ERROR. Staged advisory columns (room, treatment, complaint, signatures) never reach RME. Source:
  `168-legacy-patient-staged-verification-cancel.mdc` (supersedes archive Sprint 62.3's commit-valid-rows behaviour).
- **AIR-LIM-22** Confirmation always runs `revalidate()` against current database state and decides on that, never on
  stored counters; the verdict persists OUTSIDE the insert transaction; only a batch still awaiting review, re-read
  from the database, is revalidated; a changed or missing sha256-bound source file ⇒ `failed`; the import commits all
  approved rows or none. Source: `168-….mdc`.
- **AIR-LIM-23** `cancel()` is pre-commit only, records who/when/why, keeps staging rows, and creates or deletes no
  patient. After commit the path is `rollback()`, which soft-deletes and refuses when an imported patient has a
  visit, medical record or merge involvement. Source: `168-….mdc`, `172-patient-duplicate-resolution-merge.mdc`.
- **AIR-LIM-24** Store a blank KTP/NIK as `NULL`, never a sentinel (sixteen zeros, sixteen nines) or a `TMP-` value; NIK is
  a string normalized only by `digitsOnly()`. RM/KTP duplicate checks read `mst_patients` `withTrashed()` (non-partial
  unique indexes) plus in-file accumulators, never staging; never relax them to `whereNull('deleted_at')` (a 23505
  then aborts the commit). A no-KTP possible duplicate is a WARNING, never a merge. Source: `168-….mdc`, `169-….mdc`.
- **AIR-LIM-25** A rolled-back batch keeps reserving every NIK and composed RM it created, so re-importing the same
  file fails until they are purged (operator escalation). Purge only with provenance proof (`import_batch_id`,
  commit/rollback windows), zero dependents across FKs read from `information_schema`, a validated `pg_dump -Fc` and a
  manifest asserting `ACTUAL = EXPECTED`; keep batches, staging and `sys_audit_logs`. Source: `169-….mdc`.
- **AIR-LIM-26** The patient import's branch comes only from the CSV `Cabang` column (active, RME-enabled, MAIN
  excluded); there is no request branch field. Verification reports neutralize spreadsheet formula injection and never
  carry a full KTP; audit payloads carry counts, statuses and ids, never a name, KTP/NIK or original filename. Source:
  `168-legacy-patient-staged-verification-cancel.mdc`.

### Mass upload

- **AIR-LIM-27** Mass upload is orchestration over `LegacyRmeImportService::createFromUpload()` /
  `LegacyOdontogramImportService::createFromUpload()`; never reimplement date, branch, patient, slot, SOD or publish
  logic in `MassUpload/`. It never publishes, reuses `create_legacy_*_imports` (no `mass_upload_*` permission), takes
  no patient lock, records a stale preflight as BLOCKED, and enqueues only `LegacyMassUploadItemStatus::dispatchable()`.
  Source: `100-legacy-mass-upload.mdc`.
- **AIR-LIM-28** The manifest is never identity or authority: link by full Nomor RM with exactly one server-side match;
  refuse `nik`, `ktp`, `patient_id` and `branch_id` columns by name; refuse a partial RM (`SOURCE_RM_INVALID`) and a
  repeated patient (`DUPLICATE_MANIFEST_PATIENT`). The odontogram adapter must call
  `LegacyRmeSourcePatientBindingService::bind()` at preflight AND on the write path. Source: `100-….mdc`.
- **AIR-LIM-29** Package safety is structural: `ZipArchive::extractTo()` is forbidden; stream each entry to a generated
  filename; reject traversal, absolute paths, symlinks, duplicate leaves, bombs and non-PDF bytes; check the
  decompressed total twice. Use only disk `legacy_mass_upload_private`; never raise an archive-safety ceiling
  (`document_max_bytes`, `max_entries`, `total_uncompressed_max_bytes`, …) with a transport change. Source: `100-….mdc`.
- **AIR-LIM-30** `config/legacy_mass_upload.php` `package.max_bytes` is the only size authority; keep nginx
  `client_max_body_size` equal to FPM `post_max_size`, above `upload_max_filesize` ≥ `package.max_bytes`, declared in
  `deploy/php-fpm/daengtisiams.conf` and `deploy/nginx/upload-body-size.conf` (server-block include only). Never
  `client_max_body_size 0` or `memory_limit = -1`; a 413 is infrastructure. `set_time_limit()` must only raise: check
  `ini_get('max_execution_time')` first (CLI is 0). Source: `100-….mdc`.

### Batch review and batch publish

- **AIR-LIM-31** No "Review All": `RecordLegacyBatchReviewDecisionRequest` names exactly one `import_id`,
  `SubmitLegacyBatchReviewRequest` carries no item list, `LegacyBatchReviewDecision` has no "all" value, and
  `first_viewed_at` gates nothing. The client writes the hidden `decision` field synchronously before `form.submit()`
  (Alpine flushes bindings on a microtask; component stays in `resources/js/legacy-batch-review.js`, buttons
  `type="button"`). Source: `171-legacy-batch-review-publish.mdc`.
- **AIR-LIM-32** Route archive differences through the adapters, never a type branch: RME writes via
  `LegacyRmeImportLifecycleService::perform()` with SOD on review and publish and `CHANNEL_BATCH` audit; odontogram has
  neither. Reuse `review_/publish_legacy_*_imports` (no batch permission); out-of-scope sessions and ids are 404.
  Source: `171-….mdc`.
- **AIR-LIM-33** "Block" is a second axis in `stg_legacy_review_triage`: it never writes the canonical status (never
  CANCELLED), never releases the slot, needs a `LegacyBatchReviewReason::triageCodes()` code, and clearing is a
  review-authority act; a REVIEWED attestation clears only triage raised in the same session. Nothing in
  BatchPublish clears triage. Source: `171-….mdc`.
- **AIR-LIM-34** Never wrap batch review or publish in one outer transaction: loop the canonical per-item call, accept
  partial success. Batch review re-attempts `PENDING`/`REFUSED`; batch publish re-attempts only `PENDING` (`REFUSED` is
  terminal there, and a retry is a new run). Exactly-once publish is the canonical layer's
  (`UNIQUE(source_import_id)` + lock + `findBySourceImportId()`), never the run lock's. Select only `REVIEWED` with no
  blocking triage (`LegacyReviewTriageService::blockingFor()`), bounded by `MAX_SELECTION` 100 / `MAX_PUBLISH_PASS` 50.
  Source: `171-….mdc`.

### Completeness, queues, privacy, tests

- **AIR-LIM-35** Completeness lists a patient only when `mst_patients.import_batch_id IS NOT NULL` (never
  `Patient::isLegacyWithoutBranch()` or any heuristic) and derives state in occupancy order: published → slot-occupying
  (`FAILED` = IN_PROGRESS, trashed staging counts) → void → missing. The page is one GET route, read-only by
  interface, behind `view_legacy_patient_archive_completeness`, which must stay out of every `GOVERNANCE_PERMISSIONS`.
  Source: `172-legacy-patient-archive-completeness.mdc`.
- **AIR-LIM-36** Completeness scope borrows `LegacyImportHubService::GOVERNANCE_PERMISSIONS` (never
  `LegacyOdontogramWorkspaceScope` or `RmeWorkingBranchScope`), fails closed to empty, lets a requested branch only
  narrow, and is defined once in `scopedPatients()`; the row DTO is the PII boundary. Source: `172-….mdc`.
- **AIR-LIM-37** Legacy jobs run on `legacy-rme-documents` and `legacy-odontogram-documents`; a produced queue must be
  in the worker unit `--queue=` and the ENT-5 allowlist. An import stuck at QUEUED with `attempts=0` is a consumer
  fault: fix the consumer, never update status, retry, redispatch or re-upload. Source:
  `133-dedicated-queue-producer-consumer-contract.mdc` (archive `BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1`).
- **AIR-LIM-38** Legacy imports create no visit, medical record, native odontogram, invoice, payment, prescription,
  lab order or SATUSEHAT candidate. Hub/gate state, mass reports and audit payloads carry no Nomor RM (beyond the
  operator's own manifest value), KTP/NIK, name, filename or path. Source: `128-…hub.mdc`, `100-…mass-upload.mdc`.
- **AIR-LIM-39** Front Office is the union of Admin Klinik and Kasir, so a clinic-admin grant goes to both; it holds no
  legacy `review_*`/`publish_*`/`void_*`. Never add a permission or per-user grant to make a surface render; an
  unreachable surface is a flag, admission, branch-context or runtime-user problem. Source: `166-….mdc`, `172-….mdc`.
- **AIR-LIM-40** Keep sibling route prefixes disjoint (`settings.rme.legacy-mass-*`, `-review-*`, `-publish-*`,
  `settings.legacy-patient-completeness.*`) so `routeIs()` lights one menu. Keep CI tokens `LegacyImportHub`,
  `LegacyOdontogram`, `LegacyMassUpload`, `LegacyBatchReview|LegacyBatchPublish`, `LegacyPatient` in both critical
  variants plus `critical_gate_mandatory_suites` (`LegacyRme` does not match `LegacyMassUploadRmeTest`). Source:
  `100-….mdc`, `171-….mdc`, `172-….mdc`.
- **AIR-LIM-41** Test traps: lock and NULL-distinct index proofs run only on PostgreSQL 16 and skip elsewhere; wrap an
  intentional unique violation in a savepoint (25P02); fixture Nomor RM blocks stay disjoint
  (`legacyRmeArchivablePatient()` 1+, `lodoPatient()` 3000+, `lbpDisjointMedicalRecordNumber()` 7000+), never
  hardcoded; a `NULL` `tooth_map_payload` is an empty placeholder. Source: `171-….mdc`, `111-….mdc`.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- Hub enabled (`legacy_import_hub.enabled`); business daily limit `NULL` for all three types.
- `rme.legacy_pdf_archive` and `rme.legacy_odontogram_archive` ON in production (2026-10-04 records).
- WAVE-4 ACTIVE for TLK1/LDK2/ATG3/SPN4, per-branch quota 100, window extended to 2026-10-31; the `batch_size_policy`
  WATCH is owner-accepted. Extending a lapsed window is an owner approval (`legacy-rme:wave-admin extend`, separate
  approver).
- `LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER=true`; production held zero Admin Klinik accounts (Front Office operates).
- Batch review, batch publish and the decision-submit fix deployed; no browser-engine test for the review page yet.
- Completeness page live since 2026-10-05; nginx 512m / FPM 500M–512M upload ceilings applied 2026-10-03.
- Deferred: audited "purge rolled-back batch" command; richer odontogram patient picker; whether `manage patients`
  should be branch-scoped; any odontogram identity-SOD change (owner decision).

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/128-legacy-import-hub.mdc` | Hub, three capabilities, quota mechanics, lock order, role grants |
| `.cursor/rules/166-legacy-import-unlimited-quota.mdc` | NULL unlimited quota, backpressure survives, runtime-user readiness |
| `.cursor/rules/100-legacy-rme-activation-states.mdc` | Capability vs admission vs wave vs usable; hub gate reporting |
| `.cursor/rules/111-legacy-odontogram-native-reference-cutoff.mdc` | Meaningful native chart predicate, jsonb portability, impact reports |
| `.cursor/rules/132-legacy-odontogram-native-optional.mdc` | No-native allowed; earliest strict cutoff; no swallowed lookup errors |
| `.cursor/rules/131-legacy-odontogram-patient-lookup.mdc` | Nomor RM lookup states, validated ids, least-disclosure identity |
| `.cursor/rules/174-legacy-odontogram-read-scope.mdc` | Upload authority ≠ read authority; governance set; 404 |
| `.cursor/rules/167-legacy-visit-bound-preverified-ingestion.mdc` | Visit-bound date attestation without SOD bypass |
| `.cursor/rules/168-legacy-patient-staged-verification-cancel.mdc` | Patient CSV staging, whole-batch refusal, revalidation, cancel |
| `.cursor/rules/169-legacy-patient-rollback-identity-reservation.mdc` | Rollback keeps NIK/RM reserved; purge evidence protocol |
| `.cursor/rules/170-legacy-single-active-document-per-patient.mdc` | One active document per patient per type; advisory lock |
| `.cursor/rules/100-legacy-mass-upload.mdc` | Mass upload orchestration, package safety, transport limits |
| `.cursor/rules/171-legacy-batch-review-publish.mdc` | Batch review/publish, triage axis, submission contract, fixtures |
| `.cursor/rules/172-legacy-patient-archive-completeness.mdc` | Completeness provenance, derived state, scope, PII boundary |
| `.cursor/rules/172-patient-duplicate-resolution-merge.mdc` | Merge blocked by legacy slots; lock order; rollback refusal |
| `.cursor/rules/173-unified-medical-record-index.mdc` | Unified index reads published legacy without widening |
| `.cursor/rules/133-dedicated-queue-producer-consumer-contract.mdc` | Legacy queues must have a consumer (ENT5-Q009) |
| `.cursor/rules/102-legacy-rme-separation-of-duties.mdc` | `SeparatePublisherGuard` for legacy RME review/publish |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/06-legacy-import-odontogram-patient.part1.md` and
`docs/sprints/claude-md-archive/06-legacy-import-odontogram-patient.part2.md`. Grep by sprint id; never read a whole
file.
