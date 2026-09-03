# FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 4 attempt: BLOCKED / NO-GO

**Date:** 2026-09-04 (audit 06:21 WITA, UTC+08:00)
**Outcome:** `PHASE_4_STATUS = BLOCKED / NO-GO`
**GO tag created:** none. Neither `…-phase-4-…-go` nor the full-feature
`feature-doctor-trusted-android-device-lock-1-go` exists, and neither may be
created from this attempt.

**Enforcement state is unchanged from Phase 3 and remains deliberately off:**

| Signal | Value |
|---|---|
| `DEVICE_ENFORCEMENT_ACTIVE` | `false` |
| `DOCTOR_BROWSER_LOGIN_DENIED` | `false` |
| `doctor.trusted_device_enforcement` | default off |
| `enforcement.current_stage` | `off` |
| `ANDROID_REAL_DEVICE_VALIDATION` | `false` |
| `DEVICE_OWNER_REAL_DEVICE_VALIDATION` | `false` |
| `LOCK_TASK_REAL_DEVICE_VALIDATION` | `false` |
| `PRODUCTION_SIGNING_KEY_PROVISIONED` | `false` |
| `PRODUCTION_CERTIFICATE_PINNED` | `false` |

This document exists because the attempt produced one genuinely durable result —
a real-hardware capability audit — and four blockers that the next attempt should
not have to rediscover.

> **Continuation, 2026-09-04.** The Phase 4A/4B split agreed in §6 was
> attempted the same day. The app-only re-scoping cleared blocker **B2** — a
> personal tablet is acceptable for Phase 4A because Device Owner is deferred
> — but **B1 (signing custody) and B4 (owner sign-off, pilot subject) remain
> open**, so Phase 4A is also `BLOCKED / NO-GO`. Nothing in this record is
> superseded or corrected; see
> `docs/sprints/feature-doctor-trusted-android-device-lock-1-phase-4a-blocked.md`.

---

## 1. Authority resolved

