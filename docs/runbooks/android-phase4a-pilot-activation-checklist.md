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
- [ ] **F11.** Confirm the audit trail actually recorded the run. Every event in
      `android_release.phase_4a.audit_events_required` must be present for this
      pilot, and three of them are individually accountable
      (`audit_events_mandatory`): who approved, who rejected **and why**, and why
      a session was invalidated. A pilot with no approver named and no rejection
      reason recorded is a pilot that gets re-litigated a month from now.
      Record the logical device label — never a serial, an IMEI, a MAC or an
      Android ID.

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

GO requires every box in A, B, C, D, E and F ticked, including **F8** and **F11**.

NO-GO on any of: a digest or signer mismatch; a factory reset performed; Device
Owner provisioned; fleet-wide enforcement armed; F8 failing; a rejection recorded
without a reason; a missing mandatory audit event; a revoked device usable; a
doctor able to print.

The pilot is not GO because the app installed. It is GO when the boundary has
been demonstrated in both directions — the pilot doctor denied a browser, and
every other doctor unaffected.

---

## Activation record — what the live pilot actually taught

Added by `PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1` after the pilot was armed
on real hardware. Everything above was written before; this section is what the
run itself produced, and it is kept separate so the two are never confused.

### The pilot, as activated

| Item | Value |
| --- | --- |
| Doctor | drg Karmila, **`users.id` 18** |
| Branch | Cabang Sunu, **`SPN4`**, branch id 5 |
| Device | `PHASE4A_PILOT_TABLET_01`, device id 1 |
| Device state | `active`, `cryptographically_verified` |
| Authorization | `active` |
| Pilot enforcement | armed |
| Global enforcement | **false**, and unreachable from a host |

### The identifier that decides who gets locked out

Enforcement targets a **`users.id`**. drg Karmila is `users.id` 18 and
`mst_doctors.id` 21, and `users.id` 21 is a **different doctor**. Setting 21
would leave the pilot doctor unenforced *and* deny an unrelated doctor her
browser — the exact outcome F8 exists to catch. Resolve the id from `users`,
confirm the name on the row, and let `android:phase4a-pilot-scope` name the
covered doctor before arming.

`ANDROID_PILOT_ENFORCEMENT_BRANCH_CODE` is **advisory only**. It appears in
reports and audit and decides nothing; `BranchContext` remains the authority on
where a doctor is working. The device label is likewise administrative — trust
is the key, never the name.

### Arming order, corrected by experience

Arming while the doctor holds an enforcement-OFF **web** session invalidates
that session on its next protected request — correctly, because a browser
session carries no device binding. What the checklist did not say is that the
app had already navigated its WebView to the web login form, which then becomes
a dead end: that form is an ordinary browser login and is refused.

The doctor is not stuck; she is on the wrong screen. Recovery is to **force-stop
and reopen the app** so it returns to its **native** login, which takes the
device-api path and mints a ticket.

Prefer this order:

1. verify scope names exactly the pilot doctor, enforcement still off;
2. arm through `android:phase4a-pilot-enforcement arm --actor= --reason=`;
3. have the doctor **force-stop and reopen the app** (or arm before she logs in);
4. native login → challenge → ticket → `/device-login/<ticket>` → device-bound session;
5. re-run the scope diagnostic and require denied=1, allowed=all-others.

Ordinary web login stays denied for the pilot doctor while armed. That is the
feature, not a fault.

### Arming is now an audited command, not a file edit

`android:phase4a-pilot-enforcement status|arm|disarm` is the canonical path. It
refuses to arm without a reason and an authorised actor, refuses a scope that
covers nobody, several doctors, or someone other than the declared target,
refuses while fleet-wide permission is on, verifies the result **in a fresh
process** (the writing process still holds the old config), rolls the
environment back if that verification is not GO, and writes
`PILOT_ENFORCEMENT_SCOPE_CHANGED` with actor, reason, before/after and the
post-change counts.

