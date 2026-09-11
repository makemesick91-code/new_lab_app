# MERGED PLAN — DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1

Integrator output. Seven parallel design slices, 106 planned files, reconciled into one buildable plan.

Worktree (read-only reference): `/home/fikri/Projects/doctor-access-single-session-branch-lock-1` @ `215d277d`.
Spec detail lives in `scratchpad/spec/*.md`. **This document does not restate file bodies.** It records the
canonical choices, the merged content of every contested file, and the order to build in. For any file body,
open the spec section named in section 4.

Slice short names used throughout:

| short name | file |
|---|---|
| MIGRATIONS | `spec/migrations-and-models-for-doctor-access.md` |
| LEASE | `spec/single-active-session-lease-engine-docto.md` |
| HOMELOCK | `spec/home-lock-temporary-branch-cover-and-eff.md` |
| APPROVAL | `spec/approval-workflows-for-the-doctor-branch.md` |
| FLAGS | `spec/flags-manifest-seeders-permission-groupi.md` |
| O4 | `spec/o4-bulk-doctor-device-authorization-comm.md` |
| TESTS | `spec/slice-the-complete-test-plan-for-doctor.md` |

---

## 1. Canonical decisions

### 1.1 Module namespace — `App\Modules\DoctorAccess`

**Every new class in this sprint lives under `app/Modules/DoctorAccess/`**, in the house subdirectory shape
`{Console,Controllers,Interfaces,Listeners,Middleware,Models,Policies,Repositories,Requests,Services,Support}`.

`App\Modules\Doctor` (23 claimed files) and `App\Modules\DoctorBranchLock` (8) are both dropped as sprint
namespaces.

I verified the deciding constraints in the worktree rather than trusting the slices:

1. **The route-middleware scan forbids `DoctorDevice`.**
   `tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php:246-278` walks every registered route's
   `gatherMiddleware()`, skips only URIs starting `device-api`, and asserts that any middleware string
   containing `DoctorDevice`, `device.proof` or `trusted.device` **equals `EnsureDoctorDeviceSession::class`**.
   A globally appended `App\Modules\DoctorDevice\Middleware\EnsureDoctorSessionLease` collides on the
   *namespace segment*, so renaming the class does not save it. `DoctorAccess` contains none of the three
   substrings. Confirms LEASE's finding G1.
2. **Three source scanners read whole `DoctorDevice` identifiers against a 4-symbol allow list**
   (`DoctorDevice`, `DoctorAppLoginGate`, `DoctorDeviceSessionService`, `EnsureDoctorDeviceSession`):
   - `tests/Feature/DoctorDevice/DoctorDeviceAccessTest.php:210-244` — globs `app/Http/Controllers/Auth/*.php`,
     `app/Http/Middleware/*.php`, `app/Services/Auth/*.php`, **plus `bootstrap/app.php`** and
     `app/Http/Requests/Auth/LoginRequest.php`; regex `/[A-Za-z_]*DoctorDevice[A-Za-z_]*/`.
   - `tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php:199-236` — same globs without
     `bootstrap/app.php`.
   - `app/Support/Android/AndroidReleaseGovernanceScanner.php:1978`, driven by
     `config/android_release.php:1855-1902` — the same regex over `enforcement_coupling_surfaces`
     (`app/Http/Controllers/Auth`, `app/Http/Middleware`, `app/Services/Auth`,
     `app/Modules/ClinicVisit/Middleware`, `bootstrap/app.php`) plus
     `enforcement_coupling_watch_surfaces` (`app/Modules/DoctorDevice/Middleware`,
     `app/Modules/DoctorDevice/Listeners` — absent today, scanned the moment they appear).

   Because backslash is outside `[A-Za-z_]`, a `use App\Modules\DoctorAccess\Middleware\EnsureDoctorSessionLease;`
   line in `bootstrap/app.php` yields **no match at all** — it introduces no `DoctorDevice` symbol and no new
   allow-list entry is needed. `App\Modules\Doctor\...` would also be clean here; `App\Modules\DoctorDevice\...`
   would not (the Listeners/Middleware watch surfaces would begin being scanned, and every unsanctioned symbol
   in the new files would FAIL).

3. **Tie-break between `Doctor` and `DoctorAccess`.** Both pass the scanners, so the decision is bounded-context
   hygiene, and the codebase's own precedent settles it: device trust was **not** put in `App\Modules\Doctor`,
   it got its own `App\Modules\DoctorDevice`. `App\Modules\Doctor` is the doctor **master-data** context —
   `DoctorService::canonicalWritePayload()`, `DoctorIdentityResolver`, and critically
   `DoctorClinicalBranchResolver`, which owner decision **O3 requires to stay untouched**. Dropping a
   `DoctorEffectiveBranchResolver` into the same directory as a `DoctorClinicalBranchResolver` that must behave
   *oppositely* (one narrows to the effective branch, one deliberately stays practice-set-wide) is a real
   maintenance hazard, not a stylistic one. `DoctorBranchLock` is rejected for the opposite reason: it is too
   narrow to host the session lease, which is not a branch lock, and would force a second new module.

**Consequences to apply mechanically:** every FQCN in APPROVAL (`App\Modules\Doctor\...`), HOMELOCK
(`App\Modules\DoctorBranchLock\...`), MIGRATIONS (`App\Modules\Doctor\Models\...`,
`App\Modules\DoctorDevice\Models\DoctorSessionLease`) is rewritten to `App\Modules\DoctorAccess\...`.
The MIGRATIONS slice's own risk note — *"do not leave two lease models"* — is honoured: there is exactly one,
`App\Modules\DoctorAccess\Models\DoctorSessionLease`.

### 1.2 Migration filenames, in order

MIGRATIONS wins outright. Highest existing migration in the worktree is
`2026_09_08_100002_create_trx_doctor_device_webauthn_credentials_table.php` (verified by `ls | sort | tail`), so
the `2026_09_10_1000xx` slot sorts after everything.

1. `database/migrations/2026_09_10_100001_create_mst_doctor_branch_locks_table.php`
2. `database/migrations/2026_09_10_100002_create_trx_doctor_branch_lock_requests_table.php`
3. `database/migrations/2026_09_10_100003_create_trx_doctor_branch_covers_table.php`
4. `database/migrations/2026_09_10_100004_create_trx_doctor_session_leases_table.php`

The order is **load-bearing, not cosmetic**: `trx_doctor_session_leases.effective_cover_id` is an FK onto
`trx_doctor_branch_covers`, so LEASE's `100001 = leases` and HOMELOCK's `100002 = covers` would break
`migrate` on a fresh database. Bodies: MIGRATIONS §`NEW .../2026_09_10_1000{01,02,03,04}_*` (lines 53, 174,
324, 525).

Four table names, final: `mst_doctor_branch_locks`, `trx_doctor_branch_lock_requests`,
`trx_doctor_branch_covers`, `trx_doctor_session_leases`.

### 1.3 Test locations

**One new directory: `tests/Feature/DoctorAccess/`.** The `tests/Feature/DoctorBranchLock/` (3 files) and
`tests/Feature/AccessControl/` (2 new files) proposals are folded into it. The two O4 suites stay in
`tests/Feature/DoctorDevice/`, where the existing `DoctorDevice` token already selects them.

Final new-suite list (11 files):

| path | source slice | absorbs |
|---|---|---|
| `tests/Feature/DoctorAccess/helpers.php` | TESTS | — |
| `tests/Feature/DoctorAccess/DoctorSingleSessionLeaseTest.php` | TESTS | LEASE's `DoctorDevice/DoctorSessionLeaseTest.php` |
| `tests/Feature/DoctorAccess/DoctorMultiDeviceAccessTest.php` | TESTS | — |
| `tests/Feature/DoctorAccess/DoctorHomeBranchLockTest.php` | TESTS | HOMELOCK's `AccessControl/DoctorBranchLockOperationalScopeTest.php` |
| `tests/Feature/DoctorAccess/DoctorEffectiveBranchResolverTest.php` | HOMELOCK (moved) | — |
| `tests/Feature/DoctorAccess/DoctorBranchLockApprovalTest.php` | APPROVAL (moved) | TESTS' `DoctorBranchTransferTest.php` |
| `tests/Feature/DoctorAccess/DoctorBranchCoverApprovalTest.php` | APPROVAL (moved) | TESTS' `DoctorBranchCoverTest.php` |
| `tests/Feature/DoctorAccess/DoctorBranchLockAccessTest.php` | APPROVAL (moved) | — |
| `tests/Feature/DoctorAccess/DoctorAccessGovernanceTest.php` | TESTS | — |
| `tests/Feature/DoctorAccess/DoctorAccessConcurrencyTest.php` | TESTS | — |
| `tests/Feature/DoctorAccess/DoctorAccessFeatureFlagContractTest.php` | FLAGS | — |
| `tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationTest.php` | O4 | TESTS' `DoctorAccess/DoctorDeviceBulkAuthorizationTest.php` |
| `tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationNonEscalationTest.php` | O4 | — |

