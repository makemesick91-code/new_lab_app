# REVISION-LEGACY-ODONTOGRAM-NATIVE-OPTIONAL-1

**A legacy odontogram is historical evidence. Evidence does not need permission
from the present.**

Classification: `BUSINESS_RULE_REVISION` · `LEGACY_ODONTOGRAM` · `NATIVE_CUTOFF` ·
`CLINICAL_HISTORY` · `PRIVACY_SENSITIVE` · `IMMUTABILITY_SENSITIVE` ·
`NO_NATIVE_SIDE_EFFECTS`

Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
@ `d319881f` (tree `1b012a3a`, GO tag `bugfix-legacy-odontogram-patient-lookup-1-go`)
— independently confirmed as the deployed production authority on `srv1730088`
before any mutation.

---

## 1. The decision

```
LEGACY_ODONTOGRAM_REQUIRES_NATIVE = false
```

A patient may have a legacy odontogram archived **without** having a native
odontogram in DaengtisiaMS.

| | |
|---|---|
| **Legacy Odontogram** | historical clinical evidence, read off a paper chart |
| **Native Odontogram** | an examination *this system* performed, through a visit |

They are different domain records. Requiring the second before accepting the
first inverted the real situation: the patients whose paper charts most need
archiving are precisely the ones this clinic has never examined.

## 2. What was actually there

`LegacyOdontogramDateRuleService::evaluate()` ran five rules, and the **first**
was a hard refusal:

```php
if ($earliestNative === null && $this->requireNativeReference()) {
    return LegacyOdontogramDateRuleResult::fail(
        self::CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM,
        'Pasien ini belum memiliki odontogram di sistem, sehingga arsip odontogram lama belum dapat diarsipkan. …',
        $context,
    );
}
```

- `PRE_FIX_NO_NATIVE_BEHAVIOR` — refused.
- `PRE_FIX_REJECTION_REASON` — `PATIENT_HAS_NO_NATIVE_ODONTOGRAM`, message above.
- `PRE_FIX_CODE_PATH` — `LegacyOdontogramDateRuleService.php:127`, reached from
  **three** call sites, all funnelling through this one service:
  `LegacyOdontogramImportService:93` (`assert()` at upload),
  `LegacyOdontogramPublishService:164` (`evaluate()` re-check at publish), and
  `LegacyOdontogramImportController:122` (`snapshotCutoff()`, guidance only).
  The upload screen additionally told the operator *"arsip lama belum dapat
  diarsipkan"*.

Reproduced as a failing test before any production line changed:
**15 failed / 8 passed**. The 8 that already passed were the cutoff rules that had
to survive — a useful signal that the change was correctly scoped.

## 3. What changed

**One rule, in one service.** No migration, no route, no permission, no schema.

| File | Change |
|---|---|
| `LegacyOdontogramDateRuleService` | `CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM`, its `CODES` entry, the refusal branch and `requireNativeReference()` **deleted** |
| `config/legacy_odontogram.php` | `dates.require_native_odontogram_reference` **removed** |
| `PatientEarliestNativeOdontogramDateResolver` | contract restated: `null` now ALLOWS, so a failed lookup must stay an exception |
| `LegacyOdontogramNativeReferenceRepositoryInterface` | same, at the read boundary |
| `settings/legacy-odontograms/create.blade.php` | the "belum dapat diarsipkan" warning became a neutral "no bound applies" note |

The gate was **deleted, not defaulted off**. A config switch left behind would
mean the retired rule is one settings edit from returning, and would leave two
contradictory rules simultaneously active in the codebase.

### What did NOT change

```
NATIVE OPTIONAL  !=  NATIVE CUTOFF REMOVED
```

`require_strictly_before_native` stays on. When a meaningful native odontogram
exists it still bounds the archive:

- at the **EARLIEST** meaningful native, never the latest;
- **STRICTLY** earlier — `SAME_DATE_POLICY = REJECT`, because equal is the overlap
  case (a chart dated the day of a real examination either *is* that examination
  or contradicts it);
- a contentless placeholder still does not count (`Odontogram::hasRecordedTeeth()`).

Retiring the gate makes that predicate **more** load-bearing, not less. It used to
act in two opposite directions, which made a mistake partly self-limiting; with
only the bound left, a wrongly-counted placeholder has exactly one effect —
inventing a bound on a date where nothing was charted.

## 4. The risk this revision creates, and how it is held

```
native lookup returns nothing  →  no cutoff  →  ALLOWED   (a valid clinical state)
native lookup THROWS           →  exception  →  the request fails
```

Before the revision **both** outcomes refused, so conflating them was untidy but
harmless. Now absence allows, so folding a database fault into `null` would turn a
broken query into permission to file a chart with **no chronological bound at
all**.

The resolver and repository have **no `try`/`catch`** on that path. That was
already true and is now load-bearing, so it is documented at both layers and
pinned by a test that binds a throwing repository and asserts the exception
propagates rather than becoming a pass.

