# LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1

**An empty native odontogram placeholder is not clinical evidence for the legacy odontogram native-reference cutoff.**

| | |
|---|---|
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Base SHA | `9aa45a864d42d80a08dd48bc61204716ac9bc2bd` |
| Baseline GO tag | `post-rme-odontogram-stabilization-1-go` |
| Type | `RUNTIME_FIX` — no migration, no route, no permission, no schema change |
| GO tag | `legacy-odontogram-native-reference-cutoff-1-go` |
| Full Suite | `DEFERRED_BY_GLOBAL_TEMPORARY_POLICY` — `FULL_SUITE_EXECUTION_COUNT=0` |

---

## 1. The residual this sprint settles

`LegacyOdontogramNativeReferenceRepository` decided "this patient already has a
native odontogram" from **bare row existence** (`->whereHas('odontogram')`). A row
in `trx_odontograms` that charts nothing at all therefore counted as a native
clinical reference.

POST-RME-ODONTOGRAM-STABILIZATION-1 deliberately left this alone, because changing
it could alter legacy odontogram admissibility for existing production patients.
This sprint measured that impact first and then decided.

## 2. The cutoff acts in TWO directions — which is why this is not cosmetic

`LegacyOdontogramDateRuleService` feeds both directions from one `resolve()` call:

1. **Eligibility GATE** — `earliestNative === null` ⇒ refuse the patient entirely
   (`CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM`), `LegacyOdontogramDateRuleService.php:127`.
2. **Chronological BOUND** — the archive date must be *strictly* earlier than
   `earliestNative`, `LegacyOdontogramDateRuleService.php:135`.

Counting a contentless placeholder therefore did two wrong things at once: it
**opened the gate on evidence that does not exist**, and it **drew the bound on a
date where nothing was charted**.

Note the directions move opposite ways under a narrowing predicate:

| Patient shape | Gate | Bound |
|---|---|---|
| Only empty rows | was eligible → now **refused** | n/a |
| Early empty row + later charted row | eligible either way | bound moves **later** → window *widens* |

Both were measured on production before implementing.

## 3. Production read-only impact analysis

`asia_dental_lab_pilot` on `srv1730088`, PostgreSQL 16.15. Every session ran under
`SET default_transaction_read_only = on` — mutation was impossible, not merely
avoided. No `INSERT`/`UPDATE`/`DELETE`/DDL was issued at any point.

### 3.1 A measurement error, found and corrected

The first classification pass tested `jsonb_typeof(tooth_map_payload->'teeth') = 'array'`
and reported **0 meaningful rows / 32 empty rows / all 15 patients empty-only**.

**That result was wrong and is retracted.** `teeth` is a JSON **object keyed by FDI
tooth number** (`{"11": {...}, "12": {...}}`), never a list, so the array test was
false for every row and mis-classified 31 rows of genuine charting (`caries`,
`crown`, `missing`, `root_treated`, notes such as "karies parah") as empty.

It was caught because the output contradicted itself — the same pass reported
`payload_empty_teeth = 0` while claiming every row was empty. Both numbers could
not be true. The corrected predicate counts **keys in the teeth object**.

This is recorded rather than quietly fixed: had the wrong number been trusted, the
change would have looked like it blocked 100% of patients, and the sprint would
have been abandoned for the wrong reason.

### 3.2 Corrected measurement

Scope = the cutoff's own scope: non-cancelled visit, neither visit nor odontogram
soft-deleted.

| Metric | Value |
|---|---|
| `TOTAL_NATIVE_ODONTOGRAM_ROWS` (in scope) | 32 |
| `TOTAL_MEANINGFUL_NATIVE_ROWS` | 31 |
| `TOTAL_EMPTY_NATIVE_ROWS` | **1** |
| Rows excluded from scope (cancelled / soft-deleted) | 0 |
| `TOTAL_PATIENTS_WITH_NATIVE_ROWS` | 15 |
| `PATIENTS_WITH_MEANINGFUL` | 14 |
| `PATIENTS_EMPTY_ONLY` | **1** |
| Patients with no native row at all (already refused today) | 6 |

Predicate sensitivity — the choice of predicate does not change the answer:

| Candidate predicate | Empty rows |
|---|---|
| teeth-only | 1 |
| teeth **or** `summary_notes` **or** `additional_conditions` | 1 |
| rows with notes but no teeth (would diverge) | **0** |
| rows with teeth keys but no status (would diverge) | **0** |
| charts that are entirely `normal` (would diverge) | **0** |

`tooth_map_payload` carries exactly one top-level key across the whole table:
`teeth`. `summary_notes` and `additional_conditions` are blank on every row.

### 3.3 Eligibility delta

