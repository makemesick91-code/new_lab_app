# REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1

**Type:** MODULE_SPRINT (RME) · **Base:** `9e6142ca` (docs on top of production
`042078f7` / `governance-patient-ktp-new-patient-only-1-go`) · **Rule:** 185
(new), 100 §10 and 122 (updated in place).

Three owner-authorised revisions shipped as one governed release. No migration,
no permission, no route, no schema change.

---

## A. Visit detail: Odontogram → Rekam Medis → Resep

**Audit.** The three clinical cards are sibling `<x-ui.card>` blocks inside the
`@if ($canOperateVisit)` region of `resources/views/rme/visits/show.blade.php`,
each behind its own `@can` guard and room gate. Order was controlled purely by
source order (no tabs, accordion, JS or CSS ordering).

**Change.** The Odontogram block was moved above Rekam Medis **in the source**
(its `$medicalRecord` binding moved with the Rekam Medis block). DOM order,
keyboard focus order and screen-reader reading order therefore all follow the
screen. Every card keeps its guard, its room gate and its route; Front Office
still sees none of the three (FIX-07 unchanged).

**Evidence.** `VisitDetailClinicalSectionOrderTest` — 8 tests / 46 assertions:
order asserted on the exact card headings (each must appear exactly once) for a
linked, online **Doctor**, **Super Admin**, **Supervisor RME** and a
permission-only manager; room-gated state keeps the same order; front office
sees no clinical card; a source scan forbids CSS `order-*` / `order:`.

---

## B. The RME payment invoice

### Reference analysis

`INVOICE .pdf` (owner-supplied, CamScanner scan) is a portrait page carrying a
**rotated landscape** form. Normalised, its composition is: Daengtisia mascot +
wordmark top-left with the branch name in pink and address/telephone below;
`NOMOR` (pre-printed serial) and `TANGGAL PEMBAYARAN` top-centre; a turquoise
`INVOICE` block top-right; "DITUJUKAN KEPADA" (No. RM, Nama, No. HP, Dokter
Pemeriksa) on the left beside a yellow rounded table header (Jenis Perawatan,
Qty, Diskon, Biaya); JENIS PEMBAYARAN (DEBIT/TUNAI) and a Jumlah / Diskon /
Total Biaya / Pembayaran / Sisa Pembayaran block; Ketentuan Refund bottom-left;
the thank-you line and bank transfer details bottom-centre; an Admin signature
bottom-right. Scan artefacts (watermark, distortion, shadow) were not
reproduced, and the scan is not used as a background.

### What was built

| Piece | Purpose |
|---|---|
| `resources/views/rme/cashier/receipt/partials/invoice-document.blade.php` | The document. **One template for both renderers** (browser print and dompdf), table-based, scoped under `.dmsinv`. |
| `receipt/pdf.blade.php` | Now only the A4-landscape page box around the partial. |
| `receipt/show.blade.php` | Screen preview of the same partial + the existing lab workflow panel; print isolation kept, page box landscape. |
| `app/Modules/RmeInvoice/Support/RmeInvoiceDocument.php` | Every amount, read from stored canonical fields in exact integer cents, self-reconciling. |
| `config/rme_invoice.php` | Owner-approved refund terms + bank account, source-controlled (no `env()`). |
| `public/assets/brand/daengtisia-logo-wordmark.jpg` | A tight crop of the official logo (mascot + DAENGTISIA + KLINIK GIGI, no tagline), white-flattened for dompdf. Brand turquoise `#38B8B8` / yellow `#F8D828` were sampled from the same artwork. |
| `x-brand.daengtisia-logo` | Backward-compatible `variant="wordmark"` prop. |

### Data mapping (no field assumed, no number invented)

