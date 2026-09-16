# DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1

**Parent:** `DOCTOR-ACCESS-GLOBAL-ACTIVATION-1` (DEFERRED / PRE-ACTIVATION)
**Owner decision this sprint serves:** `TLK1_ACTIVATION_DECISION = PROVISION_ESTATE_SPARE_CAPACITY_FIRST`
**Base authority:** `00232507c1a32f728d628e6bb4503b9831841047` (tree `76619219f8f07eaa7d2a5bb8b4102985cf40aec9`),
GO tag `doctor-access-global-activation-blocker-closure-1-go`, exact-match at production HEAD.

**`ACTIVATION_OCCURRED = NO`. `GLOBAL_ACTIVATION_APPLY_AUTHORIZED = NO`.**

---

## 1. What this sprint found before it wrote a line of code

The owner asked for the estate resilience prerequisite to be closed. The first
job was to find the gate that decides it. **At the baseline commit there was no
gate** — this sprint supplies the missing half, so everything in this section is
stated about `00232507`, not about the tree that ships with it.

`spare_device_available_per_branch` appears in exactly four places, and not one
of them computes anything:

| Location | What it is |
|---|---|
| `config/android_release.php` `enforcement.global_prerequisites` | a string in a list |
| `config/android_release.php` `enforcement.global_prerequisites_attested` | a hand-signed `false` |
| `Phase4aPilotPreparationScanner::globalPrerequisiteCheck()` | asserts a signature exists; returns `NOT_APPLICABLE` inside `phase_4a` |
| docs + tests | prose and pins |

The scanner's own docblock is explicit about its limit:

> This asserts that somebody SIGNED for each prerequisite. It cannot and does not
> measure the world: "a spare device is available at every branch" is a fact
> about a room, not about a database.

That is a defensible position for an *attestation* and an indefensible one for a
programme about to widen enforcement to fifteen doctors: **nothing in the system
could tell the signer whether the thing they were signing was true.**

So the brief's §11 question — does "per branch" mean every configured branch,
every branch with home doctors, or every branch in Doctor service — **has no
source answer.** It was never defined. The only definition of *spare* anywhere is
prose in `docs/runbooks/android-device-loss-replacement-and-decommission.md`:

```
devices per branch = concurrent Doctor stations + 1 spare
```

and `concurrent Doctor stations (peak, not average)` is an operational count
nobody has recorded.

### A claim this sprint made and had to withdraw

The first draft of this document asserted that the station count "exists in no
database table". An adversarial review **refuted it**, and correctly.
`mst_clinic_rooms` is branch-scoped, typed, status-tracked and already populated
per RME branch, and

```sql
COUNT(*) WHERE branch_id = ? AND type = 'treatment_room'
         AND status = 'active' AND deleted_at IS NULL
```

is a real, existing candidate. It was not considered and then rejected — it was
simply missed.

**It is now read, reported, and deliberately not used to decide anything.**
*(Scoped later by `REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1`: it
still decides nothing at Level 3, which is this sprint's subject. Rooms do decide
**Level 2**, a separate question introduced there, over a wider room set and as a
lower bound.)* A
treatment room is an *inventory of rooms*; the formula asks for *peak concurrent
staffed stations*, and the two differ in both directions — three rooms with one
doctor on shift is one station, one room fitted with two chairs is two.
Substituting one for the other would be the same failure this engine exists to
end, merely with a more plausible number than a guess. What the count is good
for is giving whoever records the real figure a sourced starting point on the
same screen, and that is exactly the role it has: an advisory `Rooms*` column
with a footnote, pinned by a test asserting a one-room branch holding two
tablets still reports UNVERIFIED rather than PASS.

## 2. Measured production estate (read-only, 2026-09-15)

Derived from `doctor:fleet-readiness --json`, `doctor:rollout-readiness --json`
and independent SQL against `asia_dental_lab_pilot`.

| Branch | RME | HOME doctors | Devices present | Eligible | Usable creds | Spare @1 station |
|---|---|---|---|---|---|---|
| **TLK1** Telkomas | yes | **2** | **0** | **0** | 0 | **none — no primary either** |
| **LDK2** Landak | yes | 3 | 1 | 1 | 1 | **0** |
| **ATG3** Antang | yes | **0** | 1 | 1 | 1 | **0** |
| **SPN4** Sunu | yes | 10 | 3 | 1 | 1 | **0** |
| MAIN | no | 0 | 0 | 0 | — | n/a |

