# DOCTOR-ACCESS-GLOBAL-ACTIVATION-1 — PHASE-0 DELTA RECHECK

**Child sprint:** `DOCTOR-ACCESS-GLOBAL-ACTIVATION-PHASE0-DELTA-RECHECK-1`
**Branch:** `audit/doctor-access-global-activation-phase0-delta-recheck-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (never `main`)

> **`GLOBAL_ACTIVATION_APPLY_AUTHORIZED = NO`. `ACTIVATION_OCCURRED = NO`.**
>
> A GO here means the delta recheck is complete and truthful. It is **not** the
> owner's APPLY approval, it arms no flag, widens no cohort, releases no lease and
> moves no governance phase. The next step is a separate human decision:
> `APPROVE_DOCTOR_GLOBAL_ACTIVATION_APPLY=YES/NO`.

---

## 1. Authority (§2)

| | |
|---|---|
| `MAIN_SHA` | `c50645fb8828fb5d1c1b20dd6801331125369556` |
| `MAIN_TREE` | `e5f71fd6096eb8f9d60ce8bce66252a173d3f109` |
| `PRODUCTION_HEAD` | `55830629218a5ca08c8643e41dd6dfe964088580` |
| `PRODUCTION_TREE` | `a9b92cb31cd44925e25e83f4f64bd99a95d8c09a` |
| `PROVISIONING_GO_TAG_TARGET` | `55830629218a5ca08c8643e41dd6dfe964088580` |
| `PROVISIONING_GO_TAG_TREE` | `a9b92cb31cd44925e25e83f4f64bd99a95d8c09a` |
| `git describe --exact-match HEAD` (on VPS) | `doctor-access-trusted-device-estate-provisioning-1-go` |
| Production working tree | clean except untracked `storage/runtime-home/` |

`main` is an **ancestor** of production (merge-base = `main`), so production is ahead
of `main` and nothing on `main` is missing from it. The audit ran in an isolated
worktree checked out at `55830629` — every source claim below is a claim about the
bytes production is executing.

### 1.1 A local ref that lies — read this before checking ancestry

`git rev-parse feature/sprint-26-…` resolves **locally** to `9eabff07` (2026-09-09),
ten commits behind. `origin/feature/sprint-26-…` resolves to `55830629`. An ancestry
check against the stale local ref reports all four prerequisite tags as *not*
ancestors, which is false. **Fetch first; resolve against `origin/…` or against the
production head itself.**

### 1.2 Prerequisite GO authorities (§1A–1D)

All four are in the ancestry of the current production head.

| Tag | Tag object | Target | Tree | Valid |
|---|---|---|---|---|
| `doctor-access-fleet-rollout-readiness-1-go` | `d7d29f18` | `023e30cd` | `f45e6ddd` | **YES** |
| `doctor-access-global-activation-blocker-closure-1-go` | `405db91d` | `00232507` | `76619219` | **YES** |
| `revision-doctor-trusted-device-estate-capacity-policy-1-go` | `108101d5` | `a2caf476` | `22ff2964` | **YES** |
| `doctor-access-trusted-device-estate-provisioning-1-go` | `0ff910a5` | `55830629` | `a9b92cb3` | **IS the head** |

---

## 2. Current activation posture (§4) — every value re-read from its own source

| Signal | Value | Source |
|---|---|---|
| `doctor.single_active_session` | **false** | `foundation:feature-flags`, env-resolved |
| `doctor.branch_lock` | **true** | env-resolved |
| `doctor.trusted_device_enforcement` | true | env-resolved |
| `doctor.pwa_webauthn_device_login` | true | env-resolved |
| `SESSION_STORE_OBSERVABLE` | **YES** — driver `database`, `sessions` present | `IncumbentSessionProbe::observable()` |
| `BRANCH_LOCK_EFFECTIVE` | **false** | §4 |
| `CURRENT_ENFORCEMENT_SCOPE_MODE` | `pilot` | `AndroidDoctorEnforcementScope::mode()` |
| `CURRENT_SCOPE_IDS` | **`[9, 15, 18]`** | resolved union |
| `SCOPED_BROWSER_DEVICE_ENFORCEMENT_ACTIVE` | **YES** — 3 denied, 12 allowed | `android:phase4a-pilot-scope` |
| `GLOBAL_PERMITTED_EFFECTIVE` | **false** | source-controlled |
| `GOVERNANCE_PHASE` | `phase_4a` | `config/android_release.php:1225` |
| `GLOBAL_FLEET_ENFORCEMENT_EFFECTIVE` | **false** | `globalEnforcementActiveLive()` |
| `authorizes_activation` | literal `false` | estate + fleet engines |

---

## 3. HALF A — fleet-wide, and the correction that matters (§8)

```
HALF_A_COHORT_SUPPORT   = NO
HALF_A_ACTIVATION_SCOPE = ALL_ELIGIBLE_DOCTORS
HALF_A_FLEET_WIDE       = YES
```

A grep for `cohort|pilot_scope|scope_ids|enforcement_scope|in_array.*user` across all
four arming-path files returns **`NO_COHORT_REFERENCES_FOUND`**. Registration is
unconditional in both directions:

- listener — `AppServiceProvider.php:149`, `Event::listen(Login::class, ClaimDoctorSessionLease::class)`, no flag or cohort condition
- middleware — `bootstrap/app.php:82`, appended to the **global `web` group**, not a route group and not an allowlist
- `grep -rn 'AndroidDoctorEnforcementScope|DeviceEnforcement|deviceGate' app/Modules/DoctorAccess/` → **zero hits.** The Phase-4A cohort is not consulted, directly or transitively

```php
// DoctorSessionLeaseService.php:153 — the whole population predicate
public function subjectTo(User $user): bool { return $user->hasRole('Doctor'); }

