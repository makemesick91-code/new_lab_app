# Proposal — working context as a hard boundary (tablet and room)

**Status: PROPOSAL. Not authorised, not scoped, no code written.**
Raised by the operator during the PR-B production ceremony, 2026-09-12.
**Neither item is a defect.** Both are refinements of behaviour that is deliberate and documented.

Recorded here so two real product decisions are not lost in a ceremony transcript, and so nobody
mistakes them for bugs found on production.

---

## What the operator asked for

Two statements, made on seeing correct behaviour:

1. *"seharusnya user hanya bisa login di tablet yang sesuai dengan konteks kerja nya"* — a doctor
   should only be able to log in on a tablet belonging to their own branch.
2. *"narrowing ruang perawatan harus hanya menampilkan pasien sesuai dengan ruangan konteks
   kerja"* — the treatment-room worklist should only show patients in the doctor's own room.

They point the same way: **make the working context a harder boundary than today's design makes
it.** That is a coherent position, and it is worth deciding as one piece of work rather than two
patches.

---

## 1. Tablet must match branch

### What happens today, and why

`DEVICE_BRANCH_IS_BRANCH_AUTHORITY=NO`. A doctor may authenticate from any approved trusted clinic
tablet, and the tablet's branch contributes nothing to their effective branch. This was proven on
production during the ceremony: drg Karmila, locked to SPN4, logged in from the ATG3 tablet and
got SPN4.

That is the **authorised** design, in three places:

- the founding owner decision — *"a doctor may authenticate from ANY approved trusted clinic
  tablet regardless of which branch owns it"*, and *"device trust is independent of branch
  authority"*;
- `DBL-R021` in `docs/architecture/doctor-branch-lock-and-cover.md`;
- the owner's own ceremony script §23/§24, where an effective branch of ATG3 would have been the
  **FAIL**.

### What changing it would cost

- **Cover breaks under the naive version.** A doctor working a temporary cover at another branch
  is, by definition, standing at a tablet that does not match their *home* branch. A login check
  against the home lock would lock them out of the exact scenario cover exists for. Any
  implementation must compare against the **effective** branch, not the home lock.
- **Degraded locks lock people out.** When a locked branch loses `is_active` or `is_rme_enabled`
  the resolver degrades to UNSET on purpose so the doctor keeps working. A login-time branch match
  would instead deny them entirely, turning a handled degradation into an outage.
- **It is a login-path change**, which is the highest-blast-radius code in the system.

### What it would buy

Real defence in depth: a stolen or misplaced tablet could not reach another branch's work at all,
even with a valid authorization. Today the branch lock limits what the session can *see*; this
would limit where a session can *exist*.

---

## 2. Room must match room

### What happens today, and why

`DoctorRoomScopeService::applyRoomScope()`:

```php
$query->where(function ($inner) use ($roomId) {
    $inner->whereNotIn('status', ClinicVisit::PRE_EXAM_STATUSES)  // history: always visible
        ->orWhere('clinic_room_id', $roomId);                      // active: only MY room
});
```

So a doctor in Ruangan A sees **pre-exam patients** (`registered`, `waiting`, `in_progress`) only
in Ruangan A — another room's active patient is hidden, and the per-record guard refuses it with
*"Pasien ini tidak berada di ruangan perawatan Anda saat ini."* But **post-exam and terminal
visits** (`cashier_pending`, `completed`, `cancelled`) stay visible regardless of room, because
the code treats them as history and history is deliberately never room-denied.

The operator's rule is stricter: only patients in the working-context room, full stop.

### What changing it would cost

- **It removes history from the worklist.** The current carve-out is explicit in the source (§17).
  A doctor finishing paperwork on a patient who has moved to the cashier would lose sight of them.
- **`cashier_pending` is the handover state.** Sprint 62.1 made it the doctor→cashier boundary and
  Sprint 60.7 built a handover surface on it. Hiding it by room may break that flow.
- **This is not PR-B's code.** Room scoping is Sprint 66.2 plus the Phase 1 room scope, carried by
  its own CI token `DoctorRoomScoped`. PR-B added branch narrowing *above* it and changed nothing
  in it.

### What it would buy

A doctor's screen would show only the patients physically in front of them — less chance of
opening the wrong record, and a tighter fit between the physical room and the clinical surface.

---

## If this is authorised, it is its own sprint

Not a ceremony fix and not a patch. Minimum shape:

1. **Decide the comparison basis for the tablet rule**: effective branch, never the home lock, or
   cover is broken on day one.
2. **Decide what happens to a degraded lock** at login: deny, or fall through to today's
   behaviour. Denying converts a handled degradation into a lockout.
3. **Decide whether `cashier_pending` is history or active** for room purposes. That single
   question decides the room rule, and it has consequences for the cashier handover.
4. Tests first, including the cover case and the degraded case for the tablet rule, and the
   handover case for the room rule.
5. Its own PR, CI run, deploy and ceremony.

## What must not happen

Do not implement either rule inside the PR-B ceremony. No defect was found; the observed behaviour
matched the authorised specification in both cases. Changing runtime source on a live clinical
system to satisfy a requirement discovered mid-ceremony is how a proof turns into an incident.
