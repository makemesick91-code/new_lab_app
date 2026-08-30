# BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1

**Classification:** BUGFIX · PRODUCTION_FUNCTIONAL_DEFECT · LEGACY_ODONTOGRAM ·
PATIENT_LOOKUP · PRIVACY_SENSITIVE · AUTHORIZATION_SENSITIVE · CLINICAL_EVIDENCE

**Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
(do NOT target `main`).

---

## 1. The reported defect

On **Import Data Legacy → Unggah Arsip Odontogram Lama**
(`GET /settings/rme/legacy-odontograms/create`) an operator entered the patient
identifier and no patient data appeared. No error, no message, no log line.

## 2. Root cause

`FAILURE_LAYER` = **request-input handling + view state modelling** (server-side).
Not the database, not authorization, not the branch derivation, and not the
frontend — the page has no JavaScript at all.

The lookup was a plain full-page GET form whose only field was `patient_id`: the
**internal surrogate key of `mst_patients`**. That number is displayed on no
DaengtisiaMS screen, report, print-out or export. Every other patient-selection
surface in the product — the sibling Legacy RME importer, the New Visit combobox
— uses the canonical **Nomor RM**. The label `ID Pasien` appears in exactly one
Blade file in the entire repository: this one.

The controller read it as:

```php
$patient = $this->resolvePatient($request->integer('patient_id'));
```

`Request::integer()` is `intval()`, which does not fail — it guesses:

| operator input | `intval()` | rendered |
|---|---|---|
| `DG-TKM1-2024-0001` (the Nomor RM they hold) | `0` | blank "Belum ada pasien dipilih" |
| `abc` | `0` | blank |
| unknown id `999999` | `999999` | blank |
| soft-deleted patient's id | that id | blank |
| `1abc` | **`1`** | **patient #1, silently** |
| `patient_id[]=anything` | **`1`** | **patient #1, silently** |

The view then had a single `@else` for every one of those outcomes, rendering
the *same* empty state it shows before any input at all. So `FOUND`, `NOT_FOUND`
and `ERROR` were indistinguishable, and the operator's correct action — typing
the identifier printed on the chart in their hand — produced a screen identical
to having typed nothing.

**Production evidence.** `FEATURE_RME_LEGACY_ODONTOGRAM_ARCHIVE=true`,
`APP_ENV=pilot`, `APP_DEBUG=false`; **zero** legacy-odontogram entries in
`storage/logs/laravel.log` (8,718 lines). The page returned 200 and failed
silently — exactly as reproduced.

## 3. Why existing tests missed it

The suite GETs `settings.rme.legacy-odontograms.create` in exactly two places,
both **permission-negative** (`assertForbidden`, `assertNotFound`). **No test
ever requested the create route with a `patient_id` and asserted that the
patient panel rendered.** The happy path was only ever exercised through
`POST .../store`, which has a real FormRequest
(`required|integer|exists:…whereNull(deleted_at)`) and was therefore always
correct. The *display* path had no FormRequest, no repository, no service and no
test — the defect lived precisely in the gap.

## 4. The fix

Narrow. Same page, same single GET form, same full-page reload, **no JavaScript
added**, no migration, no new route, no new permission, no schema change.

| new | role |
|---|---|
| `LookupLegacyOdontogramPatientRequest` | canonical identifier boundary; `FILTER_VALIDATE_INT`, never `intval()` |
| `LegacyOdontogramPatientLookupService` | decides the state; catches + logs a failing lookup |
| `LegacyOdontogramPatientLookup` | the outcome type: IDLE / FOUND / AMBIGUOUS / NOT_FOUND / TOO_SHORT / TOO_MANY / ERROR |
| `LegacyOdontogramPatientIdentity` | least-disclosure DTO (id, name, Nomor RM, branch label) |
| `LegacyOdontogramPatientRepositoryInterface` + `LegacyOdontogramPatientRepository` | the module's only door to `mst_patients`, column-projected |

