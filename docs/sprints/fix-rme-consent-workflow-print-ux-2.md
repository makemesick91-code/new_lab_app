# FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2

Six integrated fixes to the RME clinical and cashier workflow.

- **Branch:** `feature/fix-rme-consent-workflow-print-ux-2`
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `2b1725c`
- **Manifest:** `.sprint/current.yml` — `FOUNDATION_SPRINT` (cross-module; the stricter classification)
- **GO tag:** `fix-rme-consent-workflow-print-ux-2-go`

> **Full Suite:** NOT RUN. The global temporary Full-Suite policy is ACTIVE and
> this sprint's GO tag is an explicitly requested governance exception.
> `FULL_SUITE_EXECUTION_COUNT=0`. The GO tag does **not** mean the consolidated
> Full Suite passed; that obligation remains deferred.

---

## FIX-01 — A signed consent is the payment gate

### What was actually there

There was no consent document. The entire feature was two booleans on
`trx_clinic_visits`, and — this is the defect — **the payment request supplied
them**:

```php
// RmePaymentService, before
$this->applyConsentVerification($visit, $cashier, $data); // writes $data booleans onto the visit
$this->assertConsentVerified($visit);                     // reads back what it just wrote
```

`CreateRmePaymentRequest` required the two fields to be `accepted`, the
controller passed `$request->validated()` straight through, and the service
persisted them and then asserted against its own write. A POST of

```
consent_signed_by_patient=1&consent_signed_by_doctor=1
```

settled an invoice with no document, no signature and no patient involvement.
All three payment entry points (`pay`, `allocateControlPayment`,
`allocateVisitPayment`) shared the same self-satisfying pair.

### What replaced it

Payment now asks a question it cannot answer itself: is there a signed,
non-voided `PERSETUJUAN TINDAKAN MEDIS` for **this** visit? Only
`RmeVisitConsentService` can create that record, and only from a real signature.

`applyConsentVerification()` is deleted. `assertConsentVerified()` is a pure read.

### The document

The wording is transcribed from the clinic's own printed form and lives in
`config/rme_consent.php`. It is **snapshotted into every signed consent**
(`content_snapshot`), so editing the template later cannot rewrite what a patient
already agreed to.

Clause 8 (documentation / publishing photos and video) is recorded as an
explicit `YA`/`TIDAK` with **no default**, and answering `TIDAK` never blocks
treatment or payment — consent must not become coercive. That is asserted
directly, and the validation rule is `present|boolean` rather than `required`,
because `required` rejects the legitimate answer `0`.

### Rules enforced server-side

| Rule | Where |
|---|---|
| Signing only at `cashier_pending` | `RmeVisitConsentService::assertSignable()` — earlier would be consent to an undecided treatment, later would be back-dating evidence |
| Signature is the evidence | `PrescriptionCanvasDecoder` (reused): real PNG, non-blank, **pure PHP so no GD needed** |
| Treating doctor from the visit, never the request | `sign()` reads `$visit->doctor` |
| "Saya sendiri" copies the canonical patient identity | typed input cannot override `mst_patients` |
| Signed consent is immutable | no update path in the service; policy hard-denies `update`/`delete`; correction is void + re-sign, and the voided row is **kept** |
| Branch + patient isolation | `RmeVisitConsentPolicy` via `RmeWorkingBranchScope` + `DoctorPatientScopeService` |
| Signatures are private | private disk, no public URL, streamed only through the policy |

### Permissions

New `manage_rme_consents` / `view_rme_consents`, granted to **Doctor, Kasir,
Admin Klinik, Supervisor RME**.

They are deliberately *not* `manage_clinic_visits`: **Kasir does not hold that
permission**, so hanging consent off it would have 403'd the one role that must
complete every payment. `RmeVisitConsentHttpTest` keeps a real-`Kasir` test to
stop that mistake being reintroduced.

### The legacy booleans

Kept, but demoted. `hasVerifiedConsent()` is now a **display** predicate
(signed document *or* the old cashier attestation) so visits settled before this
sprint keep a truthful history. The **gate** uses `hasSignedConsentDocument()`,
which only counts a real document. Nothing writes the booleans from a request
any more.

> **Operational consequence:** a visit sitting at `cashier_pending` at deploy
> time has no signed consent and therefore cannot be paid until one is taken.
> That is the intended behaviour, and the cashier is told so on both the visit
> detail and the payment page rather than discovering it by being refused.

### The gate covers treatment, not debt collection

Found by adversarial review, and it blocked merge until fixed.

The partial-payment rule completes a visit on its **first** payment while the
invoice stays `PARTIAL` and payable. So *"visit completed + invoice PARTIAL"* is
the normal shape of an outstanding instalment — and of every receivable that
existed before this sprint. Because consent can only be signed at
`cashier_pending`, a gate on every `pay()` made all of them **permanently
uncollectable**, on a path the Piutang screen links to directly.

