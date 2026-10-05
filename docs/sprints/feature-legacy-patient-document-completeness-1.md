# FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — Kelengkapan Arsip Pasien Legacy

**Branch** `feature/legacy-patient-document-completeness-1`
**Base authority** `d065185d271f3b6aba3374acb00176826958d9b7` (`origin/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`)
**Rule mirror** `.cursor/rules/172-legacy-patient-archive-completeness.mdc`
**Manifest** `.sprint/current.yml` — `MODULE_SPRINT`

A read-only, branch-scoped monitor at **Import Data Legacy → Kelengkapan Arsip
Pasien Legacy**: which legacy-imported patients do not yet hold both a published
legacy RME and a published legacy odontogram.

**No migration. No index. No new table. No schema change. No JS or CSS change.**
One new read permission, one new GET route, one new Blade view.

---

## 1. Why the page exists

The migration backlog was invisible. An operator could see individual imports,
and could see one patient's archive by opening that patient, but nothing answered
*"whose archive is still unfinished?"* across the estate. On production today
that gap is large: **1519 legacy patients, 19 published legacy RME archives and
1 published legacy odontogram archive.**

---

## 2. Provenance — the one predicate, measured not guessed

`mst_patients.import_batch_id IS NOT NULL` — a nullable, indexed FK to
`stg_legacy_patient_import_batches`, added by Sprint 62.3 and written by
`LegacyPatientImportService::commit()`. It is the only canonical marker.

It is applied in the repository's **single base query**, so no filter, parameter
or crafted request can switch it off. Native exclusion is structural, not a
property of the default filter.

**Never** derived from `created_at`, an old-looking RM year, the RM format, the
branch, a missing NIK/KTP, the absence of a native RME, or the presence of a
legacy document.

### A trap worth naming

`Patient::isLegacyWithoutBranch()` already exists and means something entirely
different — `branch_id IS NULL`, from Sprint 23.10. Using it here would have
listed native branchless patients and missed every imported patient that has a
branch. It is not a provenance predicate.

---

## 3. Completeness, derived from the real state machines

Per document type, per patient — and in the **same order**
`LegacySingleActiveDocumentService::occupancyFor()` uses:

| Condition | Effective state | Needs upload? | Complete? |
|---|---|---|---|
| published archive record | `PUBLISHED` | no | **yes** |
| else slot-occupying staging row | `IN_PROGRESS` | no | no |
| else void archive record | `VOID` | yes | no |
| else | `MISSING` | yes | no |

The archive is consulted **before** staging. That order is load-bearing: a
staging row keeps its historical `PUBLISHED` status forever, so asking staging
first would make a void-then-republished record read as in-progress permanently.

The agreement is asserted against the **real** service, not against a
restatement of its rules — a copy of those rules in a test would pass while
production drifted.

### Patient verdict

| Both documents | Verdict | Shown by default |
|---|---|---|
| both `PUBLISHED` | `COMPLETE` | no (audit filter only) |
| not complete, at least one `IN_PROGRESS` | `IN_PROGRESS` | yes |
| otherwise | `INCOMPLETE` | yes |

Completeness is per **patient**; state is per **document**. A patient with a
published RME and no odontogram has a `PUBLISHED` document state and an
`INCOMPLETE` verdict. Those are different questions and are never collapsed.

---

## 4. IN_PROGRESS is not MISSING

A slot-occupying staging row still owns the patient's slot, so a new upload would
be refused by the single-active-document guard. Reporting it as
*"Belum Ada — upload"* sends an operator to an action the server rejects.

- **`FAILED` is IN_PROGRESS.** `FAILED → QUEUED` is a legal transition, so the
  import is retryable and still holds the slot.
- **`CANCELLED` is MISSING.** Terminal, and it produced no archive.
- **A soft-deleted staging row still occupies the slot.** The staging aggregates
  apply **no** `deleted_at` filter, matching `firstSlotOccupyingForPatient()`'s
  `withTrashed()`. A soft delete never releases a slot; only an audited CANCEL or
  VOID does. Guaranteed by going through `DB::table()` rather than the Eloquent
  models, so there is no global soft-delete scope to forget to disable.

