# LEGACY-RME-PDF-ROLL-4-WAVE-2 — Controlled Production Migration Wave-2

**Status: `WATCH / NO-GO — WAVE EXECUTED AND ABORTED. 0 PUBLISHED. NO GO TAG.`**

Three attempts are recorded. Attempt 1 stopped with no candidates and no approval.
Attempt 2 froze a real candidate set and stopped because one human held both the
maker and checker accounts. Attempt 3 cleared that, opened the wave for real, and
was aborted by the owner because **every document was filed by `u1` (Super Admin)
instead of the approved maker `u7`**.

Nothing was reviewed. Nothing was published. The archive is unchanged at 6
published legacy records, and every clinical, billing, lab and SATUSEHAT table
ended exactly where it started.

| | |
|---|---|
| Wave reference | `LEGACY-RME-PDF-ROLL-4-WAVE-2` (wave id 2) |
| Approval | `ROLL-4-WAVE-2-OWNER-APPROVAL-2026-08-16` — LDK2, ceiling 5, maker u7, checker u11 |
| Accepted into staging | **5** (quota 5/5, exhausted) |
| Cancelled | 1 (wrong source document) |
| Left inert `READY_FOR_REVIEW` | 4 |
| Reviewed / Published | **0 / 0** |
| Wave final status | `CANCELLED` |
| Native / billing / lab / SATUSEHAT delta | **0 on every table** |
| Determination | **WATCH / NO-GO** |

---

## Attempt 3 (2026-08-16) — what ran, and why it was aborted

### The approval path had to be built first

The Supervisor RME opened the wave page and saw **no approve button**. Diagnosis:
`LegacyRmeMigrationOperationsController` 404s every action while the capability
flag is off — deliberate, so a disabled deployment does not advertise a migration
control plane. That was not the real problem.

The real problem was a defect. The approve form was nested inside the view's
`@if ($canManage)` block, so it rendered only for holders of
`manage_legacy_rme_migration_operations` — which the designated approver
deliberately does not hold. **The approver could never reach the approval.**
Separation of duties was therefore satisfiable only by a CLI actor impersonating
the approver: configured, but not exercisable by a person.

Fixed in PR #298 (`f28df89` → merged `25dc2b9`, deployed `exit=0` / `DEPLOY OK`),
with a regression test verified to fail without the fix. Approval now renders in
its own card gated on the POLICY, outside the manage-only block.

### The approval itself — a first for this system

With the fix live, a **temporary approval-only capability window** was opened
(admission still EMPTY, wave still DRAFT, no operator assigned — ingestion
triple-blocked), and the human approver acted:

```
WAVE-2  created_by = 1   approved_by = 11 (Jene Monika, Supervisor RME)
        approved_at = 2026-08-15 21:25:07   status = APPROVED
```

Production had already refused the creator approving his own wave:

```
ERROR wave: Gelombang migrasi harus disetujui oleh pengguna yang berbeda dari pembuatnya.
```

Two stop-conditions were tested rather than assumed, and both cleared: closing the
capability window did **not** invalidate the approval (it is persisted DB state,
not config), and `activate` / `assign` / `admit` all ran correctly with the
capability OFF, so capability-last was preserved for actual ingestion.

### The abort

Five documents were accepted into staging. Two faults:

1. **Wrong source bound to a patient.** Import 9 carried W2-004's metadata
   (patient 46, 2026-08-13) but the bytes of `RM Landak 3.pdf` — the rejected
   `PATIENT_NOT_FOUND` candidate. Caught before any review, purely because
   identity is pinned to SHA-256 rather than filename or position. Cancelled and
   re-filed correctly as import 11 (`92502ebc…`).
2. **The approved maker never acted.** Every import — including the correction
   that was explicitly requested to be filed by u7 — shows `uploaded_by = 1`.

