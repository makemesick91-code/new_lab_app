# REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1

Staged verification and cancellation for the Legacy Patient importer.

**Scope:** Legacy Patient only. Legacy RME and Legacy Odontogram governance is
untouched — symmetry is not a reason to reopen closed work.

| | |
|---|---|
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Base SHA | `26ae1bd035c66a0d4c6f3f87b915943f7bf7543b` |
| Prior GO tag | `revision-legacy-visit-bound-preverified-ingestion-1-go` |
| Migration | 1, additive |
| New route | none |
| New permission | none |
| Production PostgreSQL | 16.15 |

## The rule

**Uploading a legacy patient CSV is staging. It is not importing.**

```
upload -> store privately + sha256 -> stage -> validate the WHOLE batch -> review
    ERROR > 0  -> the WHOLE batch is refused; it may be cancelled
    ERROR = 0  -> operator explicitly confirms
               -> server revalidates against the database as it stands NOW
               -> every approved row commits, or none does
```

## What was already right

Sprint 62.3 had already built the staging → preview → commit workflow. `mst_patients`
is written in exactly one place, `LegacyPatientImportService::commit()`. Upload,
validation, preview, the report and cancellation created zero patients before this
revision and still do.

Also already correct, and re-pinned rather than rebuilt: blank KTP allowed and
stored as `null`; hard RM/KTP duplicate blocking including soft-deleted and
in-file; soft name+DOB duplicate as a warning; WARNING distinct from ERROR; no
daily business quota (`NULL`, per REVISION-SUNU-LEGACY-IMPORT-UNLIMITED-ADMIN-ACCESS-1);
private `local` disk storage; branch server-resolved from the CSV `Cabang` column.

## What was wrong

**G1 — ERROR did not block the batch.** `commit()` selected `whereIn(status, [valid, warning])`;
error rows were *excluded*, not blocking. `committable()` ignored `error_rows`
entirely and the preview rendered "Commit Baris Valid" with "Akan di-commit:
valid + warning". A 500-row file with 3 bad rows imported **497 patients**, and
nothing afterwards could answer *which* 497 from the file the operator still held.
This was pinned by a passing test named `commit skips error rows`.

**G2 — no confirm-time revalidation, and the race check imported partially.** The
only recheck was a per-row RM/KTP probe, and a row that had become a duplicate was
marked `skipped` while its siblings committed.

**G3 — the source sha256 was written at upload and never read again.**

**G4 — no confirmation step, and cancellation left no evidence.** Confirming was a
bare POST behind a JavaScript `confirm()`. Cancelling was `discard()`, which
soft-deleted the whole batch: the record that a file had been uploaded, reviewed
and rejected disappeared with it.

## What changed

**Model** — `STATUS_CANCELLED`; `isReviewRequired()` / `isReadyToImport()` derived
from `error_rows` (not stored: a stored review state can disagree with the
counters); `committable()` is now `isReadyToImport()`; `isCancellable()`.

**Service** — `commit()` refuses the whole batch on any error, in three ordered
gates: source identity, then revalidation, then the inserts in one transaction
under a header row lock. `revalidate()` re-runs the full rule set against current
database state. `cancel()` replaces `discard()`'s soft-delete. `assertSourceUnchanged()`
re-hashes the stored file. Canonical `AuditLogService` events, PII-free.

**Ordering that is load-bearing:** revalidation persists its verdict *outside* the
insert transaction. Inside it, the throw that refuses the import would unwind the
very evidence the operator needs, and they would be told "blocked" while the
preview still showed a clean batch.

**Deciding on fresh state, never on the counters.** The stored counters are a
snapshot of a past moment, and the point of the gate is that the moment has
passed. Confirmation always revalidates first — including for a batch that already
showed errors, so a conflict that goes away lets the batch through instead of
forcing a re-upload of a file that was correct.

**Revalidation cost is bounded.** The batch's own composed RMs and KTPs are derived
first (no patient query), then resolved in chunked `whereIn` lookups. Round trips
are bounded by chunks, not by rows. Nothing scans the patient estate.

**Also fixed, found during the audit:** the exported report did not neutralise
spreadsheet formula injection — a patient named `=cmd()` shipped as a live formula.
And both the report and the preview issued one `Branch::find()` per row.

## Migration

`2026_09_27_100001_add_staged_verification_cancellation_to_legacy_patient_import_batches_table`
adds six nullable/defaulted columns to the staging header: `cancelled_by`,
`cancelled_at`, `cancel_reason`, `revalidation_attempts`, `revalidated_at`,
`source_verified_at`. Nothing dropped, nothing made NOT NULL, no backfill.

Cancellation is *not* written into `rolled_back_by`/`rolled_back_at`: a cancelled
batch created zero patients, a rolled-back batch created patients and withdrew
them. Conflating them would make the audit trail a false statement about the
patient estate.

