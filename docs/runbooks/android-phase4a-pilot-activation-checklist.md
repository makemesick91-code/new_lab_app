# Phase 4A doctor Android pilot — operator activation checklist

**Owner:** Custodian 1 / IT
**Applies to:** the ACTIVATION sprint, `PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1`
**Prepared by:** `PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1`

This checklist is followed line by line. Anything it does not authorise is out of
scope, and any line that cannot be completed is a STOP, not a judgement call.

---

## Pilot scope — the whole of it

| Item | Value |
| --- | --- |
| Doctor | drg Karmila |
| Branch | Cabang Sunu |
| Device (logical label) | `PHASE4A_PILOT_TABLET_01` |
| Devices | 1 |
| Branches | 1 |
| Doctors | 1 |

No other doctor, branch or device is in scope. A hardware serial is never
recorded in this repository; the logical label is the only device reference.

## The device model — read this before touching anything

Phase 4A is **non-destructive**.

| Requirement | Value |
| --- | --- |
| Factory reset | **NOT required, and must not be performed** |
| Device Owner provisioning | **NOT required, and must not be performed** |
| Full kiosk / lock task | **NOT required** |
| Managed Google Play | Not required |
| Distribution | Direct admin-managed APK |

The owner has refused a factory reset for this pilot and the tablet is already
in service. Android grants Device Owner only on a device with no accounts on it,
so Device Owner and lock task are **deferred to a later dedicated-device phase**
along with the four escape-block checks. They are not cancelled; they are owed
by whoever provisions a dedicated device.

**What the deferral costs, and what pays for it.** Lock task was also what
physically kept a doctor away from a browser. Without it, "app-only" is a
server-side decision and nothing else — so on this pilot the app-only property
is true only while pilot enforcement is armed for this doctor. That is why
step E4 exists and why step F7 is not optional.

---

## A. Pre-install verification (do not skip, do not reorder)

Performed on the admin workstation, from the retained release directory. The
workstation does **not** hold the signing key.

- [ ] **A1.** Confirm the owner has approved this activation, in writing.
- [ ] **A2.** Confirm the pilot doctor is drg Karmila, the branch is Cabang Sunu.
- [ ] **A3.** Confirm the tablet is the one labelled `PHASE4A_PILOT_TABLET_01`.
- [ ] **A4.** Confirm the tablet has **not** been factory reset for this pilot.
- [ ] **A5.** Confirm no global doctor enforcement is active on production:
      `php artisan android:phase4a-pilot-readiness` must print
      `GLOBAL_ENFORCEMENT_ACTIVE=false`.
- [ ] **A6.** Confirm filename: `DaengtisiaMS-Clinic-v0.3.0-phase3-production.apk`.
- [ ] **A7.** Verify the artifact digest. It must equal, in full:
      `ab3e30df111ca3cfb6aa5efeb37dde1b3624e822c88134065c2c314a2fd10a03`
      ```
      sha256sum DaengtisiaMS-Clinic-v0.3.0-phase3-production.apk
      ```
- [ ] **A8.** Verify the signature:
      ```
      apksigner verify --verbose --print-certs DaengtisiaMS-Clinic-v0.3.0-phase3-production.apk
      ```
      Expect: verified, exactly 1 signer, v2 and v3 true.
- [ ] **A9.** Read the signer certificate digest out of that output and compare
      it, **in full**, to the pinned production certificate:
      `79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9`
- [ ] **A10.** Confirm the signer equals the pin recorded in **source control**
      (`android_release.signing.production_certificate_sha256`), not the copy in
      the manifest file. Whoever can replace the APK can replace the file
      sitting next to it.
- [ ] **A11.** Confirm package identity: `com.daengtisia.clinic`,
      versionName `0.3.0-phase3`, versionCode `1`.
- [ ] **A12.** Any mismatch in A6–A11 is a **STOP**. Do not install, do not
      re-sign, do not "try the other copy".

## B. Device precheck

- [ ] **B1.** Battery above 50%, or on power.
- [ ] **B2.** Clinic network reachable; the server certificate validates.
- [ ] **B3.** Device clock is correct and set automatically. A device-issued
      signature over a server nonce is time-sensitive.
- [ ] **B4.** Record the Android version and security patch level.
- [ ] **B5.** Confirm a **screen lock is enabled**. Without Device Owner nothing
      enforces this, and an unattended unlocked tablet is the residual risk of a
      non-destructive pilot.
- [ ] **B6.** Note whether the Clinic app is already installed. If it is, this is
      an **update in place** — see section C.
- [ ] **B7.** Preserve all user data. Do not clear app data. Do not uninstall.

## C. Install — NOT PERFORMED BY THE PREPARATION SPRINT

