# DMS-RME-REVISION-PROGRAM-8-OWNER-OPERATIONAL-CLOSURE-1 — owner checkpoint record

**Date:** 2026-11-07 → 2026-11-08 (WITA = UTC+8). **Type:** governance / owner checkpoint (no application code here).
**Production runtime at close:** `b0c0bd62` (tree `022eb55f`), Program 8C, tag `dms-rme-revision-program-8c-go`.
**Previous checkpoint:** the Program 8 re-audit (verdict NO-GO, 0 code blockers, owner decisions D1–D7 and device
outstanding). Program 8B closed D1, D2, D6 and D7.

This mission resolves what an owner checkpoint can resolve: D3, the Owner Decision Register; D4, dedicated
real-device verification; and D5, Legacy WAVE-4 closure. It records the remaining items honestly as open rather than
closed. **Silence was never treated as approval**, and no item is classified CLOSED, ACCEPTED or PASS unless the
owner said so or a measurement shows it.

## Verdict

```text
MISSION_VERDICT                              = PARTIAL_GO
D3  Owner Decision Register                  = DECIDED IN PART — implemented by Program 8C; residuals OPEN
D4  Dedicated real-device verification       = BLOCKED_EXTERNAL
D5  Legacy WAVE-4 closure                    = PREPARED / OPEN — awaiting four branch confirmations
dms-rme-revision-program-8c-go               = CREATED (b0c0bd62)
dms-rme-revision-program-8-owner-operational-closure-go = NOT CREATED
dms-rme-final-readiness-go                   = NOT CREATED
FULL_SUITE                                   = NOT RUN, NOT PASSED (temporary policy ACTIVE)
CLINIC_REOPENING                             = NOT AUTHORISED
```

The closure GO tag is withheld because D4 is blocked, D5 is open and the residuals have no approved disposition.
Creating it would claim a closure that has not happened.

## D3 — Owner Decision Register

### Decided and implemented (Program 8C)