`u7` was assigned to LDK2 on this wave and the gate was open to her; she simply
never filed. The maker/checker property was not *breached* (u1 ≠ u11, two distinct
humans), but the wave did not run as approved, and the quota ceiling was exhausted
(5/5, no refund path) so re-filing as u7 was impossible under this approval.

The owner elected to stop rather than publish records whose audit trail would say
something untrue about who handled a patient's archive.

---

## Reconciliation — truthful

| Import | Status | Patient | Source SHA-256 (16) | Filed by | Note |
|---|---|---|---|---|---|
| 7 | `READY_FOR_REVIEW` (inert) | 41 · `DG-LDK2-2026-22623` | `02672d5b3afb396a` | u1 | W2-001, correct doc, wrong filer |
| 8 | `READY_FOR_REVIEW` (inert) | 42 · `DG-LDK2-2025-12020` | `282c4250eb7c1bca` | u1 | W2-002, correct doc, wrong filer |
| 9 | `CANCELLED` | 46 | `96c78cc097983275` | u1 | **wrong source** (W2-003 file); withdrawn |
| 10 | `READY_FOR_REVIEW` (inert) | 45 · `DG-LDK2-2025-8445` | `dcf80ed45f68f885` | u1 | W2-005, correct doc, wrong filer |
| 11 | `READY_FOR_REVIEW` (inert) | 46 · `DG-LDK2-2026-22676` | `92502ebc21832948` | u1 | W2-004 corrected doc, wrong filer |

```
TOTAL_DESIGNATED=5   PREFLIGHT_PASS=4   PREFLIGHT_REJECTED=1 (W2-003 PATIENT_NOT_FOUND)
ACCEPTED=5  CANCELLED=1  READY_FOR_REVIEW=4  REVIEWED=0  PUBLISHED=0  FAILED=0
UNEXPLAINED=0
QUOTA_BEFORE=0  ACTUAL_CONSUMPTION=5  QUOTA_AFTER=5/5  QUOTA_DRIFT=0
DUPLICATE_ADMISSIONS=0  DUPLICATE_PUBLISHED=0  WRONG_BRANCH=0  WRONG_PATIENT=0
UNAUTHORIZED_PUBLISH=0
NATIVE_CLINICAL_DELTA=0  BILLING_DELTA=0  LAB_DELTA=0  SATUSEHAT_DELTA=0
```

Quota consumed 5 for 4 usable documents because `reserve()` has no compensating
write — a cancel does not refund. That is the ceiling behaving correctly, not
drift: every acceptance is charged exactly once.

W2-003 was **not** substituted to reach five. It remains an explained
`PATIENT_NOT_FOUND` rejection: the Nomor RM reads `27541`, no such patient exists,
and the one-digit-away `DG-LDK2-2026-22541` (patient 43) was never matched to it.

---

## Lifecycle gap found — import cancellation has no operator path

`LegacyRmeImportProcessingService::cancel()` is canonical and audit-preserving: it
re-checks `canTransitionTo(CANCELLED)` under a row lock and writes an
`IMPORT_CANCELLED` audit event.

**Its only entry point is HTTP.** `legacy-rme:wave-admin` covers wave actions
(register/approve/activate/pause/resume/drain/cancel/complete/assign/revoke/branch-*)
but there is **no CLI for import-level cancel, review, publish or retry**. An
operator aborting a wave over SSH therefore cannot withdraw the staged documents
the wave produced; only a browser session can.

Consequently the four unpublished u1-filed imports remain `READY_FOR_REVIEW`.
They were **not** deleted and **not** mutated with SQL or Tinker — doing so would
have destroyed the audit property that makes cancellation meaningful. They are
inert: with the capability OFF every review, publish, retry and cancel path is
refused server-side, and the wave itself is `CANCELLED`.

**They must be cancelled through the UI at the start of the next capability
window, before any retry admits the same source hashes**, or duplicate detection
will collide with them.

---

## Production final state

