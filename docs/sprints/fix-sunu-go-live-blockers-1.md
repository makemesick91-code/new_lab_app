# FIX-SUNU-GO-LIVE-BLOCKERS-1

Closes the blockers found by `DAENGTISIAMS-SUNU-GO-LIVE-READINESS-1` while
gating the Cabang Sunu (SPN4) go-live.

- **Branch:** `fix/sunu-go-live-blocker-closure-1`
- **Base:** `36e07c8525c5b237a0ff15b3b79cec285287bc86` (tree `ff66d98c`) — the exact deployed production runtime
- **Scope:** Sunu go-live only. Arms no flag, widens no scope, retires nothing.

## What did not change

`doctor.single_active_session` stays `true`. `doctor.branch_lock` stays
`false`. Enforcement stays `pilot` on cohort `[9,15,18]`, global permitted
`false`. Android and browser login stay available for the 12 non-cohort
doctors. Stage 4A/4B, Android retirement and fleet PWA-only all remain
deferred. No migration, no route, no permission, no seeder.

---

## B5 — a visit with no clinical record could be billed

**Root cause.** `RmeInvoiceService::create()` enforced `cashier_pending`
correctly, then read:

```php
if ($medicalRecord && $medicalRecord->status !== MedicalRecord::STATUS_FINAL) { … }
```

It judged a record that EXISTED and skipped the check entirely when there was
none, writing the invoice with `medical_record_id => null`. Sprint 20 already
stated the rule — *"Cashier billing requires finalized RME + `cashier_pending`
visit"* — so this was a deviation from the project's own documented invariant,
not a new requirement.

Reaching `cashier_pending` does not itself write a record, so the state was
reachable through the ordinary workflow. **SPN4 visit 52** sat in exactly that
shape for twelve days — signed consent, draft odontogram, zero records — in the
Sunu cashier queue, billable.

**Fix.** Fail closed on null: `$medicalRecord === null || status !== FINAL`.
`medical_record_id` is now non-null by construction.

**Blast radius, measured on production before changing anything:** all 28
invoices already carry a `medical_record_id`, none references a non-final
record, and exactly one visit in the whole database would newly be refused —
visit 52. Zero regression; the only thing blocked is the thing that should be.

**Visit 52 disposition:** owner decided staff are briefed to leave it alone. It
is NOT edited, cancelled or completed by this sprint — `cashier_pending` can
only advance to `completed`, and fabricating a medical record to clear it was
explicitly refused.

## B6 — revoking break-glass locked the doctor out completely

**Root cause.** `DoctorBreakGlassService::revoke()` updated the grant and wrote
its audit row, and stopped there. Revocation denies the next request, but the
session lease the emergency window created stayed open — and under
single-session an open lease is an incumbent, so the doctor could no longer log
in by ANY path.

**This is observed, not theoretical.** The 2026-09-18 production drill revoked
its grant after 72 seconds; lease 13 stayed open until a Super Admin
force-released it at 07:42 the next morning — roughly eighteen hours.

**Fix.** `revoke()` now releases the lease ITS OWN grant created, inside the
same transaction, by composing `DoctorSessionReleaseService::releaseForDoctor()`
(so the clinic room is freed and the approver audit row is written by the
service that owns that behaviour). The audit payload gains
`session_lease_released`.

**Scoped deliberately** — logging out a session the grant never created would
be worse than the bug:

| Situation | Behaviour |
|---|---|
| Grant used, lease claimed inside the window | released |
| Grant never used (`first_used_at IS NULL`) | nothing released |
| Lease claimed BEFORE `granted_at` | left alone — a different, legitimate login |
| Actor revoking their own grant | skipped, not refused — revocation must never become impossible |
| No open lease | no-op, revocation still succeeds |
| Revoked twice | idempotent; the first revocation is the one that happened |

### B6 — what adversarial review changed, and what it did not close

An independent review REFUTED the first implementation and the fixes below came
from it, all measured rather than argued:

- **Regression I introduced.** With a Doctor-role account that has no linked
  `mst_doctors` row, `releaseForDoctor()` throws, the nested savepoint unwinds,
  and the whole revocation rolls back — leaving a **live, unrevokable emergency
  grant** *and* the lease. That is strictly worse than the bug being fixed and
  violates this sprint's own rule that revocation must never become impossible.
  Now: the refusal is caught and the release falls back to the `user_id`-keyed
  `DoctorSessionLeaseService::releaseFor()`, which is the column the lease is
  actually keyed on. Regression-tested.
- **Second-precision boundary.** `claimed_at` and `granted_at` are both
  `timestamp(0)`. The comparison stays strict `lt` deliberately: a lease claimed
  in the same second as the grant is the emergency one, and `lte` would skip the
  very lease this exists to release.

**KNOWN GAP, recorded not closed: expiry releases nothing.** A grant left to
EXPIRE never reaches `revoke()`, so the lease survives and the original lockout
recurs. No expiry→release coupling exists, and there is no running scheduler to
hang one on (B3). Operational mitigation: end an emergency window by revoking
it explicitly, and use `doctor:session-force-logout` for an already-stranded
lease.

## B2 — both documented restore procedures were broken

The backups were always sound; nothing written down could restore them.

