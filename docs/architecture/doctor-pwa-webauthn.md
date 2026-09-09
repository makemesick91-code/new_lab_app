# DOCTOR-PWA-WEBAUTHN-1 — WebAuthn device credentials for the doctor browser login

**Status:** implemented, deployed, and LIVE for a bounded cohort of three doctors.
The parent programme closed at §16 and the cohort went live at §17; this header
said **Not GO** until DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 corrected it, having
been written before either happened and never updated since.

**Fleet-wide enforcement remains OFF**, and §18 measures how far away it is. A GO
on readiness is not a GO on activation — see
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

---

## 15. Credential revocation, proven as containment — DOCTOR-PWA-WEBAUTHN-CREDENTIAL-REVOKE-CONTAINMENT-1

Section 14 proved the *switch* is a rollback: turning WebAuthn off ends an open
WebAuthn session. That is an estate-wide control. This section is about the
narrow one — withdrawing **one credential** while everything else keeps running,
which is what an operator actually reaches for when a single browser profile is
suspect and the clinic still has to see patients.

The two are not the same control, and proving one does not prove the other. The
switch is checked before the credential is ever looked up, so a switch test can
pass on an implementation whose credential check is broken.

### What was audited before anything was changed

No runtime defect was found, and none was manufactured. `PROOF-BINDING-1`
already:

- binds a WebAuthn session to `DoctorSessionProof::webAuthn($credential->id)` —
  a reference to **one** credential row, not to "this device has a credential";
- re-verifies that reference on every protected request through
  `EnsureDoctorDeviceSession` → `denySessionReason()` → `deviceProofDenyReason()`;
- resolves it through `usableForDevice()`, which filters `whereNull('revoked_at')`,
  so a revoked credential is absent and the request lands on
  `DENY_WEBAUTHN_CREDENTIAL_NOT_USABLE`;
- returns from the Android branch **before** any WebAuthn state is read, so a
  credential revocation cannot reach an `android_keystore` session.

What was missing was coverage of the **blast radius** — the properties the live
ceremony depends on and which nothing pinned. So this sprint is tests and rules,
and the runtime is byte-identical to the base authority.

### The rules

**CR-1.** A WebAuthn session is bound to the **exact credential** that
authenticated it, by row reference, recorded at authentication and never
inferred afterwards from what the device holds.

**CR-2.** Revoking that credential invalidates the session on its **next
protected request**. No logout, no session sweep, no waiting for expiry. A
control that only takes effect at logout is not containment.

**CR-3.** Another usable credential **on the same device** does not rescue a
session bound to a revoked one. The lookup is `firstWhere('id', …)`, not
`first()`. The session was earned by one ceremony and does not migrate.

**CR-4.** An Android Keystore key on the same dual-proof device does not rescue
a WebAuthn session whose credential was revoked. This is the section 12 rule,
restated because credential revocation is the second way to reach it.

**CR-5.** Revoking a WebAuthn credential does **not** invalidate a genuine
`android_keystore` session — including one established *before* the revocation
and never re-authenticated.

**CR-6.** Credential revocation does not mutate the device row, and creates no
replacement device.

**CR-7.** Credential revocation does not mutate the doctor-device authorization,
and creates no replacement authorization.

**CR-8.** Revocation is **terminal for that credential row**. `revoke()` returns
early if already revoked, and there is no un-revoke path anywhere. Recovery is a
fresh registration.

**CR-9.** A revoked credential does not block re-enrolment on the same device,
and is not offered in `excludeCredentials` — otherwise the one tablet whose
credential you just withdrew would become the one tablet that cannot be
recovered, and the containment action would have caused the outage.

**CR-10.** A replacement credential must independently satisfy the same policy
as the original: user-verified, `backup_eligible = false`, device-bound,
un-revoked. A replacement admitted on looser terms is a downgrade wearing the
name of a recovery.

