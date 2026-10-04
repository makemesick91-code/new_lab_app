# FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 — PR2 Batch Publish (RME + Odontogram)

**Branch.** `feature/legacy-batch-review-publish-1-pr2`
**Base.** the deployed PR1 GO commit `01b28aeb`. Do NOT target `main`.
**Predecessor.** PR1 — `feature-legacy-batch-review-publish-1-pr1-go` @ `01b28aeb`, merged,
deployed and production-verified. PR2 consumes PR1's shipped selection contract and rebuilds
none of it.
**Worktree.** Built in an isolated git worktree at `/home/fikri/Projects/legacy-batch-publish-pr2`
(vendor and node_modules COPIED, never symlinked — a symlinked vendor makes `baseDir` run the
other checkout).

---

## The contract PR2 consumes

A document may be selected and published only when **all three** hold:

```
canonical status = REVIEWED
AND no active blocking triage annotation   (PR1's stg_legacy_review_triage)
AND every current publish-time guard passes
```

No `BLOCKED` and no `NEEDS_ATTENTION` document is selectable or publishable, and **nothing in
this feature clears a triage annotation.** Clearing stays a review-authority act in PR1's flow,
behind its own authorization.

---

## What PR2 ships

New bounded context `App\Modules\LegacyImport\BatchPublish` (sibling to `BatchReview`), reusing
`LegacyImportType`, both canonical workspace scopes, PR1's `LegacyReviewTriageService` and PR1's
PII-safe `LegacyBatchReviewItemSummary`.

| Layer | Files |
|---|---|
| Support | `LegacyBatchPublishReason` (the §11 code set), `LegacyBatchPublishItemStatus`, `LegacyBatchPublishRunStatus`, `LegacyBatchPublishEligibility`, `LegacyBatchPublishOutcome`, `LegacyBatchPublishRefusalClassifier` |
| Models | `LegacyBatchPublishRun`, `LegacyBatchPublishItem` |
| Adapters | `LegacyBatchPublishAdapter` (interface) + RME and Odontogram implementations |
| Services | `LegacyBatchPublishRunService`, `LegacyBatchPublishWorkspaceService`, `LegacyBatchPublishAuditService` |
| HTTP | abstract controller + 2 type-fixing subclasses, 2 FormRequests, 12 routes |
| Views | `settings/rme/legacy-batch-publish/{index,show,_counters}.blade.php` |

### Migration (additive only)

`2026_10_04_200001` creates `stg_legacy_batch_publish_runs` and
`stg_legacy_batch_publish_items`. No column dropped, no existing table altered, no backfill.
`migrate` only — never `migrate:fresh` / `db:wipe`.

**Orchestration only (§10).** Nothing in these tables is clinical. Clinical truth stays on the
canonical import's status and on the `trx_*_legacy_records` row with its
`UNIQUE(source_import_id)`. Dropping both tables would lose the operator's report and
**un-publish nothing**.

`published_record_id` is a plain indexed pointer, deliberately **not** unique: two runs may
legitimately *observe* the same already-published record (the second returning `created: false`),
and a second unique constraint would make that collide on bookkeeping instead of reporting
`ALREADY_PUBLISHED` cleanly.

---

## Rule 1. No outer transaction. One document, one transaction. (§5)

The canonical publish opens its own transaction, takes its own row lock, re-validates every
clinical rule, writes the archive record and its pages, and writes its audit row **after**
commit. Wrapping N of those in one outer transaction would do three unacceptable things:

- roll back publications that had already succeeded, which §4 forbids outright;
- make the post-commit audit rows describe archive records a rollback destroyed;
- hold one transaction and its locks open across hundreds of documents, which §20 forbids.

So a pass is a **loop** over the canonical per-document call. 100 selected, 97 eligible, 3 stale
⇒ 97 published and 3 refused with explicit reasons. Never 0, and never 3 silently skipped.

## Rule 2. Re-validate immediately before each publish (§6)

`publish()` re-resolves the document through the canonical scope, then re-evaluates it, for
**every** item at publish time — never trusting the selection. Order matters: triage is checked
first, because §15 makes it absolute and a withheld document must not reach the clinical checks.

The archive-specific re-check (`revalidate()`) **reuses the canonical services** rather than
reimplementing them — the same date-rule service, the same branch resolver, the same
source-binding service. It exists for two reasons only: §12's honest "eligible now" count, and
recovering the **precise** refusal code a canonical `ValidationException` cannot carry (notably
native-boundary versus any other date failure).

It is **advisory**. The canonical publish re-runs all of it under its own row lock, and an item
the pre-flight calls eligible can still be refused there.

## Rule 3. Exactly-once comes from the canonical layer, not from this orchestrator (§8)