- `restore_postgres.sh` called `pg_restore --clean` on plain-text dumps
  (`ASCII text`, zero `DROP`s) and defaulted to `asia_dental_lab` / `postgres`,
  neither of which exists here.
- `restore-rehearsal.sh` (ENT-12) failed twice: `createdb` as the app role is
  denied, and the restore then dies on a superuser-only
  `CREATE EXTENSION pg_stat_statements`.

**Measured, not assumed:** pre-creating the extension as superuser and
restoring as the app role ALSO fails — the following `COMMENT ON EXTENSION`
requires ownership, and the restore stopped after 2 tables. The canonical
pattern is therefore to restore as the superuser with the dump streamed on
stdin (dumps are `0640 daengtisiams`, so `postgres` cannot open the path but
can read a pipe). The app role is never granted superuser.

Both scripts rewritten: `psql -v ON_ERROR_STOP=1 --single-transaction`,
`set -euo pipefail`, env parsed key-by-key (never sourced), production target
refused without an explicit flag plus typed confirmation, `PIPESTATUS`
inspected so a psql failure is not masked by the reader, post-restore row
verification, success printed only on real success.

**Rehearsal evidence (VPS, disposable DB, real backup):** exit 0 — 151 tables,
48 patients, 39 visits, 36 payments, ~2s. A deliberately corrupted dump exits
1 with the psql status surfaced. Production guard refuses with exit 2. Scratch
databases dropped via `trap`; none left behind.

Note: `storage/release-evidence/latest/restore-rehearsal.json` does not exist
on production — the ENT-12 DR drill had never successfully completed on this
host, which is consistent with the defects above.

Two further defects found by review and fixed, both **measured**: the
production guard was bypassable by pointing `ENV_FILE` or `APP_DIR` at a file
that named a different database (the caller defined what counted as
production), and `--target` was interpolated unquoted into a `pg_database`
probe, executing arbitrary SQL as the superuser. The canonical production name
is now read from the checkout the script lives in and cannot be overridden, and
target names are restricted to `[A-Za-z0-9_]`. Verified: both bypass routes and
the injection now exit 2.

## B3 — there was no scheduled database backup at all

No root crontab, no `daengtisiams` crontab, nothing under `/etc/cron.*`,
`/etc/systemd/system` or `/var/spool/cron` invoking `pg_dump`,
`backup-vps.sh` or `schedule:run`. The one weekly timer is a *performance*
snapshot that never touches the database. Every dump on the box was a side
effect of deploying.

**Laravel's scheduler is registered but never fires** — two tasks appear in
`schedule:list` and nothing invokes `schedule:run`. A backup added there would
look scheduled and silently never run.

**Added** (versioned, not installed by this PR):
`deploy/systemd/daengtisiams-db-backup.service` (oneshot, `User=daengtisiams`,
never root) and `.timer` (`OnCalendar=*-*-* 19:00:00` = 03:00 WITA,
`Persistent=true`). Both pass `systemd-analyze verify` rc=0.

**Runs the script directly, not through Laravel:** a backup must not depend on
the app booting, `backup-vps.sh` is already the ENT-12-verified path, and
systemd gives a real per-run exit status. Failure is visible three ways:
`systemctl is-failed`, `journalctl -u daengtisiams-db-backup.service`, and the
MON-1 latest-backup signal which WATCHes on a stale or missing dump.

## B4 — doctors are cross-branch by accepted design

Branch narrowing requires `doctor.branch_lock`, which is off, so all 15 doctors
plus Owner / Supervisor RME / Super Admin can open and bill an SPN4 visit.
Admin Klinik, Perawat and Kasir ARE server-side branch-scoped and fail closed
on an unresolved branch. No request-supplied `branch_id` can widen anyone's
scope.

The owner accepted this explicitly as the operating model (*a doctor may work
at any branch*). **Recorded as `ACCEPTED_DESIGN`, never as a branch-isolation
PASS for doctors.**

## B1 — Sunu has never taken a payment

Not closable in code. SPN4 has 0 invoices and 0 payments in its entire history,
and no clinical or financial row has been written on this runtime at any
branch. Owner sequenced it **after** this deploy, so the staff end-to-end test
exercises the fixed billing gate rather than the old one. Until then
`B1 = BLOCKED_HUMAN_GATE`.

---

## Tests

| Suite | Tests |
|---|---|
| `tests/Feature/RME/SunuBillingClinicalRecordGateTest.php` | 7 |
| `tests/Feature/DoctorAccess/DoctorBreakGlassRevokeLeaseTest.php` | 8 |

Two fixture traps worth keeping, both of which produced vacuous green first:

1. `daArmDoctorAccess()` arms the DoctorAccess flags but leaves enforcement
   scope at `pilot` and does not touch the gate's own enforcement flag. A
   doctor outside the cohort exits the gate before break-glass is consulted, so
   the login succeeds for the wrong reason and `first_used_at` is never
   stamped.
2. One test client holds one session. Logging a second doctor in while the
   first is still authenticated is a no-op redirect that claims no lease, and
   `post('/logout')` would release the very lease under assertion. Detach with
   session-row deletion + `forgetGuards()`.

## Durable rules

`.cursor/rules/162-sunu-go-live-blocker-closure.mdc` — **SGL-R1..R4**.