**CR-11.** Registering a replacement never clears `revoked_at`, never reuses the
old row, and never reinterprets it as active. The revoked row is the record of a
security decision; erasing it erases the reason.

**CR-12.** Old sessions do not migrate onto a replacement credential, including
one enrolled while the denied session is still open.

**CR-13.** Device revoke is **not** an acceptable substitute for credential
revoke. Device revoke is terminal and a replacement device means an on-site
re-enrolment ceremony. Reaching for the wider control because the narrow one is
untested is how a containment action becomes a clinical outage.

**CR-14.** Global doctor enforcement stays `false` throughout. Containment is
proven inside the pilot scope or it is not proven.

**CR-15.** After any credential ceremony, production returns to an explicitly
**verified** safe rollout state — both switches off, readiness re-read — and
clinical continuity is proven by a real ordinary doctor login, not asserted.

### The blast-radius tests, and why the existing suite did not cover them

`DoctorPwaWebAuthnCredentialRevocationTest` revokes through
`settings.doctor-devices.webauthn.revoke` — the route an operator posts to —
rather than by writing `revoked_at`, because between the route and the column
sit a policy check, a device-ownership check, a mandatory reason, a transaction
and an audit write.

Two mutations show the tests are load-bearing rather than merely green:

- dropping `whereNull('revoked_at')` from `usableForDevice()` kills **6**;
- replacing `firstWhere('id', $credentialId)` with `first()` kills **2** — and
  the pre-existing suites catch that one only incidentally, through a test about
  a credential reference naming nothing. Nothing there covered *a second valid
  credential on the same device*, which is exactly the state recovery creates.

### The harness trap this sprint hit

The test client has **one** session; the clinic has **two browsers**. A helper
that acted as the operator and then flushed left the doctor logged out — so the
suite "proved" containment it had caused itself, reporting a denial reason of
`no_device_session` instead of the credential, and a dead Android session.

Both were the harness. The helpers now snapshot the doctor's session, act as the
operator, restore it verbatim and forget the resolved user. A mutation run is
what exposed the remaining vacuous case, which is the argument for running one.

### The safe operational sequence

As section 14, with the revocation substituted for the switch flip, and two
additions that are not optional:

1. Establish the real Android Clinic App session **before** revoking, and prove
   it usable with one protected request. A control session created afterwards
   proves only that login still works — not that revocation spared it.
2. Get explicit operator approval immediately before revoking. Revocation is
   irreversible for that row (CR-8), and the approval must be given knowing that.

Then: revoke, deny the open WebAuthn session, confirm the pre-existing Android
session survives, roll both switches off, enrol the replacement, re-arm, prove
the recovery assertion binds to the **new** credential id, roll both switches off
again, and finish with an ordinary doctor login.

---

## 16. Parent closure — DOCTOR-PWA-WEBAUTHN-PARENT-CLOSURE-1

The parent programme `DOCTOR-PWA-WEBAUTHN-1` had six GO-tagged children and no
GO of its own, because the thing it claims — that a named doctor can be held to
a hardware-bound browser login without touching anybody else — had never been
demonstrated end to end in the configuration production runs.

### The hole the closure found

Every successful-admission test in the WebAuthn suites arms the feature
**fleet-wide**. `waFlags()`, `pbFlags()` and `crvFlags()` each set
`doctor_device_enforcement.scope.mode = 'unscoped'` **and**
`android_release.enforcement.scope.global_permitted = true`, because those
suites are about credential mechanics and want the scope out of the way.

The two suites that do set `MODE_PILOT` sign the doctor in *first* under
unscoped mode and only then narrow the scope — so they prove **containment**,
never **admission**. Before this closure, no test completed a WebAuthn login
while the enforcement scope actually named the pilot doctor.

`DoctorPwaWebAuthnParentClosureTest` closes that: it arms `MODE_PILOT` against
one user, never touches `global_permitted`, and drives a real assertion through
to a bound session — asserting proof type, credential id, device, doctor and
authorization on the way.

