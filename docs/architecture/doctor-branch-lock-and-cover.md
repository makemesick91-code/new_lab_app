# Doctor branch lock and temporary cover

**Status:** shipped behind a flag that is committed OFF.
**Capability flag:** `doctor.branch_lock` (`FEATURE_DOCTOR_BRANCH_LOCK`), risk `critical`, default `false`.
**Depends on:** `doctor.single_active_session` — see DBL-R002, which is not optional.
**Sprint:** DOCTOR-ACCESS-PR-B-BRANCH-LOCK-COVER — the second of three children of
DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1.
**Runtime:** `App\Modules\DoctorAccess`.
**Operations:** `docs/runbooks/doctor-branch-lock-operations.md`.

This document describes **which branch a doctor is working in**, and nothing else. The
one-session rule is a separate capability with its own document and its own rule prefix
(`docs/architecture/doctor-single-active-session-lease.md`, LEASE-R001..R024); no rule
below restates it, and the two prefixes must not be merged.

---

## 1. The one sentence

**THE SERVER DECIDES, THE DOCTOR NEVER DOES, AND NOBODY GUESSES.**

A doctor's branch is a row somebody approved. It is not derived from the tablet they are
holding, not from their doctor code, not from where they logged in last, not from a room,
and not from history. Where no row exists the answer is **UNSET**, and UNSET means the
system behaves exactly as it did before this capability existed.

Every rule below is a consequence of that sentence, or a guard against an implementation
that would quietly invert it.

---

## 2. Three concepts, deliberately not two

| | table | lifetime | who writes it |
|---|---|---|---|
| **HOME LOCK** | `mst_doctor_branch_locks`, UNIQUE(`doctor_id`) | permanent until transferred | `DoctorBranchLockApprovalService` only |
| **TEMPORARY COVER** | `trx_doctor_branch_covers` | a half-open instant range | `DoctorBranchCoverApprovalService` only |
| **EFFECTIVE BRANCH** | nothing — it is computed | this request | nobody; it is never stored as truth |

A cover is **not** a temporary reinterpretation of the home lock. The home branch is
retained while a cover runs and is returned to when it ends. Anything that wrote the cover
target into `mst_doctor_branch_locks` would destroy the thing the doctor reverts to.

---

## 3. The rules

### DBL-R001 — One home lock per doctor, or none at all

`UNIQUE(doctor_id)` on `mst_doctor_branch_locks`. Absence of the row is the UNSET state and
is a first-class answer, not missing data. There is **no backfill migration and none may be
added**: production has no authoritative per-doctor branch to backfill from, so any backfill
would write a guess as an approved fact.

### DBL-R002 — The branch lock arms only alongside the session lease, and only with an observable session store

`DoctorEffectiveBranchResolver::enabled()` requires three things: `doctor.branch_lock`,
`doctor.single_active_session`, and `IncumbentSessionProbe::observable()`.

The flag registry's `dependencies` array is **decorative** — it is required metadata that
no caller reads — so the dependency is enforced in code. It exists because cover activation,
cover expiry and an approved transfer all take effect by **invalidating the session**, and
that mechanism is the lease engine. An armed lock over a disarmed lease engine would leave
an expired cover granting authority with nothing left to invalidate.

### DBL-R003 — There is exactly one resolver, and it is pure

`DoctorEffectiveBranchResolver` takes a `User` and nothing else: no request, no device id,
no branch argument. It performs at most three bounded reads and **zero writes**, and fewer
than three for an UNSET doctor: with no cover and no lock it returns before reading the
branch table, because nothing that table could say would change an UNSET verdict and
`BranchService::rmeEnabledIds()` is not cached. That is the whole fleet on the day this
arms. In
particular it never audits, because it runs on every protected request of every doctor and
auditing a standing condition here would insert a row per page view; the reason travels on
the returned value object instead.

A second implementation of "is a cover active" is the worst outcome available in this
capability, so the SQL predicate lives in one repository method, the PHP predicate lives on
one model, and the two are pinned equal by test.

### DBL-R004 — Null is an answer, never an error

A null user, a non-doctor, an exempt governance account, an unlinked doctor, a doctor with
no lock row, and a doctor whose locked branch has been retired all resolve to null. Every
one of them means **behave exactly as this system did before this sprint**.

### DBL-R005 — Authority is derived from timestamps on every request, never from a stored flag and never from a scheduler

There is no `is_active` column, no `EXPIRED` status, no `expireStale()` command and no
scheduled job in the correctness path. A cover is current because
`starts_at <= now < ends_at` is true on **this** request. A failed or delayed scheduler can
therefore leave a row uncollected, but it can never leave an expired cover in force.

### DBL-R006 — Cover periods are half-open, `[starts_at, ends_at)`

08:00–12:00 and 12:00–16:00 do **not** overlap; 08:00–12:00 and 10:00–14:00 do. At the
shared boundary instant the first cover is already over and the second is already in force,
which is what makes two adjacent covers unambiguous about where the doctor is.