Changed: the controller's `create()` (thin — resolve, authorize, delegate),
`store()`'s patient resolution routed through the repository, the Blade view's
state rendering, and the `RepositoryServiceProvider` binding.

**Step 1 now asks for the Nomor RM.** `patient_id` is still accepted so
disambiguation links and existing bookmarks keep working unchanged.

### Reuse boundary

The repository reuses `CrossBranchPatientLookupService`'s **rules** — its
`MIN_SUFFIX_LENGTH` / `DISPLAY_LIMIT` constants are referenced, not re-declared,
and exact-before-suffix ordering and LIKE escaping match it — so there is one
contract. It does not reuse its **shape** (`Auth`-facade actor resolution, a
per-row `latest_visit_date` query, no surrogate key in the payload). This
mirrors `LegacyOdontogramWorkspaceScope`, which is deliberately a dedicated
scope rather than a reuse of the legacy RME one. The New Visit lookup service is
**not** reused: its product policy is a different decision from legacy clinical
evidence authorization.

## 5. Invariants explicitly preserved

- Native odontogram cutoff, including "an empty placeholder row is not a chart"
  (`LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1`) — pinned by test.
- Branch derived from the patient's own Nomor RM; `branch_id` in the request is
  never read — pinned by test.
- Publication immutability (VOID + re-import, never edit-in-place).
- Private clinical storage; no public object.
- No native `ClinicVisit` / `Odontogram` / RME / invoice / payment / lab /
  SATUSEHAT side effect — pinned by test.
- Daily import quota untouched.
- Review → publish lifecycle untouched.
- KTP/NIK never rendered — now enforced by the type, not by template discipline.

## 6. Recorded as a separate UX finding, deliberately not fixed here

A richer patient picker (search by name, type-ahead, a combobox) would improve
this screen, but it is a **separate UX sprint**. This sprint restores the broken
function and adds no client-side machinery. Accepting the Nomor RM is not that
redesign — it is the canonical patient identifier the rest of the product
already uses, and without it the workflow simply cannot be completed.

## 7. Durable rules

Mirrored to `.cursor/rules/131-legacy-odontogram-patient-lookup.mdc`.

1. The lookup accepts the identifier the operator holds (Nomor RM); an internal
   database id is never the sole means of finding a patient.
2. A lookup reports a **state**, never a nullable patient; `IDLE` is the only
   blank state.
3. A failing lookup is `ERROR` — never `NOT_FOUND`, never a 500.
4. Identifiers are validated, never coerced. `intval()` / `Request::integer()`
   is forbidden for a patient identifier in this module.
5. `FOUND` is not authorization; `patient_id` is re-resolved and re-authorized
   server-side on submit.
6. Patient identity is global; the archive's branch is not.
7. Least disclosure is enforced by the DTO and the repository's projected
   column list.

## 8. Evidence

**TDD observed.** The new suite failed **13 of 32** before any production code
changed. Every failure was on the `create` display path; the `store` cases were
green from the start, because that path already had a FormRequest.

