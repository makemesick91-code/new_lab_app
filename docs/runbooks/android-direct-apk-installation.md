# Runbook — direct APK installation and update

**REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.**
Authority: [ADR 0010](../adr/0010-android-direct-apk-signing-and-distribution.md) ·
[signing governance](../governance/android-production-signing-governance.md) ·
`config/android_release.installation`.

Audience: the **authorised DaengtisiaMS Admin/IT installer**. This is the person
who physically installs the clinic app. It is not automatically the application
Super Admin.

> **Google Play is NOT used.** There is no Play Store step, no Managed Google
> Play, no Google account requirement on the tablet.

---

## 0. Who may do this

| Action | Authority |
|---|---|
| Build and sign the APK | Signing custodian, on the designated signing workstation |
| Publish to the release source | Signing custodian |
| Verify and install on a device | **Authorised Admin/IT installer** |
| Approve the device in Master Data → Device Dokter | Application Super Admin |
| Revoke a device | Application Super Admin |

Being a DaengtisiaMS Super Admin does **not** confer OS-level install authority
on clinic hardware, and holding a tablet does not confer app-approval authority.
Keep the two separate; where headcount forces one person to hold both, record it
as an accepted exception rather than letting it pass unnoticed.

---

## 1. Installer workstation requirements

- organisation-controlled machine, malware hygiene maintained
- fetches artifacts **only** from the access-controlled release source
- has `apksigner` (Android SDK build-tools) and `sha256sum` available
- **does not hold the production signing key** — unless it is also the
  designated signing workstation, which should be recorded and justified
- every install is recorded (device serial, APK version, date, installer)

---

## 2. Fetch and verify — before touching the tablet

Never install an APK you have not verified. The verification is the whole
security value of direct distribution: with no Play in the chain, this step is
the only thing standing between the clinic and a substituted artifact.

```bash
# 1. Fetch both files from the release source
#    DaengtisiaMS-Clinic-v1.0.0.apk
#    DaengtisiaMS-Clinic-v1.0.0.release.json

# 2. Verify the artifact against its manifest — one command, fails non-zero
php artisan android:verify-release \
  DaengtisiaMS-Clinic-v1.0.0.apk \
  DaengtisiaMS-Clinic-v1.0.0.release.json
```

### What that command actually proves — and what it does not

An operator standing at a tablet deserves the precise version, not a comforting
summary. Two properties are read from the **APK binary**; the rest are read from
the **manifest** and checked against trusted configuration.

| Check | Source | Meaning |
|---|---|---|
| `apk_sha256` | **APK bytes** | these are the released bytes |
| `signer_trust_anchor` | config | a production certificate is pinned at all |
| `signer_fingerprint` | **APK signature** | signed by the pinned DaengtisiaMS authority |
| `manifest_matches_trust_anchor` | manifest vs config | the paperwork names the same authority |
| `package_name` / `build_variant` / `approval_status` / `release_channel` | manifest vs config | the manifest *declares* the right values |
| `apk_filename` / `version_code` | manifest | shape and consistency only |

**`versionName` is not verified, and `versionCode` and `package_name` are NOT
read out of the APK** — they are taken from the manifest. The manifest is not
signed, so treat those as provenance metadata, not proof.

What makes the whole thing trustworthy is the pin: the expected certificate
fingerprint lives in **configuration**, not in the manifest. If it came from the
manifest, an attacker replacing the APK would simply replace the manifest with
it — naming their own key — and every check would pass. That is a checksum, not
a signature check, and it is the defect this design exists to avoid.

**While no production key is pinned, `signer_trust_anchor` FAILS and the command
exits non-zero.** That is correct: there is no authority to verify against yet.
Do not work around it — pin the fingerprint (a Phase-4 entry step).

Manual equivalents, if you need to see them yourself:

```bash
sha256sum DaengtisiaMS-Clinic-v1.0.0.apk
apksigner verify --print-certs DaengtisiaMS-Clinic-v1.0.0.apk | grep -i 'SHA-256'
```

**Fail-closed checkpoint 1** — if the SHA-256 or the signer fingerprint does not
match the manifest, **stop**. Do not install "just to test". A mismatched signer
is either the wrong build or a substituted artifact, and both are incidents.

