# FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 3.5

## Android production readiness & governance

---

> ### ⚠ Distribution decision superseded (2026-09-03)
>
> **Status:** **SUPERSEDED** — signing and distribution only; see below.
>
> This sprint's **signing and distribution** decision — Play App Signing plus a
> Managed Google Play private app — is **SUPERSEDED** by
> **REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1**
> ([ADR 0010](../adr/0010-android-direct-apk-signing-and-distribution.md)).
>
> Google Play, Managed Google Play and Play App Signing are **NOT used** and are
> **not required**. Distribution is a signed APK installed directly by
> authorised DaengtisiaMS Admin/IT, signed by a self-managed DaengtisiaMS
> production key. The "pilot exception" described below is **deleted** — with
> direct installation canonical, the pilot and the fleet use the same path.
>
> The Phase 3.5 **readiness GO remains valid and is not withdrawn**: release
> governance, versionCode discipline, custody thinking, Device Owner runbook
> concepts, procurement requirements, pilot acceptance gates and the device-loss
> runbook all survive the revision. Only the Google-Play-specific signing and
> distribution assumptions were replaced.
>
> Everything below is preserved as the historical record of what was decided
> then. Do not read it as current authority.

---

## 1. Status

| | |
|---|---|
| Phase 3.5 | **Readiness complete** — the five open decisions are made and enforced |
| Production signing governance | **RESOLVED** ([ADR 0009](../adr/0009-android-production-signing-distribution-and-device-management.md)) |
| Production signing key | **NOT PROVISIONED** — deliberately |
| Real-device validation | **NOT PERFORMED** — no clinic tablet exists |
| `DEVICE_ENFORCEMENT_ACTIVE` | **false** |
| `DOCTOR_BROWSER_LOGIN_DENIED` | **false** |
| Full feature | **NO-GO** |

The full-feature tag `feature-doctor-trusted-android-device-lock-1-go` is **not**
created and must not be until enforcement is genuinely active on real hardware.

This phase produced **decisions and enforcement**, not hardware validation.
Deciding how to sign is not signing.

---

## 2. What Phase 3 left open, and what happened to it

| Phase 3 said | Phase 3.5 |
|---|---|
| Signing custody UNRESOLVED | **Play App Signing**; clinic holds only a resettable upload key |
| Backup/recovery UNRESOLVED | Runbook written **and rehearsed** with a disposable key |
| Rotation UNRESOLVED | App signing key = permanent; upload key = resettable. Asymmetry recorded |
| Distribution UNRESOLVED | **Managed Google Play private app**, with a bounded 1-device pilot exception |
| Device management UNRESOLVED | Self-owned Device Owner (pilot) → EMM dedicated device (fleet, ≥5) |

### The decision that carries the rest

Android app signing is close to a one-way door: the certificate that signs
version 1 must sign every version after it. In *this* system that door is
heavier than usual, because **clearing app data destroys the device identity** —
so an app that cannot be updated can only be reinstalled, and every reinstall
generates a new keypair no administrator has approved. Losing the signing key
costs the fleet's enrolment, not just an update.

An **upload key is resettable; an app signing key is not.** Play App Signing puts
the permanent key in Google KMS and leaves the clinic holding only the
recoverable one. That is the entire argument, and it is why the alternative — a
self-managed keystore on a clinic workstation — was rejected despite being the
simpler thing to build.

Full reasoning, including the rejected alternatives: ADR 0009.

---

## 3. Findings worth keeping

**Rollback intuition from the server is backwards.** You cannot decrement a
`versionCode`: Play refuses the upload and Android blocks the downgrade install.
"Roll back to the previous APK" is not an operation that exists. The real
procedure is *halt the rollout, then ship the older code under a higher
versionCode*. And halting does not recall — devices that already updated stay on
the bad version until a fix ships. Anyone reaching for a rollback under pressure
with server instincts will do the wrong thing, so it is written down.

**The first release on a track cannot be halted.** There is nothing to fall back
to. The pilot's first build is a one-way door — which is an argument for one
pilot device, not five.

**The pilot cannot use the production distribution channel, and that is
structural.** A self-owned Device Owner tablet has *no Google account* — that is
a precondition of `dpm set-device-owner`, not an oversight — so it cannot install
from Managed Google Play. The exception is therefore real and is written down
with a bound (1 device, owner sign-off, Play-signed artifact only) rather than
being discovered by whoever tries it first. Side-loading a *locally* signed build
would validate a signing identity production never uses, which is worse than not
testing.

**Under an EMM, our app is not the Device Owner.** The EMM's DPC is, and it
lock-task-allowlists our package by policy. Phase 3 already made "not Device
Owner" a supported state — the app runs, it just does not lock, and says so.
That decision, made before anyone knew it would matter, is what lets both
management models work without a code change.

