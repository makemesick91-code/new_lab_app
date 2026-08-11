# LEGACY-RME-PDF-FIX-ROLL2-1 — Legacy Eligibility, Multi-Date & RM-Branch Resolution

**Type:** corrective sprint (blocks ROLL-2)
**Branch:** `feature/legacy-rme-pdf-fix-roll2-1-eligibility-multidate-rm-branch`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `382aaf100714414527ad499ec832acc9d39484f8` (ROLL-2 merge)
**GO tag:** `legacy-rme-pdf-fix-roll2-1-eligibility-multidate-rm-branch-go`

**ROLL-2 remains PAUSED throughout this corrective.** A FIX GO unblocks ROLL-2;
it is not itself a pilot GO. The production feature flag `rme.legacy_pdf_archive`
stays **OFF** for the whole of this sprint's production closure, no pilot PDF is
uploaded, and no ROLL-2 GO tag is created.

---

## 1. Root cause

Three defects surfaced when the real pilot document for RM `DG-TKM1-2024-9985`
(patient id 36 on the pilot) was prepared for import.

### 1.1 A patient with no native RME was refused

`LegacyRmeDateRuleService` refused any patient with no native RME
(`PATIENT_HAS_NO_NATIVE_RME`), on the reasoning that without a native RME there
is no date to compare against.

That inverted the purpose of the archive. The pilot patient has **0 native
clinic visits and 0 native medical records** — and so do most patients carried
over from the old system, because their entire history predates DaengtisiaMS.
The rule made the ordinary migration case impossible and pushed the operator
toward the one thing that must never happen: manufacturing a clinical encounter
to unlock an import.

The native RME is a **BOUND**, not a **PREREQUISITE**.

### 1.2 A document was treated as a single date

`selected_rme_date` was a single date, but a real historical PDF commonly carries
several clinical dates — the pilot document carries **28-01-2024** and
**31-08-2024**.

Two problems followed. There was no rule saying *which* of them to enter, so two
operators would file the same document differently. And validating one chosen
date is unsafe: a document whose oldest entry predates the native RME but whose
newest entry does not would pass, hiding a mixed legacy/native chronology behind
its oldest date.

### 1.3 The archive branch was operator input

`origin_branch_id` was a free `<select>` labelled *"Opsional — hanya catatan asal
arsip"* ("optional — just a note about where the archive came from").

That label was wrong. `LegacyRmeImportRepository::scoped()` filters row
visibility on that column and `LegacyRmeImportPolicy::inScope()` evaluates it, so
it decides **who can read the archive**. The old service also trusted a submitted
id, and anchored a blank one to the uploader's first branch — either of which
files a patient's history under a branch that does not own that patient.

Meanwhile the answer was already unambiguous in the patient's own record:
`DG-**TKM1**-2024-9985` → `TKM1` → Cabang Telkomas.

### 1.4 Consequence for publish

1.1 and 1.2 both change what publish-time revalidation has to re-check. An import
staged for a patient with **no** native RME is valid at staging and may become
invalid before publish, if a real encounter is recorded in between — and the
check can only catch that if the declared range was persisted.

---

## 2. Product decisions implemented

| # | Decision | Status |
|---|----------|--------|
| 1 | A patient with **no native RME** may be imported. It is the ordinary migration case, not a hidden exception. | implemented |
| 2 | A native RME, **when it exists**, is a bound: every date the document represents must strictly precede it. | implemented |
| 3 | A native encounter is **never manufactured** to satisfy a date rule. | implemented + tested |
| 4 | Multi-date document → `selected_rme_date` = **EARLIEST** clinical date. | implemented |
| 5 | Safety bound → `MAX(document dates) < earliest native RME`. | implemented |
| 6 | Single-date document → the range collapses; the latest date may be omitted. | implemented |
| 7 | Branch = branch code in the patient's canonical Nomor RM. | implemented |
| 8 | The operator **cannot** override the resolved branch; a conflicting value is rejected explicitly. | implemented |
| 9 | Unknown / malformed / missing / inactive / non-RME / out-of-scope branch → **fail closed**. | implemented |
| 10 | `origin_branch_id` is a **security/visibility** property, not descriptive metadata. | implemented + UI corrected |