```
CAPABILITY = OFF          ADMISSION = EMPTY        ACTIVE_WAVE = NONE
admitted_branch_codes=[]  approval_reference=null  operations.registered=false
wave 2 = CANCELLED        branch LDK2 = DRAINING
jobs = 0                  failed_jobs = 0
staged source PDFs        removed from /root/legacy-rme-wave2-input
```

Zero delta against the frozen pre-upload baseline on every table: clinic_visits 27,
medical_records 27, odontograms 27, rme_invoices 19, rme_payments 27, lab_orders 13,
satusehat_candidates 1, legacy_records 6.

Foundations after closure: `legacy-rme:rollout-readiness --expect=off --strict` GO ·
`foundation:deployment-entrypoint-check` GO · runtime isolation 70 GO / 0 FAIL /
0 SKIP · clinical timezone `Asia/Makassar` · health `/login` `/health/live`
`/health/ready` `/health/lb` all 200 · nginx, php8.3-fpm, queue worker active ·
`VPS_HEAD = 25dc2b96576d00bc6b6d6e684ed3414cb0d81133`.

**No GO tag was created.** `ADMITTED=5, PUBLISHED=0` is not a completed migration.

---

## Requirements for the retry wave

Same frozen source-hash authority; **no substitution for W2-003**.

| SEQ | SHA-256 | Patient | selected → latest | Status |
|---|---|---|---|---|
| W2-001 | `02672d5b…a8c1` | 41 | 2026-08-10 → 2026-08-10 | PASS |
| W2-002 | `282c4250…e2cf` | 42 | 2025-07-09 → 2026-07-10 | PASS |
| W2-003 | `96c78cc0…c05f` | — | — | **REJECTED — PATIENT_NOT_FOUND, do not substitute** |
| W2-004 | `92502ebc…c6e` | 46 | 2026-08-13 → 2026-08-13 | PASS |
| W2-005 | `dcf80ed4…a209` | 45 | 2025-03-23 → 2026-01-31 | PASS |

A retry requires a **fresh owner approval** naming branch `LDK2`, a ceiling of
**4** valid candidates, maker = the real human on `u7`, checker/publisher = the
separate human on `u11`, plus a window and stop conditions. The ceiling may need
to remain 5 if canonical governance ties it to the designated-set size rather than
the passing subset — decide that when the approval is written, and record which
rule was applied.

**Order of operations for the retry**, so the old records cannot collide:

1. Confirm the retry approval, and that `u7` will personally file.
2. Open a capability window and **cancel imports 7, 8, 10 and 11 through the UI**
   before anything else.
3. Only then register the retry wave, approve it with a different actor, activate,
   assign `u7@LDK2`, admit LDK2, and open capability as the final gate.

---

## Durable rules this attempt adds

1. **A permission that no reachable UI exposes is not a control.** Splitting a
   permission is only half the work; the holder must be able to exercise it.
   Assert reachability for the role that owns an action, not only that others are
   refused — a service-level test proves the rule cannot be violated and says
   nothing about whether it can be used.
2. **Verify the operator identity on every accepted record before review.** The
   maker/checker split is about *who acted*, so `uploaded_by` is evidence and must
   be checked against the approval, not assumed from who was assigned.
3. **Assignment is not participation.** `u7` was assigned and the gate was open to
   her; the wave still ran entirely as `u1`. An assignment proves permission, not
   that the approved person did the work.
4. **A cancelled import still consumes quota.** `reserve()` has no compensating
   write, so a correction costs a slot. Size a wave with correction headroom, or
   expect to need a fresh approval after a mistake.
5. **Wave `complete` is refused while a branch is unfinished; `cancel` is the
   honest terminal state for an aborted wave.** Do not force completion to make a
   run look tidy.
6. **Import lifecycle actions are UI-only.** Aborting a wave from the operations
   side cannot withdraw its staged documents without a browser session. Never
   substitute SQL or Tinker for a missing operator path — report the gap.

---

# Earlier attempts (unchanged record)

## Attempt 2 (2026-08-15) — what cleared, what blocked

