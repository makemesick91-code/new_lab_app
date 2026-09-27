# DIAGNOSE-AND-CLEAN-LEGACY-PATIENT-NIK-CONFLICT-1

**Date:** 2026-09-27
**Type:** production data diagnosis + owner-authorized scoped cleanup
**Code change:** none — the validator was proven correct
**Deploy:** none — no code changed, so no redeploy was performed

## Authority

| Item | Value |
| --- | --- |
| Canonical branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Canonical HEAD | `b4ab5ef59964b160e01184173f7d6cd8c07844e7` |
| Production HEAD | `e65efb67d60b6b1c7dd7c75efd5dc2e705e34b4d` |
| Production tag | `revision-legacy-patient-staged-verification-cancel-1-go` (exact match) |

Production trailed canonical by exactly one commit, `b4ab5ef5`, which is
documentation only. **Production runtime code was identical to canonical.** The
GO tag is an ancestor of the canonical tip.

## The reported failure

Operators uploading a Legacy Patient CSV saw
`Nomor KTP sudah terdaftar pada pasien lain.`

The message has exactly one runtime source:
`app/Modules/Patient/Services/LegacyPatientImportService.php:211`, inside
`validateAndMapRow()`:

```php
$ktp = $this->digitsOnly($values['ktp_number'] ?? '');
if ($ktp !== '') {
    if (mb_strlen($ktp) > 16)                 { /* length error */ }
    elseif (in_array($ktp, $seenKtp, true))   { 'Nomor KTP duplikat di dalam file.' }
    elseif (Patient::withTrashed()->where('ktp_number', $ktp)->exists()) {
        $errors[] = 'Nomor KTP sudah terdaftar pada pasien lain.';
    }
}
```

Tables searched: **`mst_patients` only, including soft-deleted rows.** Staging is
never consulted. The sibling composed-RM check
(`PatientMedicalRecordNumberService::exists()`) is the same shape on
`medical_record_number`.

## Root cause — classification B (stale canonical patient owns the NIK)

Measured, not inferred:

| Measurement | Value |
| --- | --- |
| `mst_patients` total | 1519 |
| …active (`deleted_at IS NULL`) | **0** |
| …soft-deleted | **1519** |
| …holding a KTP | 1318 |
| `import_batch_id = 26` | **1519 / 1519** |
| created window | 2026-09-27 09:00:09 → 09:00:14 |
| deleted window | 2026-09-27 09:06:10 → 09:06:11 |
| rows in all 17 patient-dependent tables | **0** |

Batch timeline:

| Batch | Status | Rows | Errors | Committed | Rolled back |
| --- | --- | --- | --- | --- | --- |
| 25 | cancelled | 1519 | **1** (`Tanggal Lahir tidak boleh di masa depan.`) | — | — |
| 26 | rolled_back | 1519 | 0 | 09:00 → 1519 patients | 09:06 → 1519 soft-deleted |
| 27 | cancelled | 1519 | **1406** | — | — |

Batch 27's entire error set, after grouping:

| Message | Rows |
| --- | --- |
| `Nomor KTP sudah terdaftar pada pasien lain.` | 1318 |
| `Nomor RM final DG-SPN4-<…> sudah digunakan pasien lain.` | 520 |

Ownership of every one of those conflicts:

| Owner | KTP conflicts | RM conflicts |
| --- | --- | --- |
| batch-26 soft-deleted patient | **1318 / 1318** | **520 / 520** |
| any **active** patient | **0** | **0** |
| any other provenance | **0** | **0** |
| unmatched in `mst_patients` | **0** | — |

**Exact cause.** `rollback()` soft-deletes the patients it created; both duplicate
checks include trashed rows; `discard()` refuses a rolled-back batch. No
application path releases the identity space. So the commit → rollback cycle on
batch 26 permanently occupied 1318 NIKs and 520 composed RMs, and re-uploading the
same data could no longer succeed. Rollback is **not idempotent with respect to
re-import**.

**Not a code defect.** A canonical patient row owning the NIK is a true conflict;
reporting it is correct behaviour. The defect is the orphaned data plus a missing
purge capability, not the validator.

**Relaxing the check was never an option.** `mst_patients` carries **non-partial**
unique indexes on both columns:

```
mst_patients_ktp_number_unique             btree (ktp_number)
mst_patients_medical_record_number_unique  btree (medical_record_number)
```

Neither excludes soft-deleted rows, so the reservation is enforced at the database
layer as well as the application layer. Changing the validator to
`->whereNull('deleted_at')` would have turned these 1838 validation messages
(across 1406 error rows) into an `SQLSTATE 23505` unique violation at commit — and
on PostgreSQL that aborts the entire import transaction. Cleanup was the only
correct path.

## Causes ruled out with evidence

| Candidate | Verdict | Evidence |
| --- | --- | --- |
| A — real patient owns the NIK | **No** | 0 active patients; 0 conflicts owned by an active row |
| C — staging reserves identity | **No** | validator never queries staging; batch 25 produced 1 error while batches 12–24 held ~19 000 staging rows with the same NIKs |
| D — cancelled batch participates | **No** | same evidence as C |
| E — duplicate NIK inside the source | **No** | 0 within-source duplicate NIK groups in batch 27 |
| F — row collides with itself | **No** | in-file duplicates use the separate `$seenKtp` accumulator; canonical lookup cannot see staging |
| G — normalization defect | **No** | 0 stored non-digits, 0 scientific-notation source cells; 201 blank cells → 201 `NULL`, 0 empty strings; the single non-digit cell was `/`, correctly normalized to blank |
| H — index / data drift | **No** | conflicts matched `mst_patients` rows exactly; 0 unmatched |

