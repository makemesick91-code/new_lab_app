# DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1

**Half-B readiness: giving five signed prerequisites something to be wrong about.**

Branch `feature/doctor-access-global-device-enforcement-readiness-1`, base
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
(do NOT target `main`). Baseline = production HEAD
`4fec066cf7b5b3ae88885a9678753489da950e19`, tree
`d5b7a1f3cbe971e4151f74cf62a0fad553507f1d`, `git describe --exact-match` =
`doctor-access-global-activation-half-a-1-go`.

> ## WHAT THIS SPRINT IS NOT
>
> ```
> HALF_B_ACTIVE                   = NO
> HALF_B_APPLY_AUTHORIZED         = NO
> GLOBAL_FLEET_ENFORCEMENT_ACTIVE = false
> governance_phase                = phase_4a   (unchanged)
> global_permitted                = false      (unchanged, source-controlled)
> attestations signed             = 0 of 5     (unchanged — none signed)
> ```
>
> No flag was armed, no cohort changed, no attestation recorded, no tablet
> provisioned, no doctor enrolled. **READINESS GO IS NOT APPLY APPROVAL.**

---

## 1. The defect, in four lines

```php
// Phase4aPilotPreparationScanner::globalPrerequisiteCheck()
$declared = config('android_release.enforcement.global_prerequisites');        // 5 strings
$attested = config('android_release.enforcement.global_prerequisites_attested'); // 5 booleans
$missing  = array_filter($declared, fn ($n) => ($attested[$n] ?? null) !== true);
return $missing === [] ? 'PASS' : 'FAIL';
```

It compares two lists in config. It reads no measurement of any kind — by
design, and its own docblock says so. The consequence is the one this
programme has spent several sprints removing everywhere else:

> **attested `true` + measured `false` ⟹ PASS**

The single artifact standing between this deployment and a fleet-wide clinical
lockout could be satisfied by typing `true` five times. Two of the five were
**known FALSE in the estate** on the day the signature block shipped, and the
config comment says so in as many words.

`DoctorEstateResilienceService::attestation()` had already closed this for
**one** prerequisite. Four had no measurement to be compared against, and one
of those four — `rollback_to_browser_login_proven` — had **zero occurrences
under `app/`**.

---

## 2. The three stated blockers, re-derived from deployed source

Per the brief, these were treated as hypotheses and re-derived rather than
trusted. One was materially different from its description.

| # | Stated | Re-derived from `4fec066c` | Still blocking? |
|---|---|---|---|
| A | "4 more tablets for Level 3" | **CONFIRMED, and it is a Half-B prerequisite** — not an obsolete assumption. See §3. | YES, for **activation**. Not for this sprint's deliverable. |
| B | `rollback_to_browser_login_proven` has no producer | **CONFIRMED but mis-stated.** The rollback is neither undocumented nor untested — `DoctorAccessEnforcementRollbackTest` performs each disarm across four sections. What is absent is a **producer a gate can read**: zero occurrences under `app/`. | YES → closed here |
| C | Four prerequisites have no contradiction coverage | **CONFIRMED exactly.** The contradiction detector lives in `DoctorEstateResilienceVerdict`/`Service` and covers `spare_device_available_per_branch` only — and in a **different engine** from the one the phase gate reads, so signing it `true` would pass the phase gate today despite the measured FAIL. | YES → closed here |

**B is the correction worth carrying forward.** A sprint briefed to "prove the
rollback" would have written more tests. The tests were already there and they
were good. The gap was that a green suite on a branch is not a measurement the
deployment can read, so the prerequisite could only ever be "satisfied" by
flipping a hand-signed boolean.

---

## 3. HALF_B_DEVICE_CAPACITY_REQUIREMENT — resolved

```
HALF_B_DEVICE_CAPACITY_REQUIREMENT = LEVEL 3 (spare_device_available_per_branch)
AUTHORITY_SOURCE                   = config/android_release.php
                                     enforcement.global_prerequisites[2]
                                     + Phase4aPilotPreparationScanner::globalPrerequisiteCheck()
```

