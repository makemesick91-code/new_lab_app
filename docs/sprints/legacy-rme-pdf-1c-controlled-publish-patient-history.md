# LEGACY-RME-PDF-1C — Controlled Publish & Patient History Integration

**Branch:** `feature/legacy-rme-pdf-1c-controlled-publish-patient-history`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
(1B merge `6b9ed7aa0e9971ea74adcc69e2e5f5e439c22d11`, GO tag
`legacy-rme-pdf-1b-private-upload-rendering-go`)
**GO tag:** `legacy-rme-pdf-1c-controlled-publish-patient-history-go`

---

## 1. Scope

1B ended at `READY_FOR_REVIEW`: a legacy PDF was uploaded privately, validated,
rasterized by Poppler and rendered into private page images — but it was not yet
part of any patient's record. 1C closes that loop.

The operator flow this sprint completes:

```
Master Data RME → Impor Arsip RME Lama → upload → (queued render)
  → READY_FOR_REVIEW → review the rendered pages → REVIEWED
  → PUBLISH → immutable legacy RME record
  → appears in the patient's RME history → private read-only viewer
```

**Delivered**

| Area | Outcome |
|---|---|
| Review before publish | `POST settings/rme/legacy-imports/{import}/review` (`review_legacy_rme_imports`) |
| Publish | `POST settings/rme/legacy-imports/{import}/publish` (`publish_legacy_rme_imports`) |
| Published viewer | `GET rme/legacy-records/{record}[/source][/pages/{page}]` (`view_legacy_rme_imports`) |
| Patient history | flag-gated merged timeline card on the RME visit detail page |

**Not in scope (deliberately):** VOID runtime (no `void` endpoint exists yet),
legacy print/PDF export, a doctor-facing canvas, and any backfill of historical
imports.

**No migration.** 1A already shipped every column and constraint this sprint
writes to — including `UNIQUE(source_import_id)` on `trx_rme_legacy_records` and
the `reviewed_by`/`reviewed_at`/`published_by`/`published_at` actor columns on
`stg_rme_legacy_imports`.

---

## 2. The publish contract

### 2.1 State — review is a real gate

The 1A transition map is the contract and was **not widened**:

```
READY_FOR_REVIEW → REVIEWED      (review)
REVIEWED         → PUBLISHED     (publish)
PUBLISHED        → (terminal)
```

`LegacyRmeImportStatus::TRANSITIONS` already permitted `PUBLISHED` only from
`REVIEWED`, and `LegacyRmeImportPolicy::publish` already required
`canTransitionTo(PUBLISHED)`. 1C therefore had to add the review step rather
than invent a shortcut: an unreviewed import can never be published, and there
is no republish path.

**`Gate::before` matters here.** Super Admin — the only role that currently holds
these permissions — bypasses every policy through the single global
`Gate::before`. A policy check is therefore *not* a boundary for the actual
operator. Every rule that must hold is enforced again in the service (status,
date, pages, source file) or in the controller (VOID streaming), and the tests
assert each one *as a Super Admin*.

### 2.2 Atomicity and idempotency

`LegacyRmePublishService::publish()` runs one `DB::transaction` that opens with
`lockForUpdate` on the staging row and re-reads its status under that lock.

Three independent defences against a duplicate archive:

1. an existing record for this `source_import_id` short-circuits to that record
   (a double click is a no-op, not an error);
2. the locked status re-check refuses anything not `REVIEWED`;
3. `UNIQUE(source_import_id)` refuses a second row at the database level even if
   every application check were bypassed.

A failure anywhere unwinds the whole transaction — record, pages and the staging
status change all roll back together.

### 2.3 Date revalidation

The 1A rules are re-evaluated against a **freshly resolved** cutoff at publish
time via `PatientEarliestNativeRmeDateResolver`. The patient's native history can
change between upload and publish, and `earliest_native_rme_date_snapshot` is
evidence of what was checked at intake — never the authority for a permanent
record.

### 2.4 Files — metadata promotion, no byte movement

