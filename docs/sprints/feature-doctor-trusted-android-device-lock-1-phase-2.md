# FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 2

**Doctor device registry + Master Data → Device Dokter**

- Branch: `feature/doctor-trusted-device-lock-1-phase-2-device-registry`
- Base: Phase 1 GO `be12862424fb74fa3a87d98800f2996dca0a8958` (tree `3d1e88d4…`, = production)
- Rule mirror: `.cursor/rules/139-doctor-device-registry.mdc`
- Phase 1 rule (still in force): `.cursor/rules/138-doctor-room-scoped-access-and-print-deny.mdc`

---

## 1. Scope

Phase 2 builds the **server-side control plane** for the clinic devices a future
Android Clinic App will enrol into. It is **capability only**.

| | |
|---|---|
| `DEVICE_REGISTRY_AVAILABLE` | **true** |
| `DEVICE_MANAGEMENT_AVAILABLE` | **true** |
| `DEVICE_ENFORCEMENT_ACTIVE` | **false** — and structurally so |
| `DOCTOR_BROWSER_LOGIN_DENIED` | **false** — unchanged |

The full feature stays **NO-GO**. There is still no Android app, no enrolment
API, no session binding and no device-aware login, so the full-feature tag
`feature-doctor-trusted-android-device-lock-1-go` is **not** created.

## 2. Why enforcement staying off is structural, not just "not switched on"

Nothing in authentication, session handling or any middleware references
`DoctorDevice`. `DoctorDeviceAccessTest` asserts that by scanning the auth
surfaces for the class name, and mutant **M7** proves the assertion bites: it
adds a single `DoctorDevice` mention to `PostAuthenticationRedirectService` and
the suite fails.

A passing "doctor can still log in" test alone would not have proved this — a
coupling that simply has not fired yet would still pass. That is why the check
is structural.

**No feature flag was added.** Minimum scope was deliberate: nothing needs one
until the phase that actually enforces.

## 3. Domain

`mst_doctor_devices` (additive migration `2026_09_03_100001`), module
`app/Modules/DoctorDevice/` mirroring the ClinicRoom master-data template.

Two axes are kept deliberately orthogonal:

| axis | meaning |
|---|---|
| `status` | the administrative decision — `active` / `disabled` / `revoked` |
| `identity_state` | whether the hardware ever *proved* itself — `unverified` / `cryptographically_verified` |

An admin-typed row is a **registered database record**, never a proven device.
`cryptographically_verified` is unreachable in Phase 2 by construction, so no
manual entry can masquerade as trusted hardware.

### Lifecycle

```
ACTIVE ⇄ DISABLED        ACTIVE → REVOKED        DISABLED → REVOKED
REVOKED → (terminal)
```

Revocation is terminal: reusing the physical hardware means a fresh
cryptographic identity later, not resurrecting the old row. Disable and revoke
both require a written reason; revoke is high-friction in the UI (reveal →
reason → confirm).

**No delete path exists at all** — no route, no soft delete, no service method.
A device that was ever trusted keeps its security history.

### Status is never a payload

`status`, `identity_state`, `public_key_fingerprint`, `uuid` and every
lifecycle stamp are outside the model's `$fillable`, *and* the service writes
only explicit allow-listed arrays. Those are two independent barriers — see §5.

### Branch binding

A device belongs to one active, RME-enabled branch, re-validated server-side on
create **and** on update. A request may only select from that set. Canonical
codes only (`TLK1`, `SPN4`).

### No MAC, no IMEI

Neither is stored, and neither would be an authentication authority. The future
trust anchor is `public_key_fingerprint` — a public key fingerprint, never a
private key and never a reusable enrolment secret.

## 4. Authorization

`view_doctor_devices` and `manage_doctor_devices` are seeded but granted to
**no role**, so only Super Admin reaches them via the single global
`Gate::before` — the ENT-7 `view_developer_console` precedent.

Read and write are separate: `view_doctor_devices` alone passes the route
middleware, so the **policy** refuses every write. That distinction is what
mutant M1 exposed (see §5).

## 5. Two findings the mutation matrix produced

**M1 initially survived.** The test aimed at it used an Admin Klinik actor, who
never reaches the policy at all — the route middleware rejects them first. So
the test could not tell policy from middleware. Closing it required a new case:
a user holding *only* `view_doctor_devices`, who gets past the middleware and
must still be refused every write.

**M2 is inert as a single-line mutant, by design.** `updateMetadata()` filters
the payload with `array_intersect_key` *and* writes an explicitly constructed
`$changes` array. Removing either barrier alone changes nothing; only removing
both is a real vulnerability. M2 is therefore a **compound** mutant, and it is
killed in that form.

## 6. Files changed

| File | Change |
|---|---|
| `database/migrations/2026_09_03_100001_create_mst_doctor_devices_table.php` | **NEW** additive table |
| `app/Modules/DoctorDevice/**` | **NEW** Model, Interface, Repository, Service, Policy, 3 Requests, Controller |
| `database/factories/DoctorDeviceFactory.php` | **NEW** |
| `resources/views/settings/doctor-devices/*` | **NEW** index / create / edit / show / _form |
| `routes/web.php` | `settings.doctor-devices.*` (no `destroy`) |
| `app/Providers/RepositoryServiceProvider.php` | repository binding + policy registration |
| `database/seeders/PermissionSeeder.php` | +2 permissions, granted to no role |
| `resources/views/layouts/partials/sidebar.blade.php` | Master Data → Device Dokter |
| `config/ci_runner.php`, `.github/workflows/foundation-evidence-gates.yml` | mandatory suite + `DoctorDevice` filter token |
| `tests/Feature/DoctorDevice/*` | **NEW** 39 tests |

**No role gained a permission. No enforcement. No Android code.**

## 7. Preserved

Phase 1 room isolation and RME/Odontogram print denial (mutants M8/M9 pin
them), consent gate, room gate, doctor→cashier gate, branch isolation,
KTP/NIK privacy, canonical branch codes.
