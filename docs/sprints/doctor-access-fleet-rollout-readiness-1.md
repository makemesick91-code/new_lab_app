# DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1

Bring the complete doctor fleet to operational readiness for a **future**
`DOCTOR-ACCESS-GLOBAL-ACTIVATION-1`. This sprint ends at readiness, and performs
no global activation.

Base `3239d5ae` (docs-only commits on top of the deployed runtime `d7d645cb`).
Parent GO tag `doctor-access-single-session-branch-lock-1-go` → `d7d645cb`,
tree `6f99364b`, exact-match at production HEAD.

---

## 1. What production actually said, before anything was changed

Read-only inventory, 2026-09-13, against `srv1730088`.

| | |
|---|---|
| `ELIGIBLE_DOCTORS` | 15 — every one active, linked 1:1 to an active `mst_doctors` row, pivoted to all four RME branches |
| `LOCKED_DOCTORS` | **1** (drg Karmila → SPN4) |
| `UNSET_DOCTORS` | **14** |
| `ELIGIBLE_TRUSTED_DEVICES` | 3 — device 3 (SPN4), 5 (LDK2), 6 (ATG3); all `active` + `cryptographically_verified` |
| device estate | 5 rows; 2 revoked (terminal, correctly excluded) |
| `AUTHORIZATION_TARGET/ACTIVE/MISSING/DUPLICATE` | 45 / 45 / **0** / **0** |
| usable credentials | 3 — exactly one per active tablet, **all five rows `registered_by = 1` (IT Support)** |
| `REAL_DEVICE_READY_DOCTORS` | **3** — users 9, 15, 18 |
| devices with a successful readiness proof | 3 / 3 |
| flags | `doctor.branch_lock=true`, `doctor.single_active_session=false` |
| posture | `bounded_pilot`, cohort `[9,15,18]`, `GLOBAL_ENFORCEMENT_ACTIVE=false` |

---

## 2. The finding that reframed the sprint

`doctor:rollout-readiness` reported, on production, on the day this sprint
started:

```
TARGET_DOCTOR_COUNT=15
READY_DOCTOR_COUNT=15
NOT_READY_DOCTOR_COUNT=0
READINESS_VERDICT=GLOBAL_READY
```

Three days earlier the same command reported `READY=3 / PARTIAL`. **Nothing
clinical changed between those two readings.** What changed was PR-C's bulk
authorization run, which wrote 45 correct rows.

`pathFor()` declares a doctor's path complete on: active authorization, active
device, verified identity, and *the device* holding a usable credential. That is
a faithful implementation of rule 152's GR-R4 — and it is a **provisioning**
measure. It contains no home-branch check and no evidence that any doctor ever
logged in. So the moment every doctor held an authorization on a tablet that
already had a credential, all fifteen became "ready" without a clinician
touching anything.

The verdict was renamed `GLOBAL_READY` → **`TRUSTED_PATHS_COMPLETE`**. The
engine is unchanged and remains exactly as strict as it was; only the
over-claiming name is gone. `GLOBAL_READY` is the string a future activation
sprint greps for, and GR-R1/GR-R2 already say plainly that no readiness verdict
is permission to switch enforcement on. Blast radius was contained: the const
had no consumers outside its own command and two test files.

---

## 3. Credentials are device-bound — verified, not assumed

`trx_doctor_device_webauthn_credentials` has **no `doctor_id` and no
`user_id`**, deliberately, and its migration and registration service both say
so in their own words: the credential proves *which piece of hardware* is
present; *which doctor may use it* is the authorization table's question.
Binding them together would make a shared clinic tablet unrepresentable.

The doctor is identified by the **password step** that precedes the assertion —
`completeLogin()` takes the user as a parameter and nothing derives a user from
a credential. `user_verification` is `required`, which proves *a human passed
the device screen lock*, not *which* human.

Three independent adversarial verifiers were asked to refute this, each with a
different lens (request lifecycle, hunt-for-a-counterexample, schema and
scopes). **All three returned `refuted=false`.**

Two consequences this sprint records rather than papers over:

- **No per-doctor credential enrolment is required.** A doctor authorized on a
  tablet that already has a usable credential needs no new ceremony hardware —
  subject to the physical caveat that the key must live in the authenticator
  they are standing at (`residentKey: discouraged`, `platform` attachment), i.e.
  the same tablet and browser profile.
- **A readiness proof means "this ACCOUNT reached a clinical session through
  this TABLET".** It is not proof of which clinician was present. The report
  states this in its own payload and prints it beneath the verdict.

---

## 4. Two operational gates found by reading, not assuming

**The branch-lock surface 404s.** `assertCapabilityArmed()` →
`DoctorEffectiveBranchResolver::enabled()` requires `doctor.branch_lock` **AND**
`doctor.single_active_session` **AND** an observable session probe. Production
has the second `false`, so the assignment screens are 404 right now, and there
is no Artisan fallback. The approval **service**, however, deliberately does not
read the flags — the surface is the gate.

**A doctor outside the enforcement cohort cannot produce a device proof.**
`denyBrowserSessionReason()` returns null for them, so they log straight in by
browser and no ceremony occurs. Obtaining proof for the remaining 12 means
rotating them through the bounded cohort (hard cap 5), which temporarily
device-enforces them — with a real lockout if a ceremony fails.

---

## 5. Owner decisions

