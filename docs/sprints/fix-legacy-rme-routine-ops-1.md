# FIX-LEGACY-RME-ROUTINE-OPS-1

**Routine batch operational surface, time-bounded governance, SOD staffing truth
and production closure**

| | |
|---|---|
| Branch | `feature/fix-legacy-rme-routine-ops-1` |
| Base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `b8d7053` |
| Type | `RUNTIME_FIX` (module `LegacyRme`) |
| Migration | **none** |
| New permission / role / route | **none** |
| Full Suite | **skipped by explicit user decision** — execution count **0** |
| GO tag | **none created.** `fix-legacy-rme-routine-ops-1-go` is reserved for a follow-up closure sprint that runs the Full Suite |
| Closing posture | **WATCH — PENDING FINAL FULL SUITE CLOSURE** |

---

## 1. The incident

An operator set out to open a routine batch for Telkomas:

```
ROUTINE-20260819-TKM1-01
Cabang: TKM1 / Telkomas
Quota:  25
```

The deployment approval was prepared and the batch registered — from the
terminal, beside the admission config, which is where the first step of a
routine batch happens on a production VPS. It registered as:

```
status              = DRAFT
planned_start_date  = null
planned_end_date    = null
```

`legacy-rme:ops-readiness` then returned:

```
WATCH  batch_binding
WATCH  batch_window
       "The batch declares no planned end date, so its approval has no expiry."
```

The routine batch runbook has always required a *planned start and end date*
and a *quota and window set on the batch record*. Nothing enforced it, and the
one entry point the operator was using could not express it.

The batch was cancelled canonically and the ledger closed clean:

```
ROUTINE-20260819-TKM1-01   status=CANCELLED
accepted=0  published=0  in_flight=0  failed=0  unexplained=0  quota_drift=0
```

That record is **immutable audit evidence**. It is never deleted and its code is
never reused.

---

## 2. Root causes

### A. The CLI could not express a batch window — **CONFIRMED, FIXED**

`LegacyRmeWaveGovernanceService::createWave()` has accepted planned dates all
along:

```php
public function createWave(
    User $actor, string $code, string $name, array $branchCodes,
    ?int $dailyQuota = null, ?int $perBranchDailyQuota = null,
    ?string $plannedStartDate = null,   // ← already here
    ?string $plannedEndDate = null,     // ← already here
): LegacyRmeMigrationWave
```

The model, the columns and the HTTP controller all carried them. The CLI did
not. `LegacyRmeWaveAdminCommand::register()` passed **six** named arguments and
stopped:

```php
$wave = $governance->createWave(
    actor: $actor, code: ..., name: ..., branchCodes: $codes,
    dailyQuota: ..., perBranchDailyQuota: ...,
);   // plannedStartDate / plannedEndDate fell back to null on every CLI run
```

and the `$signature` had no `--planned-start-date` / `--planned-end-date`. The
only "planned" option was `--planned-documents`, a count consumed by
`branchQuota()`.

### B. The form never offered the fields — **CONFIRMED, FIXED**

`StoreLegacyRmeMigrationWaveRequest` already validated them:

```php
'planned_start_date' => ['nullable', 'date'],
'planned_end_date'   => ['nullable', 'date', 'after_or_equal:planned_start_date'],
```

and `LegacyRmeMigrationOperationsController` already forwarded them. But
`resources/views/settings/rme/migration-operations/index.blade.php` collected
only `code`, `name`, `branch_codes[]`, `daily_quota`,
`per_branch_daily_quota`. So the browser could not set a window either — only a
hand-built POST could.

**The two official surfaces had different gaps**, which is why the invariant
survived so long: whichever one you looked at, the other appeared to be the
complete story.

### C. Separation of duties reported GO without checking staffing — **CONFIRMED, FIXED**

`checkSeparationOfDuties()` read two config booleans and nothing else:

```php
$separatePublisher = $this->separation->enabled();               // config
$separateApprover  = (bool) config('...require_separate_approver'); // config
// both on → LegacyRmeRolloutCheck::go(...)
```

No user, role, account or database query of any kind. A deployment can enforce
approver-is-not-creator while having no second account able to approve
anything; the rule then blocks every approval instead of separating two people,
and readiness still says GO. That is not a wrong answer to the question it
asked — it is the wrong question.