| Metric | Value |
|---|---|
| `CURRENTLY_BLOCKED_BY_EMPTY_ONLY` | **0** |
| `WOULD_BECOME_ELIGIBLE_AFTER_FIX` | **0** |
| Patients losing *placeholder-derived* eligibility | **1** (patient 45) |
| `cutoff_moves_LATER` (window widens) | **0** |
| `BLOCKED_BY_MEANINGFUL_NATIVE_BEFORE` | 14 |
| `BLOCKED_BY_MEANINGFUL_NATIVE_AFTER` | **14 — unchanged** |
| `ELIGIBILITY_DELTA` | **−1** |

**The brief's premise was inverted by the evidence.** It anticipated empty rows
*blocking* patients who would become eligible once the rows were ignored. In
reality an empty row is the only thing that can *open* the GATE, so on the gate
direction ignoring it can only ever refuse more.

**That is not a general monotonicity guarantee, and must not be quoted as one.**
On the BOUND direction, dropping a patient's *earliest* row moves the bound LATER
and therefore **widens** the admissible window. The suite pins exactly that case
(empty 2020-01-01 + charted 2022-03-10 ⇒ 2021-06-01 now passes where it was
previously refused), and the widening is clinically correct: nothing was charted
on the placeholder's date, so the document overlaps no real examination.
`cutoff_moves_LATER = 0` below is a **measurement of today's production data**, not
a structural property — a patient who later accumulates "empty earliest + charted
later" will exhibit it.

On this dataset:

- **No patient becomes newly eligible.** There is no eligibility expansion to risk,
  and therefore nothing to auto-import (§48 is satisfied trivially).
- **Every patient with real charting is completely unaffected** — 14 of 14, bound
  unchanged.
- **Exactly one patient (45) loses eligibility that was never real.** Their only
  odontogram is a `draft` with a `NULL` payload dated 2026-08-21 on a visit with no
  medical record — an abandoned examination, not a chart. Today the archive would
  accept any document dated before 2026-08-21 on that basis.

Patient 45 simply joins the 6 patients who already, correctly, receive
`PATIENT_HAS_NO_NATIVE_ODONTOGRAM`. That refusal is documented as deliberate in
`LegacyOdontogramDateRuleService`, and it self-heals: the moment a tooth is charted,
the patient becomes eligible again — this time against a real boundary.

### 3.4 Branch and date distribution

| Branch | Rows | Meaningful | Empty | Patients |
|---|---|---|---|---|
| LDK2 | 17 | 17 | 0 | 11 |
| TKM1 | 8 | 7 | **1** | 5 |
| ATG3 | 7 | 7 | 0 | 4 |

Meaningful rows span 2026-06-10 → 2026-08-21. The single empty row is 2026-08-21.

### 3.5 The archive is at rest

`stg_odontogram_legacy_imports` = 0, `stg_odontogram_legacy_import_pages` = 0,
`trx_odontogram_legacy_records` = 0, `trx_odontogram_legacy_record_pages` = 0.

**Nothing published depends on the old semantics**, so no already-filed archive is
retroactively invalidated by this change.

> *Form note (dataviz):* the impact is a three-number story over n=32. The form
> heuristic selects a table, not a chart — a plotted distribution of 32 rows would
> be decoration, not decision support. No chart artifact was produced or committed.

## 4. Decision

> **An empty native odontogram placeholder with no meaningful clinical content is
> not authoritative native odontogram evidence for legacy odontogram
> native-reference cutoff purposes. Only native odontogram records containing
> meaningful clinical evidence participate in that cutoff — as the eligibility gate
> and as the chronological bound alike.**

The evidence supports it: meaningful native data stays authoritative for all 14
patients that have it, and no patient gains eligibility.

## 5. Implementation

**The predicate already existed** — as a `private` method on `OdontogramService`,
reachable only by Patient Odontogram History, while the cutoff counted row
existence. Two answers to one question, and the doctor-facing one was the stricter.
This sprint did not invent a third: it promoted the existing one and made the
cutoff use it.

| File | Change |
|---|---|
| `app/Modules/Odontogram/Models/Odontogram.php` | `hasRecordedTeeth()` promoted here — **the one definition**, beside `dmftCounts()` which reads the same payload |
| `app/Modules/Odontogram/Services/OdontogramService.php` | history filter delegates to the model; private duplicate removed. **No behaviour change** |
| `app/Modules/LegacyOdontogram/Repositories/LegacyOdontogramNativeReferenceRepository.php` | requires clinical content: SQL `whereNotNull('tooth_map_payload')` + the canonical PHP predicate, earliest-first with early exit |
| `app/Modules/LegacyOdontogram/Interfaces/…RepositoryInterface.php` | contract restated |
| `app/Modules/Odontogram/Repositories/OdontogramRepository.php` | comment now points at the predicate's new home |

