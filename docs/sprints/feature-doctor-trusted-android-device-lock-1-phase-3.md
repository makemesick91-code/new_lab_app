# FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 3

**Android Clinic App + cryptographic device enrolment capability**

- Branch: `feature/doctor-trusted-device-lock-1-phase-3-android-enrollment`
- Base: Phase 2 GO `ca12e4a6d69b64338af6da1be3a57b3a968a922c` (tree `0f50c53e…`, = production)
- Rules: `.cursor/rules/140-android-clinic-device-identity.mdc` (new); 138 (Phase 1) and 139 (Phase 2) still in force
- Runbook: `docs/runbooks/android-clinic-device-provisioning.md`

---

## 1. Status

| | |
|---|---|
| Phase 3 | **Capability ready** — server deployed, emulator verified |
| `DEVICE_ENFORCEMENT_ACTIVE` | **false** |
| `DOCTOR_BROWSER_LOGIN_DENIED` | **false** |
| Real-device validation | **NOT PERFORMED** — no clinic tablet exists |
| Production signing governance | **UNRESOLVED** |
| Full feature | **NO-GO** |

The full-feature tag `feature-doctor-trusted-android-device-lock-1-go` is **not**
created and must not be until enforcement is genuinely active on real hardware.

## 2. What was built

### Android Clinic App — `android/daengtisia-clinic`

Kotlin native shell (`com.daengtisia.clinic`, v0.3.0-phase3) around a hardened
WebView. The clinical application stays the existing Laravel system; nothing is
reimplemented natively.

Deliberately dependency-light: platform `HttpsURLConnection` + `org.json`, no
OkHttp/Retrofit/serialization library. Fewer third-party dependencies is less
supply-chain risk on a device that holds clinical sessions.

- **`DeviceIdentityManager`** — EC P-256 keypair generated **inside the Android
  Keystore**; private key non-exportable, never transmitted, never in
  SharedPreferences, never in the WebView, never logged. StrongBox is requested
  opportunistically and falls back to the TEE-backed Keystore (a hard StrongBox
  requirement would refuse legitimate tablets). There is deliberately no
  `exportPrivateKey`.
- **`UrlAllowlist` / `SecureWebViewClient`** — https only, clinic host only,
  cleartext off, `onReceivedSslError` **always cancels**, no
  `addJavascriptInterface`, file/content/universal access off, external links
  **blocked rather than handed to Chrome**.
- **`KioskController` + `ClinicDeviceAdminReceiver`** — Device Owner / Lock Task,
  not screen pinning. Not-provisioned is a supported state and is reported
  honestly. No hard-coded exit PIN, no secret gesture.
- **`DeviceStateMachine`** — pure Kotlin startup decision, fail-closed: only a
  server-declared `trustworthy` device reaches the WebView.

### Server — enrolment protocol

Additive migrations: `trx_doctor_device_enrollments`,
`trx_doctor_device_challenges`, and enrolment columns on the Phase 2 registry.

Stateless device channel at `/device-api/v1/*` (registered as an `api:` group so
it carries no session cookie and no CSRF token — the caller is hardware, not a
logged-in human), rate limited per surface.

```
request enrolment → one-time pairing code → Super Admin approves in
Master Data → Device Dokter → key bound → challenge → signature → verified
```

- Pairing code stored **only as a keyed hash**; returned to the device once.
- Public key **frozen at request time**, so it cannot be swapped before approval.
- Approval binds the key but does **not** verify it — the hardware still has to
  sign a challenge.
- Nonce: 32 CSPRNG bytes, unique, short-lived, bound to one device.
- Every denial returns the **same opaque error**; the reason lives only in the
  audit trail, so the endpoint cannot enumerate the device estate.

## 3. Three findings worth keeping

**A real vulnerability, caught by a test.** The first cut burned the challenge
nonce *inside* the verification transaction. A denial throws → the transaction
rolls back → the burn is undone → an attacker can retry signatures against a
fixed nonce indefinitely. `verifyProof()` now claims the nonce in its **own
committed transaction** before verifying. Mutant **M15** reinstates the bug and
the suite kills it.

**M6 was inert as written.** Removing the `isRevoked()` branch changed nothing,
because a revoked device also fails `isActive()`. The branch's real job is the
distinct **audit reason** — an incident responder must tell "temporarily
disabled" from "trust permanently withdrawn". A test now pins that, and M6 dies.

**AGP 9 ships Kotlin support.** Declaring `org.jetbrains.kotlin.android`
alongside AGP 9.0.1 is a hard error, not a redundancy.

## 4. Enforcement is off — structurally

Nothing in authentication, session handling or web middleware references
`DoctorDevice`, and **no web route carries a device middleware**. Two assertions
enforce this: a source scan of the auth surfaces, and a sweep of every
registered route's middleware. Mutant **M9** proves they bite.

Device identity and user session stay separate concepts. A successful proof
means *"this is registered clinic hardware"* — the API says so in its own
response body.

## 5. Evidence

| Gate | Result |
|---|---|
| Server device suites | 77 passed |
| RME + Pilot + AccessControl + DoctorDevice | **1729 passed / 0 failed** (Phase 1 + 2 intact) |
| PostgreSQL 16.14 parity | 76 passed; FKs, unique nonce, unique pairing-code hash and all 7 new columns verified |
| Android unit tests | 28 passed (UrlAllowlist, DeviceStateMachine, DeviceProofMessage, TLS contract) |
| Android instrumentation (real AndroidKeyStore, emulator) | 7 passed |
| Emulator Device Owner + Lock Task | provisioned; `mLockTaskModeState=LOCKED` |
| Mutation matrix | **15 mutants / 15 killed / 0 survivors** |

## 6. Limits — stated plainly

- **Real-device validation NOT performed.** No clinic tablet exists. An emulator
  Keystore is software-backed, so hardware-backed/StrongBox storage is unproven.
- **Production signing UNRESOLVED.** Release builds declare no `signingConfig`.
  The only artifact is a **debug** APK for emulator and CI — never a release.
- The emulator app was **not** driven through enrolment against production; the
  server protocol is covered by 77 PHP tests using real EC keys instead.
- Cross-stack interop rests on format conformance (X.509 SPKI DER +
  DER ECDSA/SHA-256) plus independent real-key tests on both sides. A live
  device↔server round trip belongs to Phase 4, against a non-production server.