`helpers.php` is loaded by `require_once __DIR__.'/helpers.php';` at the top of each suite — the verified house
convention (`tests/Feature/Satusehat/`, `tests/Feature/AccessControl/`, `tests/Feature/LegacyImportHub/`,
`tests/Feature/LegacyOdontogram/`). It is **not** registered in `tests/Pest.php`. `seedAccessControl()` is
already global (`tests/Pest.php:99`), so moving suites out of `tests/Feature/AccessControl/` costs nothing.

Six existing suites are repinned in place (TESTS §EDIT, lines 363-444; APPROVAL §EDIT SupervisorRme):
`tests/Feature/AccessControl/DailyBranchContextLockTest.php`,
`tests/Feature/MasterData/DoctorRmeBranchSourceTest.php`,
`tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php`,
`tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnTest.php`,
`tests/Feature/Auth/SupervisorRmeRolePermissionTest.php`.

### 1.4 CI tokens

**Exactly one new token: `DoctorAccess`.** Inserted into **both** critical-filter variants, immediately after
`|DoctorDevice`:

- `.github/workflows/foundation-evidence-gates.yml:422` — job `critical_test_gate`, step *Run critical
  regression tests*.
- `.github/workflows/foundation-evidence-gates.yml:690` — job `critical_test_gate_self_hosted`, same filter
  behind `bash scripts/ci/self-hosted-php.sh`.

The two strings must stay byte-identical: `tests/Feature/Cicd/DedicatedSelfHostedRunnerTest.php:173-192`
asserts `expect($selfHosted)->toBe($hosted)`, and that suite is itself selected by the required `Cicd` token.

Verified mechanics: `app/Support/Cicd/SelfHostedRunnerScanner.php:520-541` derives the filter identity from the
**path** — `tests/Feature/DoctorAccess/X.php` → `Tests\Feature\DoctorAccess\X` — and the match at `:487` is a
case-insensitive `stripos`. So one directory-segment token selects every file in the directory. I checked every
existing token against the new identities: `DoctorDevice`, `DoctorAccountLink`, `DoctorRoomScoped`,
`DoctorPwaWebAuthn`, `Pwa`, `DailyBranchContext`, `BranchChange` match none of them.

**The registry reconciliation is AND, not OR** (`SelfHostedRunnerScanner.php:470-500`): a path declared in
`config/ci_runner.critical_gate_mandatory_suites` that no token selects emits
`critical gate filter #{i} does not select mandatory suite '{path}'`, and a declared path that does not exist
emits `mandatory critical suite '{path}' does not exist — the registry is stale`. `self_hosted_heavy_jobs` has
one entry, so `$variants = 2` and every declared suite must be selected by **both** filters. Registry entries
and files therefore land in the same commit.

No `DoctorBranchLock` or `DoctorBranchCover` token is added — folding those suites into
`tests/Feature/DoctorAccess/` makes them unnecessary, which removes APPROVAL's own stated risk that the second
token gets forgotten.

`critical_gate_required_filters` (`config/ci_runner.php:139-141`, currently `['Cicd']`) gains `'DoctorAccess'`.
Verified safe: `grep -rn critical_gate_required_filters app/ tests/` returns exactly one hit,
`SelfHostedRunnerScanner.php:331` — **no test pins that array to an exact set**, so this is an append, not a
repin. It converts "the token was silently deleted from one variant" from a slow discovery into a gate failure.

### 1.5 Feature flags

Two new registry entries, both `default => false`, `risk_level => 'critical'`:

- `doctor.single_active_session` — env `FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION`, `dependencies => []`.
- `doctor.branch_lock` — env `FEATURE_DOCTOR_BRANCH_LOCK`, `dependencies => ['doctor.single_active_session']`.

Both read only through `FeatureFlagService::enabled()`. Neither file may contain the literal
`doctor.trusted_device_enforcement` (brief D7; the exact-readers pin walks `app/`, `routes/`, `bootstrap/`).

### 1.6 Permissions

Four, plural, matching the existing doctor family (`view_doctor_devices`,
`manage_doctor_device_authorizations`):

`view_doctor_branch_locks` · `manage_doctor_branch_locks` (file) · `approve_doctor_branch_locks` (decide) ·
`release_doctor_session_leases`.

Grantees: **Supervisor RME gets `view_`, `approve_`, `release_` and deliberately NOT `manage_`** (maker/checker).
Super Admin reaches all four through the single global `Gate::before`
(`app/Providers/RepositoryServiceProvider.php:596-598`) — no Super Admin entry is written anywhere.
No other role changes. The existing `branch-change-request.approve` Gate
(`RepositoryServiceProvider.php:593`, Super-Admin-only) is **not** widened —
`tests/Feature/AccessControl/DailyBranchContextBypassTest.php:347-362` asserts by name that Supervisor RME must
not hold it, and it is critical-gated by `DailyBranchContext`.

---

## 2. Conflict resolutions

**A. Migration numbering — MIGRATIONS wins.** It is the only slice that numbers all four tables in one coherent
sequence, and its ordering is the only one that survives a fresh `migrate` (lease → covers FK). LEASE's
`100001 = leases` and HOMELOCK's `110001…` / `100002 = covers` are dropped. APPROVAL's `2026_09_10_1100xx`
triplet is dropped: it duplicates two of MIGRATIONS' tables under different numbers, and its
`lock_requests`-before-`locks` order contradicts the dependency direction. MIGRATIONS' table-naming rationale
also stands on its own evidence — `trx_doctor_branch_lock_requests` (31 chars) keeps the longest auto-generated
FK name at 61 bytes, under PostgreSQL's silent 63-byte truncation.

**B. Module namespace — a new `App\Modules\DoctorAccess` wins.** Decided by the route-middleware scan, not by
taste: `App\Modules\DoctorDevice\Middleware\*` is structurally forbidden
(`DoctorDeviceApiAndNoEnforcementTest.php:246-278`). Between the two survivors, `App\Modules\Doctor` is rejected
because it is the master-data context and hosts `DoctorClinicalBranchResolver`, which O3 freezes; the codebase's
own precedent (`DoctorDevice` as a separate module) is followed. `App\Modules\DoctorBranchLock` cannot host the
session lease. See §1.1 for the verified citations.

**C. Test locations — TESTS' `tests/Feature/DoctorAccess/` wins, extended.** APPROVAL's
`tests/Feature/DoctorBranchLock/` and HOMELOCK's two `tests/Feature/AccessControl/` files move in. One token
instead of three. The O4 pair stays in `tests/Feature/DoctorDevice/` because O4 correctly observed that the
existing token already selects it and no workflow edit is needed for those two files.

**D1. `.github/workflows/foundation-evidence-gates.yml` — FLAGS/TESTS win over APPROVAL.** Both propose the
single `|DoctorAccess` insertion in both variants; APPROVAL's `|DoctorBranchLock|DoctorBranchCover` pair is
obviated by the directory consolidation.

**D2. `PermissionGroupingService.php` — APPROVAL's *group* wins, FLAGS' *set* wins.** All four permissions go
into the **`rme`** group (`:52-78`), not `access_control`. FLAGS argued access_control; I ruled against it
because the routes, views and sidebar all live on the RME surface, the sole grantee is an RME role, and listing
doctor branch authority beside `manage users` / `manage roles` / `manage permissions` invites the inference
that Access Control admins should hold it — which this sprint explicitly refuses. APPROVAL's `DESCRIPTIONS`
discipline is kept and extended from one entry to four.

**D3. `RepositoryServiceProvider.php` — all three slices merge additively.** No contradiction; one edit with
five repository bindings and two policy registrations.

**D4. `config/ci_runner.php` — TESTS' + O4's paths win; FLAGS' paths are rejected as wrong.** FLAGS declares
six suite files that no slice ever authors (`DoctorSessionLeaseCardinalityTest`,
`DoctorSessionLeaseDenyNotEvictTest`, `DoctorBranchLockResolutionTest`, `DoctorBranchCoverLifecycleTest`,
`DoctorBranchLockApprovalAuthorityTest`, `DoctorBulkDeviceAuthorizationTest`). Declaring a non-existent path is
a hard gate failure. Corrected in §3.

**D5. `config/feature_flags.php` — FLAGS wins.** LEASE agrees with it on `doctor.single_active_session`.
HOMELOCK is corrected on three counts (wrong metadata key, wrong dependency key name, missing keys) — see §6.

