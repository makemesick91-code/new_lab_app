# BUGFIX-RME-PRECONSENT-FIRST-PAGE-UI-GATE-1

**Classification:** BUGFIX · RME · CONSENT · UI_CAPABILITY · CLINICAL_WORKFLOW ·
AUTHORIZATION_ALIGNMENT · NO_SCHEMA_CHANGE

**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
@ `2c9ec150f239154ca59e21ede72c270168b5ce3d`
(tree `13ec35cfaa0045bd954a73352c44dcd38e34daa9`,
GO tag `revision-rme-consent-odontogram-preconsent-edit-1-go`) — independently
confirmed as the live production HEAD on `srv1730088`.

**Migration:** none. **Route:** none. **Permission:** none. **Schema:** none.

---

## 1. The bug

A doctor with an examination in progress, no signed Consent, and a patient who
has no RM sheet yet was shown an actionable **"Buat Halaman RM Pertama"**. The
server refused the create the moment they pressed it.

```
UI      = ALLOW / OFFER
BACKEND = DENY
```

Reproduced before any change, in the suite that now guards it:

| claim | pre-fix |
|---|---|
| `PRE_FIX_UI_FIRST_PAGE_ACTION` | `AVAILABLE` |
| `PRE_FIX_BACKEND_FIRST_PAGE_CREATE` | `DENIED` |

The red baseline was 11 failed / 11 passed. Every failure was a UI-capability
assertion; every backend-denial and foundation-preservation assertion already
passed. That split is the diagnosis: the server was right the whole time.

## 2. Root cause

`MedicalRecordController::show()` renders two different workspaces from the same
method, and they consulted two different authorities for the same act.

- **Populated workspace** (`$sheets` non-empty) resolved
  `canAuthorRme` and `addSheetConsentRequired` from
  `RmeVisitConsentService::canAuthorRmeForPatient()`.
- **Empty workspace** (`$sheets` empty) resolved `canEdit` from
  `can('create', [MedicalRecord::class, $workspaceVisit])`.

`MedicalRecordPolicy::create()` checks `manage_clinic_visits`, an RME-enabled
branch, and `DoctorPatientScopeService::authorizeVisitAccess()`. It has **no
consent condition**, and correctly so: consent is not a permission. It is a
property of the encounter.

Meanwhile `MedicalRecordService::createDraft()` asserts
`RmeVisitConsentService::assertRmeAuthoringAllowedForPatient()` — the four-part
positive authority (a single current encounter, `in_progress`, actor may work on
it, valid consent for that same encounter).

So the empty state gated a **capability question** on a **permission answer**.

| field | value |
|---|---|
| `ROOT_CAUSE` | empty-state `canEdit` derived from `MedicalRecordPolicy::create` (permission + branch + doctor scope), which carries no consent condition |
| `VIEW_FILE` | `resources/views/rme/visits/medical-record/empty.blade.php` |
| `OLD_UI_GATE` | `auth()->user()?->can('create', [MedicalRecord::class, $workspaceVisit])` |
| `SERVER_GATE` | `MedicalRecordService::createDraft()` → `RmeVisitConsentService::assertRmeAuthoringAllowedForPatient()` |

## 3. The fix

No new rule, and no new predicate. The empty state joins the authority that
already existed, under the **same key the sheet-add control already uses**.

`MedicalRecordController::show()` — empty-state branch:

- `canEdit` keeps its meaning (may this user manage RME here at all);
- `addSheetConsentRequired` = `! canAuthorRmeForPatient($patientId, $user)`;
- `activeEncounter` = `ActiveEncounterResolver::currentFor($patientId)`, so the
  refusal can name the real reason.

`empty.blade.php` renders from those keys, mirroring the populated workspace's
established convention: a `warning` alert naming the reason (with the
"Pilih Form Consent" link when there is an encounter to sign for), and the
create control as a **disabled button** rather than a live form.

The backend was not touched. It was already correct, and the whole point of the
fix is that the correct direction is `UI DENY + BACKEND DENY`.

### Why disabled rather than hidden

The repository convention, set by `rm-sheet-nav.blade.php` for the identical act
("+ Tambah Lembar RM"), is a disabled control beside a stated reason. The doctor
stays oriented — the action exists, it is simply not available yet — instead of
discovering the lock by pressing it or wondering where the button went. What
must not survive is the submittable path, and it does not: there is no `<form>`
aimed at the store route at all.

## 4. Final capability matrix

