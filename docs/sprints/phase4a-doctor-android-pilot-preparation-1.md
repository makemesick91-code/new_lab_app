# PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1

**Status:** preparation complete — `READY_FOR_PILOT`
**Base:** `724e8308` (`production-android-release-apk-evidence-1-go`)
**Scope:** preparation only. No tablet touched, no ADB, no APK distributed, no
device enrolled, no pilot activated, no enforcement armed.

## What this sprint found

The Phase 4A pilot as recorded could not be run.

`device_management.pilot_model` was `self_owned_device_owner`, and six of the
thirty-three recorded `pilot.acceptance_checks` are Device Owner and lock-task
properties:

- `device_owner_established`
- `lock_task_active`
- `home_escape_blocked`
- `recents_escape_blocked`
- `external_browser_escape_blocked`
- `reboot_returns_to_clinic_app`

Android grants Device Owner only on a device carrying no accounts — in practice,
immediately after a factory reset. The owner has refused a factory reset for this
pilot, and the pilot tablet is already in service and has been through the
Phase 4A hardware preflight.

So an activation agent reading only the configuration had three options: wipe the
tablet against an explicit instruction, record six acceptance checks as PASS
falsely, or stall mid-pilot. Three sprints built a signer resolver, a certificate
pin, an artifact verifier and a 42-check readiness gate that all reported GO
around a pilot that no one could start.

This is the same shape as the defect the previous sprint found in
`proguard-rules.pro`: every predicate around the thing was proved, and the thing
itself was never proved to be possible. Rule 147, third instance.

## The half that mattered more

Removing six checks is the easy half.

Lock task was **also** what physically kept a doctor away from a browser. Dropping
it moves the app-only boundary entirely onto the server. The good news is that the
boundary genuinely holds there: `DoctorAppLoginGate` denies a browser session from
the **absence** of a server-verified device binding, re-checks it on every
protected request, and only the redemption of a server-minted ticket — signed over
a single-use nonce — can create one. Leaving the app grants nothing.

The bad news is that it holds only while enforcement is armed **for this doctor**,
and there was no way to express that. `doctor.trusted_device_enforcement` is one
boolean, and its own registry entry says what arming it does: it "DENIES browser
login for every account holding the Doctor role". `enforcement.stages` has listed
`pilot_branch_or_device` as a distinct stage since Phase 3.5 and nothing
implemented it.

That left exactly two reachable states, both wrong:

1. Arm the flag → every doctor at every branch is denied a browser unless already
   enrolled and approved. A clinical lockout, which the flag's own description
   warns about.
2. Don't arm it → the "app-only pilot" has no app-only property at all, because
   drg Karmila can simply open Chrome.

So the deferral and the scoping mechanism are one change, not two. A
non-destructive pilot without pilot-scoped enforcement is not a pilot.

## What this sprint changed

**`config/android_release.php`**

- `device_management.supported_models` gains `self_owned_non_destructive`, listed
  first; `pilot_model` points at it. `fleet_model` is unchanged — a dedicated,
  wiped, Device-Owner tablet is still right for a rolled-out fleet.
- A new `phase_4a` block records: the non-destructive decision
  (`factory_reset_required`, `device_owner_required`, `full_kiosk_required`,
  `managed_google_play_required` all false); the six **deferred** kiosk checks;
  five compensating controls; the 34-check Phase 4A acceptance matrix; the
  preinstall verification sequence; the activation boundary; the preparation
  lifecycle; the six-case rollback matrix; and the required audit events.
- A new `enforcement.scope` block: `mode` (`pilot` | `unscoped`),
  `global_permitted`, and the pilot target.

**`App\Support\Android\AndroidDoctorEnforcementScope`** — new. Turns the
`pilot_branch_or_device` stage into something real. It only ever **narrows**.

**`DoctorAppLoginGate::inEnforcementScope()`** — new, composed with the existing
`appliesTo()` rather than folded into it, because the role question has other
callers whose meaning must not change. The two deny paths consult it. With the
committed defaults the gate behaves exactly as it did before.