A silent environment-only arm is no longer the normal workflow. It leaves no
audit row, and F11 requires one.

### Client defect the server currently compensates for

With enforcement off the server sends no ticket. It used to send
`"login_ticket": null`; Android's `optString` returns the literal string
`"null"` for a JSON null, so the shipped v0.3.0-phase3 client read a 4-character
ticket, took the wrong branch and navigated to `/device-login/null` — a 404 from
a login that had entirely succeeded. The server now **omits** the key.

The client defect is still in the APK on the tablet, along with an error mapping
that reports validation failures and wrong passwords as
"Tidak dapat menghubungi server". Fixing it is
`BUGFIX-ANDROID-LOGIN-NULL-PARSING-ERROR-MAPPING-1` — versionCode 2, same
permanent signer, update in place.

### Console REPL control, stated exactly

An execution-time guard now refuses `artisan tinker` on any environment outside
`local`/`testing`, whoever types it, including over SSH — proven by a real
subprocess test, not only by a dispatched event.

**It does not block `artisan tinker --version`.** Symfony answers `--version`
before any command is resolved, so no application guard can see it. That form
starts no REPL and writes no log record, but it remains **prohibited by
policy**, and this limit is written down rather than left to be rediscovered.

### Still owed at the time of writing

The rejection, disable, revoke and governed-recovery tests are **not** done.
They interrupt a working clinician and need an explicit safe clinical window.

---

## Disruptive ceremony — where it actually got to

Recorded 2026-09-07. Two of the four disruptive checks are closed; the other two
are owed, and this section says exactly which and why.

### Closed

| Check | State | Evidence |
| --- | --- | --- |
| **Disable ceremony** | PASS | `DOCTOR_DEVICE_DISABLED` 12:13:53 by user 1, reason recorded verbatim as **`disable`** |
| **Recovery after disable** | PASS | `DOCTOR_DEVICE_REACTIVATED` 12:18:01 by user 1; login 200, one real ticket, redeemed once, `/dashboard` 200 |
| **Rejection with reason** | suite only | `reject()` requires `isPending()`; the live authorization is ACTIVE, so it is not rejectable, and manufacturing a PENDING pair needs a second clinician's credentials |

Disabling changed **status only**. Device id 1, fingerprint, public key,
`cryptographically_verified` and branch 5 all survived it, and the authorization
stayed ACTIVE throughout. One device, one distinct fingerprint — no duplicate.

### F2 is NOT proven in production

**`F2_PRODUCTION = NOT_EXERCISED`.** No login attempt was ever made against the
disabled device. Between the disable (12:13:53) and the reactivate (12:18:01)
the tablet sent only two enrolment-status polls, both 200 — no
`doctor/challenge`, no `doctor/login`. The disable-denies-login property is
therefore carried by the suite alone.

To close it in the next window, in this order, on the SAME device:

1. Disable through Master Data → Device Dokter. No SQL, no REPL, no
   delete/recreate.
2. **Exactly one** native login while disabled. Then verify server-side that the
   request reached the device API, resolved to device 1, was denied because of
   the disabled status, minted **zero** tickets and created **zero** sessions.
   If no request reaches the server, the result is `NOT_PROVEN` — tablet wording
   is not evidence.
3. Reactivate, one native login, and require the full chain again: same device
   id, same fingerprint, verified trust preserved, no duplicate, one ticket
   redeemed once, clinical interface restored, then F7/F8 GO.

### Revoke is not started, and here is what it costs

Before anyone authorises it:

- The server refuses re-enrolment of a revoked key **permanently** — *"revocation
  is terminal, and reuse requires a genuinely new key."* Revoking retires
  fingerprint `701b4ae9` for good.
- Recovery therefore **must** produce a NEW `DoctorDevice` record. There is no
  continuity path, by design. Do not describe a replacement record as continuity.