| check | result |
|---|---|
| `LegacyOdontogramPatientLookupTest` | 40 passed / 128 assertions |
| `tests/Feature/LegacyOdontogram` | 139 passed |
| Regression: LegacyOdontogram + LegacyRme + LegacyImportHub + Patient + AccessControl | 1250 passed / 18 skipped / 0 failed |
| PostgreSQL **16.14** + PHP **8.3.33** (pinned CI runtime image; production's FPM major.minor) | 139 passed — driver asserted as `pgsql`, not assumed |
| Dusk browser (real Chrome, real route, real Blade, PostgreSQL 16) | 4 passed / 46 assertions |
| Mutation | **18 attempted, 17 killed, 1 equivalent, 0 real survivors** |
| `pint --dirty`, `git diff --check` | clean |

Production runtime confirmed as **PHP 8.3 FPM** (`php8.3-fpm-daengtisiams.sock`),
not the 8.5 CLI on the same host — the 8.5 pool belongs to the co-tenant app.

### What the browser test proves that a feature test cannot

The reported defect was "the data does not appear". A feature test can assert a
controller returned a patient; it cannot assert an operator SAW one. The runtime
error state is provoked by genuinely renaming `mst_patients` out from under one
page load — a real PostgreSQL error through the real driver, reversed
immediately, no stub anywhere.

### Mutation findings that changed the work

Three survivors were real and were closed, not explained away:

- **form field rename** survived: every HTTP test passed `rm` in the query
  string and none exercised the rendered form — the exact defect class being
  fixed. Now pinned.
- **URL path rename** survived: the sibling assertion uses `toContain`, and
  `.../create-broken` contains `.../create`. Now pinned with `toEndWith`.
- **deleting the controller's `authorize()`** survived: the route middleware
  checks the same permission. The redundancy is deliberate, but an untested
  backstop is indistinguishable from a missing one, so the middleware is now
  lifted in a test to prove the second gate and the capability-404 ordering are
  real.

A fourth, **stale patient identity via the session**, was unreachable at both
the feature layer (Laravel's test client does not carry the session cookie
between requests) and the browser layer. It is pinned where it IS observable:
the FormRequest must derive the identifier from the request alone. Verified
failing under the mutation before being restored.

The only survivor left is passing an unused variable into the view, which
changes nothing rendered — genuinely equivalent. The mutations that actually
test the privacy claim (rendering phone / KTP) are killed.

### Two harness defects found while doing this, both now fixed

1. A mutation whose anchor no longer matched was applied by plain
   `str.replace()`, which no-ops silently, and was then reported SURVIVED — a
   test-suite hole that did not exist. Substitutions now assert the file
   changed.
2. Restoring a mutated `.blade.php` with `shutil.copy2` preserves mtime, and
   Blade recompiles only when *source mtime >= compiled mtime*. The restored
   template looked older than the artifact compiled while mutated, so Blade kept
   serving the mutation and every later test 500'd — nine mutations were
   reported KILLED by a poisoned cache rather than by the mutation. Restore now
   stamps the file new and clears the compiled view cache.

### Incidental corrections in the touched view

`<x-ui.card title="3. Dokumen &amp; Tanggal">` passes `&amp;` as a PROP, which
`{{ }}` escapes again, rendering the literal `&amp;` on screen (visible in the
browser screenshot). One character, in a file this sprint already rewrites.
A docblock also referenced a method name that does not exist.

### Reproducing the verification locally

```bash
# PostgreSQL 16 + PHP 8.3, the production driver and runtime
docker run -d --name lodo-pg16 -e POSTGRES_PASSWORD=... -p 127.0.0.1:5433:5432 postgres:16
docker build -f .github/ci-runtime/Containerfile.php83 -t lodo-php83 .
# then point DB_* at 127.0.0.1:5433 and run tests/Feature/LegacyOdontogram

# Browser: serve with the capability on, then
php artisan dusk --pest --filter=LegacyOdontogramPatientLookupBrowserTest
```

## 9. Follow-up: the rule and the code disagreed

Post-deploy verification of the live VPS found `store()` still reading
`$request->integer('patient_id')`. It was **safe** there —
`StoreLegacyOdontogramImportRequest` enforces `integer` + `exists` before that
line runs, so nothing could be coerced — but it contradicted rule 131 §4, which
forbids `intval()`-family coercion on a patient identifier anywhere in this
module. A durable rule the code violates on day one is worse than no rule.

Fixed by reading the **validated payload** instead
(`(int) $request->validated('patient_id')`; the cast is explicit because
`validated()` may return a numeric string and the file is `strict_types`), and
pinned by a source scanner so the contradiction cannot return.

The scanner strips comments via `token_get_all` before matching: these classes
deliberately QUOTE the forbidden call in their docblocks to explain the defect,
and a scanner that could not tell prose from code would either fail on the
explanation or force it to be deleted. This codebase already learned that once
with the UI governance scanner. The scanner was verified non-vacuous —
reintroducing the call makes it fail, restoring the fix makes it pass.