---

## 3. First install (new device)

Preconditions: tablet meets the
[procurement specification](../operations/clinic-tablet-procurement-specification.md),
and §2 verification passed.

```bash
adb install DaengtisiaMS-Clinic-v1.0.0.apk
```

Then provision Device Owner and enrol the device — full procedure in the
[provisioning runbook](android-clinic-device-provisioning.md) §2–§6.

**Fail-closed checkpoint 2** — confirm the installed package is the release
build, not a debug one:

```bash
adb shell pm list packages | grep com.daengtisia.clinic
```

A `.debug` suffix means a debug build was installed. Uninstall and start again.

---

## 4. Update an existing device

This is the path that must **preserve** the device's enrolment.

```bash
# Verify first (§2), then:
adb install -r DaengtisiaMS-Clinic-v1.1.0.apk
```

Same package + **same signer** + **higher versionCode** = in-place update. App
data survives → the Android Keystore identity survives → the DoctorDevice
enrolment survives. The doctor notices nothing.

**Verify after updating:**

- the app launches and reaches `https://daengtisia.online`
- the device still shows **Aktif** / **Terverifikasi kriptografis** in
  Master Data → Device Dokter
- Lock Task is still active after a reboot

**If the update is refused**, Android is telling you one of two things, and both
matter:

| Failure | Meaning |
|---|---|
| `INSTALL_FAILED_UPDATE_INCOMPATIBLE` / signatures do not match | The new APK is signed by a **different key**. Do not uninstall to force it — see §5. |
| `INSTALL_FAILED_VERSION_DOWNGRADE` | The new APK has a **lower versionCode**. There is no supported production downgrade — see §6. |

---

## 5. Signer mismatch — do not "fix" it by uninstalling

The tempting move is `adb uninstall` then install. **Do not.**

Uninstalling erases app data. App data holds the Android Keystore device
identity. Erasing it destroys the enrolment, and the device must be re-enrolled
and re-approved by a Super Admin.

A signer mismatch means the artifact is not from the DaengtisiaMS signing
authority. Treat it as a release-integrity problem: stop, verify the artifact
against the manifest again, and escalate to the signing custodian. The correct
outcome is usually "that was the wrong file", not "erase the tablet".

If a genuine signing-identity change is ever required, it is a planned migration
with fleet-wide re-enrolment, not an ad-hoc install.

---

## 6. Bad release — stop distribution, then forward-fix

There is no production downgrade. Installing an older `versionCode` requires
uninstalling first, which destroys the device identity. `adb install -d` bypasses
the block only for **debuggable** builds, which a production release is not.

1. **Stop distributing** — pull the artifact from the release source so no
   further device receives it.
2. **Fix forward** — revert the code, bump `versionCode` **up**, sign, verify,
   publish.
3. **Install the fix** on affected devices.
4. Devices that already updated stay on the bad version until the fix reaches
   them. If the clinic is blocked meanwhile, the doctor falls back to **browser
   login**, which is never denied — precisely so this door stays open.

| Scenario | Action |
|---|---|
| Bad APK on one device | Install the forward-fix on that device |
| Bad APK on several devices | Stop distribution, forward-fix, visit each device |
| Not yet installed anywhere | Pull from the release source; nothing else needed |
| Server/client incompatibility | Ship the tolerant server first; the client follows within the grace period |

---

## 7. ADB policy

ADB is acceptable for **controlled provisioning**. It is not a fleet management
strategy.

| Stage | USB debugging |
|---|---|
| Provisioning / update visit | temporarily **enabled** by the installer |
| Normal clinic operation | **disabled** |

Leaving USB debugging on turns every kiosk tablet into an open install surface
for anyone with a cable and thirty seconds. Disable it before the device returns
to the floor, and re-enable it only for a maintenance visit.

---

## 8. Record every install

Per install: device serial, branch, APK filename, versionName, versionCode,
signer fingerprint verified (yes/no), installer, date.

Without this, "which build is on that tablet?" is answered by guessing — and
with no Play console to consult, guessing is the only alternative.
