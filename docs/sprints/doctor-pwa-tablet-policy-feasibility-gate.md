# Tablet policy feasibility gate — per-branch tablets, per-doctor Android profiles

**Status:** CANDIDATE POLICY, NOT A PROVEN IMPLEMENTATION. Nothing here has been
tested on real hardware. No doctor may be provisioned under this policy until
every point below passes on an actual clinic tablet.

Raised by DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1, which found twelve unprovisioned
doctors and three trusted tablets. Owner decision: pursue **per-branch tablets with
a per-doctor Android profile**, gated on a real-device feasibility run first.

```
TABLET_POLICY_CANDIDATE = PER_BRANCH_TABLETS_PER_DOCTOR_ANDROID_PROFILES
POLICY_FEASIBILITY      = NOT YET RUN
FALLBACK_ON_FAILURE     = ONE_CLINIC_DEVICE_PER_DOCTOR
GLOBAL_ENFORCEMENT_ACTIVE = false   (throughout, without exception)
```

**Sharing one profile across doctors is not an available fallback.** If an
isolation invariant fails, the answer is one clinic device per doctor. Reducing
hardware cost is not a reason to weaken the binding, and a shared profile would
make the device biometric attest that *someone* unlocked the tablet while the
server recorded a specific clinician — which is the exact attribution failure this
whole programme exists to prevent.

---

## What the server already guarantees, and what it depends on

This was read from the schema and the login path so the physical run can be
targeted rather than exploratory. **None of it is a substitute for the gate.**

Everything below rests on **one physical precondition**: each Android profile must
generate its **own** Keystore key, so the Clinic App presents a **distinct**
`public_key_fingerprint` per profile.

`mst_doctor_devices.public_key_fingerprint` is `UNIQUE`, and
`DoctorAppLoginService::resolveOrProvisionDevice()` resolves a device by looking
that fingerprint up. So:

| If each profile has its own Keystore key | Then |
|---|---|
| Profile A and Profile B present different fingerprints | they become **two separate `DoctorDevice` rows** on one physical tablet |
| authorizations are `UNIQUE(doctor_id, doctor_device_id)` | each doctor gets their own authorization against their own profile-device |
| `credential_id` is globally `UNIQUE` | a credential registered in A cannot collide with one in B |
| `deviceProofDenyReason()` scopes credential lookup to `usableForDevice($device->id)` | **a credential belonging to A's device row cannot keep a session alive on B's** |

That last row means **gate point 4 is already enforced server-side** — conditional
entirely on the precondition. If Android instead shares one Keystore key across
profiles, both profiles collapse into a **single** `DoctorDevice` row, every
guarantee above evaporates at once, and the policy fails. **Point 3 is therefore
the load-bearing test; run it first and stop if it fails.**

One concrete provisioning constraint falls out of the schema:
`UNIQUE(branch_id, device_name)`. Two profile-devices at the same branch need
**distinct device names**, so a naming convention has to exist before enrolment —
`SUN4_TABLET_01` cannot label both.

---

## The gate

Run on **one** clinic tablet of the model actually in service, on the OS actually
in service. Record the hardware model and OS build with the result; a pass on
different hardware is not this pass.

| # | Must be proven | Notes |
|---|---|---|
| 1 | The clinic hardware and OS support separate user/profile contexts at all | Some vendor Android builds restrict or remove secondary users |
| 2 | The Clinic App installs and operates correctly inside a doctor-specific profile | Install, enrol, log in, reach a clinical screen |
| 3 | **Keystore behaviour under profile separation is understood** | The load-bearing one. Distinct key per profile → distinct fingerprint → distinct device row. Shared key → policy fails |
| 4 | A WebAuthn registration made in Profile A is **not** usable from Profile B | Server-side already refuses this *if* point 3 holds; prove it end to end anyway |
| 5 | Chrome/PWA storage and WebAuthn credentials stay isolated between profiles | Including the installed PWA, not only the browser |
| 6 | Switching profiles reuses **none** of: doctor session, WebAuthn credential, device authorization, cached clinical state | Test the switch in both directions |
| 7 | Server-side evidence still distinguishes the resulting trusted paths well enough for exact doctor attribution | Check `DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS` names the expected user, device and credential for each profile |
| 8 | Branch isolation is unchanged | Profiles are a device concern; branch stays server-derived |
| 9 | Reboot, profile switch, logout and login remain stable | A policy that degrades after a reboot is not a policy |

### Recording the result

On a full pass:

```
TABLET_POLICY      = PER_BRANCH_TABLETS_PER_DOCTOR_ANDROID_PROFILES
POLICY_FEASIBILITY = PASS
```

On **any** security or isolation failure:

```
TABLET_POLICY      = ONE_CLINIC_DEVICE_PER_DOCTOR
POLICY_FEASIBILITY = FAIL  (name the point that failed, and what was observed)
```

A partial pass is a fail. "Points 1–8 passed and 9 was flaky" means the policy is
not proven, because point 9 is where a doctor loses access on a Monday morning.

---

## Boundaries this gate does not cross

It provisions nobody. It enrols no production device into the live cohort. It
changes no enforcement scope, and `GLOBAL_ENFORCEMENT_ACTIVE` stays false for its
entire duration — a feasibility run is a measurement, and measuring a fleet
enforces nobody.

If the gate passes, the twelve outstanding doctors are still provisioned one at a
time through the canonical flow: authorization approved on screen, credential
registered under the doctor's own biometric in their own profile, one real login
and one protected request. A proven policy shortens none of that.
