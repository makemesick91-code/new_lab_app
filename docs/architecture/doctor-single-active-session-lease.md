# Doctor single active session — the session lease

**Status:** shipped behind a flag that is committed OFF.
**Capability flag:** `doctor.single_active_session` (`FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION`), risk `critical`, default `false`.
**Sprint:** DOCTOR-ACCESS-PR-A-SINGLE-SESSION-FOUNDATION — the first of three children of
DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1.
**Runtime:** `App\Modules\DoctorAccess`.
**Operations:** `docs/runbooks/doctor-session-lease-operations.md`.

This document is complete on its own. It describes ONE rule — at most one authenticated
session per doctor — and nothing else. The doctor **branch lock**, temporary **cover** and
the **effective-branch resolver** are a separate capability with a separate document that
PR-B adds (`docs/architecture/doctor-branch-lock-and-cover.md`); no rule below describes
branch behaviour, and none may be extended to.

---

## 1. The one sentence

**REFUSED, NOT EVICTED.** The second login is denied; the first is untouched.

The clinician already seeing patients keeps working. The person arriving second is told
that a live session exists. Every rule below is a consequence of that sentence, or a
guard against an implementation that would quietly invert it.

The alternative — newest-login-wins — was rejected because the session it would evict is
a doctor mid-consultation with a patient in the chair, and because an eviction that
happens silently is indistinguishable, from the clinic floor, from the application
breaking.

---

## 2. The rules

### LEASE-R001 — At most one unreleased lease per doctor account, enforced by the database

`trx_doctor_session_leases` carries a **partial unique index** on `(user_id)`
`WHERE released_at IS NULL`. The invariant is a schema statement, not an application
convention, so two logins racing for a free lease cannot both win by interleaving an
application check.

Released rows are **retained**. The index constrains only the unreleased ones, so the
release trail survives every re-claim.

### LEASE-R002 — The claim happens on the framework `Login` event, never in a controller

`ClaimDoctorSessionLease` listens on `Illuminate\Auth\Events\Login`. A remember-me
recaller cookie re-authenticates a browser through **no controller at all**, so a claim
placed in a login controller would be bypassed by exactly the path a returning tablet
uses.

### LEASE-R003 — A session carrying no lease token is never touched

The lease is claimed on one event. Every other entry point passes through, and nothing
in the engine may create a lease for a session that did not claim one.

This is what keeps the capability implementable: `actingAs()` authenticates through
`SessionGuard::setUser()`, which fires `Authenticated` and not `Login`, so roughly 3554
existing test sessions hold no token. A middleware that treated "authenticated doctor
with no lease" as a denial would evict all of them.

### LEASE-R004 — There is no idle reclaim

A lease becomes reclaimable when the incumbent's server-side `sessions` row is **GONE** —
dead — never when it is merely quiet. `last_seen_at` is diagnostics and operator display.

Reclaiming on a timer is eviction through the back door and inverts LEASE-R001's purpose.

### LEASE-R005 — The liveness window belongs to the session store, and is never widened

`IncumbentSessionProbe::isLive()` tests `last_activity >= now - session.lifetime`, which
is the **same predicate** `DatabaseSessionHandler::expired()` applies before it will
restore a session. An incumbent past that window is not "idle": the framework itself
would refuse to resurrect it, so there is nobody left to evict.

The lease contributes **no timer of its own**.

### LEASE-R006 — Liveness is keyed on `user_id`, never on the session id recorded at claim time

The framework mints a new session id on every re-authentication
(`Store::migrate()` → `SessionGuard::updateSession()` → `regenerate(true)`), so the
recorded id is stale within the same request. `sessions.user_id` is written by the
database handler, is indexed, and does not rotate.

A consequence operators must know: one surviving session row for the account keeps the
incumbent **alive**.

### LEASE-R007 — The engine disarms when it cannot observe an incumbent, and never guesses

Liveness is read from the server-side `sessions` table. On any driver other than
`database` that table is never written, so every incumbent would read DEAD and the rule
would silently degrade to newest-login-wins — a **fail-open** that looks green in every
test.

