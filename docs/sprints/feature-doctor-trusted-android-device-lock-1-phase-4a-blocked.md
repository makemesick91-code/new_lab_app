# FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 4A attempt: BLOCKED / NO-GO

**Date:** 2026-09-04 (readiness resolved 07:14 WITA, UTC+08:00)
**Outcome:** `PHASE_4A_STATUS = BLOCKED / NO-GO`
**GO tag created:** none. Neither
`feature-doctor-trusted-android-device-lock-1-phase-4a-app-only-real-device-pilot-go`
nor the full-feature `feature-doctor-trusted-android-device-lock-1-go` exists,
and neither may be created from this attempt.

Phase 4A was the **non-destructive, app-only** re-scoping agreed at the close of
Phase 4: real tablet, real production-signed APK, real Keystore identity, real
enrolment and approval, pilot-scoped enforcement — with Device Owner, Lock Task
and full kiosk explicitly deferred to Phase 4B so that no device would have to be
factory-reset.

That re-scoping removed blocker **B2** (the personal, in-use tablet). It did not
remove **B1**, and B1 is upstream of everything the pilot is made of.

**Enforcement state is unchanged from Phase 3 and remains deliberately off:**

| Signal | Value |
|---|---|
| `DEVICE_ENFORCEMENT_ACTIVE` | `false` |
| `DOCTOR_BROWSER_LOGIN_DENIED` | `false` |
| `doctor.trusted_device_enforcement` | default off |
| `enforcement.current_stage` | `off` |
| `enforcement.may_be_enabled_in_phase` | `5` (unchanged — see §5) |
| `ANDROID_REAL_DEVICE_VALIDATION` | `false` |
| `REAL_KEYSTORE_VALIDATION` | `false` |
| `REAL_CRYPTOGRAPHIC_ENROLLMENT` | `false` |
| `PRODUCTION_SIGNING_KEY_PROVISIONED` | `false` |
| `PRODUCTION_CERTIFICATE_PINNED` | `false` |
| `DEVICE_OWNER_REAL_DEVICE_VALIDATION` | `DEFERRED_PHASE_4B` |
| `LOCK_TASK_REAL_DEVICE_VALIDATION` | `DEFERRED_PHASE_4B` |
| `KIOSK_REAL_DEVICE_VALIDATION` | `DEFERRED_PHASE_4B` |

---

## 1. Authority resolved

Resolved independently at the start of the attempt rather than assumed from the
prior sprint's record.