No `confirmed_by`/`confirmed_at` pair: confirmation and commitment are one atomic
request, so a second pair that always equalled `committed_by`/`committed_at` would
be decoration.

## Two decisions worth stating

**Operator-branch narrowing is out of scope.** The brief asked that an Admin Sunu
batch "resolve to SPN4" and that other branches be denied. The canonical authority
is the CSV `Cabang` column, resolved server-side against active + RME-enabled
branches with MAIN excluded — stated verbatim in `config/legacy_import_hub.php`
("A request-supplied branch is never the authority") — and canonical patient CRUD
(`StorePatientRequest`/`UpdatePatientRequest`) likewise accepts any `branch_id`
under the same unscoped `manage patients` master-data permission. Narrowing the
importer to `BranchContext` would invent a new branch authority and diverge from
the CRUD it sits beside. The tested invariant is the truthful one: there is no
request-supplied branch field, and MAIN / inactive / non-RME / unknown are errors.
**Whether `manage patients` should be branch-scoped is a real product question and
is left open, not silently answered.**

**No permission changed.** `manage patients` is held by Admin Klinik, Front Office,
Perawat and Supervisor RME (plus Super Admin via `Gate::before`). Admin Sunu is
Front Office and was already capable. Nothing was granted and nothing revoked.

## Evidence

| Check | Result |
|---|---|
| `LegacyPatientStagedVerificationCancelTest` (new) | 47 passed |
| `LegacyPatientBatchImportTest` (Sprint 62.3, 4 assertions inverted) | 24 passed |
| Both suites, SQLite | 71 passed |
| Both suites, **PostgreSQL 16.15** | 71 passed |
| Two-process concurrency, PostgreSQL 16.15 | 10/10 runs, every invariant held |
| Mutation | 11 of 12 killed — see below |

### Concurrency

Two OS processes, a start barrier so both are booted before either touches the
row, against PostgreSQL 16.15. Ten runs across two scenarios. Invariants asserted
every run: patients is 0 or exactly 5 and never partial; no duplicate
`medical_record_number`; at most one winner; no unhandled exception.

The harness found a real defect. A losing concurrent confirm refreshed the verdict
of a batch the winner had just committed, resetting `status` from `committed` back
to `validated` while `committed_rows` stayed at 5. Rollback keys off
`isCommitted()`, so five real patients would have become impossible to withdraw
through the workflow that created them. `revalidate()` now re-reads the lifecycle
from the database and returns untouched unless the batch is still awaiting review.

### Mutation

Each guard deleted in turn, the suites re-run. Files restored from byte copies,
never from git. A replacement that does not apply is NOT-APPLIED, never a kill;
a mutant that does not compile is INVALID-MUTANT; compiled Blade is cleared
between runs.

| Mutant | Result |
|---|---|
| M1 upload directly imports the patients | KILLED (2 failed) |
| M2 the ERROR gate in confirm() is removed | KILLED (2 failed) |
| M3 `committable()` ignores error rows | KILLED |
| M4 the cancellation boundary admits an imported batch | KILLED |
| M5 the explicit confirmation requirement is removed | KILLED |
| M6 confirm-time revalidation is removed | KILLED |
| M7 the source sha256 verification never refuses | KILLED |
| M8 a blank KTP becomes a fabricated placeholder | KILLED (13 failed) |
| M9 branch authority trusts a request-supplied `branch_id` | KILLED |
| M10 the import is no longer one transaction | KILLED |
| M11 the in-transaction header lock and its re-assert are removed | **SURVIVED** |
| M12 duplicate identity detection is removed | KILLED (5 failed) |

**MUTANTS_TOTAL=12 MUTANTS_KILLED=11 MUTANTS_SURVIVED=1.** No mutation-score tool
is installed in this repository; this is the manual harness the repo already uses,
and the figure is a count, not a tool-reported score.

**M11 survived, and the reason is worth recording rather than engineering around.**
Removing the lock does **not** create a duplicate patient: `mst_patients.medical_record_number`
and `.ktp_number` are both UNIQUE, so the loser's INSERT fails and the transaction
unwinds. That is what the code's own comment claims, and the mutant confirms it.
What the lock changes is the loser's refusal *reason*, measured directly on
PostgreSQL 16 with two processes, reproduced twice each way:

* with the lock — `not_ready` ("another request processed this batch");
* without it — `revalidation_failed` ("your data went stale").

The second is a lie with consequences: it sends an operator to fix a file that was
never wrong. The lock is kept for that, and because the guarantee should not rest
on a single mechanism.

**This is a named coverage gap.** The reason-code difference appears only in the
genuinely concurrent window, where neither process has yet seen the other's
commit. An in-process test cannot reach it: the hardened `revalidate()` re-read
produces the same `not_ready` refusal on the deterministic stale-instance path, so
`refuses a stale confirmation with a lifecycle reason` passes with or without the
lock. It is proven by the documented two-process procedure above and **not** by the
automated suite. Manufacturing an in-process test that appeared to kill M11 would
have been a false green.