Both directions are proven: overlap is refused, and **adjacency is accepted**. The
acceptance is the half a suite of refusals cannot see — an over-strict comparison passes
every refusal test and then rejects the most ordinary pair of covers an approver files.

### DBL-R007 — Non-overlap is a property of ONE doctor's timeline

Two different doctors may hold covers over the same instants, including for the same target
branch. An invariant that forgot `doctor_id` would refuse the second clinician with a
message about a collision that does not exist.

### DBL-R008 — The non-overlap invariant rests on the service, under row locks, in this order

No index on either engine can express overlap: a unique index compares **values** and
overlap is a **range** predicate. That was established by attempting the write, not
assumed — identical periods were rejected by the index, merely overlapping ones were
accepted.

So `DoctorBranchCoverApprovalService::approve()` holds this order, and the order is
load-bearing:

1. lock the cover row
2. lock the **doctor** row
3. lock the doctor's `mst_doctor_branch_locks` row — **the serialisation point**
4. only then evaluate overlap
5. write

Evaluating overlap before step 3 would let two approvers each read a clean result and each
commit. On SQLite `lockForUpdate()` compiles to an empty string, so the local suite proves
the logic and only the PostgreSQL gate proves the concurrency.

### DBL-R009 — A cover requires a home lock

Three independent reasons, any one sufficient. `source_home_branch_id` is NOT NULL, so the
row is unrepresentable for an UNSET doctor. An UNSET doctor has no lock row, so step 3 above
would have nothing to lock. And cover is authority **relative** to a permanent home: with
no home there is nothing to revert to, so expiry would silently widen an UNSET doctor back
to every RME branch — a widening on a timer, the exact inverse of a lock.

### DBL-R010 — A permanent transfer is refused while a cover is active

Evaluated from current timestamps under the locks already held, so a cover decision racing
a transfer is already serialised on the same home-lock row. A merely **scheduled**
future-dated cover does not block, and is surfaced to the approver instead.

### DBL-R011 — Maker-checker is an actor rule enforced in the service, not a role rule enforced in a policy

`request.created_by` may never equal the approving actor. Either authorised tier may file
and either may decide; nothing encodes "Super Admin is the maker" or "Supervisor RME is the
checker".

The check lives **inside the approval transaction, under the row locks**, because the single
global `Gate::before` returns true for a Super Admin before any policy method runs — so a
clause written only in a policy would never execute for the one actor most able to be both
parties. A requester who later gains or changes role still cannot approve their own request,
because the invariant compares identities and not permissions.

### DBL-R012 — A rejection requires a written reason; an approval note stays optional

Enforced in the service against one config key and one minimum, so an HTTP caller and a
console caller cannot diverge on whether the reason was required. The form request mirrors
the rule only to produce a better message on the form.

### DBL-R013 — An approval is an event, not a credential

The lock is written inside the approval transaction. There is no approved token a requester
can carry to a second endpoint and replay, because there is no second endpoint. Single use
is a property of the design, not a counter somebody must remember to decrement.

### DBL-R014 — Every binding is re-derived and re-asserted under the lock at decision time

The request type from the live lock; a transfer's source from the live lock, refusing if it
moved; the destination re-validated for active and RME-enabled; the target checked against
the doctor's practice branches; presence read live. An approval never confers access to a
branch the doctor could not otherwise work in, and never resurrects a deactivated one.

### DBL-R015 — There is no day boundary and no EXPIRED status on a lock request

The sibling `trx_branch_change_requests` has one because that row belongs to a clinical
date. A permanent lock has no day: its freshness boundary is the stale-source guard in
DBL-R014, which is a re-derivation rather than a timestamp. A later reader must not restore
a state that was never missing.

### DBL-R016 — An approved change invalidates the doctor's session, and never silently switches branch inside one

Cover activation, cover expiry and an approved transfer are the **same event** to the
runtime: the effective branch recorded on the lease at claim time no longer matches the one
derived now, and `EnsureDoctorSessionLease` ends the session. The doctor logs in again and
branch context is re-derived rather than carried over.

The deny arm fires only when **both** sides have an answer. Disarming the capability
therefore logs nobody out, and arming it never mass-evicts doctors already working.

### DBL-R017 — A branch operation never revokes an identity

Device, device authorization and WebAuthn credential all survive an approval, a rejection
and an expiry. **Identity revocation is not a branch rollback.** Ending a login session is
the whole of the blast radius.

### DBL-R018 — The lock narrows the LIST scope and the WRITE chokepoint, and nothing else

Two hooks, each asking the resolver exactly once:

- `RmeWorkingBranchScope::resolve()` — the scope every visit list, patient queue, room
  worklist and count widget funnels through.
- `ClinicVisitService::resolveBranchId()` — the single convergence point of `branch_id` and
  `new_patient.branch_id`.

### DBL-R019 — Narrowing is an INTERSECTION, never a replacement

The effective branch is applied as `array_intersect($scopeIds, [$effectiveBranchId])`, so
the hook is structurally incapable of granting a branch the pre-sprint rules withheld. A
locked branch that is not an active RME branch yields an **empty** set rather than an
unreadable one.

