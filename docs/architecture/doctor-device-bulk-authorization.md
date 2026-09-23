# Bulk device authorization — the trusted-tablet matrix

> DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C.
> Rule mirror: `.cursor/rules/155-doctor-device-bulk-authorization.mdc`.
> Sprint record: `docs/sprints/doctor-access-pr-c-bulk-device-authorization.md`.

## What this solves

A doctor may authenticate from a clinic tablet only when three independent
things are true at once:

1. the **device** is trusted hardware — admitted, and it has proved possession
   of its private key;
2. a **`DoctorDeviceAuthorization`** exists for *that doctor* on *that device*
   and is ACTIVE;
3. the **login** carries a device-bound cryptographic proof.

Layer 2 is per-doctor by design, because one clinic tablet legitimately serves
several doctors. Populating it through the approval inbox is one decision per
cell of a matrix: fifteen doctors and three tablets is forty-five decisions, and
a missed one is a doctor refused at a chairside at 08:00.

This is the tool that closes that matrix in one reviewed action. It changes
ergonomics, not authority — an operator holding
`manage_doctor_device_authorizations` can already approve every one of these
rows by hand.

## The rule it enforces

> Every ELIGIBLE ACTIVE doctor is explicitly authorized on every ELIGIBLE
> TRUSTED CLINIC DEVICE, with **exactly one ACTIVE authorization per pair**.

"Exactly one" is not an application promise. `mst_dd_authorizations_pair_unique`
is an **unconditional** `UNIQUE(doctor_id, doctor_device_id)`, so a second row
for a pair cannot exist at any status. Duplicate ACTIVE rows are structurally
impossible; concurrency cannot produce one.

## Branch independence

Device trust and branch authority are **separate boundaries**, and the tool never
intersects them.

A doctor home-locked to one branch is still authorized onto a tablet in another.
Production already depends on this: drg Karmila is home-locked to SPN4 and holds
an ACTIVE authorization on an ATG3 tablet, approved 2026-09-11. A tool that
required `doctor.branch == device.branch` would have deprovisioned her.

Branch is carried into the report so an operator can see where a tablet lives.
It is never a filter. `BranchContext` is deliberately not read anywhere in this
surface: in a fleet-wide console tool it would scope the estate to the operator's
own branch and under-provision everyone else — a failure that looks exactly like
success.

## Eligibility

### A doctor is eligible when

| Predicate | Reason code when it fails |
|---|---|
| holds the `Doctor` role | (not in the population at all) |
| has a linked `mst_doctors` row | `doctor_record_not_linked` |
| `users.is_active` is true | `user_account_inactive` |
| `mst_doctors.is_active` is strictly `true` | `doctor_record_inactive` |

The population is the readiness engine's, reused rather than re-derived — three
divergent doctor-set predicates already exist in this estate and disagree.

`user_account_inactive` is **this tool's own code, with no readiness
equivalent**, and that is deliberate. `doctorAccounts()` does not filter
`users.is_active` and the readiness engine only inspects the doctor row, so today
a deactivated user with an active doctor record reports READY. Provisioning a
tablet for an account that cannot authenticate is noise; naming the exclusion
closes a documented blind spot rather than inheriting it.

Being branch-UNSET is **not** an exclusion. Fourteen of fifteen production
doctors are UNSET; a tool that required a home lock would provision almost
nobody.

### A device is eligible when

| Predicate | Reason code when it fails |
|---|---|
| `status = active` | `device_pending_approval` / `device_disabled` / `device_revoked` |
| `identity_state = cryptographically_verified` | `device_identity_not_cryptographically_verified` |
| `branch_id` is present | `device_not_active` |

**The bar is set by `approve()`, not by preference.** It hard-throws on a device
that is not cryptographically verified, so a WebAuthn-only tablet — legitimately
active, with no keystore key, and genuinely able to log a doctor in — cannot be
bulk-authorized. That is a real limitation, and the report states it rather than
hiding it.

`enrollment_status` is deliberately **not** read: `isEnrollmentVerified()` has no
call sites in the estate, so treating it as a trust input would fabricate a
signal nothing else honours.

## Classification

Every cell of `eligible doctors x eligible devices` lands in exactly one bucket.