| Prerequisite | Attempt 1 | Attempt 2 |
|---|---|---|
| Fresh owner approval | absent | **GRANTED** — LDK2, ceiling 5, maker u7, checker u11, this execution only |
| Genuine candidates | none existed | **SUPPLIED** — 5 designated Cabang Landak PDFs |
| Separation of duties | staffed (u7 ≠ u11) | **BLOCKED** — one human controls both accounts |

### The blocker, precisely

`u7` (Admin Klinik, LDK2) and `u11` (Supervisor RME) are correctly provisioned as
distinct least-privilege accounts, and neither holds
`manage_legacy_rme_migration_operations`. The accounts are not the problem.

The problem is that Wave-1 settled this as a rule about **identity, not
permissions** — *"a wave needs a maker and a checker, and they are different
people."* One person logging into both accounts satisfies the schema and defeats
the control. Two attestations signed by the same hand are one attestation.

Import and publish are HTTP-only; `StoreLegacyRmeImportRequest` requires
`patient_confirmation` and `date_confirmation` as `accepted`. Those are operator
attestations, and the owner ruled that they must be made by the actual authorized
humans through the canonical UI — not synthesized by an agent acting as u7/u11
through Tinker or the service layer. No impersonation was performed.

**To resume:** designate a genuinely separate authorized checker for `u11`. Every
other input is frozen and ready; nothing needs re-deriving.

---

## Frozen source set (SHA-256 is the authority, never the filename)

Independently verified from the source scans at up to 400 DPI — not from the
supplied hints, and not by OCR. All five are single-page image-only PDFs whose
printed template reads **Cabang Landak**.

| SEQ | File | SHA-256 | Bytes | Pages | RM (read from source) | selected → latest |
|---|---|---|---|---|---|---|
| W2-001 | RM Landak 1.pdf | `02672d5b3afb396adc9eeb1ddd50af11df7bd5ec76b86fe71851b8d2ed90a8c1` | 326,940 | 1 | 22623 | 2026-08-10 → 2026-08-10 |
| W2-002 | RM Landak 2.pdf | `282c4250eb7c1bcabada54a53ae0c7797a9f120a87e5db7f30d3fa756756e2cf` | 539,294 | 1 | 12020 | 2025-07-09 → 2026-07-10 |
| W2-003 | RM Landak 3.pdf | `96c78cc097983275611a5ab96f8ec79d2d9a3d65eb0d58c9e9ffc470335dc05f` | 288,754 | 1 | 27541 | 2026-08-06 → 2026-08-06 |
| W2-004 | RM Landak 4.pdf | `92502ebc2183294873de4db614ac05f416937c73f3c218a059c960932c726c6e` | 245,915 | 1 | 22676 | 2026-08-13 → 2026-08-13 |
| W2-005 | RM Landak 5.pdf | `dcf80ed45f68f8856ca61f404c1b6a0e101a84eef8ba362a785e89be8a8aa209` | 382,091 | 1 | 8445 | 2025-03-23 → 2026-01-31 |

W2-002 and W2-005 are multi-date; every encounter date was read and the earliest
taken as `selected`, the latest as `latest`, per the canonical rule.

### W2-004 — replacement identity established by hash and content, not filename

```
OLD_W2_004 = REMOVED / UNAUTHORIZED   (raw RM 7505, encounter 2026-08-15)
NEW_W2_004 = USED                     (raw RM 22676, encounter 2026-08-13)
```

The authorized replacement is staged under the normalized filename
`RM Landak 4.pdf`, so identity was established from the file's own bytes and
rendered content. Two independent corroborations that the correct file is in
hand:

1. The rendered header reads `22676`, not `7505`.
2. Production carries `DG-LDK2-2025-7505` as patient **44** — the removed
   candidate's patient exists and was correctly left untouched.

The removed candidate would also have **failed** the historical-age gate on its
own merits: its encounter date `2026-08-15` equalled the clinical today, and the
rule is a strict `<`.

---