### The defect mutation found that reading did not

A read-only audit of the admission chain reported no defect, and it was right
about the mechanism: the device-binding verdict *is* re-checked on every
protected request. What neither the audit nor the suite noticed is that
**nothing was holding that check**.

Deleting `WebAuthnDeviceBinding::isAcceptable($credential->device_bound_verdict)`
from `DoctorAppLoginGate::deviceProofDenyReason()` left **all 80 WebAuthn tests
green**. Registration refusing a syncable credential had been mistaken for
coverage of the invariant, but registration cannot reach the case the check
exists for: a credential stored while the policy was loose must stop working
when the policy is tightened, not merely stop being issued.

Two tests now hold it — `backup_eligible` and `unknown` both end the session on
the next protected request, while the device, the authorization and
`revoked_at` stay exactly as they were. It is a proof failure, not a revocation.

### Mutation results

| Mutation | Outcome |
|---|---|
| `MODE_PILOT => true` (scope covers everyone) | killed — non-pilot isolation |
| `firstWhere('id', $credentialId)` → `first()` | killed — 3 exact-binding tests |
| WebAuthn flag check removed | killed — 6 tests |
| device-binding revalidation removed | **survived**, until this sprint added coverage |

### What the parent GO does and does not authorise

It authorises **the pilot**: one named doctor, one approved device, one active
authorization, one device-bound credential, at one branch. Global doctor
enforcement stays `false` — and cannot be reached from the environment at all,
because `global_permitted` is a hard-coded `false` in `config/android_release.php`
that no host value overrides.

It does not authorise a second doctor, a second device, another branch, or
Phase 5. Expansion is a separate decision with its own stabilisation sprint.

### Why a green suite is still not a GO

The suite drives a software authenticator. It cannot produce a platform
authenticator's attachment, a real user-verification prompt, or the BE/BS flags
as actual hardware reports them — and it cannot distinguish a browser tab from
an installed PWA client context. The parent GO therefore requires the physical
ceremony on the approved tablet, in Chrome **and** in the installed PWA, both
binding the current active credential. Server gates are prerequisites, never
substitutes.

### The ceremony, as it actually ran

Production `41f87199`, armed by the two flags only (env delta proven to be
exactly two lines, keyset hash unchanged, `root:daengtisiams:640` preserved).
Gate before anyone touched the tablet: `VERDICT=ARMED`, `SCOPE_VERDICT=GO`,
covered users `[18]`, denied 1 / allowed 14, `GLOBAL_ENFORCEMENT_ACTIVE=false`.

An audit watermark was taken first (`id=622`) so the ceremony's events could be
isolated from history rather than inferred. Everything above it:

| id | event | credential | actor | at |
|---|---|---|---|---|
| 623 | `DOCTOR_SESSION_DEVICE_INVALIDATED` | — | 18 | 01:19:59 |
| 624 | `DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS` | **2** | 18 | 01:20:04 |
| 625 | `DOCTOR_SESSION_DEVICE_INVALIDATED` | — | 18 | 01:26:20 |
| 626 | `DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS` | **2** | 18 | 01:26:24 |

Two independent logins, each preceded by the documented session teardown, so
neither reused the other's session. Signature counter `1 → 3`: two increments
for two assertions, which also confirms the library's clone check is live
against a real hardware counter. Credential 1 stayed revoked with its counter
frozen at 6. Row counts before and after are identical — 3 devices, 3
authorizations, 2 credentials — so nothing was created to make the ceremony pass.

**What the server cannot prove, and is not claimed.** The audit trail does not
record client context, and by design it cannot: Chrome and the installed PWA use
the same origin-bound credential, which is the property PC-13 asserts. The server
proves two real assertions bound to the approved credential; *which* of them was
the PWA rests on the operator's attestation. Recording it that way is the point —
a closure that inflated operator testimony into server evidence would be the
exact failure this programme exists to avoid.

