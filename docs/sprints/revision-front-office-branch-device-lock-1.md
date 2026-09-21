# REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1

**Branch** `revision/front-office-branch-device-lock-1`
**Base** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `e41279b3` (= the immutable
`doctor-device-guided-registration-workflow-1-go` tag; not reopened, not moved)
**Status** capability shipped INERT — enforcement OFF, cohort EMPTY
**Migration** none
**New permission** none
**New table** none

A branch-device login lock for **exactly four** named Front Office accounts. Not for the Front Office role.

---

## 1. What the audit found, before any code

### 1.1 `Front Office` is a real role, and it is not the scope

Production carries **eight** accounts holding the seeded `Front Office` role. The owner approved **four**. So
`hasRole('Front Office')` is the wrong scope by a factor of two — and the four it would have wrongly locked include
user 7, who selected a branch and worked on the day this was written.

Scope is therefore a **cohort of user ids**, each paired with the branch it is pinned to.

### 1.2 The device registry is already generic

`mst_doctor_devices` calls itself a *"clinic device registry"* in its own class header. It is branch-owned hardware:
`branch_id` → `Branch`, with `status`, `identity_state` and `enrollment_status` lifecycles. **It has no doctor
column.** So the device authority and the device→branch ownership this sprint needs already existed, and nothing new
was created.

### 1.3 But every session BINDING was doctor-shaped

A session becomes device-bound only through `DoctorDeviceSessionService::bind()`, reachable from two paths — Android
ticket redemption and browser WebAuthn assertion — and both required a `mst_doctor_device_authorizations` row whose
`doctor_id` is an FK to `mst_doctors`. `DoctorAppLoginGate::appliesTo()` is hard-wired `hasRole('Doctor')`.

There was no device cookie, no `X-Device-*` header, no device token, and no non-doctor device table.

**Consequence, reported to the owner before implementing:** taken literally, the requirement would have denied all
four accounts on every login, permanently — a front-desk lockout at four branches.

### 1.4 The resolution: the credential never named a clinician

`trx_doctor_device_webauthn_credentials` has **no doctor column**. It is keyed by `doctor_device_id`, and the WebAuthn
user handle it stores is the **device uuid**. The credential has always identified the tablet.

The doctor-shaped lookup (`usableForDoctor()` → authorization → device → credentials) was only the doctor programme's
way of asking *"which tablets may this clinician use?"*. A front-desk account answers that question from its pinned
branch instead. One substitution, no new storage:

```
Doctor path:  doctor  -> authorization -> device -> credentials
This path:    branch  -> approved devices        -> credentials
```

`usableForDevice(int $doctorDeviceId)` already existed on the canonical repository interface, so not even a repository
method was added.

### 1.5 Enrolment needs no new path either — a correction

This sprint initially assumed the credential-registration ceremony was doctor-gated. **It is not.**
`settings.doctor-devices.webauthn.*` registers a credential onto a **device** and authorizes the actor through the
`DoctorDevice` policy; no doctor record is involved. An administrator standing at the front-desk tablet enrols it
exactly as they would a clinical one.

So the activation prerequisite is an operational step on real hardware, not missing code.

---

## 2. Accounts and branches, resolved read-only against production

Resolved 2026-09-21 by `SELECT` only. Each owner alias matched **exactly one** account.

| Alias | `users.id` | Email | Role | `users.branch_id` |
|---|---|---|---|---|
| Admin Sunu | **29** | adminsunu@daengtisia.com | Front Office | NULL |
| Admin Landak | **30** | adminlandak@daengtisia.com | Front Office | NULL |
| Admin Antang | **31** | adminantang@daengtisia.com | Front Office | NULL |
| Admin Telkomas | **32** | admintelkomas@daengtisia.com | Front Office | NULL |

**Out of scope, and must stay unchanged:** users **7** (Yuni FO), **8** (Dhea), **16** (Dhea Putri),
**17** (Maghfirah Abdullah).

**Two branch codes in the original brief were stale.** Verified against `mst_branches`:

| Branch | id | Actual code | Brief said |
|---|---|---|---|
| Cabang Telkomas | 1 | **TLK1** | ~~TKM1~~ |
| Cabang Landak | 2 | LDK2 | LDK2 |
| Cabang Antang | 3 | ATG3 | ATG3 |
| Cabang Sunu | 5 | **SPN4** | ~~SUN4~~ |