| Printed | Source |
|---|---|
| NOMOR | `invoice.invoice_number` (the sample's `07997` never appears) |
| TANGGAL PEMBAYARAN | settling payment's `paid_at` (payments ordered `paid_at desc`) |
| No. Kwitansi / No. Kunjungan | `payment.payment_number` / `visit.visit_number` |
| Branch name / address / Telp/WA | the **invoice's** branch (transaction), never the cashier's context; missing values print nothing |
| No. RM / Nama / No. HP / Dokter Pemeriksa | patient RM, name, `phone` → `whatsapp_number` fallback, visit doctor |
| Jenis Perawatan / Qty / Diskon / Biaya | item description / qty / `item.discount` / `qty × unit_price` (gross line) |
| Jumlah / Diskon / Total Biaya | `invoice.subtotal` / `invoice.discount_total` / `invoice.grand_total` |
| Pembayaran / Sisa Pembayaran | `RmeInvoice::paidAmount()` / `remainingAmount()` |
| Jenis Pembayaran | distinct `paymentMethod.name` across the invoice's payments ("Tidak tercatat" when none was recorded — the old template invented "Tunai") |
| Admin | settling payment's cashier, else the invoice cashier |

Biaya is the **gross** line so the table reconciles visibly with the summary:
Σ Biaya = Jumlah, Σ Diskon = Diskon, Jumlah − Diskon = Total Biaya. This
matches exactly how `RmeInvoiceService::create()` accumulates the stored totals.

### Financial integrity

* No amount is computed in Blade or from rendered text. `RmeInvoiceDocument`
  parses `decimal(15,2)` strings digit by digit into integer cents.
* It reconciles lines vs stored totals and paid + remaining vs total. A mismatch
  prints a visible warning and the stamp is never LUNAS; nothing is written back.
* A partial invoice prints the remaining balance in red and the
  **DIBAYAR SEBAGIAN** stamp. The receipt routes remain `PAID`-only (unchanged).
* **Finding recorded, not changed:** `RmeInvoice::recalculateTotals()` sums the
  items' *net* subtotal and then subtracts the discounts again — it would
  double-count a discount. It has **no caller** in `app/`, `tests/` or
  `database/`, so it affects nothing today; it was deliberately left alone
  (billing calculations are out of scope). The canonical write path
  (`RmeInvoiceService::create()`) is consistent and is what the document reads.

### Refund terms and bank details

No source in the repository recorded either. Asked rather than copied from a
scan: the owner **approved both verbatim, for all branches** (2026-10-12). They
live in `config/rme_invoice.php` with no environment read, so changing an
account number or a refund rule is a reviewed commit. An empty block prints
nothing. This config describes what is printed only — no refund logic changed.

### Print format and layout decisions

* **A4 landscape** — the same paper, rotated, so no cashier hardware assumption
  changes.
* The treatment table is a **top-level** block beside an out-of-flow addressee
  block. Two failed designs are recorded so they are not retried:
  1. *Nested table in a two-cell row* — dompdf cannot split such a row: a
     40-item invoice left page 1 almost empty and a near-blank 4th page.
  2. *Floated addressee* — a dompdf float "intrudes" into the next page's rows
     at the same vertical offset, squeezing their first line.
* Long invoices continue with the table header repeated, never truncated.

### Visual verification (synthetic data only)

Rendered with the real controller data path through dompdf and, separately,
through headless Chrome (the cashier's "Cetak Kwitansi" path):

| Fixture | dompdf pages | Chrome pages |
|---|---|---|
| A single treatment, paid | 1 | 1 |
| B five treatments incl. qty 2 | 1 | 1 |
| C partial payment (Sisa Rp 2.600.000, DIBAYAR SEBAGIAN) | 1 | 1 |
| D long descriptions + long branch name/address | 1 | 1 |
| E item discounts (Rp 1.150.000 − Rp 115.000 = Rp 1.035.000) | 1 | 1 |
| F other branch without address/phone | 1 | 1 |
| G historical payment date | 1 | 1 |
| H ten treatments | 1 | 1 |
| L long patient name (wraps) | 1 | 1 |
| M forty treatments (continuation, header repeated) | 3 | 2 |

Page box verified as A4 landscape (841.89 × 595.28 pt).

### Test helper changes (shared, `tests/Pest.php`)

The landscape form puts the addressee block **beside** the treatment table, so in
`pdftotext -layout` the two columns' rows interleave, and pages are laid out on
their own text grid. Two shared readers were tightened — never loosened:

* `pdfLayoutFieldValue()` continues a value only with a segment that starts in
  the value's own column, skips lines that belong only to another column, and
  still closes on a blank line or when the label column fills.
* `pdfLayoutColumnText()` measures its band per page from that page's repeated
  heading (identical to before on a single page).

`RmeReceiptOnePageTest` was adapted to the new labels (Nama, No. RM, Jenis
Perawatan/Qty, Total Biaya, Pembayaran, Sisa Pembayaran); its wrap-coverage
fixtures were lengthened because the landscape name cell is wider — exactly
the action its own coverage pin prescribes.

**Evidence.** `RmeInvoiceRedesignTest` — 30 tests / 170 assertions (branding,
no sample data, transaction branch, missing identity prints nothing, patient
details, XSS escaping, canonical amounts, cashier-screen parity, partial
payment, PAID-only rule, multi-payment sum and settling date, tamper
reconciliation, exact cents, 1/2/5/10 items one landscape page, full PDF text
content, no clipping, approved terms, empty config prints nothing, config reads
no env, unauthorised 403, guest redirect, other-branch Kasir refused, unpaired
visit/invoice refused on all five cashier routes, clinic-calendar dates).

---

## C. "Jenis Kunjungan" hidden, server-derived

### Audit

Four types exist (`new`, `control`, `continued_treatment`, `emergency`).
`control` + a parent visit drives **control-chain billing**
(`RmeControlReceivableService` carry-over). Production (post-reset estate):
7 visits, 6 `new`, **1 `control`**, 0 others — all registered by **Front
Office**, which is front-office-only and therefore cannot see the "Buat Kontrol"
launcher. Hiding the dropdown and defaulting to Baru would have silently removed
Front Office's only way to register a Kontrol visit and changed cashier
settlement. This was surfaced as a product decision.

### Owner decision (2026-10-12) — implemented

* Kontrol **only** when a prior visit is explicitly selected and passes the
  existing control-chain validation; otherwise Baru.
* Any client `visit_type` is ignored (`StoreClinicVisitRequest` overwrites it).
* An explicitly selected but invalid / cross-patient / out-of-scope / deleted
  prior visit is **rejected**, never downgraded.
* Patient history alone never makes a visit Kontrol.
* "Buat Kontrol" authorization unchanged; Front Office not widened.
* `continued_treatment` / `emergency` stay in the model, database and history;
  they are no longer creatable from this form.

### UI

The select is gone. The existing prior-visit picker is shown for registered
patients as **"Kunjungan Sebelumnya (Kontrol) (opsional)"** with an explicit
"Bukan kunjungan kontrol (Kunjungan Baru)" default; it is cleared when the
operator switches to "Pasien Baru". A refused derived type is rendered in an
always-visible place.

### Evidence

`VisitTypeServerDerivationTest` — 24 tests / 94 assertions: no visit_type input
renders; picker present; Buat Kontrol prefill; Baru without prior visit; no
history inference; Kontrol with valid prior visit; cross-branch same-patient
prior visit; rejection of non-existent, non-numeric, other-patient, non-RME and
soft-deleted prior visits (no visit created); tampering (`control` without
prior, `emergency`, `continued_treatment`, garbage, `new` with prior); new
patient (no KTP); new patient + prior visit refused; legacy-imported patient
(no KTP); queue numbering and status; **control-chain carry-over entered through
the real store endpoint**; no carry-over without a selection; historical types
untouched. `ClinicVisitControlWorkflowTest` — three superseded assertions
rewritten with the reason recorded; `RmeCreateSmokeTest` (browser) updated.

---

## Security review (pre-merge)

An independent read-only review of the diff against `9e6142ca` found no
CRITICAL or HIGH issue. Its findings and what was done:

| Severity | Finding | Action |
|---|---|---|
| MEDIUM | The five cashier routes bind `{clinicVisit}` and `{rmeInvoice}` independently; the policy authorizes the invoice only and nothing checked the pairing. Pre-existing, but this redesign widened it: the document prints the URL visit's patient name, RM and **phone**, so a Kasir holding any paid invoice could pair it with arbitrary visit ids. | **Fixed.** `RmeInvoice::belongsToVisit()` + a 404 guard after the policy check on `rme.cashier.show`, `payment.create`, `payment.store`, `receipt.show`, `receipt.pdf`. Test: all five refuse an unpaired URL, leak no identity, and a mismatched POST writes no payment; the correctly paired routes still serve. |
| LOW | Payment date and print time were formatted from the UTC application clock — a payment taken 00:00–07:59 WITA printed the previous day. | **Fixed.** Dates resolved through `ClinicalClock` in the controller; the print time is labelled WITA. Test pins 17:30 UTC → `12/10/2026`, `01:30 WITA`. |
| LOW | The patient's phone (WhatsApp fallback) is new on the printed document. | **Owner-specified.** "No. HP" is a field of the supplied INVOICE form (DITUJUKAN KEPADA). KTP/NIK is not printed. |
| INFO | `RmeInvoiceDocument` edge cases (unparseable value falls to 0, `rupiah(-30)` prints "- Rp 0"). | Fail-safe: any such value breaks reconciliation, prints the warning and suppresses LUNAS. |

Verified safe by the review: `visit_type` cannot be forced through any path; no
raw output or CSS injection in the changed views; the logo component reads only
fixed paths and dompdf runs with remote fetching and PHP disabled; authorization
and PAID-only still run first. It also noted the derivation closes an older gap
(`visit_type=new` plus an arbitrary `follow_up_of_visit_id` used to skip the
parent validation while the id was still stored).

### Mutation check

| Mutant | Result |
|---|---|
| `belongsToVisit()` always true | killed |
| payment date printed from raw `paid_at` | killed |
| derivation reverted to "trust client, default Baru" | killed |
| Odontogram / Rekam Medis order swapped | killed (6 failures) |
| `reconciles()` always true | killed |

Every mutant was applied, confirmed to change the file, and reverted by copy.

### CI selection (measured, not assumed)

Measured with `pest --list-tests` against the critical gate's exact filter, the
new suites were **not selected**: `RmeInvoiceRedesignTest` 0/30,
`VisitTypeServerDerivationTest` 0/24, `VisitDetailClinicalSectionOrderTest` 1/8
(incidental). The financial-integrity, pairing-guard and visit-type tamper tests
would therefore have run in no required gate. Added the tokens
`RmeInvoiceRedesign|VisitTypeServerDerivation|VisitDetailClinicalSectionOrder`
to **both** critical-gate variants (still byte-identical) — now 30/30, 24/24,
8/8 — and declared the first two in `config/ci_runner.php`
`critical_gate_mandatory_suites`, so dropping a token fails
`CriticalGateSuiteCoverageTest` instead of going silent.

---

## Honest limits

* Production verification of the invoice uses synthetic fixtures and read-only
  checks; no real financial transaction is created for a smoke test.
* The invoice is landscape; the clinic's physical printers were not inspected
  from here. Paper is unchanged (A4), only orientation.
* `RmeInvoice::recalculateTotals()` double-counting is recorded above, not fixed.
* `ClinicVisitController::patientVisitOptions` (feeds the prior-visit picker)
  still lacks a branch check (known residual from an earlier sprint); the
  server-side derivation + validation, not the picker, is the authority.

---

## Release evidence (2026-10-12)

| Item | Evidence |
|---|---|
| PR | #479, squash-merged as `a8685125720c7a97889ced568b663b7aa8363b91` |
| Tree identity | merge tree `e0250a4b` == CI-validated candidate `ad1b5a3c` tree |
| Required CI | run `38156671515` on `ad1b5a3c`: Classifier, NSF-R012 Quality, NSF-R011 Critical, Selective Module, NSF-9, NSF-10, Android gate — all success |
| Critical gate content | `5567 passed (28589 assertions)`, `critical_test_exit_status=0`, warning contract satisfied; `RmeInvoiceRedesignTest`, `VisitTypeServerDerivationTest`, `VisitDetailClinicalSectionOrderTest`, `DoctorMultiDeviceAccessTest` all PASS |
| Full Suite | **SKIPPED** — deferred by the active temporary policy (post-merge run `38166058854`: `full_suite_authorized=false`, `TEMPORARY_FULL_SUITE_POLICY_ACTIVE`). Not a pass. |
| Local regression | RME 1836, Ui 176, PatientMerge 72 (+2 skipped), Patient 74, AccessControl 128 (+9 skipped), Cicd 272, Pilot 74, DoctorAccess 477 — 0 failed |
| Deploy | `scripts/deploy-vps-runner.sh start` on the VPS: `exit=0`, `DEPLOY OK: 20261011-185922`, `DEPLOY_HEAD_TARGET_MATCH=YES`, backup `pre_auto_deploy_20261011-185922.sql` first, `Nothing to migrate.`, asset build ran, runtime isolation 70 GO / 0 FAIL |
| Production health | `https://daengtisia.online` `/login`, `/health/live`, `/health/ready`, `/health/lb` 200; all ready components ok; guest `/rme/visits/create`, `/rme/visits`, `/rme/cashier`, `/dashboard` 302 |
| Deployed code | card order Odontogram (365) → Rekam Medis (381) → Resep (410); 0 `visit_type` inputs; prior-visit picker present; 5 pairing guards; invoice config cached |
| Real invoices | read-only render of all 5 PAID invoices through the deployed controller path inside a rolled-back transaction: each 1 page, A4 landscape 842×595, reconciles, settled, correctly paired; 0 write statements |
| Data | unchanged vs pre-deploy baseline (visits 6 new + 1 control; invoices 5 PAID + 1 UNPAID); no record created or modified |
| Logs / jobs | `laravel.log` 1,437,201 B / 162 ERROR before and after (0 new); failed jobs 0, pending 0 |
| Posture | env pilot, debug OFF, maintenance OFF; php8.3-fpm, nginx, queue worker active |
| GO tag | `revision-rme-visit-detail-invoice-registration-ux-1-go` (annotated) → `a8685125`, exact match at VPS HEAD |
| Graph | graphify rebuilt on the merged tree: 37,798 nodes / 54,843 edges |

**Local-only false alarm, recorded:** `DoctorMultiDeviceAccessTest` failed 8/8
locally because the worktree's copied `vendor/` lacked
`paragonie/constant_time_encoding` (required by the lock). It failed identically
on the untouched base commit; `composer install` made the directory 477/477
green and it passes in CI. Not a code defect.
