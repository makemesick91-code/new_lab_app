# Runbook — Android clinic device provisioning

Turning a tablet into a DaengtisiaMS dedicated clinic device.

FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 (protocol) + Phase 3.5
(management model, distribution, fail-closed checkpoints).

Related: [ADR 0009](../adr/0009-android-production-signing-distribution-and-device-management.md) ·
[release & rollback](android-release-distribution-and-rollback.md) ·
[device loss](android-device-loss-replacement-and-decommission.md) ·
[tablet spec](../operations/clinic-tablet-procurement-specification.md).

> **Enforcement is OFF.** After this runbook the device is registered and
> cryptographically verified, but Doctor login is completely unchanged and a
> doctor may still sign in from an ordinary browser. Making device proof
> mandatory is Phase 5 and needs its own decision.

---

## 0. Prerequisites

Signing governance is **RESOLVED** as of Phase 3.5 (ADR 0009) — the Phase 3
blocker that stood here is closed. What replaces it is a checklist, because the
*key itself* still has to exist.

| Prerequisite | Where | Ready? |
|---|---|---|
| Tablet meets the minimum specification | [tablet spec](../operations/clinic-tablet-procurement-specification.md) | ☐ |
| Play Developer account (role account) exists | [release runbook](android-release-distribution-and-rollback.md) §0 | ☐ |
| Play App Signing enrolled | same | ☐ |
| Upload key generated, backed up, restore drill passed | [key runbook](android-signing-key-backup-and-recovery.md) | ☐ |
| Artifact signed by the **production** authority | ADR 0009 | ☐ |
| Branch chosen for this device | Master Data → Cabang | ☐ |
| Super Admin available to approve the enrolment | — | ☐ |
| Clinic Wi-Fi reachable, `https://daengtisia.online` resolves from it | — | ☐ |

**Fail-closed checkpoint 0** — if any box is unticked, stop. In particular:
never provision a device with a **debug** or locally signed artifact. It
validates a signing identity production will never use, and the device would
have to be wiped and redone.

---

## 1. Choose the management model

| Model | Use when | Device Owner is | App installed by |
|---|---|---|---|
| **Self-owned Device Owner** | Phase 4 pilot, ≤4 devices | our `ClinicDeviceAdminReceiver` | ADB side-load of the Play-signed artifact |
| **EMM dedicated device** | ≥5 devices, multi-branch | the EMM's DPC | Managed Google Play policy |

At **five devices** hand-provisioning stops being defensible
(`config/android_release.device_management.scale_model_required_at_devices`).

Under the EMM model **our app is not the Device Owner** — the DPC is, and it
lock-task-allowlists our package by policy. Sections 3 and 4 below are the
self-owned path; §7 is the EMM path.

Screen pinning is not a third option and never will be: it is dismissible by
the user, so it is not a security control.

---

## 2. Prepare the tablet

Device Owner can only be set on a device with **no accounts** and **no completed
setup**. In practice: a factory-reset tablet.

1. Factory reset.
2. **Skip Google account sign-in entirely.** Adding an account makes
   `dpm set-device-owner` fail — the most common provisioning mistake by a wide
   margin.
3. Connect to clinic Wi-Fi (needed for enrolment).
4. Settings → About → tap Build number ×7 → Developer options → USB debugging.

**Fail-closed checkpoint 1**

```bash
adb shell dumpsys account | grep -i "Accounts:" || true   # expect none
adb shell settings get global device_provisioned          # 1 is fine; accounts are what matter
```

Any account present ⇒ factory reset again. Do not continue and hope.

---

## 3. Install the app

```bash
adb install -r <play-signed>.apk
```

The artifact must be the **Play-signed** one (Play Console → App bundle explorer
→ download signed universal APK, or Internal app sharing). See the release
runbook §2.1 for why the pilot side-loads rather than using Managed Google Play:
a device with no Google account cannot install from it.

**Fail-closed checkpoint 2** — confirm the installed signer is the production
authority, not a debug key:

```bash
adb shell pm list packages | grep com.daengtisia.clinic     # no .debug suffix
apksigner verify --print-certs <play-signed>.apk | grep -i 'SHA-256'
```

The fingerprint must match the one recorded in the release manifest. A `.debug`
package id means a debug build was installed — uninstall and start again.

---

## 4. Provision as Device Owner

```bash
adb shell dpm set-device-owner \
  com.daengtisia.clinic/com.daengtisia.clinic.kiosk.ClinicDeviceAdminReceiver
```

Expected: `Success: Device owner set to package ...`

If it fails with *"Not allowed to set the device owner because there are already
some accounts on the device"*, go back to §2 — the reset was incomplete.

**Fail-closed checkpoint 3**