`MAIN` is id 4 and **not** RME-enabled — it is the fallback a branchless account lands on, and it can never satisfy
the lock. Production also already holds exactly one active, cryptographically-verified device per target branch:
device 3 (SPN4), 5 (LDK2), 6 (ATG3), 7 (TLK1).

**Live-use warning:** user 29 selected SPN4 and worked on 2026-09-21 (04:32–05:13). Users 30/31/32 have never
selected a context. Arm 29 **last**.

---

## 3. What was built

| File | Role |
|---|---|
| `config/front_office_device_lock.php` | Committed **policy** (scope ceiling) + env-supplied **cohort**, empty by default |
| `app/Support/AccessControl/FrontOfficeBranchDeviceCohort.php` | Fail-closed cohort parser |
| `…/FrontOfficeDevice/Support/FrontOfficeDeviceLockDecision.php` | Outcome + reason + safe audit context + Indonesian message |
| `…/FrontOfficeDevice/Services/FrontOfficeBranchDeviceLockService.php` | **The one place the lock is decided** |
| `…/FrontOfficeDevice/Services/FrontOfficeDeviceWebAuthnLoginService.php` | Device-scoped assertion ceremony |
| `…/FrontOfficeDevice/Services/FrontOfficeDeviceSessionService.php` | Audit-then-teardown |
| `…/FrontOfficeDevice/Middleware/EnsureFrontOfficeDeviceSession.php` | Per-request revalidation |
| `…/FrontOfficeDevice/Controllers/FrontOfficeDeviceWebAuthnLoginController.php` | Thin ceremony endpoints |
| `resources/views/auth/front-office-device-webauthn.blade.php` | "Touch the tablet" page |

Modified: `AuthenticatedSessionController` (one gate call), `bootstrap/app.php` (global middleware),
`UserOnlineContextService` (branch-switch refusal), `BranchContext` (branch pin), `routes/web.php`,
`config/feature_flags.php`.

### 3.1 The decision

`FrontOfficeBranchDeviceLockService::evaluate()` returns exactly one of:

`NOT_IN_SCOPE` · `ALLOW` · `DENY_UNKNOWN_DEVICE` · `DENY_DEVICE_NOT_APPROVED` · `DENY_DEVICE_BRANCH_MISMATCH` ·
`DENY_ACCOUNT_BRANCH_INVALID` · `DENY_BRANCH_INACTIVE`

Approved means all three of `isActive()` + `isCryptographicallyVerified()` + `isEnrollmentVerified()`, composed from
the model's own predicates. The credential-level refusals (no credential, unknown, revoked, wrong device) land in
`DENY_UNKNOWN_DEVICE` by construction: a failed assertion never writes a binding.

### 3.2 Enforcement is three-layer, never a hidden menu

1. **Login** — `AuthenticatedSessionController`, one call, immediately after the doctor gate. A denial **destroys the
   session**; it does not redirect a privileged session to a narrower page.
2. **Every request** — `EnsureFrontOfficeDeviceSession`, registered globally. A device revoked, disabled,
   re-branched, or an account removed from the cohort mid-session ends that session on its next request.
3. **Branch mutation** — `UserOnlineContextService::assertFrontOfficeBranchLock()`, on the **mutation** rather than
   the route, so a crafted `POST online-context/admin-clinic` is refused too; plus `BranchContext::forUser()` pins the
   armed account's branch **ahead of** the online context, so a context row selected before arming cannot widen it.

Nothing anywhere reads a branch from the request. The submitted `branch_id` is only ever the value being *checked*.

### 3.3 Fail-closed configuration

| Cohort state | Result |
|---|---|
| id absent | `NOT_IN_SCOPE` — untouched, and that is the point |
| id present, duplicated (even agreeing) | **DENY** — ambiguous |
| id present, branch code off-policy | **DENY** |
| cohort larger than `max_cohort_size` (4) | **DENY for every armed id** — scope creep guard |
| token naming no id (`abc:SPN4`) | recorded as a config error; puts **nobody** in scope |
| armed id whose role is not Front Office | `NOT_IN_SCOPE` — a typo must not lock a doctor |

