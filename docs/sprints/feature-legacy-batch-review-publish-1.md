# FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 — Batch Review & Batch Publish for Legacy RME / Odontogram

**Branch family.** `feature/legacy-batch-review-publish-1` (PR1), `feature/legacy-batch-publish-1` (PR2).
**Base.** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `76ccd334`. Do NOT target `main`.
**Phasing.** Owner-approved 2 PRs, each with its own tests, CI, VPS deploy and GO tag — the house pattern
(FIX-PRE-68-45 = 3 PRs, LAB-WORKFLOW-V2 = 5 PRs). PR1 does not touch the publish path at all.

---

## Owner decisions (recorded, not inferred)

### 1. Phasing — 2 PRs, split by risk, not by document type

PR1 ships batch **review** for BOTH document types and adds no batch publishing whatsoever. PR2 starts
only after PR1 is merged, deployed, production-verified and GO-tagged. Each PR carries its own tests,
security work, production deployment, production verification and GO tag. The two are not to be combined
merely to reduce delivery cycles: **review is non-final; publish is the dangerous, final half.**

PR1 scope: review workspace · efficient next/previous navigation · per-item Reviewed / Blocked /
Needs Attention · sticky triage annotations · audit · sidebar · authorization · security · tests ·
production deployment · production verification · its own GO tag.

### 2. "Block" is a sticky, non-destructive triage annotation

`Block` in batch review **MUST NOT** call canonical `cancel()`. Marking an item Blocked:

- leaves the canonical import lifecycle untouched, in its existing reviewable state;
- records a persistent review-triage decision with reviewer, timestamp, `reason_code` and a safe note;
- permanently excludes the item from batch publish selection **while the block remains**;
- may later be cleared or changed by an authorized reviewer;
- **never** releases the patient's single-active-document slot.

No new clinical lifecycle state is invented — the existing schema does not require one. The model is two
independent axes:

```
canonical import status  = READY_FOR_REVIEW   (untouched, canonical, clinical)
review_triage_status     = BLOCKED            (new, additive, operational)
```

and explicitly **not** `canonical import status = CANCELLED`. Cancellation stays a separate, explicit,
terminal action through the existing canonical single-item cancel flow with its own authorization and
audit. A reviewer who concludes a document must truly be abandoned invokes that flow deliberately.

**Clearing a block is itself a review-authority act.** It requires the review permission plus branch
scope, and it is routed through the same guard the canonical review path consults — so on RME, where
`SeparatePublisherGuard` bars an uploader from reviewing their own document, the uploader equally cannot
clear a block on it; on Odontogram, where no separation guard exists, an actor holding review permission
can. That is "reuse current policy truth" rather than inventing a second, divergent rule.

**The contract PR2 must honour.** A batch publish may select an item only when all three hold:

```
canonical status = REVIEWED
AND no active blocking triage annotation
AND every current publish-time guard passes
```

No `BLOCKED` and no `NEEDS_ATTENTION` item may be selected or published.

---

## The audit that shaped the design

Both lifecycles were read before any code was written. The findings below are the design constraints.

### The state machines are identical — but independently owned

`LegacyRmeImportStatus` and `LegacyOdontogramImportStatus` carry the same nine values
(`DRAFT`, `UPLOADED`, `QUEUED`, `PROCESSING`, `READY_FOR_REVIEW`, `REVIEWED`, `PUBLISHED`, `FAILED`,
`CANCELLED`), the same `TERMINAL`, the same `SLOT_OCCUPYING`, and an edge-for-edge identical
`TRANSITIONS` map. The record machines are likewise identical (`PUBLISHED => [VOID]`, `VOID => []`).

That agreement is **intentional but independent**. The odontogram `SLOT_OCCUPYING` docblock states it is
*"derived from THIS module's own transition map, not copied from the RME one"* and that a future
divergence in one must not silently change the other. There is no shared base class, trait or interface
between them, and this sprint does not introduce one.

`READY_FOR_REVIEW` is the queue state. `REVIEWED` is the human attestation and the only state from
which `PUBLISHED` is reachable.

### The enforcement layers differ, and that is the whole reason for an adapter