A charted tooth counts **regardless of status, `normal` included**. The asymmetry is
deliberate: treating real content as empty would erase a boundary and admit an
overlapping document, while treating a sparse chart as content only ever *keeps* a
boundary. When in doubt, keep the boundary.

Content, not workflow status, is the test — a `finalized` row that charts nothing is
still nothing; a `draft` that charts a tooth is evidence. On production 29 of 32
rows are `finalized`, so a status-based shortcut would have been wrong.

### 5.1 Reviewed and deliberately NOT hardened

Adversarial review raised that `hasRecordedTeeth()` accepts a tooth entry that
itself charts nothing — `{"teeth":{"11":null}}` passes, because the predicate tests
the *map* for non-emptiness, and `UpdateOdontogramPlaceholderRequest` permits a
`nullable|array` tooth entry.

**Not changed, for three reasons:**

1. **It grants no capability.** `{"teeth":{"11":{"status":"normal"}}}` is fully
   UI-reachable and design-sanctioned (`normal` is in the validation allowlist) and
   has the identical effect. The crafted `null` entry buys nothing extra.
2. **Hardening would re-split the definition.** `hasRecordedTeeth()` is now shared
   with Patient Odontogram History. Tightening it silently changes what the doctor
   is shown as history — a behaviour change outside this sprint's scope and its
   measured impact. Re-introducing a cutoff-only variant would recreate the exact
   two-definitions problem this sprint removed.
3. **The privilege required is high.** Writing any chart needs
   `manage_clinic_visits`, the `visit.room` gate, `OdontogramPolicy::author`, and
   `RmeVisitConsentService::assertOdontogramAuthoringAllowed()` — one ACTIVE
   encounter, the actor inside the working-branch and doctor-patient scopes, the
   chart being that live encounter's own, and a SIGNED consent. The patient must be
   physically mid-examination.

It is also strictly narrower than the behaviour it replaces, where a `NULL` payload
opened the gate outright. If it is ever tightened, it must be tightened for *both*
consumers, with its own impact analysis.

### 5.2 PostgreSQL portability

`tooth_map_payload` is `jsonb`. Comparing it to a string is a **hard PostgreSQL
error** (no `jsonb = text` operator) that SQLite silently tolerates — a predicate
like `!= ''` would pass every local test and 500 in production. This is not
hypothetical; it is documented on the existing history query as a real incident.

Therefore the split is:

- **SQL half** — `whereNotNull('tooth_map_payload')` only. Portable on both drivers,
  and it removes the dominant empty shape (`NULL`) before any row is loaded.
- **PHP half** — `Odontogram::hasRecordedTeeth()` settles what SQL cannot express
  portably: `{"teeth": {}}`, or a payload with no `teeth` key.

`whereJsonLength()` was rejected: it compiles to `jsonb_array_length()` on
PostgreSQL, which **errors on a JSON object**, and `teeth` is an object.

## 6. What does NOT change

- No native odontogram row is deleted, edited or backfilled. This sprint changes
  *eligibility semantics*, not data.
- No automatic import, publish, review record or migration batch. Eligibility ≠ execution.
- Published legacy odontograms stay immutable; correction remains VOID + fresh import.
- Publishing still creates no `ClinicVisit`, native `Odontogram`, `MedicalRecord`,
  invoice, payment or SATUSEHAT submission.
- Patient / RM / branch binding, the duplicate-document guard, doctor clinical
  scope, consent gates and explicit examination completion are all untouched.
- The resolver stays deliberately **un-branch-scoped**: narrowing the scan could only
  move the bound later and admit an overlapping document.

## 7. Tests

`tests/Feature/LegacyOdontogram/LegacyOdontogramNativeReferenceCutoffTest.php` — 19 tests.

**Negative control:** before the fix, 9 of the 19 failed and 10 passed. The 10 that
already passed are the meaningful-data protections — proof the suite is not merely
asserting the new behaviour everywhere.

Matrix: the canonical predicate (charted / `NULL` / `{"teeth": {}}` / no `teeth` key;
draft-with-content vs finalized-but-blank); empty-only (gate closes, one row, many
rows, empty-map shape); meaningful data still authoritative (bound applies, equal
refused, earliest of several); mixed (earlier empty must not drag the bound back,
later empty must not push it forward, earliest charted among surrounding empties);
scope (cancelled visit, soft-deleted row, same-day tie); patient isolation (both
directions); branch (still bounds across branches, by design).

**Fixture correction.** `OdontogramFactory` defaults `tooth_map_payload` to `NULL`,
so `lodoNativeOdontogram()` had been manufacturing exactly the empty placeholder the
cutoff must now ignore — every pre-existing cutoff test was asserting against a row
with no chart. The helper now charts a tooth in the real production shape;
`lodoEmptyNativeOdontogram()` covers the contentless case. All 80 pre-existing tests
keep passing with their intent intact.