| Bucket | State | Action |
|---|---|---|
| A `already_active` | row exists, ACTIVE | none |
| B `create` | no row | create PENDING, then approve |
| C `adopt_pending` | row exists, PENDING | approve only |
| D `blocked_rejected` | row exists, REJECTED | **nothing** |
| E `blocked_revoked` | row exists, REVOKED | **nothing** |
| F `ineligible_doctor` | doctor predicate failed | whole row excluded |
| G `ineligible_device` | device predicate failed | whole column excluded |

Plus one informational line that is not a bucket:
`EXISTING_ACTIVE_TO_INELIGIBLE_DEVICE` — ACTIVE authorizations pointing at
devices that no longer qualify. **Reported, never revoked.** Withdrawing trust is
a lifecycle decision with its own route and a mandatory reason.

### Why D and E are untouched

`resolveOrRequest()` is **not a read** when a row already exists: it routes one
into `reopenIfPermitted()`, which can transition REJECTED to PENDING and write an
audit row. A naive "call it for every pair" loop would therefore write during
what the operator was told is a preview. The tool classifies from its own
pre-read and short-circuits D and E before any service call.

`allowReRequest()` is never called either — it stamps re-request allowances,
which is mutating historical evidence, and it is a deliberate privileged human
act. The remedy is named in the report and left to a human.

Blocked pairs contribute to `BLOCKED` and `UNREACHABLE`, never to
`FINAL_EXPECTED`, and never to the digest. A tool that folded rows it cannot
touch into its own expected total would report a number it can never reach and
call the shortfall a failure on every single run.

## The approval gate

`--apply` requires `--confirm-plan=<digest>`.

The digest is a 12-hex prefix of a SHA-256 over the sorted eligible doctor ids,
the sorted eligible device ids, and the sorted bucket-B and bucket-C pair lists.
No timestamp, no randomness — two runs over an unchanged estate produce the same
value.

This binds consent to **the exact row delta** rather than to a moment. The
operator cannot apply without having read the preview and copied a value out of
it, and if the estate moves in between — a device revoked, a doctor deactivated,
another operator approving a row — the digest changes and the run fails closed.

**It is deliberately not a terminal prompt.** No command in this repository
prompts, and a Laravel prompt auto-answers with its default under a
non-interactive invocation: an SSH one-liner, a deploy script, CI. At fleet scale
that is an unreviewed write wearing the costume of a question.

## The write path

Two sequential transactions per pair, deliberately not nested into one.

```
T1  (bucket B only)  resolveOrRequest(doctor, device, actor, SOURCE_ADMIN)
    TOP LEVEL, never inside an outer transaction.
    Its own transaction, its own three-layer duplicate guard:
      findPair -> findPairForUpdate in-tx -> catch (QueryException) winner

T2  our transaction, locking in approve()'s OWN order:
      lockForUpdate mst_doctor_device_authorizations   <- first, see "Lock order"
      lockForUpdate mst_doctor_devices
      lockForUpdate mst_doctors
      re-assert active + cryptographically verified + doctor active
      approve(authorization, actor)      <- nested, under a savepoint
```

**Why T1 is never nested.** Its recovery path SELECTs inside
`catch (QueryException)`. On PostgreSQL a failed statement aborts the whole
transaction and every later statement fails until rollback; that recovery SELECT
works today only because the failing transaction is top-level and has already
rolled back by the time the catch runs. Nesting it would make correctness depend
on savepoint-abort semantics — and SQLite hides the difference completely, so a
green local test would prove nothing.

**Why T2 may nest `approve()`.** It never catches a `QueryException` and never
re-queries after one; it throws `ValidationException` only. Its internal device
`lockForUpdate()` re-acquires a lock already held on the same connection.

### The device-mutation guard

`approve()` has a second half: it promotes a `pending_approval` device to ACTIVE
and writes a `DOCTOR_DEVICE_ADMITTED` audit row. This tool must not mutate
devices at all.

The guard is **not** "we only select active devices" — that is a read, and reads
go stale. It is the re-assertion of `isActive()` **under the same row lock
`approve()` will re-acquire**, inside the same transaction. Holding that lock
makes `isPendingApproval()` provably false at the moment `approve()` tests it.

Three tests pin three different things:

- the **consequence** — every device row byte-identical across an apply, and zero
  `DOCTOR_DEVICE_ADMITTED` rows;
- the **guard** — a device flipped to `pending_approval` after the plan is built
  is refused, and the row is unchanged;