## Per-candidate preflight (read-only, against live production)

Clinical today `2026-08-15` (`Asia/Makassar`, canonical). Branch is **derived**
from the patient's Nomor RM; no branch was submitted or overridden.

| SEQ | RM | Patient | Canonical RM | Branch | Historical age | Native reference | Duplicate | Preflight |
|---|---|---|---|---|---|---|---|---|
| W2-001 | 22623 | 41 | `DG-LDK2-2026-22623` | LDK2 | pass | `NO_NATIVE_REFERENCE` | none | **PASS** |
| W2-002 | 12020 | 42 | `DG-LDK2-2025-12020` | LDK2 | pass | `NO_NATIVE_REFERENCE` | none | **PASS** |
| W2-003 | 27541 | — | — | — | pass | — | — | **REJECT — `PATIENT_NOT_FOUND`** |
| W2-004 | 22676 | 46 | `DG-LDK2-2026-22676` | LDK2 | pass | `NO_NATIVE_REFERENCE` | none | **PASS** |
| W2-005 | 8445 | 45 | `DG-LDK2-2025-8445` | LDK2 | pass | `NO_NATIVE_REFERENCE` | none | **PASS** |

All four approved patients have zero native visits and zero medical records, so
the native cutoff does not bind and `NO_NATIVE_REFERENCE` is the correct state.
No visit or medical record was fabricated to create a cutoff anchor.

None of the five source hashes collides with any of the six existing imports, and
no import exists for any candidate patient. Wave-1's published
`DG-LDK2-2024-22681` (patient 40) was not touched.

### W2-003 — why it is rejected and not repaired

The No. RM field was re-rendered at 400 DPI to settle the digit: it reads
**`27541`**, the second digit unmistakably a 7. That agrees with the owner's
independent reading. **No patient `27541` exists in production.**

A `DG-LDK2-2026-22541` (patient 43) does exist, and the two differ by one digit.
Matching them would be forcing a patient match to make the candidate pass, which
is prohibited. Creating patient `27541` to receive the document is equally
prohibited. The honest outcome is a rejected candidate.

This is the discrepancy path working as designed: a supplied hint was **not**
allowed to override the source, and the source was **not** allowed to override the
patient master.

---

## Production evidence (srv1730088, `pilot`, read-only throughout)

`VPS_HEAD` `6089cbb3f4a56aacd37229ab76a589e473b1ece3` — exact-match tag
`devflow-fix-base-ref-1-canonical-remote-base-resolution-go`. Unchanged by this
run; no deployment was required or performed (`CODE_CHANGE_REQUIRED=NO`).

### Resting state — never left the safe state

| Signal | Before | After |
|---|---|---|
| capability / migration capability | `false` / `false` | `false` / `false` |
| admission wave | `null` | `null` |
| admitted branch codes | `[]` | `[]` |
| approval reference | `null` | `null` |
| jobs / failed_jobs | `0` / `0` | `0` / `0` |

### Side-effect delta — zero on every table

`legacy_imports` 6, `legacy_records` 6, `clinic_visits` 27, `medical_records` 27,
`rme_invoices` 19, `rme_payments` 27, `lab_orders` 13, `satusehat_candidates` 1,
`wave_rows` 1, `quota_rows` 1 — **identical before and after**. The wave row and
quota row are Wave-1's completed history, not this run.

```
NATIVE_CLINICAL_DELTA=0  BILLING_DELTA=0  LAB_DELTA=0  SATUSEHAT_DELTA=0
ADMITTED=0  PUBLISHED=0  QUOTA_CONSUMED=0  QUOTA_DRIFT=0  UNEXPLAINED=0
```

### Foundations — all GO

`legacy-rme:rollout-readiness` **GO** · `foundation:deployment-entrypoint-check`
**ENT-11 GO** · `clinical:date-diagnose` `Asia/Makassar`, canonical **yes** ·
health `/login` `/health/live` `/health/ready` `/health/lb` all **200** ·
nginx, php8.3-fpm, queue worker **active** · 95 GB free.