| | Legacy RME | Legacy Odontogram |
|---|---|---|
| Canonical write path | `LegacyRmeImportLifecycleService::perform()` — shared verbatim by HTTP and CLI | none; the controller calls `LegacyOdontogramPublishService` directly |
| Separation of duties | **ENFORCED** on review *and* publish (`SeparatePublisherGuard::GUARDED_ACTIONS = [REVIEW, PUBLISH]`) | **NONE** — grep over the module for `separation` returns zero hits; uploader may review own |
| Gate bundling | 6 numbered gates inside `perform()` | feature guard → branch-scoped resolve (404) → `authorize('review')` → publish service |
| Review-time refusal audit | **absent** (pre-existing gap) | `IMPORT_REVIEWED` only, no refusal event |
| Date rules | includes `PATIENT_HAS_NO_NATIVE_RME` (no native RME ⇒ refused); compares a date RANGE | native is OPTIONAL (`REVISION-LEGACY-ODONTOGRAM-NATIVE-OPTIONAL-1`); single date |

PR1 therefore ships **two adapter implementations, not one shared method with a type branch** — the same
reasoning the mass-upload adapter already documents: *"RME and Odontogram are genuinely asymmetric …
a shared method with `if ($type === ...)` branches would bury that asymmetry in the middle of the
orchestrator."*

### Review is already idempotent, and already locks

Both `review()` implementations take `lockForUpdate` on the import, then short-circuit when
`status === REVIEWED` (*"reviewing twice is a no-op, not an error"*). RME asserts SOD and the
source-patient binding **before** that shortcut, deliberately. Batch review inherits all of this for
free by calling the canonical method and must not re-express any of it.

### One import == one transaction, and it must stay that way

Nothing in either chain is written to tolerate N imports inside one outer transaction, and the
post-commit audit rows would lie if it were. Batch review loops the canonical per-import call and wraps
**nothing** in an outer transaction.

---

## PR1 scope — batch review workspace

### Rule 1. Human review per item is preserved. There is no "Review All".

The workspace speeds up *reaching* and *inspecting* each document. It never substitutes for inspection.
The operator marks each item individually; only marked items are submitted. There is no endpoint,
request field, service method or button that can mark an unvisited queue as reviewed. A submit carries
an explicit list of per-item decisions, and an item with no decision row is never touched.

Browser-open is **not** treated as proof of clinical review. The authoritative signal is the actor's
explicit per-item attestation. Inspection telemetry (first-viewed-at, page-count-viewed) is recorded as
**supporting evidence only** and is never a precondition the system can satisfy on the operator's behalf.

### Rule 2. Item-level truth, never one opaque batch flag

Every decision persists its own row carrying: session, import type, import id, patient id, source SHA-256,
decision, reason code, reason text, deciding actor and decision timestamp. There is no
`batch_reviewed = true` column anywhere. The canonical per-import `reviewed_by` / `reviewed_at` stamped by
the canonical service remains the clinical record of review.

### Rule 3. Batch submit is an aggregate over the canonical single-item path

`submit()` resolves each decision in turn and, for `REVIEWED` decisions only, calls:

- RME → `LegacyRmeImportLifecycleService::perform($actor, $id, REVIEW, [], CHANNEL_BATCH)`
- Odontogram → feature guard → scope resolve → policy → `LegacyOdontogramPublishService::review()`

A refusal from either is caught, classified, written onto that decision row as `REFUSED` with a stable
code, and the loop continues. 74 marked, 2 refused ⇒ 72 reviewed and 2 refused, never 0 reviewed.

### Rule 4. No permission widening

Reuses `review_legacy_rme_imports` and `review_legacy_odontogram_imports` verbatim. No
`mass_review_superuser`, no new ability, no policy relaxation. The batch surfaces are aggregate
orchestration over existing authorization: route `permission:` middleware, then the canonical gate chain
per item. Branch scope comes from the existing `LegacyRmeWorkspaceScope` /
`LegacyOdontogramWorkspaceScope` (which have deliberately separate membership) and never from the request.

### Rule 5. Blocked is sticky, non-destructive, clearable, and excluded from publish

Triage is a first-class additive record, `stg_legacy_review_triage`, keyed `UNIQUE(import_type, import_id)`.
It holds the **current** triage for an item — `BLOCKED` or `NEEDS_ATTENTION` — with its reason code, safe
note, deciding actor and timestamp. It is deliberately a separate table from the decision history: PR2
consults exactly one row to answer "is this item blocked?", and clearing is an explicit state change with
its own authorization rather than an inference over append-only history.