Equally: no cutoff is ever fabricated for a patient who has none — no epoch, no
`9999-12-31`, no `now()`. Absence is `null` and nothing else.

## 5. Retiring the gate is not a bypass

The gate ran *first*, so removing it could silently widen far more than intended.
`LEGACY_DATE_IN_FUTURE`, `LEGACY_DATE_BEFORE_PATIENT_BIRTH` and
`LEGACY_DATE_INVALID` are each pinned for the no-native case: today and the future
are still refused, a date before the patient's birth is still refused, equal to the
birth date is still accepted, and a null birth date still skips that rule rather
than being invented.

## 6. Preserved invariants

- **No native side effects.** Upload and publish create no `ClinicVisit`, native
  `Odontogram`, `MedicalRecord`, invoice, payment, prescription, `LabOrder` or
  SATUSEHAT candidate. In particular the archive never satisfies a cutoff by
  creating an empty native odontogram — the tempting "fix" for the old gate, and
  expressly forbidden.
- **Patient master required and untouched.** Native-optional is not
  patient-optional. No patient is created, duplicated or edited, and the Nomor RM
  — the archive's branch authority — is never rewritten.
- **Future native welcome.** A patient with a published archive can be examined
  normally afterwards. The archive is not converted, deleted or mutated; the two
  coexist. The new native bounds the *next* archive, and a staged archive is
  re-evaluated at publish, so a document that stopped being historical while it sat
  in staging is refused then rather than published anyway.
- **Branch, storage, workflow, quota, lookup** all unchanged: branch derived from
  the Nomor RM (a submitted `branch_id` is ignored — asserted, not assumed), private
  disk only, `READY_FOR_REVIEW → REVIEWED → PUBLISHED`, immutable with VOID + fresh
  import as the only correction, the shared daily import quota, and the rule-131
  patient lookup with server-side re-validation.

## 7. Read-only production impact report (rule 111 §3)

Rule 111 §3 requires a read-only production impact report before any change that
broadens legacy odontogram import eligibility. This sprint broadens it, so the
report is owed. Measured on `srv1730088` / `asia_dental_lab_pilot` under
`SET default_transaction_read_only = on` inside an explicit transaction, with
`transaction_read_only` asserted as `on` in the output — mutation impossible
rather than merely avoided. No scratch script, SQL file or database left behind.

| Measure | Value |
|---|---|
| Patients (not soft-deleted) | 21 |
| Patients with a **meaningful** native odontogram | 14 |
| **Patients newly eligible under this revision** | **7** |
| `trx_odontograms` rows | 32 (1 with a `NULL` payload) |
| Staged legacy odontogram imports | 0 |
| Published legacy odontogram records | 0 |

Two things this report settles:

1. **The delta is 7 and it cross-checks.** LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1
   independently measured "6 patients who already, correctly, receive
   `PATIENT_HAS_NO_NATIVE_ODONTOGRAM`" plus patient 45 joining them — 6 + 1 = 7,
   from a different query at a different time. Two independent derivations agreeing
   is worth more than either alone.
2. **The blast radius on existing data is zero.** The archive holds no staged
   imports and no published records, so this revision cannot alter, revalue or
   invalidate any archive that already exists. It only changes which patients an
   operator may *begin* archiving against.

The predicate used for "meaningful" was written to the payload's real shape —
`tooth_map_payload->'teeth'` is a JSON **object keyed by FDI number**, so the count
goes through `jsonb_object_keys()`, never `jsonb_array_length()`, which errors on an
object and would silently report every real chart as empty (rule 111 §3).

## 8. Evidence

| Check | Result |
|---|---|
| Pre-fix TDD | 15 failed / 8 passed — the refusal reproduced |
| `tests/Feature/LegacyOdontogram` (SQLite) | 163 passed / 514 assertions |
| PostgreSQL 16.14 + PHP 8.5 | 163 passed / 514 assertions |
| PostgreSQL 16.14 + PHP 8.3 (canonical CI runtime) | 163 passed / 514 assertions |
| Migrations added | none |

The canonical runtime run used the digest-pinned
`.github/ci-runtime/Containerfile.php83` image (PHP 8.3.33, the CI extension set)
against a throwaway `postgres:16` — the same major version production runs, and
not the local host's PostgreSQL 18.

New suite: `tests/Feature/LegacyOdontogram/LegacyOdontogramNativeOptionalTest.php`
— no-native, placeholder-only, cutoff present, after-cutoff, same-date, multiple
natives, placeholder-before-meaningful, the other rules under no-native,
lookup-throws, end-to-end publish, no-side-effects, patient integrity, multiple
archives, future native, publish-time re-refusal, and the branch/HTTP boundary.

Two pre-existing tests asserted the retired refusal and were realigned to assert
the new behaviour (`LegacyOdontogramDateRuleTest`,
`LegacyOdontogramNativeReferenceCutoffTest`), along with the latter's header,
which described the gate as one of two live directions.

