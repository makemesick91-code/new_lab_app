# FEATURE-LEGACY-IMPORT-HUB-1 — Legacy Import Hub

**Branch** `feature/legacy-import-hub-1`
**Base** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `5abd4ac`
(GO tag `fix-rme-print-remove-patient-visit-duplicate-1-go`, tree `9496904`, exact-match on production)

**Do NOT target `main`.**

---

## What this sprint is

Three legacy importers already existed, built at different times for different
reasons, each complete on its own terms:

| Capability | Shipped in | What it does |
|---|---|---|
| Legacy Patient | Sprint 62.3 | CSV master-data → staging → preview → commit |
| Legacy RME | LEGACY-RME-PDF-1A..ROLL-4 | scanned RME PDF → review → publish |
| Legacy Odontogram | FIX-04b | scanned odontogram chart → review → publish |

They had no shared home (two lived under *Master Data RME*, Legacy Odontogram
had no menu entry at all), no shared vocabulary, and no shared limit — Legacy
RME had a wave-scoped quota, the other two had none.

This sprint adds exactly two things and changes nothing else:

1. **One canonical navigation group**, `Import Data Legacy`, with a landing page
   that reports each capability's status, ceiling, usage and remaining capacity.
2. **One canonical daily ceiling** — 100 accepted records per branch per
   clinical day per import type — enforced through the same code path in all
   three importers.

## What is deliberately NOT changed

- **No importer's validation, branch resolution, review, publish, void or read
  path.** Not a line.
- **The ROLL-4 wave quota stays.** It answers a different question (how much may
  this WAVE take today, across every branch enrolled in it) that the hub ceiling
  cannot express. Both apply; the tighter wins; neither can widen the other.
  Deleting it to "consolidate" would remove a governance control.
- **No permission is created or widened.** The hub itself is reachable only by
  an actor who already holds one of the three capabilities' own permissions, and
  grants nothing of its own. Existing Legacy Odontogram permissions ARE assigned
  to two roles that did not hold them — see *Role grants* below; that is an
  explicit owner decision, not a side effect, and it is the one place this sprint
  changes who can do what.
- **No clinical side effect.** Legacy imports still create no clinic visit,
  medical record, odontogram, invoice, payment, prescription, lab order or
  SATUSEHAT resource.

---

## The ceiling, exactly

> **100 ACCEPTED records, per BRANCH, per CLINICAL DAY, per IMPORT TYPE.**

### ACCEPTED

One unit is consumed when a record is admitted into its canonical store, inside
the same transaction that writes it.

| Importer | Charged at | Unit |
|---|---|---|
| Legacy RME | inside `createFromUpload`'s transaction | one staged document |
| Legacy Odontogram | inside `createFromUpload`'s transaction | one staged document |
| Legacy Patient | inside `commit`'s transaction, after the rows are known | one COMMITTED patient row |

Legacy Patient is charged at COMMIT rather than at upload because **staging is
not acceptance**: a staged batch writes nothing to `mst_patients` and can be
discarded outright, so charging at upload would bill an operator for work they
never committed. It is reserved *after* the rows are known, so a row the RM/KTP
re-check skips costs nothing.

Consequently:

- a refused upload, a failed validation, a duplicate or an error row consumes
  nothing;
- a rolled-back transaction releases its slot with no compensating write,
  because the increment lives inside it;
- a **retry** re-queues render work for a document that was already accepted and
  already charged, and is never charged again;
- a **re-commit** of an already-committed batch is a no-op and cannot be billed
  twice;
- **rollback does not refund.** Releasing a slot after the fact needs a
  compensating write that can itself fail, and the safe direction for a ceiling
  is to under-admit.

### BRANCH

Always **server-resolved**. For the two archives it is derived from the
branch-code segment of the patient's Nomor RM; for Legacy Patient it is the
branch the row's `Cabang` column resolved to, which is required and strict (a
row whose branch cannot be resolved is an ERROR row and never commits).

A request-supplied branch is never the authority — pinned by a test that passes
a forged `?branch_id=` and asserts nothing changes.

### CLINICAL DAY

`ClinicalClock` — the same clinical calendar the legacy date rules and the
ROLL-4 quota use. Never `config('app.timezone')`, which resolves to UTC in
production and would roll the quota over at 08:00 WITA, in the middle of an
Indonesian working morning.

Pinned at both boundaries: 23:59:59 clinical time is still today; one second
later is not.

### IMPORT TYPE