### Labels (no invented states)

| State | Label | Tone |
|---|---|---|
| `MISSING` | Belum Ada | neutral |
| `IN_PROGRESS` + DRAFT/UPLOADED/QUEUED/PROCESSING | Dalam Proses | info |
| `IN_PROGRESS` + READY_FOR_REVIEW | Siap Ditinjau | info |
| `IN_PROGRESS` + REVIEWED | Ditinjau | info |
| `IN_PROGRESS` + FAILED | Gagal — Dapat Diulang | info |
| `IN_PROGRESS` + blocking review triage | Ditahan Peninjau | warning |
| `PUBLISHED` | Published | success |
| `VOID` | Void / Perlu Koreksi | danger |

Every label maps onto a value the backend actually holds. The triage overlay
reads `LegacyReviewTriageStatus::blocking()` — the review module's own
vocabulary, reused rather than copied.

---

## 5. Filters

Default **Semua Belum Lengkap** = everything not `COMPLETE`, in-flight work
included. Also: Belum Ada RME, Belum Ada Odontogram, Belum Ada Keduanya, Dalam
Proses, and Lengkap (audit).

**"Belum Ada RME" includes a voided record**, because a voided record leaves the
slot free exactly as a never-filed one does — the filter answers *"whose archive
do I still need to upload?"*. The status column distinguishes a correction from a
first filing. Filter on availability, display the reason.

An unrecognised filter falls back to the default (the narrowest useful view),
never to "everything". The branch scope is applied regardless.

Search covers Nomor RM and patient name, bounded at 100 characters, with LIKE
metacharacters escaped using `!` — **never a backslash**, which makes PDO
miscount placeholders on `pdo_pgsql` through PHP 8.3.

---

## 6. Authorization

New permission **`view_legacy_patient_archive_completeness`**, classified in the
`rme` group. Granted to **Supervisor RME**, **Admin Klinik** and **Front
Office**; Super Admin passes via the single global `Gate::before`.

Denied: Doctor, Kasir, Perawat, Owner, Admin Lab, Technician, Admin Warehouse,
Finance, Kepala Cabang — on a **direct GET** as well as in the sidebar.

An intake permission was deliberately **not** reused: every branch upload
operator holds `view_legacy_*_imports`, so reuse would have decided this page's
audience as a side effect of who may upload.

The permission is absent from every `GOVERNANCE_PERMISSIONS` list, so granting it
can never widen a holder's branch scope.

### The §13 role mismatch — resolved, and reported

The owner asked for Super Admin / Supervisor RME / Admin Klinik. Measured
read-only on production:

| Role | Accounts |
|---|---|
| `Admin Klinik` | **0** |
| `Front Office` | 8 — incl. 29 Admin Sunu, 30 Admin Landak, 31 Admin Antang, 32 Admin Telkomas |

Every branch clinic admin carries `Front Office`. And `Front Office` is **defined
as the exact union** of the two legacy roles it merged
(`FrontOfficeRole::LEGACY_ADMIN_CLINIC` ∪ `LEGACY_KASIR`), with
`FrontOfficeRoleTest` deriving that union from their arrays precisely so
*"a permission added to Admin Klinik later must not silently skip the merged
role"*.

So granting Admin Klinik alone would have **broken a tested invariant and
reached nobody**. Both roles are granted.

**The measured consequence, stated rather than glossed:** the four generic
front-desk accounts (7, 8, 16, 17) share that role and gain the page too. They
already hold `manage patients`, `view_rme_patient_reports` and both legacy intake
view permissions, so the report discloses **no patient field they cannot already
reach**, and it stays pinned to their own branch.

**If the owner prefers it restricted**, the reversal is to drop the Front Office
line from `RoleSeeder` and provision real Admin Klinik accounts; two count pins
move back. That is an owner decision, not an engineering one.

