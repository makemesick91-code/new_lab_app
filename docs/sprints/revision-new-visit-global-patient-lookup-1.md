# REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1

**Type:** FEATURE_REVISION (manifest `MODULE_SPRINT`) · NEW_VISIT ·
GLOBAL_PATIENT_IDENTITY_LOOKUP · CROSS_BRANCH_REGISTRATION · PRIVACY_SENSITIVE ·
AUTHORIZATION_SENSITIVE

**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
@ `31a88b8c` (`bugfix-new-visit-patient-search-runtime-1-go`)
**Branch:** `revision/new-visit-global-patient-lookup-1`
**GO tag:** `revision-new-visit-global-patient-lookup-1-go`

---

## 1. What changed, and why it is a revision

The predecessor sprint scoped the "Kunjungan Baru" patient lookup to the
operator's working branch. That was the correct reading of the rules as they
stood, and it closed a real IDOR: a preloaded `<select>` had been shipping every
patient in the estate — phone numbers included — into the create page's HTML.

The product rule has now deliberately changed. A patient who first registered at
Telkomas and walks into Landak today must be findable by the Landak operator, by
name or by Nomor RM, without anybody switching work branch.

```
Admin Klinik working at:  LDK2 — Cabang Landak
Search:                   Jefri
Result:                   Jefri — DG-TKM1-2024-1234 · Cabang Telkomas
Select →  patient_id = the existing TKM1 patient
          visit.branch_id = LDK2
          patient RM      = DG-TKM1-2024-1234   (unchanged)
```

## 2. The one distinction this sprint establishes

```
PATIENT IDENTITY  !=  VISIT BRANCH AUTHORITY
```

Identity lookup for registration becomes global. Visit branch authority does
not. Both halves are load-bearing: a lookup that stays branch-scoped fails the
operator at the counter, and a lookup that drags the patient's origin branch
into the visit silently registers people at the wrong clinic.

A second distinction it refuses to blur:

```
GLOBAL PATIENT IDENTITY DISCOVERY   = YES
GLOBAL CLINICAL HISTORY ACCESS      = NO
```

## 3. Where the change actually lands

Exactly one method: `PatientSelectorSearchService::authorizedBranchIds()`.

```php
// before — the working-branch scope was the identity boundary
return $this->workingScope->branchIdsFor($user);

// after — the registry is the boundary; the working context is only a gate
if ($this->workingScope->isContextBound($user)
    && $this->workingScope->activeBranchId($user) === null) {
    return [];
}

return $this->branches->rmeEnabledIds();
```

That service is already registration-specific: the search endpoint, the
submit-time `patient_id` re-authorization (`StoreClinicVisitRequest`) and the
create-page prefill all resolve through it, so **search selectability and submit
selectability cannot drift apart**. No new god-service was introduced and no
`Patient::all()` exists anywhere in the path.

`RmeWorkingBranchScope` is **not** globally relaxed. Every other surface it
scopes — visit list, patient queue, RME reports, cashier, receivables — keeps
reading one working branch.

### Why "the registry" is the RME-enabled set, not every patient row

`rmeEnabledIds()` (plus the legacy `branch_id IS NULL` patients the repository
already admits) is the exact set a governance role — Owner, Supervisor RME,
Super Admin — already reads today. Going wider would newly expose MAIN,
disabled and non-RME branches that **no** role sees and where no visit may be
registered. That is disclosure the requirement does not ask for, so it was not
taken.

### Why the fail-closed gate survived

A context-bound operator with no valid working context still reads nothing.
Global means "any branch's patient", not "no authority required": an operator
who is not working anywhere cannot register anywhere. Keeping this also keeps
the enumeration surface from widening to unattached accounts, at zero cost to
the requirement.

### Why the doctor clinical scope was left alone

A Doctor holds `manage_clinic_visits` and is therefore a registration actor, but
`DoctorPatientScopeService` still narrows them to their own RM scope. That is a
CLINICAL scope, not a branch scope. Widening it would be exactly the
identity-vs-clinical confusion this sprint exists to prevent.

## 4. Visit branch authority — unchanged, and now pinned

For a context-bound operator, `StoreClinicVisitRequest::prepareForValidation()`
already overwrites `branch_id` with the daily context before validation runs, and
`ClinicVisitService::resolveBranchId()` re-checks it against the active RME set.
This sprint adds no new behaviour there — it adds the **tests** that make the
behaviour impossible to lose, because the widened lookup is exactly the change
that would make losing it plausible.

Governance roles (Supervisor RME, Super Admin) are not context-bound by design
and still choose the branch on the form; that selector is their authorized work
context.

