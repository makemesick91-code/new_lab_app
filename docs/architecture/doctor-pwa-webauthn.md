# DOCTOR-PWA-WEBAUTHN-1 — WebAuthn device credentials for the doctor browser login

**Status:** implemented, deployed with the capability flag **OFF**.
**Not GO.** A GO tag requires a ceremony on the real pilot tablet — see
[Why automated tests are not sufficient](#why-automated-tests-are-not-sufficient).

---

## 1. What this adds, in one sentence

A browser running on an **approved clinic device** can now earn *the same*
server-verified device binding that, until this sprint, only the Android Clinic
App could earn — by producing a WebAuthn assertion instead of a keystore
signature.

Everything else about the doctor device lock is unchanged.

---

## 2. The threat model, stated plainly

The property being defended is:

> A doctor's clinical session may only exist on a physical device an
> administrator approved.

Three things are **not** evidence for that property, and none of them is used:

| Not evidence | Why |
| --- | --- |
| An installed PWA | Installation is a browser bookmark with a manifest. A normal tab on the same origin has identical capabilities. |
| `display-mode: standalone` | Client-asserted, trivially spoofed, and it describes a window, not a device. |
| A `localStorage` UUID, a cookie, a User-Agent, an IP, a screen fingerprint | All client-supplied. An attacker who can present them is exactly the attacker we are defending against. |

What **is** evidence: a signature over a server-issued, single-use challenge,
produced by a private key that cannot leave the authenticator, registered
against one device row, and approved by a human.

### The mistake this design refuses to make

`authenticatorAttachment === 'platform'` **does not mean device-bound.**

A modern Android tablet's platform authenticator routinely creates a *synced
passkey*: non-exportable from that tablet's hardware **and** present in the
signed-in Google account, and therefore on the doctor's personal phone, laptop,
and any device they later sign into.

If such a credential were accepted, "approved physical clinic device" would be a
label rather than a security property — the doctor's phone would silently hold a
working clinic credential.

The only signal that distinguishes the two is the **BE (Backup Eligible)** flag
in the authenticator data, fixed at creation and immutable for the credential's
life. `WEBAUTHN_REQUIRE_DEVICE_BOUND` defaults to **true**, and a `BE=1`
credential is refused at registration.

`BS` (Backup State) is deliberately *not* the gate: it is mutable and reports
`0` until the credential actually syncs, so judging on it would pass a syncable
credential that simply had not synced yet.

An authenticator that reports **no** backup flags is `unknown`, and `unknown` is
refused under the default policy. Silence is not a pass.

### Residual risk, stated rather than smoothed over

Enrolment requires `manage_doctor_devices` — the same permission that creates,
approves, disables and revokes devices, and the same human who can grant a
doctor an authorization.

That means an operator with this permission could enrol **their own** browser
against a device row they created, name it after a clinic tablet, and authorize a
doctor on it. Nothing in the ceremony detects this: the credential proves *a*
device is present, not *which room it is in*. Device name, model and branch are
administrative metadata and are not cryptographically attested.

This is **not new** and **not widened** by this sprint — the same operator can
already approve any Android device and any authorization. It is recorded here
because a device-trust document that did not say who can mint trust would be
describing a stronger property than the system has.

What contains it: every enrolment, approval, authorization and revocation is
audited with the actor; the three records are separately revocable; and a
credential is inert until a device is ACTIVE *and* a doctor authorization is
ACTIVE. What would remove it is a second human in the approval path — a
segregation-of-duties change to the device registry, which is a separate piece
of work and is not claimed here.

---

## 3. Domain model — three separately revocable records

```
mst_doctor_devices                 the physical tablet; branch; approve/disable/revoke
        │
        ├── trx_doctor_device_webauthn_credentials   WHICH HARDWARE is present
        │
        └── mst_doctor_device_authorizations         WHICH DOCTOR may use it
```

Nothing new was invented for this sprint. The device registry and the
authorization table already existed and already modelled a shared tablet; the
credential table is the only addition.

**The WebAuthn "user" is the DEVICE, not the doctor.** The credential answers
exactly one question — which hardware is present — so the entity it is issued to
is the device, and the `userHandle` carried back in every assertion is the
device uuid. Binding it to a doctor instead would make a shared clinic tablet
impossible to model: a second doctor would need a second credential, and
revoking a doctor would mean hunting credentials rather than revoking an
authorization.

### One tablet, several doctors

```
SUNU-TAB-01  (device: ACTIVE, one WebAuthn credential)
    ├── drg Karmila   authorization ACTIVE    → may log in
    ├── drg A         authorization REVOKED   → refused, tablet unaffected
    └── drg B         no authorization        → refused, tablet unaffected
```

---

## 4. The login flow

```
password
   │  valid?  no ─────────────────────────────────────────► DENY
   ▼ yes
DoctorAppLoginGate  ── enforcement off? ─────────────────► ordinary login (unchanged)
   │
   │ denied: no device session
   ▼
gate: may a device credential answer this?   no ────────► DENY (clear message)
   │ yes
   ▼
authenticated session TORN DOWN, pending marker written
   │
   ▼
WebAuthn assertion  ── challenge · origin · RP ID · UV · signature · counter
   │
   ▼
credential usable? · device ACTIVE? · authorization ACTIVE? · doctor matches?
   │  any no ──────────────────────────────────────────► DENY (one opaque message)
   ▼ all yes
Auth::login → session regenerate → SAME binding ticket redemption writes
   │
   ▼
EnsureDoctorDeviceSession re-verifies device + authorization on EVERY request
```

**There is no password-only fallback.** A failed or cancelled assertion denies.

**The password step never yields a usable session.** The authenticated session
is invalidated *before* the pending marker is written, so a doctor waiting at a
biometric prompt is logged out. The marker names an account whose password was
verified; it grants nothing on its own.

---

## 5. Two independent switches

| Flag | Default | Effect |
| --- | --- | --- |
| `doctor.trusted_device_enforcement` | off | Unchanged by this sprint. While off, no doctor's browser is ever denied, so no assertion is ever requested and this feature is unreachable. |
| `doctor.pwa_webauthn_device_login` | off | This sprint. While off, a denied doctor is denied exactly as before. |

Turning the second flag on **admits** rather than denies, and can only admit an
account that already holds a registered credential on an ACTIVE device with an
ACTIVE authorization. On a fleet with no credentials registered it changes
nothing at all.

Turning it off blocks new WebAuthn logins **and** invalidates existing
WebAuthn-authenticated sessions on their next protected request — including on a
device that also holds an Android Keystore key. It does not disable the Android
Keystore path for that same approved device. See §12; before
DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 the first half of that sentence was true and
the second half was not.

---

## 6. Replay protection

Challenges live in `trx_doctor_device_webauthn_challenges`:

- unique random value, 32 CSPRNG bytes
- short TTL (`WEBAUTHN_CHALLENGE_TTL_SECONDS`, default 120s)
- **burned in its own committed transaction BEFORE the signature is verified**
- bound to the ceremony (registration ≠ assertion)
- bound to the user (assertion) and the device (registration)
- bound to a per-browser ceremony token held in session data

### Why the burn happens before verification

Burning inside the verification transaction — so a failed attempt "doesn't
waste" a nonce — is a real vulnerability, and this codebase has already shipped
a fix for exactly it on the Android path. A rolled-back burn leaves the nonce
live, converting a one-shot challenge into an oracle an attacker can hammer
indefinitely. A failed ceremony costs the doctor one extra tap; that is the
correct trade.

### Why the session binding is a token, not the session id

A session id rotates for reasons that have nothing to do with an attack —
Laravel regenerates it on login, and this feature's own path regenerates twice.
Binding to it would mean a rotation between issuing and completing a challenge
makes login fail, and the symptom of that is a doctor who cannot reach their
patients. The binding is a random token in **session data**, which survives
`regenerate()` and is destroyed by `invalidate()`/`flush()` — exactly the
distinction wanted.

---

## 7. Relying party configuration

| Variable | Default | Notes |
| --- | --- | --- |
| `WEBAUTHN_RP_ID` | derived from `APP_URL` host | A hand-typed value that drifts from the served origin is the commonest way this breaks after a domain change. |
| `WEBAUTHN_ALLOWED_ORIGINS` | the `APP_URL` origin | Exact list; **subdomain wildcards are not enabled**. |
| `WEBAUTHN_USER_VERIFICATION` | `required` | `discouraged` is refused outright rather than silently accepted. |
| `WEBAUTHN_REQUIRE_DEVICE_BOUND` | `true` | See §2. |
| `WEBAUTHN_ALLOW_INSECURE_LOCALHOST` | `false` | Refused in production-like environments regardless of the value. |

The relying party is validated before any ceremony is issued; an unusable
configuration produces a diagnosable server error instead of a silent
client-side dead end.

**No secret is involved.** WebAuthn's guarantee is that the private key never
leaves the authenticator, so the relying party has nothing worth hiding — only
values that must be *right*.

---

## 8. Cryptography is not hand-rolled

`web-auth/webauthn-lib` ^5.3 (Spomky-Labs) performs challenge comparison,
client-data type checks, origin and RP-ID-hash verification, user-presence and
user-verification checks, backup-bit consistency, signature verification, the
counter check, the allowed-credential list and user-handle matching.

Attestation is requested as `none` and not verified. Attestation would answer
"what MAKE of hardware is this", which is not the question; the question is "is
this the SPECIFIC device an administrator approved", and that is answered by the
credential being registered against one device row and approved by a human.

---

## 9. What did NOT change

- The Android `/device-api/v1/*` endpoints, the enrolment protocol, ticket
  minting and ticket redemption.
- `DoctorAppLoginGate`'s enforcement semantics, scope narrowing, or the
  per-request middleware.
- The APK. **It is not retired**, and remains a valid transition and rollback
  path.
- The service worker. WebAuthn endpoints are uncached because the worker is
  deny-by-default and they are outside its allowlist — no denylist was added,
  because adding one would imply the allowlist alone was insufficient.
- Clinical data remains online-only and uncached.

### The one existing behaviour that did change

`DoctorAppLoginGate::deviceUsable()` previously required the **Android keystore
key** as the only proof of device identity. DOCTOR-PWA-WEBAUTHN-1 made it accept
either that or a usable WebAuthn credential, gated on this sprint's flag.

That widened nothing for the Android path: a WebAuthn-only device has no
`public_key` and no key fingerprint, so `DoctorAppLoginService` cannot find it by
fingerprint and could not verify a keystore proof against it if it did.

It did, however, make the *reverse* substitution possible on a device holding
both proofs — which is the defect §12 closes. `deviceUsable()` no longer exists.

---

## 10. Why automated tests are not sufficient

The suite uses a **real software authenticator** — a genuine EC P-256 key pair,
real authenticator data, real client data, signed with OpenSSL and verified by
the library with no knowledge that it came from a test. It proves forged
signatures, replayed challenges, foreign origins, revoked credentials, revoked
devices and revoked authorizations are all refused.

It cannot prove what only hardware can:

- that the pilot tablet's platform authenticator produces a **BE=0** credential
  rather than a synced passkey;
- that user verification is actually enforced by that device;
- that the ceremony completes in the tablet's browser at all.

Those are the hard gates for a GO tag, and they require the physical device.

---

## 11. Operational runbook

**Before anybody stands at a tablet**

```
php artisan webauthn:readiness           # human-readable
php artisan webauthn:readiness --json
php artisan webauthn:readiness --strict  # non-zero only if a ceremony is impossible
```

A relying party misconfiguration does not fail on the server — it fails inside
the browser, on the tablet, with no message, because the authenticator simply
refuses an origin whose registrable domain does not match the id the credential
was created under. That is undiagnosable from the outside, so the configuration
has to be checkable from the inside first.

The command is read-only and safe on production. It reports the resolved RP id
and origins, whether a ceremony is possible, the user-verification and
device-binding policy, **both** switches, and COUNTS of active devices and
usable credentials — never a credential id, a public key, a device name or a
doctor's name.

| Verdict | Meaning |
| --- | --- |
| `RELYING_PARTY_UNUSABLE` | a ceremony cannot run; fix before enrolling |
| `CONFIGURED_NO_CREDENTIALS` | expected before the first tablet is enrolled |
| `READY_NOT_ARMED` | credentials exist, the flag is off |
| `ARMED_WITHOUT_CREDENTIALS` | the flag is on and nothing could use it |
| `ARMED` | live |

`--strict` fails only on an unusable relying party. "No credentials yet" is a
state, not a fault — exiting non-zero on it would train an operator to ignore
the command.

**Enrolling a tablet**

1. On the tablet, open Master Data → Device Dokter → the device → *Kredensial
   Perangkat (WebAuthn)*.
2. Tap **Daftarkan Browser Ini** and complete the biometric prompt.
3. A `Dapat disinkronkan` verdict means the platform produced a syncable
   passkey; it is refused, and the device is not enrolled.
4. Registration does **not** approve the device. Approval stays in Approval →
   Device Dokter.

**Rollback**

```
FEATURE_DOCTOR_PWA_WEBAUTHN_DEVICE_LOGIN=false
php artisan config:clear
```

Browser device logins stop immediately, open browser sessions end on their next
request, and the Android path is unaffected. No credential, device or
authorization is altered, so re-enabling needs no re-enrolment.

**Revoking**

Three independent levers, each fail-closed on the next request:

| Revoke | Effect |
| --- | --- |
| the credential | that browser only |
| the device | every doctor on that tablet |
| the authorization | that doctor on that tablet only |

---

## 12. Session proof binding — DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1

### Device identity proof is not session authentication proof

Two questions look alike and are not:

| Question | Answered by |
| --- | --- |
| Does this device hold **some** acceptable proof? | provisioning, enrolment, approval |
| Is the **exact proof that established THIS session** still valid? | every protected request |

`deviceIdentityProven()` answered the first and was used for the second. On a
device holding only one proof the two answers coincide, which is why the
original suite — every fixture a `DoctorDevice::factory()` device with
`identity_state = unverified` and `public_key = null` — could pass while the
property it claimed to prove did not hold.

### The device the pilot actually runs on

`PHASE4A_PILOT_TABLET_02` is **ACTIVE**, `cryptographically_verified`, and
carries an Android Keystore public key. It is a **dual-proof device**. On it, the
old check short-circuited on the Android branch before the WebAuthn flag or the
credential was ever consulted, so a browser session established by WebAuthn:

- **survived the kill switch** — the flag's documented rollback did not end it;
- **survived revocation of the very credential that authenticated it** — the
  Android key kept it alive.

New browser logins were never affected: `completeLogin()` verifies a real
assertion. The defect was **existing session containment**, and it is the reason
this sprint exists.

### What the session now records

`DoctorDeviceSessionService::bind()` writes, in addition to device,
authorization and doctor:

| Session key | Value |
| --- | --- |
| `doctor_device.proof_type` | `android_keystore` or `webauthn` |
| `doctor_device.webauthn_credential_id` | the credential row id, or `null` |

The proof is recorded **at authentication success, by the path that ran**. It is
never inferred afterwards from what the device happens to carry — that inference
is the defect. On one dual-proof tablet, an Android login writes
`android_keystore` and a browser login writes `webauthn`, and the WebAuthn
credential's existence does not change the first nor the keystore key the second.

No key material, signature, challenge or client data goes into the session. A
**reference** is all a re-check needs, and the row id is already what the audit
trail records.

### What is re-verified on every protected request

`DoctorAppLoginGate::deviceProofDenyReason()` re-asserts the recorded proof
**as itself**. `deviceUsable()` and `deviceIdentityProven()` are gone: a method
that can substitute one proof for another is a method that will be called by
someone who does not know it can.

| Bound proof | Device ACTIVE | Extra requirement |
| --- | --- | --- |
| `android_keystore` | yes | `identity_state = cryptographically_verified` **and** `public_key` present |
| `webauthn` | yes | flag ON **and** that exact credential un-revoked, registered to **this** device, still satisfying the device-binding policy |

Scoping the credential lookup to the bound device makes three failures land in
one place: revoked, names nothing, and belongs to another tablet. A perfectly
valid credential from a different approved device cannot keep this session alive.

Re-checking the device-binding verdict per request — not only at registration —
means a **tightened** policy ends sessions admitted under the looser one, rather
than merely refusing to issue new credentials.

### Resulting contract

| Event | WebAuthn session | Android session |
| --- | --- | --- |
| `FEATURE_DOCTOR_PWA_WEBAUTHN_DEVICE_LOGIN=false` | denied next protected request | unchanged |
| WebAuthn credential revoked | denied next protected request | unchanged |
| Device revoked / disabled | denied | denied |
| Authorization revoked | denied | denied |

All four rows hold **on the dual-proof device**, which is the only place the
first two were ever in doubt.

### Sessions with no recorded proof

A binding written before this build carries no `proof_type`. It is **denied**,
and the doctor re-authenticates.

The alternative — treating an absent proof as Android because the device happens
to hold a keystore key — is the same substitution stated one level up, and it
would grandfather exactly the sessions this sprint exists to contain. An unknown
proof is not read generously. The same denial covers a `proof_type` this build
does not recognise, and a `webauthn` binding with no credential reference: in
both cases there is nothing that can be re-checked, and a proof that cannot be
re-checked is not a proof.

The operational cost is bounded and recoverable: it can only reach a doctor
**inside the enforcement scope** who holds an open bound session at deploy time,
and re-authentication is the path their device already uses.

### Denial and audit

Three new stable codes — `session_proof_unknown`, `webauthn_login_disabled`,
`webauthn_credential_not_usable` — travel to `sys_audit_logs` through the
existing `DOCTOR_SESSION_DEVICE_INVALIDATED` entry that
`DoctorDeviceSessionService::invalidate()` already writes. No new audit
subsystem, and no per-request success logging.

All three render **one** message to the doctor. Telling them which proof was
withdrawn tells anyone holding the tablet how the estate is configured, and does
not help them sign in again.

### What this sprint did not touch

Enrolment, the ceremony, the challenge protocol, the relying party, the device
registry, the authorization model, branch scope, the service worker, the Android
`/device-api/v1/*` endpoints, the APK, and both feature flags' default values.
No migration: the binding is session state.

## 13. The verify button had no handler — BUGFIX-DOCTOR-PWA-WEBAUTHN-VERIFY-BUTTON-1

The device step shipped a button that looked live and did nothing, in the
installed PWA and in plain Chrome alike.

### Mechanism

`resources/views/auth/doctor-device-webauthn.blade.php` puts its wiring in
`@push('scripts')`. It renders through `<x-guest-layout>` →
`App\View\Components\GuestLayout` → `resources/views/layouts/guest.blade.php`,
and that layout had **no `@stack('scripts')`**. Blade discards a push with no
matching stack silently: no error, no warning, no console message.

So the server rendered a form whose seven credential inputs were empty and a
`type="submit"` button with no submit listener. Clicking it posted the empty
form, `DoctorDeviceWebAuthnAssertionRequest` rejected it, and the page bounced
back — which reads, to the person holding the tablet, as a dead button.

The bundled module was delivered the whole time. `layouts/guest.blade.php`
carries `@vite([... 'resources/js/app.js'])`, and `app.js` exposes
`window.doctorDeviceWebAuthn`. But that module only *exports* functions; it
binds no listener. Delivering it was never sufficient.

### Why both modes failed identically

The script was dropped **server-side, at render time**. No client of any kind
ever received it, so the defect could not be a service-worker or installation
issue. Reproduction in both modes was the clue that it was a render bug.

### Why registration worked

Registration renders through `settings-shell` → `x-app-layout` →
`layouts/app.blade.php`, which does render `@stack('scripts')`. Identical
wiring, different layout — the only difference that mattered.

### Evidence the ceremony never started

`trx_doctor_device_webauthn_challenges` held only `ceremony = registration`
rows. No assertion challenge was ever created, proving the failure occurred in
the client before the options endpoint was called.

### Fix

One line: `@stack('scripts')` in `layouts/guest.blade.php`, mirroring the app
layout. No WebAuthn semantics, RP configuration, device-binding policy,
authorization rule or session-proof rule was touched.

### Test gap that allowed it

The suite proved the server ceremony with real signatures, and `tests/js`
pinned the module's encoders. Nothing asserted that the wiring reaches the
browser. Regression tests now assert the **rendered response** contains the
handler, and pin the guest layout's script-stack contract for every future
guest page. A test that grepped the Blade source would have passed throughout.

## 14. The kill switch, proven as a rollback — DOCTOR-PWA-WEBAUTHN-KILL-SWITCH-1

Section 12 built the mechanism: a session records the proof that authenticated
it, and that proof is re-verified as itself on every protected request. This
section states what the mechanism is *for* — the operational promise an engineer
is relying on when they turn `FEATURE_DOCTOR_PWA_WEBAUTHN_DEVICE_LOGIN` off
while doctors are working — and pins the half of it that was never asserted.

### What a rollback has to mean

Turning the switch off has to end WebAuthn browser sessions. That much was
already proven. But "the session ended" is not by itself a rollback, and three
further properties decide whether an operator can safely touch the switch at
all:

| Property | Why it is not optional |
| --- | --- |
| A WebAuthn session survives a protected request while the switch is **on** | Without this control, "it ended after I flipped the switch" is a claim about a coincidence. A session that could not survive *any* second request would produce identical evidence. |
| The denial is attributable to **this** switch | `session_proof_unknown` and `device_not_usable` also log a doctor out. Either would be a different defect wearing the same symptom, and an operator reading only "logged out" could not tell them apart. |
| The switch destroys no identity | A rollback that quietly revokes the tablet, the authorization or the credential is one-way. Re-arming would then require the whole physical enrolment ceremony again — which is not a rollback, it is an outage with extra steps. |

### The durable rules

- **KS-1.** A session authenticated with `proof_type = webauthn` is revalidated
  **as WebAuthn** on every protected request. Never as "the device holds some
  proof".
- **KS-2.** When `doctor.pwa_webauthn_device_login` becomes OFF, an existing
  WebAuthn session is denied on its **next** protected request. Not at its next
  login, not when the doctor happens to log out.
- **KS-3.** A cryptographically verified Android Keystore key on the same device
  **must not** rescue a WebAuthn session or let it be reinterpreted as Android.
  The pilot tablet holds both proofs; this is the case that matters.
- **KS-4.** An Android-Keystore session **must not** be invalidated merely
  because the WebAuthn admission switch is off. The two proofs fail
  independently, in both directions.
- **KS-5.** Switch rollback revokes **nothing** — not the device, not the
  authorization, not the credential. It withdraws an admission. The same
  credential is usable again the moment the switch goes back on, with no new
  ceremony.
- **KS-6.** Kill-switch testing keeps `android_release.enforcement.scope.
  global_permitted` false and the scope on the pilot. Fleet-wide denial is a
  clinical-scale action and is never a test posture.
- **KS-7.** Live revocation of a device, an authorization or a credential is
  **out of scope** for kill-switch testing. Credential-revoke containment is its
  own sprint; device revocation is terminal.
- **KS-8.** Production rollback is followed by explicit readiness and scope
  verification — `webauthn:readiness` and `android:phase4a-pilot-scope` — not by
  assuming the file edit took effect.
- **KS-9.** Clinical continuity after rollback is verified with a **real**
  ordinary doctor login. A green readiness report is not evidence that a doctor
  can see patients.

### The safe operational sequence

Arming and rolling back are not symmetric, because in between there is a live
clinical session. The order below is the one the evidence in this sprint was
gathered with, and departing from it invalidates the containment claim.

1. Prove the safe baseline first: both switches off, `VERDICT=READY_NOT_ARMED`,
   `SCOPE_VERDICT=GO`, `BROWSER_DENIED_DOCTOR_COUNT=0`.
2. Record the environment file's checksum, owner, group and mode. Only the two
   flag values are read; the file is never dumped.
3. Arm **both** switches, then `config:clear && config:cache` **as the runtime
   user**. A cache rebuilt as root leaves a file the web process cannot read,
   and the symptom is a 500 on the next request, not a permission error.
4. Re-run both readiness commands and require `VERDICT=ARMED`,
   `GLOBAL_ENFORCEMENT_ACTIVE=false`, and the covered set exactly the pilot.
   If any hard gate differs, stop — do not ask the doctor to log in.
5. Establish the real WebAuthn session on the physical tablet.
6. Turn **only** the WebAuthn switch off, keeping enforcement armed. Rebuild the
   config cache. **Do not log the session out** — the open session is the
   subject of the test, and a manual logout destroys the evidence.
7. Make the next protected request from that same session and require denial.
8. Verify the Android control session is still allowed, and that the device,
   authorization and credential are all still active.
9. Restore **both** switches off, rebuild the cache, re-verify readiness and
   scope, and finish with a real ordinary doctor login (KS-9).

### Why the test suite alone cannot close this

`DoctorPwaWebAuthnProofBindingTest` exercises the real HTTP protected-request
path with real signatures on a dual-proof fixture, and the kill-switch cases
were confirmed non-vacuous by mutation: removing the flag check from
`deviceProofDenyReason()` fails them. That is strong evidence about the code.

It is not evidence about the deployment. The switch is read through a **cached
config file** written on the host, by a **runtime user**, for a session held in
a **real browser** on a physical tablet. Every one of those is outside the
suite. The physical device gate exists for the same reason section 10 exists,
and for the same reason section 13 had to be found in production.