---

## 7. Branch scope — borrowed, server-side, fail-closed

| Tier | Scope |
|---|---|
| Super Admin | every active RME branch (`Gate::before`) |
| Supervisor RME | every active RME branch (governance tier) |
| Admin Klinik / Front Office | own `BranchContext` branch only |
| unresolvable / MAIN / non-RME | **empty — no rows at all** |

Governance tier = `LegacyImportHubService::GOVERNANCE_PERMISSIONS`, **referenced
rather than copied**. One definition, shared with the sibling hub page in the
same module and nav group. In this system that set is held only by Supervisor
RME.

### Two scopes deliberately rejected

- **`LegacyOdontogramWorkspaceScope`** — its governance set includes
  `create_legacy_odontogram_imports`, which Admin Klinik and Front Office both
  hold. Reusing it would promote every branch intake operator to estate-wide
  visibility: the exact opposite of the requirement.
- **`RmeWorkingBranchScope`** — the clinic-operations authority, which fails
  closed to empty for a context-bound role with no active online context. The
  legacy archive's own established authority is the BranchContext-based workspace
  scope, which is also what resolves the Front Office branch pin.

A requested branch can only **narrow**: the authorized set is resolved first,
then intersected. A branch the actor may not read is dropped, so a crafted
`?branch_id=` leaves the scope unchanged. All counters respect the scope, and
the branch filter only offers branches inside it.

A branchless legacy patient carries no provenance, so it is governance-only —
`branch_id IN (…)` is never true for `NULL`, which makes it invisible to a
pinned actor for free.

---

## 8. Read-only, structurally

- The repository interface exposes **no** `create`, `update`, `delete`, `upsert`,
  `insert`, `transition`, `publish`, `void` or `cancel` — asserted by reflection.
- The module registers **exactly one route**, and it is a `GET` — asserted
  against the real route table.
- Rendering the page issues **zero** `INSERT`/`UPDATE`/`DELETE` statements —
  asserted with `DB::listen`.
- **No clinical artifact of any kind** is created: clinic visit, medical record,
  odontogram, invoice, payment, lab order and SATUSEHAT candidate counts are all
  unchanged.
- No review, publish, void or cancel. No wave assignment. No branch change.

**No row action shipped.** A purely read-only page was the explicitly acceptable
PR1 scope, and it removes any chance of pointing an operator at a refused upload.

---

## 9. PII boundary

Rendered: Nomor RM, patient name, branch, the two document states, the verdict,
the archive dates, the batch id.

Not rendered, and asserted absent: KTP/NIK, address, phone, WhatsApp number,
e-mail, date of birth, occupation, clinical content, document title or
description, storage path, checksum.

The row DTO **is** the disclosure boundary, and its property set is pinned by a
test so a new field cannot reach the template unreviewed.

The identity pair (RM + name) is disclosed because it is what makes a row
actionable, and because it is the established disclosure on every authorized
patient surface in this system — `PatientSelectorSearchService` returns
`id`/`name`/`medical_record_number`/`branch_label` to the same class of operator.

**No CSV export** was shipped: fewer surfaces, and no formula-injection concern.

---

## 10. Query shape and the plan

Four **grouped aggregates** LEFT JOINed — one per legacy table — bounded by the
number of DOCUMENTS rather than patients. A patient with no documents joins to
`NULL`.

Correlated EXISTS was rejected: the filter predicate runs over the whole scope
before pagination, so its cost would grow with the PATIENT count, the dimension
projected to grow fastest.

Portable on purpose — `max(case when … then 1 else 0 end)` rather than
PostgreSQL's `bool_or`, and `max(case when … then date end)` rather than
`FILTER (WHERE …)` — so the identical SQL runs on the SQLite the default suite
uses and the PostgreSQL 16 production runs.

### Measured per-table reads for one page (constant)

