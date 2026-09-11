# DOCTOR-ACCESS-PR-B-BRANCH-LOCK-COVER

**The second of three children** of DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1.
**Runtime authority this builds on:** PR-A merge `80afaad1`, deployed and production-verified.
**Rules:** `docs/architecture/doctor-branch-lock-and-cover.md` (DBL-R001..R025).
**Operations:** `docs/runbooks/doctor-branch-lock-operations.md`.
**Capability flag:** `doctor.branch_lock`, risk `critical`, committed **false**.

The parent GO tag is **not** created here. It is created after all three children are
merged, deployed and production-verified, plus the real-device ceremony.

---

## 1. What ships

One server-authoritative branch per doctor, derived on every request, changed only by an
approval that a second person signs off.

### Runtime

Four additive migrations. `mst_doctor_branch_locks` (UNIQUE `doctor_id`, and also the
serialisation point for cover approval), `trx_doctor_branch_lock_requests` (partial unique on
the pending state), `trx_doctor_branch_covers` (two partial unique indexes), and a fourth
that adds `effective_branch_id` and `effective_cover_id` to `trx_doctor_session_leases` with
**no backfill**.

`DoctorEffectiveBranchResolver` is the one answer to "which branch is this doctor in right
now": an approved cover that is current, else the home lock, else null. Two approval services
own the writes — `DoctorBranchLockApprovalService` for assignment and transfer,
`DoctorBranchCoverApprovalService` for cover — and nothing else writes those tables.

Two consumers, each asking the resolver exactly once: the list scope
(`RmeWorkingBranchScope::resolve()`) and the write chokepoint
(`ClinicVisitService::resolveBranchId()`). `EnsureDoctorSessionLease` records the effective
branch at claim time and compares it on every protected request, which is what makes cover
activation, cover expiry and an approved transfer one mechanism with no scheduler in the
correctness path.

### The four permissions

`view_doctor_branch_locks`, `manage_doctor_branch_locks`, `approve_doctor_branch_locks`,
`release_doctor_session_leases`. Supervisor RME holds all four; Super Admin reaches them
through the single global bypass. **No doctor holds any of them**, and a doctor can still file
their own home-branch request because the policy matches on `mst_doctors.user_id`.

### The surface

Twelve routes under `rme.doctor-branch-locks.*` and `rme.doctor-branch-covers.*`: file,
cancel, the approver queue, approve, reject, and the session release. Every route 404s while
the capability is off.

---

## 2. The first obligation, discharged

PR-A shipped `EnsureDoctorSessionLease` as globally appended `web` middleware and verified the
registration **by reading `bootstrap/app.php`**. That is not a regression guard, and PR-A
recorded it as a gap assigned here.

`DoctorSessionLeaseMiddlewareRegistrationTest` closes it with six cases that ask the ROUTER
for the resolved group. Three things about it are worth keeping:

- **The HTTP kernel must be resolved first.** In a console context the router's middleware
  groups are not populated until the kernel is built, so a bare `app('router')` call reports
  an empty `web` group and every assertion would pass vacuously. The first case asserts the
  group is non-empty precisely so that failure mode cannot hide the others.
- **`route:list` structurally cannot see this middleware.** `gatherMiddleware()` returns the
  raw action entries and a group's members are never expanded into them, so counting its
  output proves nothing in either direction. A case pins that constraint too: the middleware
  must never appear on any route, because route-attaching it would trip an existing
  device-middleware contract.
- **The source-level count is the load-bearing witness, and measurement proved it.** A double
  registration was injected into `bootstrap/app.php` and the resolved group **still** reported
  exactly one entry: Laravel de-duplicates a middleware group. Only the source check failed.
  So the runtime risk of an accidental second append is smaller than it looks, and a single
  witness here would have been another unproven guard.

---

## 3. Tests

64 new cases. 123 passed / 2886 assertions across `tests/Feature/DoctorAccess`.

| file | cases | what it defends |
|---|---|---|
| `DoctorHomeBranchLockTest` | 17 | what a locked doctor sees and may write |
| `DoctorBranchApprovalTest` | 43 | the three workflows, maker-checker, cover semantics |
| `DoctorBranchLockGovernanceTest` | 8 | permissions, classification, the flag, the degraded case |
| `DoctorSessionLeaseMiddlewareRegistrationTest` | 6 | the PR-A gap above |

### Four cases that are ACCEPTANCES, and why that matters

A refusal test can only prove something is forbidden. These prove something ordinary is still
**allowed**, which is the failure mode a suite full of refusals cannot see: an over-strict
guard passes every refusal test in the file and takes the clinic down on a normal Tuesday.

- **Adjacency.** A cover starting exactly where the previous one ends is accepted — the whole
  content of `[starts_at, ends_at)`, and the most ordinary pair of covers an approver files.
- **Per doctor.** Two different doctors hold covers over the same instants for the same target
  branch.
- **After expiry.** A new cover is accepted once the previous period has passed, with the old
  row still present and still APPROVED.
- **Eviction, through HTTP.** Working before the approval, redirected to login on the very
  next request after it, then logging in again and landing on the new branch.

### The adjacency case was wrong when first written

Overlap is two comparisons, `starts_at < :ends AND ends_at > :starts`. Filed in chronological
order only one of them decides anything, so relaxing the other to `<=` left the case passing.
Measured, not reasoned about. The case now files a second pair in **reverse** order, which
puts the other comparison in the deciding position, and both relaxations are confirmed to fail
it.

### Mutation battery — 13 applied, 13 killed, 0 survived

Each applied by copy and reverted by copy, each verified to have actually changed the file
before its result was banked.

