# REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1

Odontogram pre-consent authoring: the active chart becomes editable the moment
the doctor starts the examination. The RME and "Selesai Pemeriksaan" stay gated
by the signed `PERSETUJUAN TINDAKAN MEDIS`.

- **Type:** BUSINESS_RULE_REVISION (clinical authorization)
- **Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- **Baseline:** `feature-doctor-account-performance-income-linkage-1-go` @ `e9e6450`
- **Rule mirror:** `.cursor/rules/134-odontogram-pre-consent-authoring.mdc`
- **Supersedes:** the CORRECTIVE-03 clause in
  `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc` that made a signed
  consent a precondition of authoring the ACTIVE odontogram.
- **Migration:** none. No schema, route, permission or policy change.

## The problem

FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / CORRECTIVE-03 made a signed consent
the fourth precondition of writing the live encounter's odontogram, reasoning
that an odontogram is a clinical finding recorded during a treatment decision
"exactly like the note beside it".

In the clinic it is not. Charting teeth is **observation**: the doctor looks in
the mouth and records what is already there. A `PERSETUJUAN TINDAKAN MEDIS`
names the treatment being agreed to, and that name is derived from the
observation. Requiring the signature first made the workflow **circular** — the
consent form could not be completed until the finding existed, and the finding
could not be recorded until the form was signed. Doctors worked around it by
charting on paper and transcribing later, which is exactly the un-evidenced
clinical record the consent architecture exists to prevent.

## The change

One condition removed, in one method.

`RmeVisitConsentService::assertOdontogramAuthoringAllowed()` previously checked
four things. It now checks three:

1. the patient HAS a single current `in_progress` encounter (ambiguity fails
   closed);
2. the actor may work on that encounter (branch scope + clinical patient scope;
   an unresolvable actor fails closed);
3. the chart being written IS that encounter's own chart.

The fourth — `hasValidConsent($encounter)` — is gone, and its absence is stated
in the method rather than left to be inferred.

Nothing else moved. `assertRmeAuthoringAllowedForPatient()` still requires the
signature. `ClinicVisitService::transitionStatus()` still refuses
`in_progress -> cashier_pending` without it.

### Why this is a small diff

The three odontogram write paths — `saveForVisit()`, `updatePlaceholder()`,
`finalize()` — already funnelled through that single assertion, and the page
already derived its controls from `canAuthorOdontogramFor()`, which delegates to
it. Because CORRECTIVE-03 centralised the rule instead of scattering it, the
revision is one method plus the wording that described it. No second check
existed anywhere to find and remove.

### Odontogram finalization

`finalize()` is a chart-level lifecycle act, not "Selesai Pemeriksaan". Since
Sprint 59 a finalized chart is still revisable and finalizing one leaves the
visit `in_progress`. It uses the same assertion, so it is permitted pre-consent
too. Splitting it out would have created a second, divergent consent rule for no
clinical gain.

## What deliberately did NOT change

| | before | after |
|---|---|---|
| Odontogram, `in_progress`, unsigned | DENY | **ALLOW** |
| RME, `in_progress`, unsigned | DENY | DENY |
| Selesai Pemeriksaan, unsigned | DENY | DENY |
| Odontogram before "Mulai Pemeriksaan" | DENY | DENY |
| Odontogram, non-Doctor / other Doctor | DENY | DENY |
| Historical native chart | DENY | DENY |
| Published Legacy Odontogram | DENY | DENY |

Charting signs no consent, creates no consent row, and advances no visit status.
Consent signed after charting leaves the chart untouched — same row, same teeth.

## UI

The odontogram page previously showed a warning: *"Odontogram dapat dilihat,
tetapi belum dapat diubah. Pasien harus menandatangani form consent sebelum
dokter dapat mencatat atau mengubah odontogram."* That statement is now false and
was replaced, not deleted — with what is still true:

> Odontogram sudah dapat dicatat dan disimpan sekarang. Rekam Medis kunjungan ini
> dan tombol Selesai Pemeriksaan masih terkunci sampai pasien menandatangani
> Persetujuan Tindakan Medis.

This matters operationally: a doctor can now complete the entire chart without
ever meeting the gate, and would otherwise first discover the visit is stuck when
they try to finish it.

The historical read-only notice is unchanged.

The **visit detail page** carried the same false claim in a different place — its
consent banner read *"Rekam medis **dan odontogram** kunjungan ini belum dapat
ditulis..."*. Leaving it would have sent the doctor to collect a signature before
the examination that produces the chart's content, which is the circularity this
sprint removes. It now names only what is actually locked.