| Table | Statements |
|---|---|
| `trx_rme_legacy_records` | 2 |
| `stg_rme_legacy_imports` | 3 |
| `trx_odontogram_legacy_records` | 2 |
| `stg_odontogram_legacy_imports` | 3 |
| `stg_legacy_review_triage` | 1 |

Asserted **equal** at 5 rows and at 60 rows — an N+1 would show as a difference
of 55. A ceiling alone could not see one: a per-row lookup added to a 3-row
fixture fits inside almost any headroom.

### EXPLAIN ANALYZE on the real production estate (1519 legacy patients)

```
Planning Time:   2.871 ms
Execution Time:  0.552 ms
Buffers:         shared hit=14
Index Scan using mst_patients_medical_record_number_unique
  (actual time=0.033..0.047 rows=27 loops=1)
```

The ORDER BY index feeds the `LIMIT 25`, so only 27 patient rows are read — **no
`Seq Scan on mst_patients`, and no sort of 1519 rows**. §17 says not to add an
index until a plan proves one is needed; the plan proves none is, so **no index
was added**.

**Honest limit:** that plan is measured at today's document volume (19 RME
records, 1 odontogram record), where the aggregates are HashAggregates over tiny
Seq Scans. The patient-side scaling — the dimension that actually grows — is
index-driven; as document volume grows PostgreSQL would move those aggregates to
index-based group aggregates using the existing `(patient_id, status)` indexes.

---

## 11. Tests

`tests/Feature/LegacyCompleteness/` — **112 passed / 319 assertions**.

| Suite | Covers |
|---|---|
| `LegacyPatientArchiveCompletenessTest` | provenance (incl. 4 native-exclusion cases), both lifecycle matrices, the agreement with the real occupancy guard across 11 lifecycle shapes, filters, search, counters, vocabulary invariants |
| `LegacyPatientArchiveCompletenessAccessTest` | RBAC (10 denied roles, 4 allowed), branch isolation, crafted `branch_id`, fail-closed scope, the borrowed-scope contract, the read-only surface, the PII boundary |
| `LegacyPatientArchiveCompletenessQueryTest` | query constancy across page sizes, per-table read constancy, pagination bounds, deterministic paging, no per-row model loads |

Verified on **SQLite** and on **PostgreSQL 16.15** — production's exact version,
read live off the deployed host (**112 passed / 319 assertions on both**). The
PostgreSQL run used a throwaway `postgres:16` container and created 156 tables in
it, so the driver is proven rather than assumed. Adding the four modified sibling
suites to the same PostgreSQL run gives **149 passed / 478 assertions**.

### Mutation testing — 14 feature mutants + 2 security-fix mutants, all killed

Three survivors in the first pass were all real gaps and were closed:

1. **Derivation order inverted** survived — the discriminating state (published
   record **and** a live staging row) was untested. Closed with a test asserting
   a published archive outranks an anomalous in-flight import.
2. **Controller read-permission re-check removed** survived — every denial test
   was answered by the route middleware first, so none could see the second layer
   disappear. Closed with a test that **lifts the middleware**. This is the
   documented `FEATURE-LEGACY-IMPORT-HUB-1` M6 trap recurring.
3. **`deleted_at` filter removed** appeared to survive but was a **mis-placed
   mutant** (it hit `branchOptions`, not the listing). Re-placed as two separate
   mutants, both killed — and the gap it exposed in `branchOptions` was closed
   too.

### Regression

| Scope | Result |
|---|---|
| whole legacy programme (`LegacyPatient\|LegacyRme\|LegacyOdontogram\|LegacyMassUpload\|LegacyBatchReview\|LegacyBatchPublish\|LegacyImportHub\|LegacyCompleteness`) | **1693 passed**, 34 skipped, 0 failed |
| permissions / RBAC / sidebar / CI (`Permission\|AccessControl\|SupervisorRme\|Sidebar\|RoleManagement\|FrontOffice*\|Cicd`) | **1014 passed**, 9 skipped, 8 failed — all 8 the pre-existing `FrontOfficeDevice` set (see below) |

### Exact-list pins reconciled (not weakened)

