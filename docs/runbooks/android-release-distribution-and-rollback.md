# Runbook — Android release, distribution and rollback

**FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5.**
Authority: [ADR 0009](../adr/0009-android-production-signing-distribution-and-device-management.md) ·
[signing governance](../governance/android-production-signing-governance.md) ·
`config/android_release.php`.

Audience: the release operator. Assumes the Play Developer account exists and
Play App Signing is enrolled.

---

## 0. One-time setup (not yet done as of Phase 3.5)

| Step | Owner | Done? |
|---|---|---|
| Create the Play Developer account (a **role account**, not a personal one) | Play Console admin | ☐ |
| Publish the app privately once to reserve `com.daengtisia.clinic` | Play Console admin | ☐ |
| Enrol in Play App Signing | Play Console admin | ☐ |
| Generate the upload keystore per the [backup & recovery runbook](android-signing-key-backup-and-recovery.md) | signing custodian | ☐ |
| Register the upload certificate in Play Console | Play Console admin | ☐ |
| Record custodians in the operations register | clinic owner | ☐ |

> The package name is **permanent from first publish** — deleting a private app
> does not release its name. Publish `com.daengtisia.clinic` deliberately, once.

---

## 1. Release

### 1.1 Preconditions

```bash
php artisan android:release-readiness --strict     # must exit 0
```

- required CI green on the exact commit being released
- `versionCode` strictly greater than the last published value
- `versionName` updated and meaningful

### 1.2 Build

In the **protected release environment**, with manual approval:

```bash
cd android/daengtisia-clinic
./gradlew :app:bundleRelease
```

Signed with the **upload key**. Google re-signs with the app signing key.

### 1.3 Record the manifest

```json
{
  "version_name": "1.0.0",
  "version_code": 2,
  "git_commit": "<full sha>",
  "ci_run_id": "<run id>",
  "artifact_sha256": "<sha256 of the aab>",
  "signing_certificate_fingerprint_sha256": "<from Play Console app signing>",
  "release_channel": "managed_google_play_private"
}
```

```bash
php artisan android:release-readiness --manifest=release-manifest.json --strict
```

Keep it with the release evidence. It is what makes "is this the build we
tested?" answerable later.

### 1.4 Publish

Managed Google Play → private app → new release → upload the `.aab` → staged
rollout. Do not go to 100% on the first day of a version.

---

## 2. Distribution

| Fleet | Channel |
|---|---|
| Phase 4 pilot (1 device) | ADB side-load of the **Play-signed** artifact |
| Production fleet | Managed Google Play private app |

### 2.1 The pilot exception

A self-owned Device Owner tablet has no Google account, so it cannot install
from Managed Google Play. For the pilot device only:

1. Play Console → **App bundle explorer** → the release → download the signed
   universal APK (or use **Internal app sharing**).
2. `adb install -r <play-signed>.apk`

The artifact is signed by the **production authority**. Side-loading a *locally*
signed build is not this exception and is forbidden — it would validate a
signing identity production never uses.

Bounded in config: `distribution.pilot_exception.max_devices = 1`, owner
sign-off required.

---

## 3. Rollback

**Read this before you need it. The server intuition is backwards here.**

You cannot decrement a `versionCode`. Play refuses an upload that is not
strictly greater, and Android blocks a downgrade install. *There is no
"reinstall the previous APK" operation.*

### 3.1 Bad release already rolled out

1. **Halt the rollout** in Play Console. New and existing users stop receiving
   the bad version; new installs fall back to the previous release.
   → Devices that already updated **stay on the bad version** until you ship a
   fix. Halting protects the ones that have not moved yet; it does not recall.
2. **Forward-fix**: revert the code, bump `versionCode` **up**, release again.
3. If the clinic is blocked meanwhile, fall back to browser login — Doctor
   browser login is not denied, precisely so this door stays open.

### 3.2 During staged rollout

Halt the staged rollout. Same forward-fix path.

### 3.3 The one-way door

**The first release on a track cannot be halted** — there is nothing to fall
back to. The pilot's first build is unrecallable, which is a reason the pilot is
one device.

### 3.4 Pilot device (side-loaded)

```bash
adb uninstall com.daengtisia.clinic     # destroys the device identity
adb install -r <previous-play-signed>.apk
```

Then **re-enrol**, and revoke the stale device row in Master Data → Device
Dokter. Uninstall wipes app data, so the Keystore identity is gone; a fresh
install generates a new keypair no administrator has approved yet. That is the
design working, not a fault.

---

## 4. Server / client compatibility

A server deploy must not brick a tablet that has not updated.

- No minimum-client-version hard block is implemented, deliberately. Adding one
  now is the fastest way to lock a clinic out of its own records.
- Device API breaking changes take a **new path**; the old path keeps working
  for the grace period (30 days).
- Sequence: ship the tolerant server first, then the client, then retire the old
  path once telemetry shows no device on it.

---

## 5. What to watch after a release

- device enrolment failures
- cryptographic proof failures (a spike after a release means a protocol break)
- disabled/revoked device access attempts
- app version distribution across enrolled devices, and `last_seen`
- branch and room mismatch rejections
- server 4xx/5xx on the device API paths

Never log key material, full KTP/NIK, or clinical content into release
telemetry.

---

## 6. Emergency contacts

| Situation | Who | Where |
|---|---|---|
| Upload key lost/compromised | signing custodian | [backup & recovery](android-signing-key-backup-and-recovery.md) |
| Tablet lost/stolen | clinic owner → Super Admin | [device loss](android-device-loss-replacement-and-decommission.md) |
| Bad release in the field | release operator | §3 above |
| Device cannot enrol | Super Admin | [provisioning](android-clinic-device-provisioning.md) |