### No date OCR was invented

The system does not read dates out of a PDF and this corrective does not pretend
otherwise. Both ends of the range are **operator-declared**, exactly as the
single date already was. The UI states the rule ("gunakan tanggal RME paling
awal") and the operator attests to it; the server enforces ordering, the native
bound, the today bound and the birth-date bound.

---

## 3. Architecture

### 3.1 Canonical Nomor RM parser (reused, not duplicated)

`PatientMedicalRecordNumberService` has composed `DG-{KODE_CABANG}-{TAHUN}-{NOMOR}`
since Sprint 23.8. The **parser is added to that same class** — `parse()` and
`branchCodeFrom()` — so the composer and its inverse cannot drift apart. No
controller, form request, service or view carries a regex for this format.

`MedicalRecordNumberParts` is the immutable result. `parse($x)?->toString() === $x`
holds for every value `compose()` can produce, including a manual sequence that
itself contains a hyphen.

Anything that is not exactly the canonical format returns `null` — never a
partial guess.

### 3.2 Branch resolution

`LegacyRmeBranchResolver` is the single source of truth for a legacy document's
branch. It reuses `BranchService::rmeEnabledIds()` for the definition of "active
and RME-enabled" (MAIN is excluded by that definition) and
`LegacyRmeWorkspaceScope` for the actor check.

`LegacyRmeBranchResolution` carries a stable code: `RM_MISSING`,
`INVALID_RM_BRANCH_CODE`, `BRANCH_NOT_FOUND`, `BRANCH_AMBIGUOUS`,
`BRANCH_INACTIVE`, `BRANCH_NOT_RME_ENABLED`, `BRANCH_OUT_OF_SCOPE`,
`BRANCH_CONFLICT`.

There is **no fallback**: not the acting user's branch, not `BranchContext`, not
the first RME-enabled branch, and never a request value.

`BRANCH_AMBIGUOUS` is **defence in depth**: `mst_branches.code` is UNIQUE, so it
is unreachable while that constraint holds. That is asserted directly (the
database refuses a duplicate code) rather than by faking data the schema forbids.

**Conflict handling.** A submitted `origin_branch_id` is never used as the
answer. It is only *compared* with the derived one, and a mismatch raises an
explicit validation error rather than being silently discarded, so an
inconsistent operator sees the problem instead of a surprising owner.

### 3.3 Date rules

`LegacyRmeDateRuleService::evaluate(Patient, $selected, $latest = null)`:

1. Both dates must parse; `earliest <= latest`.
   A **blank** latest date means a single-date document. A **non-blank
   unparseable** one is an error and must not silently collapse into the
   earliest date, which would drop the safety bound.
2. If a native RME exists: `latest < earliest native RME` (strict).
   If not: no such bound — recorded as `NO_NATIVE_REFERENCE`.
3. `latest < today` (clinical timezone).
4. `birth date <= earliest` (skipped when the patient has no birth date).

Failure codes: `LEGACY_DATE_NOT_BEFORE_NATIVE_RME`, `LEGACY_DATE_IN_FUTURE`,
`LEGACY_DATE_BEFORE_PATIENT_BIRTH`, `LEGACY_DATE_INVALID`, and the new
`LEGACY_DATE_RANGE_INVALID`.

`PATIENT_HAS_NO_NATIVE_RME` is **removed** rather than kept as a passing code —
retaining a FAIL code while allowing the import would be misleading. The fact it
used to carry is preserved as *evidence*, in the new reference mode:

* `BEFORE_NATIVE_RME` — the decision was bounded by a native RME.
* `NO_NATIVE_REFERENCE` — the patient has none.

Both are written to the audit trail, so the distinction the old refusal destroyed
is now recorded.

### 3.4 Publish revalidation

`LegacyRmePublishService` already re-ran the date rule against a freshly resolved
cutoff. It now re-runs it over the **whole persisted range**, and additionally
re-derives the branch and refuses if it no longer matches the staged one (a stale
owner must not be frozen into an immutable record).

The actor is deliberately not passed to the resolver at publish time: the
question is whether the document still belongs where it was filed, not who is
pressing the button — that is the policy's job.

A pre-FIX row with `origin_branch_id = NULL` is not made worse by publishing, and
inventing a branch for it now would be a guess, so it is left alone.

### 3.5 Schema

`2026_08_11_100001_add_latest_rme_date_to_legacy_rme_tables` adds a nullable
`latest_rme_date` to `stg_rme_legacy_imports` and `trx_rme_legacy_records`.

**Why persistence is required** (rather than staging-only validation): publish
re-validates against a fresh cutoff, and it cannot re-check a range it does not
have. Validating the latest date only at staging time would leave publish-time
revalidation blind to exactly the race it exists to catch. It is also the
provenance of what the operator attested to.

Additive only — nothing dropped, renamed, made NOT NULL or backfilled. Existing
rows keep `NULL`, which the domain reads as "single-date document" — the same
meaning those rows already had. Production currently holds **0** legacy imports
and **0** legacy records, but the change is written to be safe on non-empty data.

Run with `migrate`. Never `migrate:fresh` or `db:wipe`.

### 3.6 Audit

Reuses the existing `sys_audit_logs` trail. Added to the allow-list:
`latest_rme_date`, `reference_mode`, `branch_code` (an operational label such as
`TKM1`, never the full Nomor RM).

New action `LEGACY_RME_IMPORT_BRANCH_REJECTED` — a distinct action rather than a
reused `IMPORT_CREATED`, because nothing was created and a trail that says
otherwise is a lie.

### 3.7 UI

* The blocking "Pasien belum memiliki RME di sistem" **danger** alert is replaced
  by an **info** notice explaining that this is normal for migrated patients and
  explicitly warning against creating a visit or medical record to satisfy the
  rule.
* Two date inputs: *Tanggal RME Paling Awal* (required) and *Tanggal RME Paling
  Akhir* (optional, "kosongkan jika dokumen hanya memuat satu tanggal").
* An explicit multi-date instruction: use the **earliest** date as the archive
  date.
* The branch `<select>` is **removed**. The resolved branch is displayed
  read-only with its code, and the misleading *"Opsional — hanya catatan asal
  arsip"* copy is gone, replaced by a statement that the branch determines who
  can see the archive.
* A document whose branch cannot be derived shows a clear blocking message
  telling the operator to fix the patient's Nomor RM.

---

## 4. Tests

New: `tests/Feature/LegacyRme/LegacyRmeMigrationEligibilityBranchTest.php` (39).

| Area | Coverage |
|------|----------|
| RM parser | pilot RM, round-trip over 4 composer outputs, 10 malformed inputs |
| Branch resolution | TKM1 → Cabang Telkomas; missing / malformed / unknown / inactive / non-RME / out-of-scope; no fallback to the actor's branch; conflict rejected, match accepted |
| Ambiguity | DB refuses a duplicate code; the guard's code/audit shape asserted directly |
| No-native import | succeeds; creates **0** visits and **0** medical records |
| Multi-date | range persisted; single-date collapses |
| Publish revalidation | native appears crossing the range → refused; later than every date → allowed; crossing only the **latest** date → refused; RM branch changed → refused |
| Side effects | both patient kinds: 0 visit / medical record / invoice / payment / lab order / lab candidate / SATUSEHAT candidate |
| Row visibility | own-branch operator 200, other-branch operator 404 |
| HTTP | range accepted; forged branch rejected; reversed range rejected |

Updated in place (they pinned the contract this corrective changes):

* `LegacyRmeDateRuleServiceTest` — no-native now **accepted**; reference modes;
  multi-date matrix (whole range before native, latest crossing, latest equal,
  reversed, future latest, single-date collapse, unparseable latest).
* `LegacyRmePdfValidationTest` — no-native now **accepted**, with a null cutoff
  snapshot and no encounter created; range persistence.
* `LegacyRmeImportAuthorizationTest` — the three tests that pinned
  operator-chosen / actor-anchored / branchless behaviour replaced by
  RM-derived, conflict-rejected, fail-closed and read-only-display tests.

**Fixture change.** `PatientFactory` emits a generic `MRN-XXXXXXXX` placeholder,
which is not a canonical Nomor RM. It is deliberately **left alone** (other
suites pin it). New Pest helpers `legacyRmeBranch()` and
`legacyRmeArchivablePatient()` create a patient with a real
`DG-{CODE}-{YEAR}-{SEQ}` number and its branch — the shape production actually
has. Legacy RME fixtures were migrated to them.

---

## 5. Security review

| Vector | Result |
|--------|--------|
| Branch spoofing via `origin_branch_id` | Value never used as the answer; mismatch rejected; covered by an HTTP test |
| Hidden-input manipulation | The form no longer emits the field; the server re-derives regardless |
| Fallback branch (privilege widening) | Removed entirely; no fallback path exists |
| Out-of-scope filing | Refused (`BRANCH_OUT_OF_SCOPE`) instead of anchoring |
| Row-visibility widening | `origin_branch_id` now always the RM-derived branch; visibility test both ways |
| RM parser confusion / prefix injection | Strict four-part split, fixed prefix, bounded charset, `null` on anything else |
| IDOR on patient | Unchanged: `findSelectable()` + doctor scope on both `create` and `store` |
| `Gate::before` (Super Admin) | Unchanged — it bypasses authorization, never the domain rules; the no-fallback and conflict tests use a Super Admin actor |
| Date manipulation | Whole range validated server-side; client `max=` is a convenience only |
| Staging→publish race | Range persisted and re-validated at publish against a fresh cutoff, plus branch re-derivation |
| PHI in logs | Audit payload stays allow-listed and structure-only; asserted by a test that the patient's name and Nomor RM never appear |

No CRITICAL or HIGH findings. No raw SQL, dynamic execution or filesystem call
is added; the only new regex is two anchored, bounded character classes applied
to already-split Nomor RM segments. A soft-deleted branch resolves to
`BRANCH_NOT_FOUND` (the resolver queries `Branch::query()`, which excludes
trashed rows) — fail closed.

### Two changes worth stating explicitly rather than burying

**Visibility change (intentional).** Before this corrective, a governance-tier
upload produced `origin_branch_id = NULL`, a row only the governance tier could
see. It now carries the RM-derived branch, so operators of *that* branch can see
their own patients' archives. That is the point of branch-derived ownership. In
the other direction it is strictly narrower: an archive can no longer be filed
under an arbitrary branch at all.

**What the range rule cannot enforce.** The bound is
`declared latest < earliest native RME`. An operator who *under*-declares the
latest date could still get a document through whose real content extends later.
That is operator misdeclaration — the same class of risk the single date already
carried before this corrective, and it is not fixable without reading dates out
of the PDF, which this sprint deliberately did not invent. It is covered by the
attestation checkbox and the audit trail, and the rule set is strictly stronger
than what it replaced.

---

## 6. Non-regression

Unchanged: legacy RME creates no clinic visit, medical record, invoice, payment,
consent, odontogram, prescription, lab order/candidate or SATUSEHAT candidate;
published records stay immutable; correction remains VOID + fresh import; files
stay private; the feature stays server-side gated; the doctor view stays
read-only.

---

## 7. ROLL-2 resume readiness

ROLL-2 may resume only from a base containing this GO tag. Its pilot expectations
become:

| | |
|---|---|
| RM | `DG-TKM1-2024-9985` |
| Resolved branch | `TKM1` / Cabang Telkomas |
| Native RME | none — **valid** for legacy migration |
| PDF clinical dates | 28-01-2024, 31-08-2024 |
| `selected_rme_date` | **28-01-2024** (earliest) |
| `latest_rme_date` | 31-08-2024 |
| Feature flag at FIX closure | **OFF** |
| ROLL-2 GO tag | **absent** |

The ROLL-2 pilot itself — enabling the flag and uploading the document — remains
a separate, user-controlled step.
