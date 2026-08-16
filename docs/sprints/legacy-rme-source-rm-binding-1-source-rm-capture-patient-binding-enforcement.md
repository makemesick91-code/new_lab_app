# LEGACY-RME-SOURCE-RM-BINDING-1 — Source RM Capture, Patient-Binding Enforcement & Wrong-Patient Prevention

**Type:** MODULE_SPRINT · **Module:** LegacyRme
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Predecessors:** LEGACY-RME-PDF-ROLL-4-WAVE-2 (GO) · ROLL-4-WAVE-3 (SKIPPED / NOT REQUIRED) ·
LEGACY-RME-OPS-CLI-1 (GO) · LEGACY-RME-SOD-1 (GO) · LEGACY-RME-MASTERDATA-1 (GO)

---

## 1. Why this workstream exists

Wave-2 produced a **real wrong-patient binding in production**.

An operator selected one patient and uploaded a document belonging to another.
Nothing on the write path could tell, for one concrete reason: **the Nomor RM
printed on the paper was never captured**. The staging row recorded the selected
patient, the source PDF, the chosen dates and the branch — but no independent
assertion about who the document was *about*. With nothing to compare against,
the server had no way to disagree with the operator. The error surfaced later,
from a frozen source hash and manual evidence.

LEGACY-RME-MASTERDATA-1 recorded the gap in writing rather than papering over it
(rule 63 of the archive rules, and the `archiveBindings()` comment in
`LegacyRmePatientResolutionAuditService`: *"the Nomor RM printed on a document is
never captured"*). This sprint closes it.

## 2. The invariant

For every **new** legacy RME import:

```
SOURCE PDF
   ↓ human confirms what is printed on it
SOURCE_RM_RAW                    (verbatim — the evidence)
   ↓ canonical normalization      (transcription repair, never a digit)
SOURCE_RM_NORMALIZED
   ↓ EXACT patient resolution     (exact, or whole manual segment — nothing else)
exactly one patient
   ↓ compare
SELECTED PATIENT
```

Anything else — missing, malformed, not found, ambiguous, a different patient, a
contradicting branch — **fails closed**, before a staging row, a stored byte, a
queued job or a consumed quota slot exists.

## 3. What shipped

### Schema (additive only)

`2026_08_17_100001_add_source_rm_to_legacy_rme_tables.php`

| Table | Columns |
|---|---|
| `stg_rme_legacy_imports` | `source_rm_raw`, `source_rm_normalized`, `source_rm_resolution` (+ index) |
| `trx_rme_legacy_records` | `source_rm_raw`, `source_rm_normalized` (+ index) |

All nullable at the database level, **for history only**. No backfill. See §5.

### The canonical guard

| Component | Responsibility |
|---|---|
| `LegacyRmeSourceRmNormalizer` | Text → canonical text. Repairs transcription; never moves a digit. |
| `LegacyRmePatientResolutionAuditService::resolveIdentity()` | Exact identity, no near-miss scan. Shares `identify()` with the MASTERDATA-1 diagnostic, so the two can never disagree. |
| `LegacyRmeSourcePatientBindingService` | **THE** rule. Normalize → resolve → compare with the selected patient → assert the asserted branch segment. Writes nothing. |
| `LegacyRmeSourceRmBinding` / `LegacyRmeSourceRmFailure` | Immutable result + stable code vocabulary. |

### Where it is enforced

| Path | Placement |
|---|---|
| Upload | **First** in `LegacyRmeImportService::createFromUpload()` — before dates, branch, admission, capacity, operations, hashing, storage and quota. |
| Retry | `assertRetryAdmitted()` — a retry restarts render work on a document. |
| Review | `LegacyRmePublishService::review()`, under the row lock, **before** the re-review no-op shortcut. |
| Publish | `LegacyRmePublishService::publish()`, under the row lock, beside the existing date and branch revalidation. |
| Cancel | **Never gated.** See §6. |

The gate lives in the service, so HTTP, a direct service call and
`legacy-rme:import-admin` are the same door — asserted by a test that calls the
service directly with a mismatched patient, and by a CLI publish test.

### Surfaces

- Form: required **“Nomor RM pada Dokumen”** + its own attestation
  (*“Saya memastikan nomor RM yang dimasukkan sesuai dengan nomor RM yang
  tercetak pada dokumen RME Legacy.”*). **Not prefilled** from the selected
  patient — see §4.
- Review screen: the asserted RM shown beside the patient's own, so a reviewer
  can see they agree rather than assume it.
- `php artisan legacy-rme:source-rm-binding-check` — read-only, creates nothing,
  exits 1 on refusal. This is how the gate is proven in production (§7).

## 4. Two decisions worth stating plainly

**The field is never prefilled from the selected patient.** A value that agrees
with the selection by construction cannot catch a wrong selection. Prefilling it
would make the operator confirm the system's guess instead of reading the paper,
and would restore the exact failure this sprint removes. There is no
“copy from patient” helper, in the UI or anywhere else.

**A refusal never says whose Nomor RM it is.** The upload form is reachable by
any `create_legacy_rme_imports` holder. A message that answered *“that RM belongs
to someone else — here is who”* would turn a safety gate into a
patient-enumeration oracle. The operator is told to re-read the document, which
is the action they actually need. The audit payload carries
`source_rm_normalized`, `source_rm_resolution` and the reason code — never the id
of the patient the number really named.

### Residual, reviewed and accepted: `NOT_FOUND` vs `PATIENT_MISMATCH`

The two codes are distinguishable, so an operator can in principle learn that a
Nomor RM *exists* without learning whose it is.

This was reviewed and kept, for two reasons. First, it grants no new capability:
the same actor already has the cross-branch Nomor RM search on the import screen
(`LegacyRmePatientLookupService::search()`, gated by the same
`create_legacy_rme_imports`), so RM existence is already directly queryable
through a supported feature. Second, collapsing the codes would make the refusal
unactionable — the operator could no longer tell whether to re-read the document
or re-pick the patient, which are opposite corrections.

What is *not* leaked is the part that matters: never the id, name or Nomor RM of
the patient the number actually belongs to.

## 5. Historical rows are never given a manufactured source RM

Backfilling `source_rm_raw` from the selected patient's own Nomor RM was
considered and **rejected**. It would convert a derived assumption into false
source evidence — claiming an independent human confirmation that never happened,
for documents nobody re-read.

So pre-enforcement rows keep NULL, and:

- **Terminal rows** (PUBLISHED / CANCELLED / VOID) are untouched. Wave-2R history
  renders exactly as before.
- **Non-terminal rows** are refused at review/publish/retry with
  `SOURCE_RM_CAPTURE_MISSING` and a message telling the operator to cancel and
  re-import. Production's resting state has none (§7), so this is a safety net,
  not a migration task.

## 6. Cancel is the safety valve

Every “cancel and re-import” instruction in §5 is a lie if cancel is blocked by
the same refusal that produced it. Cancel is therefore deliberately the one
lifecycle action this rule never gates — pinned by a test that cancels both a
stale-binding row and a pre-enforcement row.

## 7. Production posture

The gate needs no wave, no admitted branch and no enabled capability to be
*verifiable*: `legacy-rme:source-rm-binding-check` answers the binding question
read-only. Production therefore stays at rest —
`CAPABILITY=OFF`, `ADMISSION=EMPTY`, `ACTIVE_WAVE=NONE` — and no migration wave is
opened to obtain smoke evidence. `LIVE_MUTATION=NOT_EXERCISED`, by design.

**ROLL-4-WAVE-3 remains `SKIPPED / NOT REQUIRED`.** This is an engineering safety
workstream, not a migration wave.

## 8. RM 27541

Unchanged: `DOCUMENT_NOT_ELIGIBLE`, `NOT_ELIGIBLE` for future migration
(MASTERDATA-1, owner determination). It is used here **only** as a negative
regression: `27541` binds to nobody — not to the one-digit-away `22541` holder,
not to any other patient, in bare or canonical form.

## 9. Tests

`tests/Feature/LegacyRme/LegacyRmeSourceRmBindingTest.php` — 41 tests:
normalization (digit-preservation incl. the 27541 pair), the binding matrix
(bind / mismatch / not-found / ambiguous / malformed / branch), zero-footprint on
refusal, HTTP + direct-service + CLI parity, audit shape and non-disclosure,
review/publish/retry revalidation, pre-enforcement refusal, cancel availability,
bounded query count, and the archive boundary (no visit / MR / invoice / payment
/ lab / SATUSEHAT state).

## 10. Compatibility notes for future work

- `LegacyRmeImportService::createFromUpload()` gained a **required** `$sourceRm`
  parameter in position 3. Required and early on purpose: adding a mandatory
  identity assertion should break every caller loudly rather than default
  silently.
- `LegacyRmeImportFactory` stamps a coherent binding by default;
  `->withoutSourceRm()` builds a genuine pre-enforcement row and `->sourceRm()`
  builds a deliberately wrong one.
- The binding guard takes **no actor**. Whether a document is about a patient
  cannot depend on who is asking; authorization keeps its own owners.