### C′. The briefed RBAC drift — **DOES NOT REPRODUCE**

The sprint brief recorded production user 11 (`Supervisor RME`) as
`wave approve = NO` and treated it as provisioning drift to repair.

**It is not drift. Production is already canonical.** Measured live, read-only:

```
USER 11  roles=[Supervisor RME]
    view_legacy_rme_migration_operations   = YES
    manage_legacy_rme_migration_operations = NO      ← correctly withheld
    approve_legacy_rme_migration_wave      = YES     ← the briefed "NO"
    create_legacy_rme_imports              = NO      ← correctly withheld
    review_legacy_rme_imports              = YES
    publish_legacy_rme_imports             = YES

ROLE Supervisor RME  legacy=[approve_legacy_rme_migration_wave,
                             publish_legacy_rme_imports, review_legacy_rme_imports,
                             view_legacy_rme_imports, view_legacy_rme_migration_operations]
ROLE Admin Klinik    legacy=[create_legacy_rme_imports, view_legacy_rme_imports]
PERMISSIONS_NOT_REGISTERED=[]
```

That is exactly the target role model. `RoleSeeder` already grants
`approve_legacy_rme_migration_wave` to Supervisor RME, and production matches
it.

The briefed observation was a **measurement artifact**, and it reproduces two
independent ways — both of which the sprint brief itself warned about:

```
A. effective can(approve_legacy_rme_migration_wave)          = YES
B. DIRECT permission rows for user 11                        = 0
      → a direct-only whereHas('permissions') audit reports NO
C. wave ROUTINE-20260819-TKM1-01  status=CANCELLED terminal=YES
   policy approve() against THAT wave                        = false
      → a policy probe reports NO
E. policy approve() against a NON-TERMINAL wave (in-memory)  = true
F. non-terminal waves currently in production                = 0
      → right now ANY policy probe returns false, for everyone
```

`LegacyRmeMigrationWavePolicy::approve()` is
`can('approve_legacy_rme_migration_wave') && ! $wave->isTerminal()`. With the
only batch cancelled and no non-terminal batch in existence, the policy answers
"no" to every account regardless of permission.

**No RBAC repair was required and none was performed.** `RoleSeeder` was not
run as a repair; there was no delta to converge. Fabricating a fix for a
non-existent defect would have been the wrong outcome, and running
`syncPermissions` unnecessarily carries real risk — it *removes* role
permissions absent from the seeder array.

What this sprint delivers for C is durable instead: readiness now asks the
right question, and `LegacyRmeSodStaffing` asks it using effective `can()`
rather than a direct-only query, and deliberately **not** through the wave
policy. The misdiagnosis class that produced the briefed finding is now
designed out.

### C″. Why no RBAC reconciliation runs at deploy

`scripts/deploy-vps.sh` contains **no seeding at all** — verified. Every sprint
that added a permission has required a manual post-deploy
`db:seed --class=PermissionSeeder|RoleSeeder --force`, as CLAUDE.md records for
ENT-7, LAB-PROD-2/3 and the SATUSEHAT series.

Adding `RoleSeeder` to every deploy was considered and **deliberately rejected**:

- `syncPermissions` is *destructive to omitted permissions*. Making it run
  unattended on every deploy turns any hand-granted role permission into a
  silent revocation at the next unrelated deploy.
- `scripts/deploy-vps.sh` is scanned by the ENT-10, ENT-11 and ENT-12 governance
  gates against required markers; changing it is high blast radius for a sprint
  whose brief mandates minimal diff.
- The failure mode is *detection*, not *application*. Production was in fact
  correct; what was missing was anything that would have told us either way.

So the durable control is the readiness check, which now fails closed on exactly
this class of gap. This is recorded as a deliberate trade-off, not an oversight.

---

## 3. Graphify findings

The checked-in graph was stale — built at `b18188c`, a LEGACY-RME-PDF-1A-era
commit containing no wave, migration-operations or steady-state files at all
(234 LegacyRme nodes, none of them the wave subsystem). A full rebuild needs an
LLM key for 659 doc files; a **code-only** rebuild over `app/` needs none and
was used instead: **10,373 nodes / 22,124 edges**.