- [ ] **C1.** Enable install-from-unknown-sources for the installing app only.
- [ ] **C2.** Install the verified APK.
- [ ] **C3.** **Revoke** the unknown-sources permission immediately afterwards.
- [ ] **C4.** Confirm USB debugging is **off**, and verify it.
- [ ] **C5.** If this is an update: same application id, same signer, versionCode
      strictly higher. A signer mismatch cannot update in place, and uninstalling
      to force it **destroys the Android Keystore device identity and the
      enrolment with it**. That is a STOP, not a workaround.
- [ ] **C6.** Confirm the app launches and reaches the server.

## D. Enrollment — NOT PERFORMED BY THE PREPARATION SPRINT

- [ ] **D1.** The app generates its device identity in the Android Keystore.
- [ ] **D2.** Confirm the key is hardware-backed and non-exportable.
- [ ] **D3.** The device proves the key by signing a server-issued single-use
      nonce. A `User-Agent`, an IMEI, a MAC, an Android ID or a static header is
      **never** device identity.
- [ ] **D4.** Record the device by its logical label. Do not commit a serial.

## E. Pending authorization and approval — NOT PERFORMED BY THE PREPARATION SPRINT

- [ ] **E1.** An unknown (doctor, device) pair becomes a **PENDING** request
      automatically.
- [ ] **E2.** Approve or reject in **Approval Device Dokter**. Only Super Admin
      and Supervisor RME may do this. Master Data → Device Dokter is a different
      screen and is not the approval surface.
- [ ] **E3.** A rejection requires a reason. A rejected request does **not**
      silently reopen; a re-request is explicit.
- [ ] **E4.** Arm the enforcement scope **before** arming enforcement, and in
      that order:
      1. Set the scope to the pilot doctor on the host
         (`ANDROID_DOCTOR_ENFORCEMENT_SCOPE_MODE=pilot`, and the pilot doctor's
         production user id).
      2. Leave fleet-wide permission off.
      3. Clear the config cache.
      4. Only then arm `FEATURE_DOCTOR_TRUSTED_DEVICE_ENFORCEMENT`.
      Arming the flag while the scope covers nobody enforces **nothing**. It
      looks like protection and is not. `android:phase4a-pilot-readiness` fails
      on exactly that state — run it.

## F. Pilot test matrix — executed by the activation sprint

- [ ] **F1.** Approved device on the approved doctor: login succeeds.
- [ ] **F2.** Device DISABLED: denied.
- [ ] **F3.** Device REVOKED: denied, and not reactivated by an ordinary edit.
- [ ] **F4.** Authorization revoked while a session is open: the session stops on
      its next protected request.
- [ ] **F5.** Daily branch lock holds; the doctor cannot be moved to another
      branch by a request parameter.
- [ ] **F6.** Room scope holds: other-room active patients hidden, and direct
      access by id denied — not merely hidden in the UI.
- [ ] **F7.** **The pilot doctor cannot log in through a browser.**
- [ ] **F8.** **A doctor at another branch CAN still log in through a browser.**
      If F8 fails, the pilot has become a fleet-wide lockout: disarm immediately
      per G1.
- [ ] **F9.** Doctor cannot print or download RME, RM, PDF or odontogram — check
      the route and the endpoint, not only the hidden button.
- [ ] **F10.** Device screen lock, USB debugging off, unknown-sources revoked.

## G. Rollback — decided now, not during the incident

| Case | Action |
| --- | --- |
| **G1.** Server-side pilot problem | Move the scope mode off pilot or clear the pilot doctor id, clear the config cache. The doctor is back on browser login the same minute. Nothing is unenrolled. |
| **G2.** Device authorization problem | Disable or revoke the authorization in Approval Device Dokter. The open session stops on its next protected request. The device key survives. |
| **G3.** Bad APK behaviour, app still launches | Forward fix: same application id, same signer, strictly higher versionCode, update in place. |
| **G4.** App unusable | Disarm pilot enforcement first so the doctor can work, then diagnose without clinical time pressure. Do not uninstall to "start clean". |
| **G5.** Signer mismatch | **STOP.** Do not re-sign, do not uninstall. Establish which artifact is wrong. |
| **G6.** Device identity lost | Treat as a new device: enrol again, approve again, record why the identity was lost. Installation alone restores no trust. |

Uninstall appears in no routine route above, on purpose.

## H. GO / NO-GO

GO requires every box in A, B, C, D, E and F ticked, including **F8**.

NO-GO on any of: a digest or signer mismatch; a factory reset performed; Device
Owner provisioned; fleet-wide enforcement armed; F8 failing; a rejection recorded
without a reason; a revoked device usable; a doctor able to print.

The pilot is not GO because the app installed. It is GO when the boundary has
been demonstrated in both directions — the pilot doctor denied a browser, and
every other doctor unaffected.
