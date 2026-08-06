# LEGACY-RME-PDF-1A — Schema, Permission & Date Rules

**Sprint id:** `LEGACY-RME-PDF-1A`
**Type:** `MODULE_SPRINT`
**Module:** `App\Modules\LegacyRme` (new bounded context)
**Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (never `main`)
**Feature flag:** `rme.legacy_pdf_archive` — **default OFF**
**GO tag:** `legacy-rme-pdf-1a-schema-permission-date-rules-go`

---

## 1. What this sprint is

The foundation for **Import Arsip RME Lama Pasien** — importing a patient's
historical (legacy) medical-record documents that arrive as PDFs.

The PDF that will later be uploaded is **the patient's own historical RME data**.
It is not a template, and it is not an RME produced by a live examination.

The capability will be operated from:

```
Master Data → Master Data RME → Import Arsip RME Lama
```

Sprint 1A ships the **schema, permissions, policies, repositories, the date-rule
domain, the audit foundation, the configuration and the tests** — nothing else.

## 2. What this sprint deliberately does NOT ship

PDF conversion, Poppler/Imagick, OCR, canvas, PDF.js, a production-ready upload
endpoint, annotation, thumbnails, the full publish workflow, patient-timeline
integration, the doctor viewer, legacy print/PDF, automatic extraction,
automatic date reading.

Also excluded by design: clinic-visit creation, invoice, payment, consent,
odontogram import, SATUSEHAT integration, Owner-KPI changes, report aggregation
changes and any upload to public storage.

**There is no HTTP route, controller or Blade view in 1A.** An endpoint was not
added merely to satisfy coverage; the services are tested directly and the
runtime activation is documented for the follow-up sprint.

## 3. Permanent domain contract

### 3.1 Legacy RME

A legacy RME is an archive of a patient's old RME data, sourced from a
historical PDF, and is not the result of an examination created through the live
RME workflow.

A legacy RME:

- belongs to a patient;
- carries a historical RME date;
- may carry an origin branch;
- needs **no** `clinic_visit_id`;
- must **never** create a fake clinic visit;
- is **never** counted as a new visit, as revenue or as a transaction;
- **never** affects the visit workflow, the cashier, invoices, payments,
  consent or the odontogram;
- **never** creates a `LabCaseCandidate` or a `LabOrder`;
- **never** triggers or feeds SATUSEHAT;
- **never** enters visit or revenue KPI.

### 3.2 The legacy RME date

The date is:

- chosen **manually** by the operator;
- taken from the service/RME date **visible on the document**;
- **not** the upload time, not `created_at`, not the file date, not PDF
  metadata, and never derived from OCR;
- stored separately from `uploaded_at` and `published_at`, in
  `selected_rme_date` (staging) and `rme_date` (published record).

### 3.3 The date rules (evaluated in this order)

| # | Rule | Failure code |
|---|---|---|
| 1 | The patient must have a native RME to compare against | `PATIENT_HAS_NO_NATIVE_RME` |
| 2 | `legacy date < earliest_native_rme_date` (strict) | `LEGACY_DATE_NOT_BEFORE_NATIVE_RME` |
| 3 | `legacy date < today` (strict) | `LEGACY_DATE_IN_FUTURE` |
| 4 | `patient birth date <= legacy date` | `LEGACY_DATE_BEFORE_PATIENT_BIRTH` |
| — | Unparseable/empty input | `LEGACY_DATE_INVALID` |

Boundary decisions, all covered by tests:

- one day before the earliest native date → **valid**;
- equal to the earliest native date → **rejected**;
- after the earliest native date → **rejected**;
- **today** → rejected (an archive is historical, so rule 3 is strict);
- equal to the patient's birth date → **accepted** (a record may exist on the
  day of birth); earlier → rejected;
- `mst_patients.date_of_birth` is nullable by design. When it is null the
  birth-date rule is **skipped** — the service never invents a date and never
  blocks the import on a missing one. The other rules still apply.

### 3.4 Patients without a native RME

In regular import mode, `earliest_native_rme_date = null` means the import is
**refused** with a message explaining that the system has no native RME date to
compare against. There is no hidden exception. A migration mode for such
patients is out of scope for this sprint.

### 3.5 A legacy record is not a native record