A `BLOCKED` or `NEEDS_ATTENTION` triage requires a reason code. Clearing or changing one is a
review-authority act, gated by the review permission, branch scope, and the same separation guard the
canonical review path applies for that document type. The canonical import is never modified by any of
this — not on set, not on change, not on clear.

### Rule 6. Resumability

A session is durable. A browser disconnect mid-submit leaves already-applied decisions `APPLIED` and
un-attempted ones `PENDING`; re-submitting re-attempts only what is not yet `APPLIED`. Submitting twice
cannot double-review, because the canonical review is itself idempotent.

### Rule 7. Audit carries counts and references, never clinical payloads

`BATCH_REVIEW_SESSION_OPENED`, `BATCH_REVIEW_ITEM_DECIDED`, `BATCH_REVIEW_SUBMITTED` record ids, counts
and stable reason codes. No raw notes, no scans, no page bytes, no KTP/NIK. The per-item canonical
`IMPORT_REVIEWED` event remains the authoritative clinical audit.

Review-time refusals are a **pre-existing audit gap** on the RME side. PR1 fills it for the batch path
only (on the decision row plus a batch-scoped event) and does not retrofit the single-item path.

---

## Deliberately NOT in PR1

- Any change to `publish()`, the publish FormRequests, the publish policies or the publish routes.
- Batch publish selection, revalidation or partial-success publish — all PR2.
- Any change to single-item review/publish pages, which remain useful and stay.
- Any new permission, any schema change to canonical `stg_*`/`trx_*` legacy tables, any migration that
  is not purely additive. Never `migrate:fresh`, never `db:wipe`.

---

## What PR1 ships

### New bounded context `App\Modules\LegacyImport\BatchReview`

Sibling to `MassUpload` inside the same module, reusing `LegacyImportType` and the
existing workspace scopes. Architecture follows the enterprise baseline:
Controller (thin) → FormRequest → Service → canonical module service.

| Layer | Files |
|---|---|
| Support | `LegacyBatchReviewDecision`, `LegacyReviewTriageStatus`, `LegacyBatchReviewSubmitStatus`, `LegacyBatchReviewSessionStatus`, `LegacyBatchReviewReason`, `LegacyBatchReviewItemSummary`, `LegacyBatchReviewApplyOutcome`, `LegacyBatchReviewRefusalClassifier` |
| Models | `LegacyBatchReviewSession`, `LegacyBatchReviewItemDecision`, `LegacyReviewTriage` |
| Adapters | `LegacyBatchReviewAdapter` (interface) + `LegacyRmeBatchReviewAdapter`, `LegacyOdontogramBatchReviewAdapter` |
| Services | `LegacyBatchReviewSessionService`, `LegacyReviewTriageService`, `LegacyBatchReviewWorkspaceService`, `LegacyBatchReviewAuditService` |
| HTTP | `LegacyBatchReviewController` (abstract) + two type-fixing subclasses, 3 FormRequests, 14 routes |
| Views | `settings/rme/legacy-batch-review/{index,show}.blade.php` |

### Migration (additive only)

`2026_10_04_100001_create_legacy_batch_review_tables` creates
`stg_legacy_batch_review_sessions`, `stg_legacy_batch_review_decisions` and
`stg_legacy_review_triage`. No column dropped, no existing table altered, no
backfill. `migrate` only — never `migrate:fresh` / `db:wipe`.

The stickiness invariant is a database constraint, not a convention: two unique
indexes over the nullable import foreign keys. Both PostgreSQL and SQLite treat
NULLs as DISTINCT, so the RME index ignores every odontogram row and vice versa
while still pinning at most one triage row per real import.

### Routes and sidebar

`settings.rme.legacy-review-imports.*` and
`settings.rme.legacy-review-odontograms.*` — names deliberately sharing no
prefix with either `legacy-imports.*` or `legacy-mass-imports.*`, so being on a
batch review page never lights up a sibling menu item. Sessions are addressed by
UUID, constrained to the uuid character class at the route level.

Two sidebar entries under the existing "Import Data Legacy" group, each gated by
the review permission plus that archive's migration guard. The group's own
`@canany` was extended with both review permissions — without that, a
review-only actor could not have seen the menu at all. Batch Publish entries
ship in PR2.