| Field | Value |
|---|---|
| GO tag | `revision-doctor-auto-device-approval-app-only-login-1-go` |
| Tag object | `78e9df4` |
| Commit | `fe3b6bf0a9414b3f98bf8969b3f81b83b5a27d37` |
| Tree | `dda7368e21a8f04e7de53dc97661aa4183beb31a` |
| Also | tip of `origin/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |

Verified against the working repository, not assumed from the prior handover.

## 2. What was NOT done

Recorded explicitly, because a blocked phase is only trustworthy if its
non-actions are auditable:

- No production signing key generated. No keystore created anywhere.
- No certificate fingerprint pinned; `signing.production_certificate_sha256`
  remains `null`.
- No APK built, signed, installed, or distributed.
- No factory reset, no Device Owner provisioning, no Lock Task activation.
- No package installed on the tablet; no app data, account or setting altered.
  The audit was entirely read-only (`getprop`, `pm list`, `dumpsys`,
  `settings get`).
- No enrolment performed, so **no `DoctorDevice` or `DoctorDeviceAuthorization`
  row was created in production**.
- No feature flag flipped, no enforcement enabled, no migration, no deploy.
- Primary checkout untouched: it stayed on its unrelated branch at `b18188c2`,
  and its two pre-existing dirty files were not read into or written from this
  work.

---

## 3. Real-device audit — the durable result

Device pseudonym **`TAB-AUDIT-01`** (serial masked `RRGY…AZL`). The serial is
deliberately not recorded in governance rules; see rule 141 on keeping transient
hardware identity out of durable rules.

### 3.1 Identity — genuinely physical hardware

| Property | Value |
|---|---|
| Manufacturer / model | samsung / `SM-X236B` (Galaxy Tab A9+) |
| Product / device | `gta11pxx` / `gta11p` |
| SoC | `mt6878` |
| Android | 16 (API 36) |
| Security patch | 2026-07-05 |
| Build type | `user` |
| Verified boot state | `green` |
| ABI | `arm64-v8a` |
| `ro.kernel.qemu` | `0` |

`ro.kernel.qemu=0` with real Samsung/MediaTek build properties and a `green`
verified-boot state establishes this is **real hardware, not an emulator**. Prior
phases were explicitly barred from claiming real-device validation on emulator
results; that bar is met for the audit, though not for the pilot (§5).

### 3.2 Conformance to the procurement specification

| Requirement | Minimum | Observed | Verdict |
|---|---|---|---|
| Android version | 13+ | 16 (API 36) | PASS |
| RAM | 4 GB | 5.24 GB | PASS |
| Storage | 32 GB | 104 GB | PASS |
| Screen | ≥10″ | 11″ | PASS |
| Hardware-backed Keystore | **required** | present | PASS |
| StrongBox | preferred, not mandatory | **absent** | recorded |
| Device Owner capable | **required** | see §5 | **FAIL (instance)** |

The Galaxy Tab A series (A9+ or newer) is already named in the procurement
specification as an accepted lowest-cost route. **The model is acceptable. The
particular unit is not usable** — that distinction is the whole of §5.

### 3.3 Keystore capability — closes a standing Phase-4 unknown, partially

Reported features:

```
android.hardware.hardware_keystore=300     # KeyMint 3.0, hardware-backed
android.hardware.keystore.app_attest_key
android.hardware.security.model.compatible
android.software.verified_boot
```

`strongbox` feature entries: **0**.

**Proven:** the device advertises a hardware-backed Keystore at KeyMint 3.0, and
does **not** advertise StrongBox.

**Not proven, and deliberately so:** that a generated EC identity key actually
reports `SecurityLevel.TRUSTED_ENVIRONMENT` through `KeyInfo`. Confirming that
requires running `DeviceIdentityInstrumentedTest` on the device, which installs
two APKs onto a personal tablet. That was declined under the owner's
"do not modify the tablet" instruction. **A feature flag is a capability claim,
not a key-level measurement — do not upgrade this wording without the on-device
test.**

The StrongBox result is recorded truthfully as the specification demands, and it
is **not** a blocker: the specification marks StrongBox preferred, not mandatory,
precisely so acceptable hardware is not refused.

---

## 4. Blockers

### B1 — The production signing key does not exist and must not be created yet

`config/android_release.php` still carries `production_certificate_sha256 =>
null`. Signing governance requires:

- `minimum_custodians => 3` — primary, offsite recovery, sealed cold
- `backup.copies => 3`, all encrypted, `offsite_copy_required => true`
- `restore_test_cadence_days => 90`
- a designated signing workstation and an access-controlled release source

None of these exist. No custody register, no designated custodians, no offsite or
sealed-cold medium. The only keystores on the workstation belong to an unrelated
project or are debug keys.

Custody is a **human and organisational fact**; it cannot be manufactured by
tooling, and generating a permanent, non-rotatable production key before custody
exists is precisely the failure the governance was written to prevent
(`key_loss_recoverable => false`,
`key_loss_consequence => fleet_wide_reenrolment_after_forced_reinstall`).

**Owner decision, 2026-09-04: custody is not established — do not create the key.**

Everything else in Phase 4 is downstream: no key → no production-signed APK → no
certificate pin → no verified install → no enrolment.

### B2 — The available tablet is a personal, in-use device

`TAB-AUDIT-01` is not a dedicated clinic device:

- user 0 is a named personal profile
- **10 accounts** present — Google (×5), Samsung, Microsoft, Instagram, Facebook,
  GitHub, Zoom, and two children's game accounts
- 55 GB of user data on a 104 GB volume
- `device_provisioned = 1`, `user_setup_complete = 1`

Android refuses `dpm set-device-owner` on a device that has completed setup or
holds any account. Device Owner on this unit therefore requires a **factory reset
that erases all of the above**. The provisioning runbook's own rule — never
provision with a debug or locally signed artifact, and only ever a dedicated
device — combined with the standing prohibition on resetting an unrelated
personal device, makes this a hard stop.

**Owner decision, 2026-09-04: do not factory-reset, do not modify the tablet.**

Consequently `device_owner_established`, `lock_task_active`,
`home_escape_blocked`, `recents_escape_blocked`,
`external_browser_escape_blocked` and `reboot_returns_to_clinic_app` are all
unprovable in this attempt.

### B3 — No spare device

One device was reachable. `spare_device_available_per_branch` is unmet. It sits
in `enforcement.global_prerequisites`, so it gates **global** enforcement rather
than a pilot — but with a single unit there is also no fallback if the pilot
device fails, and the device-loss runbook cannot be rehearsed.

### B4 — Governance forbids enforcement before Phase 5

`enforcement.may_be_enabled_in_phase => 5`, with
`current_stage => 'off'`. A pilot-scoped stage (`pilot_branch_or_device`) is
designed and available, so pilot enforcement is a legitimate *governed revision*
rather than a violation — but it needs `requires_owner_signoff => true` and
`rollback_owner_named_before_start => true`, neither of which was supplied.
Editing `5` to `4` without that sign-off would be weakening a production safety
rule to manufacture a tag, and was refused.

Additionally, no pilot Doctor identity or credentials were supplied, and the
pilot Doctor must never be guessed or defaulted to the first Doctor found.

---

## 5. Why this is a scoping result, not a failure

Three of the four blockers are organisational (custody, dedicated hardware,
sign-off) and one is a deliberate governance boundary. None is a defect in the
Phase 1–3.5 implementation, which remains correct and deployed with enforcement
off.

The audit also produced a real finding worth the attempt: **the intended tablet
class clears the specification and provides a hardware-backed Keystore on real
silicon**, with StrongBox absent as expected for this price tier and explicitly
tolerated by the specification.

## 6. Agreed next step — Phase 4A / 4B split

The Device Owner requirement is what makes Phase 4 all-or-nothing. It is being
split:

- **Phase 4A — App-only real device pilot.** Real tablet, production-signed APK,
  real Android Keystore identity, real enrolment, approval workflow, session
  binding, branch lock, room scope, print denial. **Device Owner and Lock Task
  explicitly deferred.**
- **Phase 4B — Device Owner / kiosk.** Lock Task, escape blocking, reboot
  behaviour, on a dedicated device.

Phase 4A still requires B1 to be closed — an app-only pilot is still a *pilot*,
and installing a debug-signed artifact on a pilot device is forbidden because it
validates a signing identity production will never use.

## 7. Entry checklist for the next attempt

| Prerequisite | Blocker | State |
|---|---|---|
| 3 signing custodians designated | B1 | ☐ |
| Signing workstation designated | B1 | ☐ |
| Access-controlled release source | B1 | ☐ |
| Production key generated, 3 encrypted copies, offsite + sealed cold | B1 | ☐ |
| Restore drill passed (cert SHA-256 parity) | B1 | ☐ |
| Certificate fingerprint pinned via reviewed change | B1 | ☐ |
| Dedicated tablet, zero accounts, factory-fresh | B2 | ☐ |
| Spare device for the pilot branch | B3 | ☐ |
| Owner sign-off recorded | B4 | ☐ |
| Rollback owner named before start | B4 | ☐ |
| Pilot branch and Doctor explicitly selected | B4 | ☐ |
| Device Owner requirement re-scoped to 4B | — | ☑ agreed 2026-09-04 |
| Model clears procurement spec | — | ☑ verified |
| Hardware-backed Keystore available on target hardware | — | ☑ verified (capability) |
| Key-level `TRUSTED_ENVIRONMENT` measured on device | — | ☐ deferred to 4A |

Fail-closed: while any B1 box is unticked, no artifact may be installed on any
pilot device.