The decisive result:

```
path LegacyRmeWaveAdminCommand → LegacyRmeWaveGovernanceService
  LegacyRmeWaveAdminCommand.php --imports--> LegacyRmeWaveGovernanceService

path LegacyRmeMigrationOperationsController → LegacyRmeWaveGovernanceService
  LegacyRmeMigrationOperationsController.php --imports--> LegacyRmeWaveGovernanceService
```

Both callers reach the service **directly**, and only one of them passes through
a FormRequest. That is the structural argument for putting the window rule in
`createWave()`: it is the single node both paths traverse, so one implementation
binds both and no third caller can appear underneath it.

The codebase had already written this argument down, in the wave FormRequest's
own docblock:

> the branch set is … AUTHORIZED in LegacyRmeWaveGovernanceService … Validating
> membership in a FormRequest would put an authorization decision in the request
> layer, **where a second caller (the CLI) would bypass it entirely.**

The dates were the case that had been left behind.

---

## 4. Before / after

Form heuristic (dataviz): the data's job here is **state across dimensions**,
not magnitude or change over time — so the correct form is a status-coded
comparison matrix, not a chart. Status vocabulary is the codebase's own
(`GO` / `WATCH` / `FAIL`), not an invented palette. No visualization library is
introduced anywhere near production.

| Dimension | Before | After |
|---|---|---|
| CLI planned window | ✗ not expressible — no option exists | ✓ `--planned-start-date` / `--planned-end-date` |
| UI planned window | ✗ hidden — request validated it, form never asked | ✓ two `type="date"` fields, Indonesian labels |
| Where the rule lives | request layer only (CLI bypasses) | `createWave()` — one rule, both callers |
| Missing window | accepted → unbounded approval, `WATCH` after the fact | **FAIL CLOSED** at registration, nothing written |
| Reversed window | accepted from CLI | refused from both callers |
| Impossible date (`2026-02-31`) | rolled silently into March | refused |
| SOD config | enforced (both switches on) | enforced (unchanged) |
| SOD staffing | **never checked** | counted; distinct-pair required |
| SOD verdict when unstaffable | `GO` — misleading | `FAIL` + `SOD_STAFFING_UNAVAILABLE` (INCIDENT, stop-the-line) |
| "Two different humans" claim | correctly disclaimed | correctly disclaimed (**unchanged**) |
| Historical batches | n/a | untouched, unbackfilled, readable |

---

## 5. What changed

**New**

- `app/Modules/LegacyRme/Support/LegacyRmeBatchWindowRule.php` — the shared,
  caller-agnostic window invariant. Strict `YYYY-MM-DD`, calendar-real dates,
  inclusive end, `end >= start`, presence required by policy. Parses in the
  clinical timezone; deliberately does **not** compare against today (a batch
  may be registered ahead of its start; a lapsed window is the `batch_window`
  readiness finding, not a reason to refuse the write).
- `app/Modules/LegacyRme/Support/LegacyRmeSodStaffing.php` — can each duty
  actually be performed? Capability via `can()` (honours direct grants, role
  grants and the single global `Gate::before` Super Admin bypass); candidates
  narrowed to permission holders plus Super Admins so the query stays bounded;
  inactive and soft-deleted accounts excluded; counts and booleans only.
  The document chain is **file → review → publish**, not file → publish:
  `SeparatePublisherGuard::GUARDED_ACTIONS` is `[REVIEW, PUBLISH]` and
  `LegacyRmeImportStatus::TRANSITIONS` only permits
  `READY_FOR_REVIEW → REVIEWED → PUBLISHED`, so an import nobody else can
  review can never be published by anyone. `document_chain_staffed` requires a
  distinct maker/reviewer pair **and** a distinct maker/publisher pair.

**Changed**

- `LegacyRmeWaveGovernanceService::createWave()` — asserts the window before the
  transaction and persists the normalised strings.
- `LegacyRmeWaveAdminCommand` — two new options, passed through; the dry run
  reports the intended window and `batch_window_required` and still writes
  nothing.
