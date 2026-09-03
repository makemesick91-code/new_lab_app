# Runbook — Android release, distribution and recovery

**REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.**
Authority: [ADR 0010](../adr/0010-android-direct-apk-signing-and-distribution.md) ·
[signing governance](../governance/android-production-signing-governance.md) ·
`config/android_release.php`.

Audience: the **signing custodian / release operator**. The installer's half is
in [direct APK installation](android-direct-apk-installation.md).

> **Google Play is NOT used.** Managed Google Play, Play App Signing and a Play
> Developer account are **not required**. The release artifact is a signed
> **APK**, published to an access-controlled internal source.

---

## 0. One-time setup (not yet done)

| Step | Owner | Done? |
|---|---|---|
| Designate 3 signing custodians in the operations register | clinic owner | ☐ |
| Prepare the designated signing workstation | signing custodian | ☐ |
| Generate the production signing key per the [key runbook](android-signing-key-backup-and-recovery.md) | signing custodian | ☐ |
| Create 3 encrypted backups (1 offsite, 1 sealed cold) | custodians | ☐ |
| Pass the restore drill | signing custodian | ☐ |
| Record the certificate SHA-256 fingerprint as the canonical signer identity | signing custodian | ☐ |
| Create the access-controlled release source | Admin/IT | ☐ |

> The package name `com.daengtisia.clinic` and the signing certificate together
> are the update identity. Neither can change after the first install without an
> uninstall that erases app data — and that destroys every device's enrolment.
> Publish the first release deliberately.

---

## 1. Release

### 1.1 Preconditions

```bash
php artisan android:release-readiness --strict     # must exit 0
```

- required CI green on the exact commit being released
- `versionCode` **strictly greater** than the last published value
- `versionName` updated and meaningful

**Android only enforces `>=`.** The strictly-greater rule is ours, and nothing
else enforces it now that Play is out of the chain. Two builds sharing a
`versionCode` would install over each other silently and "which build is on that
tablet?" would stop having an answer.

### 1.2 Build and sign

On the **designated signing workstation**, under manual approval:

```bash
cd android/daengtisia-clinic
./gradlew :app:assembleRelease
# then sign with the DaengtisiaMS production key (see the key runbook)
```

Rename to the canonical pattern:

```
DaengtisiaMS-Clinic-v1.0.0.apk
```

### 1.3 Generate the release manifest

```json
{
  "package_name": "com.daengtisia.clinic",
  "version_name": "1.0.0",
  "version_code": 2,
  "git_commit": "<full sha>",
  "ci_run_id": "<run id>",
  "build_variant": "release",
  "apk_filename": "DaengtisiaMS-Clinic-v1.0.0.apk",
  "artifact_sha256": "<sha256 of the apk>",
  "signing_certificate_fingerprint_sha256": "<from apksigner --print-certs>",
  "release_channel": "direct_admin_managed_apk",
  "approval_status": "approved"
}
```

Save beside the APK as `DaengtisiaMS-Clinic-v1.0.0.release.json`.

### 1.4 Verify before publishing

```bash
php artisan android:verify-release \
  DaengtisiaMS-Clinic-v1.0.0.apk \
  DaengtisiaMS-Clinic-v1.0.0.release.json
```

Non-zero exit means do not publish. The installer runs the identical command
before installing — verifying twice, at both ends of the channel, is the point.

### 1.5 Publish

Upload both files to the access-controlled release source (a protected GitHub
Release artifact using infrastructure the project already operates). The source
must carry artifact identity, release metadata, a published SHA-256, access
control and version history.

**Not permitted:** chat, email, an unauthenticated public bucket, or a shared
drive with no access control. Those channels cannot answer "is this the artifact
we published?".

---

## 2. Distribution

Direct installation by authorised Admin/IT — the full procedure is in the
[direct APK installation runbook](android-direct-apk-installation.md). Pilot and
fleet use the **same path**; there is no special case.

---

## 3. Recovery from a bad release

**There is no production downgrade.** Installing an older `versionCode` requires
an uninstall, which erases app data, which destroys the Keystore device identity
and forces re-enrolment. `adb install -d` bypasses the block only for
**debuggable** builds. Trading a bad app version for a lost fleet enrolment is
not a rollback.

1. **Stop distribution** — remove the artifact from the release source so no
   further device receives it.
2. **Fix forward** — revert, bump `versionCode` up, sign, verify, publish.
3. **Install the fix** on affected devices.
4. Devices already updated stay on the bad version until the fix reaches them.
   The fallback meanwhile is **browser login**, which is never denied.

This is the same shape as the Play-era "halt the rollout then forward-fix" —
minus the halt, which was a Play Console button. What remains rests on platform
behaviour and survives the distribution change intact.

---

## 4. Server / client compatibility

A server deploy must not brick a tablet that has not updated.

- No minimum-client-version hard block is implemented, deliberately. Adding one
  is the fastest way to lock a clinic out of its own records.
- Device API breaking changes take a **new path**; the old path keeps working
  for the grace period (30 days).
- Sequence: tolerant server first, then the client, then retire the old path
  once no device is on it.

With manual installation, client rollout is slower than a Play staged rollout
and more uneven. Bias the compatibility window wider, not narrower.

---

## 5. What to watch after a release

- device enrolment failures
- cryptographic proof failures (a spike after a release means a protocol break)
- disabled/revoked device access attempts
- app version distribution across enrolled devices, and `last_seen`
- branch and room mismatch rejections
- server 4xx/5xx on the device API paths

There is no Play vitals dashboard. These signals are what replaces it, and the
installed-version record from the install log is the only inventory there is.

Never log key material, full KTP/NIK, or clinical content into release
telemetry.

---

## 6. Emergency contacts

| Situation | Who | Where |
|---|---|---|
| Signing key lost/compromised | signing custodian | [key backup & recovery](android-signing-key-backup-and-recovery.md) |
| Tablet lost/stolen | clinic owner → Super Admin | [device loss](android-device-loss-replacement-and-decommission.md) |
| Bad release in the field | release operator | §3 above |
| Install or update refused | Admin/IT installer | [direct APK installation](android-direct-apk-installation.md) §4–§6 |
| Device cannot enrol | Super Admin | [provisioning](android-clinic-device-provisioning.md) |
