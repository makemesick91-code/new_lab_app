# DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — Pendaftaran Device Dokter

**Branch:** `feature/doctor-device-guided-registration-workflow-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `e713f44b`
**Scope:** guided registration workflow over the existing device registry.
**No migration. No new permission. No seeder change. No JS/CSS. No production flag change.**

---

## What this is

A seven-step guided workflow at `/settings/doctor-device-registration` for
bringing a new clinic tablet into service, operated by authorized
administrative users. It is **not** doctor self-registration.

It is a **guide over surfaces that already exist**, not a second
implementation. Every step except one posts to the mutation route that already
owns it, with its existing policy:

| Step | Route it drives | Authority |
|---|---|---|
| 1 Data Device | `settings.doctor-devices.store` / `.update` | `register_doctor_devices` / `manage_doctor_devices` |
| 2 Registrasi WebAuthn | `settings.doctor-devices.webauthn.options` / `.store` / `.revoke` | `manage_doctor_devices` |
| 3 Approval Device | `settings.doctor-devices.approve-registration` | `manage_doctor_devices` |
| 4 Authorization Dokter | **new:** `settings.doctor-device-registration.doctors.store` | `manage_doctor_device_authorizations` |
| 5 Uji Login | read-only | — |
| 6 Verifikasi Readiness | read-only | — |
| 7 Selesai | read-only, gated on readiness | — |

---

## Owner decisions implemented

**D-1 — two-party relay.** The workflow is shared by Supervisor RME and Super
Admin. `register_doctor_devices` files a tablet; only `manage_doctor_devices`
enrols its credential and admits it into service. That D11 split is preserved,
not collapsed. Where the current actor cannot act the page renders an explicit
waiting state ("Menunggu Super Admin"), and the route layer refuses the
underlying POST as well as the policy.

**D-2 — sidebar.** `Pendaftaran Device Dokter` sits under Master Data beside
`Device Dokter`. `Approval Device Dokter` stays a **top-level operational
group** — it is a daily approval queue and must not be filed behind a
security-administration screen.

**D-3 — step 4 files, never approves.** Operator-selected doctors are written
as `STATUS_PENDING` + `SOURCE_ADMIN` via the existing
`DoctorDeviceAuthorizationService::resolveOrRequest()`, which cannot produce an
ACTIVE row. Approval stays in Approval Device Dokter.

---

## No migration, and why

There is no `wizard_status` column and there must not be one. Every step is
derived from security truth that already exists:

| Step | Derived from |
|---|---|
| 1 | the `DoctorDevice` row |
| 2 | its credentials, judged by `DoctorDeviceCredentialUsabilityPolicy` |
| 3 | `status` (`PENDING_APPROVAL` → `ACTIVE`) |
| 4 | its `DoctorDeviceAuthorization` rows |
| 5 | `DoctorWebAuthnLiveProofService` |
| 6 | `DoctorDeviceRegistrationReadinessService` |
| 7 | 6, and only 6 |

A stored status would be a second copy of all of that, free to drift the moment
someone revokes a credential from the device page — and a "step 7 complete" row
survives a revocation that makes the tablet unusable. A test asserts the
completed step **un-completes itself** when the credential behind it is revoked.

---

## GLOBAL SERVICE DISABLED != DEVICE DEFECT

**This is the canonical readiness decision for this sprint.**

`DoctorAppLoginGate` refuses every WebAuthn proof while the estate-wide master
switch `doctor.pwa_webauthn_device_login` is off. An earlier draft of the
checklist reported that refusal as `NO_INVALID_DEVICE_STATE = FAIL` with reason
`gate_refuses_a_provisioned_path` — which told the operator that a perfectly
provisioned tablet was defective, for a reason that had nothing to do with the
tablet and could not be fixed from this page.

The locked behaviour is two rules that are one decision:

**With the global flag OFF:**

1. The device-specific checklist **must not** report a device failure. The
   gate-agreement check reports `UNVERIFIED` carrying the real reason
   `webauthn_login_disabled`. Every device-specific gate — device active,
   approval, credential active, user verification, device binding, doctor
   authorization — continues to report its own true state, and they stay `PASS`
   for a correctly provisioned tablet.

2. Step 7 **remains blocked**. `UNVERIFIED` is not a pass, so the verdict is
   `NOT_READY` and the page never says READY FOR CLINICAL USE.

Rule 1 governs the **reason** shown to the operator: do not send someone to
re-enrol a healthy tablet. Rule 2 governs the **verdict**: do not promise a
tablet works when the capability it needs is switched off. Fail-closed is
deliberate — a tablet is not ready for clinical use if no doctor can log in on
it.

Changing either half without the other reintroduces exactly one of the two
failure modes. Both halves are pinned by tests.

> The remedy for a NOT_READY board caused by this is an estate posture decision
> about the flag, with its own change process — **not** an edit to this
> checklist and **not** a production flag flipped to make a screen go green.

---

## Other findings fixed in this sprint

**Estate report rebuilt per row.** The board called the estate-wide live-proof
report once per device row — twenty tablets, twenty full estate scans. Now
memoised per request, so every row on a page is judged against one snapshot.
The test pins it to **exactly one** build (it reports 3 without the fix), rather
than a ceiling, because a ceiling passes for a duplicate as happily as for a
correct implementation.

**Credential usability had one owner and needed two callers.** The chain was
private to `DoctorGlobalRolloutReadinessService`. Rather than write a second
copy for device scope it was extracted to
`DoctorDeviceCredentialUsabilityPolicy`, which the fleet engine now delegates
to — verified behaviour-neutral by that engine's own 41 existing tests before
anything was built on it. The REASON_* vocabulary deliberately stayed on the
fleet engine: two names for one refusal is the same drift in a different
disguise.

---

## Security invariants preserved

- registration != approval; enrolling a credential never activates a device
- no manual PASS for the login test, and no route that could record one
- no private key, PIN or biometric reaches the server
- revoke, never destroy — no delete route anywhere
- branch resolution stays server-side
- typed URLs cannot bypass mutation prerequisites
- one device authorizes many doctors without re-enrolling WebAuthn
- the return-to-workflow flag is a boolean, never a URL (no open redirect)

---

## Tests

`tests/Feature/DoctorDevice/DoctorDeviceRegistrationWorkflow{Access,Steps,Readiness}Test.php`
— 86 tests. Named to match the existing `DoctorDevice` CI critical-gate token.

Adversarial cases worth naming:

- a proof performed on **another** tablet does not satisfy this one (two
  devices in the fixture, and the other tablet is asserted still READY, so the
  test is about correlation rather than a broken engine)
- a credential row re-pointed at this device does not bring its history with it
- stale vs unmeasured freshness are separate gates
- credential / device / authorization revocation each fail their **own** gate
  while the others stay PASS
- step 5 is proven to have **no write route at all**
- global flag OFF → device gates stay PASS, gate-agreement is UNVERIFIED with
  the real reason, verdict NOT_READY, step 7 blocked
