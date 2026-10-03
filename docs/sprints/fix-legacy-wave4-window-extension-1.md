# FIX-LEGACY-WAVE4-WINDOW-EXTENSION-1 — extending an approved migration window

GO tag `fix-legacy-wave4-window-extension-1-go` @ `d16fca80` (PR #443, squash
merge of candidate `d75b45b4`). Deployed to the VPS pilot; the owner-approved
WAVE-4 extension was then performed through the new mechanism.

## The gap

When WAVE-4 passed its `2026-09-30` end date, `legacy-rme:ops-readiness` said:

```
WATCH  batch_window  The batch is past its planned end date.
-> Close the batch out, or record a fresh approval extending it.
```

**That remediation was impossible to carry out.** `planned_end_date` had exactly
one writer — `createWave()`, which `create()`s against a UNIQUE `code`. No
window setter on the governance service, no `extend` CLI action, no HTTP route.
Checked on all three surfaces.

So the only route the design offered was cancel-and-re-register under a new
code, which discards the batch's branch enrollments and operator assignments —
destructive, and ruled out by the owner (WAVE-4 had to stay ACTIVE). Same shape
as the known gap for the wave-level quota, so the capability was built once.

## What shipped

`LegacyRmeWaveGovernanceService::extendBatchWindow(actor, wave, endDate, reason)`
and `legacy-rme:wave-admin extend` (dry-run by default; `--apply` writes).

It writes **one column** inside `DB::transaction` + `lockForUpdate`, with four
things re-read *inside* the lock: the terminal-status check, the
separate-approver rule, `bindingMatches()`, and the current end date.

| Rule | Behaviour |
|---|---|
| Shortening | Refused; message names `drain`/`complete` |
| Null/empty end date | Refused — the expiry is not removable |
| Expiry-less wave given a past date | Refused |
| Start date | Read off the locked row, never from the caller |
| Terminal wave | Refused (policy + re-assert under lock) |
| Reason | Shared `assertReason()`, `min_reason_length` 10 |
| Legacy wave with null start | Extends fine (requirement is conditional) |
| Same date again | Allowed (idempotent) |

**Authorization is the approver's, not the manager's.** `extend` is gated on the
`approve` ability. Lengthening an approval window *is* an approval act; gating it
on `update` would let whoever runs the rollout grant their own batch more time.

`WAVE_WINDOW_EXTENDED` audit event carries actor, wave, status, start, and the
end date **before and after**, plus a bounded reason. Four new allowlisted
metadata keys. No PII.

## What the security review changed

The first commit claimed to join the stricter side of the maker/checker split
while skipping **both** extra guards `approve()` applies. That was not
hypothetical: the pilot runs `LEGACY_RME_REQUIRE_SEPARATE_APPROVER=true` and
WAVE-4 has `created_by=1`, so as written **the wave's own creator could have
extended their own batch**, and the approved production extension would have
been performed by that creator.

Fixed by sharing `assertSeparateApprover()` between `approve()` and
`extendBatchWindow()` — a rule enforced at one of two call sites is a rule with
a hole in it — and re-verifying `bindingMatches()` inside the lock, as
`approve`/`activate`/`resume` all do. Three further fixes: the reason floor, the
conditional start-date requirement, and the lapsed-window guard for an
expiry-less wave. The CLI also stopped reporting a pre-lock `before` value that
two concurrent runs could make wrong.

## Verification

- Batch-window suite **47 passed**; `tests/Feature/LegacyRme` +
  `tests/Feature/LegacyMassUpload` **1083 passed**, 12 pre-existing GD skips, 0
  failed; `pint --dirty` + `git diff --check` clean
- DEVFLOW: manifest GO, scope-audit GO (1 module), devflow-check GO,
  shared-service-audit GO
- CI green on the exact candidate `d75b45b4`: Classifier, NSF-R012, Android
  gate, NSF-R011 Critical, Selective Module, NSF-9, NSF-10. The self-hosted
  Critical variant SKIPPED (runner offline — exactly one variant runs by
  design); Full Suite skipped per standing policy
- `foundation:security-compliance-check` / `foundation:ci-runtime-control-check`
  FAIL in a fresh worktree because its DB is sqlite `:memory:` so
  `sys_audit_logs` cannot exist. Verified environmental by re-running both
  against the **unmodified base** — identical FAIL

## Production execution (2026-10-04 WITA)

Deploy: `scripts/deploy-vps-runner.sh start` on the VPS → exit=0, `DEPLOY OK`,
`DEPLOY_HEAD_TARGET_MATCH=YES (d16fca80)`, `Nothing to migrate.`
Backups: `pre_wave4_window_extension_20261003-160645.sql`,
`pre_wave4_window_extend_apply_20261003-182719.sql`.

Performed by **user 11 (Jene Monika, Supervisor RME)** — the separate approver,
and the account that approved WAVE-4 originally:

```
legacy-rme:wave-admin extend --wave=WAVE-4 --planned-end-date=2026-10-31 \
  --reason="owner approval 2026-10-04 extend WAVE-4 through 2026-10-31" \
  --actor=11 --apply
```

| | Before | After |
|---|---|---|
| `planned_end_date` | 2026-09-30 | **2026-10-31** |
| `planned_start_date` | 2026-08-28 | 2026-08-28 (unchanged) |
| status | ACTIVE | ACTIVE |
| `approval_reference` | ROLL-4-WAVE-4-OWNER-APPROVAL-2026-08-28 | unchanged |
| `per_branch_daily_quota` | 100 | 100 |
| `batch_window` | **WATCH** (expired) | **GO** — "Today is inside the batch planned window" |

Audit row 1100 `LEGACY_RME_WAVE_WINDOW_EXTENDED`, `performed_by=11`,
before `2026-09-30` → after `2026-10-31`.

**The separate-approver guard proven live:** a retry as user 1 (the creator) with
`--apply` was refused — *"Gelombang migrasi harus disetujui oleh pengguna yang
berbeda dari pembuatnya"* — audit count stayed at 1 and the window did not move.

Unchanged and verified: all four branch enrollments ACTIVE; all 5 operator rows
intact; Admin Sunu fail-closed matrix still SPN4 **ALLOWED** / TLK1, LDK2, ATG3,
MAIN **DENIED**; all four branches READY with no blockers.

`/login`, `/health/live`, `/health/ready` 200 over the canonical domain;
`settings/rme/legacy-mass-imports` 302 for a guest; env pilot, debug off,
maintenance off; **0 Laravel errors**. GO tag exact-match at VPS HEAD.

## Still open, deliberately

`ops-readiness` overall remains **WATCH / "Ready for a routine batch: NO"**, now
solely because of `batch_size_policy` — WAVE-4 declares no wave-level daily
quota, which is what preserves 100 **per branch**. That WATCH is pre-existing and
previously accepted by the owner, and this task was scoped to leave quota and
batch-size policy untouched. There is no post-registration setter for the
wave-level quota; cancel plus re-register under a new code remains the only way
to change it.

Batch 4 is terminal. The operator starts a **new** mass-upload package.