**One telemetry gap, not a security one.** `mst_doctor_device_authorizations.
last_authorized_login_at` did not move (still 2026-09-08 15:25:16, an Android
login). The WebAuthn path does not stamp it, so that column reflects Android
logins only. The authorization *is* verified active on every admission and every
protected request — this is an observability inconsistency to fix in
stabilisation, not a gate.

## 17. The enforcement cohort — DOCTOR-PWA-MULTI-DOCTOR-PILOT-1

Sprint record: `docs/sprints/doctor-pwa-multi-doctor-pilot-1.md`.
Durable rules: `.cursor/rules/151-doctor-pilot-enforcement-cohort.mdc` (MD-R1…R10).

### The statement that was two statements

Until this sprint `AndroidDoctorEnforcementScope` held one `?int` and compared
it with `===`. "A pilot" and "one doctor" were therefore the same statement, and
the only way to reach a second doctor was `unscoped` — which is Phase 5, and is
refused by a source-controlled `global_permitted => false`. Section 15 of the
programme had listed `pilot_branch_or_device` as a stage since Phase 3.5 with
nothing implementing it.

The scope is now a list: `pilotDoctorUserIds()`, sorted, deduplicated,
`list<int>`. The mode name did not change because the guarantee did not change —
**covered means named**. No wildcard, no role, no branch, no "all doctors".

### The failure direction is unchanged, and now has two more ways in

This class has always narrowed rather than denied: an unusable configuration
covers NOBODY, because a mistyped variable that locked every doctor out of every
branch would be a clinical incident, while one resolving to "enforce nobody"
leaves production as it was and is caught loudly by the readiness gate. A cohort
adds two more routes to that same answer:

- **One unreadable entry voids the entire list.** Dropping it would be friendlier
  and wrong: `18,19,2O` with a letter O would resolve to a working two-doctor
  pilot with the third doctor silently unenforced behind a list that still reads
  correctly. Voiding covers nobody and trips `armed_but_covers_nobody`.
- **A cohort larger than the reviewed ceiling covers nobody.**

### Why the ceiling is not beside the cohort

`android_release.enforcement.scope.pilot_cohort_maximum` is source-controlled,
next to `global_permitted`, and deliberately not in the runtime file an operator
edits. An explicit allowlist is the only expansion shape permitted — but a list
can be written out until it names every doctor in the fleet, and at that point
"pilot" has become fleet-wide denial while every guard watching for fleet-wide
denial still reads false. That is the one way this boundary can be crossed
without anybody deciding to cross it. A bound an operator can raise is not a
bound, so raising it costs a review. Missing or unreadable resolves to 1.

### GO stopped meaning "one doctor" and started meaning something stronger

`Phase4aPilotScopeResolutionReport` previously failed any scope covering more
than one doctor and passed on `count($coveredIds) === 1`. It now compares the
covered set against the declared cohort as sets. That is strictly stronger: a
cardinality check passes when the count is right and the members are wrong,
which is exactly the `users.id` / `mst_doctors.id` adjacency this report was
written to catch. Findings gained `pilot_scope_covers_more_doctors_than_reviewed_maximum`
and `declared_pilot_cohort_does_not_resolve_to_covered_doctors`.

### What a cohort still is not

Membership decides only that enforcement APPLIES. Admission still needs an
active device, an active authorization and a usable device-bound credential,
re-asserted on every protected request by services this class never calls.
Expanding the cohort creates none of them; contracting it revokes none of them.
Readiness comes before enforcement, always — naming a doctor who has no device
locks them out of their own patients.

### Shipped inert

Production sets only `ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID=18`. The singular
key and the cohort key are unioned, so deploying the mechanism resolves to
exactly `[18]` — the same doctor, the same denial, the same fourteen browsers.
A rename would have made the deploy itself change who is enforced.

### The pilot went live