All six immutable authority tags peel byte-exact and none was moved:
`2ffe00c` · `19d18a4` · `acf1e22` · `b11bbbc` · `b8038c5` · `6089cbb`.

### Source staging and its removal

The five sources were staged to `/root/legacy-rme-wave2-input/` (`0700` root-only,
files `0600`, outside the web root, unreadable by the runtime or co-tenant
identity) purely for hash re-verification. All five hashes matched byte-for-byte
after transfer.

They were then **removed**. The wave is blocked with no scheduled resumption, and
four real patients' records should not sit on a production host outside the
application's managed private store, unmonitored and with no retention policy,
while nothing is running. The owner's originals are intact and every hash is
frozen above, so re-staging is a seconds-long step whenever a checker exists.

---

## Durable rules this wave establishes

1. **A wave needs a maker and a checker who are different *people*.** Two
   accounts controlled by one human do not satisfy separation of duties. Where
   only one human is available, the honest outcome is WATCH — never switch
   accounts to make a run go green.
2. **Operator attestations belong to humans.** `patient_confirmation` and
   `date_confirmation` (and review/publish approval) must be made by the actual
   authorized operator through the canonical UI. An agent must not impersonate
   the maker or checker, or synthesize those confirmations through Tinker or the
   service layer — even though the attestations are a UX speed bump whose every
   referenced rule is independently enforced server-side.
3. **Source identity is the SHA-256, never the filename.** A replacement staged
   under a normalized name must be identified from its own bytes and rendered
   content before it is treated as the authorized candidate.
4. **A superseded candidate is removed, not merely deprecated.** The old W2-004
   stays unauthorized regardless of filename collisions.
5. **A supplied hint never overrides the source, and the source never overrides
   the patient master.** Where the RM on paper resolves to no patient, the
   candidate is rejected — not matched to the nearest RM, and not given a newly
   created patient.
6. **Preflight rejection is a normal wave outcome.** Fewer approved candidates
   than the ceiling is fine; a rejected candidate is never replaced to reach the
   ceiling without fresh owner authorization.
7. **`NO_NATIVE_REFERENCE` is valid.** Never fabricate a ClinicVisit or
   MedicalRecord to manufacture a native cutoff anchor.
8. **Open the capability last, and only with a human at the keyboard.** Preflight,
   hashing and staging are all read-only and complete before anything is opened;
   a write capability on live clinical data is never opened to wait for an
   unscheduled human.
9. **Staged clinical sources are cleaned up when a wave does not proceed.**
   Frozen hashes plus the owner's originals preserve identity without leaving PHI
   on the host.

---

## To resume Wave-2

1. Designate a **genuinely separate** authorized checker for `u11`.
2. Re-stage the four approved sources (hashes above).
3. Re-verify foundations and the resting state, then open the bounded wave:
   register → approve (different actor) → activate → assign `u7@LDK2` → admit
   LDK2 → capability ON.
4. Maker `u7` uploads the four through the canonical UI, leaving *Cabang asal*
   blank so the branch derives from the RM, and personally makes both
   attestations:

   | SEQ | File | patient_id | Tanggal RME paling awal | Tanggal RME paling akhir |
   |---|---|---|---|---|
   | W2-001 | RM Landak 1.pdf | 41 | 2026-08-10 | 2026-08-10 |
   | W2-002 | RM Landak 2.pdf | 42 | 2025-07-09 | 2026-07-10 |
   | W2-004 | RM Landak 4.pdf | 46 | 2026-08-13 | 2026-08-13 |
   | W2-005 | RM Landak 5.pdf | 45 | 2025-03-23 | 2026-01-31 |

5. The separate checker `u11` inspects the rendered pages and publishes.
6. Reconcile, prove zero side effects, restore OFF/EMPTY/NONE, then tag.

The approval granted for this attempt covers **this execution only**; a resumption
after a materially different window needs a fresh one.
