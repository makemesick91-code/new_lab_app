# DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1

**Status:** implemented and tested. **Readiness verdict on production: NOT READY**
— 3 of 15 doctors are provisioned, and the remaining twelve need physical
ceremonies that no sprint can perform from a terminal.

Branch `feature/doctor-pwa-global-rollout-readiness-1`, base
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @
`f122d237`. Durable contract: `docs/architecture/doctor-pwa-webauthn.md` §18.
Rules: `GR-R1…R20` in `.cursor/rules/152-doctor-global-rollout-readiness.mdc`.

**Global enforcement was false when this sprint started and is false now.**
Nothing here can arm it.

---

## 1. Authority

| | |
|---|---|
| Previous GO | `doctor-pwa-multi-doctor-pilot-1-go` → tag object `df7809ae`, commit `4d1b57fa`, tree `3bfb6302` |
| Production HEAD | `4d1b57fa`, tree `3bfb6302`, `git describe --exact-match` → the same tag |
| Base | `f122d237` — the pilot merge, one commit ahead of the deployed tag, docs only |
| Live cohort | user ids `9, 15, 18` |

Production and the GO tag agree exactly, so the measurements below describe the
runtime the tag names rather than something adjacent to it.

## 2. What was measured, and what it found

Fifteen active Doctor-role accounts, each linked one-to-one to an active doctor
record. No orphans, no unlinked accounts, and no doctor for whom an exclusion
could be justified — so the target population is all fifteen.

Three of them have a complete trusted path:

| user | doctor | device | authorization | credential |
|---|---|---|---|---|
| 9 — drg Nisa | 17 | 5 · LDK2 | 4 | 3 |
| 15 — drg Fiitri | 20 | 6 · ATG3 | 5 | 5 |
| 18 — drg Karmila | 21 | 3 · SPN4 | 2 | 2 |

Each is corroborated in the audit trail by `DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS`
rows whose `performed_by` is the expected user — so doctor attribution is server
evidence rather than an assumption about who was holding the tablet.

**The other twelve hold no device authorization at all.** They therefore have no
credential and no rehearsal either. Every one falls at the same gate, and it is
the gate a terminal cannot open.

### The estate, for completeness

Five devices exist. Three are active and cryptographically verified; two are
revoked and stay that way — revocation is terminal, they are counted, and they
never contribute to readiness. Five credentials exist, three usable. The two
revoked ones carry meaningless revoke reasons and are historical; they were not
rewritten, and rewriting append-only history to tidy a past mistake is not a
thing this sprint does.

### Why branch is not the constraint

Every doctor is pivot-authorized to practise at all four RME branches, so a
per-branch count keyed on that pivot returns fifteen for every branch and means
nothing. The report gives three separate numbers per branch, and the one that
matters is "doctors whose complete path runs through a device registered here".

## 3. The gate that failed on success

`android:phase4a-pilot-readiness` exited **1** on production and printed
`PHASE4A_PILOT_PREPARATION=NOT READY`, on a deployment where the pilot was
working exactly as designed.

It was correct by its own contract. It was written for a preparation sprint whose
claim was that it shipped nothing armed, so it failed whenever the enforcement
flag was armed — and the pilot armed it deliberately, with the owner's approval.
§17 of the architecture doc recorded the contradiction as a known limit rather
than fixing it.

A gate that reddens on the outcome the programme exists to reach is a gate
operators learn to ignore, and an ignored gate protects nothing.

### The fix, and the part that did not move

The status narrowed to the condition the durable rules already described, and
which the scanner was already computing one line above for its own detail text:
**the flag armed while the scope covers nobody**. That state denies no doctor
anything, so it cannot lock a clinic out, but it reads as protection while
providing none. Browser denial configured outside a declared scope stays a
failure exactly as before, and a test pins both halves.

The check id was not renamed. A sibling suite asserts by name with the message
"Check {id} disappeared from the preparation scanner", and ten-plus documents
cite it.

### What replaced the breadth that was dropped

Enforcement stopped being a boolean. A boolean cannot separate an owner-approved
three-doctor pilot from a clinic-wide lockout, and that conflation *is* the
defect. There are now four postures — `off`, `bounded_pilot`,
`global_rollout_readiness`, `global` — with the observed one derived from the
running state and compared against a declaration held in source control.

