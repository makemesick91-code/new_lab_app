# REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1

Branch `revision/front-office-branch-context-lock-1`
Base `d1f4d7c0` — the immutable `revision-front-office-branch-device-lock-1-go`
tag, unmoved and unmodified.

Locks the server-side branch context for exactly four Front Office accounts,
**without requiring any device or WebAuthn enrolment**.

---

## 1. What was already there, and why it was not enough

The branch-context enforcement this revision was asked for already existed. It
shipped with `REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1`: a pin in
`BranchContext::forUser()` ahead of the online context, and a refusal in all four
`UserOnlineContextService::start*Session()` mutations.

All of it hung off ONE predicate — `FrontOfficeBranchDeviceLockService::appliesTo()`
— which gates on the **device** flag:

```
requiredBranchIdFor() -> appliesTo() -> enforcementEnabled() -> front_office.branch_device_lock
```

So the only way to obtain a branch pin was to arm the device lock, and arming the
device lock also demands a WebAuthn assertion from the browser. For a front desk
holding no registered credential that is a lockout at four branches.

**This revision is therefore a decoupling, not a rebuild.**

## 2. The two named invariants

```
BRANCH CONTEXT LOCK != DEVICE PROOF REQUIREMENT
DEVICE LOCK IMPLIES BRANCH PIN
```

```
branchPinApplies(user)    = in cohort AND (context_lock ON OR device_lock ON)
deviceProofRequired(user) = in cohort AND device_lock ON
```

The second invariant is load-bearing, not a convenience. The already-GO device
capability promised that an armed account's branch context is pinned so the
selector cannot widen it. Reading only the context flag in `pinningEnabled()`
would retract that promise by omission for any deployment that armed the device
lock and left the context flag off.

### Behaviour matrix (all four cells tested)

| context | device | branch pinned | WebAuthn required |
|---|---|---|---|
| OFF | OFF | no — existing behaviour | no |
| ON  | OFF | **yes** | **no** |
| OFF | ON  | yes | yes |
| ON  | ON  | yes | yes |

## 3. What changed

| File | Change |
|---|---|
| `app/Support/AccessControl/FrontOfficeBranchPinResolver.php` | **New.** The pin predicate. Reads the shared cohort, ORs both flags, touches no device row. |
| `app/Modules/Branch/Services/BranchContext.php` | Pin tier now asks the resolver; adds the fail-closed branch for an armed-but-undecidable account. |
| `app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php` | `assertFrontOfficeBranchLock()` asks the resolver; **`activeContextBranchId()` narrows to the pin** (see §4b); `hasSatisfiedContext()` returns false for a conflicting pinned context. |
| `app/Modules/RmeOnlineContext/Services/BranchChangeApprovalService.php` | An approval cannot move a pinned account off its branch (§4b). |
| `app/Modules/RmeOnlineContext/Controllers/OnlineContextController.php` | Narrows the offered branch list to the pin. |
| `resources/views/rme/online-context/select.blade.php` | Pin outranks the daily lock in the offered list; "Cabang terkunci" notice. |
| `config/feature_flags.php` | New `front_office.branch_context_lock`, default **false**. |
| `.github/workflows/foundation-evidence-gates.yml` | Adds the `FrontOffice` token — see §7. |

**No migration. No second cohort. No new table. No new permission. No new route.**

After the change the branch-context path references **zero** device symbols, and
the device path (login controller, `EnsureFrontOfficeDeviceSession`, WebAuthn
controller/services) is untouched. The separation is structural, not documented.

## 4. Scope — the cohort, never the role

`FrontOfficeBranchDeviceCohort` remains the ONLY user-to-branch source of truth:
one env key, ceiling of four, allowlist `TLK1 LDK2 ATG3 SPN4` — all four verified
against production `mst_branches` on 2026-09-22.

Verified read-only against production the same day:

| Alias | user_id | branch | id | active + RME |
|---|---|---|---|---|
| Admin Sunu | 29 | SPN4 | 5 | yes |
| Admin Landak | 30 | LDK2 | 2 | yes |
| Admin Antang | 31 | ATG3 | 3 | yes |
| Admin Telkomas | 32 | TLK1 | 1 | yes |

Exactly one active, non-deleted user per alias. Production carries **eight** Front
Office accounts; **7 (Yuni FO), 8 (Dhea), 16 (Dhea Putri), 17 (Maghfirah Abdullah)**
are out of scope and unchanged — because they are ABSENT from the cohort, not
because anything names them. There is no allowlist exception for them anywhere.

`users.branch_id` was considered as the canonical model and rejected: it sits
BELOW the online context in `BranchContext`, so setting it cannot stop a branch
switch. It would have been decorative.