// DoctorSessionLeaseService.php:140
public function enabled(): bool
{
    return $this->flags->enabled(self::FLAG) && $this->probe->observable();
}
```

Three filters narrow the path, and **none is a cohort**: the guard must be `web`
(`ClaimDoctorSessionLease.php:66`), the request must have a session (`:84`), and
`login`/`logout` are exempt (`EnsureDoctorSessionLease.php:76`).

### 3.1 CORRECTION — "simultaneously" was imprecise

An adversarial review refuted the wording, and the correction changes the activation
plan. **Half A is fleet-wide in POPULATION but split in EFFECT, and the two halves of
its effect land at different moments:**

| Effect | When it lands |
|---|---|
| **Branch narrowing** — list scope + write assertion | **Immediately, fleet-wide.** `RmeWorkingBranchScope:153` and `ClinicVisitService:496` call `branchIdFor()` on every request; the instant `enabled()` flips, an already-logged-in doctor's lists narrow to their home branch and cross-branch writes are refused. |
| **Lease enforcement / session eviction** | **At each doctor's NEXT login.** `EnsureDoctorSessionLease.php:87-89` lets a session holding no lease token through forever, and leases mint only on the `Login` event. Nobody already logged in is evicted. |

So arming Half A **narrows every doctor's branch at once and evicts nobody.** Do not
plan the window as a mass logout, and do not expect to verify second-login denial on
a session that predates the flip.

### 3.2 CORRECTION — the lease population is WIDER than the branch-lock population

`DoctorEffectiveBranchResolver.php:138` gates on `requiresDoctorContext()` =
`hasRole('Doctor')` **AND NOT exempt** (Owner / Super Admin / Supervisor RME).
`subjectTo()` carries no such exemption. **A governance account holding the Doctor
role is subject to the session lease while being exempt from the branch lock.**

### 3.3 Not a pilot, not a wave (§33)

There is no staged mechanism, no cap and no cohort. `FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION=true`
is a **fleet-wide cutover**. It must never be described as a pilot, a wave or gradual
enablement unless source later implements one.

---

## 4. HALF A — effective branch predicate (§9)

```
BRANCH_LOCK_EFFECTIVE_PREDICATE = branch_lock AND single_active_session AND sessionProbe->observable()
BRANCH_LOCK_FLAG           = true
SINGLE_ACTIVE_SESSION_FLAG = false
SESSION_STORE_OBSERVABLE   = true
BRANCH_LOCK_EFFECTIVE      = false      (activation target: true)
```

```php
// DoctorEffectiveBranchResolver.php:98-103
public function enabled(): bool
{
    return $this->flags->enabled(self::FLAG_BRANCH_LOCK)
        && $this->flags->enabled(self::FLAG_SINGLE_ACTIVE_SESSION)
        && $this->sessionProbe->observable();
}
```

The 15 home locks on production are **real data behind an inert gate**. The branch
lock is not a second decision taken after Half A — it arms in the same instant.

Every narrowing consumer was traced and all of them go through the resolver: the RME
list scope (`RmeWorkingBranchScope.php:153,191`), the visit write assertion
(`ClinicVisitService.php:496`), the branch selector (`UserOnlineContextService.php:599`),
both lease hooks, and the admin screen (behind `assertCapabilityArmed()` on all 12
public methods). No policy, query scope, selector or middleware reads a lock or cover
directly to narrow.

> **Refuted sub-claim, recorded for honesty.** The resolver's own docblock asserts
> there is *exactly one* implementation of "is a cover active". There are **four** —
> `DoctorBranchCover::coversInstant()`, the pinned SQL twin in
> `DoctorBranchCoverRepository`, `DoctorBranchCoverState` and `DoctorBranchCoverPeriod` —
> and two consumers call `coversInstant()` without consulting `enabled()`
> (`DoctorEstateResilienceRepository.php:107`, `DoctorBranchCoverApprovalService.php:449`).
> Neither narrows a doctor's branch — one is a read-only report, the other an approval
> decision — so the inertness claim survives. The "one implementation" premise does not.

---

## 5. HALF B — scoped is not global (§10)

```
CURRENT_ENFORCEMENT_SCOPE_MODE = pilot
CURRENT_SCOPE_IDS              = [9, 15, 18]
GLOBAL_PERMITTED_SOURCE        = config/android_release.php → enforcement.scope.global_permitted
GLOBAL_PERMITTED_EFFECTIVE     = false
GOVERNANCE_PHASE               = phase_4a
GLOBAL_FLEET_ENFORCEMENT_EFFECTIVE = false
```

**The distinction, exactly as source draws it.** These are two different modes, not
two sizes of one:

```php
// AndroidDoctorEnforcementScope.php
MODE_PILOT    => in_array($userId, $this->pilotDoctorUserIds(), true)   // :428
MODE_UNSCOPED => true                                                    // :429
isUsable():  MODE_PILOT => cohort !== []   |   MODE_UNSCOPED => globalPermitted()  // :348-355
globalPermitted(): config('android_release.enforcement.scope.global_permitted') === true  // :337-340
```

A pilot scope naming many doctors is **still `pilot`**. `globalEnforcementActiveLive()`
(`Phase4aPilotPreparationScanner:897-902`) requires **unscoped mode AND
`globalPermitted()` AND the flag armed** — three conditions, and a large cohort
satisfies none of them.

**The enumeration attack is closed, and it fails safe.** `pilot_cohort_maximum = 5`
(`config/android_release.php:1529`) is read from the source-controlled file and falls
back to `1` if unreadable. A cohort larger than the maximum returns
`ids => [], exceeded => true` (`:251-253`), so `isUsable()` is false and the scope
covers **NOBODY, not everybody**, tripping `pilot_cohort_exceeds_reviewed_maximum`.
Enumerating all 15 doctors enforces zero of them.

### 5.1 CORRECTION — "requires a reviewed source change" is a PROCESS property

`globalPermitted()` reads `config()`, which resolves from the **deployed file or
`bootstrap/cache/config.php`** — not from git. Nothing compares the live value to the
committed one, and the auditor reads the same `config()` (`Phase4aPilotPreparationScanner:426`),
so a host-side edit of the deployed config arms global enforcement *and* the gate
reports it consistently. **There is no drift detection.**

The accurate, defensible statement is narrower and was verified exhaustively:

> `global_permitted` is **not reachable from an environment variable, a database row,
> or a runtime write.** `config/doctor_device_enforcement.php` has no such key;
> `grep "config()->set|Config::set"` across `app/ routes/ bootstrap/ database/` returns
> **zero hits**; there is no DB-driven override anywhere.

Whoever can edit deployed config can arm it. That is a deployment-integrity control,
not a code control, and it must be stated that way.

---

## 6. Governance phase and blocker closure (§11)

```
B1..B6_REMAIN_CLOSED = YES        (no regression)
```

| | Status | Evidence on the deployed tree |
|---|---|---|
| **B1** rollback / config-cache evidence | `CLOSED` | `DoctorAccessEnforcementRollbackTest.php` — *"resolves each doctor flag through a cached config the way production does"*, *"falls back to the committed default when the override is absent or unreadable"*, *"keeps the operator force-logout command reachable independently of the lease flag"* |
| **B2** phase-aware gate semantics | `CLOSED` | `governance_phase = phase_4a`; `global_prerequisites_attested` reports **`NOT_APPLICABLE`**, never PASS (`:859-863`) |
| **B3** checklist uses measured evidence | `CLOSED` | `Phase4aPilotPreparationScanner:215` computes `global_enforcement_active_live`; checklist A5 reads it |
| **B4** 0-UNSET verification path | `CLOSED` | `docs/runbooks/doctor-branch-lock-operations.md` §§60–79 — *"Verify against a **locked** doctor — there is no UNSET doctor left to use"* |
| **B5** Android + WebAuthn observability | `CLOSED` (was a **false** blocker) | `DoctorFleetReadinessRepository:37` reads `sys_audit_logs` over `PROOF_ACTIONS`; live report emits both success paths. `last_authorized_login_at` still has **one writer** (`DoctorDeviceAuthorizationService:352`) and **zero readers** — not authority, not reopened (§36) |
| **B6** no misleading `GLOBAL_READY` | `CLOSED` | live verdict `TRUSTED_PATHS_COMPLETE`; only rename-records remain |

Live gate run: **26 PASS / 0 FAIL / 1 NOT_APPLICABLE** of 27 checks.

**Per-phase semantics, re-derived:** `global_scope_not_permitted_in_phase_4a` is
`globalPermitted ? FAIL : PASS` inside `phase_4a` and `NOT_APPLICABLE` outside — never
PASS when skipped (`notApplicable()` `:584-592` is the only skip constructor and stamps
a distinct fourth token). `global_enforcement_not_active` does not skip: at
`global_activated` it emits a differently-named `global_enforcement_active` with
inverted polarity. An unrecognised phase string resolves to `phase_4a` — **a typo
tightens the audit, never loosens it.** `armed_but_covers_nobody` stays a FAIL in
every phase.

---

## 7. Global prerequisites — every one, enumerated (§12)

`CURRENT_PHASE_APPLICABILITY` for all five: **`NOT_APPLICABLE` in `phase_4a`.** The
check is only evaluated outside this phase, and then requires a strict `=== true` per
name — a missing key, `null`, `1` or `"yes"` all FAIL.

| Name | Measured | Attestation required | Attested | Evidence |
|---|---|---|---|---|
| `real_device_pilot_passed` | **no measurement exists** | YES | `false` | pure signature |
| `every_enforced_doctor_has_an_active_device` | **no measurement exists** | YES | `false` | pure signature (fleet engine measures something adjacent, but this gate reads only the attestation) |
| `spare_device_available_per_branch` | **FAIL** — Level 3, all three staffed branches | YES | `false` | `doctor:estate-resilience` |
| `device_loss_runbook_rehearsed` | **no measurement exists** | YES | `false` | pure signature |
| `rollback_to_browser_login_proven` | **no measurement, and no producer** | YES | `false` | **zero occurrences under `app/`.** The project's own test says so: `DoctorAccessEnforcementRollbackTest.php:26-31` — *"Production carries zero audit rows for a rollback because no producer exists."* |

Separately, the **activation-testing** prerequisite — a different and lower bar:

| Name | Measured | Attested | Applicability |
|---|---|---|---|
| `trusted_device_activation_test_coverage` | **PASS** | **`true`** (signed 2026-09-16, after the measurement turned true) | evaluated now |

### 7.1 Finding — four of five global prerequisites cannot be contradicted

The contradiction detector lives in a **different engine** from the gate that consumes
the attestations: `DoctorEstateResilienceVerdict.php:133`, surfaced by
`doctor:estate-resilience` and pinned by `DoctorEstateResilienceTest.php:804-823`.
`Phase4aPilotPreparationScanner` is explicit that it does not do this (`:848-850`).

Consequence, stated plainly: **signing `spare_device_available_per_branch => true`
would PASS the phase gate today despite the measured FAIL** — only the separate estate
command would report the contradiction, and only for that one prerequisite. The other
four have no measurement able to contradict them at all.

Classification: `MUST_FIX_BEFORE_ACTIVATION_WINDOW` — for **Half B only**. It is scope
for the dedicated Half B sprint (§16), not something a read-only recheck may patch.

---

## 8. Estate — Level 1 recheck (§13, §14) — no degradation

Independently re-measured with `doctor:estate-resilience`. Every expected value held.

```
ACTIVATION_TEST_COVERAGE = PASS
TLK1 = PASS   LDK2 = PASS   SPN4 = PASS   ATG3 = NOT_APPLICABLE (staffed=false, homes no doctor)
LEVEL_1_ATTESTED = true
attestation_does_not_contradict_measurement = PASS
authorizes_activation = false
```

Gates: `local_trusted_device_coverage` PASS · `trusted_device_activation_test_coverage`
PASS · `device_credential_coverage` PASS · `authorization_coverage` PASS ·
`eligible_device_set_agreement` PASS · `attestation_does_not_contradict_measurement`
PASS · `spare_device_available_per_branch` **FAIL**.

Every staffed branch reports `eligible_devices_without_credential: []` and
`eligible_devices_without_admissible_credential: []`, and
`locally_usable_device_count = 1`.

### 8.1 Levels 2 and 3 — visible, unchanged, not blockers here (§14)

```
NORMAL_PRODUCTION_ROOM_CAPACITY = PARTIAL   (Level 2 — TLK1, LDK2, SPN4)
HIGH_AVAILABILITY_RESILIENCE    = FAIL      (Level 3 — TLK1, LDK2, SPN4)
ESTATE_RESILIENCE (overall)     = FAIL      (can never read greener than Level 3)
```

Neither gates the owner-approved controlled activation-testing rung. Neither is marked
PASS and neither is hidden. Level 3 needs four more tablets.

### 8.2 Standing findings, unchanged

- `eligible_device_at_branch_homing_no_doctor` — ATG3 holds device 6 and homes nobody.
- `assignable_room_a_doctor_could_be_placed_in_is_not_counted_by_level_2` — TLK1 has 4
  active assignable rooms against 3 doctor rooms; the room-assignment gate offers every
  active room whatever its type. Either the room is mistyped or the assignment path is
  wider than it should be.
- `revoked_devices_present_in_estate` — 2, contributing nothing to capacity.

---

## 9. Device 7 regression check (§15) — clean

```
DEVICE_ID                  = 7            ("PILOT_TABLET_04_TLK1")
ACTIVE                     = true
TRUSTED                    = true         (approved; present in the eligible set)
CRYPTOGRAPHICALLY_VERIFIED = true
REVOKED                    = false
PHYSICAL_BRANCH            = TLK1 (branch_id 1, Cabang Telkomas)
CREDENTIAL_PRESENT         = YES          1 unrevoked credential
CREDENTIAL_USABLE          = YES          not in eligible_devices_without_credential
BINDING_ACCEPTABLE         = YES          not in eligible_devices_without_admissible_credential;
                                          1 of 1 reporting device-bound