| Decision | Rows | Owner answer | Delivered |
|---|---|---|---|
| Group 1, clinical write authority | OD-7-2 + R8A-1, OD-5-7, OD-5-2, OD-5-3, D3-NEW-1 | FIX NOW | 8C PR1 (#617) |
| Group 2, document integrity | OD-8A-2, OD-8A-1, P8R-S5, OD-6-2, D3-NEW-6 | FIX NOW: lock after completion; every earlier change audited | 8C PR2 (#618) |
| Signature authority | D3-NEW-2, OD-5-1 | Named doctor signs only; other clinical roles keep content authoring | 8C PR2 |
| Clinical-document read allowlist | D3-NEW-4 | FIX NOW: clinical roles and Owner keep access | 8C PR1 |

Program 8C also closed the reviewer's combined finding on these rows: a completed visit's prescription is now
read-only, and every earlier create or update writes an audit row.

The detail is in `docs/sprints/dms-rme-revision-program-8c.md`, in rule
`.cursor/rules/228-rme-clinical-write-authority-and-prescription-integrity.mdc` and in AIR-RMC-50..53.

### Decided, not implemented by design

| Row | Owner answer |
|---|---|
| D3-NEW-3 / D3-NEW-5 | **DEFER WITH CONDITIONS**, no schema change now. Remediation is required before multi-branch front-desk handwriting use, or at once as a blocker if a cross-branch disclosure of page content is confirmed. The risk analysis is in the private record. |
| OD-7-4 | **Keep denied.** A doctor cannot read a published legacy odontogram, and granting it would widen access. |
| RA-4 | A **separately governed** corrective sprint. |

### Open — no disposition approved

The owner chose **"Not yet, keep them open"**. None of these is ACCEPTED, none is CLOSED, and none was changed:

- **16 low-risk residuals:**
  - OD-5-4, OD-5-5, OD-7-5, OD-7-6, OD-7-7, R7-1, R7-F, P8-FIX1;
  - P8-SEED, OPS-SAT, R8A-2, RA-1, RA-3, RA-4, OD-6-1, R8A-3.
- **P3 backlog**, to be reviewed individually: P8R-S6, OD-7-3, OD-5-6, OD-5-10, OD-5-9 + P8R-S4b, OD-7-1, OD-5-8 (UX)
  and OPS-KTP.
- **Time-sensitive item, P8R-S6:** if billing is live, it should be decided before **2026-12-01 00:00 WITA**, the
  next month boundary.

The private Owner Decision Matrix holds the proposed disposition for each item, with its compensating controls and
revisit trigger. It is kept outside the repository because the repository is public.

### New open items raised by Program 8C

- **LOW (independent review round 2):**
  - The consent-signature fields are bounded by the general payload limit and the pixel ceiling, but not by the 1 MB
    signature limit.
  - Fix this in the next code-bearing sprint.
- **OWNER DECISION:**
  - An unsigned prescription prints without a signature image.
  - Should print, and the (disabled) WhatsApp hand-off, be refused until the named doctor signs?
  - That question was not part of the 2026-11-07 decisions and is not implemented.
- **Comment only:**
  - Two policy docblocks still describe the pre-8C read scope; the code is correct.
  - This is listed in rule 228 §15.

## D4 — Dedicated real-device verification

**`D4_STATUS = BLOCKED_EXTERNAL`** — not PASS and not ACCEPTED. The owner's answer was "Not available, keep BLOCKED".

The trusted-device estate was measured read-only:

| Item | Value |
|---|---|
| Devices | 12, of which 7 are eligible (active + cryptographically verified) and 2 are revoked |
| Authorization pairs (doctor × eligible device) | 105 target / 105 active / 0 missing / 0 duplicate |
| Level 1 activation-test coverage | PASS (measured and attested, no contradiction) |
| Level 3 spare device per branch | FAIL (measured, not attested) |
| Candidate dedicated device | id 11: not eligible, no usable WebAuthn credential, no test doctor linked |

D4 becomes runnable once the owner provides two things:

1. **A dedicated device.** It must not be one of the clinic's operational tablets, which stay frozen. It must be
   enrolled through the canonical approval path and pass the Android Keystore proof. A WebAuthn credential alone
   does not make a device eligible.
2. **A dedicated test doctor account.** It must be linked one-to-one through Master Data → Relasi Akun Dokter, and it
   must never be a real clinician's account.

The ceremony also needs a device-bound passkey: a synced passkey is refused by design, and the device-bound
requirement must never be relaxed to get past that.

The ceremony itself runs against a fresh audit watermark and covers:

- one genuine login;
- one protected request;
- an attributable denial for each negative case: wrong room, unsigned consent, completed visit, a signature by a
  non-named doctor, and a cross-branch read by an unclassified role;
- logout releasing the session lease.

The automated suites already prove the server-side rules on SQLite and PostgreSQL 16, but they cannot prove the
browser and WebAuthn ceremony on a physical tablet. `webauthn:readiness` reporting ARMED means "configured", not
"proven live".

## D5 — Legacy RME WAVE-4 closure

**`D5_STATUS = OPEN — AWAITING BRANCH CONFIRMATION (SPN4, TLK1, LDK2, ATG3)`** — not CLOSED. The owner approved the
preparation. No final transition may run until all four branches confirm whether paper archives remain.

| Owner condition | State |
|---|---|
| 1. 20 accepted = 20 published, reconciled | **MET** — `balanced = true`, unexplained 0, quota drift 0, void 0; all 20 at SPN4 |
| 2. All four branches confirm whether paper archives remain | **NOT MET** — no confirmation recorded |
| 3. Nothing pending, failed, in flight or unexplained | **MET** — queue 0, backlog 0, failed 0, in flight 0 |

TLK1, LDK2 and ATG3 accepted zero documents in the whole window. That is consistent with two different situations:
the branch has no paper archive, or its archive was never started. Only the branch can say which, and that is why
condition 2 exists.

**WATCH, left as-is by owner decision:**

- The approved window ended on **2026-10-31**, but intake is still open at all four branches.
- A lapsed window is a readiness finding, not a write refusal, so the server still accepts uploads.
- On 2026-11-08 the owner chose to leave production unchanged until the branches confirm.
- There is no automatic extension. Extending the window is an approval act, performed by the separate approver.

After condition 2 is met, the canonical closure path is as follows. Every step is dry-run first and then applied,
by the runtime user, on the Biznet host, with no SQL and no REPL.

1. Record the four branch confirmations.
2. Run `legacy-rme:wave-admin branch-drain` for each branch.
3. Re-check `legacy-rme:migration-status`.
4. Run `branch-complete` for each branch, then `complete`.
5. Clear the four admission keys and rebuild the config cache as the runtime user.
6. Verify the AT_REST state, with the published count still 20.

Constraints that hold throughout:

- Published archives stay readable.
- The queue worker is not activated.
- Nothing is extended automatically.

None of these steps has been executed.

## Constraints held across the mission

- Full Suite: not run and not retired. Every CI run in the mission deferred it, including the post-merge push.
- No clinic reopening.
- No global trusted-device enforcement. The doctor scope stays `pilot`, covering `[9,15,18]`, with global false.
- No expansion of the doctor cohort.
- No operational clinic device used.
- Queue worker, scheduler and KTP-cleanup timer not started and not enabled.
- No destructive command, no production test, no REPL on production.
- No financial history changed and no clinical record rewritten.
- The Hostinger host was not touched.
- No PII and no exploit detail in this repository. Analyses, raw review output and the per-item matrix are kept in the
  owner's private evidence package.

## WATCH at close

- **BK-2:**
  - After the 8C deploy, the official pull reported `OFF_SERVER=PENDING pending=1` with the backup HDD locked.
  - The pre-deploy backup exists on the server; copying it off-server needs the custodian to unlock the HDD.
  - It was not unlocked automatically.
- **WAVE-4 window lapsed while intake is open** (see D5).

## What closes the mission

The mission closes when the closure tag `dms-rme-revision-program-8-owner-operational-closure-go` can honestly be
created, which needs all of the following:

1. D4 executed on a dedicated device with a dedicated test doctor, and its evidence recorded.
2. D5's four branch confirmations recorded, and either the canonical closure run or a dated, approved extension.
3. An owner disposition for each of the 16 residuals and each P3 item.
4. A decision on the unsigned-print question.

Neither the final readiness tag nor clinic reopening follows from this record.
