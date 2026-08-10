# ADR 0008 — Legacy RME PDF archive foundation

- **Status:** Accepted
- **Date:** 2026-08-06
- **Sprint:** `LEGACY-RME-PDF-1A`
- **Supersedes:** nothing
- **Related:** `docs/sprints/legacy-rme-pdf-1a-schema-permission-date-rules.md`,
  `.cursor/rules/90-legacy-rme-pdf-archive.mdc`

## Context

Clinics joining DaengtisiaMS carry years of paper medical records that have been
scanned into PDFs. Those documents are the patient's real medical history and
must become readable inside the system, but they are not encounters produced by
the live RME workflow: they have no visit, no billing, no consent and no lab or
SATUSEHAT consequence.

Two things had to be decided before any runtime could be written:

1. **Where does a legacy record live?** Reusing `trx_medical_records` is
   tempting, but a native medical record requires a `clinic_visit_id` that is
   both NOT NULL and UNIQUE, and it drives the visit → cashier workflow. Storing
   a legacy document there would force a fake visit into existence.
2. **What stops a legacy document from overlapping real data?** Without a bound,
   an operator could file a scanned page onto a date the system already has a
   real record for, silently producing two competing histories.

## Decision

### 1. A separate bounded context with its own tables

`App\Modules\LegacyRme` owns four additive tables — `stg_rme_legacy_imports`,
`stg_rme_legacy_import_pages`, `trx_rme_legacy_records`,
`trx_rme_legacy_record_pages`. A legacy record has a `patient_id`, an optional
`origin_branch_id` and its own `rme_date`; it has **no** `clinic_visit_id` and
never creates one.

### 2. The date is chosen manually, never derived

The operator reads the service date from the document and selects it. The system
never infers it from the upload time, `created_at`, the file date, PDF metadata
or OCR. It is stored as `selected_rme_date` (staging) / `rme_date` (published),
separately from `uploaded_at` and `published_at`.

### 3. The bound is the patient's earliest NATIVE RME date

A legacy date must be **strictly** earlier than the first medical record the
system itself produced for that patient. Equal is refused, because equal means
overlap. The canonical clinical date is `trx_clinic_visits.visit_date` reached
through the visit that owns the medical record — `trx_medical_records` has no
clinical date column of its own, and its `created_at` / `finalized_at` /
`canonical_visit_id` are all workflow artefacts rather than the encounter date.

The bound is computed in exactly one place,
`PatientEarliestNativeRmeDateResolver`, and it deliberately scans **all**
branches: a narrower scan could only move the bound later and admit an
overlapping document. Access control is a separate concern, handled by the
policies.

### 4. A patient with no native RME is refused

In regular mode there is no comparison point, so the import is refused with an
explicit message rather than silently allowed. A migration mode for such
patients is a separate, explicit decision.

### 5. Published legacy records are immutable

No in-place edit and no hard delete — not even a soft delete on the published
tables. The only correction is VOID with a reason plus a fresh import, and
`UNIQUE(source_import_id)` makes publishing idempotent.

### 6. Foundation first, runtime second

Sprint 1A ships schema, permissions, policies, repositories, the date-rule
domain, the audit foundation, configuration and tests. It exposes no route,
controller or view, and the capability sits behind the default-off feature flag
`rme.legacy_pdf_archive`.

## Consequences

**Positive**

- The live RME workflow is untouched: no fake visits, no billing/consent/lab/
  SATUSEHAT side effects, no KPI distortion. A non-regression test pins this.
- The overlap bound is a single, testable function rather than a rule repeated
  across a form request, a service and a view.
- The follow-up sprint can build upload, rendering, review and publish against a
  schema and an authorization model that already exist and are already tested.
- Nothing can be reached in production before the flag is deliberately turned on.

**Negative / accepted trade-offs**

- Legacy history lives outside `trx_medical_records`, so any surface that wants a
  unified patient history must read two sources. That is the price of not
  fabricating visits, and the follow-up sprint merges them at the presentation
  layer only.
- The cutoff scanning every branch means a legacy import can be blocked by an
  encounter in a branch the operator cannot see. This is intentional: a safety
  bound must be the strictest available, and the refusal message states the
  bound without exposing the other branch.