### One deliberate change outside the new module

`LegacyRmeAuditEvent` gains `CHANNEL_BATCH` in its channel allowlist. The
allowlist is enforced (`withChannel()` silently drops an unknown value), and the
file's own documentation says the channel exists so an auditor can tell *which
surface* asked. Recording a batch submit as `HTTP` would make 70 canonical
reviews indistinguishable from 70 individual browser actions — the first
question an audit of a batch migration asks. Verified that no test pins
`CHANNELS` to a fixed count.

---

## Verification

| Check | Result |
|---|---|
| `tests/Feature/LegacyBatchReview` (SQLite) | **39 passed**, 4 skipped (PostgreSQL-only) |
| Same suite on **real PostgreSQL 16.15** | **43 passed** — including the 4 database invariants |
| Migration on PostgreSQL 16 | applies cleanly |
| `tests/Feature/LegacyRme` + `LegacyOdontogram` + `LegacyMassUpload` + `LegacyImportHub` + `LegacyBatchReview` | see closure section |
| `SidebarPermissionVisibility\|RolePermissionHardening\|PilotRouteAuthorization\|RoleManagement\|AdminLabLabOnly` | **58 passed** |
| `sprint:manifest-check` | GO |
| `sprint:scope-audit --strict` | GO — 2 modules (LegacyImport, LegacyRme) |
| `architecture:ui-governance-check --strict` | GO |
| `foundation:security-compliance-check` | GO (9/9, incl. the Blade KTP/NIK view scan) |
| `foundation:ci-runtime-control-check --strict` | GO |
| CICD-CTRL-1 classifier on the real commit | `unknown_high_risk`, `run_critical_tests=true`, `run_full_suite=required` |
| `pint --dirty` | clean (7 files auto-fixed) |
| `git diff --check` | clean |

### Four defects found and fixed during implementation

Recorded because each is a trap the next sprint can repeat.

1. **An empty regex alternative matched everything.** The refusal-message
   scrubber's pattern ended `...|\$|#i`, whose trailing empty alternative matches
   any string — so *every* canonical Indonesian refusal message would have been
   replaced by the generic fallback, destroying the precise "a different
   reviewer must do this one" guidance. Now proven by a direct probe: operator
   prose survives verbatim while paths, SQL and PDO errors are scrubbed.

2. **A REVIEWED attestation cleared triage unconditionally.** That demanded
   triage authority merely to attest a document, so an actor barred by separation
   of duties was refused at *attestation* time instead of at submit — losing the
   refusal classification the batch report depends on. Worse, it let any reviewer
   clear a colleague's block just by marking the item reviewed. Now a REVIEWED
   decision releases only triage raised in that same session.

3. **Three component-contract bugs in the views.** `x-ui.card` has no `header`
   slot (it has `title`/`description`/`actions`) and `x-ui.table` has no `head`
   slot. Caught by compiling the templates rather than by reading them.

4. **Two N+1 queries in the queue listing.** `canReview()` / `canClearTriage()`
   were resolved per row — and `BranchService::rmeEnabledIds()` is not memoized,
   so a 25-row page issued roughly fifty extra branch queries for values the
   views never rendered. `summarize()` also counted pages per row when
   `page_count` is already a column. The queue is now flat at 10 queries
   regardless of page size, pinned by a constancy test.

### Two measurement traps hit while testing

Both are now documented in the mirror rule so the next sprint does not pay for
them again.

- **PostgreSQL aborts the whole transaction on any failed statement.** The
  intentional unique-violation assertion left the transaction in `SQLSTATE 25P02`,
  so the *next* assertion failed for an unrelated reason. Containing the
  violation in a `DB::transaction()` savepoint fixes it. SQLite hides this
  completely by tolerating the failed statement.
- **A cold-cache query count is not comparable to a warm one.** The first
  `queue()` call carries one extra `model_has_permissions` query while Spatie
  warms its permission cache. Measured, not assumed — the query shapes were
  dumped and compared. The constancy test now discards a warm-up sample.

---

## Status

PR1 implemented and tested. Security review, mutation testing, CI, deployment
and production verification pending. **No GO tag exists for either PR yet.**
