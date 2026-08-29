# REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1

**Classification:** FEATURE_REVISION · NEW_VISIT · PATIENT_SELECTION ·
SEARCHABLE_COMBOBOX · PRIVACY_SENSITIVE · BRANCH_SCOPE_SENSITIVE

**Branch:** `revision/new-visit-patient-search-combobox-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `756bc7d`
**Baseline GO tag:** `revision-rme-reports-today-default-1-go`
**GO tag:** `revision-new-visit-patient-search-combobox-1-go`

No migration. No permission. No policy. No route rename.

---

## 1. What the operator asked for

On **Kunjungan Baru**, "Pasien Terdaftar" had two controls stacked on top of each
other:

```
[ Cari nama atau nomor RM... ]      ← control 1
[ - Pilih pasien terdaftar - ▼ ]    ← control 2
```

They wanted one:

```
[ 🔍 Cari nama atau nomor RM...                    ▼ ]
```

Type a name or a Nomor RM, pick the patient, register the visit.

## 2. What the two controls actually were

This is the part that made the revision more than cosmetic.

`resources/views/components/patient-search-select.blade.php` rendered a
`<input type="search">` next to a **native `<select>` containing every patient
the controller had loaded**, each as an `<option>`. The search box did not
search: it iterated the options and set `option.hidden` on the ones that did not
match. Nothing left the browser.

The option label was:

```
{selectorLabel()} ({branchLabel()}) · {phone}
```

so **every patient's phone number was serialised into the page**.

And the controller behind it was:

```php
$patientsQuery = Patient::with('branch')->orderBy('name');
if ($user !== null) {
    $patientsQuery = $this->doctorScope->applyPatientScopeForUser($user, $patientsQuery);
}
'patients' => $patientsQuery->get(),
```

`applyPatientScopeForUser()` returns the query **unchanged** unless the user is a
Doctor. So for Admin Klinik, Kasir, Perawat, Owner, Supervisor RME and Super
Admin this was: every patient row in the database — every branch, RME-enabled or
not, active or not — with phone numbers, rendered into the HTML of a page that
front-office staff open many times a day.

Collapsing two controls into one is the visible half of this sprint. The
invisible half is that the estate is no longer shipped to the browser and the
search now runs inside the branch scope the rest of the RME workspace already
obeys.

## 3. What replaced it

**One control.** `<x-patient-combobox>` — a text input plus a dropdown, with a
hidden `patient_id` as the only submitted value.

**Server-side search.** `GET rme/visits/patient-search`
(`rme.visits.patient-search`), gated by the ClinicVisit `create` ability, so
someone who cannot register a visit cannot enumerate patients through it.

**Branch scope from the canonical authority.**
`PatientSelectorSearchService` takes its branch set from
`RmeWorkingBranchScope::branchIdsFor()` — the same service `ClinicVisitPolicy`
already uses, and whose own docblock says controllers and repositories must not
re-derive branch scope or trust a request `branch_id`. That gives, for free:

| Role | Searchable set |
|---|---|
| Admin Klinik / Perawat / Kasir | exactly their active working branch |
| …with no valid working context | **nothing** (fail closed) |
| Owner / Super Admin / Supervisor RME | active RME-enabled branches |
| Doctor | RME-enabled branches ∩ their own RM scope |

Plus legacy patients (`branch_id` null) for any in-scope operator — they would
otherwise become unregisterable — and never a patient of a non-RME branch.

This is a **narrowing** in every direction. Nothing became visible that was not
visible before.

**Least disclosure.** The response is exactly:

```json
{ "id": 41, "name": "Nurbaya", "medical_record_number": "DG-LDK2-2025-8445", "branch_label": "LDK2 — Cabang Landak" }
```

`branch_label` is included because `CrossBranchPatientLookupService` already
classifies it as non-sensitive identity metadata, and because for an RM-bearing
patient it only restates the branch code already inside the Nomor RM. The phone
number is gone.

**Bounded.** `MIN_QUERY_LENGTH = 2`, `RESULT_LIMIT = 15`, both server constants.
The request carries no `limit` and no `branch_id`, so neither can be raised or
re-pointed from the query string. LIKE metacharacters are escaped with an
explicit `ESCAPE` clause (portable across PostgreSQL and SQLite), so a typed `%`
matches literally instead of becoming "everything".

## 4. The two behaviours that needed their own test layer

Both live in the browser, and neither is reachable from a PHP feature test.

**Typed text is not a patient.** Typing `Nurbaya` does not select Nurbaya. Only
clicking or Entering a returned result sets `patient_id`, and any subsequent
keystroke clears it again. Without this, an operator could select a patient,
retype something else, and submit a `patient_id` that disagrees with what they
can see.

**A slower earlier response never wins.** Type `nur`, then `nurb`. If `nur`'s
answer lands second, it must not replace `nurb`'s list. The component carries a
monotonic request sequence and an AbortController, and re-checks the sequence
after **every** await — including after `response.json()`, which is a second
suspension point and the one that is usually forgotten.

So the state machine lives in its own module, `resources/js/patient-combobox.js`,
and is driven directly by `tests/js/patient-combobox.test.mjs` with injected
fetch, timer and AbortController doubles. Node's built-in test runner — no new
dependency:

```
npm run test:js      # 19 tests
```

`resources/js/app.js` only registers it with Alpine.

## 5. Visibility is not authorization

The old submit path validated `patient_id` with `Rule::exists('mst_patients','id')`
and nothing else. Any authorized user could POST any patient id from any branch
and a visit would be created for that patient. The dropdown was the only thing
standing in the way, and a dropdown is not a boundary.

`StoreClinicVisitRequest` now re-asserts the submitted id through
`PatientSelectorSearchService::isSelectable()` — deliberately the **same** scope
the search uses, so what is selectable and what is submittable cannot drift
apart.

A new-patient registration also has `patient_id` nulled server-side in
`prepareForValidation()`. The combobox clears itself when the operator switches
to "Pasien Baru", but a hidden panel still submits its inputs, so the server does
not rely on the browser having done it.

## 6. Dead code removed

`_form.blade.php` had a second `<x-patient-search-select>` in an `@else` branch
for `$visit !== null`. `_form` is only ever included from `create.blade.php` with
`'visit' => null`, and `edit.blade.php` does not include it at all — so that
branch could never render, and referenced a `$patients` variable that no longer
exists. It was exactly the "hidden duplicate select that could still cause a
state conflict" this revision must not leave behind. Removed.

## 7. Deliberately not changed

- **Inactive patients stay selectable.** "Inactive" is not "unauthorized", and
  filtering them would be an operational policy change nobody asked for.
- **`CrossBranchPatientLookupService` is untouched.** It is the cross-branch
  Nomor RM *duplicate-detection* panel on the visit index; it returns no patient
  id and must never become a selection source. The two stay separate.
- **`x-patient-search-select` still exists** and still serves
  `resources/views/lab-orders/_form.blade.php` (the dental Lab Order form). That
  is a different page and out of scope. It carries the same preload and the same
  phone-in-option-label shape, and is worth its own sprint.
- No registration, queue, room, consent, RME/odontogram, visit-completion,
  invoice or payment behaviour. No report semantics. No legacy import.

## 8. Files

**New**
- `app/Modules/Patient/Services/PatientSelectorSearchService.php`
- `app/Modules/ClinicVisit/Requests/PatientSearchRequest.php`
- `resources/views/components/patient-combobox.blade.php`
- `resources/js/patient-combobox.js`
- `tests/js/patient-combobox.test.mjs`
- `tests/Feature/RME/NewVisitPatientSearchComboboxTest.php`
- `.cursor/rules/100-new-visit-patient-search-combobox.mdc`

**Modified**
- `app/Modules/Patient/Interfaces/PatientRepositoryInterface.php` — `searchSelectable`, `findSelectable`
- `app/Modules/Patient/Repositories/PatientRepository.php` — the queries
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` — `patientSearch`; `create()` no longer loads patients
- `app/Modules/ClinicVisit/Requests/StoreClinicVisitRequest.php` — submit-time boundary + new-mode null
- `routes/web.php` — the search route
- `resources/views/rme/visits/_form.blade.php` — one control; dead branch removed; reset on mode switch
- `resources/views/rme/visits/create.blade.php` — stops passing `$patients`
- `resources/js/app.js` — Alpine registration
- `package.json` — `test:js`

