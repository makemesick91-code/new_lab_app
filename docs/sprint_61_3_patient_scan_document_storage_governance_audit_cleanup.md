# Sprint 61.3 — Patient Scan Document Storage Governance, Audit & Cleanup

**Branch:** `feature/sprint-61-3-patient-scan-document-storage-governance-audit-cleanup`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (HEAD `58a833d`)
**Type:** Backend CLI governance tooling — no UI, no migration, no deploy.

## Purpose

Add safe, auditable, CLI-first storage governance over the scanned patient
identity documents (KTP scans) introduced in Sprint 61.1 / 61.1.1. The goal is
to keep the private KTP scan store **safe, auditable, clean, and controlled**
without ever exposing patient identity documents or full KTP numbers.

This sprint adds:

- A **read-only audit** command that cross-checks `mst_patient_documents` rows
  against files on the private disk and reports integrity / hygiene anomalies.
- A **safe temp cleanup** command that prunes only stale *temporary* (pre-attach)
  scan uploads — dry-run by default, `--force` required to delete.
- A governance config file with env overrides.

No business logic in the existing KTP scan capture/attach flow was changed.

## Storage paths

| Purpose | Path (under the private `local` disk = `storage/app/private`) |
|---|---|
| Final attached documents | `patient-documents/{patient_id}/...` |
| Temporary pre-attach scans | `tmp/patient-ktp-scans/{user_id}/{token}.{ext}` (+ `{token}.json` meta) |

The disk is **private**. No public URL is generated for any document. The audit
reports relative private paths only.

## Config — `config/patient_documents.php`

| Key | Default | Env override |
|---|---|---|
| `disk` | `local` | `PATIENT_DOCUMENT_DISK` |
| `private_root` | `patient-documents` | — |
| `temp_root` | `tmp/patient-ktp-scans` | — |
| `temp_ttl_hours` | `24` | `PATIENT_DOCUMENT_TEMP_TTL_HOURS` |
| `orphan_grace_days` | `7` | `PATIENT_DOCUMENT_ORPHAN_GRACE_DAYS` |
| `max_document_bytes` | `6291456` (6 MB) | `PATIENT_DOCUMENT_MAX_BYTES` |
| `allowed_document_types` | `['ktp']` | — |
| `allowed_mime_types` | `image/jpeg, image/png, image/webp` | — |

`orphan_grace_days` is reserved for the deferred `prune-orphans` command (see
below); it has no effect this sprint.

## Command 1 — `patient-documents:audit`

Read-only. **Deletes nothing.** Safe to run on production at any time.

```bash
php artisan patient-documents:audit
php artisan patient-documents:audit --json
```

Detections:

1. Document record exists but file is missing.
2. File under `patient-documents/` referenced by no record (orphan).
3. Checksum mismatch (stored `checksum` vs `sha256` of file bytes).
4. Mime mismatch (recorded `mime_type` vs detected, when safely detectable).
5. `compressed_file_size` mismatch vs actual on-disk size.
6. File path outside the allowed `patient-documents/` root (suspicious path).
7. Soft-deleted record whose file is still present.
8. Temp scan file older than `temp_ttl_hours`.
9. Unusually large scan file above `max_document_bytes`.
10. Duplicate checksum across active records (report only — never deleted).

JSON summary keys: `total_document_records`, `active_document_records`,
`soft_deleted_document_records`, `active_files_count`, `active_files_bytes`,
`orphan_files_count`, `orphan_files_bytes`, `stale_temp_files_count`,
`stale_temp_files_bytes`, `missing_files_count`, `checksum_mismatch_count`,
`mime_mismatch_count`, `size_mismatch_count`, `suspicious_path_count`,
`deleted_records_with_file_count`, `duplicate_checksum_count`,
`oversized_files_count`.

## Command 2 — `patient-documents:prune-temp`

Deletes **only stale temporary scan uploads** under `tmp/patient-ktp-scans/`.
**Dry-run by default** — `--force` is required to actually delete. Final attached
patient documents under `patient-documents/` are structurally out of reach.

```bash
php artisan patient-documents:prune-temp                                  # dry-run
php artisan patient-documents:prune-temp --older-than-hours=24 --force    # delete stale > 24h
php artisan patient-documents:prune-temp --json
php artisan patient-documents:prune-temp --older-than-hours=24 --force --json
```

- Default TTL = `config('patient_documents.temp_ttl_hours')` (24h). Minimum
  enforced TTL is 1 hour so fresh uploads are never deleted.
- A temp file's age is resolved from its meta sidecar `created_at`, falling back
  to filesystem mtime. An image and its `.json` sidecar are pruned together.
- JSON output: `dry_run`, `older_than_hours`, `would_delete_count`,
  `would_delete_bytes`, `deleted_count`, `deleted_bytes`, `temp_root`.

## Deferred — `patient-documents:prune-orphans`

**Not implemented this sprint (deferred by design).** Deleting any file under
`patient-documents/` risks destroying an active patient identity document, so the
safety bar is high. The audit already *reports* orphans (`orphan_files_count`),
which is sufficient for the pilot. If implemented later it must: dry-run by
default, require `--force`, respect `orphan_grace_days`, only delete files
referenced by **no** record (active or trashed), and never touch the temp path.

## Safety rules

- The audit command **never** deletes or mutates anything.
- `prune-temp` defaults to **dry-run**; deletion requires `--force`.
- `prune-temp` only ever operates under `tmp/patient-ktp-scans/` — final patient
  documents are never deleted automatically.
- No active patient document is ever deleted automatically.
- Duplicate-checksum files are **reported only**, never deleted.

## Privacy / access control

- No public file URL is generated.
- No KTP image bytes are emitted in any report or export.
- No full KTP number is output.
- CLI shows relative private paths plus opaque ids/checksums only.
- No web UI added.

## Recommended manual operation

1. Run the audit (read-only) and review anomalies:
   ```bash
   php artisan patient-documents:audit
   ```
2. Preview a temp cleanup (dry-run):
   ```bash
   php artisan patient-documents:prune-temp
   ```
3. If the preview looks right, perform the cleanup:
   ```bash
   php artisan patient-documents:prune-temp --force
   ```

## Scheduler note

Automatic deletion is intentionally **not** wired into the scheduler this sprint.
Run `prune-temp` manually (or via an operator-reviewed cron) only after a
dry-run looks correct. A future scheduled job is deferred.

## VPS deploy note

- **No** `migrate:fresh`, **no** `db:wipe` on VPS.
- This sprint adds **no migration**; run `php artisan migrate --force` only if a
  later change introduces one.
- Keep `storage/app/private` writable by `www-data` so the audit can read and
  `prune-temp` can delete temp files.
- After deploy, clear/rebuild config cache so the new `config/patient_documents.php`
  and registered commands are picked up:
  ```bash
  php artisan config:clear
  php artisan optimize:clear
  ```

## Validation

- `tests/Feature/RME/PatientDocumentStorageGovernanceTest.php` — 13 passed.
- Regression: `PatientKtpScan`, `RmeVisitNewPatientKtpScan`,
  `PatientDataCompletenessAuditTest` — green.
- `vendor/bin/pint --test` — passed.
- `git diff --check` — clean.
- No Blade/JS change → `npm run build` not required.