- `LegacyRmeSteadyStateOpsService::checkSeparationOfDuties()` — staffing-aware.
  Precedence preserved: publisher-disabled is still reported as
  publisher-disabled, and the approver-off accepted risk is still a `WATCH`.
- `resources/views/settings/rme/migration-operations/index.blade.php` — two date
  fields with `old()` preservation and inline error display.
- `config/legacy_rme_operations.php` — `routine_batch_window.required` (default
  **true**), `sod_staffing.max_accounts_scanned`.
- `config/legacy_rme_steady_state.php` — `SOD_STAFFING_UNAVAILABLE` registered
  in `stop_the_line` and `severity.incident_codes`.

**Not changed, deliberately:** no migration, no permission, no role definition,
no policy, no route, no seeder, `scripts/deploy-vps.sh`, and every other
readiness check.

### CLI before / after

```bash
# BEFORE — the incident. Registers an unbounded batch; readiness WATCHes later.
php artisan legacy-rme:wave-admin register \
  --wave="ROUTINE-..." --branches="TKM1" --daily-quota=25 --actor="..." --apply

# AFTER — refused, nothing written.
#   "Tanggal mulai batch wajib diisi. Batch rutin harus dibatasi waktu."

# AFTER — the canonical routine registration.
php artisan legacy-rme:wave-admin register \
  --wave="ROUTINE-YYYYMMDD-TKM1-NN" \
  --name="..." --branches="TKM1" \
  --daily-quota=25 --per-branch-daily-quota=25 \
  --planned-start-date="2026-08-19" \
  --planned-end-date="2026-08-19" \
  --actor="..." --apply
```

---

## 6. Security & architecture review

- **Layering.** Rule in the Service, on the path both callers traverse.
  Controller stays thin; the FormRequest keeps its field-scoped `date` /
  `after_or_equal` rules as early feedback while the service remains the
  authority. Adding form fields widened no authorization — pinned by a test
  that an approve-only account still gets 403 on registration.
- **Fail-closed.** Missing, reversed, malformed or non-calendar windows are
  refused before the transaction; nothing is written. Unstaffable SOD is `FAIL`
  → `NO_GO`. A staffing query that throws is caught by the existing `guarded()`
  wrapper → `UNKNOWN` → `NO_GO`.
- **Privacy.** Staffing is counts and booleans; a test asserts the encoded
  report contains neither a name nor an email. No KTP/NIK, no clinical content.
- **Clinical invariants untouched.** No native `ClinicVisit`, `MedicalRecord`,
  `Invoice`, `Payment`, `Odontogram`, `Prescription`, `LabOrder` or SATUSEHAT
  record is created or mutated. Published legacy history stays readable with the
  migration flag OFF. RM-derived branch remains the authority.
- **No hardcoding.** No production user or branch id appears in domain logic.
  `TKM1` appears only in test fixtures. The rules generalise to TKM1, LDK2,
  ATG3, SUN4 and any future RME-enabled branch.
- **Immutability.** Creation-time rule only. No historical wave is edited or
  backfilled; `ROUTINE-20260819-TKM1-01` is untouched.

---

### Defects found by adversarial review and fixed before merge

Four review lenses ran over the committed diff. Six real defects surfaced; all
are fixed and pinned by tests. Two of them were in the staffing check itself —
the part of this sprint whose entire purpose is to stop reporting a false GO.