## 9. Security review

Independent adversarial review of the change set: **CRITICAL 0, HIGH 0, MEDIUM 0**
introduced. Three LOW findings, all on the record:

1. **No rate limiting** on a leading-wildcard (`%term%`) scan, which cannot use a
   btree index. Accepted: it is strictly *cheaper* than the full-estate load it
   replaces, which ran on every page view, and no route in this application is
   throttled — adding one here alone would be an inconsistent control. A
   `pg_trgm` index or a route throttle is a reasonable follow-up.
2. **`orWhereNull('branch_id')`** is the single carve-out in an otherwise
   absolute branch predicate. Deliberate and documented: legacy patients would
   otherwise become unregisterable. Still far narrower than the base behaviour.
3. **Collation mismatch** between PHP's `mb_strtolower()` on the needle and the
   database's `LOWER()` on the column — a non-ASCII name could fail to match
   itself. **Fixed:** both sides are now lowercased by the database
   (`LOWER(name) LIKE LOWER(?)`).

The emitted clause was executed against **PostgreSQL 16.14** — production's exact
version — to confirm it parses and behaves: case-insensitive matching with a
non-pre-lowered needle, escaped `%` and `_` staying literal, and a typed bare `%`
matching only a literal percent rather than every row.

### Found here, deliberately NOT fixed here

