# REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1

Branch `feature/legacy-single-active-document-per-patient-1`, cut from
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
@ `2a9ad1b5829acf8546705c5a81a7cece20cb5bab`. Do not target `main`.

**Status at the time of writing: implemented and tested locally.** Everything
below is a statement about the code in this branch, not a plan.

---

## 1. What this revision establishes

Per patient, per legacy document type, **at most one active / non-VOID logical
lifecycle**:

* `LEGACY_RME` — at most one;
* `LEGACY_ODONTOGRAM` — at most one;
* **independent** — neither slot ever blocks the other.

A patient who already has a published legacy RME may still receive their first
legacy odontogram, and the reverse. There is no patient-wide "has any legacy
document" predicate anywhere in the implementation.

---

## 2. Phase 0 — what was measured before anything was written

### The two subsystems both exist and are substantial

Legacy Odontogram was verified in the current tree rather than inferred from
`CLAUDE.md`: `app/Modules/LegacyOdontogram/` is a full module (import + record
controllers, 4 repositories, publish/void/processing services, its own status
vocabularies) with its own four tables and its own `rme.legacy-odontograms.*`
routes including `retry`, `cancel`, `review`, `publish` and record `void`.

### Tables

| Type | Staging | Archive |
|---|---|---|
| RME | `stg_rme_legacy_imports` | `trx_rme_legacy_records` |
| Odontogram | `stg_odontogram_legacy_imports` | `trx_odontogram_legacy_records` |

Staging tables soft-delete. Archive tables do not — immutability is the contract.
Each archive has `UNIQUE(source_import_id)`, so one staging batch yields at most
one record.

### State machines (identical in shape, defined independently)

Import: `DRAFT → UPLOADED → QUEUED → PROCESSING → READY_FOR_REVIEW → REVIEWED →
PUBLISHED`, with `FAILED` and `CANCELLED`.
`TERMINAL = [PUBLISHED, CANCELLED]`. Critically **`FAILED → QUEUED` is legal**,
so `FAILED` is retryable and not terminal.

Record: `PUBLISHED → VOID`, and nothing else.

### New-lifecycle entry points — exactly two

`LegacyRmeImportService::createFromUpload()` and
`LegacyOdontogramImportService::createFromUpload()`. Each has exactly two HTTP
callers — the backlog import controller and `VisitBoundLegacyIngestionController`.
Retry goes through the **different** method `queue($import, …, isRetry: true)`.
The ops CLI (`legacy-rme:import-admin`) drives
`LegacyRmeImportLifecycleService` (`resolveForActor`/`preview`/`perform`), which
acts on existing imports and creates nothing.

### Mass upload does not exist

Verified by search across `app/`, `routes/`, `resources/views/` and
`app/Console/Commands`. Per the sprint's own instruction it was **not built**.

### No advisory-lock precedent

`pg_advisory*` appears nowhere in `app/`, `database/` or `tests/`. This revision
introduces the first use, which is why the key derivation is documented
explicitly rather than left implicit.

### Production data gate — PASS

Read-only against `asia_dental_lab_pilot` (PostgreSQL 16.15):

| Metric | Value |
|---|---|
| Patients | 1519 |
| Legacy RME imports | 1 (`PUBLISHED`) |
| Legacy RME records | 1 (`PUBLISHED`) |
| Legacy odontogram imports | 1 (`PUBLISHED`) |
| Legacy odontogram records | 1 (`PUBLISHED`) |
| Soft-deleted imports | 0 |
| Orphan records (record whose import is not PUBLISHED) | 0 |
| **Patients with >1 occupied RME slot** | **0** |
| **Patients with >1 occupied odontogram slot** | **0** |

No existing violation, so the invariant could be introduced without touching a
single clinical row. Nothing was auto-voided, auto-deleted, merged or rewritten,
and no remediation was required.

---

## 3. The slot algorithm

```
SLOT_OCCUPIED(patient, type):
  1. archive record PUBLISHED?        -> OCCUPIED   (reason ALREADY_PUBLISHED)
     archive record VOID?             -> not an occupant
  2. staging row in SLOT_OCCUPYING?   -> OCCUPIED   (reason ACTIVE_IMPORT_EXISTS)
  3. otherwise                        -> AVAILABLE
```

