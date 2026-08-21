# FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3

Four integrated fixes that re-time the RME clinical workflow: the doctor now ends
the examination explicitly, consent moves from the cashier's counter to the start
of the examination, payment stops depending on consent entirely, and the patient's
odontogram history becomes visible — including a new immutable Legacy Odontogram
archive.

| | |
|---|---|
| Sprint id | `FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3` |
| Type | `FOUNDATION_SPRINT` (cross-module: ClinicVisit, MedicalRecord, Consent, RmeInvoice, Odontogram, LegacyOdontogram) |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Baseline | `fix-rme-consent-workflow-print-ux-2-go` @ `b080ab1` (runtime), `ac6d620` (base tip incl. evidence) |
| GO tag | `fix-rme-exam-consent-odontogram-history-3-go` |
| Full Suite | **DEFERRED BY GLOBAL TEMPORARY POLICY** — `FULL_SUITE_EXECUTION_COUNT=0` |
| Durable rules | `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc` |

---

## The canonical workflow after this sprint

```
Pendaftaran -> Antrian -> Input Ruangan
  -> Dokter "Mulai Pemeriksaan"            -> in_progress
  -> Persetujuan Tindakan Medis            -> RME authoring unlocked
  -> RME + Odontogram
  -> RME and/or Odontogram complete        -> STILL in_progress
  -> Dokter "Selesai Pemeriksaan"          -> cashier_pending
  -> Kasir / Pembayaran (no consent check) -> completed
```

Historical clinical evidence — RME history, Legacy RME, native odontogram
history, Legacy Odontogram — stays **read-only and readable throughout**. The
**active odontogram workflow is unchanged**.

---

## FIX-01 — Explicit doctor examination completion

### The defect

`MedicalRecordService::finalize()` transitioned an `in_progress` visit to
`cashier_pending`. Completing a clinical **document** therefore completed the
doctor's **examination** and handed the patient to the cashier. Those are two
different facts:

- a doctor who finalizes one RM sheet may still have an odontogram to chart, a
  second sheet to write, or a treatment decision to revise;
- since Sprint 59 a finalized record is explicitly editable again, so `final` was
  never a reliable proxy for "the examination is over".

The audit confirmed this was the **only** implicit path: no observer, event,
listener, job, scheduled command or model hook moves visit status, and the
odontogram module never touches it at all.

### The change

The transition is removed from `finalize()`. `ClinicVisitService` is no longer a
constructor dependency of `MedicalRecordService`, so the class does not even hold
the ability to move a visit.

The explicit action already existed and needed no new authorization:
`POST rme/visits/{visit}/transition` → `ClinicVisitController@transitionStatus`,
guarded by `ClinicVisitPolicy::completeExamination` / `complete_rme_examination`
(Doctor, Perawat, Supervisor RME; Super Admin via `Gate::before`). Admin Klinik
and Kasir were already denied.

A **"Selesai Pemeriksaan"** button was added to the Medical Record page header,
pointing at that same guarded route. Without it a doctor could finalize, leave,
and strand the patient at `in_progress` with the cashier unable to raise an
invoice — because `RmeInvoiceService::create()` still requires `cashier_pending`.

---

## FIX-02 — Consent becomes the RME authoring gate

### The change

`RmeVisitConsentService::isSignable()` no longer requires `cashier_pending`.
Consent is now signable for any **non-terminal** visit, and a valid signed consent
is required before that visit's RME may be **written**.

New API on `RmeVisitConsentService`:

| Method | Meaning |
|---|---|
| `isWithinConsentWindow()` | the visit is non-terminal |
| `isSignable()` / `assertSignable()` | consent may be captured |
| `requiresConsentBeforeRmeAuthoring()` | in-window **and** no valid consent |
| `assertRmeAuthoringAllowed()` | the single assertion every write funnels through |
| `assertRmeAuthoringAllowedForAll()` | assert across every visit one action touches |

### Why the window is "non-terminal" rather than `in_progress`

The breadth is what makes the gate unbypassable:

- a roomed `registered` / `waiting` visit can already reach the RME screens, so an
  `in_progress`-only gate would let a doctor author a complete record before the
  examination formally started;
- **"Selesai Pemeriksaan" requires no consent**, so if `cashier_pending` were
  outside the window a doctor could finish the examination first and then author
  the entire record unconsented.

