# ADR 0010 — Direct admin-managed APK distribution with self-managed signing

**Status:** Accepted (REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1, 2026-09-03)
**Supersedes:** [ADR 0009](0009-android-production-signing-distribution-and-device-management.md)
— Decisions 1 (Play App Signing) and 2 (Managed Google Play). Decisions 3
(device management) and 4 (rollback) are **revised**, not discarded.

Machine-readable form: `config/android_release.php`.
Enforced by: `php artisan android:release-readiness`,
`php artisan android:verify-release`, and
`tests/Feature/DoctorDevice/DoctorDeviceAndroidReleaseGovernanceTest.php`.

---

## Context

ADR 0009 chose Play App Signing plus a Managed Google Play private app. The
reasoning was sound for what it optimised: it put the one unrecoverable secret
(the app signing key) into Google's KMS and left the clinic holding only a
**resettable** upload key.

The owner has since decided that DaengtisiaMS will not depend on Google Play at
all. The clinic app is installed directly by DaengtisiaMS Admin/IT onto clinic
tablets, and DaengtisiaMS owns its own signing key.

That is a legitimate product decision — it removes a Google account dependency,
a Play Developer account, an external review surface and a third party from the
clinical delivery path. It also **moves a risk back onto the clinic**, and this
ADR exists to say so plainly rather than to present the new model as strictly
better.

---

## Decision 1 — Signing: self-managed DaengtisiaMS production key

DaengtisiaMS generates, owns and operates the production Android app signing
key. No third party holds it.

### The consequence, stated rather than softened

ADR 0009's central argument was that an upload key is resettable and an app
signing key is not. **That argument has not been refuted — it has been
accepted as a cost.** Under this ADR the clinic holds the unrecoverable key.

And the blast radius here is worse than for a typical app, because of a
platform chain that has to be followed all the way through:

1. Android requires an update to be signed by the **same certificate**.
2. An APK that fails that check cannot be installed over the existing app; the
   user must **uninstall first**.
3. Uninstalling **erases all app data**.
4. App data is where the **Android Keystore device identity** lives.
5. Destroying that identity destroys the **DoctorDevice enrolment**.

So losing this key does not merely end updates. It forces a reinstall that
re-enrols **every tablet in the fleet** by hand, with an administrator matching
pairing codes and fingerprints device by device.

The governance compensates deliberately: **three** custodian copies rather than
two, and a **90-day** restore drill rather than 180. Removing Google as
custodian removed a safety net, so the procedure has to supply one.

### Rejected: keeping Play App Signing

Rejected on the owner's product decision, not on technical grounds. It remains
the lower-risk option for key custody specifically, and that is recorded here so
a future reader understands what was traded and why — not so the decision can be
quietly relitigated.

### Rejected: no signing key at all / debug signing

The debug key is a publicly known identity: anyone can sign an update over it.
Not a candidate.

---

## Decision 2 — Distribution: direct admin-managed signed APK

```
SOURCE COMMIT → REQUIRED CI → APPROVED RELEASE CANDIDATE → RELEASE BUILD
  → DAENGTISIAMS SIGNING AUTHORITY → SIGNED APK + RELEASE MANIFEST
  → SIGNER + SHA-256 VERIFICATION → AUTHORISED ADMIN/IT
  → DIRECT INSTALL → CLINIC ANDROID DEVICE
```

**The artifact is an APK, not an App Bundle.** An `.aab` is a Play *upload*
format that Google converts into device-specific APKs. With no Play in the
chain, nothing performs that conversion, so a bundle is not installable and
cannot be the release artifact. ADR 0009's `artifact_format: aab` existed only
to satisfy Play App Signing and is gone.

**The Phase 3.5 "pilot exception" is deleted, not widened.** That exception
existed for one reason: a self-owned Device Owner tablet has no Google account,
so it could not install from Managed Google Play, and the pilot therefore had to
side-load a Play-signed artifact. With direct installation canonical there is no
mismatch to except — the pilot and the fleet now use the identical path. Removing
a special case is a better outcome than generalising it.

**The artifact source is a controlled internal channel**, not chat and not
email. It must carry artifact identity, release metadata, a published SHA-256,
access control and version history. A protected GitHub Release artifact meets
this using infrastructure the project already operates; no new infrastructure is
introduced. A public unauthenticated APK bucket is not permitted.

---

## Decision 3 (revised) — Device management: EMM is advisory, not required

ADR 0009 said an EMM was **required** at five devices. That requirement was
never a kiosk requirement — it came from Managed Google Play being the
distribution channel, and hand-provisioning a Play-based fleet not scaling.

