# Stage 3 — device-loss rehearsal, as executed

`REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1` Stage 3.

Executed **2026-09-18** against the production pilot (`APP_ENV=pilot`,
`PRODUCTION_HEAD=ddf3822f`), with a real clinician on real hardware. Runbook
followed: `docs/runbooks/doctor-device-loss-rehearsal.md`.

Subject: **drg Nisa** — `users.id=9`, `mst_doctors.id=17`, branch LDK2, trusted
device `PILOT_TABLET_04_LDK2`.

Outcome attested by the owner as **passed**, with the unexercised steps and the
defect below recorded rather than smoothed over.

---

## 1. Starting posture (step 0)

```
PILOT_COHORT_USER_IDS      9,15,18
ENFORCEMENT_FLAG_ARMED     true
ENFORCEMENT_SCOPE_MODE     pilot
GLOBAL_ENFORCEMENT_ACTIVE  false
WEBAUTHN_LOGIN_ARMED       true
ACTIVE_DEVICE_COUNT        4
USABLE_CREDENTIAL_COUNT    4
break-glass grants in table 0
```

## 2. Timeline, from the server

| Time | Event | Evidence |
|---|---|---|
| — | drg Nisa signs in from a **non-tablet browser** | parked on the device-verification page; **no patient data reached** |
| 13:30:06 | break-glass granted, 1-hour window | `DOCTOR_BREAK_GLASS_GRANTED` · entity 1 · by user 1 |
| 13:31:23 | she reaches a clinical session | `first_used_at=13:31:23`, `DOCTOR_BREAK_GLASS_FIRST_USED` by user 9 |
| 13:32:35 | grant revoked with a written reason | `DOCTOR_BREAK_GLASS_REVOKED` by user 1 |
| 13:32:35+ | **her already-open session was thrown out on the next click** | operator-observed |
| after | 0 active grants; posture identical to step 0 | re-measured |

A window capable of running to **14:30:06** was dead at **13:32:35** with the
session already open. The remaining 58 minutes were not a grace period — which
is the single property this drill existed to test.

## 3. Step results

| Step | Result |
|---|---|
| 2.1 Establish the denial | **PASS** |
| 2.2 Enforcement not disabled to get around it | **PASS** — flag still armed, scope still pilot, global still false |
| 2.2 Another cohort doctor unaffected | **NOT EXERCISED** — a second tablet was deliberately out of scope |
| 2.3 Grant break-glass | **PASS** |
| 2.4 Prove continuity | **PASS** |
| 2.5 Window is real (revocation ends an open session) | **PASS** |
| 2.6 Restore normal service via the trusted tablet | **NOT EXERCISED** — tablet deliberately out of scope |
| 2.7 Leave nothing behind | **PASS** |

## 4. Defect found

**The verify-device control gives no visible reaction on a browser holding no
credential.** Reported as "looks normal, clicking does nothing".

Server-side wiring was verified **intact**, so this is not a deployment fault:

- `window.doctorDeviceWebAuthn` is defined (`resources/js/app.js:15`) and
  present in the deployed bundle `app-jVlCCtwr.js`
- the error element `[data-webauthn-error]` exists (view line 45)
- the control is `<button type="submit">`, so a click does reach the handler
- the handler is bound: `form.addEventListener('submit', run)`
- assets were rebuilt during the 2026-09-18 deploy
- `git diff 56a02aed..ddf3822f -- resources/views/auth/ resources/js/ resources/css/` is **empty** — this sprint's deploy changed no view, JS or asset input

The handler is written to *display* a message when the WebAuthn global or the
call fails, so "no reaction at all" contradicts the intended path. Browser
console access was not available and the tablet was deliberately not tested.

```
CAUSE      = UNVERIFIED
SEVERITY   = UNBOUNDED
PRE-DATES  = this sprint (empty asset/view diff)
```

This repository has shipped a dead verify button once before
(`bugfix-doctor-pwa-webauthn-verify-button-1-go`), so the symptom is a known
class here and should not be assumed benign.

**Not claimed:** that the tablet login path works. It was not tested. The most
likely reading is that this is a *feedback* defect on credential-less browsers
rather than an access defect, but that is a hypothesis, not a measurement.

## 5. Other honest notes

- The grant was filed by `granted_by=1`, the **Super Admin**, not a dedicated
  Supervisor RME account. Super Admin holds the permission through the existing
  `'*'` grant plus `Gate::before`, so it is legitimate — but the
  Supervisor-RME-specific path is **untested**.
- `users.id=9` maps to `mst_doctors.id=17`. The grant table keys on `user_id`
  deliberately; keyed on `doctor_id` it would have targeted a different person.
- `DOCTOR_BREAK_GLASS_FIRST_USED` recorded `performed_by=9`. The service passes
  `null` and the audit service falls back to the authenticated user. A useful
  result, but a fallback rather than a designed one.

## 6. What this does and does not unblock

**Closes:** `device_loss_runbook_rehearsed`, once the evidence is recorded on
production with the owner's attestation.

**Does not close:** `spare_device_available_per_branch`, still measuring
**FAIL**. The owner's pre-live risk acceptance sits beside that measurement,
never on top of it.

**Does not authorize:** Stage 4 scope widening, which needs its own checkpoint.

## 7. Notes field to record verbatim

```
Drill LDK2 2026-09-18. Denial held (parked on verification, no patient data).
Break-glass granted 13:30:06 (1h), session reached 13:31:23, revoked 13:32:35,
already-open session thrown out on next click. 0 stale grants; posture restored.
NOT EXERCISED: step 2.2b (second doctor) and step 2.6 (restore via tablet) -
tablet deliberately out of scope. DEFECT: verify-device control gives no visible
reaction on a credential-less browser; server wiring verified intact; cause
UNVERIFIED, severity unbounded; pre-dates this sprint. Grant was filed by Super
Admin, not a dedicated Supervisor RME - that path untested.
```
