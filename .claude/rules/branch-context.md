---
paths:
  - "app/Modules/Branch/**"
  - "app/Modules/Clinic/**"
  - "app/Modules/ClinicRoom/**"
  - "app/Modules/RmeOnlineContext/**"
  - "app/Modules/FrontOfficeDevice/**"
  - "app/Modules/AccessControl/**"
  - "app/Support/AccessControl/**"
  - "app/Http/Middleware/EnsureAccountIsActive.php"
  - "database/seeders/RmeBranch*.php"
  - "database/seeders/RoleSeeder.php"
  - "database/seeders/PermissionSeeder.php"
  - "config/front_office_device_lock.php"
  - "tests/Feature/Branch/**"
  - "tests/Feature/BranchScope/**"
  - "tests/Feature/FrontOfficeDevice/**"
  - "tests/Feature/AccessControl/**"
---

# Branch context, branch codes, rooms & Front Office locks — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here (CG-05 branch isolation, CG-06 three-layer authorization, CG-12 clinical time and CG-15
seeding order matter most). Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement
follow the code, then the canonical rule, and report the drift.

## Invariants

### Branch codes and branch rows

- **AIR-BRC-01** The canonical registry is `TLK1` Cabang Telkomas, `LDK2` Cabang Landak, `ATG3` Cabang Antang,
  `SPN4` Cabang Sunu. `TKM1` and `SUN4` are deprecated aliases: accepted on input, never emitted by any code path
  (new patients, Nomor RM, visit numbers, rollout declarations, reports, exports, fixtures, seed data). `MAIN` is not
  RME-enabled and is never an RME working branch. Source: `.cursor/rules/136-sunu-branch-code-canonical.mdc`,
  `92-telkomas-branch-code-canonical.mdc` (supersedes the registry line of `73-rme-branch-sun4-perawat-online-context.mdc`).
- **AIR-BRC-02** The alias mapping lives only in `App\Modules\Branch\Support\BranchCodeAlias`. Call `canonicalize()`
  before looking a code up or comparing it, and `equivalentCodes()` when matching a stored value that may hold either
  spelling. Never write `$code === 'TKM1' || $code === 'TLK1'`. A future renamed branch is one more row in
  `BranchCodeAlias::HISTORICAL_ALIASES`, never a new conditional. Source: `92-telkomas-branch-code-canonical.mdc`
  §4, `136-sunu-branch-code-canonical.mdc`.
- **AIR-BRC-03** Match branch codes as exact tokens and fail closed: `TKM` is not `TKM1`, an unknown, ambiguous,
  inactive or non-RME code resolves to nothing, and there is no fuzzy or "unknown → some branch" fallback.
  Source: `92-telkomas-branch-code-canonical.mdc` §7–8.
- **AIR-BRC-04** Rename a branch code only through a collision-checked, single-transaction migration that fails closed
  and changes only the branch-code segment of a Nomor RM through the parser (never `replace(col,'SUN4','SPN4')`). Never
  rewrite immutable records to remove an old code: published legacy evidence, `sys_audit_logs`, terminal rollout
  waves, issued `trx_clinic_visits.visit_number`, staging rows, issued room codes (Telkomas rooms stay `TKM-*`).
  Source: `92-telkomas-branch-code-canonical.mdc` §6, §11–12; archive REVISION-SUNU-BRANCH-CODE-SUN4-TO-SPN4-1.
- **AIR-BRC-05** Never assume the repository and production agree on a branch code; both Telkomas and Sunu were found
  renamed by hand in production while the code still declared the old code. Check both and treat a rename as possible
  drift repair. Resolve branches by `code`, never by an id copied between environments. Source:
  `136-sunu-branch-code-canonical.mdc` fact 2, `73-rme-branch-sun4-perawat-online-context.mdc` §1.
- **AIR-BRC-06** Branch rows are provisioned only by `Database\Seeders\RmeBranchSeeder`: idempotent `firstOrNew` by
  every equivalent code including soft-deleted rows, never renaming or reconfiguring an existing row, never touching
  `MAIN`, never deleting a branch with transactions, and comparing against `BranchCodeAlias::*_CANONICAL` constants,
  never a literal. Deploys run it explicitly (`db:seed --class=RmeBranchSeeder --force`). Source:
  `73-rme-branch-sun4-perawat-online-context.mdc` §2, `92-telkomas-branch-code-canonical.mdc` §14, `136-…` fact 3.

### Branch authority

