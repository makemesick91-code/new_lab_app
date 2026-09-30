# FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1

**Branch:** `feature/legacy-rme-odontogram-mass-upload-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `c4e67ea706de4f52d6be98280a0f840c6778e449`
**Baseline GO tag:** `revision-legacy-single-active-document-per-patient-1-go` (= base HEAD = production HEAD at start)

Adds **Mass Upload Legacy RME** and **Mass Upload Legacy Odontogram**: bulk
intake of historical patient archives via one ZIP + one manifest CSV, with a
mandatory preflight the operator reviews before anything is created.

Operator documentation: `docs/runbooks/legacy-mass-upload-operator-runbook.md`
Durable rules: `.cursor/rules/100-legacy-mass-upload.mdc`

---

## What this is, architecturally

Mass upload is an **orchestration layer**. It owns batching, package safety,
preflight reporting and bounded dispatch. It owns no clinical rule.

```
MASS BATCH
  → package/manifest validation      (new)
  → preflight per item               (new — reads canonical services)
  → operator review + confirm        (new)
  → LegacyRme/LegacyOdontogramImportService::createFromUpload()   ← CANONICAL
      → advisory slot lock, binding, dates, branch, admission, quota, PDF,
        storage, render dispatch                                   (unchanged)
  → existing review → existing separate-publisher publish          (unchanged)
