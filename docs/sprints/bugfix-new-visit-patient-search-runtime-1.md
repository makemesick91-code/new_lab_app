# BUGFIX-NEW-VISIT-PATIENT-SEARCH-RUNTIME-1

**New Visit patient search survives PostgreSQL: the LIKE escape can no longer break PDO placeholder binding**

- Branch: `bugfix/new-visit-patient-search-runtime-1`
- Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Baseline: `revision-new-visit-patient-search-combobox-1-go` @ `eba713b`
- Classification: BUGFIX · PRODUCTION_REGRESSION · NEW_VISIT · PATIENT_SEARCH ·
  RUNTIME_INTEGRATION · BRANCH_SECURITY_SENSITIVE · PRIVACY_SENSITIVE

---

## 1. What the operator saw

Typing a patient name into the "Pasien Terdaftar" combobox on **Kunjungan Baru**
produced:

```
Gagal mencari pasien. Silakan coba lagi.
```

for every query, on every branch, for every role. Patient selection — and with
it the whole registration workflow — was unusable.

That message is the frontend's generic transport-failure state. It is the right
message for a real failure, which is exactly why the outage was so hard to read
from the outside: a total server-side breakdown of the search presented itself
as a UI that simply would not cooperate.

## 2. The failure, measured rather than guessed

Production logs (`storage/logs/laravel.log`, `pilot.ERROR`, route
`rme.visits.patient-search`, userId 7) carried one entry per keystroke:

```
SQLSTATE[HY093]: Invalid parameter number: parameter was not defined
(Connection: pgsql, ... SQL: select "id", "name", "medical_record_number",
"branch_id" from "mst_patients" where ("branch_id" in (2) or "branch_id" is null)
and (LOWER(name) LIKE LOWER(%Jefri%) ESCAPE '\'
  or LOWER(medical_record_number) LIKE LOWER(%Jefri%) ESCAPE '\')
and "mst_patients"."deleted_at" is null order by "name" asc, "id" asc limit 15)
```

Classification: **500 — backend runtime exception**. Not 401, 403, 404, 419,
422, 429 or a redirect; not a contract mismatch; not a frontend state bug. The
request reached the controller, passed authorization, passed validation, was
correctly branch-scoped (`branch_id in (2)`) — and died in the database driver.

## 3. Root cause

`app/Modules/Patient/Repositories/PatientRepository.php` escaped LIKE
metacharacters with a backslash and declared it in the SQL:

```php
->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '\\'", [$like])
```

The rendered fragment is `ESCAPE '\'`. That is valid SQL, and PostgreSQL and
SQLite both execute it correctly. The fault is one layer higher.

PDO has to rewrite `?` into `$n` before pdo_pgsql can send a statement, and
through **PHP 8.3** its SQL parser treats a backslash inside a single-quoted
literal as escaping the closing quote. The literal therefore does not end where
SQL says it does; it runs on and swallows the placeholders behind it. PDO then
finds fewer `?` than the three bindings Laravel supplies — one branch id and two
LIKE terms — and refuses the statement with HY093.

Reproduced directly at the PDO layer, isolating both variables:

| | pgsql (16.14) | sqlite |
|---|---|---|
| **PHP 8.3.33** | **FAIL — HY093** | OK |
| PHP 8.5.4 | OK | OK |

## 4. Why every test was green

Three independent blind spots lined up.

**Driver.** pdo_sqlite accepts positional `?` natively, so PDO never runs the
rewriting parser and the malformed literal is never tokenised. On SQLite the
fault is not under-tested — it is genuinely absent. `phpunit.xml` sets
`DB_CONNECTION=sqlite`.

**Selection.** `tests/Feature/RME/NewVisitPatientSearchComboboxTest.php` already
covered branch scope, IDOR, privacy, result bounds and wildcard escaping — 34
cases of it — and matched **no token** in either NSF-R011 critical-gate filter.
The gate runs PostgreSQL 16 on PHP 8.3, the one combination that fails, and had
never been pointed at this code. The coverage existed; nothing required it.

**Runtime identity.** The VPS default CLI is PHP **8.5.8** — that is the
co-tenant `aish-pos`. DaengtisiaMS is served by the `php8.3-fpm` pool
`daengtisiams`. Reproducing from the VPS command line would also have passed,
and would have reported the bug as absent while it was still happening.

## 5. The fix

**The defect.** The LIKE escape character is now a private class constant that
cannot terminate a string literal, read by both the SQL clause and the escaping
helper so the two cannot drift:

```php
private const LIKE_ESCAPE = '!';
```

Semantics are unchanged on both drivers: the explicit `ESCAPE` clause stays (it
is what makes `%` and `_` literal identically on PostgreSQL and SQLite), and the
escape character escapes itself first so a name containing `!` still matches.

**The reason it escaped detection.** Both patient-search suites are now declared
in `config/ci_runner.php` `critical_gate_mandatory_suites` and selected by a
`NewVisitPatientSearch` token in **both** critical-gate variants. Declaring
without a token fails the reconciliation test; a token without a declaration can
be dropped silently by a later edit. Both are required.

**A guard that depends on neither.** The new suite replays PDO's own tokenising
rule in PHP and asserts the statement's placeholder count equals its binding
count. That fails on SQLite and on PHP 8.5 too, so the class cannot hide again
behind whichever database the suite happens to point at.

## 6. What was deliberately not changed

No migration, schema, permission, policy, route or route rename. **No
authorization was relaxed to make search work**: scope is still
`RmeWorkingBranchScope`, the endpoint still accepts no `branch_id`, the daily
branch context still owns mid-day moves, `patient_id` is still re-authorized at
submit time, the ceiling is still 15, the minimum query is still 2 characters,
the debounce is still 300 ms, and the response still carries exactly four
identity fields.

The frontend is untouched. It was correct — `response.ok === false` renders the
error state, and both async suspension points are sequence-guarded against a
stale response. Rendering an error for a real HTTP 500 is what it is supposed to
do.

## 7. Recorded, not fixed here

Out of scope by the sprint's own rule, and left as findings:

- `ClinicVisitController::patientVisitOptions` lacks a branch check.
- The dental Lab Order form still exposes a phone number through the retired
  `x-patient-search-select` component.
- `CrossBranchPatientLookupService` and `LegacyRmePatientResolutionAuditService`
  escape LIKE with a backslash but emit **no** `ESCAPE` clause, so they cannot
  hit this fault. On SQLite their escaping is a no-op — a latent semantic
  difference, not a runtime error.
- `ReportingSummaryGovernanceService` contains `ESCAPE '\'` in four places, all
  with **zero bindings**, so there are no placeholders to miscount.

## 8. Durable rules

Recorded in `.cursor/rules/130-raw-sql-driver-parity-and-gate-selection.mdc`:

1. A SQL string literal must never close on a backslash.
2. SQLite green is not PostgreSQL green — raw SQL on a production path is
   exercised on PostgreSQL, or the mechanism is encoded driver-independently.
3. Production's PHP is the FPM pool's version, not the CLI default.
4. A suite no filter token matches is not selected, and is therefore not a
   control — declare it in both places.
5. A successful empty result is not an error; the three states stay distinct.