The brief asked not to make Level 3 a Half-B prerequisite "merely because it
exists". It is one on the authority of the source: `spare_device_available_per_branch`
is **literally element three of the declared `global_prerequisites` list**, and
outside `phase_4a` every declared prerequisite must be attested `true` or the
gate fails. Under HB-R1 it may only be signed once it MEASURES `PASS`.

It measures **FAIL**. Level 3 requires four more tablets.

**This is a fact about hardware, not about code, and no sprint closes it by
writing software.** The distinction that matters:

| | Requires the 4 tablets? |
|---|---|
| Half-B **ACTIVATION** | **YES** — unavoidably, and the requirement is not weakened here |
| This sprint's **READINESS** deliverable | **NO** — the deliverable is the measurement machinery; the tablets are what the machinery will keep reporting as missing |

`ADDITIONAL_PHYSICAL_DEVICES_REQUIRED_NOW = 0` for readiness,
`= 4` before Half B may be activated. Nothing was simulated, no device id was
invented, and no phantom hardware was created.

Level 1 (`trusted_device_activation_test_coverage`) is PASS and signed;
Level 2 is PARTIAL and gates nothing; Level 3 is FAIL and unsigned — rule 159
owns the levels, rule 161 owns the list, and this sprint conflates neither.

---

## 4. What shipped

### `DoctorGlobalEnforcementPrerequisite` (Support)

The vocabulary and the rule, expressed once. `contradicts()` is the single
place `attested && measured !== PASS` exists. `worst()` folds over an
allow-list, returns **UNVERIFIED for an empty set**, and fails closed on
anything outside its vocabulary — both properties are pinned by test, and both
were shipped broken by a sibling in this programme.

### `DoctorHalfBRollbackProofService` — the producer that was missing

It proves a rollback **by performing one**, in-process, against the live
posture, and undoing it before it returns:

1. **CAPTURE** the live pre-activation posture — mode, cohort, `global_permitted`,
   the enforcement flag. Captured, never remembered: the cohort is the union of
   two host variables, has no database representation, and moved inside a single
   day once already.
2. Move the process config to the **activated** posture and ask the real
   `DoctorAppLoginGate` whether a doctor **outside today's cohort** may hold a
   browser session. Under Half B they may not.
3. **RESTORE** the captured posture and ask again. The rollback is proven when
   the same doctor, on the same deployment, is admitted.
4. Confirm the global denial is gone **from the resolved scope**, never from the
   value that was written back.
5. Confirm every captured key is back, key for key.

It is safe on production: it writes no file, no row and no audit entry, runs no
`config:cache`, changes no environment value, and its only database access is
reading the doctor accounts it needs a subject from. Every override lives in the
process's own config repository and is undone in a `finally`.

**Why an out-of-cohort subject.** A doctor already inside the pilot cohort is
denied before AND after, so they demonstrate nothing about rolling a *widening*
back. The subject is a doctor Half B would newly capture — exactly the
population a rollback has to release. If no such doctor exists the measurement
is **UNVERIFIED**, never PASS.

### `DoctorGlobalEnforcementReadinessService` — all five, measured

| Prerequisite | Producer | Composed from |
|---|---|---|
| `real_device_pilot_passed` | cohort × device-login proof | `DoctorFleetReadinessService` |
| `every_enforced_doctor_has_an_active_device` | provisioning verdict | `DoctorGlobalRolloutReadinessService` (carried through, never paraphrased) |
| `spare_device_available_per_branch` | Level 3 gate, by gate key | `DoctorEstateResilienceService` |
| `device_loss_runbook_rehearsed` | evidence artifact | canonical JSON, ROLL-5-1A pattern |
| `rollback_to_browser_login_proven` | **executed rehearsal** | new, above |

Every engine is **composed, never re-implemented** — a second authorization
calculator is a second thing to keep in step, and this programme has already
paid for one predicate written down twice.

**Two verdicts, deliberately not one.** `verdict` is the readiness of the
*instruments*; `activation_prerequisites` is the readiness of the *world*. A
green machinery verdict beside a red world verdict is the **expected and
correct** state of this deployment: the instruments are honest and they are
saying do not activate. `authorizes_activation` is `false` unconditionally.