Today users 30/31/32 hold `branch_id` NULL and no online context, so they resolve
to **MAIN — which is not RME-enabled**. The pin fixes that; it only ever narrows.

## 4b. The pin had to reach EVERY branch authority, not just BranchContext

**Found by adversarial security review, reproduced as a failing probe before it
was fixed.** The first implementation pinned only `BranchContext::forUser()`.
That was not enough, and the sprint's own claim that the pin "can only ever
NARROW" was false as written:

`BranchContext` is not the only authority on an operator's working branch.
`UserOnlineContextService::resolveActiveBranchForAdmin()` decides the branch a
**new clinic visit is registered at**, and `RmeWorkingBranchScope` decides what
the workspace lists. Both read `activeContextBranchId()` directly.

Measured on the stale-context fixture (armed to SPN4, holding a pre-arming
context row on LDK2):

| authority | before the fix | after |
|---|---|---|
| `BranchContext::forUser()` | SPN4 (pinned) | SPN4 |
| `resolveActiveBranchForAdmin()` | **LDK2** | `null` |
| `RmeWorkingBranchScope::branchIdsFor()` | **[LDK2]** | `[]` |

A split brain: the pin read as narrowing while clinical records were still being
created on the wider branch.

**Fix:** narrow inside `activeContextBranchId()` — the one chokepoint both
bypass paths share — so every current and future consumer inherits it, the same
reasoning already used to place `assertFrontOfficeBranchLock()` on the mutation
rather than the route.

**A conflict resolves to NULL, not to the pin.** Returning the pin would
resurrect a working context the daily branch lock had already decided, handing an
armed account a same-day branch move that requires Super Admin approval. Null is
also safe for registration specifically: `StoreClinicVisitRequest::authorize()`
refuses outright when this is null, rather than falling back to a form-supplied
`branch_id`. `hasSatisfiedContext()` was made pin-aware too, so the operator is
returned to the selector instead of silently carrying a context that resolves to
nothing.

Two further review findings closed with it:

- `BranchChangeApprovalService` is the only writer of an online context's
  `branch_id` outside the four `start*Session()` methods. It now refuses, at
  request AND at approval, to move a pinned account off its branch.
- The selector narrowed with `requiredBranchIdFor()` alone, so an armed account
  with an UNDECIDABLE mapping fell through to the **full** RME branch list while
  the server refused every one of them. It is now handed an empty list.

These three files were unchanged by the original diff — the gap was inherited
from the device-lock sprint, where it was unreachable without a WebAuthn
ceremony. Arming by config alone made it reachable.

## 4c. Second review round — two more findings, one of them mine

**Finding A — a FALSE SAFETY CLAIM in my own production docblock.** The first
version of the chokepoint comment asserted that null was safe for registration
because *"`StoreClinicVisitRequest` refuses authorization outright when this
returns null."* That was wrong on both clauses, and I had written it after
misreading the code:

- `StoreClinicVisitRequest::authorize()` returns `true` **unconditionally**.
- The `resolveActiveBranchForAdmin(...) !== null` I had taken for the
  authorization gate is `isAdminClinicRegistration()` — a private helper that
  only decides whether to APPLY the context branch. On null it early-returns and
  the **form's own `branch_id` survives**, validating against any RME-enabled
  branch.

So on null the mutation path trusted the form. That is **wider** than the bug
this sprint fixed: the stale-branch bug forced one wrong branch, this would have
allowed any. It was not exploitable — `hasSatisfiedContext()` is pin-aware and
`EnsureRmeOnlineContext` intercepts first — but that made a route guard the only
thing between a crafted POST and the write, while the comment told a future
maintainer a second, independent guard existed. Anyone trusting that line could
have deleted the pin-awareness as redundant and silently reopened the hole.

Fixed twice over: the comment now names the real guards, and
`StoreClinicVisitRequest::authorize()` now genuinely refuses a pinned account
whose working branch is null — so the refusal lives where the write happens, as
this module's own rule requires. A test covers the **store** path with the
middleware deliberately removed; the control asserts a non-cohort account still
reaches validation (422, not 403).

**Finding B — the Finding-3 fix was half-applied.** The controller computed
`frontOfficePinMisconfigured` and handed the view an empty list, but the blade
never read the flag and recomputed its own list from the full collection. A
misconfigured armed account **holding a daily context** was therefore still
offered the daily-locked branch, which the server refuses. The blade now honours
the flag, the misconfigured case outranks `$dailyLocked`, and there is a visible
refusal instead of an empty `<select>`.

Also tightened: the approval guard used `User::find()`, which returns null for a
soft-deleted requester and then silently skipped — a fail-open shape in a guard.
It now uses `withTrashed()` and refuses when the requester cannot be loaded.

## 4d. Third review round — Finding C, a FALSE 403 on visit registration