- The app clears its dead Keystore key only from the **device-status** screen
  (`MainActivity` `BLOCKED_REVOKED` → `clearIdentity()`). The doctor-login path's
  `ACCESS_REVOKED` shows a message and clears nothing.

**The trap:** if the tablet sits on the doctor-login screen after a revoke, it
may never clear the key, and the only remaining recovery is clear-data — which
is prohibited because it destroys the Keystore identity and the enrolment with
it. Mitigation: **force-stop and reopen the app immediately after revoking**, so
it runs its device-state check.

Budget for a fresh enrolment plus approval inside the 15-minute enrolment TTL,
with the approver already on the approval screen.

---

## Revoke ceremony — what happened, and one correction to read alongside it

Recorded 2026-09-07. Verified against the server, not from the ceremony report.

### The lifecycle, as it actually ran

```
570  DEVICE_REVOKED            device 1   by 1   12:32:00
571  REGISTRATION_REQUESTED    fp e3a7…          12:35:22
572  REGISTRATION_APPROVED     device 3   by 1   12:37:18
573  DEVICE_PROOF_VERIFIED     device 3          12:37:43
574  AUTHORIZATION_PENDING     auth 2            12:37:59
576  login outcome=pending, refused              12:37:59
577  AUTHORIZATION_APPROVED    auth 2     by 1   12:38:07
579  AUTHORIZATION_SUCCESS                       12:38:30
```

HTTP corroborates end to end: `enrollment/request 201` → `challenge 200` →
`proof 200` → `doctor/login 200` (pending) → status poll → `doctor/login 200`
(active) → `/device-login/<ticket> 302` → `/dashboard 200`. Ticket 5 on device 3,
redeemed once.

### Two identities, one tablet

| | `_01` | `_02` |
| --- | --- | --- |
| Device id | 1 | 3 |
| Fingerprint | `701b4ae9…` | `e3a73028…` |
| State | **revoked**, terminal | **active**, cryptographically verified |

Revocation is terminal by design: the server refuses re-enrolment of a revoked
key, so recovery could not restore `_01` and produced a second identity on the
same physical hardware. **A replacement record is not continuity** and must never
be described as such. Two devices, two distinct fingerprints — no duplicate for
any one key.

### CORRECTION — audit row 570 reads `reason: "disable"`

Row 570 is a **revoke**, and its recorded reason is the string `disable`,
carried over from the earlier disable ceremony.

**The row is correct as a record of what was submitted and is deliberately not
edited.** An audit trail that gets tidied after the fact is no longer an audit
trail. This annotation is the correction: where row 570 says `disable`, the
action was `DOCTOR_DEVICE_REVOKED` on device 1 at 12:32:00 by user 1, performed
as the planned Phase 4A revoke ceremony — not a disable, and not a repeat of the
12:13:53 disable.

Operators entering a reason should describe the action being taken; a reason
copied from a previous step survives in the trail long after the context does.

### F11 stands at 6 of 8

Present with actors: `device_enrollment_requested`,
`authorization_pending_created`, `authorization_approved`, `device_disabled`,
`device_revoked`, `session_invalidated_with_reason`.

Still absent, and **not** to be satisfied by lookalikes:

- **`authorization_rejected_with_reason`** — no admin rejection has been
  performed. `DOCTOR_APP_LOGIN_AUTHORIZATION_REJECTED` records a login whose
  outcome was `pending`; it is not an admin rejection and does not close this.
- **`pilot_enforcement_scope_changed`** — the live arming predated the audited
  command. **Do not fabricate or backdate it.** A real event is produced by a
  controlled DISARM → verify → ARM → verify through
  `android:phase4a-pilot-enforcement`, in a clinical window, because disarming
  returns the doctor to browser login and re-arming denies it again.

### `F2_PRODUCTION` remains NOT_EXERCISED

No login was ever attempted against a disabled device. Governance does not
mandate production exercise, so the property is carried by the suite and the gap
is stated rather than closed by inference.