Consent is therefore required **while the visit is at `cashier_pending`** and not
for instalments on a completed visit, nor for carry-over receivables settled
during a later visit. Collecting a debt is not a new treatment.

The exemption is safe only because `completed` is not an accepted value in
`TransitionStatusRequest`, `ClinicVisitService::transitionStatus()` refuses it
from anywhere but `cashier_pending`, and `RmeInvoiceService::create()` will not
raise an invoice on a non-`cashier_pending` visit — so the only route to a
completed visit is *through* a payment that already passed the gate. All three
are asserted, not assumed.

**Performance:** `hasVerifiedConsent()` was a free column read and is now a
query, so the unpaginated cashier handoff board eager-loads `consents`.

---

## FIX-02 — Medical Record page hierarchy

`Dokumen RME Pasien` was rendered **above** `Informasi Kunjungan`. The order is
now:

```
Informasi Kunjungan  ->  RME Tulisan Tangan  ->  Dokumen RME Pasien
```

This supersedes LEGACY-RME-DOCTOR-WORKSPACE-1, which put the document rail above
the history card so the archive was reachable without scrolling. That goal
survives the move: **LEGACY-RME-DOCTOR-WORKSPACE-1A inlined the published legacy
pages into the handwriting canvas itself**, so the archive is still near the top
and the rail is the explicit selector, not the only path. A test now pins that
reasoning (`still inlines published legacy pages in the canvas after the rail
moved down`) — if the inline pages ever regress, FIX-02's placement must be
revisited.

## FIX-03 — Finalisasi moved to the top

Moved into the page-header actions. **Moved, not duplicated** — asserted by
counting both the finalize route and the label. Authorisation, the route, and
the mandatory-handwriting precondition are unchanged; the state messages travel
with the button so the doctor learns *why* it is unavailable without scrolling.

## FIX-04 — Cetak RME moved to the Medical Record page

Removed from Detail Kunjungan; added to the Medical Record page header, mirroring
`Cetak Odontogram`. It reuses the **existing** print route — no second print
endpoint — and prints `$clinicVisit`, which `MedicalRecordController` binds to
`$activeSheet->clinicVisit`, i.e. the visit whose sheet is on screen.

**Superseded contract:** FIX-CLINIC-OPS (FIX-07) said "Admin Klinik's visit
detail is read-only plus Cetak RME". The front office would otherwise have
*lost* the capability, because the Rekam Medis card is behind `$canOperateVisit`
and they would have had no path to the page that now owns the action. They get a
**navigation link** (not a second print button), gated by the very ability that
decides whether they may print; clinicians already have the card, so they get no
duplicate — also asserted.

## FIX-05 — Odontogram removed from the RME print

Removed from `partials/print-body.blade.php`, which both `print.blade.php` and
`print-pdf.blade.php` include, so one edit covers browser print and PDF. The
now-dead status-label maps, the unused `$odontogramPrint` view-model and its
eager load went with it.

Print composition only — the odontogram model, table, workflow, editor UI and
standalone print are untouched, and `MedicalRecordPrintOdontogramSeparationTest`
proves **both** halves. The PDF half is proven against real renderer output
(dompdf + `pdftotext`) with positive control assertions, so the absence checks
cannot pass vacuously.

## FIX-06 — One-page kwitansi

The receipt declared **no `@page` rule** — the only print template in the repo
that did not — so margins were the browser default. It now declares A4 with 10mm
margins and compacts card chrome, logo and table padding in print.

One page is reached by removing waste, never by hiding money. Nothing is
clipped, no row is suppressed, totals are kept whole, and a receipt beyond the
supported envelope **continues** with a repeating header rather than losing
rows.

Because a print dialog cannot be measured, the same receipt is also renderable
through the existing dompdf pipeline (`rme.cashier.receipt.pdf`), sharing the
receipt's authorisation, paid-only rule and — via `resolveReceiptData()` — its
data, so the two cannot drift. The tests render the real PDF, read the page
count with `pdfinfo`, then extract the text and assert every item, subtotal,
total, payment and identity survives.

**Recorded, not changed:** the kwitansi is a *pelunasan* document; both receipt
routes redirect unless the invoice is `PAID`, so a partial payment has no
receipt at all.

---

## Superseded contracts

Every one was updated in place with the reason recorded inline, never worked
around:

| Test | Was | Now |
|---|---|---|
| `MedicalRecordPrintOdontogramMergeTest` | odontogram merged into RME print | replaced by `MedicalRecordPrintOdontogramSeparationTest` |
| `StructuredOdontogramPrintTemplateTest` (combined print) | combined print includes odontogram | excludes it; standalone unchanged |
| `RmePdfPrintHardeningTest` | RME print includes odontogram summary | excludes it |
| `ClinicVisitTest` ×3 | odontogram in print; Cetak RME on visit show | inverted |
| `FixClinicOpsVisitActionsTest` | Admin Klinik sees Cetak RME | sees a Rekam Medis navigation link; read-only guarantee intact |
| `RmeOdontogramUixTest` | print route on detail page | absent |
| `OdontogramSelectedResultsTableTest` | selected results in visit print | absent |
| `LegacyRmeDoctorWorkspaceDocumentsTest` | rail above history card | ordering dropped; inline-pages safety net added |
| `RmePatientFormatConsentTest` ×4 | booleans gate payment | signed document gates payment |
| `RmeDoctorCashierCompletionGateTest` | consent omitted ⇒ blocked | consent *asserted* ⇒ still blocked |
| `CashierPaymentUixTest` | checkbox-only consent block | real consent state, both branches |

## Evidence

- New: `RmeVisitConsentGateTest` (26), `RmeVisitConsentHttpTest` (17),
  `RmeReceiptOnePageTest` (9), `MedicalRecordPageActionsTest` (12),
  `MedicalRecordPrintOdontogramSeparationTest` (10)
- Shared helpers added to `tests/Pest.php`: `rmeSignedConsentFor()`,
  `pdfPageCount()`, `pdfExtractText()` (poppler — already a CI-verified dependency)
- All five new suites added to the CI critical filter in **both** gate variants

## Deploy

```
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan permission:cache-reset
```

One additive table (`trx_rme_visit_consents`). No destructive migration.

---

## Closure evidence

| | |
|---|---|
| BASE_SHA | `2b1725c2c5002cc9c20a0c3e64fc113cbe068f48` |
| CANDIDATE_SHA | `39318f72bce2cab8d0d95f15d8e5fbc64c23209a` |
| PR | #323 |
| RUNTIME_MERGE_SHA | `b080ab13e47de3d6e47bafd06a41996fdd8f8fe0` |
| VPS_HEAD | `b080ab13e47de3d6e47bafd06a41996fdd8f8fe0` (exact match) |
| GO_TAG | `fix-rme-consent-workflow-print-ux-2-go` (object `ccc4586`) |
| CI run | `32396544839` — all required gates success |
| FULL_SUITE_EXECUTION_COUNT | **0** — deferred, `full_suite_authorized=false` |
| Local regression | 1504 passed / 0 failed |
| Migration | 1 additive table, batch 60, after a pre-deploy backup |

### Production UAT — read only

Zero writes: `payments 31/31`, `consents 0/0`, `medical records 29/29`.

- **FIX-01** — 2 real `cashier_pending` visits: `hasSignedConsent=no` (payment
  blocked), `signable=yes`. Visit detail shows the consent banner and
  "Pilih Form Consent"; no identity number rendered.
  **Gate boundary proven on real money:** `RME-202608-000001` carries an
  outstanding **Rp 1.000.000** on a COMPLETED visit and stays collectable.
- **FIX-02** — `Informasi Kunjungan` < `RME Tulisan Tangan` < `Dokumen RME
  Pasien`; the rail is still rendered.
- **FIX-03** — FINAL record: button absent, "telah difinalkan" shown. Real DRAFT
  record: **exactly 1** Finalisasi, above the handwriting.
- **FIX-04** — **exactly 1** "Cetak RME" on the RME page above the handwriting;
  label and `rme.visits.print` both **absent** from Detail Kunjungan.
- **FIX-05** — real visit with an odontogram: odontogram markers absent, patient
  / medical record / branch present, **standalone odontogram print still works**.
- **FIX-06** — real PAID invoice via `rme.cashier.receipt.pdf`:
  **`pdfinfo` page count = 1**, grand total + LUNAS + every item present in the
  extracted text.

Health on `https://daengtisia.online`: `/login`, `/health/live`,
`/health/ready`, `/health/lb` all **200**. `NEW_RELEVANT_APPLICATION_ERRORS=0`
(the 7 log lines in the window are self-inflicted UAT-harness probes).
Legacy RME `AT_REST`; WhatsApp `DisabledWhatsAppGateway` (FIX-02 stays HOLD).

### Deploy note

The deploy ran detached via `scripts/deploy-vps-runner.sh start`. The `follow`
SSH pipe later timed out (exit 255) — the exact dropped-pipe case the POST-ENT
runner exists to survive. The deploy itself finished `exit=0` / `DEPLOY RUNNER
OK`, confirmed from `storage/logs/deploy-runner/latest.status`, never from the
pipe. A follow-pipe timeout is not a deploy failure; always read the status file.