**Home branch matrix** — dictated explicitly, doctor by doctor, after being
shown the evidence and every conflict in it. Nothing was inferred.

| doctor | user | code | branch | note |
|---|---|---|---|---|
| 15 drg Irwan | 28 | DOC-LDK001 | **LDK2** | code + busiest branch agree |
| 16 drg Fahira | 14 | DOC-SPN002 | **TLK1** | three-way conflict; owner chose the freshest signal |
| 17 drg Nisa | 9 | DOC-LDK002 | **LDK2** | visits said TLK1; owner chose code + context + tablet |
| 18 drg Ramadhan | 12 | DOC-LDK003 | **LDK2** | code + visits agree |
| 20 drg Fiitri | 15 | DOC-TLK002 | **TLK1** | **against all observed signal** — see below |
| 21 drg Karmila | 18 | DOC-SPN001 | SPN4 | already locked, untouched |
| 22–30 (9 doctors) | 19–27 | DOC-SPN003..011 | **SPN4** | no signal of any kind; owner accepted the code prefix |

`drg Fiitri` was flagged explicitly before the decision was confirmed: all three
of her visits, her only online context and her proven tablet are ATG3, and a
home lock narrows her operational lists to the locked branch. The owner
confirmed TLK1 with that consequence stated. **Recorded as an explicit owner
decision taken against the observed signal**, recoverable by permanent transfer
or temporary cover, both of which exist.

Validation against production before any write: **14 proposed, 0 invalid
branches, 0 outside a practice set, 0 already locked, 0 transfers required.**

**Ceremony path** — the owner chose to build a governed CLI rather than arm
`single_active_session` for a live clinic. The design review that preceded it is
section 6.

---

## 6. What shipped

### `doctor:fleet-readiness` — the gate an activation decision should read

`DoctorFleetReadinessService` **composes** the provisioning engine rather than
re-implementing it; its per-doctor state and reason codes are carried through
verbatim. It adds three dimensions provisioning does not have:

1. **Home branch lock** — from `mst_doctor_branch_locks`; no row is UNSET.
2. **A login that actually happened** — from the two success audit actions,
   keyed on `performed_by`. Chosen over the two scalar columns because
   `last_authorized_login_at` is written on the Android path alone (it would
   call proven PWA doctors unproven) and `last_used_at` has no doctor to
   attribute to at all (on a shared tablet it would let one doctor's login mark
   another ready).
3. **Whole-estate authorization**, reconciled from the estate rather than taken
   from the tool that writes it, so a fourth tablet reopens the matrix.

Fail-closed throughout: absence of evidence is NOT READY, and an empty
population is NO-GO with a `no_eligible_population_to_measure` finding rather
than a free pass.

### `doctor:branch-lock-bulk-assign` — a thin governed adapter

Drives the owner matrix through `DoctorBranchLockApprovalService`, twice per
doctor, exactly as the two HTTP endpoints do. It decides nothing: every branch
validation, row lock, session invalidation, `markOffline`, online-impact
acknowledgement and audit row belongs to that service.

- **Dry run is the default** and writes nothing at all.
- `--apply` requires `--confirm-plan=<digest>` over the matrix *and its
  preconditions*, so consent binds to the delta and estate drift fails closed.
  A digest rather than a prompt, because a prompt auto-answers under SSH.
- `--maker` and `--checker` are both required and must differ; each is checked
  for the permission its half of the workflow needs, through the Gate so a role
  grant and the Super Admin bypass both count. Shell access is not authorization.
- **Never infers a branch.** An entry it cannot read is REFUSED, never guessed
  and never dropped. An empty matrix is a refusal, never "everyone".
- A doctor locked elsewhere is `TRANSFER_REQUIRED` and stops — an initial
  assignment never silently becomes a permanent transfer. Locked to the same
  branch is `ALREADY_SATISFIED`, which is what a second run must produce.
- An online doctor is held back as `ONLINE_NEEDS_ACKNOWLEDGEMENT` unless named
  in `--acknowledge-online`.

**A defect the tests caught.** The matrix originally parsed into a PHP array
keyed by doctor id, which cannot represent `17=SPN4,17=LDK2` — it silently
collapses to whichever came last, and the tool would have assigned a branch
nobody approved while reporting success. It now parses to a list of pairs so the
duplicate survives to be refused.

---

## 7. Tests

| file | tests |
|---|---|
| `DoctorFleetReadinessTest` | 24 |
| `DoctorFleetReadinessNonMutationTest` | 8 |
| `DoctorBranchLockBulkAssignTest` | 18 |

The non-mutation suite is structural as well as behavioural: it scans the engine
source for the primitives that could write, spawn, fetch, read a request or move
a flag, so a future edit that adds one fails there rather than on production. It
also pins the query budget as **constant across fleet size** — measured 21 on
the fixture, with a documented ceiling of 24 and the reasoning for the headroom.

---

## 8. What this sprint does NOT claim

- It is **not** activation. `doctor.single_active_session` stays `false`,
  `GLOBAL_ENFORCEMENT_ACTIVE` stays `false`, and the pilot cohort stays
  `[9,15,18]`.
- 45 authorization rows are **45 rows**, not 45 proven login combinations.
- A device proof identifies a **tablet**, not a clinician.
- A readiness GO authorises a later sprint to **decide**. It never authorises
  the switch.

Durable rules: `.cursor/rules/156-doctor-fleet-rollout-readiness.mdc`
(**FR-R1..FR-R16**).
