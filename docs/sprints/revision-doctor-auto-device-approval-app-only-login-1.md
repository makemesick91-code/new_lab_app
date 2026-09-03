# REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1

Programme: `FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1`
Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Baseline: `revision-doctor-android-direct-apk-signing-distribution-1-go` @ `bf4c4649f720696848d1b63b8043b72e1d8f9fce`
(tree `3e1baae01d2c326188dc4079313b8041b95d43f0`, identical on origin and on the VPS)

## What this revision is, and is not

It builds the **complete capability** for a doctor to be locked to approved clinic
hardware, and it leaves that capability **switched off in production**.

| | after this revision |
|---|---|
| `AUTO_DEVICE_APPROVAL_CAPABILITY` | true |
| `APPROVAL_UI_AVAILABLE` | true |
| `DOCTOR_DEVICE_AUTHORIZATION_DOMAIN` | true |
| `APP_ONLY_GATE_CAPABILITY` | true |
| `SESSION_DEVICE_BINDING_CAPABILITY` | true |
| `DEVICE_ENFORCEMENT_ACTIVE` | **false** |
| `DOCTOR_BROWSER_LOGIN_DENIED` | **false** |
| `ANDROID_REAL_DEVICE_VALIDATION` | **false** — no physical clinic device was available |
| Phase 4 | NOT STARTED |
| full feature | **NO-GO** |

A doctor working in production today logs in exactly as they did before. Nothing
in this revision can lock a doctor out, and an empty authorization table is not
a denial while the flag is off.

## Two things that were being conflated

Phase 2 gave us a registry of **physical devices**. What was missing is the
statement *"this doctor may use this device"*. The revision separates them:

```
Doctor ──┐
         ├── DoctorDeviceAuthorization ── DoctorDevice (physical, cryptographic)
Doctor ──┘
```

One tablet serves many doctors. There is exactly **one** `DoctorDevice` row per
physical device — never one per doctor — and one authorization row per
(doctor, device) pair.

## Canonical workflow

```
Doctor opens the Clinic App
   → enters username / password IN THE APP
   → the app signs a server-issued nonce with its Keystore key
   → server verifies the signature  (cryptographic possession, not a header)
   → server validates the credentials  (no session is created)
   → server resolves the doctor       (mst_doctors.user_id only)
   → resolve the (doctor, device) authorization
        not found → create PENDING
        PENDING   → stay PENDING
        REJECTED  → stay REJECTED   (never auto-reopened)
        REVOKED   → stay REVOKED    (terminal)
        ACTIVE    → eligible
   → Approval → Approval Device Dokter
   → Super Admin / Supervisor RME approve or reject
   → ACTIVE
   → (only when enforcement is switched on) app-only login is permitted
   → existing Daily Branch Lock
   → existing room selection
   → existing room-scoped patient list
```

## Ordering is the security property

The device proves its key **before** credentials are examined, and credentials
are validated **before** any row is written. So:

* a wrong password creates nothing;
* a forged / absent signature creates nothing;
* an account that is not a linked, active Doctor creates nothing.

Ten repeated login taps produce **one** doctor, **one** device, **one** PENDING
authorization — guaranteed by `UNIQUE(doctor_id, doctor_device_id)`, not by
application politeness.

## Provisional devices: why a new device status

Section 15 of the brief asks that the first doctor login be enough to register
the hardware, while section 16 asks that the operator make a single decision.
Phase 3 required an administrator to pair an enrolment to a pre-existing
registry row *before* the device could do anything — two decisions.

A device that has just proved possession of its key is now auto-provisioned into
the registry with a new administrative status:

```
DoctorDevice::STATUS_PENDING_APPROVAL = 'pending_approval'
```

This status is **strictly less privileged than anything that existed before**.
`assertAdministrativelyUsable()` still demands `active`, so a provisional device:

* cannot pass the Phase 3 `/device-api/v1/proof` trust endpoint,
* is not `trustworthy`,
* cannot carry a login ticket.

Nothing that Phase 3 denied became permitted. `identity_state` may still become
`cryptographically_verified`, because that records a *fact* — this hardware holds
the private key — and facts are not permissions.

Approving the authorization promotes the device `pending_approval → active`
inside the same transaction. That is the single operator decision.