Stated plainly because §8 demands honest attribution:

- `UNIQUE(source_import_id)` on the archive records table — a database constraint, so two
  simultaneous transactions cannot both insert.
- `lockForUpdate` on the import inside the canonical publish transaction.
- An explicit `findBySourceImportId()` early-return handing back the existing record with
  `created: false`.

The run's own row lock serializes **only the status transition** and is released on commit.
`PUBLISHING → PUBLISHING` is permitted so a resumed pass can re-enter, which makes the run status
structurally incapable of serializing two passes. A test asserts that attribution directly rather
than letting a comment claim it.

## Rule 4. Authorship is arbitrated, so the report cannot overstate (§4, §13)

The canonical layer guarantees one **record**. It does not stop two attempt rows in different
runs both believing they created it — two interleaved passes can each observe "no record yet"
before either transaction commits. So at most one attempt row across **all** runs may hold
`created_record` for a given import. A loser is still `PUBLISHED` (the document IS filed) but
records `ALREADY_PUBLISHED`, which is true from its point of view.

A run of 100 that finds 3 already filed therefore cannot read as 103.

## Rule 5. No permission widening (§13)

Reuses `publish_legacy_rme_imports` and `publish_legacy_odontogram_imports` verbatim. No
batch-publish super-permission. Branch scope comes from the canonical workspace scopes and never
from the request; out-of-scope resolves to **absence**, never 403.

Stated honestly: the publish permission is *itself* a member of each scope's
`GOVERNANCE_PERMISSIONS`, so any actor who can reach these routes already spans every
RME-enabled branch. That makes "out-of-scope resolves to absence" true but near-vacuous on this
surface — and it is **identical** to the canonical single-item publish page, which carries the
same permission and the same scope. PR2 widens nothing.

## Rule 6. Two adapters, never a type branch (§7)

| | Legacy RME | Legacy Odontogram |
|---|---|---|
| Canonical write path | `LegacyRmeImportLifecycleService::perform(..., PUBLISH, ...)`, shared with the ops CLI | controller sequence → `LegacyOdontogramPublishService::publish()` |
| Separation of duties at publish | **ENFORCED** (gate 5, and re-asserted under the row lock before the idempotency shortcut) | **NONE** anywhere in the module |
| Source-patient binding re-check | yes | no — pre-existing canonical behaviour, identical on the single-item page |

§7 forbids making odontogram either weaker **or silently stricter** than its single-item publish
workflow. Both directions are pinned by tests and by mutation.

## Rule 7. Bounded execution, no new queue (§20)

- selection: `MAX_SELECTION = 100`, matched to `MAX_PER_PAGE` because that is all a page can
  render — a larger value is unreachable from the UI and only widens what a crafted request costs.
- cumulative: `MAX_OUTSTANDING = 500` bounds a run's queue across many requests.
- publish pass: `MAX_PUBLISH_PASS = 50`, clamped in both the FormRequest and the service.
- page size: `MAX_PER_PAGE = 100`, clamped.

The per-item write stays in its **own** short single-row transaction rather than being batched
into one. Batching would trade many brief transactions for one long-held one — the opposite of
what §20 asks for. Bounding the work is the right lever.

**No queue was introduced.** Everything runs synchronously inside a bounded request.

---

## Verification

| Check | Result |
|---|---|
| `tests/Feature/LegacyBatchPublish` (SQLite) | **54 passed**, 8 skipped (PostgreSQL-only) |
| PR2 + PR1 on **real PostgreSQL 16.15** | see closure section |
| §8 concurrency matrix on PG16 | **8 passed** — A, B (both directions), C, D, E, plus the attribution test |
| **Mutation** | **15 of 16 killed**; the single survivor is a *demonstrated* equivalent mutant |
| **Security review** | no CRITICAL, no HIGH, no MEDIUM; 11/16 classes structurally absent; 5 LOW fixed |
| `pint --dirty`, `git diff --check` | clean |

### Four defects found while building

1. **A date type error.** The model casts the clinical date to `Illuminate\Support\Carbon`, but
   the canonical date-rule service accepts only `CarbonImmutable|string|null`. Passing the cast
   value straight through was a `TypeError` on 13 tests. Both adapters now pass
   `?->toDateString()`, exactly as the canonical publish service does.
2. **A Blade compile error `view:cache` did not catch.** Escaped quotes in an Alpine selector
   (`[data-selectable=\'1\']`) compiled to invalid PHP and 500'd at render, while `view:cache`
   reported success. Caching views is not the same as rendering them.