Publishing **moves no bytes**. 1B already writes the source PDF and every
rendered page into an opaque, private, per-import directory that is never
rewritten, and the staging row is soft-deleted rather than erased. The published
record simply points at those same private paths.

This is the reason there is no compensation logic: nothing is copied, so a
rolled-back publish cannot leave an orphan file, and a retry cannot duplicate
one.

### 2.5 Completeness checks

Publishing refuses, with a stable code and a path-free message, when:

| Code | Condition |
|---|---|
| `IMPORT_NOT_PUBLISHABLE` | status is not `REVIEWED` (or the patient is gone) |
| `IMPORT_NOT_REVIEWABLE` | status cannot reach `REVIEWED` |
| `RENDERED_PAGES_MISSING` | no pages, a page not `READY`, or missing render metadata |
| `PAGE_COUNT_MISMATCH` | fewer rendered pages than the document declares |
| `PAGE_FILE_MISSING` | a page image is no longer on the private disk |
| `SOURCE_FILE_MISSING` | the source PDF is no longer on the private disk |

**Refusals are audited *after* the transaction unwinds.** Writing the
`LEGACY_RME_PUBLISH_REJECTED` row inside the transaction would roll it back with
the refusal — the trail would silently vanish. `LegacyRmePublishRefusal` carries
the field and the PII-free audit metadata out of the transaction so the rejection
is actually recorded; it is converted to a `ValidationException` at the boundary
and never escapes as a 500.

---

## 3. Patient history integration

`LegacyRmePatientHistoryService` merges the caller's already-resolved native
visit history with the patient's **published** legacy records into one
chronological timeline.

* **Separate entities.** Nothing converts a legacy record into a `ClinicVisit`
  or a native `MedicalRecord`. The merge is a presentation projection
  (`LegacyRmeTimelineEntry`) built for one screen.
* **Published only.** Draft, queued, processing, ready-for-review, reviewed,
  failed, cancelled and VOIDed rows never appear.
* **Clinical date ordering.** `rme_date` for legacy, `visit_date` for native —
  never upload or creation time. An archive uploaded today is a document from
  years ago and sorts where it clinically belongs. Ties break deterministically
  (date → kind → id) even though the 1A date rule makes a legacy/native
  collision impossible for valid data.
* **Empty when there is nothing to merge.** With the flag off, without the
  permission, or when the patient has no archive, the service returns an empty
  collection and the card is not rendered at all — so the RME workspace looks
  exactly as it did before this sprint.

The native side is **passed in**, not re-queried: the RME workspace already
resolved it under its own authorization, and re-deriving it would create a
second, divergent definition of "the patient's visits".

---

## 4. Security posture

| Concern | How it is closed |
|---|---|
| IDOR | Records resolve through the repository with `LegacyRmeWorkspaceScope::branchIdsFor($user)`; empty scope → `whereRaw('1 = 0')`. Pages resolve **through** their record. Out of scope is 404, never 403. |
| Path traversal | The request supplies ids only. `variant` is compared to the literal `thumbnail`. Every path is a DB column written by 1B. |
| Public exposure | `assertPrivateDisk()` rejects a public disk, `visibility: public`, any `url` key and `serve: true`. Responses are `private, no-store` + `nosniff` with generic filenames. |
| Feature flag | Every 1C endpoint aborts 404 server-side when the flag is off — including inside `PublishLegacyRmeImportRequest::prepareForValidation()`, so a malformed payload cannot answer 422 and confirm the endpoint exists. |
| Mass assignment | The request contributes only `title` and `description`. Patient, branch, date, disks, paths, checksums, `source_import_id` and `status` are read from the locked staging row. |
| PII | Audit payloads pass the 1A allow-list (`failure_code`, `rule_code`, ids, dates). No KTP/NIK, no absolute path, no patient content in any message or view. |
| VOID | A voided archive **stops streaming its bytes** (404), enforced in the controller so `Gate::before` cannot bypass it. The row stays readable — retracted, not erased. |
| Method | The published viewer is GET/HEAD only; review and publish are POST with CSRF. |