```
TOTAL_DEVICE_ESTATE      = 5
ELIGIBLE_TRUSTED_DEVICES = 3   ids [3, 5, 6]
REVOKED                  = 2   ids [1, 4]  — BOTH at SPN4
AUTHORIZATION            = 45 target / 45 active / 0 missing / 0 duplicate
CREDENTIALS              = 5 rows, 2 revoked; every eligible device holds exactly 1 usable
ELIGIBLE_DOCTORS         = 15   LOCKED 15 / UNSET 0
REAL_DEVICE_READY        = 15/15
```

**Under every candidate reading of "per branch", the gate fails at every branch:
no branch holds more than one eligible tablet, and TLK1 holds none.**

Two structural facts worth naming:

- **The two revoked units are both at SPN4.** SPN4 physically holds three
  tablets and can use one. A revoked device is never brought back — a repaired
  unit enrols as a new device — so that is not latent capacity.
- **ATG3 holds hardware and homes nobody; TLK1 homes two doctors and holds
  nothing.** A branch scope taken from the device table alone would have hidden
  TLK1 — the only branch that actually cannot work.

## 3. TLK1's gap is physical, not an authorization gap

All 15 doctors already hold ACTIVE authorizations on all 3 eligible tablets, and
cross-branch trusted-device use is valid and proven: every ceremony in the fleet
readiness campaign ran on **one** tablet (device 3, SPN4), including for doctors
homed elsewhere.

A TLK1 doctor is therefore not locked out — they are standing in a room with no
tablet in it. `LOCAL_DEVICE_COVERAGE = OPERATIONAL_RESILIENCE`, never
`DOCTOR_AUTHORIZATION_AUTHORITY`.

## 4. Credential scope — proven from schema, not taken from the brief

`trx_doctor_device_webauthn_credentials` carries `doctor_device_id` and **no
doctor or user column at all.**

```
DEVICE_CREDENTIAL_SCOPE      = DEVICE_SCOPED
CREDENTIAL_TO_DEVICE_BINDING = doctor_device_id
CREDENTIAL_TO_DOCTOR_BINDING = none
PER_DOCTOR_RE_CEREMONIES     = 0
```

One enrolment per tablet covers every doctor authorized on it. Fifteen redundant
per-doctor ceremonies were **not** performed and are not required.

## 5. What was built