`DoctorSessionLeaseService::enabled()` is therefore the flag **AND** the probe. It is
deliberately NOT "assume the incumbent is alive", which would deny every doctor login on
a file, redis or array driver — a **fail-closed** clinic-wide outage.

Inert means inert in both directions: while disarmed nothing is claimed and nothing is
denied.

### LEASE-R008 — The deny path never calls `Auth::logout()`

`users.remember_token` is **one column shared by every session of the account**.
`SessionGuard::logout()` cycles it; `logoutCurrentDevice()` does not. Refusing login
number two through `logout()` would invalidate the remember-me cookie of session number
one — a partial eviction of the session the rule just promised not to touch.

This is also why the device module's `invalidate()` is not reusable here: it calls
`logout()`.

### LEASE-R009 — A denial creates no row

The refusal is audited against the **account** (`DOCTOR_SESSION_LEASE_DENIED`, entity
`users`), because there is no new lease to hang it on. A denial cannot accumulate lease
history.

### LEASE-R010 — Reclaim, release, denial and eviction are four distinct audit actions

`DOCTOR_SESSION_LEASE_CLAIMED`, `…_RECLAIMED`, `…_DENIED`, `…_RELEASED`, `…_EVICTED`,
`…_REVOKED`. Nobody reading the trail should have to guess whether a lease was taken from
a live session or a dead one, or whether a doctor logged out or was logged out.

A lease eviction is **not** a device invalidation, and reusing the device action would
make the trail unreadable at exactly the moment somebody is explaining to a doctor why
they were logged out.

### LEASE-R011 — Revalidation answers only what a lease can be wrong about on its own

**AMENDED BY PR-B: there are now THREE conditions, and the third was not an invention.**