With direct installation canonical, **direct admin management stays
product-supported at any fleet size**. An MDM/EMM may be *recommended* as the
fleet grows (advisory threshold: 10 devices) but adopting one is an owner
decision, not an automatic trigger.

Device Owner + Lock Task is unchanged. So is the rule that screen pinning,
launcher replacement alone, hidden exit gestures and hard-coded exit PINs are
never kiosk mechanisms.

---

## Decision 4 (revised) — Recovery: stop distribution, then forward-fix

ADR 0009 said "halt the rollout, then forward-fix". **"Halt the rollout" is a
Play Console operation** and there is no Play Console. The forward-fix half
survives, because it rests on platform behaviour.

### A precision the Play-era wording hid

| Layer | Rule |
|---|---|
| **Android platform** | the update's `versionCode` must be **≥** the installed one |
| **Google Play** | additionally refused anything not **strictly >** |
| **DaengtisiaMS governance** | **strictly >**, enforced by us |

Play was silently supplying the stricter half. Removing Play removes that
enforcement — so the governance must state it explicitly, or two different
builds could share a `versionCode` and "which build is on that tablet?" would
stop having an answer. Android would happily install one over the other.

### There is no production downgrade

Installing an older `versionCode` requires uninstalling first, which erases app
data, which destroys the device identity and forces re-enrolment. `adb install
-d` bypasses the downgrade block only for **debuggable** builds, which a
production release is not. Treating downgrade as a rollback path would trade a
bad app version for a lost fleet enrolment.

**Recovery is therefore:** stop distributing the bad APK → fix forward under a
higher `versionCode` → install the fix. Devices already updated stay on the bad
version until the fix reaches them, exactly as they would have under a halted
Play rollout.

---

## Consequences

**Removed dependencies:** Google Play, Managed Google Play, Play App Signing, a
Play Developer account, a Google account on clinic devices, Play review, Play
rollout tooling.

**Accepted risks:**

| Risk | Mitigation |
|---|---|
| Signing key loss is **unrecoverable** and costs fleet re-enrolment | 3 encrypted copies, 1 offsite, 90-day restore drill, 3 custodians |
| No Play-side signature or malware checking | APK signature verified against a **certificate fingerprint pinned in configuration**, plus SHA-256, before every install |
| No staged rollout tooling | One-device pilot first; installs are manual and therefore inherently staged |
| Install is a human action | Named installer authority, verification gate, recorded install |
| Sideload surface on the device | ADB debugging disabled after provisioning; Device Owner + Lock Task |

### The trust anchor, and why it is not the manifest

Security review caught the first implementation reading the expected certificate
fingerprint out of the release manifest that ships beside the APK. That verifies
self-consistency, not authenticity: whoever can replace the artifact replaces the
manifest with it, sets the digest to their build and the fingerprint to their own
key, and every remaining check — package, variant, approval, channel — is a public
constant readable in this repository. The command would have exited 0 and the
runbook would have told the installer to proceed.

The expected fingerprint therefore lives in **`config/android_release.signing.
production_certificate_sha256`**, and the APK is compared against *that*. The
manifest's copy is a cross-check for paperwork drift, never the authority.

It is `null` today because no production key exists, and while it is null
`android:verify-release` **fails closed** rather than authenticating against a
document that cannot vouch for itself. Pinning it is a Phase-4 entry step
alongside provisioning the key — and the two must happen together, because a key
without a published fingerprint cannot be verified by anyone.

**Unchanged:** Phase 1 room scoping and print denial, Phase 2 device registry
lifecycle, Phase 3 cryptographic enrolment / challenge-response / secure WebView
/ Lock Task. Possession of an APK still confers **no trust** — device trust
comes from the Keystore enrolment, which a copied APK cannot reproduce.

**Still false after this ADR:**

```
PRODUCTION_SIGNING_KEY_PROVISIONED = false
PRODUCTION_CERTIFICATE_PINNED      = false
ANDROID_REAL_DEVICE_VALIDATION     = false
DEVICE_ENFORCEMENT_ACTIVE          = false
DOCTOR_BROWSER_LOGIN_DENIED        = false
```

Choosing who signs is not signing. The key and the tablet remain Phase 4 inputs.

---

## Sources

Retrieved 2026-09-03. Platform behaviour, not Play policy — which is precisely
why it survives the distribution change.

- [App signing (AOSP)](https://source.android.com/docs/security/features/apksigning)
- [Sign your app](https://developer.android.com/studio/publish/app-signing)
- [apksigner](https://developer.android.com/tools/apksigner)
- [Version your app](https://developer.android.com/studio/publish/versioning)
- [APK signature scheme v3 (rotation)](https://source.android.com/docs/security/features/apksigning/v3)