`role_wide_permitted` is a source-controlled `false`, unreachable from the environment.

---

## 4. Evidence

`tests/Feature/FrontOfficeDevice/` — **59 tests, 193 assertions, all passing.**

- `FrontOfficeBranchDeviceLockTest` (40) — the owner's matrix 1–30, each denial mirrored by an assertion that a
  non-cohort account or another role was untouched.
- `FrontOfficeDeviceCeremonyScopeTest` (12) — what the ceremony may offer and what it refuses.
- `FrontOfficeDeviceLoginCeremonyTest` (7) — **end to end with real EC keys** through the real endpoints: correct
  tablet admits, wrong-branch tablet refused, revoked credential refused, **forged signature refused**, **replay
  refused**, ceremony without the password step refused, branch pinned after a real login.

Two test expectations were corrected rather than the code:

- **A branchless device cannot exist.** `mst_doctor_devices.branch_id` is a NOT NULL constrained FK, so the database
  refuses it one layer below the lock. The requirement is satisfied lower than expected; the null handling stays as a
  defensive belt and the test now pins the schema guarantee that makes it unreachable.
- **A successful ceremony login legitimately writes one denial row** — the password step ran before any assertion
  existed, so the account genuinely had no bound device at that moment. The test now asserts that the only denial is
  `DENY_UNKNOWN_DEVICE` and that no branch or approval denial appears.

---

## 5. Controlled activation (NOT performed)

Enforcement ships **OFF** and the committed cohort is **EMPTY**, so a deploy arms nobody. Per-account arming is
native: the cohort is a list, so arming one account adds one pair.

Recommended order — **Admin Sunu LAST**, because it is the only one in live use:
Landak (30) → Antang (31) → Telkomas (32) → Sunu (29).

For each account:

1. Confirm the user id and required branch code against production.
2. Identify the real branch-owned device (`mst_doctor_devices`, active + cryptographically verified + enrolled).
3. At **that** tablet's browser, enrol a credential via `settings.doctor-devices.webauthn.*` (existing path, admin
   authorized by the `DoctorDevice` policy). The credential must be **device-bound**, not a syncable passkey.
4. Prove credential → device → branch by reading back `trx_doctor_device_webauthn_credentials.doctor_device_id` and
   that device's `branch_id`.
5. Arm only that account:
   `FEATURE_FRONT_OFFICE_BRANCH_DEVICE_LOCK=true`, `FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT=<id>:<CODE>` →
   `php artisan config:clear`.
6. Real correct-device login; confirm the branch context is the pinned branch; confirm a branch switch is refused
   server-side; confirm revocation ends the session.
7. Only then arm the next account.

**Rollback** — remove that one pair from the cohort and clear the config cache; the other armed accounts are
unaffected. Or set the flag false to disarm the capability entirely. No data changes either way.

**Never** move a device's branch, revoke a real device, or create a credential to manufacture evidence.

---

## 6. Durable rules

1. The lock is scoped by an **exact user-id cohort**, never by the `Front Office` role. Adding a fifth account
   requires an explicit owner scope update, and exceeding four fails closed.
2. The other four Front Office accounts, and every other role, are unchanged whether the flag is on or off.
3. Device identity is **server-side only**: a WebAuthn credential resolving to `mst_doctor_devices`. Never a cookie,
   header, localStorage value, hidden input or submitted `branch_id`.
4. The device's branch is `mst_doctor_devices.branch_id`. Both sides of the comparison are server-side facts.
5. A denial **destroys the session**. Hiding a menu is presentation, not a boundary.
6. The branch lock is enforced on the **mutation** (`UserOnlineContextService`) and in resolution
   (`BranchContext::forUser`), so no route, view or future caller can widen it.
7. Configuration that cannot be trusted **denies** the armed accounts and never widens scope.
8. `env()` appears only in config; the runtime is deterministic under `config:cache`.
9. Audit records ids, codes and a reason code only — never a password, credential, token, cookie or biometric value.
10. No new device table, and no generalization of doctor-device authorization storage.
11. A GO on this sprint authorizes the lock for **these four accounts only**. It does not authorize role-wide Front
    Office enforcement, automatic onboarding of future accounts, or device locks for other roles.