- the **premise** — an estate-wide scan proving `pending_approval` is written by
  exactly one INSERT and never by an UPDATE, so `active -> pending_approval` is
  not a reachable transition.

The premise test is the one that matters most. Without it a future sprint could
add that transition and the other two would still pass, on fixtures that never
exercise it.

### Orphan PENDING rows

A bucket-B pair whose T2 is **refused** — or a crash between T1 and T2 — leaves
the PENDING row T1 committed. Per-pair atomicity across both halves is **not**
claimed, and pretending otherwise would be the lie.

It is honest and self-healing: the row is byte-identical to what a doctor tapping
login produces, it appears in the approval inbox where a human can see it, and
the next run classifies it as bucket C and approves it once the drift that caused
the refusal is resolved.

The operator can tell: a refused pair is reported with its bucket, so
`bucket=create` beside a `refused_*` outcome is exactly the case that left an
inbox row behind.

### What the tests can and cannot prove about the locks

The suite runs on SQLite, where `lockForUpdate()` is a no-op. So the tests prove
the ORDER the locks are requested in and the re-assertion that follows them; they
do not exercise real row contention, and no test here should be read as proving
the lock itself under concurrency. The contention behaviour is a PostgreSQL
property, argued from the code and the index rather than demonstrated locally.

What IS demonstrated locally is the part that matters most for safety: the guard
refuses, and the device row is unchanged, when the estate drifts between the plan
and the write.

### Lock order

The authorization row is locked **first**, then the device, then the doctor —
matching `approve()`'s own `authorization -> device` order.

This is not a detail. Taking the device first would invert the order against
every concurrent approval coming from the inbox: one transaction holding the
authorization and wanting the device, this one holding the device and wanting the
authorization. PostgreSQL resolves that by aborting one of them, mid-run, with an
error this code does not catch.

## Provenance and audit

New rows carry `request_source = admin`, distinguishing a bulk-provisioned grant
from a doctor's own login tap. This is the constant's first consumer in the
codebase.

An adopted PENDING row keeps its own `request_source = app_login` and its
`requested_at`: it records that the *doctor* asked, and approving it must not
rewrite that into an administrative action.

One run-level audit row per apply,
`DOCTOR_DEVICE_BULK_AUTHORIZATION_RUN`, carrying the digest, the operator's
reason and the counters — scalars only, no key material and no patient data.
Every write passes an **explicit** actor: `AuditLogService` falls back to
`auth()->user()`, which from an unauthenticated console process is null, and a
forgotten actor produces an unattributable security-history row.

## What it cannot do

It never registers a device, enrols or revokes a WebAuthn credential, changes a
device status or branch, touches a home lock, a cover, a session lease, the pilot
cohort or any feature flag. It never revokes or un-rejects an authorization.

It also **does not make anyone able to log in**. A doctor still needs a
device-bound credential enrolled at the tablet under their own biometric. The
five reason codes this run cannot close — `device_not_active`,
`device_identity_not_cryptographically_verified`, `no_webauthn_credential`,
`credential_not_user_verified`, `credential_not_device_bound` — need a human at
the tablet. A bulk tool that presented partial provisioning as readiness would be
worse than one that refused.

## Nothing is automatic

There is no observer, no listener, no login hook and no scheduler. A new doctor
or a newly admitted tablet does **not** silently acquire the matrix.

The workflow for both is the same, and it is deliberate governance rather than
missing automation:

```
new doctor hired, or new tablet admitted
        -> operator runs the dry run
        -> reads the exact missing pairs
        -> runs --apply --confirm-plan=<digest>
        -> re-runs the dry run to confirm zero remaining
```

## Query budget

The plan is **constant** in fleet size: roughly nine queries whether the estate
is two doctors or two hundred. Doctors, doctor records, every authorization and
every device are each read once, and the matrix is assembled in PHP as an O(1)
lookup per cell. `findPair()` is never called in a loop. A test pins that the
count does not grow when the fleet goes from 2x2 to 5x5.

Apply then touches only buckets B and C, so the second run over a closed matrix
costs the plan alone — the idempotency guarantee is also the performance
guarantee.

Both bounds are declared in `config/doctor_access.php` and the tool **refuses**
above them rather than degrading: a bulk writer that quietly handles more than it
was reviewed for is the blast radius the dry-run default exists to bound.
