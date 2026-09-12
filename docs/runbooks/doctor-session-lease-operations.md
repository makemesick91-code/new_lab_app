# Runbook — doctor session lease operations

**Capability:** one authenticated session per doctor.
**Flag:** `doctor.single_active_session` (`FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION`), risk `critical`, committed **OFF**.
**Rules:** `docs/architecture/doctor-single-active-session-lease.md` (LEASE-R001..R024).
**Scope of this runbook:** the **lease** only. The branch lock and temporary cover are a
separate capability with their own runbook, now shipped:
`docs/runbooks/doctor-branch-lock-operations.md`.

**One thing PR-B changed that matters when you read section 3:** a third eviction reason
exists, `branch_context_changed`, and unlike the other two it DOES release the lease row
(reason `effective_branch_changed`). See LEASE-R013, which was amended rather than left to
rot. If you are diagnosing a released lease and cannot find a logout or an operator action,
an approved branch change or an expired cover is the third possibility.

---

## 1. Before you arm anything

Three preconditions, and the middle one is the one people miss.

1. **The flag is off in source control and must stay off there.** Arming is an
   environment override, never a committed default. `riskyEnabledFlags()` is pinned empty
   on every checkout the suite runs on, so a committed `true` fails CI.

2. **The session driver must be `database`.** The engine reads incumbent liveness from the
   server-side `sessions` table. On any other driver that table is never written, so the
   capability **disarms itself** (LEASE-R007) and arming the flag changes nothing. Check
   before you arm, or you will spend the window wondering why nothing is enforced:

   ```
   php artisan about --only=drivers      # the Session row must read: database
   ```

   > `about` is read-only and is already what the deploy runner calls, so it is safe on
   > production. Never reach for a REPL here: a `psyshrc` write pins the monitoring logs
   > signal to WATCH for 24 hours, and a read that costs you a day of monitoring signal is
   > not a cheap read. If `about` is unavailable, read `SESSION_DRIVER` from the deployed
   > environment file instead — still read-only, still no REPL.

3. **The permission must be seeded.** `release_doctor_session_leases` is granted to
   Supervisor RME and to Super Admin (via the `'*'` sync). Seed permissions **before**
   roles:

   ```
   php artisan db:seed --class=PermissionSeeder --force
   php artisan db:seed --class=RoleSeeder --force
   php artisan permission:cache-reset
   ```

---

## 2. Arming the flag

**Arming can refuse a doctor's login.** It belongs to a supervised window on real
hardware with a doctor present, never to a deploy.

1. Announce the window. Somebody has to be reachable who holds
   `release_doctor_session_leases`.
2. Set the override in the deployed environment file and clear the config cache.
3. Confirm the engine is actually live rather than inert:
   - a doctor logs in on tablet A → one unreleased row in `trx_doctor_session_leases`,
     audit action `DOCTOR_SESSION_LEASE_CLAIMED`;
   - the **same** doctor logs in on tablet B → refused on the login form, audit action
     `DOCTOR_SESSION_LEASE_DENIED` against the `users` entity, and **tablet A is still
     working**;
   - the doctor logs out of tablet A → `…_RELEASED`, reason `logout`;
   - tablet B now logs in → `…_CLAIMED`.
4. If any step behaves differently, **disarm first and diagnose afterwards.**

### Rollback

Set the override back to `false` and clear the config cache. The claim listener and the
per-request revalidation both return immediately: no login is refused, no session is torn
down, and every session already open stays open.

**No data is touched.** Existing lease rows stay as they are, and the partial unique index
still permits at most one unreleased lease per account — harmless while nothing claims
one. Re-enabling needs no cleanup: a stale lease whose session row is gone is reclaimed by
the next claim (LEASE-R004), and a lease genuinely stuck behind a **live** session is
cleared with the force-logout action, never with a manual `UPDATE`.

---

## 3. The audit actions, and what each one means

Read them from `sys_audit_logs`.

| Action | Entity | Means |
| --- | --- | --- |
| `DOCTOR_SESSION_LEASE_CLAIMED` | `trx_doctor_session_leases` | A doctor took a free lease. Ordinary login. |
| `DOCTOR_SESSION_LEASE_RECLAIMED` | `trx_doctor_session_leases` | The previous session's server-side row was **gone**, so it was dead and nobody was evicted. A doctor who finished on the ward tablet and walked to the office PC. |
| `DOCTOR_SESSION_LEASE_DENIED` | `users` | A second login was refused while a live session held the lease. **The first session was not touched.** No lease row exists for a denial. |
| `DOCTOR_SESSION_LEASE_RELEASED` | `trx_doctor_session_leases` | The doctor logged out. |
| `DOCTOR_SESSION_LEASE_REVOKED` | `trx_doctor_session_leases` | An operator released the lease. Carries the closed reason vocabulary (`admin_release`). |
| `DOCTOR_SESSION_RELEASED_BY_APPROVER` | `mst_doctors` | **Why** a human ended somebody else's session, in their own words, attributed to them in `performed_by`. Also records `devices_touched = false` and `webauthn_credentials_touched = false`. |
| `DOCTOR_SESSION_LEASE_EVICTED` | `trx_doctor_session_leases` or `users` | A session presented a token whose lease was gone or belonged to somebody else, and that session was torn down on **its own next request**. |

`…_REVOKED` and `…_RELEASED_BY_APPROVER` always come as a **pair** for one force logout.
A `…_REVOKED` with no partner means something wrote the lease table outside the service.