- **AIR-BRC-07** `BranchContext::forUser()` resolves in this order: Front Office pin (from
  `FrontOfficeBranchPinResolver`); an armed but misconfigured pinned account resolves to `null`; then the active RME
  online context (`UserOnlineContextService::activeContextBranchId()`, which applies the daily lock and the pin);
  then an active `users.branch_id`; then the `branches()` relation; then the default branch. The online context is
  canonical for context-bound roles; `users.branch_id` is a legacy fallback, never an enforcement boundary.
  Source: code `BranchContext::forUser()`, `73-…` §4, `164-front-office-branch-context-lock.mdc`.
- **AIR-BRC-08** `App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope` is the only answer to "which RME
  branches may this workspace read". Context-bound roles (Admin Klinik / Front Office, Perawat, Kasir) work from one
  selected branch and get an EMPTY scope without a valid context — never MAIN, the first branch, the patient's branch
  or the estate. Governance roles keep the full RME set. A request `branch_id` only narrows. The scope covers lists,
  counts, totals, print and export alike. Source: `108-clinic-ops-working-branch-context.mdc` §1–7.
- **AIR-BRC-09** Never call `RmeWorkingBranchScope::branchIdsFor(null)`: null answers every RME branch (recorded
  residual contract). Pass a persisted actor. Source: `199-rme-clinic-visit-branch-authority.mdc` §23.
- **AIR-BRC-10** Doctor is not context-bound by `RmeWorkingBranchScope`; the doctor practice-branch model
  (`DoctorClinicalBranchResolver`) is a separate domain and must not be regressed. All context-bound roles use the one
  `RmeOnlineContext` engine (`startAdminClinicSession`, `startPerawatSession`, `startKasirSession`); never build a
  second context engine or pin a role through `users.branch_id` for daily work. Source: `108-…` §6,
  `73-…` §3.
- **AIR-BRC-11** Front Office is the merged front-desk role (union of Admin Klinik and Kasir) and is resolved through
  the admin-clinic online context. Ask `App\Support\AccessControl\FrontOfficeRole` (`ADMIN_CLINIC_CONTEXT`); never add
  a `hasRole('Admin Klinik')` / `hasRole('Kasir')` literal, because a role matching neither fails open and silently
  drops the daily branch lock. Source: code `FrontOfficeRole`, `199-…` §3b.
- **AIR-BRC-12** Resolving branch authorities is not read-only: `currentContextFor()` lazily calls
  `markExpiredInactive()` on an expired context. A read-only tool must resolve inside an always-rolled-back
  transaction and its tests must include an AGED context row. Verify production by code fingerprint, never by calling
  the resolver for a real context-bound account. Source: `165-sunu-go-live-readiness.mdc` §6a, archive
  AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1.

### Daily working-branch lock

- **AIR-BRC-13** The admin-clinic and kasir role contexts (`DailyBranchContextService::LOCKED_ROLE_CONTEXTS`) choose a
  branch once per clinical day (`ClinicalClock`, calendar day, not rolling 24 h); a different branch later the same day
  needs Super Admin approval; re-selecting the same branch is idempotent. Doctor and Perawat are not locked.
  `trx_daily_branch_contexts.current_branch_id` outranks the session row; the guard `assertSelectable()` runs inside
  `startAdminClinicSession()` / `startKasirSession()` after the eligibility assert. Source:
  `92-daily-branch-context-lock.mdc` §1–3, §5.
- **AIR-BRC-14** Keep `UNIQUE(user_id, clinical_date)` without `role_context`, the partial unique index on pending
  `trx_branch_change_requests`, and `lockForUpdate()` on both rows; never replace them with an application check.
  Concurrency proofs belong in `DailyBranchContextConcurrencyTest` (PostgreSQL, skips loudly elsewhere). Source:
  `92-daily-branch-context-lock.mdc` §4, §10.
- **AIR-BRC-15** An approval is an event, not a credential: `BranchChangeApprovalService` applies the switch inside the
  approval transaction and re-asserts user, source branch, destination eligibility and clinical day under the row
  lock. Self-approval is refused in that service (a policy clause never runs for Super Admin). Approval authority is
  the `branch-change-request.approve` gate — never invent an `approve_branch_switch` permission. Decision columns stay
  out of `$fillable` and are written with `forceFill`. Source: `92-daily-branch-context-lock.mdc` §6–9.

### Front Office cohort locks

- **AIR-BRC-16** Front Office locks are scoped by an exact cohort, never the role: `FrontOfficeBranchDeviceCohort` is
  the only user-to-branch source, read from `FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT` as `user_id:BRANCH_CODE` pairs,
  ceiling `max_cohort_size` 4, allowlist `TLK1 LDK2 ATG3 SPN4`, `role_wide_permitted` a source-controlled `false`, and an
  empty committed cohort. Never add a second cohort, a `users.branch_id` backfill, a `hasRole('Front Office')` shortcut
  or an exception naming an out-of-scope account. Source: `164-front-office-branch-context-lock.mdc`,
  `92-front-office-branch-device-lock.mdc`.
