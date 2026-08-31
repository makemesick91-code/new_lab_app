# FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1

**Branch:** `feature/doctor-account-performance-income-linkage-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `8b3468e` (production authority, tag `revision-telkomas-branch-code-tkm1-to-tlk1-1-go`)
**Type:** MODULE_SPRINT · **Module:** Doctor
**GO tag:** `feature-doctor-account-performance-income-linkage-1-go`

---

## 1. What the audit found

The task began as an audit of the chain:

```
AKUN LOGIN DOKTER → MASTER DATA DOKTER → KINERJA DOKTER → PENDAPATAN DOKTER
```

| Question | Finding |
| --- | --- |
| Does a User ↔ Doctor mechanism exist? | **Yes.** `mst_doctors.user_id` — nullable FK to `users`, **unique**, `nullOnDelete` (migration `2026_06_29_120002`, Sprint 66.0). |
| How does Kinerja determine doctor identity? | `DoctorPerformanceReportService::resolveAccess()` → `Doctor::where('user_id', $user->id)`. IDOR-safe, forces the doctor's own id. |
| How does Pendapatan determine doctor identity? | **The same feature.** "Kinerja & Pendapatan Dokter" is one report (`rme.reports.doctor-performance`); income is the `trx_rme_payments` sum for that doctor's visits. |
| Is there a UI to link an account to a doctor? | **No.** |

### Why there was no UI

`user_id` is `$fillable` on the model, but:

- `StoreDoctorRequest` and `UpdateDoctorRequest` do **not** validate `user_id`, so `validated()` strips it — the doctor form cannot set it;
- `resources/views/settings/doctors/_form.blade.php` has no account field;
- unlike technicians (`lab:technician-link-user`) there was **no CLI command** either.

So the link was **unsettable through the application**. `DoctorPerformanceAccessAuditCommand` even instructs the operator to *"Link the affected doctor accounts via master data (do NOT auto-link)"* — pointing at a control that did not exist. The only way to link a doctor was direct SQL on production.

**Classification: CASE B** — the backend relation exists and is correct; the management UI was missing.

### A second finding: email was runtime authority

`DoctorUserResolver::resolveForUser()` fell back to case-insensitive **email matching** when `user_id` was null:

```php
return Doctor::query()->where('is_active', true)
    ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])->first();
```

That resolver is not cosmetic. It feeds:

- `DoctorPatientScopeService` — **which patients, visits and medical records a doctor account may open**;
- `UserOnlineContextService::startDoctorSession()` / `resolveDoctorForRoom()` — **which doctor a visit is attributed to**.

So a coincidental or re-used email address could stand in for clinical identity. Production measurement at the time of this sprint: **5 doctors, 2 linked, 3 unlinked, 0 users holding the `Doctor` role, and 2 doctors whose email matched a user account** — latent, not yet exploited, and closable without breaking anyone.

---

## 2. What was built

### Canonical identity — `App\Modules\Doctor\Services\DoctorIdentityResolver`

The single answer to *"which doctor is this logged-in user?"*, resolved strictly from `mst_doctors.user_id`. It answers **identity only**; whether the doctor may practise now (`is_active`), from which branch, and what they may see remain separate decisions owned by their existing callers, so no consumer's policy changed.

- `DoctorUserResolver` now delegates to it — **the email fallback is gone** — while keeping its `is_active` filter, so the online-context flow behaves exactly as before for linked doctors and now fails closed (with the existing *"Akun dokter belum terhubung ke data master dokter"* message) instead of silently resolving by email.
- `DoctorPerformanceReportService` now resolves through the same service, so kinerja and pendapatan can never drift onto a different notion of doctor identity than the rest of the system.

### Management UI — Master Data RME → **Relasi Akun Dokter**

| | |
| --- | --- |
| Route | `settings.doctors.account-links.{index,store,destroy}` (`/settings/doctors/account-links`) |
| Controller | `DoctorAccountLinkController` (thin; re-checks the policy so the route is never the only guard) |
| Request | `LinkDoctorAccountRequest` (shape only) |
| Service | `DoctorAccountLinkService` (every eligibility rule + the audited write) |
| Policy | `DoctorPolicy::manageAccountLink` |
| Permission | **`manage_doctor_account_links`** (new) |
| View | `settings/doctors/account-links/index.blade.php` |

The route is declared **before** the `doctors` resource so the static `account-links` segment is never shadowed by a wildcard doctor route — the same precedent as the legacy patient import.

**Why a new permission rather than reusing `manage doctors`:** in production `manage doctors` is held only by Super Admin, so reuse would have been safe *today*. But a wrong link exposes another doctor's income, and it is entirely foreseeable that someone later grants `manage doctors` to a clinic admin so they can maintain the doctor list — which would silently also hand them the power to redirect income visibility. The two capabilities are separated so that cannot happen by accident. `manage_doctor_account_links` is granted to **no** role in `RoleSeeder`; Super Admin reaches it through the existing `'*'` grant and `Gate::before`.

### Link rules (all enforced server-side, in one locked transaction)

- account must exist, be **active**, and already hold the **`Doctor`** role — the role is a prerequisite, never a side effect;
- one account → at most one doctor; one doctor → at most one account (also guaranteed by the DB unique index);
- replacing an existing link requires explicit confirmation (`confirm_relink`) — a link is never silently overwritten;
- unlink preserves the doctor record, the account, and all clinical and financial history; only self-service access is withdrawn;
- every link/relink/unlink writes `DOCTOR_ACCOUNT_LINK` / `_RELINK` / `_UNLINK` to `sys_audit_logs` with **technical identifiers only** — no name, email or financial detail.

Nothing in this sprint touches a visit, medical record, invoice, payment or earning row, and no income formula was changed. Linking decides **who the doctor is**; it does not redefine **how** kinerja or pendapatan is calculated.

---

## 3. How to use it

1. Log in as **Super Admin** (the only holder of `manage_doctor_account_links`).
2. Sidebar → **Master Data RME** → **Relasi Akun Dokter**.
3. Find the doctor (search by name/code, or filter *Belum terhubung*).
4. Pick the account from **— Pilih akun —**. Only active accounts that hold the `Doctor` role and are not already linked are offered.
5. Press **Hubungkan**. To replace an existing link, tick **Ganti akun** first, then **Ganti**.
6. Verify: that doctor's account can now open **Kinerja & Pendapatan Dokter** and sees only their own figures.

If the dropdown is empty, the account does not yet exist, is inactive, lacks the `Doctor` role, or is already linked — fix that in **Pengguna** / **Peran** first. Linking will never create or elevate an account for you.

To check the estate at any time: `php artisan rme:doctor-performance-access-audit --strict`.

---

## 4. Deploy

No migration (the column has existed since Sprint 66.0) and no role change. After deploy:

```bash
php artisan db:seed --class=PermissionSeeder --force   # registers manage_doctor_account_links (idempotent)
php artisan permission:cache-reset
php artisan rme:doctor-performance-access-audit        # read-only estate check
```

Production doctors stay **unlinked** until an administrator links them deliberately. No account is auto-linked, and no historical row is rewritten.
