# DOCTOR-PWA-MULTI-DOCTOR-PILOT-1

**Status: CAPABILITY SHIPPED / PILOT BLOCKED.**
The enforcement cohort mechanism is built, tested and deployed inert.
The multi-doctor pilot is **not** live and **no** `doctor-pwa-multi-doctor-pilot-1-go`
tag exists.

`GLOBAL_ENFORCEMENT_ACTIVE=false` · `GLOBAL_ROLLOUT_AUTHORIZED=NO`

---

## 1. What this sprint was asked to do, and what it could actually do

The sprint was to expand the live production pilot from drg Karmila alone to a
controlled multi-doctor cohort, with a stated hard minimum of **3 doctors, 2
branches and 2 physical clinic devices**, at least one covered doctor outside
SPN4 and at least one approved device other than device 3.

Discovery against production established that the minimum cannot be met, for a
reason no amount of software can fix. The response was to build the mechanism
the cohort will need, ship it so that it changes nobody, and hold the GO.

## 2. Authority, verified rather than assumed

Every value below was re-derived from canonical git, the VPS and the live
database. Nothing was carried over from the sprint brief on trust.

| Fact | Value |
| --- | --- |
| Parent GO tag | `doctor-pwa-webauthn-1-go` → tag object `913c3048` → commit `41f87199` |
| Parent closure tag | `doctor-pwa-webauthn-parent-closure-1-go` → object `8ffa5932` → same commit |
| Parent runtime tree | `b2c0cb8a` |
| Production HEAD / tree | `41f87199` / `b2c0cb8a` — exact match, `git describe --exact-match` returns the GO tag |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Base SHA / tree | `9eabff07` / `4bc1ef80` (local == remote) |
| Production health | `/login`, `/health/live`, `/health/ready` all 200 |

Live scope before this sprint, from `android:phase4a-pilot-scope` on the VPS:
mode `pilot`, covered `[18]`, `GLOBAL_ENFORCEMENT_ACTIVE=false`,
`DOCTOR_ROLE_ACCOUNT_COUNT=15`, denied 1, allowed 14, `SCOPE_VERDICT=GO`.

## 3. The two findings that decided the sprint

### 3.1 The scope could not express a cohort

`AndroidDoctorEnforcementScope::pilotDoctorUserId()` returned a single `?int`
and `coversUser()` compared it with `===`. There was exactly one chokepoint,
`DoctorAppLoginGate::inEnforcementScope()`, and it could name exactly one
person. A source change was therefore genuinely required — this was not a
configuration sprint.

### 3.2 The fleet cannot form a cohort

The entire production device inventory is three rows:

| Device | Model | Status | Branch |
| --- | --- | --- | --- |
| 1 — `PHASE4A_PILOT_TABLET_01` | SM-X236B | **revoked** (terminal) | SPN4 |
| 3 — `PHASE4A_PILOT_TABLET_02` | SM-X236B | **active** | SPN4 |
| 4 — `PHASE4A_PILOT_TABLET_03` | 2311DRK48G | **revoked** (terminal) | SPN4 |

Only doctor 21 (drg Karmila, user 18) holds any authorization, and only device
3 holds any credential. The other 14 Doctor-role accounts have no device, no
authorization and no credential between them. Every device that has ever
existed is at SPN4.

Two further facts make this irreducible:

- `identity_state = cryptographically_verified` is written by exactly two code
  paths, `DoctorAppLoginService` and `DoctorDeviceProofService`. Both are inside
  the Android Clinic App. **A trusted device cannot be created from the server
  side** — it needs a physical tablet completing a real enrolment.
- A WebAuthn credential with `user_verified`, `backup_eligible=false` and
  `device_bound` requires a human performing user verification at that physical
  device. Section 11 of the sprint brief forbids fabricating any of it, and
  fabricating it would be worthless anyway.

So the minimum needs at least one more enrolled Android tablet, ideally at a
non-SPN4 branch, plus provisioning ceremonies per doctor. None of that exists,
and none of it is reachable from this side.

### 3.3 One useful nuance for whoever unblocks this

The WebAuthn credential is bound to the **device**, not the doctor — the
`userHandle` is the device uuid — and `mst_doctor_device_authorizations` has
supported many doctors per device since Phase 3. So a second doctor on the
existing tablet needs only an authorization and would reuse credential 2. That
lowers the cost of the *doctor* half considerably. It does not lower the cost of
the *device and branch* half at all.

## 4. What shipped

A cohort, bounded and fail-closed. The mechanism, not the pilot.

- **`AndroidDoctorEnforcementScope`** gains `pilotDoctorUserIds()` (sorted,
  deduplicated, `list<int>`), `pilotCohortMaximum()`, and strict membership in
  `coversUser()`. All nine pre-existing public methods are preserved.
- **`config/doctor_device_enforcement.php`** gains `pilot.doctor_user_ids`,
  read from `ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS`. The singular key stays
  and keeps its meaning.
- **`config/android_release.php`** gains `enforcement.scope.pilot_cohort_maximum`
  = 5, source-controlled beside `global_permitted`.
- **`Phase4aPilotScopeResolutionReport`** stops treating a second doctor as a
  failure and starts checking something stronger — see §6.
- **`android:phase4a-pilot-scope`** prints `DECLARED_PILOT_DOCTOR_USER_IDS`,
  `DECLARED_PILOT_COHORT_SIZE` and `PILOT_COHORT_MAXIMUM` beside the existing
  singular line.

The durable rules are in `.cursor/rules/151-doctor-pilot-enforcement-cohort.mdc`
as **MD-R1 … MD-R10**.

## 5. Why deploying this changes nobody

The property that made it safe to ship to a live clinical pilot.