| mutation | killed by |
|---|---|
| a second `resolve()` per protected request | the query budget (delta 8 → 13) |
| cover overlap `starts_at '<'` → `'<='` | adjacency |
| cover overlap `ends_at '>'` → `'>='` | adjacency |
| overlap query drops `doctor_id` | two doctors, same hours |
| overlap query drops the period predicate | a new cover after expiry |
| middleware tolerates a revalidation denial | the evicted session |
| maker-checker invariant disarmed | five cases |
| operational scope replaces instead of intersects | the hybrid account |
| effective-branch write guard removed | three cases |
| transfer-through-active-cover guard neutralised | two cases |
| identity revoked as part of a branch change | the device trail |
| rejection reason made optional again | the reason case |
| lease partial index flattened | two lease cases |

`MUTATIONS_APPLIED=13  MUTATIONS_KILLED=13  MUTATIONS_SURVIVED_ACTIONABLE=0`

---

## 4. Two corrections to PR-A's own claims

Both are inherited assertions that PR-B's arrival makes wrong. Neither was deleted.

### The query budget was an understatement

PR-A published a ceiling of 6 added queries against a measured 3. Correct for the lease engine
— and not a statement about production, because the shipped capability arms **two** flags and
that test re-armed only one after its disarm, so the resolver never ran inside the measurement
it published.

Re-measured with both armed: **8**. The enumeration is the schema probe, the doctor identity,
the active cover, the home lock, the branch-health read, the lease select and the drifting-
session update. The test now asserts three things instead of one — the lease-only leg at PR-A's
unchanged ceiling, the whole capability at a ceiling of 12, and the **increment** between them,
so a resolver regression cannot hide in the headroom. Every branch table is pinned to at most
one read per request, which is real rather than trivially true: the resolver does not memoise,
so a second consumer shows up immediately.

### The flag-absence pin is inverted, not removed

PR-A pinned `doctor.branch_lock` as absent from the registry, on the rule that a flag
registered ahead of its runtime promises something nothing implements. This pull request ships
that runtime. What stays load-bearing is that the two are **separate keys**: one switch serving
both capabilities would make DBL-R002 unexpressible.

---

## 5. A defect found only because the migration was run both ways

Adding a foreign-key column makes SQLite rebuild `trx_doctor_session_leases`, and Laravel
re-creates that table's indexes from introspection **without the predicate**. PR-A's
`UNIQUE(user_id) WHERE released_at IS NULL` was flattened to a plain unique on `user_id`, which
would make every doctor's second login after any logout raise a unique violation — **on SQLite
only, invisible to a green PostgreSQL gate**.

The migration now re-asserts the index with its predicate in both `up()` and `down()`, verified
across a full round trip: predicate intact after adding the columns, after rolling back, and
after re-migrating; a released plus an unreleased lease for one doctor both accepted; a second
unreleased one refused.

One correction to my own account of this, recorded because it was reported before it was
checked: I initially reported that the rollback failed and left the schema damaged. That was
caused by probe rows inserted with foreign keys disabled. On a clean schema the rollback
succeeds, drops both columns and preserves the predicate.

---

## 6. HONEST LIMITS

### Concurrency is proven on PostgreSQL only

`lockForUpdate()` compiles to an empty string on SQLite, so the local suite proves the ordering
logic and only the PostgreSQL critical gate proves the serialisation. The overlap invariant
cannot be expressed as an index on either engine — measured by attempting the write: identical
periods were rejected, merely overlapping ones were accepted.

### The pilot cohort is not asserted in a test, deliberately

Owner requirement "existing pilots [9,15,18] regression" is a **production verification** item,
not a unit test. Those ids are host values; pinning them in a test would assert somebody's
environment rather than this diff, and would fail on any checkout configured like production.
What is asserted is that this pull request **wrote no cohort**, and the cohort is verified on
the deployed host during the ceremony.

### What only a real-device ceremony can prove

That an approver reading the queue on a real screen understands what approving will do to a
doctor who is online. That the degraded-lock row is legible to whoever has to act on it. That a
doctor evicted by an approval sees a sentence they can act on rather than a blank login form.

### Deliberately not delivered

The bulk device-authorization tool (PR-C). Any change to global doctor WebAuthn enforcement,
which stays false. Any backfill. Any narrowing of per-record reads or the legacy archive.

### Full Suite

`FULL_SUITE_EXECUTED=NO`. `FULL_SUITE_RESULT=SKIPPED`. `FULL_SUITE_CLAIMED_PASS=NO`.

One Full Suite runs after all three children are merged, deployed and production-verified, on
the final immutable tree, and it gates the parent tag alone. **This child skip must never be
read as a parent pass.**

---

## 7. Handover to PR-C

- **Migration slots.** PR-A holds `2026_09_10_100001`; PR-B holds `100002`–`100005`. PR-C adds
  no table.
- **What PR-C adds:** `DoctorDeviceBulkAuthorizeCommand`,
  `DoctorDeviceBulkAuthorizationService` and `DoctorDeviceBulkAuthorizationPlan`, plus the four
  governance cases for them that were deliberately left out of
  `DoctorBranchLockGovernanceTest`.
- **What PR-C must not do:** create a WebAuthn credential, mutate a branch lock, revoke an
  existing authorization as part of synchronisation, touch a feature flag, or widen the pilot
  scope. Dry-run is the default and the apply step stops for an explicit operator approval
  showing the exact row delta.
- **The source-file scan already covers it.** `DoctorSessionLeaseGovernanceTest`'s file
  enumeration walks the whole `Modules/DoctorAccess` tree recursively, so PR-C's files are
  inside the enforcement-flag scan and the sprint-boundary pin the moment they are added. PR-C
  adds the command path to that list.