**Snapshot agreement.** The estate engine composes the fleet engine internally,
so this report holds two fleet snapshots taken microseconds apart. Rather than
assume they agree, the invariant both publish is compared and a disagreement
**demotes every measurement drawn from them to UNVERIFIED**. This programme has
a recorded open defect of exactly this shape.

### `global_prerequisite_attestations_do_not_contradict_measurement` (scanner)

The false green, closed where it lived. A **second, always-evaluated** row
beside the signature-only sibling.

- **Not phase-scoped.** Its sibling is a Phase-5 precondition and correctly goes
  quiet inside `phase_4a`. A false signature is not a Phase-5 event — it is
  recorded now, in a reviewed change, months before the phase moves.
- **Zero cost until somebody signs.** With nothing attested it answers from
  config alone and issues **zero database queries** (pinned by test). This
  scanner runs in CI and in the release-evidence chain.
- **Fails closed.** Once a signature exists, an unreadable measurement is a
  FAIL.
- Resolved via `app()` rather than injected, following the documented precedent
  in `DoctorAppLoginGate::deviceCredentialLoginAvailable()` — the readiness
  service depends on the scanner, and a constructor cycle is a needless price.

### `doctor:half-b-readiness`

`--json`, `--strict` (machinery), `--activation-preflight` (world, exit 2 —
a strictly harder question, mirroring `doctor:estate-resilience`).
**A contradiction exits 1 whether or not `--strict` was asked for**; every other
condition describes a rollout in progress and exits 0, so the command is safe in
a deploy chain.

---

## 5. Evidence

### Tests — `DoctorHalfBEnforcementReadinessTest`, 33 passed / 104 assertions

Contradiction coverage is proven **per prerequisite**, not in aggregate: the
dataset runs all five, and each must measure not-PASS, contradict, and turn the
verdict BLOCKED.

### Adversarial review found four defects, two of them false greens

Two reviewers were asked to **refute**, not approve. Everything below was found
by them and fixed before merge. They are recorded because three of the four are
the same failure shape this sprint exists to remove — *a mechanism that reads as
a check and cannot fail*.

| # | Defect | Why it mattered |
|---|---|---|
| 1 | **The snapshot cross-check was dead, and failed OPEN.** It compared `authorization_matrix.doctor_count` and `estate_totals.eligible_doctor_count` — **neither of which any engine publishes** — so it hit a `-1` sentinel on every real build and returned `agrees => true`. The demotion was unreachable and the finding could never be emitted. | A documented mechanism that does not run, whose fallback reports *absence of evidence* as *agreement*. Now keyed to `authorization_matrix`, which both engines genuinely publish (the fleet from its own run, the estate from the fleet run it performs internally — so comparing them compares the two snapshots), and a missing invariant is now UNVERIFIED. |
| 2 | **An orphan signature was invisible.** Both engines derived "what is signed" by filtering the *declared* list. Delete a name from `global_prerequisites`, leave its `true` beside it, and nothing checked it — and the signature-only sibling only notices a *fully* empty list and is NOT_APPLICABLE in `phase_4a` anyway. | The single edit that removes a prerequisite from oversight also removed it from the detector built to catch exactly that. Now iterates the **union** of declared ∪ attested. |
| 3 | **Two rollback steps were tautologies, with no baseline.** `denyBrowserSessionReason()` returns `null` on its first line when the flag is off, so "admitted after rollback" reduced to *did the boolean we just wrote read back as false*. The deny step asserted only `!== null`. Nothing measured the subject's state **before** the move. | Added `captured_posture_admits_the_subject` as a real baseline, and the deny step now asserts the exact code `DENY_NO_DEVICE_SESSION` — anything else means something other than the scope widening is denying them. |
| 4 | **The flag restore was lossy.** It wrote the resolved boolean into both `default` and `env_value`, so an entry whose `env_value` was `null` (no override) came back as `false` (an override recorded as off). Same resolved value, different fact — and `capture()` compared only the collapsed boolean, so the restore step could never fail for it. | Captures and restores the whole entry; an entry that did not exist is removed rather than written as `false`. |