## 5. Deliberately NOT changed

- No migration, schema, column or index. `DATABASE_MIGRATION_REQUIRED=false`.
- No route, route rename, permission, policy or role change.
- `MIN_QUERY_LENGTH` 2, `RESULT_LIMIT` 15, debounce 300 ms, stale-response
  protection — all unchanged.
- Response keys still exactly `id, name, medical_record_number, branch_label`.
- `LIKE_ESCAPE` stays `'!'` (never a backslash — see
  `bugfix-new-visit-patient-search-runtime-1`), and the placeholder-parity guard
  suite stays.
- Frontend: one line of helper copy. The combobox already rendered
  `branch_label` and needed no redesign.

## 6. Recorded, not fixed here

- `ClinicVisitController::patientVisitOptions` lacks a branch check. This sprint
  explicitly does **not** use global identity lookup as a justification to open
  it. A test in the new suite asserts that cross-branch **visit detail** is still
  refused.
- The dental Lab Order form still exposes phone through the retired
  `x-patient-search-select` component. That component does not use this service
  and is unaffected either way.
- `CrossBranchPatientLookupService` and `LegacyRmePatientResolutionAuditService`
  escape LIKE with a backslash but emit no `ESCAPE` clause.

## 7. Evidence

### Tests

| Suite | Result |
|---|---|
| `NewVisitGlobalPatientLookupTest` (new, 29 tests) | 29 passed / 133 assertions |
| `NewVisitPatientSearchComboboxTest` + `…RuntimeTest` | 42 passed / 172 assertions |
| Combined on **PostgreSQL 16.14 + PHP 8.3.33** | 71 passed / 305 assertions |
| Regression (`ClinicVisit\|DailyBranchContext\|BranchChange\|RmeVisitConsent\|PatientRegistration\|PatientQueue\|DoctorPatientScope\|Sidebar…\|RolePermission…\|PilotRoute…\|Cicd\|RmeReportTodayDefault`) | 639 passed, 9 skipped |
| `tests/js/patient-combobox.test.mjs` | 19 passed |
| Dusk `NewVisitGlobalPatientLookupBrowserTest` (new) | 2 passed / 27 assertions |
| Dusk `NewVisitPatientSearchBrowserTest` | 4 passed / 36 assertions |

TDD order was observed: the new suite failed **16 of 29** against the base
implementation before any production code changed.

### Driver parity

The predecessor outage proved SQLite green is not PostgreSQL green. The suites
were therefore re-run inside the pinned CI runtime image (PHP 8.3.33, the
production FPM major.minor) against PostgreSQL **16.14** — the same server
version production runs — with the connection asserted as `pgsql`, not assumed.

### Mutation matrix

15 adversarial mutations attempted, **15 killed, 0 real survivors**. Revert was
by file copy, never `git checkout --`, because the sprint adds untracked test
files.

| ID | Mutation | Verdict |
|---|---|---|
| M1 | re-add current-branch-only scope | KILLED |
| M2 | use the patient's origin branch as the visit branch | KILLED |
| M3 | trust request `branch_id` for the visit | KILLED |
| M4 | rewrite the patient's RM to the operator branch | KILLED |
| M5 | clone the patient instead of reusing the row | KILLED |
| M6 | expose phone | KILLED |
| M7 | expose KTP/NIK | KILLED |
| M8 | leak a clinical field in the payload | KILLED |
| M9 | drop registration authorization | KILLED |
| M10 | blank query dumps the registry | KILLED |
| M11 | remove the result ceiling | KILLED |
| M12 | selection moves the DailyBranchContext | KILLED |
| M13 | open cross-branch visit detail | KILLED |
| M14 | remove submit-time re-authorization | KILLED |
| M15 | regress the LIKE escape to a backslash | KILLED |

### CI gate registration

`NewVisitGlobalPatientLookupTest` matched **no** token in either critical gate
filter, exactly the blind spot that let the predecessor outage ship. It is now
declared in `config/ci_runner.php` `critical_gate_mandatory_suites` and selected
by a `NewVisitGlobalPatientLookup` token in both gate variants;
`CriticalGateSuiteCoverageTest` enforces the reconciliation.

## 8. Rules synchronised

- `.cursor/rules/101-new-visit-global-patient-identity-lookup.mdc` — **new**,
  the canonical rule.
- `.cursor/rules/100-new-visit-patient-search-combobox.mdc` — section 3
  (branch-scoped lookup) explicitly marked **SUPERSEDED** and re-pointed;
  section 8 re-worded. No contradictory rule is left standing.
- `CLAUDE.md` — sprint section and canonical rule.
- `.sprint/current.yml` — manifest.