**A comment-aware scanner is not the same as a comment-*and*-string-blanking
one.** Phase 3's lesson was to strip comments so a guard stops matching the prose
explaining it. Applying that naively here would have broken the new guard: the
forbidden token is `signingConfigs.getByName("debug")`, which *contains a string
literal*. Blank the strings and the token becomes unmatchable — the guard passes
on a genuinely debug-signed release. So `KotlinSourceScanner` is literal-**aware**
(a `//` inside a string does not open a comment) while leaving literals intact.
Mutant M7 pins exactly this.

**A disposable key proves a procedure, never a production key.** The recovery
drill ran end to end and the strongest evidence in it is not "the file came
back" — it is that artifacts signed *before* the simulated loss and *after* the
restore carry an identical **signer certificate**, which is what an update
actually requires. Comparing the PKCS#7 blocks instead reports a false failure,
because those are signatures over each artifact's own content.

**A guard can disarm itself, and mutation testing is how you find out.** Mutant
M2 — emptying the key-material pattern lists — *survived* the first version of
this suite. Both `no_committed_key_material` and `keystore_ignored` are absence
assertions, and an absence assertion over an empty pattern list passes on
nothing at all: two checks reporting PASS while enforcing zero. It is the same
family as the Phase 3 vacuity problem, arriving from the opposite direction, and
no amount of reading the code would have surfaced it. Fixed with a
`key_material_guard_armed` check that fails closed on an empty or disarmed list.

**The string literals that had to be preserved were also an attack surface.**
Security review found the sharpest defect in this sprint, and it followed
directly from the previous finding. Because literals are kept, a file-wide
search for `release {` can be *hijacked* by any earlier string containing that
text:

```kotlin
val releaseNotes = "release { see the wiki }"   // wins the match
android { buildTypes { release {
    signingConfig = signingConfigs.getByName("debug")   // never scanned
} } }
```

The reviewer ran this through the real scanner: it printed **PASS —
"Release build type does not fall back to the debug signing identity"** about a
build that does exactly that. The non-vacuity check did not catch it because it
asserted its markers against the *whole file* while the forbidden-token check
read the *extracted block* — so the markers resolved in a block nothing had
scanned. A variant with the decoy inside `mapOf(...)` yielded an **empty**
extraction that also passed.

Fixed three ways: extraction is anchored to `android > buildTypes > release`
rather than a file-wide match, the marker check now runs against the extracted
block, and an empty block is a FAIL. Mutants M13 and M14 pin both halves.

**Three more guards could be disarmed by an edit nothing noticed**, all found in
the same review: the armed set was a hardcoded pair covering two of six declared
patterns (M15); `keystore_ignored` substring-matched raw `.gitignore` text, so a
comment mentioning the pattern satisfied it — and so did `!android/**/*.jks`,
a negation that *un-ignores* keystores while the check reported they were
ignored (M16); and the enforcement-coupling scan skipped missing directories
silently while covering `app/Http/Middleware`, which is **not** where this
codebase registers route middleware — a Phase 4 hook at
`app/Modules/DoctorDevice/Middleware` would have been invisible to the one
invariant this phase most needs to hold (M17). Required surfaces now fail closed
when absent; the future enforcement paths are *watched* rather than required,
because they legitimately do not exist yet.

The pattern across all of these is worth naming: **the architecture was sound and
the detection was not.** Every defect was a guard that would report success
while enforcing nothing.

**And then the trap sprang on this sprint, from the other side.** Bounding the
scanner's file reads introduced a suppressed read, which put this file into the
exact-set inventory that `CriticalGateWarningContractTest` maintains. The right
fix was the guarded shape that contract itself calls exemplary — `is_readable()`
then an explicit `=== false` — rather than growing the exception list. But the
suite still went red afterwards, because the **comment explaining why the
suppression operator is avoided contained the operator's name**, and that
contract matches raw source. Rule 14 of `.cursor/rules/141`, demonstrated on its
own author within the hour it was written. The prose no longer names it.

---

## 4. What was built

**Governance as code**

- `config/android_release.php` — signing, distribution, device management,
  versioning, compatibility, device spec, pilot gates, enforcement stages,
  scanner contract. Every forbidden literal lives here, never in the scanner,
  so no guard can match its own source.
- `app/Support/Android/KotlinSourceScanner.php` — comment stripping that is
  string- and char-literal aware, with **nesting** block comments.
- `app/Support/Android/AndroidReleaseGovernanceScanner.php` — 17 read-only
  checks. Signs nothing, needs no secret, reaches no network.
- `php artisan android:release-readiness [--json] [--strict] [--manifest=]` —
  safe on a fork PR because it holds nothing worth stealing.

