# ADR 0009 — Android production signing, distribution and device management

**Status:** **SUPERSEDED** (2026-09-03) by
[ADR 0010 — Direct admin-managed APK distribution with self-managed signing](0010-android-direct-apk-signing-and-distribution.md).

> **This record is historical.** It was accurate when Phase 3.5 was GO-tagged and
> is preserved unedited below so the reasoning of that decision survives.
>
> **Decisions 1 and 2 (Play App Signing, Managed Google Play) are SUPERSEDED.**
> Google Play, Managed Google Play, Play App Signing and a Play Developer
> account are **NOT used** and are **not required** by DaengtisiaMS.
> Decision 3 (device management) and Decision 4 (rollback) are **revised** by
> ADR 0010, not discarded.
>
> Decision 1's central argument — that an upload key is resettable and an app
> signing key is not — was **not refuted**. ADR 0010 accepts that risk
> deliberately and compensates with stricter custody. Read both.

**Originally accepted:** FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5, 2026-09-03
**Supersedes:** the "UNRESOLVED" posture recorded in
`docs/sprints/feature-doctor-trusted-android-device-lock-1-phase-3.md` §6 and
rule 11 of `.cursor/rules/140-android-clinic-device-identity.mdc`.

Machine-readable form: `config/android_release.php`.
Enforced by: `php artisan android:release-readiness` and
`tests/Feature/DoctorDevice/DoctorDeviceAndroidReleaseGovernanceTest.php`.

---

## Context

Phase 3 shipped the Android Clinic App — Keystore device identity,
challenge/response enrolment, secure WebView, Lock Task kiosk — and stopped at a
wall it named honestly:

> Production signing is UNRESOLVED. Release builds declare **no**
> `signingConfig`. Custody, backup, rotation, distribution and disaster
> recovery must be decided by a human owner before any production Android
> release — this blocks Phase 4.

That wall is real and it is not primarily a technical one. Android app signing
is close to a one-way door: the certificate that signs version 1 must sign every
later version, or the update is refused. Getting it wrong does not degrade the
system, it strands it — and in this system stranding is expensive in a specific
way, because **clearing app data destroys the device identity**. An app that
cannot be updated can only be uninstalled and reinstalled, and every reinstall
generates a new keypair that no administrator has approved. Losing the signing
key therefore costs the entire device fleet's enrolment, not just an update.

Four decisions had to be made together, because each constrains the others:
signing custody, distribution channel, kiosk/device-management model, and the
rollback mechanism.

---

## Decision 1 — Signing: Play App Signing, with a clinic-held upload key

**Google holds the app signing key in its KMS. The clinic holds only an upload
key.**

The property that decides this is narrow and specific: an **upload key is
resettable** and an **app signing key is not**. Under Play App Signing, if the
clinic loses or leaks the key it holds, that is a Play Console support request.
Under self-managed signing, the same event ends the app's updatable life.

We are choosing where to put an unrecoverable failure. Google's KMS is a better
custodian of a near-permanent secret than any storage this clinic can operate,
and that is not a compliment to Google so much as an honest reading of what a
dental clinic's IT capacity is for.

### Rejected: self-managed production keystore, direct APK distribution

The obvious path, and the one a developer reaches for first. Rejected because
every failure mode is terminal and the clinic absorbs all of them:

- key loss ⇒ the app can never be updated; every enrolment is destroyed on the
  forced reinstall
- key compromise ⇒ an attacker can sign an app that installs as an *update* over
  the legitimate one, inheriting its data and its device identity
- no rollback mechanism, no staged rollout, no update telemetry
- provisioning becomes per-device manual work that scales linearly with tablets

### Rejected: self-managed keystore stored in CI secrets

Rejected on a single sentence: pull-request CI executes untrusted code. A
signing key reachable from PR CI makes every contributor — and every fork — a
release authority. `pull_request_ci_may_sign` is `false` in config and the
readiness gate asserts it.

A protected release environment with manual approval is a legitimate variant of
this, and is how the *upload* key is used. It is not a place for an app signing
key.

### Rejected: third-party MDM-only signing/distribution

Adds a vendor without removing the problem. The signing identity still has to
exist and be owned; the MDM just moves where the APK is fetched from.

---

## Decision 2 — Distribution: Managed Google Play private app

Organisation-restricted, centrally updatable, staged-rollout capable, and the
same channel that provisions dedicated devices at fleet scale. Publishing a
private app creates a Play Developer account for the organisation with no
registration fee.

Two consequences worth writing down before someone discovers them:

- **The package name is permanent from first publish.** Deleting a private app
  does not release its package name. `com.daengtisia.clinic` is a one-way
  commitment.
- **Play App Signing wants an App Bundle.** The artifact format is `.aab`; the
  device-installable APKs are generated and signed by Google.

### The pilot exception, stated rather than discovered

A self-owned Device Owner tablet has **no Google account on it** — that is a
precondition of `dpm set-device-owner`, not an oversight. Such a device
therefore *cannot* install from Managed Google Play.

For the single Phase 4 pilot device, the **Play-signed** artifact is downloaded
from Play Console and side-loaded over ADB. The signing identity is identical to
production; only the transport differs. Side-loading a *locally* signed build is
not this exception and is not permitted — it would validate a signing identity
that production will never use, which is worse than not testing at all.

Bounded in config: one device, owner sign-off required, artifact source must be
the Play Console signed artifact.

---

## Decision 3 — Device management: two models, chosen by fleet size

| Model | When | Who is Device Owner | How the app is installed |
|---|---|---|---|
| `self_owned_device_owner` | Phase 4 pilot, 1 device | our `ClinicDeviceAdminReceiver`, set over ADB on a factory-reset device | ADB side-load of the Play-signed artifact |
| `emm_managed_dedicated_device` | fleet, ≥5 devices | the EMM's DPC | Managed Google Play policy |

The app already treats *not Device Owner* as a supported state — it runs, it
simply does not lock, and it reports that honestly instead of implying
protection it lacks. That is what makes both models work without a code change,
and it is worth noticing that Phase 3 got this right before knowing it would
matter here.

Under the EMM model **our app is not the Device Owner** — the DPC is, and it
lock-task-allowlists our package by policy. Anyone reading `KioskController` and
expecting it to call `setLockTaskPackages` on a fleet device will be confused
otherwise.

Screen pinning is excluded permanently. It is dismissible by the user, so it is
not a security control. So are hidden exit gestures and hard-coded exit PINs — a
shared secret printed on every device in the fleet is a shared secret.

---

## Decision 4 — Rollback: halt the rollout, then forward-fix

This is the decision most likely to be got wrong under pressure, because the
intuition from server deployment is exactly backwards.

**You cannot decrement a versionCode.** Play refuses an upload whose versionCode
is not greater than the current one, and Android itself blocks a downgrade
install. "Roll back to the previous APK" is not an available operation.

What is available:

1. **Halt the rollout.** New and existing users stop receiving the bad version;
   new installs fall back to the previous release.
2. **Forward-fix.** Ship the *older code* under a *higher* versionCode.

And one hard edge: **the first release on a track cannot be halted** — there is
no previous release to fall back to. The pilot's first build is therefore a
one-way door, which is a reason to keep the pilot to one device.

---

## Consequences

**Unblocked.** Phase 4 has a signing authority, a distribution channel, a
provisioning model and a rollback procedure. The remaining blockers are
procurement and one human action (creating the Play Developer account).

**Accepted dependency.** Fleet distribution depends on Managed Google Play and,
at scale, an EMM. A clinic that will not run either falls back to self-managed
signing with every risk in Decision 1 — that fallback requires a new ADR, not a
quiet exception.

**Still false after this ADR**, and stated so nothing infers otherwise:

```
PRODUCTION_SIGNING_KEY_PROVISIONED = false
ANDROID_REAL_DEVICE_VALIDATION     = false
DEVICE_ENFORCEMENT_ACTIVE          = false
DOCTOR_BROWSER_LOGIN_DENIED        = false
```

Deciding how to sign is not signing. Phase 3.5 delivers the decision; the key
and the tablet are Phase 4 inputs.

---

## Sources

Retrieved 2026-09-03. Re-verify before acting: Google changes these.

- [Sign your app](https://developer.android.com/studio/publish/app-signing)
- [Use Play App Signing](https://support.google.com/googleplay/android-developer/answer/9842756)
- [Publish private apps from managed Play](https://support.google.com/googleplay/work/answer/9146439)
- [Best practices for private apps](https://support.google.com/googleplay/work/answer/9496237)
- [Version your app](https://developer.android.com/studio/publish/versioning)
- [Halting a fully rolled-out release](https://support.google.com/googleplay/android-developer/answer/16285429)
- [Dedicated device requirements](https://developers.google.com/android/work/requirements/dedicated-device)
- [Device setup methods](https://support.google.com/work/android/answer/9566881)
- [Android Enterprise Recommended devices](https://support.google.com/work/android/answer/16751490)
