# POST-RME-ODONTOGRAM-STABILIZATION-1

Branch `feature/post-rme-odontogram-stabilization-1`
Base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `7eb959b1`
(the exact production runtime, GO tag `fix-rme-exam-consent-odontogram-history-3-go`).

Three residuals carried out of FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 and
FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1. No migration, no permission, no new status,
no clinical workflow redesign.

The clinical workflow authority is unchanged and re-pinned by tests:

```
Pendaftaran → Antrian → Mulai Pemeriksaan → in_progress → Consent
  UNSIGNED : active RME + active Odontogram READ-ONLY, every history READ,
             Selesai Pemeriksaan DENIED
  SIGNED   : active RME + active Odontogram writable
RME/Odontogram save or finalize → the visit STAYS in_progress
explicit "Selesai Pemeriksaan" → cashier_pending → Kasir → completed
```

---

## FIX-01 — opening the odontogram no longer creates a clinical row

### Problem

`GET /rme/visits/{clinicVisit}/odontogram` inserted a `trx_odontograms` row on
every open. Viewing is not a clinical act, and the row it left behind was real
clinical data with a `created_by` attribution.

### Root cause

`OdontogramController::show()` called `OdontogramService::getOrCreateForVisit()`,
a pure write path wrapped in a transaction. Two things made it worse than a
tidiness problem:

- the route is gated by `permission:view_clinic_visits|manage_clinic_visits` and
  the controller authorized `create`, which only requires `canView()` — so a
  **read-only operator provably wrote a row**;
- `getOrCreateForVisit()` never consulted `RmeVisitConsentService`, unlike
  `updatePlaceholder()` and `finalize()` — so **a row was created even when
  consent forbade authoring anything at all**.

The codebase had already grown two workarounds rather than removing the cause:
`OdontogramService::hasRecordedTeeth()` filters empty drafts out of patient
history, and `OdontogramRepository::patientHistoryForBranches()` carries a
`whereNotNull('tooth_map_payload')` for the same reason.

### Why it was not a one-line deletion

Creation-on-view was the **only** path in the whole HTTP surface that created a
native odontogram. The save form's action *is* the odontogram id
(`route('rme.odontograms.update', $odontogram)`), and `update`/`finalize`/`print`
are all bound to an existing model. Deleting the call alone would have left the
doctor unable to chart anything, and the page would have 500'd generating a URL
for a model with no key.

### Change

| Layer | Change |
|---|---|
| Service | `findForVisit()` (pure read) and `draftForVisit()` (saved chart, else an **unsaved** instance for rendering). `saveForVisit()` is the new create-on-first-write entry. `getOrCreateForVisit()` is retained as the create primitive but is no longer reachable from the read path. |
| Controller | `show()` resolves through `draftForVisit()`. New `store()` handles the first save. |
| Policy | New `author(User, ClinicVisit)` — the write-level counterpart of `create`, requiring `canManage()` like `update()` does. |
| Route | `PATCH rme/visits/{clinicVisit}/odontogram` → `rme.visits.odontogram.store`, inside the existing `permission:manage_clinic_visits` + `visit.room` group. |
| View | Form action, print button and finalize button branch on `$odontogram->exists`. |

`draftForVisit()` returns an unsaved model rather than `null` deliberately:
`OdontogramPrintFormatter::format()`, `dmftCounts()` and the view are all typed
against a non-nullable `Odontogram`, so a transient instance removes the write
without pushing null-handling into a formatter, a view and a print template.

**Consent is asserted before the insert.** `saveForVisit()` calls
`assertOdontogramAuthoringAllowed()` *first*, so a refused write leaves no trace
— otherwise the side effect would simply have moved from the read path to the
write path.

### Behaviour that deliberately changed

- **Print button** is hidden until a chart is saved. The print route is
  model-bound, so before the first save the button could only 404.