The three are counted separately. A branch that has archived 100 RME documents
today may still archive 100 odontogram charts and admit 100 patients. They are
different work, done by different people, with different downstream cost.

### NULL is not zero

A `NULL` ceiling declines to limit. A ceiling of `0` admits nothing. Collapsing
them would turn "we did not set a quota" into "this capability is closed".

A ceiling configured above `max_declarable_daily` (500) is **clamped**, and the
hub says so rather than quietly disagreeing with the environment.

---

## Concurrency

The ceiling is a concurrency problem, and counting rows cannot be made safe by
adding a transaction: two uploads racing for the last slot both read N-1 and
both insert, because the row that would block the second one is the one neither
has written yet.

So a counter row exists **before** the decision:

1. `insertOrIgnore` creates the bucket (race-safe, `ON CONFLICT DO NOTHING`);
2. `SELECT ... FOR UPDATE` locks it, ordered by `branch_id`;
3. the decision is taken from the locked value;
4. the increment shares the transaction with the record it counts.

### Lock ordering

Two lock orders exist on the Legacy RME path — this ceiling's bucket, and the
ROLL-4 wave buckets. Both order by `branch_id` internally, so the only way to
form a cycle is for a caller to take them in opposite sequence.

`LegacyRmeImportService::createFromUpload` is the single site that takes both,
and it takes **the hub bucket first, always**. `reserveMany` sorts branch ids
before locking for the same reason.

### How it was proven

SQLite compiles `lockForUpdate()` to nothing, so the whole SQLite suite —
correct as it is — cannot prove the property that matters.

`LegacyImportHubConcurrencyTest` therefore runs against **PostgreSQL 16.14**
(the production major) and **skips loudly** on any other driver. It also does
not use `RefreshDatabase`: that trait wraps each test in a transaction which is
never committed, so a second session cannot see the row at all, and a lock on an
invisible row is unobservable — `FOR UPDATE NOWAIT` would match zero rows, raise
nothing, and the test would report "not locked" whether or not the lock existed.
The suite drives the real service over a connection `RefreshDatabase` does not
manage, and includes a sanity assertion that the probe CAN see the row when
nobody holds it, so the lock assertion cannot pass for the wrong reason.

Result: **4 passed** against PostgreSQL 16.14.

---

## Architecture

```
HTTP → LegacyImportHubController (thin)
     → LegacyImportHubService            (status, scope, what the page reports)
     → LegacyImportDailyQuotaService     (the ceiling)
     → LegacyImportDailyQuotaRepositoryInterface
     → LegacyImportDailyQuotaRepository  (the only place buckets are locked)
     → LegacyImportDailyQuota
```

New bounded context `App\Modules\LegacyImport`. The repository interface is
bound in `RepositoryServiceProvider`. There is deliberately **no** increment on
the boundary that does not go through `lockBuckets()`.

### Migration

One additive table, `ops_legacy_import_daily_quotas`:

- `UNIQUE(import_type, branch_id, quota_date)` — every component NOT NULL,
  because PostgreSQL treats NULLs as distinct in a unique index and a nullable
  component would silently permit two buckets for the same day;
- `INDEX(quota_date, import_type)` for the hub page's read path.

No column altered, no column dropped, no backfill, no destructive statement.

---

## Authorization

The hub route accepts the union of the three capabilities' own view permissions
and grants nothing:

- an actor holding **only** `manage patients` reaches the hub and still gets
  **403** on both archive importers;
- an actor holding none of the three gets **403** on the hub;
- the branch scope is `LegacyImportHubService::branchIdsFor()` — governance tier
  (any review/publish/void permission from either archive) sees the whole RME
  branch set, anyone else sees the single branch their server-resolved context
  places them in, and only when it is RME-enabled;
- a forged `?branch_id=` changes nothing;
- a branch that is not RME-enabled (MAIN) yields **no** branches, not the RME
  set.

The sidebar hides what an actor cannot use, and **is not the boundary**. The
route's permission middleware is pinned by its own test: a mutation that deleted
it left every behavioural test green, because the controller's re-check still
denied — defence in depth held, but the layer was not pinned. It is now.

---

## Evidence

### Tests

| Suite | Result |
|---|---|
| `LegacyImportHubQuotaTest` | 19 passed |
| `LegacyImportHubIntegrationTest` | 14 passed |
| `LegacyImportHubSurfaceTest` | 20 passed |
| `LegacyImportHubConcurrencyTest` (SQLite) | 4 skipped — stated, not silent |
| `LegacyImportHubConcurrencyTest` (PostgreSQL 16.14) | 4 passed |
| **Directory total (SQLite)** | **53 passed, 4 skipped, 146 assertions** |