The declaration is a **ceiling, not an equality**. The same reviewed code runs
with enforcement off on a developer machine, off in CI, and armed on production;
demanding they match would either redden CI or force the declaration down to the
weakest deployment, which would stop it auditing production at all. What must
never happen is the reverse — a deployment enforcing more than anyone reviewed —
and that is what fails.

`global_rollout_readiness` sits at the same enforcement strength as the pilot it
describes, deliberately not above it. If measuring outranked the pilot, declaring
readiness would itself be an activation.

## 4. A second staleness, found while fixing the first

The report printed `GLOBAL_ENFORCEMENT_ACTIVE=false` by reading a hardcoded
`false` out of the activation-boundary block. It was true, and it was true because
somebody typed it.

That block is a legitimate historical record of what one preparation sprint did
not do, and it was left alone. But the activation checklist reads that line before
arming anything, and a safety assertion that cannot become false is not an
assertion. So a **measured** line was added beside it: `global_enforcement_not_active`
asks the resolved scope, and `GLOBAL_ENFORCEMENT_ACTIVE_LIVE` prints the answer.

Two of the boundary's other claims — `PILOT_ACTIVATED=false` and
`DEVICE_ENROLLED=false` — are now factually wrong on production, since the pilot
is activated and devices are enrolled. They are left as the historical record they
are, under the header that already says "what this sprint did NOT do", and named
here so the next reader does not mistake them for live state.

## 5. What shipped

**The engine.** `DoctorGlobalRolloutReadinessService` with one public method,
behind a new `DoctorDeviceRolloutReadinessRepositoryInterface`. Four queries for
the whole fleet, eager-loaded, so the shape scales past five branches.

The new interface exists rather than a widened old one for a specific reason.
`DoctorDeviceWebAuthnCredentialRepositoryInterface` says in its own docblock that
it deliberately has no "all credentials" accessor, because "a credential list is a
map of the clinic's hardware estate, and the login path has no reason to be able
to enumerate it". A fleet readiness engine has to enumerate exactly that.
Widening the existing interface would have handed the enumeration to
`DoctorAppLoginGate`, which is constructor-injected with it. So the enumeration
went somewhere the gate is never given, and a test asserts the gate's constructor
still cannot reach it.

**The command.** `doctor:rollout-readiness`, `--json` and `--strict`. NOT_READY
exits 1 either way; PARTIAL exits 0 unless `--strict`; GLOBAL_READY exits 0. The
asymmetry is deliberate: a staged rollout is PARTIAL for its whole duration, and a
gate that reddens that long is a gate somebody removes from the deploy chain.

> **Amended 2026-09-13 by `DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1`.** That top
> verdict is now emitted as **`TRUSTED_PATHS_COMPLETE`**, not `GLOBAL_READY`. Exit
> codes are unchanged. The old name claimed more than this engine measures — it has
> no home-branch check and no evidence anyone ever logged in — and a bulk
> authorization run moved it from PARTIAL to its top value without a clinician
> touching a tablet. Fleet readiness is `doctor:fleet-readiness` (rule 156).


**The console refusal.** `ForbiddenConsoleCommandGuard` at `CommandStarting`,
salvaged from the closed PHASE4A activation pull request — see §7.

**The read-only guarantee, structurally.** A test scans the engine, the command
and the repository for the primitives that could write, spawn, fetch or read a
request. That is a stronger claim than "it did not mutate on the paths it took
today": a future edit that adds one fails in CI rather than in production.

## 6. Tests

| Suite | Cases | What it defends |
|---|---|---|
| `DoctorGlobalRolloutReadinessTest` | 19 | the five conditions, each falsified alone; null and true `backup_eligible`; verdict drift; one good path among broken ones |
| `DoctorGlobalRolloutReadinessCommandTest` | 11 | the exit asymmetry, every printed token, JSON parity, membership ≠ readiness |
| `EnforcementPostureGovernanceTest` | 14 | the defect fix, the security case it must not take with it, the four postures, the ceiling, the measured global line |
| `DoctorGlobalRolloutReadinessNonMutationTest` | 9 | source scan, row-level byte identity, no audit row, no route, the gate's constructor boundary |
| `ForbiddenConsoleCommandGuardTest` | 12 | exact match, fail-closed environments, wired at the event, excluded from reporting |

One of these found a real defect while being written: the engine emitted a field
called `credential_id` holding a row primary key, one rename away from the column
that holds actual WebAuthn credential material. It is now `credential_row_id`.

