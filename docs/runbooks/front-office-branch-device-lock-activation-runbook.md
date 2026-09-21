# Runbook — Front Office branch-device lock, per-account activation

**Sprint** REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1
**Applies to** exactly four approved accounts. Nothing here may be applied to any other Front Office account.
**Default state after deploy** enforcement OFF, cohort EMPTY — a deploy arms nobody.

---

## 0. Before you start

**Owner authorization is required for each account separately.** Shipping this capability did not authorize arming
it. The real-device ceremony is a separate owner decision.

**Never, at any point in this runbook:**

- arm more than one new account at a time
- arm all four at a deploy
- move a device's `branch_id` to make a login work
- revoke a real device, or create a credential, to manufacture evidence
- widen any permission, or add an account to the cohort that the owner has not named
- trust a branch id from a request

**Arming order — Admin Sunu LAST.** User 29 is the only one of the four in live daily use; the other three have never
selected a branch context. Landak (30) → Antang (31) → Telkomas (32) → **Sunu (29)**.

---

## 1. The approved cohort

| Alias | `users.id` | Required branch | Code |
|---|---|---|---|
| Admin Landak | 30 | Cabang Landak | `LDK2` |
| Admin Antang | 31 | Cabang Antang | `ATG3` |
| Admin Telkomas | 32 | Cabang Telkomas | `TLK1` |
| Admin Sunu | **29** | Cabang Sunu | `SPN4` |

`TKM1` and `SUN4` are retired aliases — do not use them. `MAIN` (4) is not RME-enabled and can never satisfy the
lock.

---

## 2. Per-account activation

Repeat all of steps A–H for **one** account. Do not begin the next account until this one has passed.

### A. Confirm the identity (read-only)

```sql
SELECT u.id, u.name, u.email, u.branch_id
FROM users u WHERE u.id = <USER_ID>;
```

Confirm the id matches the alias in §1. **Never resolve an account by display name** — names are editable and can
collide.

### B. Identify the real branch-owned device (read-only)

```sql
SELECT d.id, d.device_name, d.branch_id, b.code, d.status, d.identity_state, d.enrollment_status
FROM mst_doctor_devices d JOIN mst_branches b ON b.id = d.branch_id
WHERE b.code = '<BRANCH_CODE>' ORDER BY d.id;
```

The device must be `status=active`, `identity_state=cryptographically_verified`,
`enrollment_status=verified`. If none is, **stop** — register and enrol the hardware through the normal device
workflow first.

### C. Enrol the front-desk credential ON that tablet

At the **front-desk tablet's own browser**, signed in as an administrator authorized by the `DoctorDevice` policy:

`Settings → Doctor Devices → <device> → WebAuthn` (`settings.doctor-devices.webauthn.create`)

This is the existing, device-scoped registration path — no doctor record is involved and this sprint added no
enrolment surface. The platform authenticator must produce a **device-bound** credential; a syncable passkey is
rejected by policy because it can leave the tablet.

### D. Prove credential → device → branch (read-only)

```sql
SELECT c.id, c.doctor_device_id, c.device_bound_verdict, c.revoked_at,
       d.branch_id, b.code
FROM trx_doctor_device_webauthn_credentials c
JOIN mst_doctor_devices d ON d.id = c.doctor_device_id
JOIN mst_branches b ON b.id = d.branch_id
WHERE c.doctor_device_id = <DEVICE_ID> AND c.revoked_at IS NULL;
```

Require: `device_bound_verdict = device_bound`, `revoked_at IS NULL`, and `b.code` equal to the account's required
branch. If the code does not match, **stop** — the mismatch is the thing the lock exists to catch.

### E. Arm only this account

On the VPS:

```bash
# Add exactly ONE pair. Keep pairs already armed; do not replace the list wholesale.
FEATURE_FRONT_OFFICE_BRANCH_DEVICE_LOCK=true
FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT=<USER_ID>:<BRANCH_CODE>[,<already armed pairs>]

php artisan config:clear
```

Sanity checks before the operator tries to log in:

- the cohort contains **only** owner-approved ids, and **no more than four**
- each id appears **once** (a duplicate — even an agreeing one — denies that account)
- each branch code is one of `TLK1` `LDK2` `ATG3` `SPN4`

### F. Real correct-device login

At the enrolled tablet: password → the device verification page → touch the sensor.

Expected: login succeeds, and the session is bound to that device server-side.

### G. Confirm the branch context is locked

- The workspace resolves to the account's own branch.
- A branch switch is **refused server-side**, not merely hidden. A crafted
  `POST /rme/online-context/admin-clinic` with another `branch_id` must fail validation and write no context row.

### H. Confirm revalidation

With the operator logged in, disable the device (normal device workflow, then re-enable afterwards). The next request
must end the session and return to the login form. Then re-enable the device and have the operator log in again.

**Only now proceed to the next account.**

---

## 3. Wrong-device evidence, without disrupting another branch

Do **not** carry a tablet between branches, and do **not** re-branch a device to produce a refusal.

The wrong-branch denial is already proven by automated adversarial evidence against real endpoints with real EC keys
(`tests/Feature/FrontOfficeDevice/FrontOfficeDeviceLoginCeremonyTest` — *"refuses Admin Sunu holding a credential
enrolled on the Landak tablet"*, plus forged-signature and replay refusals). A safe production check is to read back
the decision inputs (§B, §D) and confirm the codes differ.

If the owner wants a live wrong-device demonstration, it must be scheduled on a branch that is **not** operating, and
never by mutating device ownership.

---

## 4. Rollback

| Goal | Action | Effect |
|---|---|---|
| Disarm **one** account | Remove that pair from `FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT`, `php artisan config:clear` | That account logs in as before. Other armed accounts unaffected. |
| Disarm the capability | `FEATURE_FRONT_OFFICE_BRANCH_DEVICE_LOCK=false`, `php artisan config:clear` | No Front Office login is refused; the branch pin stops applying. |

Either way **no data is touched**: devices, their branch ownership and every credential stay exactly as they are, so
re-arming needs no re-enrolment. Existing device-bound sessions simply stop being device-checked.

An armed session already open is torn down on its **next** request, never in place.

---

## 5. Symptoms and causes

| Operator sees | Cause | Fix |
|---|---|---|
| "Perangkat ini belum terdaftar atau tidak diizinkan…" | No credential on this browser, or the bound device is not approved | Enrol at §C, or check device status at §B |
| "Akun Front Office ini hanya dapat digunakan pada perangkat Cabang X." | Right account, wrong branch's tablet | Use that branch's own tablet. **Do not** re-branch the device. |
| "Konfigurasi cabang untuk akun Front Office ini belum valid…" | Cohort entry duplicated, off-policy, oversized; or the branch is inactive/non-RME | Fix the cohort at §E, or the branch record |
| "Akun Front Office ini terkunci pada cabangnya sendiri…" | A branch switch was attempted | Expected. The lock is working. |
| Device page says the browser is unsupported | Not a secure origin, or no platform authenticator | Open the official HTTPS address on the tablet |

Every refusal is audited as `FRONT_OFFICE_DEVICE_BRANCH_LOGIN_DENIED` in `sys_audit_logs` with a structured reason,
the required and actual branch codes, and the device id — and never a credential, token or password.

---

## 6. Standing limits

- These four accounts only. A fifth requires an explicit owner scope update; adding one anyway exceeds
  `max_cohort_size` and fails **closed** for everyone armed.
- The other four Front Office accounts (7, 8, 16, 17) and every other role stay unchanged, armed or not.
- Role-wide Front Office enforcement is a source-controlled `false` and is not reachable from the environment.
