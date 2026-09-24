# REVISION-SUNU-LEGACY-IMPORT-UNLIMITED-ADMIN-ACCESS-1

**Branch** `feature/sunu-legacy-import-unlimited-admin-access-1`
**Base** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `c5661b1c`
(the SUNU-GO-LIVE-READINESS-1 GO commit; that tag is not reopened or moved)

## Why

Cabang Sunu goes live with a legacy paper estate that has to be registered
first. The Legacy Import Hub shipped a business ceiling of **100 accepted
records per import type, per branch, per clinical day**. An operator migrating
a real estate hits that ceiling and then waits for the clinical calendar to
roll — for every import type, every day, until the estate is done.

The owner's decision is that the complete estate must be migratable without
waiting for a daily counter to reset.

## What changed

Exactly one production behaviour: **the shipped daily business ceiling is now
absent** for all three legacy import types.

`config/legacy_import_hub.php`

- `daily_limit.legacy_patient`, `.legacy_rme` and `.legacy_odontogram` now
  default to **NULL — no business ceiling** instead of `100`.
- The env reader now resolves `LEGACY_IMPORT_HUB_DAILY_LIMIT` through the same
  normalizer as the per-type overrides, so a ceiling can still be declared
  (as an integer) or explicitly disabled (`none` / `null` / `unlimited`).
- Three invariants added and pinned by tests:
  `business_quota_unlimited_by_default`, `unlimited_is_null_not_a_sentinel`,
  `technical_backpressure_survives_quota_removal`.

**No migration. No new permission. No role change. No route change. No schema
change.**

### Why NULL rather than a large number

`LegacyImportDailyQuotaService` already speaks this vocabulary: **NULL declines
to limit, 0 admits nothing.** Unlimited is therefore expressed as the absence
of a ceiling, in the engine's own terms.

A sentinel (`999999`, `PHP_INT_MAX`) would still be a ceiling. It would refuse
the day an estate exceeded it, and it would render to the operator as a
countdown — a number that looks like a budget and eventually behaves like one.

A consequence worth stating plainly: with no ceiling the service deliberately
**writes no quota bucket and counts nothing** (a counter nobody reads is a
drift source it would have to reconcile forever). The hub therefore reports
`limit = null` and `remaining_today = null` rather than a countdown. Volume
observability comes from the staging and record tables, which are the real
source. This is pre-existing engine behaviour, not new.

## What deliberately did NOT change

Removing a **count** authorizes nobody. Each of these still refuses on its own
and is asserted in the test suite:

| Gate | Status |
|---|---|
| Capability feature flags | unchanged |
| Branch admission allowlist + wave approval | unchanged, independently enforced |
| RM-derived branch authority | unchanged |
| Permissions and policies | unchanged — **no permission added or widened** |
| Duplicate detection / idempotency | unchanged |
| Human review, publish and VOID controls | unchanged |
| Legacy RME eligibility and date rules | unchanged |
| Upload size, MIME, PDF page and render caps | unchanged |
| Queue backpressure, worker concurrency, free-disk floor | unchanged |
| Private storage, audit logging, DoctorPatientScope | unchanged |

> Quota removal is not branch authorization. A branch still migrates only if
> admission, approval, permissions and validation all pass.

## One operational characteristic, stated plainly

`LegacyPatientImportService::commit()` commits a staged batch inside a **single
`DB::transaction`**, and `reserveMany()` is taken once, near the end of that
transaction, for the whole batch.

That means the transaction is as large as the staged batch — bounded by the
5 MB CSV upload cap, not by the quota.

**This shape is unchanged by the revision, and the revision does not make the
transaction bigger.** Before the change a 150-row CSV inserted all 150 patients
inside the transaction, *then* hit the ceiling at `reserveMany()`, threw, and
discarded every insert. The same work happened; it was simply thrown away and
the operator could never get a batch past 100 rows. After the change the same
transaction commits.

So this is not "unlimited implemented as one giant synchronous transaction" —
it is a pre-existing batch-atomicity property that the retired ceiling was
hiding by guaranteeing failure. It is recorded here rather than left implicit,
and the operator checklist recommends splitting large files, which keeps each
transaction small and makes a mistake cost one file instead of an afternoon.

The archive importers (RME, Odontogram) are unaffected: they accept one
document per upload and hand rendering to the queue, where ROLL-3 backpressure
governs load.