| Signal | Value |
|---|---|
| Integration branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| `ORIGIN_INTEGRATION_HEAD` | `62fdcc05ddd8af93877342d7b7ddcda8af98de50` |
| `ORIGIN_INTEGRATION_TREE` | `c06fef08cbfc03fa7d7286502eee58c0488a3a06` |
| Latest capability GO tag | `revision-doctor-auto-device-approval-app-only-login-1-go` |
| GO tag commit | `fe3b6bf0a9414b3f98bf8969b3f81b83b5a27d37` |
| GO tag tree | `dda7368e21a8f04e7de53dc97661aa4183beb31a` |
| Phase-4 blocked evidence commit | `62fdcc05` (PR #379, docs-only) |
| `VPS_HEAD` | `fe3b6bf0a9414b3f98bf8969b3f81b83b5a27d37` |
| `VPS_TREE` | `dda7368e21a8f04e7de53dc97661aa4183beb31a` |
| `VPS_EXACT_TAG` | `revision-doctor-auto-device-approval-app-only-login-1-go` |

**Production is one commit behind the integration branch, and deliberately so.**
`62fdcc05` is the Phase-4 docs-only evidence commit; it carries no runtime,
schema, flag or asset change, so it was never deployed and production remains at
an exact GO-tag match. This record follows the same precedent (§7).

---

## 2. What was NOT done

Recorded explicitly, because a blocked phase is only trustworthy if its
non-actions are auditable.

- **No production signing key generated. No keystore created anywhere.**
- No certificate fingerprint pinned; `signing.production_certificate_sha256`
  remains `null`.
- No APK built, signed, installed or distributed. No release manifest produced.
- No factory reset, no Device Owner provisioning, no Lock Task activation, no
  account removed, no personal app or file touched.
- **Nothing was installed on the tablet and no command was run against it.** The
  device was never authorised for this session (§4.3), and authorisation was not
  bypassed.
- No enrolment performed, so **no `DoctorDevice` or `DoctorDeviceAuthorization`
  row was created in production**.
- No feature flag flipped, no enforcement stage advanced, no pilot target
  written, no migration, no seed.
- No application runtime, route, policy, permission or schema change of any kind.
  This record is documentation only.
- `enforcement.may_be_enabled_in_phase` was **not** edited from `5` to `4`
  (§5).
- `.sprint/current.yml` left as-is, following the Phase-4 evidence precedent. It
  is a rolling file whose declarations (`runtime_change`, `schema_change`,
  `frontend_change`) *over*-declare relative to a docs-only diff, which is the
  safe direction — a manifest contradiction is declaring `false` where the diff
  shows `true`, never the reverse.
- Primary checkout untouched: it stayed on its unrelated branch at `b18188c2`,
  and its two pre-existing dirty files were neither read into nor written from
  this work.

---

## 3. Why Phase 4A could not start

### B1 — Signing custody is still an open organisational fact

This is the whole of it. Verified on the current integration head, not inherited
from the Phase-4 record:

| Custody requirement | Source of truth | Verified state |
|---|---|---|
| Production keystore exists | filesystem sweep of the signing workstation | **absent** |
| `production_certificate_sha256` pinned | `config/android_release.php:88` | `null` |
| Custody register / designated custodians | repository and workstation search | **absent** |
| 3 encrypted copies, offsite + sealed cold | `signing.backup` | **absent** |
| Designated signing workstation | `release_signing_context` | **not designated** |

The only keystores present on the workstation are an Android **debug** key, an
upload key belonging to an **unrelated project**, and the desktop keyring. None
of them is, or may become, a DaengtisiaMS production signing identity.

**Owner decision, 2026-09-04: custody is not established — do not generate the
production signing key.**

This is the correct outcome rather than an unfortunate one. The key is
`key_loss_recoverable => false` with
`key_loss_consequence => fleet_wide_reenrolment_after_forced_reinstall`, and
rule 141 §3 ("never create a production key to make a sprint green") exists
precisely to stop a permanent, non-rotatable key being minted so that a phase can
report progress. The rule held.

**Phase 4A does not soften this.** An app-only pilot is still a pilot, and
installing a debug-signed artifact on a pilot device would validate a signing
identity that production will never use — an install path proven against the
wrong trust anchor is worse than no proof, because it reads as evidence.

Everything downstream is blocked by arithmetic, not by judgement:

> no key → no production-signed APK → no certificate pin → no verified install →
> no real Keystore validation → no enrolment → no approval → no pilot enforcement
> → no browser-denial proof → no session-binding proof → no update-in-place proof

### B4 — Owner sign-off and pilot subject remain unsupplied

`enforcement.may_be_enabled_in_phase => 5` is guarded by
`requires_owner_signoff` and `rollback_owner_named_before_start`. Neither was
supplied, and:

- no **pilot Doctor** identity has been chosen,
- no **pilot branch** has been chosen,
- no **rollback owner** has been named.

**Owner decision, 2026-09-04: not yet chosen — do not guess, and do not default
to the first Doctor found.** These stay explicit entry-gate items.

### B3 — Still a single device

One tablet. `spare_device_available_per_branch` remains unmet. It gates *global*
enforcement rather than a pilot, but with a single unit there is no fallback if
the pilot device fails and the device-loss runbook still cannot be rehearsed.

---

## 4. What this attempt did establish

Three findings the next attempt should not have to rediscover.

### 4.1 The pilot enforcement model already exists — it was never the gap

The Phase-4A brief anticipated having to design an `OFF / PILOT / GLOBAL`
enforcement model, and warned against blurring pilot and global by editing
`may_be_enabled_in_phase` from `5` to `4`.

**No such model needs to be built.** `config/android_release.php` already carries
a five-value ladder:

```
off
pilot_branch_or_device
selected_doctors_approved_device_set
all_ready_doctors
global_doctor_enforcement
```

`current_stage => 'off'`, and `global_prerequisites` already scopes the
`real_device_pilot_passed` / `spare_device_available_per_branch` /
`rollback_to_browser_login_proven` conditions to **global** enforcement
specifically. Pilot enforcement is therefore a *governed revision of the stage*,
not a violation of the phase gate and not a new abstraction.

The consequence matters for planning: **Phase 4A carries no enforcement-model
implementation work.** The remaining Phase-4A scope is entirely (a) closing
custody, and (b) executing the real-device pilot against it.

### 4.2 The `5 → 4` edit is a sign-off decision, not a code change

Because the pilot stage already exists, the phase number is doing exactly one
job: recording that an owner has accepted the pilot's clinical risk. Editing it
without `requires_owner_signoff` and a named rollback owner would be weakening a
production safety rule to manufacture a tag. It was refused in Phase 4 and is
refused here for the same reason.

### 4.3 The tablet is reachable but not authorised

`TAB-AUDIT-01` is physically connected and enumerated over USB, but reports
`unauthorized` — the ADB daemon was restarted since the Phase-4 audit, which
invalidates the previous host authorisation.

Re-authorising requires a physical **"Allow USB debugging"** tap on the tablet by
the operator. This was not bypassed, and there is no supported way to bypass it.
It is a one-tap operator action, not a blocker in its own right — recorded so the
next attempt budgets for it rather than being surprised by it.

The Phase-4 hardware finding stands unchanged and does not need re-running: the
unit clears the procurement specification and provides a **hardware-backed
Keystore on real silicon**, with StrongBox absent as expected for the price tier
and explicitly tolerated by the specification. What remains unproven is
**key-level** `KeyInfo` attestation for an actual DaengtisiaMS key, which cannot
be obtained without an installed production-signed app.

---

## 5. Governance deliberately left unchanged

No governance value was edited by this attempt. In particular:

| Value | State | Why unchanged |
|---|---|---|
| `enforcement.may_be_enabled_in_phase` | `5` | Requires owner sign-off + named rollback owner (§3, B4) |
| `enforcement.current_stage` | `off` | Advancing it is the pilot itself, which is blocked |
| `signing.production_certificate_sha256` | `null` | Nothing to pin; pinning a fingerprint no key produced would be a fiction |
| `doctor.trusted_device_enforcement` | default off | Flipping it without an enrolled, approved device is a clinical lockout |

Rule 141 §1, §3, §4, §9 and §11 all applied to this attempt and all held. No rule
was narrowed, excepted or reinterpreted to accommodate the phase.

---

## 6. Phase 4 / 4A / 4B — how these records relate

Phase 4's record is **not** superseded or corrected by this one. It remains
accurate history.

| Phase | Scope | Status |
|---|---|---|
| **Phase 4** | Original Device-Owner real-device pilot | **BLOCKED / NO-GO** — B1 custody, B2 personal tablet, B3 no spare, B4 sign-off |
| **Phase 4A** | Non-destructive **app-only** real-device pilot; Device Owner and kiosk deferred | **BLOCKED / NO-GO** — B1 custody, B4 sign-off. B2 resolved by the re-scoping |
| **Phase 4B** | Dedicated-device Device Owner / Lock Task / kiosk hardening | **NOT STARTED** — requires a dedicated, factory-fresh tablet |
| **Phase 5** | Global doctor enforcement | **NOT STARTED** |

The 4A/4B split did what it was meant to do: it removed the factory-reset
requirement from the critical path and reduced four blockers to two. Both
survivors are organisational, and neither is a defect in the Phase 1–3.5
implementation, which remains correct and deployed with enforcement off.

---

## 7. Why this record was not deployed

This commit changes documentation only — no runtime, schema, flag, permission,
route or asset. Production sits at an exact GO-tag match
(`revision-doctor-auto-device-approval-app-only-login-1-go`), and deploying an
evidence commit would move it off that match while changing nothing a clinic can
observe. The Phase-4 evidence commit `62fdcc05` was left undeployed for the same
reason, and this project has a standing precedent of deliberately undeployed
evidence commits.

`DEPLOY_PERFORMED = false (docs-only, deliberate)`

---

## 8. Entry checklist for the next Phase-4A attempt

Carried forward from Phase 4 and re-scoped for the app-only pilot. Two items are
now closed by the re-scoping itself; the rest are unchanged and unmet.

| Prerequisite | Blocker | State |
|---|---|---|
| 3 signing custodians designated | B1 | ☐ |
| Signing workstation designated | B1 | ☐ |
| Access-controlled release source | B1 | ☐ |
| Production key generated, 3 encrypted copies, offsite + sealed cold | B1 | ☐ |
| Restore drill passed (certificate SHA-256 parity) | B1 | ☐ |
| Certificate fingerprint pinned via reviewed change | B1 | ☐ |
| Owner sign-off recorded for pilot enforcement | B4 | ☐ |
| Rollback owner named before start | B4 | ☐ |
| Pilot Doctor explicitly selected (never guessed) | B4 | ☐ |
| Pilot branch explicitly selected | B4 | ☐ |
| Tablet re-authorised for ADB (one physical tap) | — | ☐ operator action |
| Spare device for the pilot branch | B3 | ☐ gates global, not the pilot |
| Dedicated factory-fresh tablet | B2 | ☑ **not required for 4A** — deferred to 4B |
| Device Owner requirement re-scoped to 4B | — | ☑ agreed 2026-09-04 |
| Pilot enforcement model exists | — | ☑ **already shipped** — see §4.1 |

**The first six rows are one decision.** Until signing custody is a real
organisational fact, Phase 4A cannot begin, and no amount of tooling can
substitute for it.
