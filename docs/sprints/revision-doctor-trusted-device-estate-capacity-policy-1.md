# REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1

**Three requirements that had been asked with one word.**

Policy / scanner / governance revision. Read-only throughout. No migration, no
route, no permission, no policy, no hardware, no attestation, no activation.

- Branch: `revision/doctor-trusted-device-estate-capacity-policy-1`
- Base: `a6b50ad7` — the base branch tip **and** the deployed production HEAD,
  tree `37b56187`, carrying no tag (the parent sprint closed NO-GO).
- Parent: `DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1` (PR #410),
  merged and deployed, `GO_TAGGED=NO`.

---

## 1. Why this exists, and what it is not

The parent sprint measured `spare_device_available_per_branch` correctly under
the only definition the codebase owned — the device-loss runbook's
`concurrent Doctor stations + 1 spare` — and reported **FAIL**. **That verdict
stands and is not revised here.** Nothing in this sprint makes a failing estate
pass.

What the parent could not do was tell three different questions apart:

| Level | Signal | Requirement | Gates activation testing? |
|---|---|---|---|
| 1 | `trusted_device_activation_test_coverage` | `>= 1` device that can be **logged into**, at every staffed branch | **yes** |
| 2 | `trusted_device_room_capacity` | `>= 1` device per active Doctor room | no — normal-production target |
| 3 | `spare_device_available_per_branch` | `stations + 1` — survives losing one | no — high availability |

Collapsed into one FAIL, "can we begin testing at all?" was answered with "does
every branch survive losing a tablet?" — and the owner was handed a four-tablet
bill when the actionable sentence was **one tablet at TLK1**.

### The premise this sprint had to correct before writing a line

The brief assumed `spare_device_available_per_branch` currently *blocks*
activation. **It does not, and never has in code.** `governance_phase` is
`phase_4a`, and `Phase4aPilotPreparationScanner::globalPrerequisiteCheck()`
opens with a `NOT_APPLICABLE` return for exactly that phase. The list it reads
is a **Phase-5** precondition for fleet-wide enforcement. What actually defers
activation today is the owner's recorded `PROVISION_ESTATE_SPARE_CAPACITY_FIRST`
decision — a human decision that named a capacity level.

So this sprint does not re-point a code gate that did not exist. It makes the
level the owner's decision should key on **measurable, named and read by two
surfaces**, and says plainly that `authorizes_activation` remains a literal
`false`.

---

## 2. Measured on production before the change (deployed engine, `a6b50ad7`)

```
TLK1   2 home doctors, 3 rooms, 0 devices, 0 eligible   FAIL
LDK2   3 home doctors, 3 rooms, 1 device,  1 eligible   FAIL
ATG3   0 home doctors, 2 rooms, 1 device,  1 eligible   PASS  (homes nobody)
SPN4  10 home doctors, 2 rooms, 3 devices, 1 eligible   FAIL
ESTATE_RESILIENCE=FAIL   MINIMUM_ADDITIONAL_DEVICES=4   AUTHORIZATION 45/45/0/0
```

Under the separated levels the same estate reads: **Level 1 FAIL at TLK1 only**
(LDK2 and SPN4 clear it), Level 2 short, Level 3 FAIL everywhere. One branch, one
tablet — against four for high availability.

---

## 3. What was built

| Artifact | Purpose |
|---|---|
| `DoctorEstateCapacityLevel` | the five-word level vocabulary (`PASS`/`PARTIAL`/`FAIL`/`UNVERIFIED`/`NOT_APPLICABLE`), its worst-of, and the narrowing to the three-word gate vocabulary |
| `DoctorEstateResilienceService` | three per-branch level statuses from ONE snapshot; a Level-1 gate; an attestation-contradiction gate; the composed activation prerequisite |
| `…RepositoryInterface` / `…Repository` | `activeDoctorRoomProfileByBranch()` (one query, two counts) and `activeCoverTargetBranches()` |
| `DoctorEstateResilienceCommand` | prints all three levels and `OVERALL_ACTIVATION_TEST_PREREQUISITE` **before** the aggregate |
| `Phase4aPilotPreparationScanner` | `activation_test_prerequisites_declared` — list INTEGRITY, evaluated **in** Phase 4A unlike its Phase-5 sibling |
| `config/android_release.php` | `activation_test_prerequisites` + `…_attested`, both shipping `false` |

### Independence is the property, not the split

Level 3 keeps its key, its formula and its answer. Level 1 is **stricter** than
the legacy `local_trusted_device_coverage` (it counts devices carrying an
unrevoked credential, not merely eligible ones) and a test pins that ordering.
Level 2 never enters the gate array, so it cannot move `ESTATE_RESILIENCE` or
the `--strict` exit code.

---

## 4. Five defects an adversarial review found before any of this shipped

Two reviewers were asked to refute the design, not approve it. Every finding
below was a real hole in code I had already written or was about to.

| Finding | Fix |
|---|---|
| **Level 1 would have passed on a tablet nobody can log into.** Its first draft counted ELIGIBLE devices, which says nothing about credentials. A branch receiving one tablet with a revoked credential would have turned the activation prerequisite GREEN where no doctor can sign in. | Level 1 counts `eligible − without-credential`, derived by subtraction from lists already collected. No second predicate. |
| **The staffed population is shrinkable, and shrinking it turned the gate green.** Moving the last locked doctor off a tablet-less branch makes it unstaffed, drops it from Level 1, and flips FAIL → PASS with no hardware bought. | `staffed_branch_count` and `doctors_without_home_branch` are published on the gate; any UNSET doctor holds the verdict at UNVERIFIED. |
| **An active cover put a working doctor at a branch no level could see.** A cover deliberately does not move a home lock, so the covered branch counted zero home doctors while a doctor stood in it. | `staffed` = home locks **OR** an approved, currently-running cover; such a branch is added to the universe, not just flagged. |
| **Level 2 in the gate array would have broken the `--strict` contract.** Every fixture without a `ClinicRoom` yields an UNVERIFIED Level 2, so `ESTATE_RESILIENCE` could never read PASS again and `--strict` would be pinned non-zero by an incremental rollout. | Level 2 is a reported field, never a gate. `PARTIAL` and `NOT_APPLICABLE` never reach `worst()`. |
| **The prerequisite would have been a third declared-but-unread list** — the exact defect the config block above it records, where `global_prerequisites` sat for two phases as five strings nothing consumed. | The Phase-4A scanner asserts the list's integrity; this engine measures the same entry and fails on contradiction. Two surfaces, one list, neither silent. |

**A sixth defect the review did not find — running the suite did.** The scanner
check's first draft asserted every activation-test prerequisite was signed
`true`. Three green tests went red, and they were right to: that turns
`android:phase4a-pilot-readiness` red for months over hardware that has not
arrived. **That scanner's subject is the bounded Phase-4A pilot, which is live
and prepared**; reddening it because a *later* rung is short of tablets is the
same conflation this sprint exists to end, and a gate red for months gets
deleted rather than fixed. It now asserts INTEGRITY — every declared
prerequisite has a boolean signature slot, which catches the list and the
signature block drifting apart — and satisfaction stays with
`doctor:estate-resilience`, the surface an activation preflight actually runs.

The room denominator was also refuted twice and is narrower for it: the
room-assignment path (`ClinicVisitService::activeRoomsForBranch()`) filters
branch and status with **no type clause** despite a docblock that says
"treatment rooms", so a doctor can be placed in a sterilization room. Level 2
counts doctor-facing types and reports the wider assignable count beside it, so
the divergence is visible rather than inherited.

---

## 5. What changed about the attestation, exactly

The parent sprint recorded an owner choice: measurement over coupling, the
attestation observed and never enforced. **That choice stands in the direction
it was made for** — an unsigned prerequisite still fails nothing here, and this
engine still writes no signature.

One case narrowed: a signature recorded `true` beside a measurement that is not
PASS now fails `attestation_does_not_contradict_measurement`. Nothing is trusted
more than before; a recorded `true` simply may no longer sit beside a measured
falsehood and be reported as agreement. Six artifacts asserting the broader old
stance were amended rather than left to rot — including the parent sprint doc,
which carries a scoped supersession note rather than a rewrite.

---

## 6. What this sprint did NOT do

- attest anything — both slots ship `false`, and the measured Level 1 is FAIL
- register, move, enrol, approve or revoke any device, credential or authorization
- arm a flag, widen a cohort, touch a lease or make branch lock effective
- weaken `global_prerequisites`, the Phase-5 precondition for fleet-wide enforcement
- change `spare_device_available_per_branch` in key, formula or answer
- add a migration, route, permission or policy

`GLOBAL_ACTIVATION_APPLY_AUTHORIZED=NO`. A policy-revision GO does **not** mean
`ACTIVATION_TEST_COVERAGE=PASS`.

---

## 7. Next

`DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-PROVISIONING-1` — provision one eligible,
credentialled, authorized tablet for **TLK1**, the only staffed branch with
none, then re-measure. Note that ATG3 already holds an eligible tablet and homes
nobody: whether that unit moves or a new one is bought is a provisioning
decision, and the report has named that idle capacity since the parent sprint.