- "Today" is evaluated in the configured clinical timezone. If that setting is
  misaligned with the wall clock the clinic actually works in, the boundary can
  move by one calendar day. It is anchored to the same timezone the RME workflow
  uses when stamping `visit_date`, so both sides of the comparison shift
  together, and the setting is env-overridable without a code change.
- `source_pdf_sha256` is indexed but not globally unique, so the same PDF can be
  filed twice. This is required for cross-patient duplicate investigation and
  for VOID-then-reimport; duplicate handling is a service decision in the
  follow-up sprint.

## Alternatives considered

- **Store legacy records in `trx_medical_records` with a synthetic visit.**
  Rejected: it pollutes the visit workflow, the cashier queue and every visit
  KPI, and a synthetic visit is indistinguishable from a real one downstream.
- **Derive the date from OCR or PDF metadata.** Rejected: scanned paper metadata
  reflects the scan, not the encounter, and a wrong clinical date is worse than
  no automation.
- **Allow a legacy date equal to the earliest native date.** Rejected: equality
  is exactly the overlap case the bound exists to prevent.
- **Allow patients with no native RME through the regular path.** Rejected: it
  would create an unbounded import path by default. It stays an explicit,
  separate mode.

---

# Amendment — LEGACY-RME-PDF-1B (upload runtime, queue, page rendering)

**Status:** Accepted. Extends this ADR; no 1A decision is reversed.

## Additional decisions

- **A dedicated private disk, rooted outside the `local` disk root.** Laravel
  registers a `storage.{disk}` route for a local disk with `serve => true`, so
  everything under `storage/app/private` is in principle addressable by a signed
  framework URL. Legacy pages are clinical evidence that must only ever be
  reachable through the policy-gated streaming controller, so `legacy_rme_private`
  gets its own root and `serve => false`. Reusing `local` was rejected: the
  signature requirement is a control we would be *relying* on rather than a
  boundary we own.
- **Rendering is queue-only.** No HTTP request executes Poppler. The job carries
  only the import id, so a queued payload can never hold clinical bytes.
- **Poppler via argument arrays, never a shell string.** Symfony `Process`
  `execve`s the binary directly, so there is no shell to inject into. Binary
  names come from config; every path is server-constructed and absolute.
- **Thumbnails come from Poppler `-scale-to`, not a PHP image extension.**
  Rejected alternative: GD/Imagick. The local dev machine has no GD, and adding
  an image dependency for a downscale `pdftoppm` already performs would make
  rendering environment-dependent. Consequence: thumbnails are PNG rather than
  WebP — a deliberate portability trade.
- **Queue retries do not drive domain failure.** A failure lands the import in
  `FAILED` with a stable code and stops; the operator's explicit Retry
  (`FAILED → QUEUED`, already legal in the 1A map) is the recovery path, keeping
  a human in the loop. Queue-level retries still cover a worker that dies before
  recording anything. Rejected alternative: auto-retrying domain failures, which
  burns attempts on deterministic errors and hides them from the operator.
- **No `READY_FOR_REVIEW → QUEUED` transition was invented.** The 1A map has no
  such edge, and abusing `FAILED` to fake one would make a good render look like
  a failure in the audit trail. A rendered document is corrected by cancelling
  and re-uploading.
- **A VOID record does not block a duplicate import.** 1A defines correction as
  "VOID plus a fresh import"; blocking on VOID would make that impossible. The
  collision is audited instead.
- **`origin_branch_id` is validated against the uploader's own scope.** 1A
  described this column as "provenance, never authorization". That description
  was incomplete: repository listings filter on it and the policy scopes on it,
  so it decides which branch owns the row. Left unchecked, a scoped operator
  could file a document into a branch they have no authority over and then 404
  on their own row. The rule mirror (rule 14) has been corrected accordingly.

## Consequences

- `poppler-utils` becomes a runtime dependency of any host that processes
  documents. The one real-Poppler test suite skips (never silently passes) when
  the binaries are absent; every other test uses deterministic fakes.
- The `legacy-rme-documents` queue must be served by the managed worker before
  the feature flag is enabled.
- A staged document stops at `READY_FOR_REVIEW`. It is not yet part of the
  patient's archive, and no publish endpoint exists.
