# ADR 0011 — Doctor↔device authorization, automatic approval requests, and the app-only login gate

* Status: **ACCEPTED** — capability shipped, enforcement OFF
* Date: 2026-09-03
* Sprint: `REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1`
* Supersedes, narrowly: the Phase 2/3 rule that no authentication surface may
  reference the device registry (see “Decision 2”). Nothing else in
  ADR 0009 or ADR 0010 changes.

## Context

Phase 2 gave DaengtisiaMS a registry of physical clinic devices. Phase 3 gave
those devices a hardware-backed cryptographic identity and a challenge/response
protocol. What neither produced was the sentence the owner actually needs:

> **this doctor** may use **this device**.

Operationally the gap showed up twice. First, pairing a tablet required an
administrator to match a pairing code to a pre-existing registry row *before*
the device could do anything — two decisions for what a clinic thinks of as one.
Second, nothing anywhere expressed per-doctor access, so a tablet was either
trusted for everyone or for nobody, while a real clinic has several doctors
sharing one chair-side tablet.

The owner asked for the workflow to become: the doctor opens the app, signs in,
and if that pairing is unknown a request appears in an approval inbox for
Super Admin or Supervisor RME.

## Decision 1 — authorization is a separate concept from the device

```
Doctor ──┐
         ├── DoctorDeviceAuthorization ── DoctorDevice
Doctor ──┘
```

One physical tablet keeps exactly one `DoctorDevice` row and gains one
authorization per doctor. The alternative — a device row per doctor — was
rejected: it would have duplicated cryptographic identities, made “is this
tablet revoked?” ambiguous, and quietly broken the Phase 3 uniqueness of
`public_key_fingerprint`.

Idempotency is `UNIQUE(doctor_id, doctor_device_id)`. An application-level check
was considered and rejected: two concurrent logins both pass a read-then-write.

## Decision 2 — the auth path may consult exactly one gate

Phase 2/3 asserted structurally that no file under `app/Http/Controllers/Auth`,
`app/Http/Middleware`, `app/Services/Auth` or `LoginRequest.php` may contain the
string `DoctorDevice`, and its own comment said “Phase 4 is where that changes,
deliberately and with its own review”. This is that review.

An app-only gate cannot exist while the login path is forbidden from asking
whether a doctor's session may exist. The rule is therefore **narrowed rather
than dropped**, to what was actually load-bearing:

* the auth path may reference `DoctorAppLoginGate`, `DoctorDeviceSessionService`
  and `EnsureDoctorDeviceSession` — and nothing else;
* a proof service, a direct authorization query or a second read of the
  enforcement flag in an auth surface is still a failure.

The reason is not tidiness. Enforcement that lives in two places eventually
disagrees with itself, and the disagreement is discovered by a doctor who cannot
see their patients.

## Decision 3 — a provisional device status, rather than trusting new hardware

To let one operator decision cover both halves, hardware that has just proved
possession of its key is auto-provisioned into the registry as
`DoctorDevice::STATUS_PENDING_APPROVAL`.

This status is **strictly less privileged than anything that existed before it**.
`assertAdministrativelyUsable()` still demands `active`, so a provisional device
cannot pass the Phase 3 proof endpoint, is not `trustworthy`, and cannot carry a
login ticket. Nothing Phase 3 denied became permitted.

`identity_state` may still become `cryptographically_verified`, because that
records a fact — this hardware holds the private key — and a fact is not a
permission.

Approving the authorization promotes the device inside the same transaction.
`reactivate` was tightened to accept only `disabled`, closing the side door
where the disable/reactivate lifecycle could have admitted hardware nobody
approved.

## Decision 4 — credentials are collected natively; a ticket bridges to the session

The Clinic App collects the doctor's credentials itself and proves its key on
the stateless device channel. A session cookie has to be created by the WebView,
so once the server has already decided the login is permitted it mints a
one-time, hashed-at-rest, 60-second ticket bound to user + doctor + device +
authorization, and the WebView redeems it.

Rejected alternative: injecting a device assertion header into the WebView's
login POST. WebView header injection is unreliable across navigations, and it
would have created exactly the header-as-authority pattern this feature exists to
avoid.

The password is never retained to auto-login after approval. A doctor whose
request is still pending types it again later; one extra tap is cheaper than a
credential at rest on a tablet in a clinic corridor.

## Decision 5 — REJECTED does not loop, and the allowance is identity-based

A refused pair stays refused; the next login reports it and writes nothing.
Reopening requires an explicit privileged `allow re-request`, which records
**which** rejection it forgives (`re_request_allowed_for_rejected_at`). The
allowance is live only while that still equals `rejected_at`, so a later
rejection spends it and no decision is ever erased.

The obvious alternative — “the allowance must be newer than the rejection” —
was implemented first and was wrong: an approver who allows a re-request in the
same second as the rejection produces two equal timestamps, and a strict `>`
then refuses the legitimate path. A test caught it. Identity of the forgiven
rejection is exact; clock ordering is not.

REVOKED is terminal. Neither a login attempt nor an approve button returns it.

## Decision 6 — enforcement ships OFF, behind the canonical flag

`doctor.trusted_device_enforcement` in `config/feature_flags.php`, default
false, risk `critical`.

Phase 2 had ruled that “the flag is created by the phase that needs one”, and
Phase 3.5 recorded `flag_exists => false`. This revision is the phase that needs
one: it ships the complete gate, and a capability with no switch can only be
released by editing code under pressure. The rule that stance protected — no
half-wired switch — is preserved and strengthened: the flag is fully wired, and
`android:release-readiness` now verifies its OFF state against the real feature
flag registry rather than against a config file that could claim anything.

## Consequences

* `DEVICE_ENFORCEMENT_ACTIVE=false`, `DOCTOR_BROWSER_LOGIN_DENIED=false`.
  Production behaviour for doctors is unchanged.
* Three additive tables; no existing column altered, nothing backfilled.
* Supervisor RME gains two permissions and no other authority — in particular
  not the device registry.
* The full feature remains **NO-GO**. Real-device validation has not been
  performed and Phase 4 has not started.

## Phase 4 entry conditions (unchanged from ADR 0009 §enforcement)

Real-device pilot passed, every enforced doctor holds an active device, a spare
device exists per branch, the device-loss runbook is rehearsed, and rollback to
browser login is proven.