```bash
adb shell dumpsys device_policy | grep -i "Device Owner"
adb shell dumpsys activity | grep -i mLockTaskModeState     # expect LOCKED
```

Not Device Owner is a *supported* state — the app runs and reports honestly that
it is not locking — but it is **not acceptable for a clinic device**. A tablet
that does not lock is a tablet a patient can browse from.

---

## 5. Enrol the device

1. Launch the app. On first run it generates a **non-exportable EC P-256 keypair
   inside the Android Keystore** and requests enrolment.
2. The app shows an **8-character pairing code**.
3. A **Super Admin** opens `Master Data → Device Dokter`, finds the pending
   request, matches the pairing code **and** the key fingerprint, picks the
   registry device to bind, and approves.
4. The app answers a server challenge by signing it. On success the device
   becomes `identity_state = cryptographically_verified`.

Approval alone is **not** verification — the hardware still has to prove it holds
the private key.

**Fail-closed checkpoint 4** — in `Master Data → Device Dokter`:

- Status **Aktif**
- Identitas **Terverifikasi kriptografis**
- Key fingerprint shown, and it matches what the tablet displayed
- Branch binding is the intended branch

A fingerprint mismatch means you are approving a different device. Stop.

---

## 6. Field verification — the part people skip

Emulators do not prove any of this.

| # | Test | Expected |
|---|---|---|
| 1 | Press Home | nothing happens |
| 2 | Press Recents | nothing happens |
| 3 | Follow an external link | blocked; stays in the clinic app |
| 4 | Reboot the tablet | boots back into the clinic app |
| 5 | Turn Wi-Fi off, use the app, turn it back on | recovers without a reinstall |
| 6 | Load the login page | reaches `https://daengtisia.online` |
| 7 | Force a TLS error (e.g. an intercepting proxy) | **fails closed** — no bypass prompt |
| 8 | Leave idle through screen-off, wake it | still in Lock Task |

Record the outcome of each, plus the **hardware-backed** and **StrongBox**
results the device actually reports — truthfully, whatever they say. A device
reporting software-backed storage is a finding, not something to round up.

---

## 7. EMM model (fleet)

1. Enrol the device with the EMM: QR provisioning, `afw#` token, zero-touch or
   NFC, per the EMM's console.
2. **QR payloads must not carry secrets** — anyone who can photograph the screen
   can read one. Google's own guidance says so, and
   `device_management.qr_provisioning_may_carry_secrets` is `false`.
3. Push the clinic app from Managed Google Play.
4. Configure the dedicated-device policy: lock-task-allowlist
   `com.daengtisia.clinic`, set it as the home/launcher activity, restrict
   "clear app data" for the package (see the device-loss runbook §7).
5. Enrol per §5 — the protocol is identical.
6. Run §6 field verification on at least the first device of every model.

---

## 8. Day-to-day operations

| Situation | Action |
|---|---|
| Tablet away for repair | **Disable** with a reason. Reversible. |
| Tablet lost or stolen | **Revoke** with a reason. **Terminal.** → [device loss runbook](android-device-loss-replacement-and-decommission.md) |
| Revoked tablet recovered | Factory reset, reinstall, enrol again. New key identity; the old row stays revoked. |
| Tablet replaced | Revoke the old device, register and enrol the new one. |
| App update needed | [release runbook](android-release-distribution-and-rollback.md) |
| Admin maintenance on the device | Temporarily release Lock Task via the management action — never a hidden gesture or a hard-coded PIN |

Devices are never deleted. A device that was ever trusted keeps its security
history.

---

## 9. Decommission and re-provision

Full procedure — including the ordering that matters — is in the
[device loss, replacement and decommission runbook](android-device-loss-replacement-and-decommission.md) §6.

Short form: **revoke first**, then remove Device Owner / EMM enrolment, then
factory reset, then update the asset register. Revoking after the device has
left the building is revoking too late.

---

## 10. What clearing app data does

It destroys the Keystore entry, and therefore the identity. The device must
re-enrol and be re-approved. This is also why **copying the APK to another
tablet grants nothing**: the new install generates a different key that no
administrator has approved.

---

## 11. Emulator validation — what has and has not been proven

Validated on a clean `google_apis` (non-Play) emulator image:

- install, Device Owner provisioning, `mLockTaskModeState=LOCKED`
- app is the resumed activity, no BROWSABLE deep links
- real AndroidKeyStore: key generation, idempotent identity, fingerprint parity
  with the server formula, signature verification, identity destruction, and a
  second install producing a different identity

**Not proven, and a Phase 4 blocker:**

- hardware-backed / StrongBox key storage (an emulator Keystore is software-backed)
- Device Owner provisioning on real clinic hardware
- field behaviour: reboot, network loss, battery, real TLS chain
- production signing on a real device

Never describe emulator results as real-device validation.