Four count/list contracts moved because a permission was added, each with its
reasoning recorded inline:

- `FrontOfficeRoleTest` — union 22 → 23 (two places)
- `FrontOfficeLegacyPolicyTest` — Admin Klinik 21 → 22
- `FrontOfficeMigrationTest` — `expected_permission_count` 22 → 23
- `SupervisorRmeRolePermissionTest` — exact list +1

### A latent seed-dependent defect this sprint exposed in another sprint's test

CI run `37241632923` on candidate `a8e763d1` failed the Critical gate with
**1 failed / 5006 passed**. The single failure was
`LegacyRme\VisitBoundPreverifiedIngestionTest > it shows the checker the attested
dates as read-only evidence` — not one of this sprint's suites, not one of the 8
pre-existing `FrontOfficeDevice` failures, and **green locally** in this sprint's
own whole-legacy run of the same filter.

**Cause.** The test compared `$operator->name` — a faker-generated value —
against **rendered HTML** with a raw `toContain`. CI drew `Nat D'Amore`; Blade
emits `&#039;`, so the raw string is not in the markup. This is the class
`CICD-BASELINE-REVERIFY-1` closed for `Oswaldo O'Kon`. **This sprint did not
break it:** adding test files shifted faker's sequence enough to expose it, which
is the mechanism that sprint documented.

**Fixed deterministically, not by re-running for a kinder seed.** The operator's
name is pinned to `Nat D'Amore` so the escaping path is exercised on *every* run
rather than on the minority of seeds that produce such a name, and the assertion
compares `e($operator->name)`. Reverting the `e()` now fails every run with the
exact CI message (mutation-verified; file restored byte-identical).

**The durable half — the guard that should have caught it.**
`FullSuiteBaselineContractTest` exists for exactly this shape and still missed
it: its regex `(?:getContent|content)\(\)\)?->toContain\(` matches only the
**chained** form, while this test assigns `getContent()` to `$html` and asserts
in a **later statement**. A new check scans variables genuinely assigned from a
response body. That scoping is deliberate — it keeps the sibling **negated** PII
assertions against audit `$payload`s out of scope, because JSON encoding does
not escape an apostrophe and wrapping those in `e()` would be wrong rather than
safer. Only **positive** calls are flagged; a negated body assertion has the
opposite failure mode (it can pass vacuously) and conflating the two would hide
one behind the other.

Reintroducing the raw comparison makes the new guard fail and name the offending
file and expression. A repo-wide sweep confirmed this was the **only** positive
HTML-body occurrence, so the fix is one assertion plus one detector rather than a
ten-file sweep.

---

### Pre-existing failure, NOT from this sprint

`tests/Feature/FrontOfficeDevice` fails **8 of 110** locally with `Undefined
array key "challenge"` — 7 in `FrontOfficeDeviceLoginCeremonyTest` and 1 in the
ceremony-scope suite.

**Proven pre-existing by comparing failing SETS, not counts.** The directory was
run on a clean checkout that does not contain this sprint at all (the module
directory is absent there): `8 failed, 102 passed` on that checkout and
`8 failed, 102 passed` on this branch, with byte-identical failing-name sets
(`diff` empty). This sprint modifies no file in that module.

**A refuted hypothesis, recorded rather than quietly dropped.** An earlier draft
of this section attributed the failures to the local `APP_URL` being
`http://127.0.0.1:8000` and the relying party refusing an insecure origin. That
was tested and is FALSE: overriding `APP_URL` to an `https` origin leaves all 7
failing. The options endpoint returns no challenge because `WEBAUTHN_RP_ID` and
`WEBAUTHN_ALLOWED_ORIGINS` are env-driven and unset locally, so
`WebAuthnRelyingParty` refuses to serve. The confirming run with those keys
supplied was blocked by a local permission prompt, so the cause is
**characterised but not confirmed**, and is stated that way instead of asserted.

---

## 12. CI wiring