ELIGIBLE                   = true
```

Provisioning persisted in full. TLK1's `locally_usable_device_count = 1` is what carries
Level 1 there.

---

## 10. Authorization matrix delta (§16) — complete

Re-derived from the current estate, no fixed denominator:

```
ELIGIBLE_DOCTORS     = 15
ELIGIBLE_DEVICES     = 4          ids [3, 5, 6, 7]   (estate total 6; 2 revoked)
TARGET_PAIRS         = 60         = 15 × 4
ACTIVE_TARGET_PAIRS  = 60
MISSING              = 0
DUPLICATE_ACTIVE     = 0
unlinked_doctor_count = 0
```

`ACTIVE_TARGET_PAIRS == TARGET_PAIRS` ✓ · `MISSING == 0` ✓ · `DUPLICATE_ACTIVE == 0` ✓

All four eligible devices are `active`, `cryptographically_verified`, unrevoked, and
carry exactly one unrevoked device-bound credential.

---

## 11. Fleet readiness — one genuine positive delta

```
eligible_doctor_count          = 15      locked = 15    unset = 0
real_device_ready_doctor_count = 15      not ready = 0
fleet_ready_doctor_count       = 15
provisioning verdict           = TRUSTED_PATHS_COMPLETE
overall verdict                = PARTIAL
```

All fifteen doctors hold a device-login proof — reconciled independently against
`sys_audit_logs`: 15 distinct users across `DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS`
and `DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS`, latest 2026-09-14 07:56:51.

**Be precise about what moved.** These proofs **pre-date** the provisioning sprint —
the readiness-campaign rotations of 2026-09-13/14 produced them. The record saying
"9 of 15 proven, 6 remain" was simply stale. Nothing logged in during this window.

The `PARTIAL` verdict has exactly one cause, and it is **not a doctor**:

```
trusted_device_without_readiness_proof → device_id 7
```

Device 7 is the TLK1 tablet provisioned on 2026-09-16; no doctor has yet completed a
login through it. `blocker_tally` is empty. Classification: `INFORMATIONAL` — Level 1
counts credentials the login gate would admit, not logins already performed.

---

## 12. Lease inventory (§17) — fresh, complete, nothing mutated

```
TOTAL_LEASE_ROWS  = 8
RELEASED_LEASES   = 7
UNRELEASED_LEASES = 1
LEGITIMATE_LIVE   = 0
STALE_ORPHAN      = 1
HISTORICAL_INERT  = 7
AMBIGUOUS         = 0
```

All eight rows belong to user 18 / doctor 21 (drg Karmila). No other doctor has ever
held a lease.

| Lease | Released (UTC) | Reason | Eff. branch | Cover | Class |
|---|---|---|---|---|---|
| 1 | 2026-09-11 22:18:45 | `device_invalidated` | – | – | HISTORICAL_INERT |
| 2 | 2026-09-11 22:45:48 | `logout` | – | – | HISTORICAL_INERT |
| 3 | 2026-09-11 22:46:22 | `device_invalidated` | – | – | HISTORICAL_INERT |
| 4 | 2026-09-11 22:47:11 | `device_invalidated` | – | – | HISTORICAL_INERT |
| 5 | 2026-09-12 02:35:01 | `admin_release` | 5 | – | HISTORICAL_INERT |
| 6 | 2026-09-12 03:03:47 | `effective_branch_changed` | 5 | – | HISTORICAL_INERT |
| 7 | 2026-09-12 03:26:36 | `effective_branch_changed` | 3 | 1 | HISTORICAL_INERT |
| **8** | **NULL** | — | 5 | – | **STALE_ORPHAN** |

### The one unreleased lease

```
LEASE_ID             = 8
USER_ID              = 18            DOCTOR_ID = 21 (drg Karmila)
SESSION_ID           = atr7RHB8bvdkbSgP3Ghpa2cfo8aFAbzKGDyWJ5Lx
CREATED_AT_UTC       = 2026-09-12 03:30:19
EFFECTIVE_BRANCH_ID  = 5             COVER_ID = (none)
LIVE_SESSION_EXISTS  = NO            0 rows in `sessions` for user 18; 0 rows for that session id
USER_ONLINE          = stale row only — trx_user_online_contexts id 10, status 'inactive',
                       offline_at IS NULL, open since 2026-09-12 03:30:29
