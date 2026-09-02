# REVISION-SUNU-ADD-ROOM-A-B-1 — Cabang Sunu gets Ruangan A and Ruangan B

**Type:** BUSINESS_RULE_REVISION / MASTER_DATA
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `73c27a3`
**Baseline GO tag:** `revision-sunu-branch-code-sun4-to-spn4-1-go`
**Rule mirror:** `.cursor/rules/137-branch-room-provisioning.mdc`

## The decision

Cabang Sunu — canonical branch code `SPN4`, branch id 5 in the pilot — operates
two rooms, **Ruangan A** and **Ruangan B**. Both are active and both hang off the
existing Sunu branch identity.

## What was actually wrong

Nothing in code. Production carried rooms for every other RME branch and none at
all for Sunu:

| Branch | Rooms |
|---|---|
| TLK1 Cabang Telkomas | `TKM-A` Ruangan A, `TKM-B` Ruangan B, `TKM-C` Ruangan C (inactive), + 5 legacy |
| LDK2 Cabang Landak | `LDK-A` Ruangan A, `LDK-B` Ruangan B, `LDK-C` Ruangan C |
| ATG3 Cabang Antang | `ATG-A` Ruangan A, `ATG-B` Ruangan B, `ATG-C` Ruangan C (inactive) |
| MAIN | one room |
| **SPN4 Cabang Sunu** | **none, in any state** |

`ROOT_CLASSIFICATION = MISSING_MASTER_DATA`. No duplicate, no inactive row, no
soft-deleted row, no ambiguity — verified read-only before any write.

The room codes were **derived, not invented**: the existing rows already
establish `<branch three-letter prefix>-<room letter>`. Read off the CANONICAL
code `SPN4`, that gives `SPN-A` and `SPN-B`. (Telkomas' rooms are still `TKM-*`
because they were issued before the `TKM1 → TLK1` rename; issued identifiers are
deliberately preserved.)

## The part that needed care

`mst_clinic_rooms` carries `UNIQUE(branch_id, code)` and `UNIQUE(branch_id,
name)`, and **neither index is conditioned on `deleted_at`**. A soft-deleted
"Ruangan A" therefore still occupies both slots. A blind insert against one does
not produce a duplicate row — it raises a constraint violation and takes the
deploy with it. That single schema fact is what separates this from a two-row
INSERT, and it is why the existing `ClinicRoomSeeder` (`updateOrCreate` on a
non-trashed query, scoped to whatever branch `BranchContext` resolves to) could
not be reused.

## What shipped

**`database/seeders/RmeBranchRoomSeeder.php`** — a canonical per-branch room
registry, following the `RmeBranchSeeder` precedent:

- Branch resolved by **code**, through `BranchCodeAlias::equivalentCodes()`, so a
  deployment still carrying `SUN4` is recognised rather than duplicated. Never by
  display name: Master Data Cabang owns that label and may edit it.
- Every room lookup is `withTrashed()`.
- **Name is the identity**; `code` is only assigned on creation. A room already
  named "Ruangan A" under an operator-chosen code is adopted, and its code is left
  alone.
- Convergence restores a trashed room and activates an inactive one. Code, type
  and description are never rewritten.
- **Fails closed** when the declared code is held by a differently named room, or
  when code and name resolve to two different rows.
- Never creates a branch, never deletes, never touches another branch.
- Registered in `DatabaseSeeder` after `ClinicRoomSeeder`.

`ClinicRoomSeeder` is untouched and stays — it answers a different question
(default rooms for the `BranchContext` branch).

## What did NOT change

No migration, no schema change, no route, no permission, no policy, no
controller, no service, no view. No historical visit, queue, roster, patient,
RME, odontogram, invoice or payment was reassigned. No doctor was auto-assigned
to a room. `SPN4` stays canonical; `SUN4` stays a historical alias and never
appears in a room code.

## Consumers

There are **no hard-coded room lists anywhere** in the repository — every room
consumer reads the master data dynamically through
`ClinicVisitService::activeRoomsForBranch()`, which filters on `branch_id` and
`status = active`. The Sunu rooms therefore become visible in the doctor
online-context room selector and the patient-queue room selector with no code
change.

Assignability has a second, pre-existing gate (Sprint 66.1.4): the treating
doctor is resolved FROM the room, so a room only becomes assignable once exactly
one doctor is online in it. Creating the rooms does not put a doctor in them —
which is exactly the no-auto-assignment property this sprint required.

## Branch boundary

`ClinicVisitService::assignRoom()` already enforced, server-side, that the room
exists, is active, and that `room.branch_id === visit.branch_id` — the visit's
branch, never a request field. This sprint pins that boundary for Sunu in both
directions.

A finding worth recording: asserting only `toThrow(ValidationException::class)`
was **not sufficient**. Mutation testing showed that deleting the branch check
still throws — from doctor resolution further down — so a type-only assertion
passed against code that had lost the branch boundary entirely. The tests now
assert the refusal *reason*.

## Evidence

- `tests/Feature/Branch/SunuClinicRoomProvisioningTest.php` — 28 tests.
- Mutation matrix: 13 mutants, **13 killed, 0 survivors** (each verified applied
  and verified restored; restore by copy, because the seeder is untracked and
  `git checkout --` cannot restore it).
- PostgreSQL **16.14** (production's version) — 28/28.
- Regression on PG16: branch/room/visit **320 passed**; CI/DEVFLOW **359 passed**.
- Deploy-command smoke on PG16: `db:seed --class=RmeBranchRoomSeeder --force` run
  three times → exactly two active rooms, correct branch, branch count unchanged,
  no second Cabang Sunu.
- CI critical-gate token `SunuClinicRoom` added to **both** critical variants, so
  the suite is actually selected rather than relying on a naming accident. It was
  deliberately NOT added to `critical_gate_mandatory_suites` — that registry
  documents its own bar (controls whose failure was live in production), and this
  sprint does not meet it.

## Deploy

No migration. No permission change.

```
php artisan db:seed --class=RmeBranchRoomSeeder --force
```

Idempotent; safe to re-run.

## Rollback

The rooms are additive master data. Rollback is to set the two rooms
`status = inactive` through Master Data Ruangan — **not** to delete them, because
a room may already be referenced by a visit by then. Deleting a referenced room
is blocked by the schema anyway (`restrictOnDelete` on the visit FK path).