## Backup

| Artifact | Value |
| --- | --- |
| DB dump | `storage/app/backups/deploy/pre_legacy_patient_nik_cleanup_20260927-091712.dump` |
| Size | 4 408 565 bytes |
| SHA256 | `4ebd9e32d9214980d29294058b1441ed620cc7fa00e222cd1586b2f8a61363b2` |
| `pg_restore -l` | 2110 entries; `mst_patients` + both staging tables present |
| Source files | `…_20260927-091712_sourcefiles.tar.gz` |
| Size / SHA256 | 1 310 368 bytes / `e3e8f3b7b9fa452746066cbde52d5926ac63d9df7411d03a470d4064124fd4c1` |
| Contents | 16 CSVs — one per batch |

All 16 batch source files were present on disk with **SHA256 matching
`file_hash`**, so the imported content is fully reconstructable. No prior backup
was overwritten.

> Path note. The `local` disk root is `storage/app/private`, so the real path is
> `storage/app/private/legacy-patient-imports/`. Probing `storage/app/…` reports
> all 16 files as `FILE_MISSING`, which reads as lost evidence. The prior revision
> closure had already recorded this correctly ("the `local` disk root is
> `storage/app/private`, not `storage/app`"); this task's first probe used the
> wrong prefix anyway and had to be re-run. The correct prefix is also already
> used by `docs/operations/full-patient-estate-reset-1-runbook.md`.

## Cleanup authorization gate

| Check | Result |
| --- | --- |
| `REAL_OPERATIONAL_PATIENT` | **0** |
| `UNKNOWN` with clinical/financial activity | **0** |
| `LEGACY_IMPORT_STALE` | 1519 (all of them) |
| Rows in any of the 17 FK-dependent tables | **0** |

Gate passed. Scope chosen: **minimal and surgical** — only the 1519 orphaned
soft-deleted patient rows. The 16 batches and 22 862 staging rows were left
untouched: they are audit evidence and they provably do not participate in
duplicate detection.

## Cleanup executed

Manifest:

| Table | Expected | Selection rule | Dependency reason |
| --- | --- | --- | --- |
| `mst_patients` | 1519 | `import_batch_id = 26 AND deleted_at IS NOT NULL` | holds the conflicting `ktp_number` and `medical_record_number` |
| `mst_patient_documents` | 0 | `patient_id IN (target)` | `CASCADE` child; table empty |
| 15 × `RESTRICT` children | 0 each | `patient_id IN (target)` | must be 0 or the delete is blocked |
| `trx_satusehat_data_quality_issues` | 0 | `patient_id IN (target)` | `SET NULL` child; table empty |

`EXPECTED_TOTAL = 1519`.

One transaction, four fail-closed `RAISE EXCEPTION` guards: exact target count;
no active patient in scope; no patient outside scope; and a loop over every FK
referencing `mst_patients` **enumerated from `information_schema` at run time**
asserting 0 dependent rows. Then the scoped `DELETE`, with
`GET DIAGNOSTICS ROW_COUNT` compared against the expectation before `COMMIT`.

Result: `MANIFEST OK: mst_patients deleted actual=1519 expected=1519`, all 17
guard-4 probes `= 0`, `COMMIT`, exit 0.

No `migrate:fresh`, no `db:wipe`, no global `TRUNCATE`, no `CASCADE`, no FK
disable, no sequence reset.

## Post-cleanup estate

| Measurement | Before | After |
| --- | --- | --- |
| `mst_patients` (any state) | 1519 | **0** |
| Visits / medical records | 0 | 0 |
| Import batches | 16 | **16** (preserved) |
| Staging rows | 22 862 | **22 862** (preserved) |
| `sys_audit_logs` | 1018 | **1018** (preserved) |
| NIK conflicts vs newest source | **1318** | **0** |
| Composed-RM conflicts | **520** | **0** |

Protected domains, all byte-identical to the pre-mutation baseline: users 32,
roles 19, permissions 168, `model_has_roles` 33, `role_has_permissions` 475,
branches 6, clinic rooms 17, doctors 29, migrations 194.

## Fresh upload readiness

The conflict was re-tested by replicating the validator's two canonical queries
verbatim against all 1318 distinct source NIKs and all batch-27 composed RMs:
both return **0**. With `mst_patients` empty in every state, both
`withTrashed()` lookups return false for any possible input — a total proof, not
a sample.

Batch 27's error set contained **only** the two conflict types, so the same source
file now validates with 0 errors. No patient row was created to prove this, and
**no new batch was confirmed or imported** — final import remains a separate
owner-authorized operational action.

## Health

* Migrations: 194, unchanged. No migration was run.
* `https://daengtisia.online` — `/login` 200, `/health/live` 200, `/health/ready`
  200, `/health/lb` 200.
* `storage/logs/laravel.log` 1 420 893 bytes before and after — **zero new log
  bytes**, therefore zero new `ERROR`/`CRITICAL`/`SQLSTATE` entries.
* `APP_ENV=pilot`, `APP_DEBUG=false`, maintenance off.

## Recommended follow-up (not done here)

`BUGFIX-LEGACY-PATIENT-ROLLBACK-IDENTITY-RELEASE-1` — an audited "purge
rolled-back batch" operation that hard-deletes import-created patients only when
every FK-dependent table is empty for them, replacing the manual database work
this task had to perform. Until it exists, a post-rollback re-import is an
operator-escalation path. See rule
`.cursor/rules/169-legacy-patient-rollback-identity-reservation.mdc`.

Optional housekeeping, deliberately not performed: 16 discarded/cancelled batches
and 22 862 staging rows remain. They block nothing and are audit evidence.