### Decisions taken deliberately

* **Review is not four-eyes.** `publish()` does not require a different actor
  than `reviewed_by`. Only Super Admin currently holds either permission, so
  enforcing separation of duties would make the capability unusable for its
  only operator. Review is a mandatory *state* gate, not a second-person
  control. If four-eyes becomes a requirement, enforce it in the service (where
  `Gate::before` cannot bypass it) and grant the two permissions to different
  roles.
* **Staging pages stay viewable after publish.** Publishing moves them to
  `PUBLISHED`; `LegacyRmeImportPageStatus::VIEWABLE` is deliberately wider than
  `PUBLISHABLE` so the staging screen — the operator's evidence of what was
  reviewed — keeps working.
* **Thumbnails are not audited.** Opening an archive already writes
  `RECORD_VIEWED`, and the gallery requests one thumbnail per page; auditing
  those too would put a row per thumbnail in the trail and bury the real events.
* **Streaming honours the stored disk.** A published row records the disk it was
  written to, and `diskFor()` streams from that disk after re-asserting it is
  private — so repointing the configured disk cannot silently 404 history.

---

## 5. Verification

| Gate | Result |
|---|---|
| New 1C tests | `LegacyRmePublishTest`, `LegacyRmePatientHistoryTest`, `LegacyRmeRecordViewerTest` |
| Full LegacyRme suite (SQLite) | 237 passed |
| Full LegacyRme suite (PostgreSQL 16.14) | green — production runs 16.14 |
| RME critical regressions | `RmeDoctorCashierCompletionGate`, `RmeRoomAssignmentGate`, `MedicalRecordFinalization`, `CashierBilling`, `RmePayment`, `PatientOutstandingReceivableCarryOver`, `PatientCentricRmWorkspace` |
| `tests/Feature/RME` | 1031 passed |
| Real Poppler | `LegacyRmePopplerIntegrationTest` executes (never silently skips) |
| Pint (repo-wide) | passed |
| `git diff --check` | clean |

Two 1B assertions were **repinned**, exactly as every prior sprint in this chain
did when it superseded the previous boundary:

* `LegacyRmeNonRegressionTest` — "no review or publish endpoint" → "exactly the
  staging surface plus review and publish, and nothing else" (plus a new
  assertion that the published viewer is GET-only).
* `LegacyRmeImportAuthorizationTest` — "the publish route does not exist" → "no
  publish action is offered on an import that has not been reviewed".

A PostgreSQL-only test-harness quirk surfaced and was handled honestly: a unique
violation aborts the surrounding `RefreshDatabase` transaction, so the
constraint assertion now runs inside a nested transaction (a SAVEPOINT) and
behaves identically on both engines.

---

## 6. Deploy

**No migration. No seeder. No permission change** — 1A already defined all five
legacy permissions and Super Admin holds them through the RoleSeeder `'*'` grant.

The feature flag stays **OFF**:

```
FEATURE_RME_LEGACY_PDF_ARCHIVE=false
```

With the flag off the whole surface 404s for an authorized user and redirects a
guest to login, and the patient RME timeline card is not rendered — so the deploy
is inert by construction.

Post-deploy verification (read-only):

```bash
php artisan tinker --execute="echo app(App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard::class)->enabled() ? 'ON' : 'OFF';"
php artisan route:list | grep legacy-records
```

**Rollback:** `scripts/rollback-vps.sh legacy-rme-pdf-1b-private-upload-rendering-go`
(peels to `6b9ed7aa`). No schema change to reverse.

**Before the flag is ever enabled**, the `legacy-rme-documents` queue must be
served by the managed worker and `poppler-utils` must be present — both are 1B
requirements and are unchanged by this sprint.

---

## 7. Next

**LEGACY-RME-PDF-1D** — VOID runtime (with its reason + audit), legacy print/PDF
export, and the doctor-facing viewer.