| Severity | Defect | Fix |
|---|---|---|
| **HIGH** | Staffing modelled the document pair as file→publish, omitting `review_legacy_rme_imports` — so a deployment where nobody but the maker can review reported `GO` while being unable to complete a single document. Two lenses found this independently. | reviewer counted as part of the chain |
| **MEDIUM/HIGH** | The first fix corrected *which* permissions are counted but not *how* they combine: ANDing two independent pair tests lets a **different** maker satisfy each. `A = create+publish`, `B = create+review` passes both pairs, yet A's documents stall at `REVIEWED` (only A can publish, and A uploaded) and B's stall at `READY_FOR_REVIEW` (only B can review, and B uploaded). Nothing is publishable and readiness said `GO`. | `chainStaffed()` requires **one** maker with a reviewer other than them *and* a publisher other than them |
| LOW | `parse()` resolved the clinical timezone *inside* its `catch (Throwable)`, so a misconfigured `CLINICAL_TIMEZONE` was reported as "your date is malformed" — sending the operator to fix the one thing that was correct. `ClinicalClock` is contractually fail-loud. | timezone resolved outside the try; `InvalidClinicalTimezoneException` propagates |
| LOW | `0000-01-01` survives the strict round trip (PHP represents year zero happily) and PostgreSQL then rejects it at `INSERT` as an unhandled `QueryException` — a 500 where a field error belongs. | year `< 1` refused as a malformed window |
| LOW | `(bool) env(...)` — the pattern this codebase already documents as unsafe: a present-but-empty `LEGACY_RME_ROUTINE_BATCH_WINDOW_REQUIRED=` casts to `false` and silently switches the invariant off. | reuses the existing fail-safe `SeparatePublisherGuard::resolveEnabledFromEnv()` |
| LOW | the form hardcoded `required`, so `routine_batch_window.required = false` was honoured by the service and CLI but not the browser | `required` now follows the policy, passed from the controller |

## 7. Tests

New:

- `tests/Feature/LegacyRme/LegacyRmeRoutineBatchWindowTest.php` — **25**.
  Rule semantics (canonical strings, inclusive single-day window, calendar-real
  dates, format strictness, ordering, optional-mode); service refusals and the
  no-partial-write property; CLI options present, persisted, refused for
  missing / reversed / impossible windows, dry-run reports without writing,
  other CLI actions unaffected; HTTP form renders both fields, persists a valid
  window, rejects missing and reversed windows on the right field, and
  authorization is unchanged; historical null-date waves stay readable and
  unbackfilled.
- `tests/Feature/LegacyRme/LegacyRmeSodStaffingReadinessTest.php` — **14**.
  No accounts → no pair; a lone Super Admin is **not** a separated pair;
  the bypass counts as real capability once a second account exists; inactive
  and soft-deleted accounts excluded; production topology recognised; readiness
  `FAIL` + `SOD_STAFFING_UNAVAILABLE` when the approver or the publisher pair
  cannot be staffed; `GO` when both are; precedence preserved for
  publisher-disabled and approver-off; the human-vs-account claims stay
  distinct; report carries no identities; staffing is not decided from a
  terminal wave.

Updated:

- `LegacyRmeWaveSeparationOfDutiesTest` — the wave fixture declares a window
  like every real caller now must.
- `LegacyRmeSteadyStateOpsTest` — `beforeEach` staffs SOD so each test asserts
  the one condition it is about.
- `tests/Pest.php` — `legacyRmeStaffSeparationOfDuties()`, mirroring the real
  production topology (governance manager, branch intake maker, separate
  checker).

`SupervisorRmeRolePermissionTest` needed **no repin**: `RoleSeeder` is unchanged
because it was already canonical.

Existing `batch_window` coverage in `LegacyRmeSteadyStateOpsTest` (null end date
→ `WATCH`; final planned day still inside the window → `GO`; expired → `WATCH`)
already pins the readiness half and was left as-is.

---

## 8. Full Suite — skipped by explicit user decision

**`FULL_SUITE_STATUS = SKIPPED_BY_EXPLICIT_USER_DECISION`**
**`FULL_SUITE_EXECUTION_COUNT = 0`**

The Full Suite was **not** run for this sprint. That is a deliberate decision,
not an omission and not a failure — and it is why this sprint carries **no
engineering GO tag**. Claiming a pass we never obtained would be the one
outcome worse than not running it.

Audited history, so the record is complete rather than merely asserted:

| CI run | SHA | Event | Full Suite job |
|---|---|---|---|
| `32198344406` | `46f717e` | `pull_request` | job created, `conclusion=cancelled`, `startedAt == completedAt` (zero duration). Collateral of the run being superseded by a newer push; it never became runnable, because it `needs` the critical gates and those were still in flight. On a `pull_request` event its `if` is false regardless, so it would have been skipped. **No test executed.** |
| `32199492319` | `a08a79f` | `pull_request` | skipped by the workflow's own condition |