The existing `LegacyPatient` token already selects all three new suites — but
**only because `LegacyPatientArchiveCompletenessTest` happens to contain
`LegacyPatient` as a contiguous substring.** That is coverage resting on a
filename coincidence, which is exactly the failure
`CI-MONITORING-CRITICAL-TOKEN-COVERAGE-1` exists to prevent.

So **no new token was added** (avoiding over-selection) and all three files are
declared in `config/ci_runner.php` → `critical_gate_mandatory_suites`, so the
coverage reconciliation fails loudly if the token is ever dropped or a file
renamed. Verified: 112 tests selected by the critical gate's own filter;
`CriticalGateSuiteCoverageTest` and `foundation:ci-runtime-control-check
--strict` both GO.

---

## 13. Sidebar

**Import Data Legacy → Kelengkapan Arsip Pasien Legacy**, last in the group: the
entries above are the work (upload, review, publish) and this one answers what is
still outstanding.

The group's `@canany` admits the new permission, so a read-only grantee is not
left with an invisible group — the gap the hub sprint had to fix for Admin Klinik.

It is deliberately **not** wrapped in a `migrationEnabled()` check like its
siblings: those guard intake, whose resting state is OFF, and a backlog monitor
that vanished whenever intake was closed would be unavailable exactly when an
operator wants to plan the next wave.

The route name `settings.legacy-patient-completeness.*` shares no prefix with any
sibling. A child of `settings.legacy-imports.*` would have highlighted **two**
menu entries at once — the same active-state collision the mass-upload sprint had
to avoid, caught here before shipping.

---

## 14. Files

**New** (`app/Modules/LegacyImport/Completeness/`): 5 Support classes
(`LegacyArchiveDocumentState`, `LegacyArchiveCompleteness`,
`LegacyCompletenessFilter`, `LegacyCompletenessQuery`, `LegacyPatientArchiveRow`,
`LegacyArchiveStatusLabel`), 1 interface, 1 repository, 1 service, 1 controller,
1 FormRequest; `resources/views/settings/legacy-patient-completeness/index.blade.php`;
3 test suites.

**Modified**: `routes/web.php`, `resources/views/layouts/partials/sidebar.blade.php`,
`database/seeders/PermissionSeeder.php`, `database/seeders/RoleSeeder.php`,
`app/Modules/AccessControl/Services/PermissionGroupingService.php`,
`app/Providers/RepositoryServiceProvider.php`, `config/ci_runner.php`,
and the four exact-list pin tests.

---

## 15. Deploy

**No migration.** Post-deploy, in this order:

```
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan permission:cache-reset
```

Permissions **before** roles: `RoleSeeder` uses `syncPermissions`, which throws
`PermissionDoesNotExist` for a permission that has never been seeded — the
documented ordering trap.

No feature flag to arm. The page is gated by `legacy_import_hub.enabled` (the
same operator-visible kill switch the sibling hub page uses, currently on) and by
the new permission.

**Verify read-only afterwards:** the route exists, the sidebar entry renders for
an authorized actor, a guest gets a login redirect, and an unauthorized role gets
403. Do not create a patient, an import or an archive record for evidence.

## 16. Security review, and the two findings it closed

An independent adversarial review booted the application against a throwaway
database and executed the real repository end-to-end against planted legacy,
native, branchless and soft-deleted patients with hostile payloads, rather than
reading the diff.

**CRITICAL 0 / HIGH 0 / MEDIUM 0 / LOW 2.** No exploit was constructed for any
of the claimed properties. Refuted by execution, not by argument: SQL injection
(every interpolated fragment traced to a literal or a private const; a hostile
`$filter` injected *directly into the value object*, bypassing both the
FormRequest and `normalize()`, never reaches the SQL because `$filter` is a
`match` subject and not a fragment), `SQLSTATE[HY093]` binding misalignment
(placeholders vs bindings across seven query shapes: 25/25, 21/21, 22/22,
20/20, 21/21, 23/23, 24/24), the `escape '!'` round trip, provenance bypass,
`?branch_id=` widening, fail-open on an unresolvable scope, counters escaping
the scope, PII disclosure **at the SQL layer** (the executed query selects 15
columns and `ktp_number` / `phone` / `address` / `date_of_birth` are not among
them, so the forbidden fields never leave the database), reflected XSS through
`$rows->appends()`, write/mutation, authorization layering, and unbounded reads.

