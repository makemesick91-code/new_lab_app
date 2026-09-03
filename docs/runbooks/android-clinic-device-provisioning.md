# Android Clinic Device — provisioning runbook

FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.

Turning a tablet into a DaengtisiaMS dedicated clinic device.

> **Enforcement is OFF.** After this runbook the device is registered and
> cryptographically verified, but Doctor login is completely unchanged and a
> doctor may still sign in from an ordinary browser. Making device proof
> mandatory is Phase 5 and needs its own decision.

---

## 0. Before you start — the blocker

**Production signing governance is UNRESOLVED.** No production signing identity
exists, and none may be invented. The only artifact Phase 3 produces is a
**debug** APK for the emulator and CI.

A human owner must decide, and record, all of the following before any
production Android release:

| Decision | Why it cannot be deferred |
|---|---|
| Who owns the signing key | An app signed by the wrong party cannot be updated by the right one |
| Where the keystore lives and who may touch it | Key loss means the app can never be updated — only uninstalled and reinstalled, losing every enrolment |
| Backup and disaster recovery for the key | Same |
| Rotation posture | Android app signing key rotation is limited; the choice is near-permanent |
| Distribution: Play private channel, Managed Google Play, or direct APK | Determines provisioning method and update path |

Until that is recorded, **do not** ship an APK to a clinic.

---

## 1. Prepare the tablet

Device Owner can only be set on a device with **no accounts** and **no
completed setup**. In practice that means a factory-reset tablet.

1. Factory reset.
2. Skip Google account sign-in entirely. Adding an account makes
   `dpm set-device-owner` fail — this is the most common provisioning mistake.
3. Enable Developer options → USB debugging.

## 2. Install the app

```bash
adb install -r app-debug.apk        # or the release artifact once signing is resolved
```

## 3. Provision as Device Owner

```bash
adb shell dpm set-device-owner \
  com.daengtisia.clinic/com.daengtisia.clinic.kiosk.ClinicDeviceAdminReceiver
```

Expected: `Success: Device owner set to package ...`

If it fails with *"Not allowed to set the device owner because there are already
some accounts on the device"*, go back to step 1 — the reset was incomplete.

Verify:

```bash
adb shell dumpsys device_policy | grep -i "Device Owner"
```

> This is **Device Owner + Lock Task**, not screen pinning. Screen pinning is
> dismissible by the user and is not a security control.

## 4. Enrol the device

1. Launch the app. On first run it generates a **non-exportable EC P-256
   keypair inside the Android Keystore** and requests enrolment.
2. The app shows an **8-character pairing code**.
3. A **Super Admin** opens `Master Data → Device Dokter`, finds the pending
   request, matches the pairing code and the key fingerprint, picks the registry
   device to bind, and approves.
4. The app then answers a server challenge by signing it. On success the device
   becomes `identity_state = cryptographically_verified`.

Approval alone is **not** verification — the hardware still has to prove it
holds the private key.

## 5. Verify the result

In `Master Data → Device Dokter` the device should show:

- Status **Aktif**
- Identitas **Terverifikasi kriptografis**
- A short key fingerprint

## 6. Day-to-day operations

| Situation | Action |
|---|---|
| Tablet away for repair | **Disable** with a reason. Reversible. |
| Tablet lost or stolen | **Revoke** with a reason. **Terminal** — it can never be reactivated. |
| Revoked tablet recovered | Factory reset, reinstall, enrol again. It gets a **new** key identity; the old one stays revoked in the history. |
| Tablet replaced | Revoke the old device, register and enrol the new one. |

Devices are never deleted — a device that was ever trusted keeps its security
history.

## 7. What clearing app data does

It destroys the Keystore entry, and therefore the identity. The device must
re-enrol and be re-approved. This is also why **copying the APK to another
tablet grants nothing**: the new install generates a different key that no
administrator has approved.

## 8. Emulator validation (what has and has not been proven)

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
- anything about production signing

Never describe emulator results as real-device validation.