The mechanism shipped inert, and was then used. Cohort `[9, 15, 18]`: drg Karmila
on device 3 at SPN4, drg Nisa on device 5 at LDK2, drg Fiitri on device 6 at ATG3.
Three doctors, three branches, three active devices, each with exactly one usable
device-bound credential.

Activation was a single added host line, `ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS=9,15`,
unioned with the untouched singular `=18`, then a config rebuild as the application
user. No code was deployed, because the cohort is a host value.

Every doctor was proven physically in Chrome and in the installed PWA, each login
preceded by `DOCTOR_SESSION_DEVICE_INVALIDATED` and followed by a WebAuthn success
with the authenticator's signature counter advancing. Counters are the load-bearing
evidence: they come from the tablet, not from us.

Two limits are recorded rather than smoothed over. The server cannot distinguish
Chrome from an installed Android PWA, because their user agents are byte-identical;
what it proves is two independent assertions, and which client made each is
operator testimony. And `android:phase4a-pilot-readiness` reports FAIL on
`enforcement_inactive` for as long as any pilot is live, because it audits the
preparation sprint's "ship it off" contract. The gate for a running pilot is
`android:phase4a-pilot-scope`.

Full record: `docs/sprints/doctor-pwa-multi-doctor-pilot-1.md`. Rules: MD-R1…R14 in
`.cursor/rules/151-doctor-pilot-enforcement-cohort.mdc`.

---

## 18. Global rollout readiness — DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1

Three doctors are enforced onto trusted devices and it works. The question this
section answers is the next one, and it is not the same question: could that be
switched on for *everybody* without stranding a clinician?

The answer, measured against production, is no — and the shape of the no is the
useful part. Fifteen doctors hold an active account linked to an active doctor
record. Three of them have a complete trusted path. The other twelve hold no
device authorization at all, so they have no credential and no rehearsal either.
Every one of them falls at the same gate, and nothing downstream of that gate can
be fixed in software: a credential only exists once a person registers it on a
tablet under their own biometric.

### What "ready" means, and why it is counted per doctor

A doctor is ready when at least one complete path exists: an active doctor record
linked to an active account, an active and cryptographically verified device, an
active authorization joining the two, and on that device a credential that is
user-verified, not backup-eligible, device-bound and not revoked.

Counted per DOCTOR, never per device. A credential belongs to a device, so a
fleet with three usable credentials and fifteen doctors is entirely consistent —
and entirely unready. Sharing a tablet is not being authorized on it. A doctor
with two devices needs only one of them to work; a doctor with none is unready
however many tablets sit in their clinic.

Branch is reported three ways because one number would lie. Every doctor is
pivot-authorized to practise at every RME branch, so a count keyed on that pivot
returns the whole fleet for every branch. The number that means something is
"doctors whose complete path runs through a device registered here".

### The gate that failed on success

`android:phase4a-pilot-readiness` exited non-zero on production, printed NOT
READY, and was right by its own contract and wrong about the world. It was
written for a preparation sprint whose claim was "we shipped nothing armed", so
it failed whenever the enforcement flag was armed — and §17 armed it, on purpose,
with an owner's approval. §17 recorded the contradiction as a known limit rather
than fixing it. This section fixes it.

The status narrowed to the condition the durable rules already described, and
which the code was already computing one line above for its own detail text: the
flag armed while the scope covers nobody. That state denies no doctor anything,
so it cannot lock a clinic out, but it reads as protection while providing none.
Browser denial configured outside a declared scope stays a failure exactly as
before.

What replaced the dropped breadth is not nothing. Enforcement stopped being a
boolean, because a boolean cannot tell an owner-approved three-doctor pilot apart
from a clinic-wide lockout — and that conflation is the whole defect.

### The rules

**GR-1.** Readiness GO is not activation GO. Measuring a fleet enforces nobody,
and a readiness tag authorises a later sprint to decide, never the switch itself.

**GR-2.** Fleet-wide enforcement stays off until a dedicated activation authority
says otherwise. Nothing in a readiness sprint may move it.