### A pre-existing mismatch found and NOT fixed here

On the RME workspace **empty state** (a patient with no RM sheet yet), the "Buat
Halaman RM Pertama" button is gated on the manage permission only, not on
consent — so it is offered while `MedicalRecordService::createDraft()` refuses.
There is no security defect: the server refuses correctly, which the browser test
now proves by pressing the button and asserting no record is created. It is
reported rather than fixed because it predates this sprint, lives in the RME
empty state rather than the odontogram, and correcting it means changing a
workspace surface this revision was not asked to touch.

## Tests

New: `tests/Feature/RME/RmeConsentOdontogramPreConsentEditTest.php` (34).

Written before the source change; 17 of its first 31 cases failed against unmodified source
and 14 — every guard-preservation case — passed, which is the shape a revision
should have.

Revised, not deleted, in `RmeExamConsentCorrective3Test.php`: the four cases that
asserted the withdrawn clause, plus the read-only-notice case. Each carries a
`REVISED by` note naming what it used to assert, so the history stays legible.

New browser test: `tests/Browser/OdontogramPreConsentEditBrowserTest.php` (2),
run through Chrome against PostgreSQL 16 — the production driver. It charts a
tooth with the real editor, reloads to prove persistence, presses the real RME
button and asserts the refusal, signs through the real service, and re-checks the
chart survived. It drives the production DOM; no `dusk=""` hooks were added to
the template to make it convenient.

Mutation testing: 18 mutants, **17 killed, 1 equivalent, 0 real survivors**. Three
initially survived and two were real gaps worth the exercise — the persistence
test signed via the `rmeSignedConsentFor()` fixture and so never executed the real
signing path (a signing side effect that destroyed the chart would have gone
unnoticed), and `OdontogramPolicy::canManage()` was masked by the route
middleware, so the policy layer was not independently asserted. Both are now
pinned. The equivalent one removes a row lock that no single-threaded test can
observe: `createForClinicVisit()` is itself find-or-create, so the mutant produces
no behavioural difference.

The suite is declared in `config/ci_runner.php` `critical_gate_mandatory_suites`.
It is selected today by the existing bare `Odontogram` filter token — i.e. by a
substring of its filename, which is precisely the "coverage rested on a filename
coincidence" failure that registry exists to prevent. Declaring it makes a
future rename move the coverage rather than silently drop it.

## An unrelated CI failure fixed on the way through

The first CI run reddened on `InventoryAnalyticsServiceTest > it groups monthly
outbound value trend by month` — a module this sprint does not touch (`git diff
base..HEAD -- app/Modules/Inventory tests/Feature/Inventory` is empty).

It is a **calendar flake**, not a regression. The test seeds the current month's
outbound movement at `now()->startOfMonth()->addDays(2)` but filters the query
with `date_to = now()`. On the 1st and 2nd of any month that movement is in the
future, so the current-month row does not exist and `$currentRow` is null —
"Trying to access array offset on null". Today is the 1st, so every PR opened
today would have hit it.

Fixed by dating that movement `now()`, which is always inside the window and
always in the current month. The grouping the test is about, and its expected
`500.0`, are unchanged. Kept as its own commit so it stays legible as unrelated.

## Files

- `app/Modules/Consent/Services/RmeVisitConsentService.php` — the one behavioural change
- `app/Modules/Odontogram/Controllers/OdontogramController.php` — `$odontogramConsentRequired` → `$consentPendingForRme`
- `resources/views/rme/visits/odontogram/show.blade.php` — reminder replaces refusal
- `config/ci_runner.php` — mandatory-suite registry entry
- `.cursor/rules/134-odontogram-pre-consent-authoring.mdc` — new canonical rule
- `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc` — superseded clauses corrected in place
- `tests/Feature/RME/RmeConsentOdontogramPreConsentEditTest.php` — new
- `resources/views/rme/visits/show.blade.php` — the banner no longer claims the odontogram is locked
- `docs/ai-knowledge/08_DaengtisiaMS_RME_Workflow.md` — consent/odontogram sections + the workflow steps
- `tests/Feature/RME/RmeExamConsentCorrective3Test.php` — revised
- `tests/Feature/RME/RmeExamConsentCorrectiveTest.php` — revised (inverted for the second time; see the note in the file)
- `tests/Feature/RME/PostRmeOdontogramStabilizationTest.php` — revised (the "a refused write leaves no trace" property kept, trigger moved)
- `tests/Browser/OdontogramPreConsentEditBrowserTest.php` — new
