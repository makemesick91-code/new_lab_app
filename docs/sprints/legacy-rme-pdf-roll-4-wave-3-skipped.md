# LEGACY-RME-PDF-ROLL-4-WAVE-3 — Controlled Production Migration Wave-3

**Status: `SKIPPED / NOT REQUIRED`**

This is a governance record, not a wave report. **Wave-3 was never executed.**

| | |
|---|---|
| `WAVE_3_EXECUTED` | **NO** |
| `WAVE_3_CREATED_IN_PRODUCTION` | **NO** |
| `WAVE_3_ADMITTED` | **0** |
| `WAVE_3_PUBLISHED` | **0** |
| `WAVE_3_QUOTA_CONSUMED` | **0** |
| `WAVE_3_APPROVAL` | **NONE REQUESTED, NONE GRANTED** |
| `WAVE_3_GO_TAG` | **NONE — and none is required** |
| Determination | **SKIPPED / NOT REQUIRED** |

---

## Why it was skipped

`ROLL-4-WAVE-2R` already established sufficient controlled-production migration
evidence for the current rollout stage. It ran end to end on live clinical data
with a real maker (`u7`, Admin Klinik, pinned LDK2) and a genuinely separate
checker (`u11`, Supervisor RME), published four real Cabang Landak documents,
reconciled balanced (`unexplained 0`, `quota_drift 0`, `in_flight 0`), proved a
zero delta on every native clinical, billing, lab and SATUSEHAT table, and
returned production to the safe resting state (capability OFF, admission EMPTY,
active wave NONE).

A Wave-3 would have exercised **the same already-proven workflow again**. It
would have consumed a fresh owner approval, opened a real write capability over
live clinical data, and produced evidence the project already holds — while
leaving untouched the one thing Wave-2 actually exposed.

**What Wave-2 exposed was an operational gap, not a workflow doubt.** When the
operator aborted the wave, they could not canonically withdraw or progress the
staged imports over SSH: `cancel`, `review`, `publish` and `retry` existed only
as controller methods. The alternatives an operator reaches for at that moment —
a direct `UPDATE`, a Tinker `->update(['status' => ...])`, a hand-edited
`published_by`, a manually adjusted quota bucket — bypass the transition map, the
branch scope, the policy, the quota semantics and the audit trail simultaneously,
and leave clinical evidence asserting something that never happened.

Closing that gap was therefore the higher-value next step, and it became
**LEGACY-RME-OPS-CLI-1 — Canonical Import Lifecycle CLI & Abort-Recovery
Operations** (`docs/sprints/legacy-rme-ops-cli-1-canonical-import-lifecycle-cli.md`).

---

## What this decision does NOT mean

- It is **not** a statement that migration is finished. TKM1, ATG3 and SUN4 hold
  un-migrated archives and remain out of scope until separately approved.
- It is **not** a blanket future authorization. Any later migration wave is a
  **newly scoped, freshly approved operation** with its own exact branch set,
  ceiling, maker and checker.
- It is **not** a retroactive renaming opportunity. A future wave is not
  "Wave-3 resumed"; the Wave-3 slot is closed. Governance would have to reopen
  this decision explicitly to use that name again.
- It does **not** relax any rule. Every ROLL-3 admission, ROLL-4 operations,
  separation-of-duties, quota and reconciliation rule stays exactly as it is.

---

## Durable rule

> **A skipped wave is not a GO wave.** `SKIPPED / NOT REQUIRED` means no
> production wave was created, no approval was consumed, no branch was admitted,
> no quota was spent and no document was published. It must never be recorded,
> tagged, summarised or cited as a completed migration, and no
> `legacy-rme-pdf-roll-4-wave-3-*-go` tag exists or should ever be created for it.

Documentation must not leave Wave-3 reading as "still pending". It is closed.

---

## Verification

```bash
# No Wave-3 tag exists, and none should.
git tag --list '*wave-3*' '*wave3*'      # → empty

# No Wave-3 wave row was ever created in production.
php artisan legacy-rme:wave-status --json

# Production resting state is unchanged by this decision.
php artisan legacy-rme:migration-status --json
php artisan legacy-rme:rollout-readiness --json
```

Expected: capability OFF, admission EMPTY, active wave NONE — the state Wave-2R
left behind, untouched.