## 9. Mutation testing — and the one real gap it found

18 mutations across the date rule, the native-reference repository, the intake,
the publish service, the controller and the storage config. Every mutant was
verified applied, executed and reverted by content hash (never `git checkout --`,
which cannot restore the untracked new suite), with mtime bumped and the compiled
Blade cache cleared on both apply and revert.

**15 killed on the first pass. 3 survivors, each resolved rather than reported:**

| Survivor | Verdict | Resolution |
|---|---|---|
| **M13** bypass the actor-scoped patient re-resolution at `store()` | **REAL GAP** | Closed with a new test; re-run **KILLED** |
| **M15** publish without a human review | **MIS-PLACED MUTANT** | It injected the status change *after* the `canTransitionTo` guard it claimed to bypass, so it changed nothing. Re-placed before the guard as M15b → **KILLED** |
| **M16** skip the daily import quota | **EQUIVALENT** | Proven, not assumed — see below |

**Real survivors: 0.**

### M13 was a genuine hole in a claim the codebase already made

Rule 131 §5 states "FOUND is not authorization — `patient_id` is re-resolved and
re-authorized server-side on submit". Nothing pinned it: replacing
`$this->resolvePatient(...)` with a bare `Patient::find()` passed all 163 tests.
`LegacyOdontogramPatientRepository::baseQuery()` is where
`DoctorPatientScopeService` is applied, so the bare lookup silently drops the
actor's patient scope. Widening eligibility makes that boundary matter more, not
less, so it is now pinned by a test that binds a refusing repository stub and
asserts the upload 404s.

### M16 is equivalent, and the investigation still improved coverage

Nulling the intake's quota `preview()` survived — and so did removing the ceiling
from the authoritative `reserve()`, *individually*. The two are mutually
redundant by design: `preview()` refuses early so a 20 MiB PDF is never streamed
and hashed first, while `reserve()` is the authoritative decision taken under a
row lock; both raise the same `legacy_import_quota` message. Either one alone
still refuses the upload, which is what "equivalent mutant" means here.

That was proven rather than argued: **removing BOTH guards is KILLED** by the new
test. The claim "the daily quota still refuses a no-native patient" is therefore
backed end-to-end. The hub's own suite exercises the quota *service* directly and
never through the odontogram intake, so before this sprint nothing pinned that the
intake honours the ceiling at all — that gap is now closed too.

## 10. Security review

Reviewed against the diff, which is five production files: the date rule service,
two contract docblocks, one config key and one Blade message. No policy, no
permission, no route, no FormRequest, no repository query and no storage path was
touched — so most of the surface below is preserved by construction rather than by
argument, and the mutation matrix is what proves it rather than the diff's size.

| Concern | Outcome | Held by |
|---|---|---|
| Authorization / permissions | unchanged | nothing in the diff reaches them |
| Patient IDOR at submit | **strengthened** | M13 — a real gap, now pinned |
| Cross-branch patient identity | unchanged (global by design, rule 131 §6) | — |
| Archive branch authority | unchanged | M12 killed |
| Submitted `branch_id` tampering | ignored | M12 killed + explicit test |
| Clinical evidence privacy / public disk | unchanged | M14 killed |
| Native side effects | none | M3, M10, M11 killed |
| Cutoff bypass | refused | M6, M7 killed |
| Placeholder-row semantics | not evidence | M4, M5 killed |
| Publish immutability / review gate | unchanged | M8, M9, M15b killed |
| VOID + replacement correction path | unchanged | existing suite |
| Daily import quota | enforced | M16e killed (both guards) |
| Mass assignment | no new fillable or validated field | — |
| Patient master mutation / duplication | none | M17, M18 killed |

**`CRITICAL = 0`, `HIGH = 0`.**

The one genuinely NEW risk this revision creates is the fail-open direction
described in §4: absence now allows, so a swallowed lookup error would become an
unbounded archive. It is held by an explicit test and documented at both layers,
and no `try`/`catch` was added anywhere on that path.

The one real finding — M13, the unpinned actor-scoped re-resolution — was found by
this sprint's own mutation run and closed inside it rather than recorded for later.

## 11. Rules synchronised

- **New** `.cursor/rules/132-legacy-odontogram-native-optional.mdc` — the canonical
  rule.
- `.cursor/rules/111-legacy-odontogram-native-reference-cutoff.mdc` §2 — the
  eligibility GATE marked retired; the BOUND documented as the surviving direction,
  with an explicit warning not to read the gate's removal as licence to relax the
  `hasRecordedTeeth()` predicate.
- `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc` §5 — the legacy-date
  clause qualified with "when the patient has one".

`SUPERSEDED_RULES` = rule 111 §2 (eligibility half) and rule 109 §5 (native-required
clause). Both were edited **in place**: the old and new rules are never
simultaneously active.