**D6/D7. `PermissionSeeder.php` / `RoleSeeder.php` — FLAGS' four-permission set wins over APPROVAL's single
`approve_doctor_branch_lock`.** APPROVAL's controller needs four distinct abilities anyway (index, store,
decide, release-session); collapsing them onto one permission would make the filer and the approver the same
grant, which contradicts the maker/checker split APPROVAL itself enforces inside the transaction. Names take
the plural form for family consistency. Ruling P17's "behind the same permission" is honoured in *effect* —
`release_doctor_session_leases` is granted to exactly the approver role — while staying separately auditable.

**D8. `SupervisorRmeRolePermissionTest.php` — FLAGS wins**, with the three-permission insert
(`view_`, `approve_`, `release_`), not APPROVAL's one.

**D9-D11. The three duplicated models — MIGRATIONS' bodies win, relocated to `DoctorAccess`.** MIGRATIONS is the
only slice that specifies them in full (`$fillable` policy, casts, derived-state separation, half-open interval,
`establishedUnder` PHP-side `===` comparison). HOMELOCK's and APPROVAL's model stubs are dropped; any behaviour
they named that MIGRATIONS lacks (relations the repositories need) is added to the MIGRATIONS body.

---

## 3. Merged content of the twelve shared files

Each entry says what the single coherent edit contains. Bodies stay in the specs.

### 3.1 `.github/workflows/foundation-evidence-gates.yml`
Two edits, one per critical-gate variant, identical in effect. In the `--filter='…'` alternation at `:422` and
at `:690`, insert `|DoctorAccess` immediately after `|DoctorDevice` and before `|DoctorPwaWebAuthn`. Nothing
else in either string changes. No `paths-ignore`, no bare `runs-on: self-hosted`
(`config/ci_runner.php:117-120` forbidden markers). Detail: FLAGS §EDIT `.github/workflows/…` (line 334) and
TESTS §EDIT (line 324). Note for the sprint doc: touching this file puts the change into the CICD-CTRL-1
`ci_workflow` profile — the strongest — so every gate runs. That is correct for a sprint adding permissions, a
policy and four migrations.

### 3.2 `app/Modules/AccessControl/Services/PermissionGroupingService.php`
Two edits. **(a)** Append all four permission names to the `rme` group's `permissions` array (`:55-78`), after
`'view_legacy_odontogram_archive',` at `:77`. **(b)** Add four Indonesian entries to the `DESCRIPTIONS` map
(opens `:242`), beside the existing RME entries — APPROVAL supplies the voice
(`'Setujui penetapan dan perpindahan cabang tetap dokter, serta cover cabang sementara.'`); write one each for
view / manage / approve / release. Keeps the three whole-set `toBe` assertions green
(`RoleManagementTest.php:247`, `Sprint55…:173`, `Sprint53…:176`) and the Sprint54 assertion that the
`Other / Uncategorized` group still exists (many permissions, the whole doctor-device family included, remain
unclassified — deliberately not widened here).

### 3.3 `app/Providers/RepositoryServiceProvider.php`
One edit, three parts. **(a)** `use` imports for five interfaces + five implementations + two models + two
policies, all `App\Modules\DoctorAccess\…`, beside the existing Doctor/DoctorDevice imports at `:35-50`.
**(b)** Into the **`private`** `$repositories` array (`:286`, applied by the bind loop):
`DoctorSessionLeaseRepositoryInterface`, `DoctorBranchLockRepositoryInterface`,
`DoctorBranchLockRequestRepositoryInterface`, `DoctorBranchCoverRepositoryInterface` → their repositories.
**(c)** Into the **`private`** `$policies` array (`:447`), beside
`BranchChangeRequest::class => BranchChangeRequestPolicy::class` at `:509`:
`DoctorBranchLockRequest::class => DoctorBranchLockRequestPolicy::class` and
`DoctorBranchCover::class => DoctorBranchCoverPolicy::class`.
**Do not** add a `Gate::define` (these are permissions, not gates) and **do not** add a policy `before()` hook —
it would shadow the single global `Gate::before` at `:596-598`. Both arrays are `private`, not `protected`: a
stock-Laravel copy/paste onto another class registers nothing, and there is no `AuthServiceProvider`.
`DoctorEffectiveBranchResolver` needs no binding (concrete, constructor-autowired, like `ClinicalClock`).

### 3.4 `config/ci_runner.php`
Two edits. **(a)** Append thirteen paths to `critical_gate_mandatory_suites` (array opens `:172`, closes `:625`)
under one comment block in the established voice — the eleven `tests/Feature/DoctorAccess/*` suites from §1.3
plus the two `tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorization*` suites. The comment must say what the
registry preamble at `:143-170` demands: membership is a governance decision, and the specific reason here is
that the core invariant is a **partial unique index** while the local suite runs SQLite and the authoritative
gate runs PostgreSQL 16 — if these are not selected, the proof that a second live lease is refused on the
production driver executes nowhere. **(b)** Append `'DoctorAccess'` to `critical_gate_required_filters`
(`:139-141`). Verified safe: seven existing tests read the mandatory array and all use `->toContain(...)`, never
an exact-set `toBe`; nothing reads the required-filters array except the scanner. **Do not** touch
`critical_gate_warning_contract.expected_warning_count` (`:657`, `0`) — it is a declared baseline, not a
tolerance. **Do not** add these to `strict_pipeline_steps` (`:715-747`) — that list names workflow step names.

### 3.5 `config/feature_flags.php`
One insert, two entries, between the closing `],` of `doctor.pwa_webauthn_device_login` (`:421`) and the
`// --- FIX-04b` comment (`:423`). Verbatim block: FLAGS §EDIT `config/feature_flags.php` (line 49). Ten keys
each — `name, description, default, env_key, owner, risk_level, rollout_status, dependencies, rollback_action`
plus optional `review_target`; `enabled` and `env_value` are **not** declared (hydrate synthesises `enabled` at
`:207`; the config-build capture loop at `:452-458` injects `env_value`, which is what makes the flags survive
`config:cache`). `default => false` with `risk_level => 'critical'` is mandatory or FLAG-RISKY-DEFAULT-OFF fails
the whole registry (`FeatureFlagService.php:126-132`). `doctor.branch_lock.dependencies` names
`doctor.single_active_session`, and its description says outright that the array is documentation while the real
contract is the explicit AND in `DoctorEffectiveBranchResolver::enabled()` (ruling P7). Prose must avoid the
sensitive environment-file literal (`config/release_evidence.php:147`).

### 3.6 `database/seeders/PermissionSeeder.php`
One insert into `public const PERMISSIONS`, immediately after `'manage_doctor_device_authorizations',` (`:188`)
so the doctor family stays contiguous, adding the four names from §1.6 with FLAGS' comment block. `run()` is
`firstOrCreate` and idempotent. PermissionSeeder must always run **before** RoleSeeder: `RoleSeeder.php:551`
syncs Super Admin against this constant and Spatie throws on an unknown permission name.

### 3.7 `database/seeders/RoleSeeder.php`
One insert inside the `'Supervisor RME'` block (opens `:395`), after `'publish_legacy_odontogram_imports',`
(`:497`): `view_doctor_branch_locks`, `approve_doctor_branch_locks`, `release_doctor_session_leases`. The
comment must record that `manage_doctor_branch_locks` is **deliberately absent** — the role that decides may not
also file — and that separation of duties is additionally enforced inside the approval transaction, because
`Gate::before` returns true for Super Admin before any policy method runs. **Do not** add any of these to
`Doctor` (`:255-288`; `RolePermissionHardeningTest.php:62-68` pins Doctor to clinical workflow) or to
`Admin Lab` (`AdminLabLabOnlyAccessTest.php:175-176` asserts `role_extra_non_lab` is empty). Super Admin needs
no entry (`'*'` at `:550-551`).

### 3.8 `tests/Feature/Auth/SupervisorRmeRolePermissionTest.php`
One insert into the file-level `const SUPERVISOR_RME_PERMISSIONS` (opens `:16`, closes `:95`), before `:95`:
the same three names, with the comment stating why `manage_doctor_branch_locks` is excluded. The failing
assertion without it is the sorted whole-set `toBe` at `:110` — the only exact whole-role pin in the suite.
**State honestly in the sprint doc (ruling P14):** this file's identity
`Tests\Feature\Auth\SupervisorRmeRolePermissionTest` matches **no** token in either critical filter. It is
selected by the CICD-CTRL-1 Selective Module Gate at `:900` (`--filter='Permission|AccessControl'`), which the
classifier turns on because this sprint touches `database/seeders/*Permission*` and `*Role*`
(`scripts/ci/resolve-gates.sh:210`, `:301`). Do not claim critical-gate coverage for it.

### 3.9-3.11 The three duplicated models
All three move to `app/Modules/DoctorAccess/Models/` and take MIGRATIONS' bodies verbatim except the namespace
and imports.

