# TLK1 provisioning ceremony — corrected plan

`DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-PROVISIONING-1`. Operator-facing.
Base authority `a2caf476f6fe5017f0b209c1261f1eea312a2151` (production HEAD, tag
`revision-doctor-trusted-device-estate-capacity-policy-1-go`).

## Why this document exists

The obvious plan — *register the tablet, approve it, enrol a WebAuthn
credential, it becomes eligible* — **does not work**, and it fails **silently**.
An adversarial review caught it before any hardware was touched. Two of its
findings were verified independently against the deployed source.

---

## The three corrections that change the ceremony

### C1 — WebAuthn does NOT make a device eligible. The Android keystore does.

```php
// DoctorEstateResilienceService
private function isEligible(DoctorDevice $device): bool
{ return $device->isActive() && $device->isCryptographicallyVerified(); }
```

`identity_state = cryptographically_verified` is written in exactly **three**
places, all Android-keystore challenge-response:

- `DoctorAppLoginService:411`
- `DoctorAppLoginService:447`
- `DoctorDeviceProofService:169`

`DoctorDeviceWebAuthnRegistrationService` references `identity_state`
**zero times**. Every registration/approval path writes `IDENTITY_UNVERIFIED`.

**Consequence:** a tablet that is registered, approved and carrying a happy
green WebAuthn credential is **still not eligible**, TLK1 stays at
`eligible_device_count = 0`, and Level 1 stays FAIL — with no error anywhere.

**So:** the tablet must run the **Android Clinic App** and complete the
keystore proof. That is the step that creates eligibility. The WebAuthn
credential is a **separate, later** requirement (it is what Level 1 counts as
*login-admissible*), and it is needed too — but it is not what makes the device
eligible.

### C2 — A stock tablet's passkey will be REFUSED (BE=1)

`config/webauthn.php:138` — `require_device_bound` defaults **true**.
`WebAuthnDeviceBinding::verdict()` returns `device_bound` only when
`backupEligible === false`. The config says it outright: *"a BE=1 credential is
REFUSED at registration."*

A modern Android tablet using **Google Password Manager** as its passkey
provider mints **synced** passkeys → `BE=1` → rejected with a validation error.
`authenticator_attachment: platform` does **not** help; the config's own comment
says *"platform DOES NOT MEAN device-bound."*

**So:** before the ceremony, make the tablet mint a **device-bound (BE=0)**
passkey — screen-lock-only passkey provider, or Google passkey sync disabled
for the account on that tablet.

**Do NOT** set `WEBAUTHN_REQUIRE_DEVICE_BOUND=false` to make it pass. That
converts a measured security gate into a fiction, and the whole "approved
physical device" property collapses with it.

### C3 — The branch must be bound by the ADMIN, not derived from the doctor

Two enrolment paths behave differently:

| Path | `branch_id` comes from |
|---|---|
| Admin approves a pending enrolment | the **operator's explicit choice** ✅ |
| Bare Clinic App auto-registration | the **doctor's lowest-id RME branch** ❌ |

`DoctorAppLoginService::resolveDeviceBranchId()` picks the signing doctor's
lowest-id active RME branch. If the first doctor to sign in is multi-branch with
a branch id below TLK1's, the tablet is created **under the wrong branch** and
TLK1 stays FAIL while the report shows a shiny new eligible device.

Compounding: an admin-registered row has `public_key_fingerprint = null`, and
app login looks devices up **by fingerprint** — so it will not find that row and
will create a **second, duplicate** device at the doctor-derived branch.

**So:** drive the canonical enrolment flow — app requests enrolment, admin
approves it **into a TLK1 row with `branch_id` chosen explicitly**. Never rely
on bare app-login auto-registration.

---

## Hardware pre-qualification (before anything else)

- [ ] Factory-fresh, **or** fully wiped including Clinic App data.
      Two devices in the estate are **revoked**. `public_key_fingerprint` is
      globally unique and there is **no un-revoke path**: if this tablet is one
      of them and its keystore alias survived, enrolment is denied permanently.
      A data wipe yields a new alias and therefore a clean new identity.
- [ ] Can mint a **BE=0** passkey (see C2).
- [ ] Device name intended for TLK1 is unused there
      (`mst_doctor_devices` is unique on `(branch_id, device_name)`;
      TLK1 currently holds **no** devices, so any sensible name is free).

## Preconditions, pinned from production at plan time

| Fact | Value | Why it matters |
|---|---|---|
| `UNSET_DOCTORS` | **0** | any doctor with no home branch flips Level 1 to UNVERIFIED, which is *not* a pass |
| Linked doctors | **15** | the authorization denominator |
| Staffed set | TLK1, LDK2, SPN4 | ATG3 is unstaffed and therefore NOT_APPLICABLE |
| ATG3 covers | none active | an active cover at ATG3 pulls it into the Level-1 population, where it holds no device and would drag Level 1 down |