**Documents**

| Document | Answers |
|---|---|
| [ADR 0009](../adr/0009-android-production-signing-distribution-and-device-management.md) | why these four decisions, and what was rejected |
| [signing governance](../governance/android-production-signing-governance.md) | who signs, with what, kept where, what breaks |
| [release & rollback](../runbooks/android-release-distribution-and-rollback.md) | how to ship and how to un-ship |
| [key backup & recovery](../runbooks/android-signing-key-backup-and-recovery.md) | key lost or leaked |
| [device loss & decommission](../runbooks/android-device-loss-replacement-and-decommission.md) | tablet gone |
| [provisioning](../runbooks/android-clinic-device-provisioning.md) | tablet → trusted device |
| [tablet procurement](../operations/clinic-tablet-procurement-specification.md) | what to buy |

**Rule**: `.cursor/rules/141-android-production-release-governance.mdc`.

---

## 5. Phase 4 — real device pilot plan

### 5.1 Scope

```
1 device · 1 branch · 1 controlled pilot identity · scheduled window
```

Deliberately conservative. Follow the standing production-UAT governance for the
pilot identity — a production Doctor account is not used casually.

### 5.2 Entry conditions

Every one, before the window opens:

- [ ] pilot tablet procured and accepted on arrival
- [ ] Play Developer account created (**role account**)
- [ ] Play App Signing enrolled
- [ ] upload key generated, backed up, restore drill passed
- [ ] pilot build signed by the production authority
- [ ] pilot branch selected
- [ ] pilot identity approved
- [ ] rollback operator **named**
- [ ] `php artisan android:release-readiness --strict` exits 0

### 5.3 Acceptance checklist

Every check on the **real device**. Emulator results satisfy none of them.
Machine-readable: `config/android_release.pilot.acceptance_checks`.

*Artifact and signing*
- [ ] artifact signed by the production authority
- [ ] signing certificate fingerprint recorded

*Device identity*
- [ ] real Android Keystore identity generated
- [ ] hardware-backed result **recorded truthfully**
- [ ] StrongBox result **recorded truthfully** (either way)

*Kiosk*
- [ ] Device Owner = true
- [ ] Lock Task active
- [ ] Home escape blocked
- [ ] Recent Apps escape blocked
- [ ] external browser escape blocked
- [ ] reboot returns to the clinic app

*WebView security*
- [ ] external origin blocked
- [ ] cleartext blocked
- [ ] TLS error fails closed

*Enrolment and lifecycle*
- [ ] enrolment verified end to end
- [ ] `ACTIVE` accepted
- [ ] `DISABLED` denied
- [ ] `REVOKED` denied
- [ ] session invalidated after revoke

*Clinical boundaries — Phase 1 and 2, on the device*
- [ ] Doctor login through the trusted device
- [ ] daily branch lock verified
- [ ] device ↔ branch intersection verified
- [ ] room selection verified
- [ ] other-room active patients hidden
- [ ] cross-room direct access denied
- [ ] Doctor RME print denied
- [ ] Doctor odontogram print denied

*Recovery*
- [ ] rollback verified

### 5.4 Stop and rollback

**Stop immediately** on: a clinical workflow blocked with no workaround; any
patient data visible across a room or branch boundary; a kiosk escape; device
identity accepted after revocation; any suspicion of signing compromise.

**Rollback** — enforcement is off, so the fallback is always available:
1. Doctor returns to browser login (never denied in Phase 3.5).
2. Revoke the pilot device.
3. If the release is bad: halt the rollout, forward-fix
   ([runbook](../runbooks/android-release-distribution-and-rollback.md) §3).
4. Record what happened before anyone's memory of it improves.

**Success** = every acceptance check passed, no clinical disruption, rollback
demonstrated rather than assumed.

---

## 6. Enforcement rollout — designed, not activated

```
OFF                                   ← Phase 3.5 ends here
 ↓
PILOT_BRANCH_OR_DEVICE                ← Phase 4
 ↓
SELECTED_DOCTORS_APPROVED_DEVICE_SET
 ↓
ALL_READY_DOCTORS
 ↓
GLOBAL_DOCTOR_ENFORCEMENT             ← Phase 5
```

**There is no feature flag.** Phase 2 decided that the flag is created by the
phase that needs one; creating it "in preparation" is how a half-wired switch
gets flipped by someone who assumes it is finished. `enforcement.flag_exists` is
`false` and the readiness gate asserts it.

Before global enforcement (`enforcement.global_prerequisites`):

- real-device pilot passed
- **every** enforced Doctor has an `ACTIVE` device
- a spare per branch
- device-loss runbook rehearsed
- rollback to browser login proven

