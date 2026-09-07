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

Turning it off ends browser sessions that are open at the time — which is what
makes the rollback real rather than nominal.

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
key** as the only proof of device identity. It now accepts either that or a
usable WebAuthn credential, gated on this sprint's flag.

This widens nothing for the Android path: a WebAuthn-only device has no
`public_key` and no key fingerprint, so `DoctorAppLoginService` cannot find it by
fingerprint and could not verify a keystore proof against it if it did.

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
