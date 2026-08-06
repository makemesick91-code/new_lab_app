# LEGACY-RME-PDF-1B — Private Upload Runtime, Validation, Queue & Page Rendering

**Branch:** `feature/legacy-rme-pdf-1b-private-upload-rendering`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`)
**Baseline:** LEGACY-RME-PDF-1A merge `0b37ce4b477a9a5ebb698c56c81a9a0486f3ece7`, GO tag `legacy-rme-pdf-1a-schema-permission-date-rules-go`
**GO tag:** `legacy-rme-pdf-1b-private-upload-rendering-go`

---

## 1. What this sprint delivers

The runtime that turns an uploaded historical PDF into a staged, rendered archive
document:

```
Super Admin → Master Data RME → Impor Arsip RME Lama
  → pick patient (Nomor RM)
  → choose the legacy RME date MANUALLY from the document
  → optional origin branch
  → upload PDF
  → validate (bytes, size, magic, checksum, duplicate, optional malware scan)
  → store privately
  → QUEUE
  → inspect (pdfinfo) → rasterize (pdftoppm) → thumbnails → page rows
  → READY_FOR_REVIEW
```

`READY_FOR_REVIEW` is the **furthest** a document goes in this sprint. It is not
yet the patient's archived record.

### Deliberately NOT implemented

Review approval, publish, promotion into `trx_rme_legacy_records`, the patient
RME timeline, the doctor viewer, legacy print, annotation/pen/eraser/canvas, OCR,
automatic date extraction, final normalized visual duplicate decisioning, and
VOID runtime. There is **no publish route** and no publish action in the UI — a
test asserts this.

---

## 2. Schema

**No migration.** 1A already shipped every column this sprint writes to
(`stg_rme_legacy_imports`, `stg_rme_legacy_import_pages`), including the
rendering columns it deliberately left nullable for exactly this sprint.

---

## 3. Private storage

New disk `legacy_rme_private` (`config/filesystems.php`):

```php
'legacy_rme_private' => [
    'driver' => 'local',
    'root' => storage_path('app/legacy-rme-private'),
    'serve' => false,
    'visibility' => 'private',
    'throw' => true,
],
```

**Why its own disk instead of reusing `local`.** Laravel registers a
`storage.local` route for the `local` disk (`'serve' => true`), so anything under
`storage/app/private` is in principle addressable by a signed framework URL. The
legacy archive is clinical evidence that must only ever be reachable through the
policy-gated streaming route, so it gets a root **outside** the `local` disk root
and `serve` disabled. A test asserts both properties.

Layout — every segment is opaque (an integer surrogate key and a server-generated
UUID); no name, KTP/NIK, phone, diagnosis or doctor ever appears in a path:

```
rme-legacy/imports/p{patient_id}/{import_uuid}/
├── source/source.pdf
├── pages/page-0001.png
├── thumbnails/page-0001.png
└── processing/{run-uuid}/     ← removed in a finally block, always
```

`LegacyRmeStorageService::assertPrivateDisk()` refuses a configured disk that is
in the forbidden list, carries `visibility: public`, exposes a `url`, or has the
framework `serve` route enabled — a misconfiguration fails loudly rather than
silently publishing patient documents.

---

## 4. PDF pipeline

Two contracts, so no test depends on an external binary:

| Contract | Production | Test double |
|---|---|---|
| `LegacyRmePdfInspectorInterface` | `PopplerLegacyRmePdfInspector` (`pdfinfo`) | `FakeLegacyRmePdfInspector` |
| `LegacyRmePdfRasterizerInterface` | `PopplerLegacyRmePdfRasterizer` (`pdftoppm`) | `FakeLegacyRmePdfRasterizer` |
| `LegacyRmeMalwareScannerInterface` | `ConfiguredLegacyRmeMalwareScanner` (off by default) | — |

**Process safety.** `LegacyRmeProcessRunner` is the only place this module
executes a binary, always as an **argument array** through Symfony `Process`
(which `execve`s directly — there is no shell to inject into), always with an
explicit timeout, and never with an interpolated string. Binary names come from
config only. Diagnostics (stderr, exit code) go to the application log; they are
never put on the exception, because that message is both rendered in the UI and
persisted to `failure_message`.

**Thumbnails use Poppler `-scale-to`, not a PHP image extension.** This is a
deliberate decision: the local dev machine has no GD, and adding an image
dependency for a downscale that `pdftoppm` already does would make rendering
behave differently per environment. Page dimensions are validated with
`getimagesize()`, which is a core function and needs no extension. Thumbnails are
**PNG**, not WebP, for the same portability reason. A thumbnail failure degrades
to "no thumbnail" rather than losing an otherwise perfectly rendered document.

**Output normalization.** `pdftoppm` zero-pads its numeric suffix to the width of
the page count (9 pages → `-1`, 10 pages → `-01`), so output is always discovered
by scanning the directory and parsing the suffix, never by assuming a filename. A
real-Poppler test covers the 12-page padded case.

**Stable failure codes** (`LegacyRmePdfFailure`): `INVALID_PDF`,
`PDF_HEADER_INVALID`, `PDF_INSPECTION_FAILED`, `PDF_ENCRYPTED`,
`PDF_PASSWORD_PROTECTED`, `PDF_PAGE_COUNT_INVALID`, `PDF_PAGE_LIMIT_EXCEEDED`,
`PDF_DIMENSION_LIMIT_EXCEEDED`, `PDF_FILE_TOO_LARGE`, `PDF_MALWARE_DETECTED`,
`PDF_STORAGE_FAILED`, `PDF_PROCESS_TIMEOUT`, `PDF_RENDER_FAILED`,
`PAGE_OUTPUT_COUNT_MISMATCH`, `PAGE_IMAGE_INVALID`, `RENDER_SIZE_LIMIT_EXCEEDED`,
`SOURCE_FILE_MISSING`, `DUPLICATE_SAME_PATIENT`, `DUPLICATE_OTHER_PATIENT`,
`IMPORT_NOT_PROCESSABLE`, `IMPORT_NOT_RETRYABLE`, `IMPORT_NOT_CANCELLABLE`.
Callers branch on the **code**, never the message.

---

## 5. Upload order, and why

```
feature flag
→ 1A date rules (never re-derived here)
→ origin branch validation
→ structural file validation + server-side SHA-256
→ exact-file duplicate precheck
→ optional malware scan
→ store the private source PDF
→ DB transaction: create the staging row
→ AFTER COMMIT: dispatch the job
```

The file is written before the transaction because a filesystem write cannot
participate in it; if the transaction then fails, the orphan file is removed in
the catch block (tested). The job is dispatched only after commit, so a worker
can never pick up an id that is not yet visible.

**The malware scan runs at upload, not in the job.** This is stronger than
scanning later: infected bytes are never stored at all. Re-scanning the same
immutable bytes on every retry would add cost without adding security.

---

## 6. Exact-file duplicate policy

Decided on the **server-computed** SHA-256 — the filename is irrelevant in both
directions.

| Colliding row | Same patient | Different patient |
|---|---|---|
| PUBLISHED record | block | block |
| **VOID record** | **allow** (audited) | **allow** (audited) |
| Active staging (DRAFT…REVIEWED) | block | block |
| FAILED staging | block, point at **Retry** | allow |
| CANCELLED staging | allow | allow |

**Why VOID does not block.** 1A defines the only correction of a published record
as *"VOID with a reason plus a fresh import"*. Blocking on a VOID collision would
make that documented correction path impossible, so the collision is audited
instead of refused.

**Why a FAILED/CANCELLED row for a *different* patient does not block.** "Wrong
patient chosen — cancel and re-upload against the right one" is the intended
correction; blocking it would strand the document.

---

## 7. Queue processing

`ProcessLegacyRmePdfImport` extends the ENT-5 `EnterpriseQueueJob`, implements
`ShouldBeUnique`, runs on the dedicated `legacy-rme-documents` queue, and carries
**only the import id** — never a model and never bytes.

* **Claim under a lock.** The status transition to `PROCESSING` is a single
  locked transaction, so two workers can never both start the same import. A
  duplicate delivery is a no-op, not an error.
* **Idempotent.** Stale rendered output and stale page rows are removed before
  rendering, and pages are upserted on `UNIQUE(legacy_import_id, page_number)`,
  so a retry converges on exactly one row per page.
* **Failure is truthful.** Any failure lands the import in `FAILED` with a stable
  code and a safe message. The queue is not asked to retry, because the
  operator's explicit Retry (`FAILED → QUEUED`, a legal 1A transition) keeps a
  human in the loop; queue-level retries still cover a worker that dies before it
  could record anything (`failed()` → `markFailedAfterExhaustedRetries`).
* **Stuck-worker recovery.** A `PROCESSING` import whose worker never reported
  back becomes reclaimable after twice the process timeout plus a margin.

**Retry is only available from `FAILED` (or a stale `PROCESSING`).** A
successfully rendered import is *not* re-runnable: the 1A map has no
`READY_FOR_REVIEW → QUEUED` edge and this sprint deliberately does not invent
one, nor abuse `FAILED` to fake it. A rendered document is corrected by
cancelling and re-uploading.

---

## 8. HTTP surface

All under `/settings/rme/legacy-imports`, route names `settings.rme.legacy-imports.*`:

| Method | Path | Name | Permission |
|---|---|---|---|
| GET | `/` | `index` | `view_legacy_rme_imports\|create_legacy_rme_imports` |
| GET | `/create` | `create` | `create_legacy_rme_imports` |
| POST | `/` | `store` | `create_legacy_rme_imports` |
| GET | `/{import}` | `show` | view/create |
| GET | `/{import}/status` | `status` | view/create |
| GET | `/{import}/source` | `source` | view/create |
| GET | `/{import}/pages/{page}` | `pages.show` | view/create |
| POST | `/{import}/retry` | `retry` | `create_legacy_rme_imports` |
| POST | `/{import}/cancel` | `cancel` | `create_legacy_rme_imports` |

**No new permission** — the five 1A permissions are the boundary.

**The group is deliberately NOT nested inside `manage patients`.** 1A defines the
five named legacy permissions as *the* boundary for this capability; inheriting a
second, unrelated requirement would make those permissions insufficient on their
own. A test asserts a view-only holder can open the index.

**Three independent gates** on every action: the route `permission:` middleware,
the controller's feature-flag check (a disabled capability **404s** — it reveals
nothing about itself), and the policy (which adds the per-row branch scope).

**Resolution is repository-first.** `{import}` is a plain integer resolved through
`LegacyRmeImportRepositoryInterface::findByIdInBranches()` with the caller's
server-resolved scope, and out-of-scope is a **404, not a 403**, so an operator
cannot probe which ids exist in a branch they cannot see. Page streaming looks the
page up *through* its import, so a page number can never reach another import's
output.

**File responses** carry `Cache-Control: private, no-store`,
`X-Content-Type-Options: nosniff`, an explicit `Content-Type`, and a generic
download name — the stored original filename and the storage path are never
echoed back.

---

## 9. UI

`resources/views/settings/rme/legacy-imports/{index,create,show}.blade.php`, built
on the UIX-1 design system (`x-ui.*` + semantic tokens, Blade + Tailwind + Alpine
only, no new dependency). The show page polls `status` while processing. KTP/NIK
is never rendered. There is no publish button.

---

## 10. Audit

Reuses the shared `sys_audit_logs` trail. New events: `LEGACY_RME_PDF_UPLOADED`,
`PROCESSING_QUEUED`, `PROCESSING_STARTED`, `PROCESSING_COMPLETED`,
`PROCESSING_FAILED`, `PROCESSING_RETRIED`, `IMPORT_CANCELLED`, `SOURCE_VIEWED`,
`PAGE_VIEWED`. New allow-listed metadata keys are structure only (`failure_code`,
`page_number`, `size_bytes`, `mime_type`, `dpi`, `malware_scanned`, duplicate row
ids, `variant`) — never a filename, path, command line, stack trace or clinical
content.

---

## 11. Configuration

`config/legacy_rme.php` gains a `processing` block. Env names keep the 1A
`LEGACY_RME_*` family rather than introducing a second `LEGACY_RME_PDF_*` family
for the same domain — one prefix, one place to look:

| Key | Default |
|---|---|
| `LEGACY_RME_DISK` | `legacy_rme_private` |
| `LEGACY_RME_MAX_BYTES` | 20 MiB (1A) |
| `LEGACY_RME_MAX_PAGES` | 200 (1A) |
| `LEGACY_RME_DPI` | 180 |
| `LEGACY_RME_THUMBNAIL_MAX_EDGE` | 320 |
| `LEGACY_RME_PROCESS_TIMEOUT` | 180 |
| `LEGACY_RME_MAX_PAGE_PIXELS` | 40,000,000 |
| `LEGACY_RME_MAX_PAGE_DIMENSION_PT` | 20,000 |
| `LEGACY_RME_MAX_RENDER_BYTES` | 200 MiB |
| `LEGACY_RME_QUEUE` | `legacy-rme-documents` |
| `LEGACY_RME_MALWARE_SCAN` | `false` |

`FEATURE_RME_LEGACY_PDF_ARCHIVE` stays **false**.

---

## 12. Runtime dependency

`poppler-utils` (`pdfinfo`, `pdftoppm`) must be present on any host that
processes documents. `LegacyRmePopplerIntegrationTest` **skips** (never silently
passes) when the binaries are absent; every other test drives the pipeline
through the fakes.

The dedicated `legacy-rme-documents` queue must be served by the managed worker
before the flag is enabled.

---

## 13. Tests

| Suite | Covers |
|---|---|
| `LegacyRmeImportAuthorizationTest` | permissions, roles, feature flag, branch scope, no publish route, no KTP, verb posture |
| `LegacyRmePdfValidationTest` | bytes-not-extension validation, size, magic, encryption, page/dimension limits, date rules, malware honesty, dispatch |
| `LegacyRmeExactDuplicateDetectionTest` | the full duplicate matrix incl. VOID and cross-patient correction paths |
| `ProcessLegacyRmePdfImportTest` | rendering, idempotency, retry, cancel, temp cleanup, payload shape, safe failure messages |
| `LegacyRmePrivateStorageTest` | private disk, opaque paths, streaming, nested page ownership, headers, orphan cleanup |
| `LegacyRmePopplerIntegrationTest` | the REAL Poppler adapters (skips without the binaries) |
| `LegacyRmeNonRegressionTest` | no visit/invoice/payment/consent/odontogram/lab/SATUSEHAT row, no visit status change |

The 1A assertion *"registers no HTTP route for the legacy RME archive"* was
replaced rather than left to pass by accident: its substring filters would not
have matched the new `legacy-imports` naming, so it would have kept "passing"
while the surface existed. It now asserts the exact staging route set **and** that
`publish`/`review`/`approve`/`void` still have no endpoint.

---

## 14. Deploy

**No migration, no seeder** — no schema and no permission change.

```bash
# on the VPS
bash scripts/deploy-vps-runner.sh start
command -v pdfinfo && command -v pdftoppm     # runtime dependency
```

`FEATURE_RME_LEGACY_PDF_ARCHIVE` stays `false`; the whole surface 404s until the
review/publish sprint (1C) makes the capability complete.

---

## 15. Next: LEGACY-RME-PDF-1C

Review approval, publish into `trx_rme_legacy_records`, patient-timeline
integration, the doctor viewer and legacy print.