**`App\Support\Android\Phase4aPilotPreparationScanner`** and
**`android:phase4a-pilot-readiness`** — new, read-only. Separate from
`android:release-readiness` on purpose: that one answers whether a release could
be MADE safely, this one whether a pilot could be STARTED safely, and the two must
be able to disagree.

**`docs/runbooks/android-phase4a-pilot-activation-checklist.md`** — new, and
registered in `scanner.required_documents` so it cannot go missing without
reddening a gate.

## The direction the scope fails in, and why

`AndroidDoctorEnforcementScope` resolves every unusable, contradictory or
unrecognised configuration to **covering nobody**:

| Configuration | Covers |
| --- | --- |
| `pilot` with a valid target | that one doctor |
| `pilot` with no target | nobody |
| `unscoped` with `global_permitted` | every doctor |
| `unscoped` without `global_permitted` | nobody |
| an unrecognised mode | nobody |

This is the opposite of the usual deny-by-default reflex, so it is stated
deliberately rather than left to be discovered.

"Deny" here does not withhold data from an attacker. Every RBAC, branch, room and
consent gate is in force either way, and a doctor still needs their password.
What "deny" withholds is a doctor's ability to reach their patients at all. A
mistyped environment variable that locked every doctor out of every branch is a
clinical incident; the same mistake resolving to "enforce nobody" leaves
production exactly as it is today and grants no new access.

The cost of that choice is a **silent no-op**: an operator could arm the flag,
believe doctors are locked to devices, and be wrong. That is a real risk, so it is
made loud in three places — `enforcement_inactive` FAILS when the flag is armed
while the scope covers nobody; the command prints `ENFORCEMENT_SCOPE_USABLE`
directly beneath `ENFORCEMENT_FLAG_ARMED`; and checklist step E4 orders the arming
and step F8 demands the boundary be demonstrated in both directions. The loud
failure is the compensating control for the quiet direction.

Fleet-wide denial now requires **two** deliberate declarations, neither of which
is the enforcement flag. Arming the flag alone can no longer lock a fleet out.

## Deferral is not deletion

The six kiosk checks stay in `pilot.acceptance_checks` as the historical Phase 3.5
record, and appear in `phase_4a.deferred_to_dedicated_device_phase` as work still
owed by whoever provisions a dedicated device. `deferred_kiosk_checks_declared`
FAILS if a deferred check is dropped from both lists, and FAILS if one is smuggled
back into the Phase 4A matrix. A check that is deferred is a check somebody still
owes.

## Sibling assertions repinned

- `DoctorDeviceAndroidReleaseGovernanceTest` pinned
  `device_management.pilot_model` to `self_owned_device_owner`. That pin *was* the
  defect, held in place by a test. Repinned, with the reason recorded beside it.
- `enforceDeviceLock()` in `DoctorDeviceEnforcementGateTest` flipped one boolean
  and every assertion under it assumed fleet-wide denial. It now declares the
  fleet-wide scope explicitly. Eleven call sites, one helper, no assertion
  weakened — a test that asserts fleet denial should have to say so.

## What remains false, and asserted false

`APK_DISTRIBUTED`, `APK_INSTALLED`, `TABLET_TOUCHED`, `ADB_USED`,
`DEVICE_ENROLLED`, `PILOT_ACTIVATED`, `PILOT_BROWSER_DENIAL_ACTIVE`,
`GLOBAL_ENFORCEMENT_ACTIVE` — all false, all asserted by
`activation_boundary_all_false`.

No signing key was accessed. No vault was opened. No PKCS12 was read. No password
was typed. The production APK was not rebuilt, not re-signed and not moved.

## Next sprint

`PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1`, following
`docs/runbooks/android-phase4a-pilot-activation-checklist.md` line by line. It
must not be started as a side effect of this one.