A `…_RELEASED_BY_APPROVER` carrying `session_released = false` is the honest record of a
release that found nothing to release (LEASE-R019) — not a failure.

---

## 4. Clearing a stuck lease

**Symptom.** A doctor standing in the clinic is refused at login, the trail shows
`DOCTOR_SESSION_LEASE_DENIED` for their account, and the incumbent is unreachable — a
tablet locked in a drawer, or a doctor who went home without logging out while their
session row is still live.

**First, check whether you need to do anything at all.** If the incumbent's session row
has expired, the next login reclaims it by itself (LEASE-R004/R005). Liveness is keyed on
`user_id`, so **one** surviving session row for that account keeps the incumbent alive
(LEASE-R006).

### Step 1 — look, without writing

```
php artisan doctor:session-force-logout \
  --doctor=<mst_doctors.id> \
  --actor=<your user id or email> \
  --json
```

Dry run is the default. It reports `holds_active_lease`, `lease_id`, `claimed_at`,
`last_seen_at` and whether the doctor is currently online. **A reason is not required to
look** — you should not have to decide what to write before you may read.

Exit code 0 means the preview ran, including when there is nothing to release. Any
non-zero exit is a **refusal**, and the message says which.

### Step 2 — release, with a reason

```
php artisan doctor:session-force-logout \
  --doctor=<mst_doctors.id> \
  --actor=<your user id or email> \
  --reason="Tablet bangsal terkunci di lemari, dokter menunggu di poli." \
  --apply
```

- **One doctor at a time.** There is no `--all`, no branch selector and no wildcard.
- `--actor` must be a real, **active** account holding `release_doctor_session_leases`.
  It is checked exactly as a browser surface would check it.
- `--reason` is required to write and bounded by `config('doctor_access.reason')`
  (10–1000 characters). The refusal quotes the bound.
- **You cannot release your own session** (LEASE-R016). Use the ordinary logout.
- Re-running is safe: the second run reports `released = false` and exits 0.

### Step 3 — tell the doctor the honest thing

> **The release is data. The evicted session stops on its next request, not immediately.**

There is no cross-session logout primitive in this codebase and the command does not
invent one (LEASE-R018). The doctor whose session was released keeps working until their
browser asks the server for something — for an idle tablet that can be **minutes**. Their
clinic room is freed immediately; their browser is not.

If the arriving doctor still cannot log in, the incumbent has not made a request yet.
They will be redirected to the login screen when they do.

### What force logout never does

**It ends a login session and nothing else.** No device, no `DoctorDeviceAuthorization`
and no WebAuthn credential is revoked, and none of those tables is written (LEASE-R014).
WebAuthn revocation is irreversible, so a support action taken to unstick a doctor at
08:00 must never destroy the credential on their tablet. **The doctor logs back in; they
do not re-enrol their device.**

---

## 5. Refusals you will meet, and what each one means

| The command says | It means | Do this |
| --- | --- | --- |
| `--doctor=<id> wajib diisi…` | The option was absent or not a positive integer. | Pass a `mst_doctors.id`, not a user id. |
| `Sertakan --actor=…` | No actor was named. | Name yourself. The Linux user is not an application identity. |
| `Pengguna tersebut tidak ditemukan.` | The actor id or email resolved to nobody (soft-deleted accounts included). | Check the identifier. |
| `Akun tersebut sudah tidak aktif…` | The actor account is inactive. | Use an active account. |
| `Akun tersebut tidak berwenang…` | The actor does not hold `release_doctor_session_leases`. | Ask a Supervisor RME, or check §1.3 was run. |
| `--reason="<alasan>" wajib diisi…` | Applying without a reason, or one outside the bound. | Write a sentence a colleague could act on. |
| `Data dokter tidak ditemukan.` | No such doctor, or soft-deleted. | Check the id. |
| `Dokter ini tidak aktif.` | The doctor record is inactive. | An inactive doctor's session is not cleared through this action. |
| `Akun dokter belum terhubung ke data dokter…` | `mst_doctors.user_id` is null, so there is no session to end. | Link the user to the master doctor record first. Do **not** guess at the account by name or email. |
| `Anda tidak dapat mengakhiri sesi Anda sendiri…` | Self-release (LEASE-R016). | Log out normally. |

---

## 6. Diagnosing without the command

Read-only, and safe on production.

```sql
-- The live lease for one account. There can be at most one.
SELECT id, user_id, doctor_id, claimed_at, last_seen_at
FROM   trx_doctor_session_leases
WHERE  user_id = :userId AND released_at IS NULL;

-- Why the previous ones ended.
SELECT id, released_at, released_reason, released_by_user_id
FROM   trx_doctor_session_leases
WHERE  user_id = :userId AND released_at IS NOT NULL
ORDER  BY released_at DESC;

-- Is the incumbent actually alive? Liveness is a user_id predicate; ONE row is enough.
SELECT id, ip_address, last_activity
FROM   sessions
WHERE  user_id = :userId;
```

An account with rows in `sessions` and an unreleased lease is a **live incumbent**: the
refusal is correct, and force logout is the only way past it. An account with an
unreleased lease and **no** session rows will be reclaimed by the next login all by
itself — do nothing.

**Never** `UPDATE trx_doctor_session_leases` by hand. It leaves no trail, frees no clinic
room, and produces a `…_REVOKED` with no partner row for whoever has to explain the
eviction later.

---

## 7. Related

- `docs/architecture/doctor-single-active-session-lease.md` — the rules.
- `docs/sprints/doctor-access-pr-a-single-session-foundation.md` — what was and was not
  proven.