Because the gate window and the signable window are **the same window**, a blocked
write always has an unblocking action available — the gate can never deadlock a
live visit.

**Terminal visits are never gated.** Sprint 59 makes finalized and historical
records revisable, and no visit predating this architecture has a signed consent,
so gating them would permanently lock historical clinical correction.

### Where it is enforced

Service layer, so no route can bypass it:

- `MedicalRecordService::createDraft()` / `updateDraft()` / `finalize()`
- `MedicalRecordService::getOrCreateCanonicalMedicalRecord()`
- `MedicalRecordHandwritingController::store()` (writes through the repository
  directly, so it asserts the gate itself)

**The encounter is gated, not only the storage target.** Sprint 64.0.2 redirects a
new handwriting page onto the patient's canonical medical record, which usually
belongs to an older, terminal visit. Checking only the record's owner would let
content authored during a live unconsented encounter be written through an exempt
historical visit.

### What consent does NOT block

- **Reading anything.** Patient history, previous sheets, Legacy RME, previous
  odontograms, the Legacy Odontogram archive and this visit's own record stay
  fully readable. Withholding the clinical history from the doctor deciding the
  treatment would be unsafe.
- **The active odontogram.** Its workflow, UI, payload, authorization and print
  are unchanged. Consent is deliberately not a new odontogram gate.

---

### Adversarial review — what it found and what changed

An adversarial security review was run against the finished implementation. It
found three HIGH issues, all fixed and pinned by regression tests before merge.

**HIGH — the gate asked the request which visit it was for.** The "live encounter"
came from the `{clinicVisit}` route parameter and an optional `source_visit_id`
field. Because Sprint 64.0.2 stores new handwriting on the patient's CANONICAL
record — for a returning patient an older, TERMINAL, therefore EXEMPT visit —
opening the book from the Rekam Medis list (ordinary navigation, no crafting) let a
doctor write today's clinical note with no consent anywhere: every visit the
request named was exempt.

Fixed with `assertPatientRecordWritable()`, which asks a question the request cannot
influence: **does this patient have an open encounter that has not been consented?**
One patient has one record book, so a write into that book during a live encounter
is a write for that encounter whatever visit id the URL carries. A patient with no
open visit is unaffected, so Sprint 59 historical revision still works.

**HIGH — the odontogram history used the whole RME estate as its branch scope.**
`BranchService::rmeEnabledIds()` with a doctor-only narrowing is a no-op for Kasir,
Admin Klinik and Perawat, so a context-bound operator would have read a patient's
clinical odontogram findings from every branch, in bulk — a boundary the per-record
abilities hold. Now intersected with `RmeWorkingBranchScope::branchIdsFor()`.

**HIGH — the Legacy Odontogram intake had no duplicate detection.** Its sibling
Legacy RME archive blocks a document whose checksum is already staged or published.
Without it the same scanned chart could be published irreversibly into two
different patients' clinical histories. Mirrored, including the deliberate
exception that a VOID collision does not block (void-then-reimport is the
correction path).

Also fixed: structured diagnoses and the emergency diagnosis override now pass the
gate; the "Selesai Pemeriksaan" button no longer silently retargets when the doctor
swipes to another sheet (it hides, and its label names the visit); the odontogram
history's clinical-content predicate moved into SQL so `limit` bounds real history
rather than letting empty drafts push findings out; staged legacy page reads are
audited.

## FIX-03 — Cashier / payment consent independence

`assertConsentVerified()` and its three call sites are **deleted**. Consent is no
longer any part of payment eligibility.

| Surface | Change |
|---|---|
| `RmePaymentService` | gate method and all call sites removed; may never consult consent again |
| `CreateRmePaymentRequest` | `consent_signed_by_*` rules and messages removed |
| `RmePaymentController` | no longer resolves a signed consent for the view |
| `payment/create.blade.php` | the whole consent block removed |
| `CashierHandoffStatusService` | `CONSENT_PENDING` rung removed |
| `cashier/handoff.blade.php` | Consent column removed (9 `<th>` / 9 `<td>` verified) |
| `clinical-summary` / `visits/show` | reworded to the real signed document, informational only |

The safety property is **stronger**, not weaker: a treatment that was recorded at
all already had consent. The old cashier gate only fired while the visit was at
`cashier_pending`, so every carry-over receivable and every instalment on an
already-completed visit bypassed it by design.