- **SATUSEHAT dental readiness.** An empty auto-created row made
  `odontogram_present = true` with zero supported resources, which resolves to
  the blocking `DENTAL_MAPPING_BLOCKED`. With no row the candidate reports the
  non-blocking, informational `dental_odontogram_missing` instead. This removes
  false blocking; it does not weaken any gate. Odontogram-less visits were
  already designed to be dental-informational
  (`SatusehatOperationalStatusResolver`: *"an absent odontogram is
  informational, not blocking"*).
- **Legacy Odontogram import cutoff — prospective only, and NOT changed here.**
  `LegacyOdontogramNativeReferenceRepository::earliestVisitWithOdontogramForPatient()`
  uses `whereHas('odontogram')`, so it counts *any* row including the empty
  drafts this fix stops creating. Rows already in production are untouched, so
  no existing patient's cutoff moves. Going forward the cutoff is derived only
  from visits that were actually charted, which matches the semantics
  `hasRecordedTeeth()` and the history query already use. **Deliberately left
  alone:** narrowing that resolver to non-empty payloads would change
  admissibility for existing production data, which is an owner decision and a
  separate sprint, not a side effect of this one.
  - **Superseded (2026-08-22).** LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1 is
    that separate sprint. The cutoff now requires meaningful clinical content
    (`Odontogram::hasRecordedTeeth()`). Measured read-only on production first:
    of 15 patients with a native row, 14 kept an identical bound, 0 gained
    eligibility, 1 lost a bound that was never real. The paragraph above is
    retained as the record of this sprint's decision, not as current fact.

---

## FIX-02 — the tracked SQLite artifact at the repository root

### Problem

A 2,924,544-byte file named `asia_dental_lab` was tracked at the repository
root, added by PR #321 (FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1, commit `2913c4a`).

### Audit (re-run, not inherited)

It is a genuine SQLite 3 database, and it is **schema-only**:

| | |
|---|---|
| Tables | 129 |
| Tables with rows | **2** — `migrations` (167) and `sqlite_sequence` (25) |
| `mst_patients`, `users`, `mst_doctors`, `trx_clinic_visits`, `trx_medical_records`, `trx_odontograms`, `sys_audit_logs`, `sessions`, `notifications`, `trx_rme_payments`, `trx_rme_invoices` | **0 rows each** |
| Secrets | none — `password` / `token` appear only as column names in DDL (2 tables each); no api key, bearer token, private key or email pattern |
| Staleness | records 167 migrations against 172 files in the tree |

**Not a security incident.** No patient, user, clinical, payment or audit row,
and no credential. It is a stale build artifact.

### Root cause

`config/database.php:37` resolves the sqlite connection's file from
`DB_DATABASE`:

```php
'database' => env('DB_DATABASE', database_path('database.sqlite')),
```

A local environment file legitimately sets `DB_DATABASE` to the **PostgreSQL
database name** `asia_dental_lab`. Any command run with `DB_CONNECTION=sqlite`
therefore resolves that name as a path *relative to the working directory* and
creates a SQLite database of that name at the repository root, which a
`git add -A` then swept in.

### Change

1. `git rm --cached asia_dental_lab` — untracked; the local file is left on disk.
2. An anchored `/asia_dental_lab` rule in `.gitignore`, with the mechanism
   documented beside it. It cannot be `*.sqlite` / `*.db`: the file has no
   extension. `database/.gitignore` already covers `*.sqlite*` for the
   legitimate `database/database.sqlite` fixture path.
3. `tests/Feature/Architecture/RepositoryArtifactHygieneTest.php` — the actual
   recurrence guard. It asserts by **file content** (the 16-byte
   `SQLite format 3\0` header) that no SQLite database is tracked at the
   repository root, so it catches a future stray under any name. Verified to
   fail when the artifact is force-added, so it is not a vacuous guard.

**No history rewrite**, and every `asia_dental_lab` / `asia_dental_lab_pilot`
*PostgreSQL database name* — in the environment example, `config/ci_runner.php`,
`scripts/backup_postgres.sh`, `scripts/restore_postgres.sh` and the docs — is
untouched. A test pins that too.

Not done, and deliberately: hardening `phpunit.xml` with `force="true"` on
`DB_DATABASE` would override the `DB_DATABASE: testing` / `daengtisia_ci`
exported by six CI job blocks while `DB_CONNECTION` stays `pgsql`, breaking
every database-touching test in every gate.

---

## FIX-03 — RM number and registration date now follow the clinical calendar

### Problem

This is the residual FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 recorded and explicitly
declined to fix:

> `StoreClinicVisitRequest` still falls back to a UTC `today()` when composing a
> new patient's RM number and no `registered_at` is supplied. […] It is
> reported, not fixed.

### Root cause and true scope

`config/app.php:68` hard-codes `'timezone' => 'UTC'` **on purpose** — technical
instants stay UTC, and `config/clinical.php` is the separate authority for the
clinical calendar (`Asia/Makassar`, via `ClinicalClock`). So `Carbon::today()`
is the UTC calendar day, and for the first **eight hours of every clinical day**
the UTC date is still yesterday. On 1 January that is a different **year** — and
the year is baked permanently into the patient's Nomor RM.

The residual was broader than reported. Five sites derived the registration day
from the process clock:

| Site | Was |
|---|---|
| `PatientMedicalRecordNumberService::composeForRegistration()` | `Carbon::now()` fallback |
| `PatientService::resolveRegisteredAt()` — the **persisting** site | `Carbon::today()` |
| `StorePatientRequest` | `Carbon::today()` |
| `UpdatePatientRequest` | `Carbon::today()` |
| `StoreClinicVisitRequest` | `Carbon::today()` (the one that was reported) |

Two concrete consequences:

1. A patient registered at 07:00 WITA on 1 Jan 2027 received
   `DG-{BRANCH}-2026-…` and `registered_at = 2026-12-31`.
2. **One registration produced two different years.** `ClinicVisitService`
   already adopted `ClinicalClock` for `visit_date`, so the visit was dated 2027
   while the patient created beside it was numbered 2026.

### Change

All five sites resolve "today" through `ClinicalClock`. A caller that *supplies*
a date is trusted verbatim — that value is a calendar date a human entered or
the workflow stamped, and timezone-converting it would corrupt history.

**Not changed:** the RM format (`DG-{KODE_CABANG}-{TAHUN}-{NOMOR}`), the parser,
branch authority, and every existing identifier. No patient is renumbered. The
fix is prospective only; no reconciliation migration is included, and none was
found to be needed (see *Existing records* below).

`LegacyPatientImportService` is untouched: it derives the year from the
operator-supplied Timestamp column, which is already a calendar date.

### Existing records

No RM number is rewritten. Only registrations that fall in the 00:00–08:00 WITA
window and supplied no explicit `registered_at` could carry a stale year, and
only a 1 January registration in that window could carry the wrong *year* at
all. Nothing is silently renumbered; if the owner wants historical
reconciliation it is a separate, evidenced sprint.

---

## Governance / CI

An Odontogram-only change classified as `runtime_app` with no module flag, and
`Odontogram` was absent from the NSF-R011 critical `--filter` (only
`LegacyOdontogram` was present, which does not match `OdontogramTest`). The
Selective Module Gate has no RME step, so this sprint's own core tests would not
have run in CI. `Odontogram` and `RepositoryArtifactHygiene` were added to the
critical filter in **both** the GitHub-hosted and the self-hosted job.

`config/sprint_regression_matrix.php` already declares category `rme` with
`filters => ['ClinicVisit','MedicalRecord','Odontogram','Rme']` and
`ci_jobs => ['critical_test_gate','selective_module_gate']`, but its only
consumer is `SprintTestPlanner` — no CI job reads it. The critical-gate half of
that claim is now true. The `selective_module_gate` half remains unimplemented
and is recorded here as a known governance gap, not silently closed.

Full Suite is **not** run: the temporary global policy is ACTIVE, and
`full_suite_gate` is structurally unreachable on a `pull_request` event.

---

## Tests

| File | Tests |
|---|---|
| `tests/Feature/RME/PostRmeOdontogramStabilizationTest.php` | 28 — new |
| `tests/Feature/RME/PostRmeRmNumberClinicalClockTest.php` | 11 — new |
| `tests/Feature/Architecture/RepositoryArtifactHygieneTest.php` | 5 — new |
| `tests/Feature/RME/OdontogramTest.php` | 2 contracts reversed, 1 added, 2 print cases now chart first |
| `tests/Feature/RME/OdontogramAdditionalFieldsTest.php` | 2 cases moved to the first-save route |

Both new behavioural suites were **negative-controlled**: the FIX-01 guard was
confirmed to fail when the artifact is force-tracked, and the FIX-03 boundary
tests were confirmed to fail (4 of 11) against the pre-fix code and pass after,
so neither suite is vacuous.

### Known pre-existing failures — not from this sprint

Two cases in
`tests/Unit/Services/Monitoring/PilotPerformanceSnapshotLogAnalyzerTest.php`:

- *"it returns zero fail-on-watch when only historical logs would have …"*
- *"it keeps overall ok for historical-only grouped stack traces via service"*
  (`overall_status` is `WATCH` instead of `OK`)

Reproduced **identically — 2 failed, 7 passed — on a pristine detached worktree
at the production SHA `7eb959b1` with zero changes**. They are
environment-dependent (the WATCH originates outside the logs section) and are
reported, not fixed here.

### PostgreSQL verification

SQLite green is not sufficient here: this exact area already produced a
production-only defect (comparing the `jsonb` `tooth_map_payload` to a string is
a hard PostgreSQL error that SQLite never surfaces). The suites were therefore
re-run against a real PostgreSQL server, on a scratch database created and
dropped for the purpose:

| Run | Result |
|---|---|
| `PostRmeOdontogramStabilization\|PostRmeRmNumberClinicalClock\|RepositoryArtifactHygiene\|Odontogram` | **362 passed / 1149 assertions, 0 failed** |
| `Patient\|ClinicVisit\|LegacyOdontogram\|RmeExamConsent\|MedicalRecordFinalization` | **734 passed / 2197 assertions, 0 failed** |

**1,096 tests on real PostgreSQL, zero failures.** The scratch database was
dropped afterwards; `asia_dental_lab` and `asia_dental_lab_test` were not
touched.

The server available locally is **PostgreSQL 18.6**; production runs **16.14**.
That difference is recorded rather than glossed: the class of defect this guards
against — `jsonb` operator resolution, `NULL` semantics, `lockForUpdate`, date
comparison — behaves identically across both majors, and CI additionally runs
the critical gate against a pinned `postgres:16` service.

### Adversarial review findings addressed

Three independent review lenses ran over the staged diff, each finding
adversarially verified. **CRITICAL = 0, HIGH = 0.** The security lens concluded
it *"could not construct any path that creates a `trx_odontograms` row without a
signed consent, and the new `author` ability is exactly as strong as the
`update` it mirrors."* The LOW findings that were acted on:

| Finding | Action |
|---|---|
| Unlocked find-then-create in the new first-save path: a concurrent double-submit would violate the `UNIQUE` on `clinic_visit_id`, roll back the transaction, and lose the doctor's charted teeth to a 500. More reachable than before, because creation moved from the page GET to the Save button. | `saveForVisit()` now takes the create-or-update decision under `findByClinicVisitForUpdate()` (a new repository method with `lockForUpdate`). The second save updates the winner's row instead of failing. |
| `getOrCreateForVisit()` left public and consent-free with zero production callers — "a docblock is not a boundary", and a documented-but-reachable create path is exactly what produced this defect. | Marked `@internal` with the reasoning, and the claim is now **enforced**: `RepositoryArtifactHygieneTest` fails if any file under `app/` outside `OdontogramService` references it. |
| The Legacy Odontogram cutoff shift was documented but not pinned by any test. | Three tests added: opening a page no longer anchors the cutoff, saving a chart does, and a pre-existing empty row still does (so no existing patient's admissibility moved). |
| Rule 110 invalidated a sentence in rule 109 §2 without declaring supersession — and the same stale claim also lived in two code comments read *before* any rule file. | Explicit supersession clause added to rule 110; both stale comments in `OdontogramService` and `OdontogramRepository` corrected. |
| Manifest resolved to `WATCH`: `deploy_required: true` with no `go_tag`, and the rewrite dropped `full_suite_status` / `inherits_*`. | All four keys restored. `sprint:manifest-check` → **GO**; `sprint:scope-audit` → **GO**. |
| `PatientService` resolved `ClinicalClock` via the service locator while its sibling in the same diff used constructor injection. | Constructor injection, consistently. Verified no site constructs either service with `new`. |
| The CI `--filter` matches case-insensitively against the whole `Class::method` name, so bare `Odontogram` also matches lowercase text in Pest *descriptions* (+208 tests, 15 of them partial-class selections in RME-adjacent files). | Not a defect — the gate is strictly wider, which is the safe direction. A comment now records the semantics so a future prune does not silently drop coverage. |

Consciously **not** changed: moving the Blade's save-target branch into the
controller (the file's existing `$canUpdate` / `$canFinalize` computation sets
the precedent, and the reviewer classed it "acceptable, not a violation"), and
narrowing `LegacyOdontogramNativeReferenceRepository` to non-empty payloads
(changes admissibility for existing clinical data — an owner decision).

### Test-infra note, re-confirmed the hard way

Never run two `artisan test` processes against the same worktree. `Storage::fake`
wipes the shared testing disk, and overlapping runs of the same filter reported
**2, then 5, then 2** failures — the three extra were phantom LegacyRme
PDF/void/closure failures that pass under isolation. Kill
`pestphp/pest/bin/pest` and verify isolation before diagnosing anything.