## Admin Sunu access — the audit finding

The mission asked for Admin Sunu (user 29, Front Office, SPN4) to reach all
three upload surfaces. **No access change was required, and none was made.**

The audit found the `Front Office` role already carries every permission the
three importers demand, and that production already reflects it:

| Surface | Required | Front Office holds it |
|---|---|---|
| Legacy Pasien | `manage patients` | yes |
| Legacy RME | `view_legacy_rme_imports`, `create_legacy_rme_imports` | yes |
| Legacy Odontogram | `view_legacy_odontogram_imports`, `create_legacy_odontogram_imports` | yes |

So the mission's escape hatch — "if the architecture cannot target user 29
only, STOP and report" — never had to be taken: no role was widened, no
per-user grant was invented, and no other Front Office account gained anything,
because nothing was granted at all.

Least privilege is intact: Front Office holds **no** `review_*`, `publish_*` or
`void_*` legacy permission. Uploading is not publishing.

### Other Front Office accounts — stated precisely

Production carries **eight** active Front Office accounts (Yuni FO, Dhea, Dhea
Putri, Maghfirah Abdullah, Admin Sunu, Admin Landak, Admin Antang, Admin
Telkomas), and **all eight already held these permissions before this
revision**. There are no per-user legacy grants anywhere in production.

So "other Front Office users are unchanged" is literally true — but not because
this work kept them narrow. They could already reach these surfaces; each is
confined to their own branch by server-side branch resolution, which is what
actually contains them. The brief's concern about accidentally widening every
Front Office account could not arise here, because nothing was granted.

If the owner wants legacy upload restricted to *fewer* accounts than the role
currently reaches, that is a separate, deliberate change to the role or to
per-user grants — and it would narrow existing access rather than preserve it.

Admin Sunu's branch stays SPN4 through the existing server-side resolution
(`FEATURE_FRONT_OFFICE_BRANCH_CONTEXT_LOCK=true`, cohort `29:SPN4`, and a
daily branch context pinned to branch 5). `users.branch_id` is null for this
account; the branch comes from the context lock, not the column.

## Two audit corrections worth recording

**1. `SUN4` in the production allowlist is not a defect.** The admission
allowlist declares `TLK1,LDK2,ATG3,SUN4` while Cabang Sunu's canonical code is
`SPN4`. That looks like a fail-closed lockout and was initially read as one.
It is not: `LegacyRmeBranchAdmissionService` canonicalizes **both** sides
through `BranchCodeAlias`, so `SUN4` resolves to `SPN4`. An earlier revision
built this alias layer for exactly this failure mode. Production
`legacy-rme:rollout-readiness --expect=on` reports `branch_admission: GO`.
A regression test pins the alias resolution.

**2. Readiness must be run as the runtime user.** Running
`legacy-rme:rollout-readiness` as `www-data` reports
`private_disk_writable: UNKNOWN` and an overall **NO_GO**. The runtime user is
`daengtisiams` (the PHP-FPM pool user); run as that user the same check is
**GO**. The UNKNOWN is the probe failing, not the disk. Always run legacy
readiness as `daengtisiams`.

## Patients without a NIK — checked, not a product gap

The brief asked whether the absence of no-NIK support would block a real
migration, and whether one needed inventing. It does not, and it does not.

`LegacyPatientImportService` stores a blank Nomor KTP as `NULL`
(`'ktp_number' => $ktp !== '' ? $ktp : null`) and runs the identity-collision
check **only** when a value is present. A minor with no NIK therefore imports
cleanly by leaving the column empty. There is no sentinel to invent and no
schema to add.

The operator consequence is recorded in the checklist: rows without an identity
number cannot be duplicate-checked on it, so they rely on the medical-record
collision check plus the name + date-of-birth warning. That warning is the only
duplicate signal those rows have, which matters more now that volume is
unbounded.

## Tests

New: `tests/Feature/LegacyImportHub/SunuLegacyImportUnlimitedAccessTest.php`
(20 tests) covering the owner's required matrix — unlimited for all three
types, a single batch past the old ceiling, no waiting for a day roll,
unlimited-is-absent-not-a-sentinel, no bucket written, a later-declared ceiling
still honoured, queue backpressure still saturating, technical rails intact,
admission independently enforced, MAIN never admitted, the SUN4→SPN4 alias,
Front Office reach, publish/review/VOID withheld, unauthorized refused, the
role unwidened, per-branch metering, and the duplicate/idempotency invariants.