3. **A foreign key collided with §4's "report every refusal".** An id resolving to no in-scope
   document has no clinical row to attach an attempt to, and the item table's foreign keys
   correctly refuse one. Rather than nulling the FK to make bookkeeping possible, those are
   counted and surfaced in the operator's selection summary. Referential integrity over a row
   pointing at nothing — a deliberate, documented deviation from a literal reading of §4.
4. **A fragile single-active assertion.** Pinning one exception type made the test a statement
   about guard *ordering* rather than about the one-archive-per-patient rule. It now asserts the
   invariant.

### The CI failure, and the bug behind it

The first CI run on `0a3abb0f` **failed: 1 test failed, 4795 passed** over 86 minutes. The failure
was mine, and diagnosing it found a bug larger than the symptom.

**Symptom.** `UniqueConstraintViolationException` —
`duplicate key ... (medical_record_number)=(DG-TLK1-2024-0001)` inside a cross-archive odontogram
fixture.

**Root cause.** `legacyRmeArchivablePatient()` (tests/Pest.php) and `lodoPatient()`
(tests/Feature/LegacyOdontogram/helpers.php) both mint
`sprintf('DG-%s-2024-%04d', $branchCode, $sequence)` from their **own independent**
`static $sequence`, both starting at the same value. In a fresh process the first call to each
produces the **identical** number, so any test creating patients through both collides.

It had been masked because the counters are process-global: by the time a mixed test runs, earlier
tests have usually knocked them out of step. "Usually" was the whole problem — a full-suite run hit
the aligned case. Running only the batch suites locally never did.

**What was done, and what was rejected.** The first instinct was a PR2-local workaround — give this
sprint's fixtures a high block and move on. That was rejected because it would leave PR1's own
mixed tests still relying on luck. Nothing asserts the exact values these helpers emit (verified),
so the root fix was safe: three disjoint blocks, `legacyRmeArchivablePatient` `1+`, `lodoPatient`
`3000+`, `lbpDisjointMedicalRecordNumber` `7000+`.

**The guard was verified in both directions**, because a regression test that only passes proves
nothing: with the fix it passes; with the offset reverted it fails with exactly the
`UniqueConstraintViolationException` CI produced.

A second latent flake of my own was fixed alongside it: the patient-binding test hardcoded a
drifted record number that could, once the shared sequence had advanced, coincide with its own
patient's real number — silently turning a negative test into a test of nothing. It now derives the
drifted value from the patient under test.

Regression after the root fix: the ~10 suites that use the changed helper pass **320/320**.

### What the security review found, and what I did

No CRITICAL, HIGH or MEDIUM. Five LOW, each verified against the code before being acted on:

- **The concurrency suite's docblock claimed separate PDO connections driving real concurrent
  transactions. That was false** — the tests are sequential on one connection. Corrected to state
  what is proven and what is not. §8 asks for honest attribution, and a flattering description of
  one's own method is the same failure in a different place.
- `created` was derived from a pre-read; RME now uses the canonical `changed` flag, odontogram's
  limitation is documented (its publish service computes `created` and discards it), and
  authorship is arbitrated against the attempt table.
- `select()` reported a benign `ALREADY_PUBLISHED` as a refusal while `publish()` treated it as
  "nothing to do". The two paths now agree.
- `finalize()` wrote the run status without the transition map, so an in-flight pass could
  resurrect an ABANDONED run to COMPLETED. Guarded.
- Cumulative selection was unbounded across requests. Bounded.

### What mutation found

Two of fourteen survived the first run, both weak assertions of mine:

- **"mark refused item published"** survived because no publish-*path* refusal asserted the item
  STATUS, only its reason code — and the selection path and publish path have different writers.
  Now killed.
- **"skip patient binding"** survived, and still does, because it removed only the adapter's
  advisory pre-flight. The canonical publish asserts the same binding independently under its row
  lock, so the document is still refused with the same code: a behaviourally equivalent mutant.
  **The equivalence was proven, not assumed** — two stronger mutants (removing the assertion from
  the canonical publish; removing it from both layers at once) are **both killed**. A note in the
  guard suite records this so future mutation work targets the canonical layer.

**No mutant that actually removes a guard survives.**

---

## Shipped

PR #448 squash-merged as `ac9acd9f16914650feea14242003ff8c6962ce27`.

CI was green on the **exact** candidate SHA `915fc096` — CI head = PR head = local HEAD, so the
green run describes the code that merged and not an earlier push.

**NSF-R011 Full Suite was SKIPPED on `pull_request`** under the standing temporary deferral policy,
even though the CICD-CTRL-1 classifier marked it required. **SKIPPED is not PASS.** No Full Suite
evidence is claimed for this PR. The substitute evidence is the local regression and the real
PostgreSQL 16 runs recorded under *Verification* above — not a full-suite run. CI was not weakened
to avoid the runtime.