An existing legacy row — staged or published, active or VOID — is never counted
as native RME and never becomes the comparison point. Only records created by
the system's own workflow are.

### 3.6 Published legacy records

Once published, a legacy RME is part of the patient's medical history and is
**immutable**: no in-place edit of the patient, date, file, hash or pages, and
no hard delete. The only correction path is **VOID with a reason plus a fresh
import**. The published tables therefore carry no soft delete either.

## 4. The canonical clinical date

`earliest_native_rme_date` is derived from **`trx_clinic_visits.visit_date`**,
reached through the visit that owns the medical record.

`trx_medical_records` has **no clinical date column of its own** — its clinical
date is inherited from its visit via the NOT NULL + UNIQUE `clinic_visit_id`.

Rejected alternatives, each a real trap:

- `trx_medical_records.created_at` — Sprint 59 removed the finalize edit-lock
  and Sprint 64.0 lets a doctor open a sheet from a later visit, so the row can
  be written long after the encounter;
- `trx_medical_records.finalized_at` — nullable workflow timestamp;
- `trx_medical_records.canonical_visit_id` — a cache; its own migration says the
  resolver is the live source of truth;
- `mst_patients.registered_at` — administrative, not clinical.

**Single source of truth:**
`App\Modules\LegacyRme\Services\PatientEarliestNativeRmeDateResolver`, backed by
`ClinicVisitRepository::earliestVisitWithMedicalRecordForPatient()`. No
controller, form request, policy, view or test may re-derive this query.

Exclusions (tested): cancelled visits, visits without a medical record,
soft-deleted visits, soft-deleted medical records, and every legacy row.

**Deliberately not branch-scoped.** The cutoff is a clinical safety bound; a
narrower scan could only move it *later* and let a legacy document overlap a
real native record. Branch isolation for "who may see or import what" is
enforced separately by the policies. This diverges from
`PatientRmWorkspaceResolver::resolveCanonicalVisit()` on purpose, and the
divergence is pinned by a test.

**Timezone.** "Today" is evaluated in `legacy_rme.dates.clinical_timezone`,
which defaults to the application timezone — the same wall clock the RME
workflow uses when it stamps `visit_date` (`Carbon::today()`), so the legacy
date and the native cutoff are always compared in one frame. `visit_date` is
treated as an opaque calendar date and is never shifted, since a shift would
only introduce a new off-by-one against the value the workflow originally
stamped. Realigning the setting moves the "today" boundary by at most one
calendar day.

## 5. Schema (4 additive migrations)

| Migration | Table | Purpose |
|---|---|---|
| `2026_08_06_100001` | `stg_rme_legacy_imports` | staging document |
| `2026_08_06_100002` | `stg_rme_legacy_import_pages` | staging pages |
| `2026_08_06_100003` | `trx_rme_legacy_records` | published record |
| `2026_08_06_100004` | `trx_rme_legacy_record_pages` | published pages |

Key constraints:

- `UNIQUE(uuid)` on both staging imports and published records;
- `UNIQUE(legacy_import_id, page_number)` and
  `UNIQUE(rme_legacy_record_id, page_number)`;
- `UNIQUE(source_import_id)` on `trx_rme_legacy_records` — one staging batch can
  produce at most one record, which makes publishing idempotent;
- `INDEX(patient_id, rme_date)`, `INDEX(patient_id, status)`,
  `INDEX(source_pdf_sha256)`, `INDEX(normalized_content_hash)`.

`source_pdf_sha256` is intentionally **not** globally unique: cross-patient
duplicate investigation and VOID-then-reimport both need repeats to be
representable. Duplicate handling is a service decision in a later sprint; the
repository only reports the collision.

Delete behaviour: patient/branch FKs use RESTRICT / NULL rather than a cascade,
staging pages cascade with their batch, and published pages use RESTRICT because
they are clinical evidence.

File columns are nullable on staging (the upload runtime lands later) and
required on the published record.

**No native RME table was altered.** Additive only — `php artisan migrate`,
never `migrate:fresh`, never `db:wipe`.

## 6. Status domain

No native PHP `enum` exists anywhere in `app/`, so the vocabulary is expressed as
final support classes plus model constants — typed, validated and closed, never
scattered magic strings:

- `LegacyRmeImportStatus`: `DRAFT → UPLOADED → QUEUED → PROCESSING →
  READY_FOR_REVIEW → REVIEWED → PUBLISHED`, plus `FAILED` and `CANCELLED`, with
  an explicit `TRANSITIONS` map and terminal set (`PUBLISHED`, `CANCELLED`).
- `LegacyRmeImportPageStatus`: `PENDING → PROCESSING → READY`, plus `FAILED`,
  `PUBLISHED`, `CANCELLED`. Fail-closed: only `READY` is publishable, so an
  unprocessed page can never slip into the archive.
- `LegacyRmeRecordStatus`: `PUBLISHED → VOID` only.

## 7. Permissions, policies and branch isolation

Five permissions (snake_case, `web` guard, seeded idempotently in
`PermissionSeeder`):

```
view_legacy_rme_imports
create_legacy_rme_imports
review_legacy_rme_imports
publish_legacy_rme_imports
void_legacy_rme_imports
```

They are granted to **no operational role**. Super Admin — the designated
operator of Master Data RME — holds them through the existing `'*'` grant plus
the single global `Gate::before` bypass. A later sprint may grant them
explicitly once the runtime ships. They are classified into the existing `rme`
permission group so the permission page stays clean.

Two policies, registered in `RepositoryServiceProvider::$policies`:

- `LegacyRmeImportPolicy` — `viewAny`, `view`, `create`, `review`, `publish`,
  `cancel`;
- `LegacyRmeRecordPolicy` — `viewAny`, `view`, `void`, and `update`/`delete`
  hard-wired to `false` (immutability).

Every per-row ability additionally requires the row's origin branch to be inside
the caller's server-resolved scope (`LegacyRmeWorkspaceScope`): the governance
tier sees every RME-enabled branch, everyone else is pinned to their resolved
`BranchContext` branch, and an unresolvable scope denies everything. Rows with no
origin branch carry no provenance and are visible to the governance tier only.

`origin_branch_id` is provenance metadata, never an authorization bypass, and the
branch is never read from the request.

## 8. Audit foundation

The existing `sys_audit_logs` trail is reused through `AuditLogService` — no new
audit table and no parallel audit system. `LegacyRmeAuditService` adds the
domain's payload policy, because the shared service performs no masking of its
own: every payload is filtered against an explicit allow-list and reduced to
length-bounded scalars.

Events: `LEGACY_RME_IMPORT_CREATED`, `LEGACY_RME_DATE_SELECTED`,
`LEGACY_RME_PUBLISH_REJECTED`, `LEGACY_RME_DUPLICATE_DETECTED`,
`LEGACY_RME_PUBLISHED`, `LEGACY_RME_VOIDED`.

Never persisted: patient name, KTP/NIK, clinical content, base64 file data, a raw
PDF, an absolute filesystem path.

## 9. Tests

`tests/Feature/LegacyRme/` — 73 tests:

| File | Covers |
|---|---|
| `LegacyRmeSchemaTest` | tables, columns, nullability, unique constraints, no native-table drift |
| `LegacyRmeFoundationTest` | casts, relations, uuid generation, soft-delete posture, status vocabularies, repository bindings, fail-closed scope, feature flag default-off, audit payload policy |
| `PatientEarliestNativeRmeDateResolverTest` | the canonical cutoff and every exclusion |
| `LegacyRmeDateRuleServiceTest` | all four rules and their boundaries, timezone/leap-day/parsing, server-side recomputation, PII-free context |
| `LegacyRmePermissionTest` | permission seeding, role posture, grouping, policy matrix, branch isolation, immutability |
| `LegacyRmeNonRegressionTest` | no visit/invoice/payment/lab/SATUSEHAT side effects, no route exposed |

The suite is wired into the CI critical filter so it runs on every non-docs PR.

## 10. Deployment

No seed data and no runtime behaviour is enabled by this sprint.

```bash
php artisan migrate --force                      # 4 additive tables
php artisan db:seed --class=PermissionSeeder --force
php artisan permission:cache-reset
```

Keep `FEATURE_RME_LEGACY_PDF_ARCHIVE` false. Never run `migrate:fresh` or
`db:wipe` on the VPS.

## 11. Next sprint

`LEGACY-RME-PDF-1B` — upload runtime, page rendering, review UI, publish
workflow, private-file serving, patient-timeline integration and the doctor
viewer, all behind the same feature flag.