`SLOT_OCCUPYING` = `ALL` minus `TERMINAL` = `DRAFT, UPLOADED, QUEUED,
PROCESSING, READY_FOR_REVIEW, REVIEWED, FAILED`. Declared on each module's own
status class and **pinned by a test** to that derivation, so a future added state
cannot silently stop occupying the slot.

The archive is consulted **first**. That single ordering decision is what makes
case 5 work: a staging row keeps its historical `PUBLISHED` status forever, so if
the staging table were consulted first a voided archive could never be replaced
and void-then-reimport would be permanently dead.

---

## 4. Architecture

```
createFromUpload()                       (the only new-lifecycle entry point)
  ├── … existing refusals, precedence unchanged …
  ├── exact-PDF duplicate detection       (keeps its precedence — see §6)
  ├── slots->previewForNewLifecycle()     ADVISORY, before bytes are stored
  ├── storage->putFile()
  └── DB::transaction:
        ├── slots->assertAvailableForNewLifecycle()   AUTHORITATIVE, lock #1
        ├── hubQuota->reserve()                       lock #2 (branch-keyed)
        ├── quota->reserve()                          lock #3 (wave-keyed)
        └── imports->create()
```

New in `App\Modules\LegacyImport`:

* `Services\LegacySingleActiveDocumentService` — the single eligibility truth,
  composing the four repositories.
* `Support\LegacyDocumentSlotOccupancy` — immutable, PII-free result carrying a
  reason code, the blocking row ids and the operator-facing Indonesian message.
* `Support\LegacyDocumentSlotLock` — the advisory-lock key registry.
* `Exceptions\LegacyDocumentSlotOccupied` — the race loser, converted to a
  `ValidationException` only after the transaction has rolled back and the stored
  bytes have been compensated (an audit row written inside the failing
  transaction would have rolled back with it).
* `Exceptions\LegacyDocumentSlotLockUnavailable` — programming/environment fault,
  deliberately not a `ValidationException`.

Four repository methods added behind their existing interfaces
(`firstSlotOccupyingForPatient`, `firstPublishedForPatientUnscoped`).

### No migration

The queries are served by indexes that **already exist** on all four tables:
`(patient_id, status)`. Per the owner's decision the invariant is not expressed
as a partial UNIQUE index — it cannot be, because the predicate reads two
tables — so no schema change was needed or made.

---

## 5. Race safety

`pg_advisory_xact_lock(classid, objid)`:

* `classid` — a fixed documented namespace per document type
  (`NAMESPACE_BASE + 1` for RME, `+ 2` for odontogram);
* `objid` — `patient_id`, identity-mapped. Not hashed: a hash collision would
  silently serialize two unrelated patients, a liveness bug only visible under
  load. An out-of-range id is refused rather than wrapped.

The two-argument form gives a genuine namespace split, so the slot independence
is enforced **at the lock level** and not only in the query.

Transaction-scoped, never session-scoped. `assertAvailableForNewLifecycle()`
refuses when `DB::transactionLevel() < 1`, because a transaction-scoped lock
taken outside a transaction is released immediately and would guard nothing.

Laravel returns the write PDO from `getReadPdo()` whenever a transaction is open,
so the occupancy re-read cannot be answered by a lagging read replica.

On non-PostgreSQL drivers the lock step is a documented no-op. The concurrency
property is proven against a real PostgreSQL 16 server and the suite **SKIPS**
elsewhere rather than passing silently.

---

## 6. Two things the tests caught

Both were real, and both are recorded because the reasoning that produced them
was wrong the first time.

**The slot pre-check shadowed duplicate detection.** Placed before the checksum
comparison, it meant a same-patient re-upload of the *same scanned document* was
reported as "slot occupied" instead of "duplicate", and `DUPLICATE_DETECTED` was
never written. `LegacyRmeExactDuplicateDetectionTest` failed on exactly that. The
check moved to **after** duplicate detection (still before any byte is stored),
which is what the accompanying comment had claimed all along. Cardinality
supplements the checksum guard; it never replaces it.