```
BEFORE_START:
  ODONTOGRAM=DENY   RME_FIRST_PAGE=DENY   RME_EDIT=DENY   FINISH=DENY

IN_PROGRESS_NO_CONSENT:
  ODONTOGRAM=ALLOW  RME_FIRST_PAGE=DENY   RME_EDIT=DENY   FINISH=DENY

IN_PROGRESS_VALID_CONSENT:
  ODONTOGRAM=ALLOW  RME_FIRST_PAGE=ALLOW  RME_EDIT=ALLOW  FINISH=ALLOW (other guards)

HISTORICAL (cashier_pending / completed):
  RME_FIRST_PAGE=DENY   RME_EDIT=DENY
HISTORICAL (cancelled):
  no workspace at all — the visit is not an eligible RM anchor (404)

LEGACY:
  RME_MUTATION=DENY (published archive immutable, unchanged)
```

`IN_PROGRESS_NO_CONSENT / ODONTOGRAM = ALLOW` is the
REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1 rule and is pinned by this
sprint's own suite, not merely inherited. Blocking the record must never drag
the chart down with it.

## 5. Scope discipline

Deliberately **not** changed: the consent gate itself, consent form content or
signing, the odontogram authoring rule, the finish gate, RME clinical content,
print/PDF, billing, Legacy RME, Legacy Odontogram, permissions, routes, schema.

One neighbouring test was **superseded**, not silently edited around.
`tests/Browser/OdontogramPreConsentEditBrowserTest.php` step 4 used to *press*
"Buat Halaman RM Pertama" and watch the server refuse, carrying a note that the
button "is gated on the manage permission only, not on consent, so it is offered
even though the server refuses". That note described this bug. The step now
asserts the disabled control and the absent form; its claim for that sprint —
that blocking the record did not travel with the odontogram permission — is
unchanged.

## 6. Enforcement in CI

The suite is wired in **twice**, deliberately:

- token `RmePreConsentFirstPageUiGate` added to both critical-gate filter
  variants in `.github/workflows/foundation-evidence-gates.yml`;
- the file declared in `config/ci_runner.php` `critical_gate_mandatory_suites`,
  on the STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 precedent.

The declaration is what makes the token unremovable: `SelfHostedRunnerScanner`
requires every declared file to be selected by some token in *every* critical
variant, so a future edit that drops the token turns the gate red instead of
quietly deleting the coverage.

The precedent is earned on its own terms. The mismatch was live in production;
nothing in CI asserted the two authorities agreed; and the likely regression —
a UI that becomes more permissive than the service — produces no error, no
exception and no log line, only a control that fails when pressed.

## 7. Evidence

- New suite `tests/Feature/RME/RmePreConsentFirstPageUiGateTest.php` — 22 tests.
  Red before the fix (11 failed), green after, on SQLite **and** on
  PostgreSQL 16 (the production driver).
- New browser suite `tests/Browser/RmePreConsentFirstPageBrowserTest.php` —
  two journeys through Chrome against PostgreSQL 16: before consent the control
  is present and genuinely not pressable (asked of Selenium, not grepped) while
  the odontogram stays available; after signing through the real service the
  control returns and the create succeeds.
- Mutation matrix M1–M18 with zero real survivors.

## 8. Security review

Independent review of the working-tree diff: **CRITICAL 0 · HIGH 0 · MEDIUM 0**,
two LOW.

**LOW-1 — fixed in this sprint.** The two Blade guards defaulted
`($addSheetConsentRequired ?? false)`. That is a *capability* flag, so an absent
key meant "assume allowed" and would fall through to the live create form —
silently reinstating the exact mismatch this sprint removes, in the exact shape
the registry docblock calls out (no error, no exception, no log line). Both now
default `?? true`, and the reason is stated in the template so it does not read
as a stylistic choice. This also aligns them with the neighbouring
`($activeEncounter ?? null)`, which already failed closed.

**LOW-2 — RECORDED, deliberately not changed here.**
`ActiveEncounterResolver::currentFor()` is scoped to the whole RME-enabled
branch set rather than the viewer's working branch, so a doctor authorized on a
patient in branch A can be told "Kunjungan #<branch B's visit_number>" when that
patient has a live examination elsewhere. The `@can` still withholds the button;
only the sentence renders. This is **pre-existing behaviour shipped in
`show.blade.php`**, mirrored here verbatim — this sprint extends the surface, it
does not introduce the class. `visit_number` is an operational identifier, not
clinical content, and the viewer is already authorized on the patient. It is
worth deciding once for both surfaces in its own change rather than being
half-fixed here, where an inconsistency between the two workspaces would be a
worse outcome than the disclosure.

## 9. The durable rule

`.cursor/rules/135-rme-authoring-ui-capability-mirror.mdc`, cross-linked from
`134-odontogram-pre-consent-authoring.mdc` in both directions.

Its general form, which outlives this button:

> A permission check is not a capability check. Any control whose act is gated
> by encounter state, consent, or workflow status must consult the capability,
> not only the permission — and when UI and backend disagree, the resolution is
> always UI DENY + BACKEND DENY, never a relaxed server.