**GR-3.** Readiness is per doctor. Every doctor intended for enforcement needs at
least one complete trusted path of their own.

**GR-4.** A complete path is all five of: active doctor record, active account,
active cryptographically verified device, active doctor-device authorization, and
a usable device-bound credential on that device. Four out of five is not a path.

**GR-5.** A credential belongs to a device, not to a doctor. Counting credentials
measures hardware; counting doctors with paths measures readiness.

**GR-6.** Two doctors may share one trusted device, and only through two explicit
active authorizations. Device sharing never implies doctor authorization.

**GR-7.** `user_verified` must be true and `backup_eligible` must be exactly
false. The column is nullable because an authenticator that reported neither flag
told us nothing, and silence is not a pass — so the test is `=== false`, never
`!== true`.

**GR-8.** The stored device-bound verdict is checked separately from the flag it
was derived from. The two agreeing is the normal case; the two disagreeing is the
only route by which a syncable passkey could reach a clinical session, and
collapsing the check would remove the only place it is visible.

**GR-9.** A revoked device stays revoked. Revocation is terminal, it is counted
in the estate, and it never contributes to readiness.

**GR-10.** The readiness engine reports; it never provisions. That is a
structural claim: a test scans its source for the primitives that could write,
spawn, fetch or read a request, because a readiness gate that can act is a
readiness gate whose output describes its own side effects.

**GR-11.** Enforcement has four postures — off, bounded pilot, global rollout
readiness, global — and the report names which one is observed. A boolean that
cannot separate a bounded pilot from a fleet lockout is how a gate ends up
failing on success.

**GR-12.** The declared posture lives in source control and is a CEILING, not an
equality. The same reviewed code runs quiet in CI and armed on production; what
must never happen is a deployment enforcing MORE than anyone reviewed.

**GR-13.** `indeterminate` — armed over a scope resolving to nobody — is a state
a deployment can be observed in and never a posture a reviewer may declare.

**GR-14.** Global rollout readiness sits at the same enforcement strength as the
bounded pilot it describes, never above it. If measuring outranked the pilot,
declaring it would itself be an activation.

**GR-15.** Whether fleet-wide enforcement is live is MEASURED from the resolved
scope, never read from a recorded claim. The activation boundary carries a
hardcoded `false` for the same field; a safety line that is true because somebody
typed it is not a safety line, and the activation checklist reads it before
arming anything.

**GR-16.** Fleet enumeration lives in its own repository interface, which the
login gate is never given. The credential interface's refusal to expose an "all
credentials" accessor is a boundary the login path depends on, and a reporting
need is not a reason to widen it.

**GR-17.** The engine's answer and the login gate's answer are compared, and a
disagreement is reported as a finding rather than resolved in either direction. A
second implementation of a security decision is a second implementation that can
drift.

**GR-18.** A doctor covered by the cohort but not provisioned is the state that
strands a clinician, and it is reported explicitly. Membership and readiness are
different facts.

**GR-19.** PARTIAL is the ordinary state of a staged rollout and exits zero. A
gate that reddens for the whole duration of a rollout is a gate somebody removes
from the deploy chain.

**GR-20.** No count of ready doctors substitutes for a ceremony. Readiness says a
path exists on paper; only a person at a tablet proves it carries them.

### What this section does not claim

That any of the twelve can be provisioned from a terminal. They cannot. Each
needs an authorization approved through the canonical screen, a credential
registered on a real tablet under their own biometric, and one genuine login
plus one protected request so the audit trail names the exact user, device,
authorization and credential. Rehearsals run in batches no larger than the
source-controlled cohort ceiling, so twelve doctors are at least three rotations,
and the cohort returns to its approved membership afterwards.

Full record: `docs/sprints/doctor-pwa-global-rollout-readiness-1.md`. Rules:
GR-R1…R20 in `.cursor/rules/152-doctor-global-rollout-readiness.mdc`.