GO tag `feature-legacy-batch-review-publish-1-pr2-go` @ `ac9acd9f` (annotated, no force), verified
equal at all four points:

| point | value |
| --- | --- |
| local tag peel | `ac9acd9f1691…` |
| remote tag peel | `ac9acd9f1691…` |
| VPS `HEAD` | `ac9acd9f1691…` |
| `git describe --exact-match HEAD` on VPS | `feature-legacy-batch-review-publish-1-pr2-go` |

## Production deployment

Deployed with `scripts/deploy-vps-runner.sh start`, run **on** the VPS (detached; the runner is
designed to execute there, and driving it over a foreground SSH session is what the evidence-capture
timeout trap is about).

- backup taken **before** migrate, per the standing rule
- migration `2026_10_04_200001_create_legacy_batch_publish_tables` → `[77] Ran`
- `migrate --force` only — never `migrate:fresh`, never `db:wipe`
- route count 701 → 713 (+12, the batch-publish routes)
- `DEPLOY OK`, `exit=0`, `DEPLOY_HEAD_TARGET_MATCH=YES`
- no seeder step: PR2 adds no permission and no new ability

The single deploy smoke warning is the **known pre-existing nginx co-tenant shadow** —
`http://127.0.0.1/login` returns 404 because a co-tenant app answers the bare loopback; the
canonical entry point `https://daengtisia.online/login` returns 200. Unrelated to this PR.

## Production verification

Read-only. **No clinical document was published, and no fake patient, RME or odontogram was created
to exercise the publish path.** The publish path is proven by the test and mutation evidence above;
production is proven by structure, wiring and read-only state.

| # | check | result |
| --- | --- | --- |
| 1 | VPS `HEAD` | `ac9acd9f`, exact-match |
| 2 | env / debug / maintenance | pilot / OFF / OFF |
| 3 | migration | `[77] Ran` |
| 4 | `stg_legacy_batch_publish_runs` + `_items` | both PRESENT |
| 5 | batch-publish routes | 12 |
| 6 | batch-publish super-permission | **0 — none exists** |
| 7 | 3 services + both adapters | all resolve from the container |
| 8 | RME `SeparatePublisherGuard` | `enabled=true`, guards `review,publish` |
| 9 | Odontogram separation guard | **0 files — asymmetry preserved** |
| 10 | PR1 `LegacyReviewTriageService` | wired; `blockingFor()` consumable |
| 11 | bounds | `MAX_SELECTION=100` `MAX_OUTSTANDING=500` `MAX_PUBLISH_PASS=50` `MAX_PER_PAGE=100` |
| 12 | new table row counts | `0` / `0` — the deploy created nothing |
| 13 | canonical archives | unchanged: 9 RME, 1 odontogram published |
| 14 | audit channels | `HTTP,CLI,BATCH` |
| 15 | PostgreSQL | 16.15 |
| 16 | `https://daengtisia.online` `/login` `/health/live` `/health/ready` | 200 / 200 / 200 |
| 17 | new surfaces, guest | both 302 — no 500 |
| 18 | PR1 review surfaces | both 302, unaffected |
| 19 | sibling canonical surfaces | `legacy-imports`, `legacy-mass-imports`, `legacy-odontograms` all 302 |
| 20 | php8.3-fpm + nginx | active; `queue:failed` empty |
| 21 | deploy-caused log errors | **0** |

On #21, the honest detail: `laravel.log` holds exactly one entry dated today, a
`The "--columns" option does not exist` CLI typo at **01:27:06** — six hours *before* the 07:30:16
deploy, from the PR1 verification window. The file's mtime is still 01:27:06, so the PR2 deploy
wrote no log entry at all.

## Deployment posture

Gated by the **existing** legacy migration capability guard, not a new flag — that guard already
gates every legacy upload, review, publish and VOID path, and the owner declined a second
OFF-by-default flag after PR1's GO. Those flags are enabled in production, so the batch publish
workspace is live for anyone **already** holding `publish_legacy_rme_imports` or
`publish_legacy_odontogram_imports`. Nobody gains a permission, and every document still passes the
complete canonical gate chain one document at a time.

## Status

**GO.** PR2 merged, deployed, production-verified and GO-tagged at `ac9acd9f`.

**FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 is COMPLETE** — PR1 (batch review, `01b28aeb`) and PR2
(batch publish, `ac9acd9f`), both deployed and both GO-tagged. The operator flow is now: mass upload
→ preflight → process → batch review workspace → human review per item → submit reviewed in bulk →
batch publish → server re-validates each item → publish the eligible ones → report partial failures
with reason codes.