Updated (the ceiling is now declared in-test rather than inherited from a
default the product no longer has — the metering mechanism stays covered):
`LegacyImportHubQuotaTest`, `LegacyImportHubIntegrationTest`,
`LegacyImportHubSurfaceTest`. The old "declares a ceiling of 100" contract test
is replaced by "ships no business ceiling for any import type", which reads the
**shipped config file** rather than the runtime value so a test-set ceiling
cannot satisfy it.

## Mutation evidence

A passing test proves nothing if it also passes with the guard removed. Each
guard was deleted in turn and the suite re-run; **6 of 6 mutants were killed**.

| Mutant | Result |
|---|---|
| M1 restore the retired 100/day business ceiling | KILLED (7 failed) |
| M2 express unlimited as a large sentinel (`999999`) instead of absence | KILLED (6 failed) |
| M3 drop branch-code alias canonicalization from the allowlist | KILLED |
| M4 widen Front Office with `publish_legacy_rme_imports` | KILLED |
| M5 disable ingestion capacity backpressure | KILLED (2 failed) |
| M6 pool every branch into one shared quota bucket | KILLED |

M6 was first written as a syntactically invalid mutant. The harness reports
that as `INVALID-MUTANT`, never as a kill — a mutant that does not compile
proves nothing about the guard. It was rewritten and re-run to a genuine kill.
Every file is restored from a byte copy rather than from git, so untracked and
uncommitted work is never destroyed.

## Security review

Reviewed against the threat list for this change. The production diff is a
single config default, which bounds what can go wrong — but the interesting
question is not what the diff touches, it is what the removed ceiling was
incidentally protecting.

| Threat | Finding |
|---|---|
| Cross-branch upload | **No change.** Branch is server-resolved (`BranchContext`; RM-derived for the archives). No request-supplied branch is read. Pinned by `branch_is_server_resolved` and a test. |
| Permission widening | **None.** No permission, role, policy or middleware is touched. Verified against the diff, not asserted. |
| Front Office privilege escalation | **None.** Nothing granted. Front Office holds no `review_*`/`publish_*`/`void_*` legacy permission, pinned by test. |
| Publish / VOID bypass | **No change.** Separate permissions, separate gate, untouched. |
| Branch request manipulation | **No change.** Admission compares canonicalized tokens server-side. |
| Unbounded synchronous workload | **Pre-existing, not introduced.** See the batch-atomicity section: the same inserts already happened and were rolled back at the ceiling. Bounded by the 5 MB CSV cap. Archive importers are queue-backed with ROLL-3 backpressure, unchanged. |
| Storage exhaustion | **Backpressure intact.** `min_free_disk_bytes` (2 GiB floor) still refuses. Production has 89 GB free against a 27 MB database. |
| Queue abuse / flooding | **Backpressure intact.** `max_pending_jobs` still saturates and refuses; pinned by a test that fills the queue and asserts refusal. |
| Duplicate amplification | **Detection unchanged** — and this is the threat the ceiling most plausibly masked, since volume is now unbounded. Identity-number, medical-record and in-file collision checks all still run under a row lock inside the commit transaction. The residual risk is rows with **no** identity number, where the name + date-of-birth warning is the only signal; called out explicitly in the operator checklist. |
| File validation | **Unchanged.** MIME, PDF magic bytes, size and page caps all still apply. |
| PII logging | **Unchanged.** Nothing added logs a patient name, medical-record number or identity number. The hub surface renders counts, limits and route names only. |

**No CRITICAL or HIGH finding.** The one item worth the owner's attention is
duplicate amplification for no-identity-number rows — not a defect introduced
here, but a risk whose *likelihood* rises with volume, which is why the
checklist tells the operator those warnings are the only duplicate signal those
rows have.

## Restoring a ceiling

No deploy required — declare an integer:

```
LEGACY_IMPORT_HUB_DAILY_LIMIT=100        # all three types
LEGACY_IMPORT_RME_DAILY_LIMIT=50         # or one type
```

`none`, `null` and `unlimited` remain accepted spellings of "no ceiling".