The `authorize()` guard added for Finding A keyed off `resolveActiveBranchForAdmin()`,
which answers for the **admin_clinic and perawat contexts only** and returns null
for a DOCTOR context even when the working branch is resolvable and equals the pin.

So a cohort account also holding the `Doctor` role, online as a doctor **at its own
pinned branch**, passed the middleware and was then refused registration outright.
Reproduced (403 instead of 422) before fixing. Low likelihood — being online as a
doctor needs a linked `mst_doctors` record, and a front-desk clerk normally holds
none — but a false 403 on visit registration is a clinical-scale outage, not a leak.

**Fixed by keying off `activeContextBranchId()`** — the pin-narrowed,
daily-lock-aware chokepoint — so a non-null answer already means "this operator has
a working branch AND it is the pinned one".

That alone would have been **wrong**, and the trap is worth recording: with
`authorize()` passing, `applyAdminClinicBranchContext()` still early-returns for a
doctor context, so the form's `branch_id` would have survived — reopening the
widening Finding A closed. New `applyFrontOfficePinnedBranchContext()` therefore
overwrites `branch_id` for any pinned account from `activeContextBranchId()` —
deliberately NOT from `requiredBranchIdFor()`, which would bypass the daily lock.

**A coverage lesson.** The first test for that merge used an admin_clinic context
and the mutant SURVIVED: `applyAdminClinicBranchContext()` already overwrites a
crafted branch there, so the test passed with and without the new code. The real
property only shows on a context that helper does not answer for, and it is now
asserted on the FormRequest directly — a Doctor-role account is additionally
narrowed by the clinical patient scope, which would have failed the request for an
unrelated reason and proved nothing about the branch.

## 5. Fail closed means "no branch", not "no pin"

An ARMED but UNDECIDABLE account — duplicate entry, off-policy code, oversized
cohort, or a pinned branch that lost `is_active`/`is_rme_enabled` — resolves to
**NULL**, and the selection guard refuses every branch.

The device layer could treat a null as "do not pin" because such an account had
already been denied login by `evaluate()`. The branch-context layer is deliberately
not allowed to deny a login, so falling through would hand the account its online
context, its `users.branch_id`, or MAIN — every one WIDER than intended. That is
fail OPEN, and `isMisconfiguredFor()` exists to prevent it.

A token naming no user id (`abc:SPN4`) is a config error and puts NOBODY in scope.

## 5b. The pin does not outrank the daily branch lock

The pin and `FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1` are independent narrowing
rules, and the pin deliberately does not win — otherwise arming an account would
grant it a same-day branch move that the daily lock requires Super Admin approval
for. A test pins this.

The consequence is operational: arming an account mid-day, when it has already
committed today to a DIFFERENT branch, leaves it unable to select anything until
the clinical day rolls over. Verified 2026-09-22 that no approved account is in
that state — 30/31/32 hold no daily context, and 29's context for today is
already SPN4 — but the runbook pre-flight checks `trx_daily_branch_contexts`
because the answer changes daily.

## 6. Evidence

`tests/Feature/FrontOfficeDevice/FrontOfficeBranchContextLockTest.php` — 20 tests.
Truth-table cells A–E, the two decoupling regressions, the device non-regression,
cohort safety (duplicate / off-policy / oversized / malformed / retired branch),
stale-context and static-`branch_id` narrowing, other roles, and the selector.

**Mutation-tested — the suite was proven to fail before it was trusted:**

| Mutant | Killed by |
|---|---|
| `pinningEnabled()` reads only the device flag (the pre-sprint defect) | cell B + 8 others |
| `pinningEnabled()` reads only the context flag | cell C — exactly the right test |
| fail-closed branch removed from `BranchContext` | 14a / 14b / 14e |

- New suite: 20 passed (sqlite) / 20 passed (PostgreSQL 16.14)
- `--filter=FrontOffice`: 112 passed on both sqlite and PostgreSQL 16.14
- Branch/context/permission regression: see §8

## 7. A CI gap this sprint found and closed

`FrontOfficeBranchDeviceLockTest` — the already-GO device capability's suite —
matched **no token** in the critical gate's filter allowlist, so it had never run
in CI. The new suite would have had the same fate.

Adding the single token `FrontOffice` brings **112 tests** into the critical gate
that were not running before. This is reported rather than quietly fixed because
it means the device capability's GO was taken without its own suite in CI.

## 8. Activation is NOT part of this closure

Both flags ship OFF with an EMPTY committed cohort, so deployment is inert.
Arming is a separate, supervised, per-account ceremony — see
`docs/runbooks/front-office-branch-context-lock-activation-runbook.md`.

Order: **Landak (30) → Antang (31) → Telkomas (32) → Sunu (29) last**, because
user 29 is in live daily use and held an online SPN4 context on 2026-09-21.