Both LOW findings were the same shape — **a docblock asserting a control the
code did not actually have** — and both are now fixed in code rather than
documented away, because an inaccurate security claim is worse than none: it
tells the next author the check already exists.

### LOW-1 — the branch filter restated the scope instead of sharing it

The class claimed *"every public method above routes through here"*. It did
not: `branchOptions()` built its own query and re-stated the soft-delete,
provenance and branch predicates by hand. The divergence was **already real** —
`baseQuery()` honours `includeUnscopedBranch`, `branchOptions()` applied a bare
`whereIn` — so a governance actor saw a branchless legacy patient in the rows
with no matching branch option.

Fixed by extracting `scopedPatients()` as the one definition and having both
the listing and the branch filter consume it. The aggregates stay out of it, so
the branch filter does not pay for four joins it never reads, and the join to
`mst_branches` is INNER there, so a patient with no branch contributes no option
structurally rather than through a second predicate that has to be kept in step.

The direction of the old divergence was narrower, so nothing leaked. What it
cost was the guarantee. Now pinned by `it expresses the scope and the
provenance predicate exactly once`, which counts the predicates at the source —
because a row-set assertion cannot see a second copy that agrees today.

### LOW-2 — three public lookups trusted their caller's patient ids

`slotOccupyingRmeStatuses()`, `slotOccupyingOdontogramStatuses()` and
`reviewBlockedPatientIds()` are interface methods whose only predicate was
`whereIn('patient_id', $patientIds)` — no branch scope, no provenance — while
the interface promised *"every query here is unconditionally restricted"*. The
review demonstrated it by calling them directly with hand-picked ids and
getting another branch's staging status and reviewer holds back.

Not exploitable in this change set (one GET route, the controller passes no
ids, the sole caller derives them from the scoped paginator) — which is exactly
why the gap was invisible. Fixed by taking the resolved query and re-applying
the scope as a sub-select over `scopedPatients()`: **the ids narrow to a page,
the query authorises.** Pinned by `it refuses to read legacy pipeline state for
a patient outside the scope, even when handed their id`, with a non-vacuity
control proving the same ids DO resolve for an actor whose scope covers them,
and by a companion test for a natively registered patient.

### Mutation-verified

Reverting the LOW-2 fix (stripping the re-applied scope from both lookups)
fails both new scope tests. Reverting the LOW-1 fix (restating the predicates in
`branchOptions()` by hand) fails the predicate-count test. The repository was
restored by copy and verified byte-identical after each run, so no mutation
residue reached the commit.

### Informational notes, corrected for accuracy rather than behaviour

- **The `RmeWorkingBranchScope` rationale was partly moot.** The service argued
  the operational scope would make the backlog unreadable to an admin with no
  working context — but this route is not in
  `EnsureRmeOnlineContext::EXEMPT_ROUTE_NAMES` and that middleware is global on
  `web`, so a context-bound actor is redirected to the selector *before* the
  service runs. The outcome is more restrictive than the docblock implied, never
  less, and the real reason to borrow the hub's set stands: one definition of the
  archive's branch authority, not a wider one. Docblock corrected.
- **`summary()` applies the search filter**, so the KPI cards narrow with an
  active `?q=` while the docblock said they describe the actor's whole scope.
  The behaviour is deliberate — the search is a narrowing the operator chose and
  the cards follow it; only the status filter is excluded, because the cards are
  what the operator pivots between. Both docblocks corrected to say so.
- `registeredAt` and `patientId` sit on the row DTO without being rendered.
  Inside the agreed property set, so not a disclosure; noted because the DTO is
  the stated boundary.