CURRENT_ROOM         = none
ACTIVE_VISIT_COUNT   = 1             VIS-SPN4-20260907-001, cashier_pending, dated 2026-09-07
CLASSIFICATION       = STALE_ORPHAN
```

### 12.1 Karmila recheck (§18)

```
KARMILA_HISTORICAL_LEASE_8_EXISTS   = YES
KARMILA_LEASE_8_RELEASED            = NO
KARMILA_LEASE_8_LIVE_SESSION_MATCH  = NO   (its session id has no row; the user has no session at all)
KARMILA_CURRENT_OPEN_LEASES         = 1
KARMILA_REARM_RISK                  = YES  (conditional — see §12.2)
```

Not `AMBIGUOUS`: the probe is decisive.

```php
// IncumbentSessionProbe::isLive()
DB::table('sessions')->where('user_id', $lease->user_id)
  ->where('last_activity', '>=', now()->subMinutes($lifetime)->getTimestamp())->exists();
```

Zero session rows ⇒ the incumbent is dead and the next claim reclaims it.
**Nothing was released (§39).**

### 12.2 Re-arm risk model (§19)

```
REARM_BLOCKING_LEASES  = 0   (as measured at this instant)
REARM_AMBIGUOUS_LEASES = 1
```

| Lease | `WOULD_BECOME_INCUMBENT` | `FRESH_LOGIN_IMPACT` |
|---|---|---|
| 1–7 | **NO** — released | none |
| 8 | **CONDITIONAL** — `NO` while user 18 holds no live session; `YES` the moment she has one | while dead: reclaimed silently, login granted. Once live: the next login is refused with `DENY_ACTIVE_SESSION_ELSEWHERE` |

**The trap is the obvious sequence.** User 18 logs in **while the flag is off** → a
`sessions` row is written and **no lease is claimed** → the flag is armed → lease 8 is
now backed by a live session → her next login is refused.

Remediation — either order works, and one must be chosen before the window opens:

1. Enter the window with **no live doctor session** and arm first; or
2. Release lease 8 first, under audit, with the CLI tool in §14.

---

## 13. Active clinical work baseline (§20) — read-only

| user | doctor | home | online | room | open visits | visits today | live session | open lease |
|---|---|---|---|---|---|---|---|---|
| 9 | 17 drg Nisa | LDK2 | no | – | 1 | 0 | 0 | 0 |
| 12 | 18 drg Ramadhan | LDK2 | no | – | 2 | 0 | 0 | 0 |
| 14 | 16 drg Fahira | TLK1 | no | – | 0 | 0 | 0 | 0 |
| 15 | 20 drg Fiitri | TLK1 | no | – | 2 | 0 | 0 | 0 |
| 18 | 21 drg Karmila | SPN4 | stale row | – | 1 | 0 | 0 | **1** |
| 19 | 22 drg Windi | SPN4 | no | – | 0 | 0 | 0 | 0 |
| 20 | 23 drg Nurmilah | SPN4 | no | – | 0 | 0 | 0 | 0 |
| 21 | 24 drg Aisyah | SPN4 | no | – | 0 | 0 | 0 | 0 |
| 22 | 25 drg Ilmiah | SPN4 | no | – | 0 | 0 | 0 | 0 |
| 23 | 26 drg Ega | SPN4 | no | – | 0 | 0 | 0 | 0 |
| 24 | 27 drg Syifa | SPN4 | no | – | 0 | 0 | 0 | 0 |
| 25 | 28 drg Yudya | SPN4 | no | – | 0 | 0 | 0 | 0 |
| 26 | 29 drg Syahrul | SPN4 | no | – | 0 | 0 | 0 | 0 |
| 27 | 30 drg Wahyuni | SPN4 | no | – | 0 | 0 | 0 | 0 |
| 28 | 15 drg Irwan | LDK2 | no | – | 0 | 0 | 0 | 0 |

```
ACTIVE_CLINICAL_DOCTORS = 0
LIVE DOCTOR SESSIONS    = 0    (10 `sessions` rows: 3 belong to user 1 IT Support, 7 unauthenticated)
VISITS DATED TODAY      = 0
OPEN VISITS (backlog)   = 7    2026-06-28 … 2026-09-07; five `cashier_pending` (awaiting the
                               cashier, not a doctor), two `registered`