### A trap worth recording

`Tests\Feature\Deploy\ForbiddenConsoleCommandGuardTest` matches **no token** in
the critical gate's filter. It would have been declared mandatory and never
actually run. `ForbiddenConsoleCommand` was added to both the GitHub-hosted and
self-hosted filter variants — editing one desynchronises the gate.

## 7. PR #392, and why it was closed rather than merged

Its merge base is `bfcb6cc0`, so the three-dot diff shows what the branch
*contains*, not what it would *change*. `git cherry` against authority tells the
real story: of five commits, two are already upstream byte-identical, two landed
and were then superseded, and one is genuinely new.

| Piece | Disposition |
|---|---|
| `ForbiddenConsoleCommandGuard` + exception + listener + config keys | **ported** — authority has only a static file scanner, and no runtime refusal existed |
| `Phase4aEnforcementSwitch` | **not ported** — its preconditions demand exactly one covered doctor, and `pilotDoctorUserId()` returns null for a cohort by design, so it would refuse to operate on the live pilot |
| `EnvFileWriter` | **not ported** — `write()` is a bare `file_put_contents` with no temp-file-and-rename, on both the apply and the rollback path, so a crash mid-write truncates the production environment file |
| `pilot_device_label` → `_02` | **dropped** — a single scalar label stopped describing a three-tablet fleet |
| scope command, scope report, four test files | **dropped** — authority is strictly ahead; merging would revert the cohort |
| enrolment service, requests, controllers, view | **already merged**, byte-identical |

Merging the branch would have dragged single-doctor scope semantics back over the
multi-doctor cohort. The audited arm/disarm switch is still genuinely missing —
`config/android_release.php` requires a `pilot_enforcement_scope_changed` audit
event that has no producer — and it needs rewriting against cohort semantics with
a dry-run and the atomic-write fix before it is ever pointed at production.

## 8. What the twelve still need

Per doctor, in order, and none of it performable from a terminal:

1. an authorization approved through the canonical screen;
2. a credential registered on a trusted tablet under their own biometric;
3. one genuine login plus one protected request, so the audit trail names the
   exact user, device, authorization and credential;
4. per device intended for the rollout, one browser assertion and one installed-app
   assertion, attested by the operator — the server cannot distinguish them,
   because their user agents are byte-identical.

Rehearsals run in batches no larger than the source-controlled ceiling of five, so
twelve doctors are at least three rotations, and the cohort returns to `9,15,18`
afterwards.

### The tablet policy, decided as a candidate and gated

Twelve doctors and three tablets. A shared clinic tablet's biometric attests that
*the device* was unlocked, not which clinician was present, so this had to be an
owner decision rather than a provisioning detail.

**Decided candidate: per-branch tablets with a per-doctor Android profile** — and
explicitly a candidate, not a proven implementation. Nothing is provisioned under
it until a real-device feasibility gate passes on actual clinic hardware:
`docs/sprints/doctor-pwa-tablet-policy-feasibility-gate.md`.

**Sharing one profile across doctors is not an available fallback.** If any
isolation invariant fails, the fallback is one clinic device per doctor. Hardware
cost is not a reason to weaken the binding.

The schema already decides most of the outcome, which is why the gate is targeted
rather than exploratory. `mst_doctor_devices.public_key_fingerprint` is UNIQUE and
enrolment resolves a device by it, so **if each Android profile generates its own
Keystore key**, two profiles on one tablet become two separate device rows — and
`deviceProofDenyReason()` scopes credential lookup to one device row, so a
credential from Profile A already cannot keep a session alive on Profile B. If
Android instead shares one key across profiles, both collapse to a single device
row and every one of those guarantees goes at once. That single question is the
load-bearing test, and the gate says to run it first and stop if it fails.

One constraint falls straight out of the schema: `UNIQUE(branch_id, device_name)`
means two profile-devices at one branch need distinct names, so a naming
convention has to exist before anyone enrols.

## 9. What this does not authorise

Not activation. Not a widened cohort. Not a claim that any of the twelve is ready.
`READINESS_VERDICT` on production is `PARTIAL` with `NOT_READY_DOCTOR_COUNT=12`,
and no readiness GO tag is issued while that is true — a tag saying otherwise
would be the first false statement in a chain the whole programme is built to keep
honest.

Global enforcement remains false in the source guard, the effective configuration,
the runtime scope report and the newly measured live line.