- **AIR-BRC-17** `BRANCH CONTEXT LOCK != DEVICE PROOF REQUIREMENT` and `DEVICE LOCK IMPLIES BRANCH PIN`. Branch pinning
  asks only `FrontOfficeBranchPinResolver` (either flag); device proof asks only
  `FrontOfficeBranchDeviceLockService` (`front_office.branch_device_lock` only). The branch-context path must never
  reference a device symbol, and `pinningEnabled()` keeps its `OR`. Source: `164-…` (predicate ownership).
- **AIR-BRC-18** The pin lives in `activeContextBranchId()`, the chokepoint shared by `BranchContext`,
  `resolveActiveBranchForAdmin()` and `RmeWorkingBranchScope`. A pin/context conflict and an armed-but-undecidable
  account (duplicate, off-policy code, oversized cohort, retired branch) both resolve to NULL — never to the pin, never
  to a wider fallback. A token naming no user id puts nobody in scope. Source: `164-…` ("The pin must reach EVERY
  branch authority", "Fail closed means no branch").
- **AIR-BRC-19** `StoreClinicVisitRequest::authorize()` refuses a pinned account whose working branch is null, and
  `applyFrontOfficePinnedBranchContext()` forces the branch from `activeContextBranchId()` (never
  `requiredBranchIdFor()`). `isAdminClinicRegistration()` is not an authorization gate, and
  `resolveActiveBranchForAdmin()` is not "has a working branch" (it returns null for doctor and kasir contexts).
  Source: `164-…`.
- **AIR-BRC-20** The pin never outranks the daily lock, and `BranchChangeApprovalService` refuses to move a pinned
  account at request and at approval. Arming an account that already committed today to another branch locks it out
  until the clinical day rolls — a pre-flight check, never a reason to let the pin win. Source: `164-…`.
- **AIR-BRC-21** The device lock is decided only by `FrontOfficeBranchDeviceLockService::evaluate()`; the device
  registry is `mst_doctor_devices` (no second device table); credentials must be device-bound; device identity comes
  only from the server session binding of a completed assertion; enforcement is login, every request
  (`EnsureFrontOfficeDeviceSession`) and branch mutation. Denial audits carry ids and reason codes only. Source:
  `92-front-office-branch-device-lock.mdc`.
- **AIR-BRC-22** Establish the armed cohort only with `php artisan rbac:front-office-branch-pin-audit` (`--json`,
  `--strict` exits 2 on anomaly); it compares the pin, `BranchContext::forUser()` and `resolveActiveBranchForAdmin()`
  per account and has no arming option. Never infer the armed set from the environment string or a production REPL.
  Source: `165-sunu-go-live-readiness.mdc` §5–8.

### Rooms

- **AIR-BRC-23** `mst_clinic_rooms` has `UNIQUE(branch_id, code)` and `UNIQUE(branch_id, name)` that ignore
  `deleted_at`, so every provisioning lookup is `withTrashed()`. The room name is the identity, `code` is assigned
  only on creation, conflicts throw instead of guessing, branches resolve by code through `equivalentCodes()`, and room
  provisioning never creates a branch. `RmeBranchRoomSeeder::CANONICAL_BRANCH_ROOMS` declares Cabang Sunu only.
  Source: `137-branch-room-provisioning.mdc`.
- **AIR-BRC-24** The room is written only by `assignRoom()` (`rme.visits.assign-room`), which checks the room exists,
  is `active` and has `room.branch_id` equal to the visit's server branch. The doctor comes from the room for a
  front-desk registration; any other registrar must name a doctor online at the visit's branch
  (`assertDoctorSelectableForVisit()`, `StoreClinicVisitRequest`).
  Registration never writes `clinic_room_id`, and the visit edit is the allow-list `ClinicVisitService::EDITABLE_FIELDS`.
  Tests must assert the refusal REASON, not only `ValidationException`. Source: `137-…`, `199-…` §14a,
  `200-rme-active-user-patient-selection-cross-branch-control.mdc` §D17–18.

### Accounts, actors and visits

- **AIR-BRC-25** `App\Support\AccessControl\ActiveAccountResolver` is the one definition of a usable account
  (persisted, `is_active`, not soft-deleted, re-read). `EnsureAccountIsActive` stays in the `web` group after
  `EnsureDoctorSessionLease` and before `TouchOnlineContextLastSeen` / `EnsureRmeOnlineContext`; `LoginRequest` uses
  `Auth::attemptWhen` and shows the inactive message only after the password is proven. Any new login path re-checks
  the account. There is no "suspended" state. Source: `200-…` §E19–28.
- **AIR-BRC-26** Never name a middleware method `terminate()` unless it is the terminable hook: the HTTP kernel calls it
  after every response regardless of visibility (it turned every request into a 500). Source: `200-…` §E23.
- **AIR-BRC-27** `ClinicVisitService` takes a non-nullable explicit `User $actor`, re-read by `ClinicVisitActorGuard`
  and checked against `ClinicVisitPolicy`, and contains no `Auth::`, `auth()`, `request()`, `session()` or
  `BranchContext`. Visit aggregates take their branch set from `ClinicVisitService::scopedBranchIdsFor()`, never from
  `BranchService::rmeEnabledIds()`. Source: `199-…` §B–E.
- **AIR-BRC-28** Cross-branch and cross-doctor Kontrol are allowed (OD-1): the prior visit is checked for patient
  identity only, the new visit takes the registrar's working branch, and carry-over receivables are discovered only at
  the paying visit's branch (OD-1a). Source: `200-…` §A–B.
- **AIR-BRC-29** A document's clinic identity comes from the branch that owns the record, never the viewer's context or
  a template literal; a branch with no recorded address prints no address line. Source: `108-…` §8–9.

### Permissions and tests

- **AIR-BRC-30** `RoleSeeder` calls `syncPermissions()` per role, so it removes any permission absent from that role's
  list; inspect the delta before running it on production, seed `PermissionSeeder` first (CG-15), and never edit
  `role_has_permissions` by hand. A role or permission revoke takes effect on the next request. Source: code
  `RoleSeeder`, `200-…` §E26.
- **AIR-BRC-31** HTTP tests for context-bound roles must start a context (`rmeMakeAdminClinicActive`,
  `rmeMakeFrontOfficeActive`, `rmeMakePerawatActive`, `rmeMakeKasirActive` in `tests/Pest.php`) or they 302 to
  `rme.online-context.select`; fixtures asserting visit dates or numbers use `clinicalToday()`. Source:
  `73-…` §7, `108-…` §25–26.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- Daily branch lock is live for the admin-clinic and kasir role contexts (Front Office included).
- `front_office.branch_context_lock` is ON with a cohort of one (the SPN4 front-desk account); the LDK2, ATG3 and
  TLK1 front-desk accounts are not armed — each needs its own supervised ceremony. Production holds eight Front Office
  accounts; only four may ever enter the cohort. Verify with `rbac:front-office-branch-pin-audit`.
- `front_office.branch_device_lock` is OFF (the Sunu desk holds no registered credential); do not turn it on to
  "complete" the lock.
- Doctor `branch_lock` stays OFF in production (rule 199 §18); `doctor.single_active_session` is a separate flag.
- Cabang Sunu (`SPN4`) has active rooms `SPN-A` and `SPN-B`; no doctor is auto-assigned.
- OD-1 / OD-1a are technically enforced. Open owner decision: a per-person branch binding for unpinned front-desk
  accounts (their first daily selection is self-chosen).

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/73-rme-branch-sun4-perawat-online-context.mdc` | Perawat online context, BranchContext order, seeder (registry line superseded) |
| `.cursor/rules/92-telkomas-branch-code-canonical.mdc` | Registry-wide rename mechanics: aliases, parser, fail closed |
| `.cursor/rules/136-sunu-branch-code-canonical.mdc` | Current registry table, Sunu `SPN4`, drift repair |
| `.cursor/rules/92-daily-branch-context-lock.mdc` | Once-per-day branch lock, approvals, concurrency |
| `.cursor/rules/108-clinic-ops-working-branch-context.mdc` | RmeWorkingBranchScope, document identity, examination completion |
| `.cursor/rules/92-front-office-branch-device-lock.mdc` | Four-account Front Office device lock |
| `.cursor/rules/164-front-office-branch-context-lock.mdc` | Branch pin without device proof, chokepoint, fail closed |
| `.cursor/rules/165-sunu-go-live-readiness.mdc` | Pin audit command, read-only proof, canonical checkout |
| `.cursor/rules/137-branch-room-provisioning.mdc` | Room seeder idempotence, room branch boundary |
| `.cursor/rules/199-rme-clinic-visit-branch-authority.mdc` | Explicit actor in ClinicVisitService, fail-closed scope |
| `.cursor/rules/200-rme-active-user-patient-selection-cross-branch-control.mdc` | Inactive accounts stopped, Kontrol OD-1/OD-1a, edit allow-list |
| `.cursor/rules/branch-enforcement.mdc` | General branch-isolation checklist and required tests |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/03-branch-context.md`. Grep by sprint id; never read a whole
file.
