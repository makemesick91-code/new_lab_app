# BUGFIX-DOCTOR-PWA-WEBAUTHN-VERIFY-DEVICE-NO-FEEDBACK-2

Child of `REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1`, opened by a defect the
Stage 3 rehearsal found on real hardware.

**Retires nothing. Moves no flag. Widens no scope. Starts no Stage 4.**

---

## 1. The symptom, and how long it hid

On the SPN4 pilot tablet (`PHASE4A_PILOT_TABLET_02`, device id 3) the **Verify
Device** control produced **no visible reaction** — five attempts across three
client configurations, on a tablet holding a valid, device-bound credential.

Every fact about the failure had to be recovered from **nginx and Postgres
afterwards**, because the page reported nothing at all.

It hid because **the Android and browser paths are completely separate**:

| | Android APK | Browser / PWA |
|---|---|---|
| Proof | Keystore signature | WebAuthn assertion |
| Service | `DoctorAppLoginService` | `DoctorDeviceWebAuthnLoginService` |
| WebAuthn refs in that service | 0 | 77 |
| Keystore refs in that service | 35 | 0 |
| Client JS | none (native) | `resources/js/doctor-device-webauthn.js` |

The pilot doctors were logging in through **Android**
(`DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS` 2026-09-17) while the WebAuthn leg had
not completed an assertion since **2026-09-09**. "The pilot is live" was never
evidence the PWA worked.

## 2. Root cause — evidenced, not inferred

```
POST /doctor-device-webauthn/options   200, 633 bytes      ← click reaches server
POST /doctor-device-webauthn           NEVER SENT          ← no assertion produced
Referer  https://daengtisia.online/... ← origin correct, RP-ID ruled out
Laravel error logs                      none
```

`resources/js/doctor-device-webauthn.js` awaited
`navigator.credentials.get()` with **no abort and no timeout**. A ceremony that
never settles leaves the `await` pending forever: the `catch` never runs, no
message renders, and the button stays disabled. From the clinician's side the
control is simply dead.

A second defect sat beside it: every failure that *did* reject collapsed into
one sentence, so nobody could tell a missing credential from an untrusted
origin from an expired session.

### Hypotheses eliminated by evidence, in order

1. **Handler not attached** — refuted: challenges were minted on every click.
2. **Missing `@stack('scripts')`** (the historical defect) — refuted: the stack
   resolves and the bundle contains the module.
3. **RP-ID / origin mismatch** — refuted: `Referer` matches `RELYING_PARTY_ID`.
4. **Chrome "Request desktop site"** — genuinely found and corrected (the UA
   changed desktop → Android in nginx), but the symptom persisted. Not the cause.

## 3. The fix

**`resources/js/doctor-device-webauthn.js`**

- `AbortController` + `CEREMONY_TIMEOUT_MS` (60s) on both `assert()` and
  `register()`, with the signal actually passed to `credentials.get/create`.
  The WebAuthn `timeout` member is only a hint to the browser; the abort is
  what ends the wait.
- Typed reason codes: `timeout`, `no_credential_or_denied`, `ceremony_cancelled`,
  `origin_not_trusted`, `device_state_invalid`, `unsupported`, `session_expired`,
  `options_rejected`, `network_unavailable`, `unexpected`.
- There is now **no exit path that resolves to nothing**.

**`resources/views/auth/doctor-device-webauthn.blade.php`**

- Each reason maps to distinct, actionable Indonesian wording.
- The reason **code** is shown (`(kode: timeout)`) so an operator can quote it
  in an incident report without describing the device estate.
- The button is always re-enabled — a disabled control with no message is the
  exact state that read as "dead".

### What was deliberately NOT done

`NotAllowedError` is **not** reported as "no credential registered". The spec
returns it both for a missing credential and for a dismissed prompt, precisely
so a page cannot probe which credentials a device holds. Claiming to
distinguish them would be inventing a distinction the browser refuses to make,
so the wording covers both — and a test pins that.

**No server-side security changed.** The WebAuthn requirement, device binding,
user verification, `DoctorDeviceAuthorization`, single-session and pilot
enforcement are untouched. A ceremony that fails still fails; it now says why.

## 4. Tests

`tests/js/doctor-device-webauthn.test.mjs` — 7 added, 47 total pass.

The regression test was **mutation-checked**: removing `signal` from
`credentials.get` makes it fail (`not ok 8`), and restoring it returns 47/47.
It is not a vacuous assertion.

One test pins **producer/consumer drift**: every reason the module can emit must
have wording in the view. A new code added without wording would surface to a
clinician as a bare failure — this defect, reintroduced.

PHP: `DoctorDevice` + `DoctorAccess` + `Auth` + `Ui` — **0 failures, 9886
assertions**.

## 5. Durable rules this establishes

1. **A silent WebAuthn failure is a defect.** Any ceremony a clinician can
   start must end in either success or a visible, deterministic message.
2. **Server denial and UX feedback are separate requirements.** Denying
   correctly while showing nothing is still a defect.
3. **Every browser-side ceremony is bounded.** An unbounded `await` on an
   external authenticator has no upper limit on how long the UI can lie about
   being busy.
4. **Failure types stay distinct** where the browser distinguishes them, and
   are honestly merged where it refuses to.
5. **Android and browser doctor auth are independent.** One working says
   nothing about the other; they share only the gate and the session binding.
6. **Application timestamps are UTC.** Clinic-local time must be labelled
   **WITA (UTC+8)**. An unlabelled timestamp that could be read as local is a
   reporting defect.
7. **Authenticated device pages keep the canonical shell.** Styles come from
   Vite; there is no `@stack('styles')` requirement to satisfy.

## 6. What this does NOT change

```
device_loss_runbook_rehearsed      PASS    (Stage 3, unaffected)
spare_device_available_per_branch  FAIL    (blocks activation)
ACTIVATION_PREREQUISITES           FAIL
authorizes_activation              false
STAGE_4_AUTHORIZED                 NO
ANDROID_DOCTOR_AUTH                UNCHANGED
```

**Android is currently the only working doctor authentication path.** Stage 5
retires it. Doing so before a WebAuthn assertion is proven on real hardware
would lock out every enforced doctor — which makes "retirement last" a hard
dependency rather than a sequencing preference, and gives Stage 5 a new
prerequisite.

## 7. Still open after this sprint

- **Defect B — why the assertion rejects on a tablet holding a valid passkey.**
  **UNVERIFIED.** This fix is the instrument that will name it: the next attempt
  reports a reason code instead of nothing.
- **Readiness truth gap.** `webauthn:readiness` reports
  `CREDENTIALS_USABLE / VERDICT=ARMED` from database rows alone. It cannot see
  whether the authenticator still holds the key, so it reported ARMED while the
  leg had been dead for ten days. Worth its own sprint.
- **Unexplained UA anomaly.** Page loads carried an Android UA while the
  options POST carried a desktop one, same IP, ~1s apart, before desktop-site
  mode was corrected. Recorded as **UNVERIFIED**.