ACTIVE_COVERS           = 0    SCHEDULED_COVERS = 0   ANOMALOUS_COVERS = 0
```

The single historical cover (id 1, doctor 21, SPN4→ATG3) ran 2026-09-12 02:25→03:25
and is long expired.

> `NO_UNSAFE_ACTIVE_CLINICAL_WORK_NOW` is **informational only**. It is a perishable
> measurement, not a standing property, and the activation window must re-take it.

### 13.1 The clinical hard gate, made machine-checkable (§21)

`NO_UNSAFE_ACTIVE_CLINICAL_WORK = YES` requires **all** of the following, measured
within minutes of the cutover. `online = false` alone is explicitly **not** sufficient —
user 18's row proves the presence table can hold an open row for a session that no
longer exists.

| # | Condition | Query |
|---|---|---|
| 1 | No live doctor session | `sessions` joined to Doctor-role users, `last_activity >= now() - SESSION_LIFETIME` → **0** |
| 2 | No doctor in a clinic room | `trx_user_online_contexts` where `offline_at IS NULL AND role_context='doctor' AND clinic_room_id IS NOT NULL` → **0** |
| 3 | No examination in flight | `trx_clinic_visits` where `status = 'in_progress' AND deleted_at IS NULL` → **0** |
| 4 | No visit opened today | `trx_clinic_visits` where `visit_date = (now() AT TIME ZONE 'Asia/Makassar')::date` → **0**, or each one explicitly accepted by the operator |
| 5 | No unreleased lease backed by a live session | for each lease with `released_at IS NULL`, `IncumbentSessionProbe::isLive()` → **false** |
| 6 | No active cover starting or ending inside the window | `trx_doctor_branch_covers` `status='approved'` overlapping the window → **0** |

Conditions 1–3, 5 and 6 are hard. Condition 4 is a judgement the operator records.

---

## 14. Incident paths (§27, §28)

```
HTTP_FORCE_LOGOUT_AVAILABLE_NOW          = NO
HTTP_FORCE_LOGOUT_AVAILABLE_AFTER_HALF_A = YES
SSH_INCIDENT_PATH_AVAILABLE              = YES
SSH_ACCESS_AVAILABLE                     = YES   (srv1730088, /var/www/asia-dental-lab-v2)
CAN_READ_EFFECTIVE_CONFIG                = YES   (foundation:feature-flags, android:phase4a-pilot-scope)
CAN_EXECUTE_CANONICAL_CONFIG_REBUILD     = YES   (§15)
CAN_RUN_CANONICAL_LEASE_RECOVERY_TOOL    = YES   (doctor:session-force-logout — not executed)
CAN_VERIFY_HEALTH                        = YES   (§18)
```

The route **is** registered unconditionally —
`POST rme/doctor-branch-locks/{doctor}/release-session`, middleware
`web,auth,permission:release_doctor_session_leases`, and it is the only HTTP route that
reaches a release. The **controller** closes it:

```php
private function assertCapabilityArmed(): void
{
    abort_unless($this->effectiveBranch->enabled(), 404);   // first line of releaseSession()
}
```

With `single_active_session = false` the resolver is inert, so it 404s. It opens the
moment Half A arms. **Never rely on HTTP as the sole rollback control.**

The CLI carries **no feature-flag guard**, verified three ways: `handle()` contains no
`enabled()`/flag check; `DoctorSessionReleaseService` matches zero of
`enabled()|flags|FeatureFlag`; and it is not on the
`release_safety.forbidden_production_commands` blocklist. Its real gates are the
`release_doctor_session_leases` permission on `--actor`, plus `--apply` and a bounded
`--reason`.

```
php artisan doctor:session-force-logout \
    --doctor=<mst_doctors.id> --actor=<id|email> --reason="<10-1000 chars>" [--apply]
```

Dry run is the default. It releases the lease and frees the clinic room; it never
revokes a device, an authorization or a credential.

> **Refuted claim, recorded.** "Nothing writes while the flag is off" is true of the
> per-request and per-login hot path — `ClaimDoctorSessionLease:70`,
> `EnsureDoctorSessionLease:63` and the resolver all return before any query. It is
> **false** of the subsystem as a whole: `DoctorSessionReleaseService::releaseForDoctor`
> writes a presence row, a lease release and a `sys_audit_logs` row with both flags off,
> and `DoctorBranchLockApprovalService` / `DoctorBranchLockBulkAssignmentService` write
> locks and audit rows likewise. That is deliberate — **it is exactly what makes the
> lease-cleanup plan executable before Half A arms.**

---

## 15. Config cache (§24, §25, §26)

```
CONFIG_CACHE_ENABLED                        = YES   bootstrap/cache/config.php present,
                                                    owned by daengtisiams
CONFIG_CACHE_REBUILD_REQUIRED_FOR_ACTIVATION = YES
APPLICATION_USER                            = daengtisiams
WORKING_DIRECTORY                           = /var/www/asia-dental-lab-v2
```

Editing the environment file alone changes nothing — Laravel skips it entirely when
config is cached.

```bash
# REBUILD (the deploy script's own sequence)
runuser -u daengtisiams -- php artisan optimize:clear
runuser -u daengtisiams -- php artisan config:cache
runuser -u daengtisiams -- php artisan route:cache
runuser -u daengtisiams -- php artisan view:cache

# CLEAR (if a rebuild must be staged)
runuser -u daengtisiams -- php artisan config:clear
runuser -u daengtisiams -- php artisan route:clear

# VERIFY EFFECTIVE STATE
runuser -u daengtisiams -- php artisan foundation:feature-flags --json
runuser -u daengtisiams -- php artisan android:phase4a-pilot-scope --json
runuser -u daengtisiams -- php artisan doctor:estate-resilience --json
```

`scripts/deploy-vps.sh` **refuses to run** if the declared runtime user is `root` or
`www-data` (`DMS_FORBIDDEN_RUNTIME_USERS`) and wraps every artisan call in
`runuser -u "$RUNTIME_USER"`. Follow that shape by hand.

**No activation-state config rebuild was executed by this sprint.**

### 15.1 The rollback transaction (§26)

```
capture A  → android:phase4a-pilot-scope --json  +  foundation:feature-flags --json   (§16)
mutate  B  → edit the environment file
rebuild    → optimize:clear ; config:cache ; route:cache ; view:cache
verify  B  → re-run both --json commands and diff against the intent

ROLLBACK
restore A  → write the CAPTURED values back, never a remembered constant
rebuild    → same four commands
verify  A  → re-run both --json commands and diff against snapshot A
```

---

## 16. Pre-activation snapshot (§22, §23) — non-secret only

No password, token, API key, database credential, signing secret or session secret was
read or recorded. Only activation controls:

```
PRE_ACTIVATION_SCOPE_CURRENT = [9, 15, 18]

FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION      = false
FEATURE_DOCTOR_BRANCH_LOCK                = true
FEATURE_DOCTOR_TRUSTED_DEVICE_ENFORCEMENT = true
FEATURE_DOCTOR_PWA_WEBAUTHN_DEVICE_LOGIN  = true

ANDROID_DOCTOR_ENFORCEMENT_SCOPE_MODE     = pilot
ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID  = 18
ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS = 9,15
ANDROID_PILOT_ENFORCEMENT_BRANCH_CODE     = SPN4

governance_phase  = phase_4a   (source-controlled, config/android_release.php)
global_permitted  = false      (source-controlled, no environment reader)