As PR-A shipped it, two: the lease behind the token is **gone** (released at logout, or
cleared by an operator), or it belongs to a **different account** (the session was restored
over another user's).

PR-B adds `DENY_BRANCH_CONTEXT_CHANGED`. It belongs here rather than anywhere else for a
specific reason: it is a fact **recorded on the lease row itself** — the effective branch and
cover the session was established under — so it is exactly "what a lease can be wrong about on
its own", which is what this rule is named for. Nothing else in the request can answer it,
because nothing else remembers what the session was established under.

The boundary the rule was drawing still holds and is worth restating in its stronger form:

> Revalidation answers only questions the LEASE ROW can answer. Whether the tablet is still
> trusted is the device middleware's question and must never be asked here.

A fourth condition that needed to read a device, a request or a policy would still be an
invention, and would still belong somewhere else.

### LEASE-R012 — Eviction frees the clinic room before it tears the session down

`markOffline()` nulls `clinic_room_id` for a doctor context. Doing it after the lease
release would race the doctor's own next request, which refreshes presence, and would
leave an evicted doctor's consultation room marked occupied — blocking the clinician
taking over.

### LEASE-R013 — Eviction writes only when the lease is THIS session's to end

**AMENDED BY PR-B, and the original wording is now wrong rather than merely incomplete.**

As PR-A shipped it this rule said "eviction releases no lease row", justified by enumerating
the only two reasons that could reach `evict()`: a MISSING lease has nothing left to release,
and a MISMATCHED one belongs to somebody else and must never be written to from the session
that merely carries its token. Both of those describe a lease that is **already not this
session's to end**, and for both of them writing nothing is still correct.

PR-B added a **third** reason, `DENY_BRANCH_CONTEXT_CHANGED`, and it is different in kind: the
lease **is** this session's, it is live, and the session is being ended because the branch it
was established under is no longer the branch that is effective. So `evict()` releases it, with
`RELEASE_EFFECTIVE_BRANCH_CHANGED`, and leaving it unreleased would strand an active lease
behind a session that no longer exists — the doctor's own next login would then be refused by
their own dead lease.

The invariant that actually held all along, and now says so:

> `evict()` writes to the lease row **if and only if** the lease belongs to the session being
> ended. Two of the three reasons mean it does not, so two of them write nothing.

The lookup runs in every case, so the eviction is audited against the lease it was about.

### LEASE-R014 — FORCE LOGOUT is the only escape hatch, and it ends a login session and nothing else

One active session per doctor means a lease can get **stuck**: a tablet locked in a
drawer, or a doctor who went home without logging out while their session row is still
live. The escape hatch is a deliberate, authorized, audited human action — never an idle
timer (LEASE-R004), never a manual `UPDATE` (no trail, frees no room).

It **must never** revoke a device, a `DoctorDeviceAuthorization` or a WebAuthn
credential, and it writes to none of those tables. WebAuthn revocation is irreversible —
the only write to `revoked_at` in the registration service is `=> now()` — so a support
action taken to unstick a doctor at 08:00 would permanently destroy the credential on
their tablet. **The doctor logs back in; they do not re-enrol.**

### LEASE-R015 — Force logout requires a named human actor and a written reason

The Linux user, the SSH login and root are never an application identity. The surface
resolves one real, **active** account and checks `release_doctor_session_leases` against
it exactly as a browser surface would — which is also the only way LEASE-R016 can mean
anything on a command line.

The reason is bounded by `config('doctor_access.reason')`: a real floor, not a non-empty
check, because `x` and `asdf` pass a non-empty check and explain nothing. **No `env()`
call in that config file** — a bound an operator can move from the machine they are
already on is not a bound.

### LEASE-R016 — A self-release is refused, for every holder

An operator ending their own session through force logout is not a support action; it is
a logout, and the logout path releases a lease cleanly with its own reason. Blocking it
keeps `admin_release` meaning what it says in the trail.

The comparison lives **in the service**, inside the transaction, after the doctor row is
locked. A permission check could not catch it, and a policy clause would not run for a
Super Admin, whom the single global `Gate::before` short-circuits.

### LEASE-R017 — Force logout is dry-run by default, and the preview runs the real guard

Without an explicit apply it reports what it would do and writes nothing: no release, no
room change, no audit row. The preview takes the **same** row lock and applies the
**same** refusals, so a refusal previews as a refusal.

It is not a promise. The lease it reports can be released by a logout or claimed by a
reconnecting tablet in between; the decision re-reads everything under its own lock and
is the only authority.

### LEASE-R018 — A release is DATA. Nothing is evicted in place

There is no cross-session logout primitive in this codebase and nothing here invents one.
The victim keeps working until their browser makes another request, at which point
revalidation finds no lease behind their token and tears that session down. **For an idle
tablet that can be minutes.**

Say that to the operator rather than implying the release is instantaneous.

### LEASE-R019 — Finding nothing to release is an outcome, not an error

A doctor who holds no active lease is not a failure: the release answers null, the audit
records `session_released = false`, and the operator is told there was nothing to clear
rather than shown a success message for a no-op. Re-running the runbook step is safe.

### LEASE-R020 — An unlinked doctor is refused at both ends

A `Doctor`-role record with no `mst_doctors.user_id` binds nobody and has no session to
end, so without the refusal the action would report success while doing nothing. The
message names the **remedy**, not just the condition, and uses the same wording the
doctor-performance hotfix already uses so operators see one message for one condition.

### LEASE-R021 — The subject's active state is read at decision time

Never carried in from a screen rendered ten minutes ago. `findForUpdate()` also queries
through the model's `SoftDeletes` scope, so a removed doctor reads as absent — correct: a
removed doctor is not one whose session anybody needs to end.

### LEASE-R022 — The persisted token is a digest, never a bearer credential

The plaintext lease token lives in **session data** and nowhere else; only its
`sha256` digest is stored. `trx_doctor_session_leases` is therefore not a credential
store, and a database read cannot mint a session.

### LEASE-R023 — The rule is per doctor, not per deployment

Three doctors at three chairs is the ordinary clinical case. The cardinality invariant is
per `user_id`; refusing concurrent doctors would be a clinic-wide outage rather than a
single-session rule.

### LEASE-R024 — This capability never reads the trusted-device enforcement flag

`doctor.single_active_session` and `doctor.trusted_device_enforcement` are independent
switches. Neither may arm, disarm or consult the other, and an existing exact-equality
pin in `DoctorDeviceEnforcementGateTest` fixes the readers of the device key to exactly
one file.

---

## 3. What lives where

| Concern | Home |
| --- | --- |
| Every lease decision — claim, deny, reclaim, renew, release, evict | `DoctorSessionLeaseService` |
| "Is the incumbent alive?", and "can we see at all?" | `IncumbentSessionProbe` |
| Claim on authentication | `ClaimDoctorSessionLease` (listener) |
| Per-request revalidation and teardown | `EnsureDoctorSessionLease` (middleware) |
| Force logout, room-then-lease ordering, self-release refusal | `DoctorSessionReleaseService` |
| The decidable-subject guard and its three refusals | `DoctorAccessSubjectGuard` |
| The operator surface | `doctor:session-force-logout` |
| Reason bounds | `config/doctor_access.php` |
| The switch | `config/feature_flags.php` |

`EnsureDoctorSessionLease` sits after `StartSession` and before the online-context and
device middleware. It revalidates the lease and nothing else.

---

## 4. What this pull request does not claim

- **Concurrency is not proven by the suite.** Every test runs inside one
  `RefreshDatabase` transaction on one connection, and `lockForUpdate` compiles to an
  empty string on SQLite, so the racing claim is never actually contended locally. The
  database-cardinality test proves the **logic** of the partial unique index; only the
  PostgreSQL critical gate exercises the lock, and even there the claim runs one
  savepoint deeper than production.
- **Nothing here has been proven on a tablet.** Arming the flag can refuse a login, so it
  belongs to a supervised activation window on real hardware, never to a deploy.
- **The ruling's "without an SSH session" is not yet delivered.** The operator surface in
  this pull request is a console command, so it needs server access. An HTTP surface can
  be added on top of `DoctorSessionReleaseService` without restating one of its
  decisions — that is PR-B's controller.
- **No branch authority is recorded.** The lease table carries no effective branch on this
  base. PR-B adds the columns additively; see §5.

---

## 5. The PR-B seam — CROSSED. PR-B has merged and deployed.

`trx_doctor_session_leases` now **has** `effective_branch_id` and `effective_cover_id`, added
by PR-B's fourth additive migration together with the resolver that fills them. The sentence
this section used to open with — that the table ships without them — was true of PR-A alone and
is false on this tree.

What remains permanently true, and is the part that matters:

Leases claimed between the two deploys carry **nulls**, and the comparison tolerates a null —
so **nobody was evicted by the PR-B deploy, and no backfill may be added.** Inventing an
effective branch for a session that was established before the concept existed would be a
fabricated clinical fact.

Production, re-measured at parent closure: **8 leases, of which 4 carry an effective branch**
(ids 5, 6, 7, 8) and the four claimed before the PR-B deploy carry nulls. Lease 8 is still
unreleased. `doctor.branch_lock` is ON and `doctor.single_active_session` is OFF, so the
comparison is not currently on any request path. The rule above is what matters and it holds:
the nulls were tolerated, nobody was evicted by the deploy, and no backfill was added. An
earlier version of this paragraph claimed the table was empty and the capability off — both
were true when it was written and neither is true now, which is why a confirmation sentence
should never be written in the present tense without a date.

Two rules above were amended by PR-B rather than left to rot: **LEASE-R011** (revalidation now
answers three conditions, not two) and **LEASE-R013** (eviction writes to the lease row when
the lease is this session's to end, which PR-B's third reason is). Each says so in place.

---

## 6. Related documents

- `docs/runbooks/doctor-session-lease-operations.md` — arming, the audit actions, clearing
  a stuck lease.
- `docs/sprints/doctor-access-pr-a-single-session-foundation.md` — this pull request's
  record, including its honest limits.
- `docs/sprints/doctor-access-single-session-branch-lock-1/` — the shared design archive
  for all three children.
- `docs/architecture/doctor-branch-lock-and-cover.md` — **added by PR-B.** The branch
  lock, temporary cover and effective-branch resolver, under their own rule prefix.