Production sets `ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID=18` and leaves the
cohort variable unset. The two sources are **unioned**, so the scope resolves to
exactly `[18]` after the deploy as it did before it. A rename would have made
deployment itself an act that altered who is enforced; a union makes it inert.
This is asserted, not assumed, by *"it resolves a deployment that sets only the
old singular key exactly as before"*.

## 6. The ceiling, and why it exists

An explicit allowlist is the only expansion shape permitted — but an explicit
list can be written out until it names all fifteen doctors, and at that point
"pilot" has become fleet-wide denial while every guard watching for fleet-wide
denial still reads `global_permitted=false`. That is the one way the pilot
boundary can be crossed without anybody deciding to cross it.

So the ceiling sits in `android_release.php`, next to `global_permitted`, for
the same stated reason: a bound an operator can raise on a host is not a bound.
A missing or unreadable ceiling resolves to **1**, never to unlimited.

The report's GO condition changed from *"exactly one doctor"* to *"the covered
set equals the declared set, and is not empty"*. That is strictly stronger than
what it replaced: a cardinality check passes when the count is right and the
members are wrong, which is precisely the `users.id` / `mst_doctors.id` mix-up
the report was written to catch.

## 7. Verification

| Gate | Result |
| --- | --- |
| New cohort suite `MultiDoctorPilotCohortTest` | 27 passed, 146 assertions |
| Pre-existing scope contracts (Phase4a preparation + scope command) | 65 passed, 356 assertions |
| DoctorDevice + DoctorDeviceWebAuthn + Auth + AccessControl + Pwa | 770 passed, 4733 assertions, 0 failed |
| Sprint manifest | GO |

The regression run reports 9 skipped and 1 risky. Both were proven to be
baseline by running them at the unmodified base commit `9eabff07`: the skips are
PostgreSQL row-lock tests SQLite cannot observe, and the risky test
(`DoctorDeviceApiAndNoEnforcementTest`, middleware registration) is risky at
base identically.

### Mutation campaign

Nine mutations, applied locally, never committed, restored by copy and verified
by checksum. Seven killed, two proven equivalent and documented in place.

| # | Mutation | Result |
| --- | --- | --- |
| MUT-1 | cohort resolver always covers | KILLED (10 failures) |
| MUT-2 | strict membership → loose | **EQUIVALENT** — see below |
| MUT-3 | verdict stops comparing covered vs declared | **EQUIVALENT** — see below |
| MUT-4 | global guard treated as true | KILLED (4) |
| MUT-5 | reviewed ceiling ignored | KILLED (3) |
| MUT-6 | one bad entry dropped instead of voiding | KILLED (4) |
| MUT-7 | ceiling fails open when unreadable | KILLED (1) |
| MUT-8 | cohort entries stored unnormalised | KILLED (12) |
| MUT-9 | report ignores an unresolved declared id | KILLED (3) |

**MUT-2** is equivalent because `coversUser()` takes a typed `int` and the
cohort holds only ints, over which `==` and `===` cannot disagree. The response
was not to shrug but to pin the precondition that makes it equivalent — a test
now asserts every cohort entry is a genuine integer, so if a numeric string ever
enters the list, strictness becomes load-bearing again and something notices.

**MUT-3** is equivalent because both directions of a set mismatch are already
findings, and a non-empty findings list returns FAIL two statements earlier. The
guard is kept as deliberate redundancy, in the same spirit as the unreachable
`default => false` arm the codebase already documents in `coversUser()`.

## 8. What was deliberately not done

- **No cohort was activated.** The live scope is unchanged at `[18]`.
- **No GO tag for the pilot.** `doctor-pwa-multi-doctor-pilot-1-go` does not
  exist and must not be created until §9 is satisfied.
- **No device, authorization or credential was created, and none was revoked.**
  Devices 1 and 4 stay terminally revoked; credential 1 stays revoked;
  credential 2 stays active on device 3.
- **No ceremony was claimed.** No Chrome login, no installed-PWA login, no
  protected request, for any doctor. There was nothing to hold one for.
- **PR #392 remains out of scope**, carried to
  `DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1`.
- **No weakening of the stated minimum.** Three doctors on the single existing
  tablet would have reached the doctor count while failing the branch and device
  minimums, and was declined rather than quietly redefined.

## 9. What unblocks the multi-doctor GO

In order:

1. **Hardware.** At least one more physical Android clinic tablet, enrolled
   through the Clinic App until `identity_state=cryptographically_verified`,
   ideally sited at a branch other than SPN4 so the two-branch minimum is met by
   geography rather than by paperwork.
2. **Named doctors.** The owner names the two additional doctors. All 14
   non-pilot accounts are equally eligible on the data available — active, no
   device, no static branch — so there is no objective ranking to offer and one
   should not be invented.
3. **Provisioning, then enforcement, in that order.** Per MD-R4: device,
   authorization and a usable device-bound credential must all exist *before*
   any id joins the cohort.
4. **Ceremonies.** Per doctor: Chrome login, protected request, independently
   initiated installed-PWA login, protected request — each against a fresh audit
   watermark, and each distinguishing server-verified facts from
   operator-attested client context.
5. **Then** set `ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS`, rebuild config as
   `daengtisiams`, and re-verify covered/denied/allowed counts reconcile against
   the live `DOCTOR_ROLE_ACCOUNT_COUNT`.

Rollback is unchanged and does not touch identities: both feature flags off,
then `config:clear && config:cache` as `daengtisiams`. Narrowing the cohort
removes a doctor from enforcement without revoking their device, authorization
or credential, so per-doctor rollback is available and terminal revocation
remains reserved for real compromise.

## 10. Next programme

`DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1` — and it is gated behind a real
multi-doctor pilot, which is gated behind §9. Nothing in this sprint authorizes
global rollout.