A consequence, accepted deliberately: an account that is both a locked doctor and a
context-bound role gets the intersection of the two scopes and sees nothing until the
disagreement is resolved. Empty is narrower than either input, so the choice cannot grant
anything.

### DBL-R020 — Per-record reads stay cross-branch, on purpose

`RmeWorkingBranchScope::allows()`, `ClinicVisitPolicy` and
`DoctorClinicalBranchResolver` are **not** narrowed. A patient's record book is anchored to
their earliest visit, so narrowing these would hide a patient's own history from the doctor
treating them. The docblocks say so in place; do not "align" them with the list scope.

One visible consequence: a locked doctor who crafts a report request for another branch is
**silently narrowed** rather than refused, because the figures are scoped by the list path
anyway. There is no cross-branch leak — the requested branch simply has no effect — and a
stale bookmark still renders the report the doctor is entitled to instead of a dead end.

### DBL-R021 — The tablet's branch never decides anything

Device trust and branch authority are independent. A doctor locked to one branch sees and
writes that branch from an approved tablet owned by any other branch.

### DBL-R022 — A request's `branch_id` never decides anything

Both the online-context start and visit creation re-assert the effective branch server-side.
A crafted value is refused at the service boundary, not merely hidden in the view.

### DBL-R023 — A degraded lock is VISIBLE

If a locked branch loses `is_active` or `is_rme_enabled`, the resolver degrades to UNSET so
the doctor keeps working under pre-sprint rules. Correct is not the same as visible, and
DBL-R003 forbids auditing it, so the approver queue lists every such doctor with the
retained home branch, the reason **code** and a sentence. The predicate is not
re-implemented in the view: each lock row is put to the resolver.

### DBL-R024 — Instants cross the SQL boundary in the frame the columns store

`Connection::prepareBindings()` renders a `DateTimeInterface` using the timezone the object
is **carrying**. Hand a cover predicate a clinical-zone reading of the right instant and the
binding is eight hours away from the column, so `starts_at <= at < ends_at` quietly matches
nothing and every guard built on it returns normally.

This is not hypothetical: it is how the transfer-through-active-cover guard of DBL-R010 came
to be dead code before it was found. Normalisation happens at the one repository boundary
where these instants stop being PHP objects and become SQL. It converts a frame, never an
instant, so a caller already passing UTC is unaffected byte for byte.

### DBL-R025 — Adding a foreign-key column must re-assert the lease's partial unique index

Adding an FK column makes SQLite rebuild the table, and Laravel re-creates that table's
indexes from introspection **without a partial index's predicate**. That flattens
`UNIQUE(user_id) WHERE released_at IS NULL` into a plain unique on `user_id`, which breaks
every doctor's second login after any logout — on SQLite only, invisible to a green
PostgreSQL gate. The migration re-asserts the index with its predicate in both `up()` and
`down()`.

---

## 4. What lives where

| concern | file |
|---|---|
| the one answer | `app/Modules/DoctorAccess/Services/DoctorEffectiveBranchResolver.php` |
| initial assignment, transfer | `app/Modules/DoctorAccess/Services/DoctorBranchLockApprovalService.php` |
| cover request and decision | `app/Modules/DoctorAccess/Services/DoctorBranchCoverApprovalService.php` |
| the subject guard, presence | `app/Modules/DoctorAccess/Services/DoctorAccessSubjectGuard.php` |
| the period value object | `app/Modules/DoctorAccess/Support/DoctorBranchCoverPeriod.php` |
| the answer + its reason | `app/Modules/DoctorAccess/Support/DoctorEffectiveBranch.php` |
| the SQL cover predicate | `app/Modules/DoctorAccess/Repositories/DoctorBranchCoverRepository.php` |
| the list hook | `app/Modules/RmeOnlineContext/Services/RmeWorkingBranchScope.php` |
| the write hook | `app/Modules/ClinicVisit/Services/ClinicVisitService.php` |
| session invalidation | `app/Modules/DoctorAccess/Middleware/EnsureDoctorSessionLease.php` |

---

## 5. What this pull request does not claim

- **It is not armed.** Both flags are committed OFF and no doctor has a lock row, so the
  capability is inert on the deployment that carries it.
- **It assigns nobody a branch.** Every one of the fleet's doctors stays UNSET until an
  approver files and a second approver decides.
- **Global doctor WebAuthn enforcement is untouched** and stays false.
- **Concurrency is proven on PostgreSQL only.** `lockForUpdate()` is a no-op on SQLite, so
  the local suite proves the ordering logic and the critical gate proves the serialisation.
- **The bulk device-authorization tool is not here.** It is PR-C.

---

## 6. Related documents

- `docs/architecture/doctor-single-active-session-lease.md` — LEASE-R001..R024, the
  one-session rule this capability depends on.
- `docs/runbooks/doctor-branch-lock-operations.md` — arming, the two workflows, cover, and
  what to do about a degraded lock.
- `docs/sprints/doctor-access-pr-b-branch-lock-cover.md` — this pull request's record,
  including its honest limits.
- `docs/sprints/doctor-access-single-session-branch-lock-1/` — the shared design archive for
  all three children.