```

**No migration to the clinical archive. No new queue. No new permission. No new
clinical rule. 8919 insertions, 0 deletions.**

---

## Four findings that shaped the design

### 1. The odontogram path had no wrong-patient defence to inherit

RME has `assertSourcePatientBinding()`, built after a real production
wrong-patient binding — the canonical service says so in a comment: *"This is the
gate the Wave-2 wrong-patient binding did not have."*

`LegacyOdontogramImportService::createFromUpload()` takes **no `sourceRm`
argument at all**. Its only wrong-patient defence is the human
`patient_confirmation` checkbox — exactly the per-document human act that mass
upload removes by definition.

Shipping mass odontogram as "pure orchestration" would therefore have left it
with **no wrong-patient defence whatsoever**. So the odontogram adapter asserts
the manifest RM against the resolved patient using the same canonical binding
service, hard-blocking any mismatch, **at preflight and again on the write
path**. Mass odontogram is consequently *stricter* than single odontogram upload.

The write-path assertion is not redundant: unlike every other gate it is not
re-evaluated inside the canonical call, because that call takes no `sourceRm`.

### 2. Patient lookup falls back to a suffix match across all branches

`CrossBranchPatientLookupService` tries exact (with branch-code alias
equivalence), then `LIKE '%'.$rm` across **every** branch. A manifest value of
`27541` will happily find `TLK1-2019-27541`.

At bulk scale that is a wrong-patient generator. It is caught because
`bind()` runs its **own** exact, near-miss-free resolution and refuses — so the
binding is not a tautology even though the lookup found the patient by the same
string. The mass layer maps that refusal to `SOURCE_RM_INVALID` ("write the RM in
full") rather than `SOURCE_RM_MISMATCH` ("wrong patient"), because at 250 rows
the wrong instruction is expensive.

### 3. The daily quota exists but is currently unlimited

`config/legacy_import_hub.php` sets `daily_limit.legacy_rme` and
`legacy_odontogram` to **`null`**, with invariants
`business_quota_unlimited_by_default` and
`technical_backpressure_survives_quota_removal`. The historical 100/day was
removed by the Sunu unlimited revision.

Mass upload adds no business cap (§38) but does honour `preview()` /
`remainingToday()`, so if a limit is ever declared it appears in preflight rather
than being discovered at item 101. Chunked dispatch is technical backpressure,
not a quota.

### 4. Naming the routes under `legacy-imports.*` would have broken the sidebar

The single-upload entry marks itself active with
`routeIs('settings.rme.legacy-imports.*')`. A `legacy-imports.mass.*` name would
match that wildcard and light up the **single** upload entry while the operator
was on a mass page. Hence the disjoint `legacy-mass-imports` /
`legacy-mass-odontograms` prefixes, pinned by tests.

---

## Schema (additive only)

`2026_09_30_100001_create_legacy_mass_upload_staging_tables`

- `stg_legacy_mass_upload_batches` — operator work-queue: uuid, import_type,
  status, creator/confirmer, provenance branch, package digest/size, manifest
  digest, per-status counts, package-rejection reason, lifecycle timestamps.
- `stg_legacy_mass_upload_items` — one manifest line: verbatim manifest values,
  normalized source RM, server-resolved patient/branch, document path/digest/size,
  status, reason code + operator-safe message, and the created canonical import id.

`UNIQUE(batch, row_number)` and `UNIQUE(batch, file_name)`. `migrate` only —
never `migrate:fresh` / `db:wipe`.

Neither table is clinical. A mass item does **not** occupy a patient's document
slot; only a real canonical import does. That is what makes a BLOCKED item free.

---

## Evidence

| Gate | Result |
|---|---|
| Mass upload suite (SQLite) | 88 passed, 7 skipped / 691 assertions |
| Mass upload suite (PostgreSQL 16.15) | 73 passed / 612 assertions |
| Concurrency (PG 16, advisory locks) | 7 passed / 18 assertions |
| Broad legacy regression (LegacyRme + LegacyOdontogram + LegacyImportHub) | 1276 passed, 15 skipped / 3663 assertions |
| Migration on PostgreSQL 16 | applied, 47.6ms |
| Mutation / adversarial | **17 KILLED / 1 SURVIVED (documented) / 0 not-applied** |
| Pint (`--dirty --test`) | passed |
| `git diff --check` | clean |
| `view:cache` | compiles |
| Governance: self-hosted-runner / ci-runtime-control / security-compliance / cicd-enterprise / ui-governance / roadmap | all **GO** |

The 7 skips are the concurrency suite on SQLite, which cannot take an advisory
lock. The skip is stated out loud rather than reported as a pass.

### What the mutation campaign actually taught us

Four mutants initially survived. Three were **real coverage gaps**, now closed by
new tests (blocked rows are never attempted; a stale resume skips a row that
already produced an import; a document substituted between preflight and
creation fails; an unadmitted branch is refused). One was a survivor for a good
reason (a second PDF check caught it) and one was a mis-targeted mutant.

The single remaining survivor, `M04f`, removes an in-loop idempotency marker that
is redundant with the dispatch query's own filter. Removing **either** alone is
caught by the other (`M04e` and `M04g` are both KILLED); the marker's own purpose
— two passes selecting the same row before either commits — needs true
parallelism, which the concurrency suite states in-process testing cannot
produce. Documented rather than papered over.

The campaign's most valuable output: with **both** mass-layer idempotency guards
removed, no duplicate lifecycle appears, because the canonical advisory-locked
slot guard refuses it. Duplicate prevention does not rest on this layer at all.

---

## CI wiring

`LegacyRme` does **not** match `LegacyMassUploadRmeTest` — `--filter` matches the
test identity and the contiguous substring is `LegacyMassUploadRme`. Without
action these 88 tests would have matched no gate token and run only by accident.

- `LegacyMassUpload` token added to **both** critical-gate variants
  (GitHub-hosted and self-hosted) in `.github/workflows/foundation-evidence-gates.yml`.
- Three suites declared in `config/ci_runner.php` `critical_gate_mandatory_suites`
  (Package, Rme, Odontogram) so the coverage reconciliation fails loudly if the
  token is ever dropped or a file renamed.

`foundation:self-hosted-runner-check` → GO, 11/11.

---

## Deploy

No seeder, no permission change. One additive migration.

```bash
php artisan migrate --force
```

Feature visibility follows the existing legacy feature guards — mass upload is
reachable exactly where single upload already is, and nowhere else.

---

## Out of scope, deliberately

- Publishing. Mass upload prepares review only.
- VOID and correction — per document, existing authority.
- Visit-bound ingestion. Mass upload uses the backlog pathway and never
  fabricates a visit to unlock an import.
- Historical visits, invoices, payments, consent, odontogram payloads.
- Any daily business quota.
