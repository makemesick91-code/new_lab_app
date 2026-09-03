# Runbook — clinic tablet loss, replacement and decommission

**FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5.**
Related: [provisioning](android-clinic-device-provisioning.md) ·
`.cursor/rules/139-doctor-device-registry.mdc` ·
[ADR 0009](../adr/0009-android-production-signing-distribution-and-device-management.md).

Audience: clinic owner and Super Admin, under pressure.

---

## The one action that matters

**Lost or stolen tablet → REVOKE it in Master Data → Device Dokter. Now.**

Everything else can wait. Revocation is terminal by design: a revoked device
cannot be reactivated, so the honest recovery for a device that turns up again
is to re-enrol it as a new device. That asymmetry is deliberate — an
administrator who is unsure should revoke, because the cost of being wrong is
five minutes of re-enrolment, and the cost of the other error is a trusted
device in someone else's hands.

> While Phase 3.5 stands, **device enforcement is off**, so a stolen tablet
> confers no login advantage over any browser. Revoking is still correct: it
> makes the registry true, and the registry is what Phase 4/5 will enforce on.

---

## 1. Lost or stolen

| # | Action | Who |
|---|---|---|
| 1 | Master Data → Device Dokter → the device → **Revoke**, with a reason | Super Admin |
| 2 | Confirm status is `REVOKED` and the audit entry exists | Super Admin |
| 3 | If a Doctor session was live on it, invalidate their session (password reset forces re-authentication) | Super Admin |
| 4 | Record: device label, branch, when last seen, circumstances | clinic owner |
| 5 | Bring the branch spare into service (§3) | branch lead |
| 6 | If theft: police report; note the serial from the procurement register | clinic owner |
| 7 | Under an EMM: issue a remote wipe as well — belt and braces | Super Admin |

**Do not delete the device row.** Never. It is the security history of the
incident, and the registry has no delete for exactly this reason.

### What an attacker actually gets

Worth knowing before anyone panics:

- The device private key is **non-exportable**, generated inside the Android
  Keystore. It cannot be copied off, even with the tablet in hand.
- Under Lock Task with Device Owner, the tablet is confined to the clinic app.
- Copying the APK to another device grants nothing: a new install generates a
  different keypair that no administrator has approved.
- The threat that remains is an unlocked, already-enrolled tablet with a live
  Doctor session. That is what steps 1 and 3 close.

---

## 2. Broken, dead battery, will not boot

1. Revoke the device — you cannot verify what state it is in, and an unverifiable
   device is not a trusted one.
2. Bring the spare into service.
3. If it is repaired: factory reset, re-provision, **enrol as a new device**.
   The old row stays revoked forever.

---

## 3. Spare devices

Sizing is deliberately not a fixed number pulled from the air. Base it on how
many Doctor stations a branch actually runs concurrently:

```
devices per branch = concurrent Doctor stations + 1 spare
```

The `+1` is the whole policy. Without it, one broken tablet under enforcement is
a branch that cannot see patients — which is precisely why enforcement stays off
until `spare_device_available_per_branch` is satisfied
(`config/android_release.enforcement.global_prerequisites`).

**Cold spare** (recommended for the pilot): provisioned, enrolled, `DISABLED` in
the registry, charged monthly. Activation is one status change, and a disabled
device that is never activated is inert if it is stolen from the cupboard.

**Warm spare**: provisioned and `ACTIVE`. Faster, but it is a live trusted device
sitting in a drawer. Only where physical security justifies it.

Monthly check: charge it, boot it, confirm it reaches the login screen, confirm
its registry row is the state you expect.

---

## 4. Doctor coverage

Before enforcement is ever switched on, per branch:

- [ ] every Doctor who will be enforced has an `ACTIVE` device
- [ ] concurrent Doctor stations counted (peak, not average)
- [ ] one spare available
- [ ] a named fallback for the day it all goes wrong

The fallback is **browser login**, which is why Phase 3.5 leaves
`DOCTOR_BROWSER_LOGIN_DENIED = false`. Removing that door before spares exist is
how a clinic loses a day of appointments.

---

## 5. Replacement

1. Procure per the [tablet specification](../operations/clinic-tablet-procurement-specification.md).
2. Provision per the [provisioning runbook](android-clinic-device-provisioning.md).
3. Enrol as a **new** device, approve it, bind it to its branch.
4. Revoke the device it replaces, if not already revoked.
5. Update the asset register: serial, branch, purchase date, warranty end.

---

## 6. Decommission (end of life, resale, redeployment)

Order matters — revoke *before* the device leaves anyone's hands.

1. Revoke in Master Data → Device Dokter, reason `decommissioned`.
2. Confirm no active Doctor session remains on it.
3. Under an EMM: remove from the EMM, then release Device Owner.
   Self-owned Device Owner: `adb shell dpm remove-active-admin ...`, or factory
   reset, which is simpler and more thorough.
4. **Factory reset.** This destroys the Keystore identity — that is the point.
5. Verify the device boots to the stock setup wizard.
6. Update the asset register.
7. Only then does it leave the building.

Never sell or hand on a device that is still `ACTIVE` in the registry.

---

## 7. App data cleared (identity lost, device still in hand)

Clearing app data destroys the Keystore identity. Intended behaviour, not a
bug — and it is why a copied APK grants nothing.

1. The device can no longer prove itself; it will fail enrolment checks.
2. Revoke the old row.
3. Re-enrol: the fresh install generates a new keypair, which an administrator
   must approve.

Under an EMM, restrict "clear app data" for the clinic package so this stops
being a one-tap accident.