Two smaller ones, same spirit: a throw was recorded as a failing
`rollback_restores_browser_login` even when it happened before any rollback was
attempted (now its own step id, so one evidence array can no longer carry the
same id twice with two statuses); and `'mutations' => 0` was a **hardcoded
literal asserting the property a reader most wants evidence for** — the exact
pattern this class replaces. It was first derived from the restoration step,
then **removed**, because mutation testing showed a derived projection of an
already-reported step cannot be made to fail independently of it.

**Two overclaims were also corrected rather than defended.** The rehearsal
reaches the gate through the config repository, which is resolution step 1 —
strictly above the runtime `env()` read — so it does **not** exercise the
environment file or the config cache. It never claimed to in code, but the
docblock said "proves the rollback CHAIN", which is more than it measures. The
payload now states the limit, and the runbook owns the other half.

And one claim the reviewers **could not** refute, which is worth recording
because it is the load-bearing safety argument: the rehearsal moves process-local
config, and no concurrent request can observe it — there is no Octane, neither
this service nor the scanner that consults it is reachable from any route or
controller, and an Artisan process serves no HTTP request. All three were checked
against this deployment. Fact two is one controller away from being false, so the
class says so.

### Mutation — 11 applied across two rounds, **0 survivors**

| Mutation | First run | After |
|---|---|---|
| `contradicts()` → `false` | KILLED (7) | KILLED |
| `worst([])` → PASS | **SURVIVED** | KILLED |
| rollback `finally` restore removed | **SURVIVED** | KILLED |
| subject ignores cohort | KILLED (2) | KILLED |
| future-date guard removed | KILLED (1) | KILLED |
| scanner check removed | KILLED (3) | KILLED |
| no-producer no longer blocks | KILLED (6) | KILLED |
| flag restore back to the lossy form | — | KILLED |
| union → declared-only (orphan hole) | KILLED | KILLED |
| missing invariant → "agrees" | **SURVIVED** | KILLED |
| malformed signature ignored | KILLED | KILLED |
| deny reason not asserted | **SURVIVED** | KILLED |

**Four survivors across the two rounds, every one a real gap in the tests.**

1. `worst([])` was never exercised. That is the exact "empty population" defect
   that let a sibling gate report PASS while the estate held zero usable
   tablets.
2. The `finally` restore **is only load-bearing on the throw path.** On the
   happy path the posture is restored mid-rehearsal, so the suite stayed green
   with the `finally` deleted.
3. The snapshot **fail-closed branch was never entered**, because both engines
   always publish the invariant in a normal build. A test that drives them with
   no invariant at all now pins the direction.
4. The **exact deny code** was never contradicted, because the real gate always
   returns the expected one. A gate double returning a different reason now
   pins it.

Every one of the four was invisible to a green suite. Sources verified
byte-identical to their pre-mutation copies afterwards.

---

## 6. Half-A regression

Untouched and pinned by test. `DoctorSessionLeaseService::enabled()` is
`single_active_session AND probe` and does not consult `branch_lock`; this
sprint reads neither. `single_active_session` stays TRUE, `branch_lock` stays
FALSE, `BRANCH_LOCK_EFFECTIVE` stays false, all 15 home locks retained and
inert.

---

## 7. Pre-live validation model

DaengtisiaMS has no organic clinical traffic, so:

```
PRE_LIVE_OPERATIONAL_OBSERVATION = NOT_APPLICABLE_PRE_LIVE
```

and **`NOT_APPLICABLE_PRE_LIVE` is neither PASS nor real-world operational
proof.** It is replaced by the controlled matrix in §5, which exercises the
authorized/unauthorized/revoked/invalid-binding paths, the browser denial under
a simulated global posture, the non-Doctor invariant, the rollback, and
prerequisite degradation after attestation.

---

## 8. What is still true after this sprint

- Half B is blocked on **four tablets** and on **five signatures nobody may
  honestly give yet**. That has not changed and this sprint did not try to
  change it.
- What changed is that those blocks are now **measured** rather than
  **declared** — and a signature recorded against any of them will now be
  contradicted by the deployment it was recorded on.

**NEXT: STOP. `APPROVE_HALF_B_GLOBAL_DEVICE_ENFORCEMENT_APPLY=YES/NO` is the
owner's, separately, in their own words.**