### Mutation testing

Ten mutants, each a real defect this feature could plausibly acquire:

| # | Mutation | Killed by |
|---|---|---|
| M1 | ceiling 100 → 101 | 4 tests |
| M2 | branch scope dropped from the reservation | 3 tests |
| M3 | `lockForUpdate()` removed | 1 test |
| M4 | the three types collapsed into one bucket | 9 tests |
| M5 | clinical day replaced by the UTC day | 2 tests |
| M6 | hub route's permission middleware removed | **survived** → gap closed, now killed by 1 test |
| M7 | Legacy RME importer stops charging | 2 tests |
| M8 | Legacy Patient commit stops charging | 5 tests |
| M9 | Legacy Odontogram importer stops charging | 1 test |
| M10 | hub trusts a request-supplied branch | 1 test |

M6 is the one that matters: it survived because the controller's re-check kept
the *outcome* correct while a *layer* was deleted. The suite now pins the layer.

---

## CI

The critical gate selects these suites two ways, deliberately:

1. the `LegacyImportHub` filter token, added to **both** critical variants
   (GitHub-hosted and self-hosted — they must stay identical);
2. `config/ci_runner.php` `critical_gate_mandatory_suites`, which FAILS the gate
   if a declared file is no longer selected or no longer exists.

The concurrency suite is deliberately **not** declared mandatory: it can only
ever skip on CI's SQLite, and a declared suite that always skips reads as
evidence it is not.

---

## Role grants — an audit finding, and the owner decision that closed it

FIX-04b created the five legacy **Odontogram** permissions and assigned them to
**no role**. The capability shipped complete and was reachable only by Super
Admin. Worse, the *Master Data RME* sidebar group is wrapped in
`@unless($user?->hasRole('Admin Klinik'))`, so Admin Klinik could not even see
the legacy **RME** entry for permissions it already held.

The hub surfaced both gaps. The owner decided to **mirror the legacy RME archive
exactly**:

| Role | Legacy Odontogram | Legacy RME (unchanged) |
|---|---|---|
| Admin Klinik | `view_` + `create_` | `view_` + `create_` |
| Supervisor RME | `view_` + `review_` + `publish_` | `view_` + `review_` + `publish_` |
| Super Admin | everything, incl. `void_` | everything |
| Doctor / Kasir / Owner / Perawat | none | none |

**The omissions are load-bearing.** Admin Klinik gets no review/publish, so the
operator who files a chart never certifies it into patient history. Supervisor
RME gets no `create_`, so it cannot review its own intake. `void_` stays with
Super Admin, because retracting published clinical evidence is heavier than
publishing it.

**No permission was created.** Only existing ones were assigned.

Two exact-list pins were re-anchored rather than deleted:

- `LegacyOdontogramFoundationTest` asserted "NO operational role holds these",
  which is now false by design. It was replaced with the **stricter** exact
  holder set per permission — a grant to any other role, or of a withheld duty,
  fails there.
- `SupervisorRmeRolePermissionTest`'s exact list repinned +3, with the two
  omissions documented inline.

## Deployment

```bash
php artisan migrate --force                      # one additive table
php artisan db:seed --class=RoleSeeder --force   # the odontogram grants
php artisan permission:cache-reset
```

No new permission definition. No PermissionSeeder change.

**Capability activation** is an environment change on the VPS, made separately
and recorded in the deploy evidence:

| Flag | Meaning |
|---|---|
| `FEATURE_RME_LEGACY_PDF_ARCHIVE` | Legacy RME migration/ingestion |
| `FEATURE_RME_LEGACY_ODONTOGRAM_ARCHIVE` | Legacy Odontogram migration/ingestion |

Legacy Patient has no flag — its permission is its availability.

Turning a flag on does **not** by itself make Legacy RME usable: ROLL-3 branch
admission and a matching ACTIVE ROLL-4 wave are still required, and the hub card
says so rather than implying a green flag is the whole story.

**Activation posture chosen for this deploy: both flags ON, no wave created.**
Legacy Patient and Legacy Odontogram become fully usable immediately. Legacy
RME's surface opens and honestly reports that a wave and an admitted branch are
still outstanding — creating one is a separate governed act with its own owner
approval reference, and this sprint deliberately does not perform it.
