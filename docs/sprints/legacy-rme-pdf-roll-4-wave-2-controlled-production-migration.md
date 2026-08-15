# LEGACY-RME-PDF-ROLL-4-WAVE-2 — Controlled Production Migration Wave-2

**Status: `WATCH — BLOCKED ON SEPARATION OF DUTIES. WAVE NOT OPENED, NOTHING PUBLISHED, NO GO TAG.`**

Two attempts are recorded. Attempt 1 stopped because there were no candidates and
no approval. Attempt 2 cleared both of those, froze a real candidate set, and
stopped at a different and more important boundary: **no second human exists to
act as checker**, so the maker/checker split could only have been satisfied by
one person switching accounts. The owner's instruction was explicit — stop rather
than switch accounts — and that is what happened.

| | |
|---|---|
| Wave reference | `LEGACY-RME-PDF-ROLL-4-WAVE-2` |
| Approved branch set | `LDK2` (Cabang Landak) — single branch |
| Accepted-import ceiling | 5 (a ceiling, never a target) |
| Designated sources | 5, frozen by SHA-256 |
| Preflight approved | **4** |
| Preflight rejected | **1** (W2-003, `PATIENT_NOT_FOUND` — explained, not substituted) |
| Admitted / published | **0 / 0** |
| Production state | capability OFF, admission EMPTY, wave NONE — **never opened** |
| Determination | **WATCH** |

---

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