Historical receivables — every one of which predates the consent architecture —
stay collectible.

The legacy `consent_signed_by_patient` / `consent_signed_by_doctor` columns and
`hasVerifiedConsent()` are retained as historical display data. Nothing reads them
for authorization any more; the display surfaces were switched to
`hasSignedConsentDocument()` so they do not report "Belum" forever.

---

## FIX-04 — Patient odontogram history + Legacy Odontogram

### Native history (read-only)

A **Riwayat Odontogram Pasien** card was added to the odontogram page, below the
active chart and **outside** the `x-data="odontogramEditor(...)"` wrapper so its
Alpine state can never bind against the chart being edited.

- New `OdontogramRepositoryInterface::patientHistoryForBranches()` — branch ids
  are a required parameter so a caller cannot forget them; an empty set fails
  closed to an empty result.
- New `OdontogramService::patientHistoryForVisit()` — applies the active RME
  branch set **and** the doctor's clinical patient scope, excludes the current
  visit, and excludes empty auto-created drafts.
- Ordering is by the owning **visit's clinical date**, not the odontogram's
  timestamps: a chart may be corrected long after the encounter, and the history
  is a clinical timeline, not an edit log.
- Read-only by construction: no form, no input, no save/finalize/delete control,
  and no mutation endpoint accepts a history row.

**`OdontogramPolicy::update` is unchanged.** A doctor may still correct a past
chart by opening that visit's own odontogram page — Sprint 59 requires it, and an
erroneous old chart must stay correctable. This was an explicit product decision.

### Legacy Odontogram archive

A separate bounded context modelled on the shipped Legacy RME archive, with its
own feature flag, tables and permissions. It deliberately does **not** piggyback
on a Legacy RME migration batch, wave, quota or admission, and adds no
discriminator column to the Legacy RME published tables.

Rules: immutable published evidence (VOID + re-import is the correction path);
branch derived from the medical-record number's branch code, never submitted;
manually chosen clinical date validated against the clinical clock and strictly
before the patient's earliest **native odontogram** (not the earliest RME —
a different fact); PDF-only for v1 to match the existing Poppler pipeline;
private storage with policy-gated streaming; the flag gates migration only, never
reading an already-published archive.

---

## Legacy RME programme state

Untouched. `ENGINEERING_ROLLOUT_MODE` stays CLOSED, `STEADY_STATE_OPERATIONS`
stays AUTHORITATIVE, and the resting state (capability OFF, admission EMPTY, no
active batch) is preserved. `ROUTINE-20260819-TKM1-01` remains CANCELLED and is
never reused.

## Inherited holds

`FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 / FIX-02` (WhatsApp prescription delivery)
remains **HOLD / BLOCKED_EXTERNAL_DEPENDENCY**. This sprint does not activate it,
touch the gateway, the configuration or the credentials.

---

## Superseded rules

| Superseded | Replaced by |
|---|---|
| "Consent is the RME payment gate" (`.cursor/rules/100` §1) | consent gates RME **authoring** |
| "Consent required while the visit is at `cashier_pending`" (§1a) | consent is **never** a payment condition |
| "Consent is signable only at `cashier_pending`" (§2) | signable for any non-terminal visit |
| "Finalize RM → transition ke cashier_pending" (`docs/ai-knowledge/08`) | finalization transitions nothing |

Preserved unchanged: `.cursor/rules/100` §1b and §3–11; `.cursor/rules/108`
§10–12 (examination completion authority, cashier-owned `completed`, "hiding a
button is never the boundary").

---

## Closure evidence

<!-- filled in at closure -->

| | |
|---|---|
| BASE_SHA | |
| FEATURE_BRANCH | `feature/fix-rme-exam-consent-odontogram-history-3` |
| CANDIDATE_SHA | |
| PR | |
| RUNTIME_MERGE_SHA | |
| EVIDENCE_SHA | |
| VPS_HEAD | |
| GO_TAG | `fix-rme-exam-consent-odontogram-history-3-go` |
| TAG_OBJECT_SHA | |
| TAG_PEELED_SHA | |
| CI run | |
| FULL_SUITE_EXECUTION_COUNT | `0` |
| FULL_SUITE_STATUS | `DEFERRED_BY_GLOBAL_TEMPORARY_POLICY` |
| Local regression | |
| Migration | |