`reactivate` was tightened at the same time: it now accepts only `disabled`, so
the disable/reactivate lifecycle can never be used as a side door that
administratively activates a device nobody approved.

## Enforcement switch

Canonical mechanism, not a parallel one: `config/feature_flags.php` +
`App\Services\Foundation\FeatureFlagService`.

```
doctor.trusted_device_enforcement   default false   FEATURE_DOCTOR_TRUSTED_DEVICE_ENFORCEMENT
```

Read only through `DoctorAppLoginGate::enforcementEnabled()`. With it off the
gate returns before touching the database, and the session middleware returns on
its first line.

## What is authoritative, and what is not

Trust comes from a signature over a server-issued single-use nonce, verified by
OpenSSL against the enrolled public key. Never from `User-Agent`, never from a
static `X-` header, never from a MAC address, IMEI, Android ID or an installation
UUID. The web login ticket is a server-minted, hashed-at-rest, single-use,
60-second, device-bound credential — a transport for a decision the server
already made, not a claim the client gets to assert.

## Preserved

Phase 1 room isolation and RME/odontogram print denial; Phase 2 device lifecycle
and terminal revocation; Phase 3 challenge/response, replay protection and the
opaque device channel; the direct signed-APK distribution chain, with Google Play
still out of it; the Daily Branch Lock; `BranchContext` as the only branch
authority.

## Deploy

`php artisan migrate --force`, then `db:seed --class=PermissionSeeder --force`,
`db:seed --class=RoleSeeder --force`, `permission:cache-reset`.
Leave `FEATURE_DOCTOR_TRUSTED_DEVICE_ENFORCEMENT` unset / false.

## Adversarial testing

24 mutants applied against the new gate, lifecycle, policy, middleware and
login service. **22 killed. 2 survivors, both equivalent by construction, so
zero real survivors.**

What the mutants actually found — three genuine coverage gaps, and two broken
mutants that had been reporting as survivors:

| Mutant | Finding |
|---|---|
| M7 / M8 (remove the policy permission checks) | Survived, because the route's `permission:` middleware refuses first and the policy never runs. Defence in depth is why that is safe, and exactly why the inner layer needed coverage of its own — it could otherwise rot unnoticed until reached from a command, a job, or a new route that forgets the middleware. Fixed by testing the policy directly. |
| M20 (reuse a consumed login ticket) | Survived, because the test replayed a **made-up** ticket the second time — refused for being unknown whatever the replay rule says. The real ticket now gets replayed. |
| M19 (redeem while enforcement is off) | Survived, because with the flag off no ticket exists to redeem. That reasoning holds only until an operator disables enforcement mid-flight — a real rollback step — with a ticket already in a tablet's hand. Now tested. |
| M17 (resolve the device branch from any branch) | Survived on a single-branch fixture. The new test gives the doctor one branch among several decoys, which is what a multi-branch clinic looks like. |
| M14 (default the enforcement flag to true) | **Broken mutant, not a survivor.** It inserted `'default' => true` *before* the real key, and in a PHP array literal the later key wins, so the file changed and the behaviour did not. Repaired to mutate the real key; it is killed. |
| M23 / M24 (added after the above) | The unique-index catch and the "no RME branch" refusal, neither of which had been probed. Both killed. |

The two survivors, with the reason each is equivalent rather than a gap:

* **M1** — removing the locked re-read inside the transaction. Three layers
  guard idempotency: the read before the transaction, that locked re-read, and
  the unique index. In a single process the first always answers; in a real race
  the third does. M23 proves the third is load-bearing, so removing the second
  changes no observable behaviour.
* **M10** — removing the early "no device session" return from
  `denyBrowserSessionReason`. Execution falls through to `denySessionReason`,
  whose `is_int()` check returns the **same** deny code. The suite stayed green
  including `denies an ordinary browser login for a doctor`, i.e. the browser was
  still refused: the mutant removed a redundant expression of the guard, not the
  guard.

Restore was by file copy with a `cmp` verification after every mutant
(`RESTORE_VERIFIED=true` × 24, zero failures), and the compiled Blade cache was
cleared around each one — a stale compilation silently invalidates every later
verdict.