The second and third are the ones that turn an outage into an inconvenience.

---

## 7. Branch readiness

Evidence-based only. `UNKNOWN` stays `UNKNOWN`; a guess here becomes a purchase
order.

| Branch | Device? | Enrolled? | Device Owner? | Doctor coverage | Fallback | Network | Pilot | Enforcement ready |
|---|---|---|---|---|---|---|---|---|
| Telkomas (TLK1) | none | no | no | UNKNOWN | browser | UNKNOWN | no | **no** |
| Landak (LDK2) | none | no | no | UNKNOWN | browser | UNKNOWN | no | **no** |
| Antang (ATG3) | none | no | no | UNKNOWN | browser | UNKNOWN | no | **no** |
| Sunu (SPN4) | none | no | no | UNKNOWN | browser | UNKNOWN | no | **no** |

Branch set from the RME-enabled branch registry. Zero tablets exist, so every
row is `no` — which is the honest state, and exactly why enforcement is off.

Doctor coverage and peak concurrent stations are marked `UNKNOWN` rather than
estimated: they are a Phase 4 input, gathered by counting stations per branch,
and an invented number here becomes a fleet size nobody can defend.

---

## 8. Readiness matrix

| Item | Result |
|---|---|
| Production signing governance | **PASS** |
| Production key actually provisioned | **NO** — no approved custody environment yet; deliberately not created |
| Signing recovery procedure | **PASS** |
| Test recovery exercise (disposable key) | **PASS** |
| Distribution strategy | **PASS** |
| Device Owner strategy | **PASS** |
| Provisioning runbook | **PASS** |
| Tablet minimum specification | **PASS** |
| Procurement shortlist | **PASS** (dated candidates; per-SKU verification required at purchase) |
| Pilot acceptance checklist | **PASS** |
| Pilot rollback plan | **PASS** |
| App update strategy | **PASS** |
| Signing compromise response | **PASS** |
| Device-loss response | **PASS** |
| Server/client compatibility policy | **PASS** |
| Phase 4 prerequisites | **PASS** |

Nothing unknown was promoted to PASS. "Production key actually provisioned" is
**NO** and stays NO: creating an irreplaceable production identity on an
ephemeral context, with no recorded custodian, to make a sprint look green is
precisely the failure this governance exists to prevent.

---

## 8a. Evidence

| Gate | Result |
|---|---|
| `DoctorDeviceAndroidReleaseGovernanceTest` | **31 passed** (124 assertions) |
| `android:release-readiness --strict` | **GO — 17/17 PASS, 0 WATCH, 0 FAIL** |
| DoctorDevice + DoctorRoomScoped + Cicd + Devflow | **490 passed / 0 failed** (3902 assertions) |
| RME + AccessControl + Pilot + DoctorDevice | **3303 passed / 0 failed** (14636 assertions) |
| Mutation matrix | **18 mutants / 18 killed / 0 survivors** |
| Independent security review (after fixes) | **CRITICAL 0 / HIGH 0** |
| Signing recovery drill (disposable key) | **PASS** — signer certificate identical across the simulated loss |
| `sprint:manifest-check` / `sprint:scope-audit --strict` | GO |
| `foundation:devflow-check --strict` / `shared-service-audit --strict` | GO |
| `foundation:roadmap-check --strict` | GO |
| Android tree | byte-identical to the Phase 3 GO commit; `gradlew` still `100755` |
| pint / `git diff --check` | clean |

Mutation reverts were by **copy** of a pre-recorded snapshot, verified by
sha256 after every mutant. `git checkout --` cannot restore untracked files and
would have discarded uncommitted work, and one bad revert silently invalidates
every later verdict.

Four DB-touching governance gates (`security-compliance`, `cicd-enterprise-gate`,
`enterprise-documentation`, `ci-runtime-control`) could **not** run in the
isolated worktree — it has no database credentials, so they fail with
`SQLSTATE[08006]` before reaching any assertion. That is environment, not code;
CI runs them against a real database.

---

## 9. Phase 4 entry checklist

1. Procure one approved pilot tablet.
2. Verify it against the minimum specification on arrival.
3. Create the Play Developer account (role account) and enrol Play App Signing.
4. Generate the upload key; back it up; pass the restore drill.
5. Sign the pilot build with the production authority.
6. Provision the tablet as Device Owner.
7. Enrol and approve the device; bind it to its branch.
8. Select the pilot branch.
9. Select the pilot Doctor identity under UAT governance.
10. Schedule the window.
11. Name the rollback operator.
12. Verify both rollback paths — server and Android.
13. Begin Phase 4 DEVFLOW.

Items 1 and 3 are human actions with lead times. Nothing in Phase 4 starts
without them.