SESSION_DRIVER = database   SESSION_LIFETIME = 120
APP_ENV = pilot             APP_DEBUG = false
```

**The cohort is split across two host variables and the union is what runs.** The
singular key holds `18`, the plural holds `9,15`. A rollback restoring only one
restores the wrong cohort.

Restoring all three ids into the plural variable alone resolves to the identical
cohort, so `android:phase4a-pilot-scope` — which reports the resolved union — is a
sufficient rollback source. One reporting subtlety to know **before** an incident:
`declared_pilot_doctor_user_id` reads **`null`** today. That is by design —
`pilotDoctorUserId()` returns a value only when the resolved cohort has exactly one
member. It is not a sign the singular variable is unset.

### 16.1 What the window must capture immediately before mutation (§31)

| Artefact | How |
|---|---|
| `PRE_ACTIVATION_SCOPE_SNAPSHOT` | `android:phase4a-pilot-scope --json` |
| `PRE_ACTIVATION_FLAGS` | `foundation:feature-flags --json` |
| `PRE_ACTIVATION_EFFECTIVE_CONFIG` | both of the above, taken **after** the last cache rebuild |
| `LEASE_STATE` | read-only `SELECT` over `trx_doctor_session_leases` |
| `AUTHORIZATION_MATRIX_HASH` | sha256 of the `authorization_matrix` block of `doctor:estate-resilience --json` |
| `DEVICE_ESTATE_HASH` | sha256 of the `devices` block of the same report |
| `HOME_LOCK_HASH` | sha256 of `doctor:fleet-readiness --json` `home_branch_matrix` + per-doctor home rows |
| `CREDENTIAL_STATE_HASH` | sha256 of the per-device credential counts in the estate report |
| `AUDIT_WATERMARK` | `MAX(id)`, `MAX(performed_at)` from `sys_audit_logs` |
| `LOG_WATERMARK` | byte size of `storage/logs/laravel.log` |
| `HEALTH_BASELINE` | `/login`, `/health/live`, `/health/ready` over **https://daengtisia.online** |

All read-only. **No destructive backup is generated** — these are hashes and
watermarks, not dumps.

---

## 17. Rollback (§29, §30)

```
ROLLBACK_HALF_A_READY = YES
ROLLBACK_HALF_B_READY = YES
ROLLBACK_SCOPE_SOURCE = the snapshot captured in §16.1, never a remembered constant
ROLLBACK_GLOBAL_MODE  = pilot
CONFIG_CACHE_REBUILD_STEP = §15 (four commands, as daengtisiams)
VERIFY_EFFECTIVE_STATE    = foundation:feature-flags --json + android:phase4a-pilot-scope --json
```

**Half A rollback** — set the override to `false`, rebuild the cache. Required result:

```
single_active_session = false
branch_lock flag      = true    (unchanged)
branch_lock_effective = false
HOME locks, covers, authorizations, credentials, audit history — all preserved
```

The claim listener and per-request revalidation both return immediately: no login is
refused and **no open session is torn down**. Lease rows are left exactly as they are;
the partial unique index still permits one unreleased lease per user, harmless while
nothing claims one.

**Half B rollback** — set enforcement off, restore the exact captured scope, rebuild
the cache. Browser login works again immediately; no device is unenrolled and no
authorization is altered.

**No production on→off drill was performed.** The B1 test evidence remains valid
because the source it covers is unchanged on this tree, and production is not a unit
test.

---

## 18. Production health (§58)

Measured over the canonical domain (an IP request returns `301` to HTTPS — not a
failure):

```
/login        = 200
/health/live  = 200    {"status":"ok","service":"daengtisiams","check":"live"}
/health/ready = 200    components: database ok, cache ok, queue ok, storage ok, object_storage ok
APP_DEBUG           = false
MAINTENANCE         = OFF
MIGRATIONS_PENDING  = 0
FAILED_JOBS         = 0        ("No failed jobs found")
QUEUE_HEALTH        = PASS     (worker service active)
NEW_UNEXPLAINED_LARAVEL_ERRORS = 0   (no log file for today; laravel.log last written 2026-09-14)
```

---

## 19. No-activation assertion (§59)

Re-read after every step of this audit:

```
SINGLE_ACTIVE_SESSION            = false
BRANCH_LOCK_EFFECTIVE            = false
CURRENT_BROWSER_ENFORCEMENT_SCOPE = [9, 15, 18]
GLOBAL_FLEET_ENFORCEMENT_ACTIVE  = false
GLOBAL_ACTIVATION_APPLY_AUTHORIZED = NO
NO_LEASE_RELEASES_BY_THIS_PHASE  = YES
NO_SCOPE_MUTATION                = YES
NO_ACTIVATION_CONFIG_REBUILD     = YES
ACTIVATION_OCCURRED              = NO