Both are pre-existing and byte-identical on the base branch; fixing them would
change behaviour outside this revision's scope.

- **`ClinicVisitController::patientVisitOptions` has no branch check.** It
  authorizes `viewAny` and applies the doctor scope but never consults
  `RmeWorkingBranchScope`, so a context-bound operator can pass an arbitrary
  `patient_id` and read another branch's visit history (visit number, date,
  type, doctor name, initial treatment, status). It sits directly beside the new
  endpoint, which received exactly the check this one lacks. **Recommended as
  the next fix sprint.**
- **The phone leak still lives on the Lab Order form.**
  `resources/views/lab-orders/_form.blade.php` still renders the entire patient
  estate — including phone numbers — through the retired
  `x-patient-search-select` component, fed by `PatientService::listAll()`.
  Kunjungan Baru is fixed; the component is not retired repo-wide.

## 10. Durable rules

Mirrored in `.cursor/rules/100-new-visit-patient-search-combobox.mdc`.

1. New Visit existing-patient selection is **one** searchable combobox.
2. Search covers patient **name** and **Nomor RM**, partial and case-insensitive.
3. Search is **server-side and bounded**; the patient set is never preloaded.
4. The response carries only `id`, `name`, `medical_record_number`,
   `branch_label` — pinned by an exact key-set assertion.
5. The selected patient authority is a **server-validated `patient_id`**.
6. Typing alone never selects a patient; editing after a selection clears it.
7. Switching to "Pasien Baru" clears the existing-patient selection, in the
   browser and again on the server.
8. Branch scope is `RmeWorkingBranchScope` and the daily branch context stays
   authoritative; a request `branch_id` is never authority.
9. Cross-branch RM lookup behaviour is preserved and stays a separate service.
10. A result limit is mandatory and server-owned.
11. A stale autocomplete response must never replace a newer query's results.