**Abort conditions:** any of the above moving during the window. In particular,
soft-deleting TLK1's last homed doctor would turn Level 1 green *with no
hardware provisioned* — that is a false pass, not a fix.

---

## Ceremony

### 1 — Hardware preflight (operator, on the tablet)

Per `docs/runbooks/android-clinic-device-provisioning.md` §2A.

```bash
adb shell getprop ro.boot.verifiedbootstate    # expect: green
adb shell getprop ro.boot.flash.locked         # expect: 1
adb shell getprop ro.boot.vbmeta.device_state  # expect: locked
adb shell getprop ro.kernel.qemu               # expect: 0  (not an emulator)
adb shell pm list features | grep -i keystore  # hardware_keystore expected
```

Then **the gate** — the instrumented measurement, not the capability flag:

```bash
cd android/daengtisia-clinic
./gradlew connectedDebugAndroidTest \
  --tests '*DeviceIdentityInstrumentedTest*'
```

Accept only `KeyInfo.getSecurityLevel()` = `TRUSTED_ENVIRONMENT` or
`STRONGBOX`, **and** the key non-exportable.
`SOFTWARE` and `UNKNOWN_SECURE` are rejections. StrongBox absent is **normal**
and is not a failure. A deprecation warning from the pre-31 branch is not a
finding.

**Report back:** model, Android version, API level, the four boot props, the
security level, exportability, and the test's pass/fail + counts.
**Do not send** the ADB serial, IMEI, MEID, ICCID or Android ID — governance
wants the class of device, never the instance.

### 2 — Install the signed app (operator)

```bash
php artisan android:verify-release <APK> <manifest>   # verify BEFORE touching the tablet
adb install <APK>
adb shell pm list packages | grep com.daengtisia.clinic   # must NOT end in .debug
```

The manifest for `v0.3.0-phase3` is in-repo at
`docs/evidence/android-release/`; the APK itself comes from the
access-controlled release source.

### 3 — Enrolment request (operator, on the tablet)

Launch the app. It generates a **non-exportable EC P-256** keypair in the
Android Keystore and requests enrolment, then displays an **8-character pairing
code**.

**Report back:** the pairing code and the key fingerprint shown on screen.

### 4 — Approval (Super Admin, in the web UI) — **STOP for approval**

`Master Data → Device Dokter` → find the pending request → **match the pairing
code AND the key fingerprint** → approve, setting:

- `device_name` = the agreed TLK1 name
- `branch_id` = **TLK1** (explicitly — see C3)

A fingerprint mismatch means you are approving a different device. Stop.

### 5 — Cryptographic verification (automatic)

The app answers a server challenge by signing it. Only then does
`identity_state` become `cryptographically_verified`.

**Assert before going on:** status `Aktif`, identity `Terverifikasi
kriptografis`, fingerprint matches the tablet, branch is TLK1.

### 6 — WebAuthn credential (one, device-scoped)

The credential hangs off `doctor_device_id` and there is **no doctor column** —
so **one enrolment serves every authorized doctor**. Do not enrol 15.

**Assert:** `device_bound_verdict = device_bound`, not revoked, user-verified.

### 7 — Authorization matrix (me, from here)

Eligible devices 3 → 4, so target becomes 15 × 4 = **60** with **15 new pairs**.

```bash
php artisan doctor:device-bulk-authorize --actor=<id>          # dry run, prints a digest
php artisan doctor:device-bulk-authorize --actor=<id> --apply --confirm-plan=<digest>
```

This is **digest-bound, fail-closed, single-actor** — not maker/checker. The
digest binds the eligible sets and both pair lists; if the estate moves between
plan and apply, it refuses. It cannot grant against a non-eligible device
(re-checked inside the service), and it touches no credential, branch lock,
lease, cohort or flag.

### 8 — Measure Level 1 and the composed prerequisite **together**

Between "device becomes eligible" and "bulk-authorize completes",
`authorization_coverage` is **red by design** — the gate's own text says a new
tablet makes it red until every doctor is authorized on it. The composed
activation measurement takes the worst of Level 1, credential coverage and
authorization coverage.

So report both, or report neither. Level 1 PASS beside a red composed
measurement is not programme success.

---

## What this will make measurably worse, and we say so

The new device lands in fleet-readiness `without_readiness_proof_ids` — no
doctor has a real proven login on it yet. Fleet "overall ready" requires that
list to be empty. **That gate is not one this sprint must pass**, and no
15-doctor login ceremony is required: Level 1's formula reads only `staffed` and
the count of locally usable devices. But the number will move, and it should not
surprise anyone.