## Security review

Reviewed against the threat list for this change. No unresolved CRITICAL or HIGH.

| Threat | Finding |
|---|---|
| Unauthorized patient creation | **Gated.** Every action sits in the `permission:manage patients` route group; a user without it gets 403 on confirm, cancel and the report. Tested. |
| Forged confirmation | **Refused.** The acknowledgement field is not the control — the state gates are, and they run server-side. A direct POST with `acknowledged=1` against a batch with errors is refused. Tested. |
| Confirmation replay / double confirmation | **Idempotent.** `isCommitted()` early return, then a lifecycle gate, then a header row lock with the state re-read inside the transaction. Tested in-process and with two OS processes. |
| Race between preview and confirm | **Refuses the batch.** Revalidation catches it; if it slips through, the UNIQUE indexes make the INSERT fail and the transaction unwinds. Ten two-process runs: never a duplicate, never a partial import. |
| Source-file substitution | **Refused.** The stored file is re-hashed at confirmation and compared with `hash_equals`. A changed or missing file moves the batch to `failed`. Tested. |
| Source-hash bypass | **Bounded.** A batch with no `stored_path`/`file_hash` skips the comparison — a pre-existing batch staged before this revision legitimately has neither, and refusing those would turn a deploy into an outage for work already in review. It does **not** fake a pass: `source_verified_at` stays null, so nothing downstream can read silence as proof. |
| Staged-verdict tampering | **Healed, not trusted.** Confirmation recomputes every row's verdict from the immutable `raw_payload`, so flipping a row's `status` from `error` to `valid`, or editing `normalized_payload`, changes nothing — the recomputed verdict wins. The `rolls back every insert` test demonstrates this incidentally: corrupting `normalized_payload` was healed by revalidation, which is why that test injects its failure at INSERT time instead. |
| Staged-**source** tampering | **Out of reach of this control, and stated as such.** The sha256 binds the FILE, not the staging rows. An attacker with write access to `stg_legacy_patient_imports.raw_payload` could change the verdict — but that same access can create patients directly, so this control is not what stands between them and the estate. |
| Private source-file exposure | **Never served.** Stored on the private `local` disk. No route, controller or view reads `stored_path` except the service's own hash check — verified against the code, not assumed. |
| CSV / spreadsheet formula injection | **Fixed in this revision.** The verification report did not neutralise it; a patient named `=cmd()` shipped as a live formula. Every exported cell now passes the canonical `csvSafe` prefix. Tested. |
| PII in reports | **Masked.** The report carries `ktp_masked` only; the full KTP never appears. Tested by asserting the full number is absent. |
| PII in logs | **Structurally excluded.** Audit payloads are assembled from counts, statuses and ids. The original filename is deliberately omitted — operators name files after people. Tested by asserting a patient name and the filename are absent from every payload. |
| Cross-branch import | **Unchanged.** Branch is server-resolved from the CSV column against active + RME-enabled branches, MAIN excluded. There is no request-supplied branch field; a crafted `branch_id` in the body is ignored. Tested. |
| Permission widening | **None.** No permission, role, policy, seeder or middleware touched. Verified against the diff. |
| Batch IDOR | **Pre-existing and unchanged.** Batches are not scoped to their uploader or to a branch: any holder of `manage patients` can view, confirm or cancel any batch. That matches the permission's design — it is unscoped master-data authority, the same authority that lets the holder edit any patient — and narrowing it here without narrowing patient CRUD would be a divergence, not a fix. Flagged as an open product question, not silently accepted as correct. |
| Cancellation as a deletion path | **Impossible.** Cancellation is pre-commit only and touches no patient row. After commitment the only path is `rollback()`, which refuses when an imported patient already has a visit or medical record. Tested. |
| Mass-import denial of service | **Bounded, with one pre-existing caveat.** Confirmation is cheap: identity conflicts are prefetched in chunked lookups, so a 500-row batch confirms in under a second. Upload-time validation remains O(rows) in queries — pre-existing Sprint 62.3 behaviour, unchanged here — and the 5 MB file cap bounds it. |
| Duplicate amplification | **Prevented twice.** Validation blocks RM and KTP collisions including soft-deleted and in-file; the UNIQUE indexes stop anything that slips past. |
| Fabricated identity | **Impossible by construction.** A blank KTP is stored as `null`. A sentinel would collide on the unique index and turn "no KTP" into a duplicate, which is how the absence of this bug is observable: three blank-KTP patients coexist. Tested. |

## Durable rules

Recorded in `CLAUDE.md` and `.cursor/rules/92-legacy-patient-staged-verification-cancel.mdc`.