No Full Suite was dispatched, and no CI file was modified to suppress one. The
gate fires only on `schedule`, on `workflow_dispatch` with
`run_full_suite=true`, or on a push to the base branch — so a pull request
skips it through the workflow's official path, with nothing hidden.

### What a follow-up closure sprint has to do

Everything else in this sprint is finished and evidenced, so the remaining work
is narrow:

1. Re-verify the baseline on the merged SHA (targeted suites below).
2. Run **one** authoritative Full Suite; expected failures **0**.
3. Make the final GO decision on that result.
4. Create the tag `fix-legacy-rme-routine-ops-1-go` — nothing here has created
   or moved it.

## 9. Evidence

### Targeted regression — authoritative run

`php artisan test tests/Feature/LegacyRme tests/Feature/AccessControl tests/Feature/Auth`
on the final tree, **run in isolation**:

```
Tests:  1010 passed, 5 skipped, 0 failed  (3128 assertions)
```

The 5 skips are named, not swallowed: all are guarded on the GD extension
(dompdf image decoding) which this dev machine's PHP lacks. CI installs
`gd, exif, poppler-utils`, so they execute there.

Every mandated suite ran and passed:

| Suite | |
|---|---|
| `LegacyRmeWaveSeparationOfDutiesTest` | PASS |
| `LegacyRmeWaveApprovalUiReachabilityTest` | PASS |
| `LegacyRmeSteadyStateOpsTest` | PASS |
| `LegacyRmeMigrationOperationsGateTest` | PASS |
| `LegacyRmeImportAuthorizationTest` | PASS |
| `LegacyRmeMigrationReconciliationTest` | PASS |
| `LegacyRmeImportLifecycleCliTest` | PASS |
| `SupervisorRmeRolePermissionTest` | PASS |
| `LegacyRmeProgramClosureContractTest` | PASS |
| `LegacyRmeRoutineBatchWindowTest` (new) | PASS |
| `LegacyRmeSodStaffingReadinessTest` (new) | PASS |

Also clean: `pint --dirty --test`, `git diff --check`,
`sprint:manifest-check`, `sprint:scope-audit`, `foundation:devflow-check`,
`foundation:shared-service-audit`, `foundation:ci-runtime-control-check`,
`foundation:security-compliance-check`.

### A test-infrastructure trap worth keeping

Earlier whole-directory runs showed "file not found in storage" failures
(`SOURCE_FILE_MISSING`, *"Berkas sumber/gambar halaman tidak ditemukan pada
penyimpanan"*) hitting a **different, innocent test each run**. The cause is not
the code and not test ordering — no randomisation is configured. It is **two
concurrent `php artisan test` processes in the same worktree**: `Storage::fake()`
DELETES `storage/framework/testing/disks/...` when it runs, so whichever process
fakes first destroys the other's files.

Diagnosed by process inspection, not by guessing: a review subagent was running
the suite alongside the main run. Isolated, the same command is clean.

Before diagnosing any LegacyRme storage failure, confirm the suite is running
alone. Note that the obvious check self-matches — a shell whose own command line
contains the pattern is counted by `grep`, which is how a naive
`until [ "$(ps -eo cmd | grep -c pest)" -le 1 ]` guard deadlocks against itself.

---

## 10. Rollback

Independent axes:

- **Code** — revert the merge, or `scripts/rollback-vps.sh <previous GO tag>`.
  Nothing in this sprint has a schema or data component, so code rollback is
  complete on its own.
- **RBAC** — nothing was changed; nothing to roll back.
- **Config** — `LEGACY_RME_ROUTINE_BATCH_WINDOW_REQUIRED=false` restores the old
  permissive registration behaviour without a deploy, if a batch must be opened
  before a code rollback lands. It is a documented, deliberate weakening.
- **Feature flag / admission** — untouched; the safe resting target remains
  capability **OFF**, admission **EMPTY**, active batch **NONE**.

No destructive database rollback is ever performed for historical wave records.
`ROUTINE-20260819-TKM1-01` is never deleted.

---

## 11. Next operational action

After GO, a routine TKM1 batch may be opened as a **new** batch with a fresh
approval and a bounded window. `ROUTINE-20260819-TKM1-01` is never reused.
Opening it is a separate operator action requiring its own authorization, and is
not part of this engineering sprint.
