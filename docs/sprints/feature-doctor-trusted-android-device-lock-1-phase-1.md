# FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 1

**Doctor room-scoped ACTIVE patient isolation + Doctor RME/Odontogram print denial**

- Branch: `feature/doctor-trusted-device-lock-1-phase-1-room-scope-print-deny`
- Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `931ad32e` (= production, GO tag `revision-sunu-add-room-a-b-1-go`)
- Rule mirror: `.cursor/rules/138-doctor-room-scoped-access-and-print-deny.mdc`
- Manifest: `.sprint/current.yml`

---

## 1. Why this is Phase 1 and not the whole feature

The requested feature gives a Doctor four stacked security contexts:

```
TRUSTED ANDROID DEVICE  +  DAILY BRANCH LOCK  +  CURRENT ROOM CONTEXT  +  RBAC
```

Three of those already exist or are device-independent. Only the first needs
hardware. A readiness pass established, with evidence, that the device half
**cannot be completed or honestly verified right now**:

| Blocker | Evidence | Consequence |
|---|---|---|
| No physical Android device | `adb devices` empty; no Android vendor on `lsusb` | Real dedicated-device / Device Owner / Lock Task validation is impossible, and fabricating it is forbidden |
| No signing keystore, no signing governance | No `.jks`/`.keystore` in repo or `~/.android`; no signing runbook | A production signing identity is irreversible and is not an agent's decision to make |
| No registered clinic device exists | Device registry does not exist yet | Enabling "Doctor login requires a trusted device" on the live pilot would lock every doctor out of all four RME branches |

Owner decision: **no clinic tablets exist and none are being procured.** So the
Android app would be speculative, unverifiable work.

Therefore the programme is phased, matching this repo's own precedent
(FIX-PRE-68-45 = 3 PRs, LAB-WORKFLOW-V2 = 5 phases):

| Phase | Content | Status |
|---|---|---|
| **1 (this sprint)** | Room-scoped active patient isolation + RME/Odontogram print denial | **Shipped** — device-independent, independently valuable, GO-taggable |
| 2 | Device registry + `Master Data → Device Dokter`, shipped OFF | Deferred |
| 3 | Android Kotlin kiosk app + cryptographic enrollment | **Blocked on hardware + signing governance** |
| 4 | Device pilot → login enforcement ON → full-feature GO tag | Blocked on Phase 3 |

**The full-feature GO tag `feature-doctor-trusted-android-device-lock-1-go` is
NOT created by this sprint.** Device enforcement is not active, so by §13/§31/§45
of the sprint contract it would be a false GO.

---

## 2. What Phase 1 changes

### §16 — Room-scoped ACTIVE patient isolation

A Doctor sees and may open only the **ACTIVE** patients of the treatment room
they are currently online in.

- The room is resolved **server-side** from the live online context
  (`trx_user_online_contexts`, `role_context = doctor`, `status = online`).
  A crafted `clinic_room_id` / `branch_id` / `visit_id` cannot widen it.
- A scoped Doctor's room and branch list filters are dropped in the controller,
  and the room selector is not rendered — there is no "Semua Ruangan".
- Enforced as **both** a list scope and a per-visit authorization guard, so a
  hidden row and a denied direct URL are the same rule.
- Fails closed: no resolvable room ⇒ no ACTIVE visit is visible or openable.

### §17 — History is deliberately *not* restricted

The restriction covers only `ClinicVisit::PRE_EXAM_STATUSES`
(`registered`/`waiting`/`in_progress`). `cashier_pending` (post-examination) and
`completed`/`cancelled` (historical) stay reachable under the pre-existing
Sprint 66.2 doctor-patient scope.

> **Bug caught during implementation.** The first cut applied a bare
> `where('clinic_room_id', …)` inside `doctorVisitScope()`. That closure also
> feeds `ClinicVisitService::paginate()` (Daftar Kunjungan), which lists
> historical visits — so it would have hidden a doctor's own completed records.
> `applyRoomScope()` now narrows only ACTIVE rows. This is why the two methods
> must stay mirror images.

### §18 / §19 — Print denial

A Doctor may read and edit RME/Odontogram under the existing lifecycle rules but
may never print, export or download them:

- `rme.visits.print`, `rme.visits.pdf` → denied via `ClinicVisitPolicy::print`
- `rme.odontograms.print` → denied via `OdontogramPolicy::print`

Both Blade actions were **already** wrapped in `@can('print', …)`, so the policy
denial hides the buttons with no UI-only check. Prescription printing is out of
scope and untouched. Non-Doctor roles keep printing.

### §23 — Audit

`DOCTOR_ROOM_ACCESS_REJECTED`, `DOCTOR_PRINT_RME_REJECTED`,
`DOCTOR_PRINT_ODONTOGRAM_REJECTED` via the shared `AuditLogService`
(`sys_audit_logs`).

Audit is written from **controllers, never policies** — policies are also
evaluated for `@can` UI gating, and auditing there would record a "rejection"
every time a button is merely hidden. Payload is identifiers and status only;
no patient PII, no KTP/NIK.

---

## 3. Files changed

| File | Change |
|---|---|
| `app/Modules/RME/Services/DoctorRoomScopeService.php` | **NEW** — single authority for room scope + print denial + audit |
| `app/Modules/ClinicVisit/Services/ClinicVisitService.php` | `doctorVisitScope()` composes the room scope |
| `app/Modules/ClinicVisit/Policies/ClinicVisitPolicy.php` | Room guard on `view`/`update`/`transition`/`print`/`completeExamination`; print denial on `print` |
| `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` | Drops Doctor room/branch filters; audits real denied attempts |
| `app/Modules/Odontogram/Policies/OdontogramPolicy.php` | Room guard + print denial |
| `app/Modules/Odontogram/Controllers/OdontogramController.php` | Audits denied print |
| `resources/views/rme/visits/room-worklist.blade.php` | Room selector hidden for a scoped Doctor |
| `tests/Feature/RME/DoctorRoomScopedAccessAndPrintDenyTest.php` | **NEW** — 26 tests |

**No migration. No new permission. No new route. No schema change.**
`MedicalRecordPolicy` is deliberately untouched: `MedicalRecordController@show`
authorises `view` on the **opened** visit before the Sprint 64.0 canonical
redirect, so the RME surface is covered without disturbing the patient-centric
workspace resolver.

---

## 4. Preserved foundations

Consent gate, room gate (`visit.room`), doctor→cashier completion gate,
full-payment-only, patient-centric RM workspace, historical/legacy immutability,
branch isolation (`BranchContext` / `RmeWorkingBranchScope`), KTP/NIK privacy,
Sunu `SPN4` branch codes, Perawat / Admin Klinik / Kasir / Supervisor RME / Owner
behaviour — all unchanged.

---

## 5. Known finding left untouched

`GET rme/online-context/rooms` may disclose room metadata cross-branch for an
arbitrary `branch_id`. It was mapped and is **not** on the trusted room-selection
critical path for this sprint: Phase 1 never reads a room from the request, it
reads the doctor's room from the server-side online context. Per §22 it is
recorded, not half-fixed, and is not bundled into this sprint.