LATEST_AUDIT_ROW      = 2026-09-16 15:22:40   (the provisioning ceremony; total 860)
AUDIT_ROWS_TODAY_WITA = 0
LEASE_MAX_ID          = 8       unreleased = 1
DEVICES               = 4 active / 6 total
BRANCH_LOCKS          = 15
```

Nothing after the provisioning ceremony. This audit wrote nothing to production.

---

## 20. Blocker classification (§38)

| Finding | Class |
|---|---|
| Production authority, prerequisite tags, Level 1, device 7, 60/60 authorization | `READY` |
| Half A mechanics re-derived, including the staggering correction | `READY` |
| Config-cache procedure and rollback transaction | `READY` |
| SSH escape hatch and CLI lease-recovery tool | `READY` |
| Owner APPLY decision | `MUST_FIX_BEFORE_ACTIVATION_WINDOW` (a decision, not a defect) |
| **Half B**: `global_permitted` source change + phase move + five attestations | `MUST_FIX_BEFORE_ACTIVATION_WINDOW` — Half B only; dedicated sprint |
| **Half B**: four of five global prerequisites cannot be contradicted (§7.1) | `MUST_FIX_BEFORE_ACTIVATION_WINDOW` — Half B only |
| Lease 8 stale orphan | `MUST_FIX_INSIDE_ACTIVATION_WINDOW` |
| Stale `trx_user_online_contexts` row 10 (user 18) | `MUST_FIX_INSIDE_ACTIVATION_WINDOW` |
| Device 7 has no readiness proof yet | `INFORMATIONAL` |
| ATG3 holds an idle eligible tablet | `INFORMATIONAL` |
| TLK1 assignable-room typing (4 assignable vs 3 doctor rooms) | `INFORMATIONAL` |
| `global_permitted` has no drift detection against git (§5.1) | `INFORMATIONAL` — deployment-integrity control |
| Level 2 room capacity `PARTIAL` | `DEFERRED_PRODUCTION_CAPACITY` |
| Level 3 high availability `FAIL` (four more tablets) | `DEFERRED_PRODUCTION_CAPACITY` |

---

## 21. Lease cleanup plan (§40) — planned, **not executed**

| | |
|---|---|
| `LEASE_ID` | **8** |
| `DOCTOR_ID` | 21 (drg Karmila, user 18) |
| `CLASSIFICATION` | `STALE_ORPHAN` |
| `RECOMMENDED_ACTION` | audited release, **inside** the activation window, immediately before the Half-A cutover |
| `CANONICAL_TOOL` | `php artisan doctor:session-force-logout --doctor=21 --actor=<id\|email> --reason="<…>" --apply` (dry-run first, without `--apply`) |
| `AUDIT_REASON` | e.g. *"Pelepasan lease usang sebelum cutover Half A — sesi sudah tidak ada sejak 2026-09-12."* (10–1000 chars, required to write) |
| `PRECONDITIONS` | clinical hard gate §13.1 all green; `isLive()` false re-confirmed at that moment; actor holds `release_doctor_session_leases`; dry run inspected first |

Leases 1–7: `HISTORICAL_INERT`, **no action**, never mutated.

The stale `trx_user_online_contexts` row 10 is the same abandoned session's second
orphan. `releaseForDoctor` also frees the clinic room and marks presence offline, so
the same command closes both.

**Cleanup happens immediately before the cutover, not days earlier (§39)** — a lease
released today can be replaced by a fresh one the moment that doctor logs in again.

### 21.1 Window order (§41) — verified against source and runbook

1. Confirm the scheduled window and that operators are present
2. Re-read production health (§18)
3. Re-read active clinical work — the §13.1 hard gate
4. Re-read **all** leases, fresh
5. Canonical logout of legitimate live sessions, if any
6. Canonical audited release of stale/orphan leases (§21)
7. Verify no blocking incumbents remain — `isLive()` false for every unreleased lease
8. Capture the §16.1 baseline
9. **Owner APPLY approval**
10. Half A config mutation → cache rebuild → verify effective state
11. Cross-branch branch-lock proof, second-login denial proof
12. Half B only if separately approved and unblocked

Step 6 is executable with both flags off — that is the design property recorded in §14.

---

## 22. Activation state machine (§32)

```
        ┌──────────────────────────────────────────────────────────────┐
        │ STATE A — READINESS / PRE-ACTIVATION            ◀── WE ARE HERE
        │   single_active_session = false                              │
        │   branch_lock_effective = false                              │
        │   scoped browser enforcement, cohort [9,15,18]               │
        └───────────────┬──────────────────────────────────────────────┘
                        │ (no change — Half A needs no source)
                        ▼
        ┌──────────────────────────────────────────────────────────────┐
        │ STATE B — ACTIVATION-CAPABLE CODE DEPLOYED                   │
        │   Half A: ALREADY TRUE at State A                            │
        │   Half B: NOT reached — needs a reviewed source change       │
        └───────────────┬──────────────────────────────────────────────┘
                        ▼
        ┌──────────────────────────────────────────────────────────────┐
        │ STATE C — ACTIVATION WINDOW CLEAN                            │
        │   leases reconciled · clinical gate green · baseline captured│
        └───────────────┬──────────────────────────────────────────────┘
                        │  ◀── OWNER APPLY APPROVAL REQUIRED HERE
                        ▼
        ┌──────────────────────────────────────────────────────────────┐
        │ STATE D — HALF A CUTOVER                                     │
        │   single_active_session = true → branch lock effective       │
        │   ALL 15 in population; branch narrowing immediate,          │
        │   lease enforcement from each doctor's next login            │
        └───────────────┬──────────────────────────────────────────────┘
                        │ ◀─── ROLLBACK: flag false + cache rebuild → STATE A
                        ▼
        ┌──────────────────────────────────────────────────────────────┐
        │ STATE E — HALF A VERIFIED                                    │
        │   cross-branch refusal proven · second-login denial proven   │
        └───────────────┬──────────────────────────────────────────────┘
                        ▼
        ┌──────────────────────────────────────────────────────────────┐
        │ STATE F — HALF B GLOBAL DEVICE/BROWSER ENFORCEMENT           │
        │   BLOCKED: global_permitted, phase move, 5 attestations      │
        └───────────────┬──────────────────────────────────────────────┘
                        │ ◀─── ROLLBACK: enforcement off + RESTORE CAPTURED
                        │              scope + cache rebuild → STATE E
                        ▼
              STATE G — POST-ACTIVATION 15/15 VERIFIED
                        ▼
              STATE H — STABILIZED / GLOBAL GO