**One existing test asserted the opposite of the new invariant.**
`it('allows the same filename when the bytes differ')` staged **two** imports for
**one** patient to prove the guard keys on bytes rather than filename. That is now
impossible. The property is still worth proving, so it is proven across two
patients; the same-patient form moved to the slot suite. This is a deliberate,
owner-authorized behaviour change, not a test weakened to fit.

---

## 7. Behaviour

### Legacy RME

| Situation | New upload |
|---|---|
| no legacy RME | allowed |
| staging row `DRAFT`/`UPLOADED`/`QUEUED`/`PROCESSING`/`READY_FOR_REVIEW`/`REVIEWED` | **denied** |
| staging row `FAILED` (retryable) | **denied** — retry or cancel it |
| staging row `CANCELLED` | allowed |
| archive `PUBLISHED` | **denied** — VOID first |
| archive `VOID` | allowed |
| retry of the same import | allowed, always |
| visit-bound preverified path | **denied** on the same terms |
| live import soft-deleted | **denied** (fail closed) |

### Legacy Odontogram

Identical, against its own tables and its own status vocabulary.

### Independence

| | RME blocks RME | Odo blocks Odo | RME blocks Odo | Odo blocks RME |
|---|---|---|---|---|
| | YES | YES | **NO** | **NO** |

---

## 8. Operator messages

Indonesian, reason-specific, and never advising an impossible action.

* Published: *"Legacy RME sudah tersedia. … gunakan prosedur VOID oleh petugas
  yang berwenang lalu lakukan upload ulang."*
* In flight: *"Upload Legacy RME sedang diproses. … Selesaikan atau batalkan
  proses tersebut sebelum membuat upload baru."* — **never** mentions VOID, which
  is not a legal transition from any staging state.

Callers branch on `REASON_ALREADY_PUBLISHED` / `REASON_ACTIVE_IMPORT_EXISTS`,
never on the text.

---

## 9. Audit

Four new events, two per module:
`…_NEW_UPLOAD_BLOCKED_ALREADY_PUBLISHED`, `…_NEW_UPLOAD_BLOCKED_ACTIVE_IMPORT`.
Separate events rather than one with a discriminator, because the operator's next
action differs and the trail should be greppable by it.

Payload is structure-only and added to both `ALLOWED_METADATA_KEYS` allow-lists:
`slot_reason`, `blocking_import_id`, `blocking_record_id`, `blocking_status`.
No patient name, no Nomor RM, no KTP/NIK, no clinical content — asserted by test.

Denials are audited **outside** the intake transaction, so the trail survives the
rollback that the refusal causes.

---

## 10. UI

Both create screens show the slot state and withhold the upload form when the
slot is held, following the existing `admissionDecision` precedent
("store() re-decides server-side, and that call is the boundary"). Each panel
says explicitly that the *other* document type is unaffected.

The UI is a courtesy, not a boundary: `store()` re-decides under the lock and
answers a hand-crafted POST identically.

---

## 11. What was explicitly preserved

Exact-PDF duplicate detection · date rules and their precedence · branch
derivation from the patient's Nomor RM · wave admission · ingestion capacity ·
the operations gate · the hub daily quota · maker-checker / separate-publisher
SOD · publish and VOID authority (unchanged, not widened) · private storage ·
queue retry and worker recovery · unlimited business volume (no daily quota
reintroduced) · all history (nothing deleted, nothing auto-voided).

---

## 12. Tests

* `tests/Feature/LegacyImportHub/LegacySingleActiveDocumentTest.php` — 36 tests
  covering the vocabulary derivation, the lock key, the transaction guard, both
  slots across every occupying state, cancel/void release, the
  published-staging-row-plus-voided-record case, retry, soft-delete fail-closed,
  the visit-bound path, the PII-free audit trail, and all four independence
  directions.
* `tests/Feature/LegacyImportHub/LegacySingleActiveDocumentConcurrencyTest.php` —
  the advisory-lock proof, against real PostgreSQL 16, skipping elsewhere.
* `tests/Feature/LegacyRme/LegacyRmeExactDuplicateDetectionTest.php` — one test
  updated to the new contract (§6).

---

## 13. Out of scope

* Mass/bulk legacy upload — does not exist in this codebase and was not built.
* Any change to publish or VOID authority.
* Any remediation of production data — none was needed.