- **`DoctorBranchLock.php`** — MIGRATIONS line 717. `$fillable = []` (every column is the authority or a
  lifecycle stamp; the `DoctorDeviceAuthorization.php:65-70` reading); writes go through the repository using
  `forceFill`. No `HasFactory`.
- **`DoctorBranchLockRequest.php`** — MIGRATIONS line 834. Requester-contributed columns only in `$fillable`
  (the `BranchChangeRequest.php:50-58` reading), so a forged `status=approved` has nowhere to land.
- **`DoctorBranchCover.php`** — MIGRATIONS line 997. Declares **only** `PENDING/APPROVED/REJECTED/CANCELLED`.
  `ACTIVE` and `EXPIRED` have **no model constant at all**; they live on the final Support class
  `DoctorBranchCoverState` (MIGRATIONS line 1199) reachable only via `for($cover, $at)`. Half-open interval
  `[starts_at, ends_at)` in both `coversInstant()` and `overlapsPeriod()`.

APPROVAL's `DoctorBranchCoverStatus`, `DoctorBranchLockRequestStatus` and `DoctorBranchLockRequestType` Support
classes are kept (they carry the persisted vocabularies and the assignment-vs-transfer derivation) and sit
beside `DoctorBranchCoverState` in `app/Modules/DoctorAccess/Support/`. `DoctorBranchCoverStatus` must **not**
declare `ACTIVE`/`EXPIRED` — that is `DoctorBranchCoverState`'s job and the whole point of the split.

---

## 4. Ordered implementation sequence

Each step leaves the tree runnable (`php artisan test`, `pint`, `view:cache` all clean at every boundary).

**Step 1 — Schema.** Four migrations in the §1.2 order. Spec: MIGRATIONS §Files, sections at lines 53, 174, 324,
525, and §Decisions for the partial-index rationale. The lease's unguarded partial unique index
(`CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq ON trx_doctor_session_leases (user_id) WHERE
released_at IS NULL`) goes in the **same** migration as the table, `DB::statement`, no driver guard — proven on
both engines by brief sections K and M. Covers carry the pending partial index plus the identical-period
backstop, whose migration comment must say it does **not** catch partial overlap.
*Leaves the tree runnable:* nothing reads these tables yet.