```

**Visualization:** https://claude.ai/code/artifact/506412ad-8b44-4c29-8a1e-00efbba90acf
— the eight states, the rollback arrows, the estate levels and what still blocks Half B.

---

## 23. Android / WebAuthn semantics carried forward (§34) — re-derived, not copied

**`cryptographically_verified` is written in exactly three places**, all of them the
Android Keystore challenge-response:

```
DoctorAppLoginService.php:411
DoctorAppLoginService.php:447
DoctorDeviceProofService.php:169
```

`DoctorDeviceWebAuthnRegistrationService` contains **zero** references to
`identity_state` or `cryptographically_verified`. **A WebAuthn credential does not make
a device eligible** — `isEligible()` = `isActive() && isCryptographicallyVerified()` —
and every screen reports success anyway.

`config/webauthn.php:138` — `require_device_bound` defaults **true**. A Google-synced
passkey is `BE=1` and is refused at registration. Fix the tablet's passkey provider;
**never** flip `WEBAUTHN_REQUIRE_DEVICE_BOUND`.

Bare app-login auto-registration derives `branch_id` from the **doctor**, not the
tablet, and admin rows carry a null `public_key_fingerprint` that app login matches on
— so it creates a **duplicate device at the wrong branch**. Bind `branch_id` explicitly
at admin approval.

`doctor:device-bulk-authorize` is **digest-bound single-actor governance, not
maker/checker.** Dry run is the default; `--apply` additionally requires
`--confirm-plan=<digest>` over the eligible sets and exact pair lists; `--reason` is
required to write. One operator plans and applies.

**These hardware rules must not be simplified in any activation runbook.**

---

## 24. Failed-login observability (§35)

Carried forward for activation monitoring:

- The Clinic App reports **"cannot contact server"** for a server-side
  `invalid_credentials` rejection. **Client text never proves transport failure.**
- Failed auth events may carry `performed_by = NULL`, so a per-user audit query will
  miss them.
- Activation monitoring must correlate **server-side**: nginx access/error logs, the
  audit trail, challenge/ticket identifiers, device and authorization ids, and time
  windows — not the operator's description of the screen.

---

## 25. Activation decision matrix (§37)

| Gate | State |
|---|---|
| `FLEET_READINESS_GO` | **VALID** |
| `BLOCKER_CLOSURE_GO` | **VALID** |
| `ESTATE_POLICY_GO` | **VALID** |
| `TLK1_PROVISIONING_GO` | **VALID** |
| `ACTIVATION_TEST_COVERAGE` | **PASS** |
| `AUTHORIZATION_MATRIX_COMPLETE` | **YES** — 60/60, 0 missing, 0 duplicate |
| `LEVEL1_ATTESTATION` | **true**, and does not contradict its measurement |
| `ROLLBACK_PATH_PROVEN` | **YES** (Half A and Half B both defined; B1 test evidence valid) |
| `CONFIG_CACHE_PROCEDURE_READY` | **YES** |
| `LEASE_INVENTORY_CURRENT` | **YES** — 8 rows, 1 unreleased |
| `BLOCKING_LEASES_IDENTIFIED` | **YES** — 0 blocking now, 1 conditional |
| `CLINICAL_WORK_BASELINE_KNOWN` | **YES** — 0 active clinical doctors |
| `HALF_A_MECHANICS_VERIFIED` | **YES** (with the §3.1 and §3.2 corrections) |
| `HALF_B_MECHANICS_VERIFIED` | **YES** — and verified **BLOCKED** |
| `GLOBAL_ACTIVATION_APPLY_AUTHORIZED` | **NO** |

---

## 26. Source change decision (§42)

```
SOURCE_FIX_REQUIRED = NO
```

No activation blocker **defect** was found in source. Half A is activation-capable with
zero source change. Half B's blockers are a governance decision plus real hardware —
and the only way to "fix" §7.1 in code today would be to sign four statements nothing
can contradict, which is the opposite of a fix.

Half B remaining blockers, exactly:

1. `enforcement.scope.global_permitted` is `false` and reads no environment — a
   reviewed source change.
2. `enforcement.governance_phase` must leave `phase_4a`, or the posture check FAILs.
3. All five `global_prerequisites` must be attested `true`. Two are measurably or
   knowably untrue (`spare_device_available_per_branch` measures FAIL;
   `rollback_to_browser_login_proven` has no producer at all), and four of the five have
   nothing able to contradict them.

**Proposed dedicated child sprint** — obtain the four tablets Level 3 requires, build a
real producer and measurement for `rollback_to_browser_login_proven`, extend
contradiction detection to cover the remaining prerequisites, and only then take the
`global_permitted` flip plus the phase move as one governed act. **It must not be folded
into a Half A activation window.**

This sprint therefore ships documentation, rules and a manifest only — it is inert by
construction, not by review.

---

## 27. Evidence commands

All read-only, all run as `daengtisiams` on `srv1730088`:

```
php artisan doctor:fleet-readiness --json
php artisan doctor:estate-resilience --json
php artisan doctor:rollout-readiness --json
php artisan android:phase4a-pilot-readiness --json
php artisan android:phase4a-pilot-scope --json
php artisan foundation:feature-flags --json
```

Lease, session, cover, presence and clinical-work inventories were read with bounded
read-only `SELECT`s against `asia_dental_lab_pilot` (PostgreSQL 16.15).

**`php artisan tinker` was not used** — it pins the monitoring log to WATCH for 24 hours.

---

## 28. POST-GO — activation window OPENED and HELD (2026-09-17 WITA)

**This section records events AFTER the Phase-0 GO tag.** The tag's claim of
"zero mutations" is accurate for the recheck itself and up to the moment of
tagging. It is **no longer true of production as of 07:44 WITA** — one audited
mutation was made under explicit owner approval, recorded here.

### 28.1 Owner decision

```
APPROVE_DOCTOR_GLOBAL_ACTIVATION_APPLY = YES     (2026-09-17, owner)
```

Scope of that approval: **Half A only.** Half B remained blocked throughout and
was not touched — no attestation was signed, `global_permitted` was not changed,
and the governance phase stayed `phase_4a`.

### 28.2 Window opened — clinical hard gate measured GREEN at 07:34 WITA (Thu)

| # | Condition | Measured |
|---|---|---|
| 1 | live doctor sessions (`last_activity` within `SESSION_LIFETIME`) | **0** |
| 2 | doctors holding a `clinic_room_id` with `offline_at IS NULL` | **0** |
| 3 | visits `in_progress` | **0** |
| 4 | visits dated today (WITA) | **0** |
| 5 | unreleased leases backed by a live session | **0** (lease 8 `live_now=0`) |
| 6 | approved covers overlapping the window | **0** |

### 28.3 Rollback baseline captured, then deliberately discarded

`android:phase4a-pilot-scope`, `foundation:feature-flags`,
`doctor:estate-resilience` and `doctor:fleet-readiness` were captured to JSON and
sha256-hashed; log watermark `1407663` bytes; audit watermark id `882` / 860 rows.

Pre-activation scope recorded as `pilot`, cohort `[9, 15, 18]`, branch `SPN4`,
`declared_pilot_doctor_user_id = null` (expected — the cohort has more than one
member). Flags: `single_active_session` false, `branch_lock` true,
`trusted_device_enforcement` true, `pwa_webauthn_device_login` true.

**That capture has since been deleted on purpose.** It predates the lease release
in §28.4 and is therefore stale. A future window must capture its own baseline —
restoring a remembered one is exactly what rule **AW-R10** forbids.

### 28.4 THE ONE MUTATION — lease 8 released under audit

```
php artisan doctor:session-force-logout --doctor=21 --actor=1 \
    --reason="Pelepasan lease usang sebelum cutover Half A: sesi user 18 sudah
              tidak ada sejak 2026-09-12, lease 8 tidak pernah dilepas." --apply
```

Dry run inspected first: `doctor_online=no`, `holds_active_lease=yes`,
`lease_id=8`, `claimed_at=2026-09-12 03:30:19`. Applied: `released=yes`.

Re-checked afterwards by dry-running the same command: `holds_active_lease=no`,
`lease_id=—`. **All 8 leases are now released; 0 blocking incumbents remain.**

The command also freed drg Karmila's clinic room and marked her presence offline,
which closed the second orphan (`trx_user_online_contexts` id 10). **No device, no
authorization and no WebAuthn credential was touched.**

### 28.5 Half A was NOT armed — the window was HELD

Two blockers stopped the cutover, and the owner chose to hold rather than force
either:

1. **The perishable gate became unverifiable.** The read-only SQL path used to
   measure the six conditions was withdrawn by the environment's permission
   layer, and no artisan report covers conditions 1–4 or 6. Arming narrows every
   doctor's branch **immediately**, so a stale gate reading is not an acceptable
   basis for the flip (**AW-R9**).
2. **No window was ever scheduled.** The approval settled *what*, not *when*. It
   was 07:47 on a Thursday with clinics opening and no operator standing by —
   runbook step 1, which is not terminal-verifiable.

```
OWNER_WINDOW_DECISION = HOLD FOR A SCHEDULED WINDOW
HALF_A_ARMED          = NO
```

### 28.6 State on production right now

```
single_active_session = false        branch_lock = true
BRANCH_LOCK_EFFECTIVE = false
scope = pilot   cohort = [9, 15, 18]   global_enforcement_active = false
HEAD = 21b8a57a  (GO tag exact-match)
leases: 8 total, 8 released, 0 unreleased, 0 blocking incumbents
estate: 15 locked / 0 unset / 60 of 60 authorizations / 4 eligible devices
health: /login 200 · /health/live 200 · /health/ready 200
```

**State C (activation window clean) is reached and stable.** Nothing decays while
the flag is off: the only change a future window will see is a doctor logging in
and claiming no lease, which is harmless — and is exactly why **AW-R8** puts lease
cleanup immediately before the cutover rather than days ahead of it. When the
window is scheduled, resume at runbook step 2: re-read health, re-measure the
six-condition gate, re-read all leases, capture a **fresh** baseline, then flip.