A read-only measurement engine, because the owner chose measurement over a
signature on a hope — and explicitly chose **not** to couple the attestation to
it. (Narrowed later for contradictions only; see the note under "The
attestation is observed, never enforced" below.)

| Artifact | Purpose |
|---|---|
| `App\Modules\DoctorAccess\Services\DoctorEstateResilienceService` | per-branch capacity, spare headroom, credential + authorization coverage, failure domain |
| `App\Modules\DoctorAccess\Support\DoctorEstateResilienceVerdict` | `PASS` / `FAIL` / `UNVERIFIED`, and the named branch scope |
| `doctor:estate-resilience` (`--json`, `--strict`) | the operator surface |
| `config/android_release.php` `enforcement.concurrent_doctor_stations_per_branch` | **ships empty** — a home for the input the gate always needed |
| `DoctorEstateResilienceRepositoryInterface` (+ repository) | one read-only query: active treatment rooms per branch, advisory only *(the capacity-policy revision later added two more reads — a room PROFILE that decides Level 2, and active branch covers — leaving this one advisory)* |

**No migration. No route. No permission. No policy.** One read-only repository,
for the treatment-room count only — a bulk *credential* repository was also
written and then deleted as redundant, because the device estate read already
eager-loads credentials. The room count does not reuse
`ClinicRoomRepositoryInterface` deliberately: that interface carries create,
update and delete, and a resilience engine that can write is one that can
manufacture its own capacity.

### Why there is an `UNVERIFIED`

An engine that assumed a station count would be manufacturing the input to its
own gate. So:

- **0 or 1 eligible devices at a branch → FAIL.** Decidable without the station
  count: any count ≥ 1 requires at least two devices. The lower bound is what
  the estate fails today.
- **2 or more, station count undeclared → UNVERIFIED.** Never PASS.
- **2 or more, station count declared → PASS or FAIL against `stations + 1`.**

A declared count below one is treated as *undeclared*, not honoured, so a typo
cannot manufacture headroom. Recording a count costs a reviewed source change —
the same price as an attestation, because it is the same kind of claim: a
statement about a room, made by somebody who has stood in it.

### The attestation is observed, never enforced

The report prints `MEASURED` beside `ATTESTED` and raises a finding when they
contradict. It **does not** gate, block or write the attestation. Signing stays a
human act with a human's name on it — the owner's choice, recorded.

> **SUPERSEDED IN PART by `REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1`
> (2026-09-16), in one direction only.** A CONTRADICTION — a signature recorded
> `true` beside a measurement that is not PASS — now fails a gate of its own,
> `attestation_does_not_contradict_measurement`. The engine still writes no
> attestation, still cannot be satisfied by one, and still fails nothing when a
> signature is merely ABSENT: that is the half of the owner's choice this
> paragraph was written for, and it stands. What changed is that a recorded
> `true` may no longer sit beside a measured falsehood and be reported as
> agreement. See rule 159, ECP-R14.

## 6. Gates, as measured on the estate above

| Gate | Verdict | Why |
|---|---|---|
| `local_trusted_device_coverage` | **FAIL** | TLK1 homes 2 doctors and holds no eligible device |
| `spare_device_available_per_branch` | **FAIL** | no branch holds 2 eligible devices |
| `device_credential_coverage` | **PASS** | 3 of 3 eligible devices carry an unrevoked credential |
| `authorization_coverage` | **PASS** | 45/45, 0 missing, 0 duplicate |
| `eligible_device_set_agreement` | **PASS** | both engines resolve the same eligible device id list — and nothing wider |
| **`ESTATE_RESILIENCE`** | **FAIL** | worst-of |

### Failure domain — loss of one eligible tablet

| Branch | Serviceable after one loss | Home doctors stranded |
|---|---|---|
| TLK1 | already unserved | 2 |
| LDK2 | **NO** | 3 |
| SPN4 | **NO** | 10 |
| ATG3 | n/a — homes nobody | 0 |

**Every staffed branch stops on a single device loss.**

## 6b. What an adversarial review found, and what it changed

Two reviewers were asked to refute this sprint's claims rather than approve
them. They returned **eight** defects that survived scrutiny — the seven below,
all in code this sprint wrote, plus one in its documentation. All are fixed and
pinned by named tests.

| Finding | Fix |
|---|---|
| **A false green.** `spare_device_available_per_branch` took the worst over ALL branches, so an estate of only unstaffed branches reported the named gate as **PASS** holding zero usable tablets — and `attestation()` reads its verdict from that gate, so a signed `true` came back as "no contradiction" | the gate's population is now staffed branches, and an empty population FAILS |
| **A non-monotonic verdict.** An unstaffed branch PASSED with zero devices and FAILED with one, so standing a tablet in an empty room made the gate worse — and would have pinned the prerequisite at FAIL forever over a branch that cannot strand anyone | the spare requirement applies only to branches that home doctors |
| **`worst()` failed open.** `worst(['BANANA'])`, `worst(['pass'])` and `worst([null])` all returned PASS by fallthrough | allow-list; anything outside the vocabulary is FAIL |
| **A gate claiming more than it checks.** `estate_snapshot_agreement` compared only the eligible device id list, while its PASS text said the two engines had read the same hardware — they each read locks and credentials separately | renamed `eligible_device_set_agreement`, **and its message narrowed with it** |
| **Home doctors were counted as lock ROWS.** `Doctor` is soft-deleted and the lock FK is `restrictOnDelete`, so a retired doctor kept sizing their branch and silently disagreed with the fleet engine | live doctors only, with the orphan rows reported as a finding |
| **Two discarded signals** — a branch whose code will not resolve (so its station count can never be declared, that map being keyed by code) and doctors with no home lock at all | both reported as findings |
| **A misleading field.** `device_bound_credential_count` read like "this tablet can be logged into" but omits the `user_verified` and `backup_eligible` halves of admissibility | renamed `unrevoked_credentials_reporting_device_bound` |

The eighth was in the documentation rather than the code, and is recorded in
§1: the claim that no database table could supply the station count.

**A third round then reviewed the FIXES, and found five more.** The most
valuable is the same defect class the second round was convened to eliminate,
sitting one gate away from the one that was fixed:

| Finding | Fix |
|---|---|
| **`device_credential_coverage` printed its PASS text on a FAIL** — "Every eligible device carries at least one UNREVOKED credential", directly contradicted by the list of credential-less devices beside it. Untouched sibling of the gate whose message had just been narrowed | the detail now follows the verdict, and a test asserts it across **every** gate so the next one cannot repeat it |
| **The spare gate's detail named only one of its three FAIL causes** — a branch with four spares and one credential-less tablet was listed under a message about spares. An UNVERIFIED verdict also named **no branch at all**, because the list filtered on FAIL | `spareGateDetail()` names the causes actually found; `branches_unverified` added beside `branches_failing` |
| **The attestation coupling was a duplicated string literal** with no test pinning it. Renaming the gate — exactly what the previous round did to a sibling — would have dropped `attestation()` to its UNVERIFIED default in silence, and the contradiction test would have stayed green because UNVERIFIED is also `!== PASS` | one `GATE_SPARE_DEVICE` constant; the test asserts equality against the **live gate**, not a literal |
| **The monotonicity guard test was vacuous** — it asserted the gate, and the branch it added is excluded from that gate's population, so it would have stayed green if the row itself flipped PASS→FAIL | the test now asserts the row too, and a second test pins the honest scope: the monotonicity is about the SPARE requirement, and an eligible tablet nobody can log into still reddens its row and the credential gate |
| **An orphan-lock finding could name a branch id appearing nowhere else** in the report | the detail says so |

Three rounds, thirteen defects, twelve of them in code written here. Every fix is
pinned by a named test; the suite is **36 tests / 117 assertions**.

## 7. Why this sprint is NO-GO

The owner reported **1–2 tablets in hand**. Closing
`spare_device_available_per_branch` under the weakest possible assumption (one
concurrent station everywhere) needs **four** additional eligible devices:

```
TLK1  needs 2 (it has no primary at all)
LDK2  needs 1
SPN4  needs 1
ATG3  needs 1 if counted (homes no doctor, so excluded under the branch-scope reading used)
```

`MINIMUM_ADDITIONAL_DEVICES = 4` against 1–2 available. The gate cannot reach
PASS, and §74 makes `SPARE_DEVICE_AVAILABLE_PER_BRANCH = PASS` a hard GO
condition.

**Nothing was signed, and no attestation was flipped.** Attesting a prerequisite
this sprint measured as FALSE would be recording something untrue — which is the
exact failure the prerequisite list exists to prevent.

## 8. Provisioning plan (for the operator, when hardware exists)

Owner decision: **new hardware for TLK1; leave ATG3 alone.**

Priority order, highest value first:

1. **TLK1 primary** — the only branch with staffed doctors and no tablet at all.
   Closes `local_trusted_device_coverage`.
2. **TLK1 spare**, **LDK2 spare**, **SPN4 spare** — in that order; SPN4 carries
   ten home doctors but already has a working unit, TLK1 would have none.

For each unit, the canonical sequence is unchanged by this sprint:
physical-possession confirmation → device baseline capture → cryptographic
identity preflight → registration → maker/checker approval → **one** device-scoped
credential enrolment → `doctor:device-bulk-authorize` (the target rises with the
estate, so every doctor must be authorized on the new tablet) → re-run
`doctor:estate-resilience --strict`.

Record the concurrent station count per branch in
`android_release.enforcement.concurrent_doctor_stations_per_branch` at the same
time. Without it the gate stays `UNVERIFIED` even once the hardware is there.

## 9. Invariants held throughout

```
doctor.single_active_session      = false   (unchanged)
doctor.branch_lock                = true    (unchanged)
BRANCH_LOCK_EFFECTIVE             = false   (unchanged)
CURRENT_BROWSER_ENFORCEMENT_SCOPE = [9,15,18] (unchanged)
GLOBAL_FLEET_ENFORCEMENT_ACTIVE   = false   (unchanged)
GLOBAL_ACTIVATION_APPLY_AUTHORIZED= NO
ACTIVATION_OCCURRED               = NO
```

No device registered, no approval written, no credential enrolled, no
authorization created, no attestation signed, no flag armed, no cohort widened,
no lease touched.