**Step 2 — Models and vocabularies.** Four models + four Support classes under `App\Modules\DoctorAccess\`.
Spec: MIGRATIONS lines 717, 834, 997, 1199, 1320 (lease model) and APPROVAL lines 67, 72, 77. Declare
`newFactory()` explicitly on any model a test factories, or the module namespace resolves to
`Database\Factories\Modules\…` and fails.

**Step 3 — Repositories, interfaces, bindings.** Five interfaces + five repositories, then the
`RepositoryServiceProvider` edit (§3.3, repositories only — policies wait for step 6). Spec: LEASE lines 71, 83;
APPROVAL lines 97-127; HOMELOCK lines 70-90 (content) — all renamespaced.

**Step 4 — Flags and the resolver.** `config/feature_flags.php` (§3.5), then the single
`DoctorEffectiveBranchResolver` in `app/Modules/DoctorAccess/Services/`. **This is a merge of two slices'
classes:** FLAGS' `DoctorBranchLockResolver` (line 92 — the flag-reading half, `enabled()` requiring **both**
flags, ruling P7) and HOMELOCK's `DoctorEffectiveBranchResolver` (line 95 — the effective-branch half). One
class, one name: `DoctorEffectiveBranchResolver`. It returns the immutable `DoctorEffectiveBranch`
(HOMELOCK line 90) carrying `(branchId, coverId, reason)`, is pure (takes a `User`, no Request, writes nothing),
and fails closed to UNSET-with-audited-warning when the locked or covered branch has lost `is_active` or
`is_rme_enabled` (ruling P9). *Runnable:* flags default off, nothing calls the resolver yet.

**Step 5 — Lease engine.** `IncumbentSessionProbe`, `DoctorSessionLeaseService`, `ClaimDoctorSessionLease`
listener, `EnsureDoctorSessionLease` middleware, `bootstrap/app.php` and `AppServiceProvider` registration, the
`AuthenticatedSessionController` and `ProfileController` release edits. Spec: LEASE lines 94, 116, 209, 236,
265, 278, 320, 335. Non-negotiables carried from the brief: claim on `Illuminate\Auth\Events\Login` (section R —
it fires on the remember-me recaller path at `SessionGuard.php:197-202`, and `actingAs` goes through `setUser`
so the ~3564 test call sites are untouched); the claim uses the outer-transaction + nested-savepoint +
catch-outside shape with a per-service portable unique-violation detector accepting both `23505` and SQLite's
`23000` (brief section N, template `DailyBranchContextService::assertSelectable()`); the deny path uses
`logoutCurrentDevice()`, **never** `Auth::guard('web')->logout()` and **never**
`DoctorDeviceSessionService::invalidate()`, which cycles the shared `users.remember_token` (ruling P4, section
R); the liveness probe is driver-aware — consult `sessions` only when `config('session.driver') === 'database'`
and otherwise treat the incumbent as ALIVE, failing closed toward denial (ruling P3); the middleware calls
`UserOnlineContextService::markOffline($user)` before teardown (ruling P5); lease events use their own audit
action, not `DOCTOR_SESSION_DEVICE_INVALIDATED` (ruling P8).
*Runnable:* the flag is off, so listener and middleware return on their first line.

**Step 6 — Approval workflows.** Two services, two policies, five FormRequests, one controller, three Blade
views, eleven routes, the `EnsureRmeOnlineContext` exemption, the sidebar block, the
`RepositoryServiceProvider` policy half, and `config/doctor_branch_lock.php`. Spec: APPROVAL lines 62, 127-192,
192 (routes), 197, 217, and the views at 177-192. Non-negotiables: both approvals lock the **request row first,
then the doctor's single `mst_doctor_branch_locks` row** — that row lock is the only thing enforcing cover
non-overlap, because a `FOR UPDATE` guard on a zero-row predicate does not lock a gap; refuse a cover for a
doctor with no home lock (the mutex row must exist) and refuse an unlinked doctor at both `request()` and
`approve()` (ruling P6); re-load and re-assert the doctor inside the transaction (P10); require a second
confirmation when the subject is ONLINE (P11); cancellation must set `status = 'cancelled'` as well as
`cancelled_at`, or the backstop index blocks a re-grant for the identical period; block a permanent transfer
approval while a cover is ACTIVE (section Q); release the lease and realign/clear the online context inside the
same transaction; touch **no** device, authorization or WebAuthn credential row.

**Step 7 — Seeders and permission grouping.** `PermissionSeeder` (§3.6), `RoleSeeder` (§3.7),
`PermissionGroupingService` (§3.2), and the `SupervisorRmeRolePermissionTest` repin (§3.8) in the same commit —
the repin is what keeps the suite green.

**Step 8 — Read/write/selector hooks.** `RmeWorkingBranchScope` (a **new** method, not an edit to
`branchIdsFor()`), the two `ClinicVisitService` read call sites plus the **write** hook at
`resolveBranchId()` (`ClinicVisitService.php:395-421` — ruling P1, the forged-`branch_id` visit-creation hole),
`UserOnlineContextService` server boundary, `OnlineContextController` selector narrowing, and the
`select.blade.php` copy. Spec: HOMELOCK lines 159, 183, 215, 231, 240. **Ruling P2 is a hard boundary:** hook
the LIST scope only; do **not** hook `ClinicVisitPolicy` or any per-record authorization, and do **not** touch
`DoctorClinicalBranchResolver` (O3). *Runnable:* all narrowing sits behind `doctor.branch_lock`, off by default.

**Step 9 — O4 bulk device authorization.** Service, plan value object, console command, and the two
`DoctorDeviceAuthorizationRepository` additions. Spec: O4 lines 38, 114, 136, 181, 199. Dry-run by default,
explicit plan-token approval showing the exact row delta before APPLY, transactional apply through the existing
`DoctorDeviceAuthorizationService::resolveOrRequest()` + `approve()` lifecycle — never `firstOrCreate`, because
`DoctorDeviceAuthorization` sets `protected $fillable = []` deliberately. Never revokes, never resurrects a
REJECTED or REVOKED pair, mints no credentials, writes no branch lock, arms no flag.

**Step 10 — Tests.** The eleven new suites plus the two O4 suites (§1.3), the six repins, then
`.github/workflows/foundation-evidence-gates.yml` (§3.1) and `config/ci_runner.php` (§3.4) **in the same
commit** — the registry fails on a declared path that does not yet exist. Spec: TESTS lines 42-323, APPROVAL
lines 197-250 (the three moved suites), HOMELOCK lines 256, 268, FLAGS line 134.

**Step 11 — Docs and manifest.** `docs/architecture/doctor-access-single-session-branch-lock.md` (rules
DSB-1..DSB-22), `docs/runbooks/doctor-branch-lock-and-session-lease-operations.md`,
`docs/sprints/doctor-access-single-session-branch-lock-1.md`,
`.cursor/rules/153-doctor-access-single-session-branch-lock.mdc` (**153, not 93** — ruling P18, 93 collides),
`.sprint/current.yml` (carry every inherited block forward verbatim; `full_required` deliberately absent), and
the `CLAUDE.md` section. Spec: FLAGS lines 161-524.

---

## 5. Owner decision coverage

- **O1 — lock starts UNSET, no backfill, two approval workflows.** `2026_09_10_100001_create_mst_doctor_branch_locks_table.php` (`home_branch_id` NOT NULL, so UNSET *is* the absence of a row and a backfill is structurally impossible); `2026_09_10_100002_create_trx_doctor_branch_lock_requests_table.php` (one table, `request_type` discriminating INITIAL_ASSIGNMENT vs TRANSFER); `app/Modules/DoctorAccess/Services/DoctorBranchLockApprovalService.php`; `app/Modules/DoctorAccess/Services/DoctorEffectiveBranchResolver.php` (a missing row reads as legacy compatibility, never an error); `tests/Feature/DoctorAccess/DoctorHomeBranchLockTest.php`, `…/DoctorBranchLockApprovalTest.php`; repins in `tests/Feature/MasterData/DoctorRmeBranchSourceTest.php`.
- **O2 — the lock narrows operational lists, but only once SET.** `app/Modules/RmeOnlineContext/Services/RmeWorkingBranchScope.php` (new method), `app/Modules/ClinicVisit/Services/ClinicVisitService.php` (2 read sites + the `resolveBranchId()` write hook, ruling P1), `app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php`, `app/Modules/RmeOnlineContext/Controllers/OnlineContextController.php`, `resources/views/rme/online-context/select.blade.php`; three-case regression in `tests/Feature/DoctorAccess/DoctorHomeBranchLockTest.php` (UNSET → legacy; LOCKED SPN4 → SPN4 only; same doctor on the LDK2 tablet → still SPN4 only).
- **O3 — the lock does NOT narrow legacy archive reads.** Satisfied by *absence*: `app/Modules/Doctor/Services/DoctorClinicalBranchResolver.php`, `app/Modules/LegacyRme/Support/LegacyRmeWorkspaceScope.php` and `app/Modules/LegacyOdontogram/Support/LegacyOdontogramWorkspaceScope.php` are on the do-not-touch list; pinned positively in `tests/Feature/DoctorAccess/DoctorHomeBranchLockTest.php` and `…/DoctorAccessGovernanceTest.php`.
- **O4 — bulk device authorization ships.** `app/Modules/DoctorAccess/Services/DoctorDeviceBulkAuthorizationService.php`, `app/Modules/DoctorAccess/Support/DoctorDeviceBulkAuthorizationPlan.php`, `app/Console/Commands/DoctorDeviceBulkAuthorizeCommand.php`, `app/Modules/DoctorDevice/Interfaces/DoctorDeviceAuthorizationRepositoryInterface.php` + `…/Repositories/DoctorDeviceAuthorizationRepository.php` (`countAll()`); `tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationTest.php` and `…NonEscalationTest.php`.
- **O5 — sprint boundary; global WebAuthn enforcement stays OFF.** Satisfied by absence plus assertion: `config/feature_flags.php:400` (`doctor.trusted_device_enforcement`) and `config/android_release.php` `scope.global_permitted` are untouched, no new file under `app/`, `routes/` or `bootstrap/` contains the literal `doctor.trusted_device_enforcement`, and no `doctor-pwa-global-rollout-readiness-1-go` tag is created; pinned in `tests/Feature/DoctorAccess/DoctorAccessGovernanceTest.php` and the repinned `tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php`.
- **O6 (as corrected by section Q) — temporary cover with explicit `starts_at`/`ends_at`, derived ACTIVE/EXPIRED, session invalidated on BOTH activation and expiry.** `2026_09_10_100003_create_trx_doctor_branch_covers_table.php`, `app/Modules/DoctorAccess/Models/DoctorBranchCover.php`, `app/Modules/DoctorAccess/Support/DoctorBranchCoverState.php` (the only home of ACTIVE/EXPIRED), `app/Modules/DoctorAccess/Services/DoctorBranchCoverApprovalService.php`, `DoctorEffectiveBranchResolver`, and the `effective_branch_id` + `effective_cover_id` columns on `trx_doctor_session_leases` compared in PHP by `DoctorSessionLease::establishedUnder()` from `app/Modules/DoctorAccess/Middleware/EnsureDoctorSessionLease.php` — the mechanism that makes activation, expiry and transfer behave identically with no scheduler; `tests/Feature/DoctorAccess/DoctorBranchCoverApprovalTest.php` (expiry proven with **no artisan command run anywhere in the test**) and `…/DoctorAccessConcurrencyTest.php` (the four mandatory section-Q scenarios).

---

## 6. What the slices got wrong, and what I corrected

1. **FLAGS declared six CI mandatory suites that no slice authors.** `DoctorSessionLeaseCardinalityTest`, `DoctorSessionLeaseDenyNotEvictTest`, `DoctorBranchLockResolutionTest`, `DoctorBranchCoverLifecycleTest`, `DoctorBranchLockApprovalAuthorityTest`, `DoctorBulkDeviceAuthorizationTest` exist in no other slice. `SelfHostedRunnerScanner.php:477-479` fails the gate on any declared path that does not exist, so shipping FLAGS' block verbatim would have **turned the gate red on merge**. Replaced with the real filenames (§3.4).
2. **HOMELOCK used the wrong feature-flag metadata key.** It wrote `'risk' => 'high'`. The whitelist at `app/Services/Foundation/FeatureFlagService.php:17-27` is `name, description, default, env_key, owner, risk_level, rollout_status, dependencies, rollback_action` — `risk` is not a member and `risk_level` is required. It also omitted `rollout_status`. `FeatureFlagFoundationTest` iterates every flag over all of these and is critical-gated by `FeatureFlag`. Corrected to FLAGS' ten-key block.
3. **HOMELOCK named a flag that does not exist.** Its `dependencies => ['doctor.single_session_lease']` and its description both reference `doctor.single_session_lease`; the flag LEASE and FLAGS actually register is `doctor.single_active_session`. Corrected everywhere, including inside `DoctorEffectiveBranchResolver::enabled()` where the dependency is genuinely enforced.
4. **Two resolvers were designed for one job.** FLAGS' `DoctorBranchLockResolver` and HOMELOCK's `DoctorEffectiveBranchResolver` are the same object seen from two slices. Merged into one class (§ step 4). Two classes would have produced exactly the second decision point ruling P7 exists to prevent.
5. **Two lease models were planned.** MIGRATIONS put `DoctorSessionLease` in `App\Modules\DoctorDevice\Models`; LEASE put it in `App\Modules\DoctorAccess\Models`. MIGRATIONS' own risk register flags this (*"do not leave two lease models"*). One model, `App\Modules\DoctorAccess\Models\DoctorSessionLease`, MIGRATIONS' body.
6. **Two lease test suites were planned for the same requirement.** LEASE's `tests/Feature/DoctorDevice/DoctorSessionLeaseTest.php` and TESTS' `tests/Feature/DoctorAccess/DoctorSingleSessionLeaseTest.php`. LEASE's placement argument (*"placed in tests/Feature/DoctorDevice/ so the critical gate actually runs it"*) is obsolete once the `DoctorAccess` token exists. Merged into the DoctorAccess suite.
7. **Two bulk-authorization suites share a basename in different directories.** `tests/Feature/DoctorAccess/DoctorDeviceBulkAuthorizationTest.php` (TESTS) and `tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationTest.php` (O4). Kept O4's — it is authored by the slice that builds the command and is already selected by the existing `DoctorDevice` token — and dropped TESTS' duplicate, folding any unique assertions into it.
8. **APPROVAL's single permission could not express its own maker/checker rule.** One `approve_doctor_branch_lock` would have gated index, store, decide and release-session alike, making the filing authority and the deciding authority the same grant while the slice's own service enforces that they must differ. Replaced by FLAGS' four-permission set (§1.6). Conversely, FLAGS' *grouping* choice (`access_control`) was rejected in favour of APPROVAL's `rme` group (§D2).
9. **Two suites were planned for `tests/Feature/AccessControl/` where no critical token reaches them.** HOMELOCK's `DoctorBranchLockOperationalScopeTest` and `DoctorEffectiveBranchResolverTest` have identities `Tests\Feature\AccessControl\…`; `AccessControl` is not in either critical filter, and neither name matches any existing token. They would have run only in the conditional CICD-CTRL selective gate. Moved into `tests/Feature/DoctorAccess/`.
10. **APPROVAL's two-token workflow edit was avoidable.** It proposed `|DoctorBranchLock|DoctorBranchCover` and flagged its own risk that the second would be forgotten. The directory consolidation reduces the whole sprint to one token.
11. **Migration ordering was wrong in two slices in a way that breaks `migrate`, not just tidiness.** LEASE numbers leases `100001`; HOMELOCK numbers covers `100002`. `trx_doctor_session_leases.effective_cover_id` is an FK onto `trx_doctor_branch_covers`, so either ordering fails on a fresh database. MIGRATIONS' `100001/100002/100003/100004` is the only correct sequence.
12. **A cancellation path can collide with the cover backstop index.** MIGRATIONS spotted it and assigned the fix to the service slice, which does not restate it: cancellation MUST write `status = 'cancelled'` as well as `cancelled_at`, or a cover cancelled and re-granted for the identical period is refused by `trx_doctor_branch_covers_period_uq`. Carried explicitly into step 6.
13. **Ruling P13's compile defect must not be reintroduced.** `claimOrDeny()` cannot audit a `$leaseId` assigned inside a nested closure whose return value is discarded. Called out in step 5 because the savepoint shape makes it easy to write again.
14. **Two honest-labelling obligations from ruling P14 are preserved rather than quietly dropped:** the manifest validator's frontend pattern does not match `.blade.php`, and the SupervisorRme repin is covered by the selective gate only, never the critical gate (§3.8).

### Known residual gaps (not resolvable at integration time)

- **Blocking unknowns 2, 5 and 7 stay open by design.** Which doctors get which locked branch is an owner decision per doctor (O1 forbids inference); the approval-authority choice is settled here as a **new permission** rather than a widened Gate, which is the option the sprint requires and which `DailyBranchContextBypassTest.php:347-362` forces; the lease TTL question is dissolved by ruling P3 — there is **no idle reclaim at all**, only dead-incumbent reclaim, so no TTL needs choosing.
- **Blocking unknown 6 (does `logout()` cycle `remember_token`) is now ANSWERED** by brief section R against vendor: `SessionGuard.php:650` → `:657` cycles it, `logoutCurrentDevice()` at `:680` does not. Folded into step 5.
- **The cover non-overlap invariant is not in the database on either engine**, and the plan does not pretend otherwise. It rests on the `mst_doctor_branch_locks` row lock inside the approval transaction. On SQLite `lockForUpdate` compiles to an empty string, so the local run proves the logic and the PostgreSQL critical gate proves the concurrency. This must be stated plainly in the sprint doc, not implied away.
- **The `sessions` liveness join is untestable as the suite is configured** (`SESSION_DRIVER=array` in `phpunit.xml:29` and in both CI jobs). It will be pinned by a fake. Say so; do not claim coverage.
- **Pre-existing defect, out of scope:** three sites catch a `QueryException` *inside* `DB::transaction` and then run further queries, which fails on PostgreSQL with `25P02` — `app/Modules/RME/Services/PatientDoctorAssignmentService.php:48` and `:172`, `app/Modules/MedicalRecord/Services/DiagnosisRolloutService.php:127`. Report to the owner; do not widen this sprint.

---

# S. ASSURANCE CORRECTIONS — BINDING. APPLY ALL BEFORE IMPLEMENTING.

The assurance review returned CONDITIONAL FAIL with 11 problems. Every one is ruled below. These
OVERRIDE the corresponding text earlier in this file and in the slice specs.

## C1 (CRITICAL) — a session with no lease token PASSES THROUGH, unconditionally
Brief E5: ~3554 `actingAs()` sites and the `doctorWithOnlineContext` / `rmeMakeDoctorOnline` helpers
(tests/Pest.php:497, :512) mint sessions that never fired `Login`. If the middleware denies a doctor
session that carries no lease token, the migration cost is the whole suite.

RULE, non-negotiable, add to step 5:
- Claim ONLY on the `Login` event.
- Renew conditionally.
- A session carrying NO lease token passes through unconditionally.
- NEVER create a lease for a session that never had one.
Pin it: `actingAs()` a doctor with both flags armed, hit a protected route, assert 200 and ZERO lease rows.

## C2 (CRITICAL) — enabled() is flag AND probe, never "treat the incumbent as ALIVE"
Delete the clause telling the probe to treat an incumbent as ALIVE when the session driver is not
`database`. That fails closed toward denial and would deny every login on a file/redis/array driver.
Adopt the LEASE spec verbatim:
    DoctorSessionLeaseService::enabled() === flag && IncumbentSessionProbe::observable()
    IncumbentSessionProbe::observable() === config('session.driver') === 'database' && Schema::hasTable('sessions')
Pin a case asserting the engine is inert on a non-database driver.

## C3 (HIGH) — middleware position is FIRST in the web append list
It must be the first element of `$middleware->web(append: [...])` at bootstrap/app.php:59-70, BEFORE
`TouchOnlineContextLastSeen::class` at :60, because Touch does an unconditional `last_seen_at` write that
`assertRoomNotOccupiedByOtherDoctor()` keys off, so appending last lets a doomed request refresh a
room-holding presence row. PREPEND IS IMPOSSIBLE: Configuration/Middleware.php:484-493 puts StartSession
inside the group, so a prepended middleware has no session and no user. Also carry ruling P5's second half
and CORRECT the bootstrap comment, which currently claims a benefit the present ordering does not deliver.

## C4 (HIGH) — the branch resolver must also require the probe
Cover-expiry invalidation lives only in the lease middleware. If the lease engine disarms because the
session driver is not observable while `doctor.branch_lock` stays armed, lists narrow and an EXPIRED cover
keeps granting authority. Therefore:
    DoctorEffectiveBranchResolver::enabled() === doctor.branch_lock && doctor.single_active_session && IncumbentSessionProbe::observable()
Add a governance case: with the probe unobservable the resolver reports not-applicable and lists and writes
fall back to legacy behaviour rather than granting unenforceable cover.

## C5 (HIGH) — one lease-release mechanism, in the controllers
Two specs disagreed. RULING: release in `AuthenticatedSessionController::store()` immediately before the
gate invalidate at :83; in `::destroy()` before the logout at :117; and in `ProfileController::destroy()`
before `Auth::logout()` at :55. Do NOT make `DoctorDeviceSessionService::invalidate()` release the lease.
Rewrite the `DoctorPwaWebAuthnTest:528` repin rationale to name the controller release, not invalidate().

## C6 (HIGH) — rename table for non-namespace literals
The merge changed literals beyond namespaces. Apply mechanically everywhere:
    DoctorBranchLockResolver        -> DoctorEffectiveBranchResolver
    approve_doctor_branch_lock      -> approve_doctor_branch_locks
    release_doctor_session_lease    -> release_doctor_session_leases
Add a governance case asserting every permission string used by the controller and the policies exists in
`PermissionSeeder::PERMISSIONS`.

## C7 (MEDIUM) — the sessions liveness join IS testable. Delete the false residual.
The `sessions` table is created by 0001_01_01_000000_create_users_table.php:30-37 and migrated by
RefreshDatabase in EVERY run regardless of driver. Only the WRITER is absent under the array driver, so a
test inserts the row itself. Use the LEASE spec recipe. Keep only the two residuals that are genuinely
real: RefreshDatabase makes the claim run one savepoint deeper than production, and `lockForUpdate`
compiles to nothing on SQLite so only the PostgreSQL critical gate proves concurrency.

## C8 (MEDIUM) — ruling P12 is assigned to step 11
Enumerate EVERY `RmeWorkingBranchScope` consumer in docs/architecture/doctor-access-single-session-branch-lock.md,
marking each hooked or not-hooked and why. Call out `OdontogramService::patientHistory` at
OdontogramService.php:86 as the O3 tripwire that forced the new-method design.

## C9 (MEDIUM) — copy isUniqueViolation verbatim; do NOT add 23000 as a code test
Section N's template tests `getCode() === '23505'` plus three lowercase message substrings. SQLSTATE 23000
is the GENERIC integrity-constraint class: testing it as a code would misclassify a foreign-key or
not-null violation as a lost race. Copy DailyBranchContextService.php:221-232 verbatim.

## C10 (MEDIUM) — ruling P16 is ruled: pre-check, do not burn the ticket
Pre-check lease availability in `DoctorDeviceSessionService` BEFORE consuming the one-time login ticket, so
a denial leaves `consumed_at` NULL. Pin `consumed_at` NULL in the lease suite. Rationale: the path is dead
only while enforcement is off, and the moment the pilot arms it a denied doctor would otherwise lose their
ticket and have to re-authenticate from scratch on the tablet.

## C11 (LOW) — registry and manifest details
- Drop `tests/Feature/DoctorAccess/helpers.php` from `critical_gate_mandatory_suites`; it is not a suite,
  and the DoctorAccess token selects the directory anyway.
- The repin count is FIVE, not six.
- `.sprint/current.yml` declares schema_change=true, security_impact=true and frontend_change=true, and
  MUST keep naming LEGACY-RME-PROGRAM-CLOSURE-1 (LegacyRmeProgramClosureContractTest.php:146-157).

## Residual honest gaps, carried forward unchanged
- Which doctor gets which locked branch is an owner decision per doctor. Every lock row stays absent.
- Cover non-overlap cannot live in the database on either engine, because a unique index compares values
  while overlap is a range predicate. It rests on the `mst_doctor_branch_locks` row lock inside the
  approval transaction. On SQLite `lockForUpdate` compiles to an empty string, so only the PostgreSQL
  critical gate proves the concurrency; the local run proves the logic.
- Three pre-existing defects reported and deliberately NOT fixed: PatientDoctorAssignmentService.php:48
  and :172, DiagnosisRolloutService.php:127.

# T. STEP 1-2 IMPLEMENTED AND EMPIRICALLY VERIFIED (2026-09-10)

12 files written: 4 migrations, 4 models under App\Modules\DoctorAccess\Models, 4 Support vocabularies.
php -l 12/12 clean; `pint --test` passed; PSR-4 autoload verified; `git status` shows ZERO existing files
modified. The full migration chain ran clean on a throwaway sqlite database.

INDEX BEHAVIOUR PROVEN BY ATTEMPTING TO VIOLATE IT, not by inspecting the schema:
- second ACTIVE lease for the same user            -> REJECTED (UNIQUE constraint failed)
- different user, concurrent lease                 -> ACCEPTED
- release then re-claim for the same user          -> ACCEPTED, released row retained (2 rows)
- second PENDING lock request, same doctor         -> REJECTED
- a REJECTED request alongside a PENDING one       -> ACCEPTED (partial predicate correct)
- different doctor, PENDING                        -> ACCEPTED
- identical APPROVED cover period, same doctor     -> REJECTED (the backstop index works)
- second PENDING cover, same doctor                -> REJECTED
- **OVERLAPPING (non-identical) cover period       -> ACCEPTED**

THE LAST LINE IS THE HONEST RESIDUAL, NOW CONFIRMED RATHER THAN ASSUMED. A unique index compares values;
overlap is a range predicate no index on either engine can express. The non-overlap invariant therefore
rests ENTIRELY on the `mst_doctor_branch_locks` row lock inside the cover-approval transaction. Two
consequences the implementation must honour:
1. The approval service MUST take that row lock before evaluating overlap, and MUST re-evaluate under the
   lock. Without it, two approvers can grant overlapping covers.
2. No document, test name or sprint note may claim the database prevents overlapping covers. It prevents
   duplicate PENDING covers and identical APPROVED periods only.
On SQLite `lockForUpdate` compiles to an empty string, so the local suite proves the logic and only the
PostgreSQL critical gate proves the concurrency.

# U. MY OWN REVIEW OF THE STEP-5 CODE (2026-09-11)

The middleware and listener were read line by line, not accepted on their author's summary. The ordering,
the passthrough placement, the markOffline-before-teardown and the WebView-safe denial shape are all
correct as written. Two findings the TEST step must cover rather than assume:

## U1 — a DENIED claim on the REMEMBER-ME path throws from deep inside Auth::user()
ClaimDoctorSessionLease runs on Illuminate\Auth\Events\Login, which section R proved also fires from
SessionGuard::user() at :202 on the recaller path. claimOrDeny() DENIES by throwing a ValidationException
after tearing the current device down. On an ordinary form login that is exactly right. On the recaller
path it throws from inside whatever first resolved $request->user(), typically the Authenticate
middleware, on a plain GET.

Expected behaviour: the device is logged out first, so the redirect-back lands on a protected page, which
then redirects to login. One extra hop, not a loop. That is a PREDICTION and must be PROVEN.

REQUIRED TEST CASE: a doctor holding a live lease in session A; browser B presents a valid recaller cookie
for the same account; assert B is refused, assert A's lease is still active with released_at NULL, assert
B lands on the login screen rather than looping, and assert the shared users.remember_token was NOT cycled
so A's own recaller still works.

## U2 — hot-path cost on every authenticated doctor request that holds a lease
The middleware calls DoctorEffectiveBranchResolver::resolve() on every such request, which is up to three
bounded reads (doctor by user_id, active cover, locked branch), plus the lease revalidation read. That is
the hot-path budget. It is only paid by doctors, only when both flags are armed and the session driver is
observable, and only for a session that actually holds a lease token.

REQUIRED MEASUREMENT before GO: count the queries added to one authenticated doctor request with the flags
armed, and confirm every lookup hits an index. Report the number rather than asserting no regression.

## U3 — the "audited warning" half of the degradation has no home yet
Verified by reading the code: DoctorEffectiveBranch::degraded() sets branchId to NULL, so a doctor whose
locked branch has lost is_active or is_rme_enabled falls back to legacy behaviour and is never evicted.
That is exactly the handled degradation rulings C9 and P9 require, and it is correct.

But ruling P9 says UNSET-with-AUDITED-WARNING, and the resolver deliberately does NOT audit — correctly,
because auditing there would write a sys_audit_logs row on every page view. So today the degradation is
handled but SILENT: a doctor quietly stops being branch-locked and nobody is told.

REQUIRED, assign to the approver surface (step 6b) and/or a readiness command (step 9):
- The approver queue must show any doctor currently in a degraded state, with the retained home branch id
  and the reason code, so an admin can see that a lock has stopped applying.
- If a readiness or audit command is added, it must report degraded doctors as a WATCH, never as GO.
- Do NOT solve this by auditing inside the resolver.
Without this, a single master-data toggle silently unlocks a doctor and the sprint's own invariant lapses
with no signal. This is the difference between a safe degradation and an invisible one.

## U4 — timezone reading, checked and accepted
The resolver uses CarbonImmutable::now() rather than the clinical clock, with a stated reason: starts_at
and ends_at are INSTANTS, comparing an instant to an instant is timezone-agnostic, and the clinical clock
throws on a misconfigured timezone, which on a per-request path would turn a config typo into a total
doctor outage. The clinical clock remains the authority where a WALL CLOCK is meant: parsing the period an
approver types and rendering it back.

This is faithful to owner decision O6's "deterministic and timezone-safe using the clinic's canonical
timezone" PROVIDED step 6a actually parses and renders through the clinical clock. VERIFY THAT when the
approval services land; if they parse with a bare Carbon::parse, the period an approver types is silently
interpreted in the app timezone and the guarantee is lost.

# V. OPEN ITEMS RAISED BY STEPS 7 AND 8 (2026-09-11)

## V1 (MUST FIX) — an approved lock or cover can strand a doctor completely
If an approved home lock or cover names a branch that is NOT in the doctor's mst_doctor_branches practice
pivot, `startDoctorSession()` refuses at the pre-existing practice-branch check BEFORE the new locked-branch
assert is ever reached. The doctor then cannot go online at all — not narrowed, stopped.

Do NOT relax the practice-branch check; relaxing it would widen access. The fix belongs in the APPROVAL
services: `DoctorBranchLockApprovalService::approve()` and `DoctorBranchCoverApprovalService::approve()`
must validate, inside the transaction under the existing locks, that the target branch is one of the
doctor's practice branches, and refuse with an Indonesian message naming the remedy.

Latent, not live: in production today all 15 doctors are pivoted to all four RME branches. It becomes live
the first time anyone narrows a practice pivot.

## V2 (MUST FIX) — a refused forged-branch attempt leaves no trail
The write hook `ClinicVisitService::assertWithinDoctorEffectiveBranch()` refuses but does not audit, while
`DoctorEffectiveBranchResolver`'s own docblock claims "the write hook audits a refusal once per attempt".
One of the two must change. A locked doctor posting another branch's id is exactly the event that belongs
in `sys_audit_logs`. Wire AuditLogService into the assert, or delete the sentence. Do not leave the
codebase asserting something it does not do.

## V3 (SHOULD FIX) — the branch-filter dropdown still offers every branch to a locked doctor
`ClinicVisitService::selectableRmeBranches()` (:115) and `RmeReportController` (:488) still call
`branchIdsFor()`, so a locked doctor sees every RME branch in the filter. NOT a boundary hole — `narrow()`
discards an out-of-scope filter, so the data returned is still only their locked branch — but it offers a
choice that does nothing, which is exactly the kind of UI that teaches operators the lock is unreliable.
Switch those two call sites to `operationalBranchIdsFor()`.

## V4 (TEST) — a report request for another branch narrows silently instead of refusing
`RmeReportController:446` validates a requested branch through `allows()`, which is deliberately NOT
narrowed, so a locked doctor asking for another branch's report passes the check and then quietly receives
their own branch's data. No leak. Decide and pin: silent narrowing or an explicit refusal.

## V5 (TEST) — hybrid accounts fail closed to an empty scope
A user who is both a lockable Doctor and a context-bound role gets the INTERSECTION of their online-context
branch and their locked branch, which is EMPTY when the two disagree. That is the fail-closed choice and it
can only narrow, never widen, but no owner decision names it. Pin it with an explicit test so the behaviour
is chosen rather than inherited.

## V6 (OWNER DECISION NEEDED) — the maker tier has exactly one occupant
`manage_doctor_branch_locks` was granted to NO role, deliberately, to keep maker and checker apart:
Supervisor RME holds view + approve + release but NOT manage. Consequence: filing a COVER is reachable only
by a Super Admin, via the global Gate::before, and the cover sidebar item is invisible to Supervisor RME.
A doctor filing their OWN home-lock request is unaffected — that path is policy-only against
mst_doctors.user_id and needs no permission at all.

So the working shape is: a Super Admin files a cover, and a DIFFERENT Super Admin or a Supervisor RME
approves it, because the service refuses requester == approver by user id. That requires more than one
Super Admin account to exist. ASK THE OWNER whether a non-Super-Admin maker role is wanted. It is a role
grant, not a code change.

# W. OWNER DECISION O7 — STRICT ACTOR-BASED MAKER-CHECKER (2026-09-11). Supersedes V6.

Both Super Admin AND Supervisor RME may CREATE a temporary branch-cover request, and BOTH may approve or
reject. `manage_doctor_branch_locks` is therefore granted to Supervisor RME as well.

THE INVARIANT IS ACTOR BASED, NEVER ROLE BASED:

    request.created_by MUST NEVER equal the approving actor      ->      maker_user_id != checker_user_id

Worked examples the owner gave:
- Super Admin A creates    -> Supervisor RME may decide;  Super Admin A may NOT decide.
- Supervisor RME creates   -> Super Admin may decide;     that same Supervisor RME may NOT decide.

DO NOT ENCODE "Super Admin is always the maker" or "Supervisor RME is always the checker". That would couple
the workflow to today's staffing, which is exactly one account of each. If more accounts of either role
appear later, the invariant must still hold on user id alone.

**The service-layer invariant is MANDATORY and must execute independently of `Gate::before` and the Super
Admin bypass.** The single global `Gate::before` returns true for Super Admin before any policy method runs,
so an invariant living only in a policy would never execute for the one actor most able to be both parties.
It belongs in the service, inside the transaction, under the row lock.

Permanent doctor branch transfers stay SEPARATE: a doctor may file their own request with no management
filing permission, and may NEVER approve their own.

## The eight tests the owner requires
1. Super Admin creates, Supervisor RME approves            -> PASS
2. Supervisor RME creates, Super Admin approves            -> PASS
3. Super Admin creates, the SAME Super Admin approves      -> DENY
4. Supervisor RME creates, the SAME Supervisor RME approves -> DENY
5. an unauthorised role creates a management cover          -> DENY
6. an unauthorised role approves                            -> DENY
7. a requester who GAINS or CHANGES role later still cannot approve their own request
8. `Gate::before` must NOT bypass the maker-checker invariant

Test 7 is satisfied structurally if the check compares the STORED requester_user_id rather than re-deriving
anything from the actor's current roles. Test 8 is satisfied only if the refusal sits in the service, not
the policy. Both must be asserted explicitly rather than argued.

# X. A LIVE HOLE FOUND BY OWNER DECISION O7 (2026-09-11)

Applying O7 exposed a real defect that had already been written and would otherwise have shipped.

**The COVER approval service had NO requester-versus-approver check at all.** Its own docblock said so
verbatim: "THE REQUESTER MAY APPROVE THEIR OWN COVER; ONLY THE SUBJECT DOCTOR IS EXCLUDED", and
`DoctorBranchCoverPolicy::decide()` checked only `can('approve_doctor_branch_locks')`. The LOCK service had
the check correctly; the cover path did not.

Consequences had it shipped:
- A Super Admin could already file AND approve their own cover, because the single global `Gate::before`
  satisfies the permission check and nothing else stood in the way.
- Granting `manage_doctor_branch_locks` to Supervisor RME, which O7 requires, would have opened the same
  self-approval to that role too. The owner's instruction is what surfaced it.

**Now closed and verified by reading the code.** `lockPendingCover($coverId, $decider)` is called at
DoctorBranchCoverApprovalService.php:229 (approve) and :347 (reject), both inside `DB::transaction` opened at
:222 and :346. The refusal at :529-535 is a plain integer comparison on the STORED `requester_user_id`,
ordered BEFORE the pending re-assert, with no `can()`, no policy call and no Gate anywhere in the path — so
the Super Admin bypass cannot skip it, and a requester who later gains a different role is still refused
because nothing is re-derived from current roles. That satisfies owner tests 3, 4, 7 and 8 structurally.

The full decision order under lock is now: lock the cover row, refuse maker == checker, re-assert PENDING,
lock the doctor row, refuse approver == subject doctor, validate the practice pivot, evaluate overlap, write.

`cancel()` is deliberately NOT bound by the maker-checker rule: withdrawing your own pending cover is the
requester's own remedy, not a decision about it.

LESSON FOR THE SPRINT DOC: the lock path and the cover path were written by the same step and only one got
the invariant. Any future sibling workflow must be checked against BOTH, not assumed to inherit.

# Y. THE FALSE GREEN TO CHECK FOR WHEN THE NEW SUITES RUN (2026-09-11)

Under the array session driver that phpunit selects, a login generates a session id but writes NO row to
the `sessions` table. `IncumbentSessionProbe::isLive()` reads that table. So an incumbent lease whose
session row is absent is classified DEAD and RECLAIMED.

Consequence: a "second login is denied" test that does not first insert a `sessions` row for the incumbent
will see the second login SUCCEED by reclaim, and a carelessly written assertion could then be made to pass
for entirely the wrong reason. The test would be asserting the reclaim path while claiming to prove the
denial path.

WHEN I RUN THE SUITES I MUST CONFIRM, by reading the test bodies and not the names:
1. Every "second login denied" case calls `daInsertSessionRow()` for the FIRST session before the second
   login attempt, so the incumbent is genuinely live.
2. The dead-incumbent reclaim case uses `daKillSessionsFor()` and asserts the OPPOSITE outcome, so the two
   paths are distinguished rather than conflated.
3. The two cases assert different things: denial asserts the first lease still has released_at NULL and the
   second login got no lease; reclaim asserts the first lease IS released with the reclaim reason and the
   second login holds the only active lease.
If a suite proves denial without a live session row, the test is wrong even though it is green.

# Z. OWNER DECISION O8 — ONE FULL SUITE, AT THE END ONLY (2026-09-11)

Verbatim intent: run the Full Suite ONLY after everything is finished. Do NOT run a Full Suite before
all three pull requests are complete.

CONSEQUENCES, to be honoured by every child PR:
- No child PR runs a Full Suite. PR-A, PR-B and PR-C are each gated on their OWN suites, the wide
  regression, the governance gates and CI, and nothing more.
- Each child manifest must therefore continue to OMIT `full_required` from `test_profiles`, and must keep
  saying plainly that the omission is not a claim the full suite ran. That is now an owner instruction as
  well as the standing repository CI policy.
- ONE Full Suite runs after PR-C is merged, deployed and verified, and it gates the PARENT GO tag
  `doctor-access-single-session-branch-lock-1-go`. No child tag depends on it.

THE EXPOSURE, STATED RATHER THAN BURIED: production receives PR-A and PR-B before any Full Suite has run.
That is a deliberate owner choice and it is consistent with existing practice here — the repository's
standing CI policy already defers the Full Suite gate, and every prior foundation sprint shipped the same
way. Each child deploy is still covered by its own suites, by the ~2442-test wide regression across RME,
DoctorDevice, DoctorDeviceWebAuthn, Auth, AccessControl, MasterData and DoctorAccess, by ten governance
gates, and by the CI critical gate's large filtered selection. So this is not an unguarded deploy; it is a
deploy guarded by everything except the final exhaustive pass.

REVISED TIME: roughly 15 to 22 hours of working time remaining, of which 3 to 6 is the single closing
Full Suite. Wall-clock is longer, because the three deploys are strictly sequential and PR-B's ceremony
needs a person at three tablets.
