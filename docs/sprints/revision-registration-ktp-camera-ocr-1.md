# REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — KTP camera capture + OCR suggestions

Status: **IMPLEMENTED · TESTED (local) · NOT PRODUCTION READY · NOT DEPLOYED**
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `4df7399a`.
Flag: `patient.ktp_camera_ocr` (`FEATURE_PATIENT_KTP_CAMERA_OCR`), **default OFF**.

## What it does

During patient registration (Master Data → Pasien → Tambah, and RME → Kunjungan
Baru → Pasien Baru) an authorized operator can photograph a KTP with the device
camera (rear camera preferred, desktop webcam supported, camera switching,
ID-1 alignment guide), review the photo (Ulangi / Gunakan Foto Ini), and receive
OCR **suggestions** for the registration fields that exist on `mst_patients`:
`ktp_number`, `name`, `date_of_birth`, `gender`, `address`, `occupation`.

Nothing is written from OCR. The operator ticks which suggestions to apply,
edits freely, and must confirm "Saya sudah mencocokkan data hasil baca KTP
dengan KTP asli pasien" before the server accepts the registration. Every value
is then validated by the **existing** rules (KTP uniqueness, duplicate-patient
check, branch, RM number) exactly as manual entry.

## Repository audit (what already existed — reused, not rebuilt)

| Concern | Existing implementation (Sprint 61.1 / 61.1.1 / 61.3) |
|---|---|
| KTP image intake | `KtpScanService::storeTempFromBase64()` — base64, magic-byte check via `getimagesizefromstring`, mime allowlist, 6 MB cap, GD resize to 1600 px / q82 |
| Private storage | `local` disk (private), temp `tmp/patient-ktp-scans/{user}/{token}`, final `patient-documents/{patient}/` |
| Document record | `PatientDocument` (`mst_patient_documents`, type `ktp`, checksum, uploaded_by) |
| Serving | `PatientDocumentController::show()` — policy-gated stream, no public URL |
| Temp cleanup | `patient-documents:prune-temp` (dry-run default) |
| Duplicate patients | `PatientRegistrationDuplicateCheck` at submit (branch-scoped least disclosure) |
| Registration surfaces | `settings/patients/create` + RME visit "Pasien Baru" — both include `_ktp-scan` |
| Authorization | `manage patients` route group + `can('create', Patient::class)` |

`mst_patients` has **no** column for birth place, RT/RW, village, district,
religion or marital status. Per the brief ("populate only supported fields; do
not introduce unrelated fields") **no migration was added**: RT/RW, Kel/Desa and
Kecamatan are composed into the single existing `address` column; birth place,
religion and marital status are shown to the operator as "tidak disimpan" and
never persisted.

## Architecture decision

**OCR runs in the operator's browser; the server parses and validates.**

- Tesseract is installed neither on the developer machine nor on the VPS
  (measured: `tesseract: command not found` on both). Server-side OCR would
  need a production package install, would occupy a PHP-FPM worker
  (`pm.max_children = 5` on a 2-vCPU VPS) or a new queue path, and adds VPS
  load for every scan.
- Browser OCR with **self-hosted** tesseract.js 7 (WASM) needs no VPS package,
  never blocks a PHP worker, adds no queue, and the KTP image never leaves the
  browser for OCR. It is the same Tesseract LSTM engine.
- The browser sends back only `{text, confidence}` lines (≤ 60 × 200 chars).
  `KtpOcrParser` (pure PHP, no DB/IO/logging) is the authority that decides
  what the text supports; it is unit-testable and mutation-tested.

**OCR engine:** tesseract.js 7.0.0 + tesseract.js-core 7.0.0, LSTM-only,
Indonesian model `@tesseract.js-data/ind` `4.0.0_best_int` (1.2 MB gz). No
external OCR API.

**Self-hosting:** `vite.config.js` copies the worker, the three LSTM core
variants and the model into `public/build/ocr/tesseract-<versions>/`; the
browser downloads ONE core variant after SIMD detection. tesseract.js' CDN
defaults (jsdelivr) are always overridden; `ocrAssetConfig()` throws rather
than fall back. Verified in real headless Chrome with **all non-localhost DNS
blocked**: every request was same-origin.

## Parser contract (`App\Modules\Patient\Services\KtpOcrParser`)

- A field is read only from a line carrying its own KTP label.
- Missing → `missing`, value `null`. Invalid → `invalid`, value `null` (never offered).
- Below the confidence threshold (75), unknown confidence, or glyph-corrected → `low_confidence`: offered, never pre-ticked.
- A label read twice with different values → `ambiguous`, `null`.
- NIK: 16 digits, province 11–94, valid month, day 1–31 or 41–71 (female +40). Look-alike glyphs (O→0, I→1, S→5…) corrected only on the NIK line and always flagged.
- Birth date: strict `DD-MM-YYYY`, real calendar date, not in the future (ClinicalClock), not before 1900 / 130 years.
- **The NIK is never used to fill the birth date or gender** — only to cross-check; disagreement demotes both sides.
- Address block composed into `address`; never better than its weakest part.

## Registration integration

- `ktp_ocr_applied` (hidden, set by "Terapkan ke Formulir") + `ktp_ocr_verified`
  (checkbox) — `StorePatientRequest`: `exclude_unless:ktp_ocr_applied,1|accepted`;
  `StoreClinicVisitRequest`: required only in new-patient mode.
- Filled fields are never pre-selected for replacement ("mengganti isian").
- The scan token now survives a failed submit (`old('ktp_scan_token')`), so a
  validation error no longer orphans the captured image.

## Security

| Control | Implementation |
|---|---|
| AuthN/AuthZ | Parse route inside `auth` + `permission:manage patients`; request `authorize()` = `can('create', Patient)`; flag off → 404 |
| CSRF | Standard `web` group (X-CSRF-TOKEN) |
| Rate limit | Named limiter `ktp-scan`: 20/min per user **per route** (upload and parse separately) |
| Untrusted input | Payload bounded; OCR text written to the DOM only via `.value` / `.textContent` (asserted in JS tests) |
| Image | Magic bytes + mime allowlist (existing) + **header-checked decompression-bomb guard** (≤ 12000 px/side, ≤ 40 MP) before GD decodes |
| Temp isolation | Temp paths rebuilt from a sanitized token inside the caller's own folder; `replaces_token` must be a UUID; another user's token can be neither discarded nor attached |
| NIK exposure | Parse response `Cache-Control: no-store`; nothing logged or audited; no URL carries a NIK |
| Duplicate oracle | Parse endpoint does **no** patient lookup; duplicates are checked at submit by the existing branch-scoped workflow |
| Cross-branch | Parse endpoint reads no patient data at all; documents keep the existing policy-gated, private serving |
| Supply chain | All OCR assets same-origin; CDN fallback impossible by construction |

## Performance (measured, not projected)

| Measurement | Environment | Result |
|---|---|---|
| OCR per image, Node | dev laptop CPU, 4 synthetic images | 733–997 ms; init 200 ms; peak RSS 193 MB |
| Full camera flow, browser | headless Chrome 149, dev laptop | capture → suggestions ≈ 1.9 s (first run, incl. model load) |
| Upload size | camera capture 1028×648 JPEG | 75.8 KB |
| Server parse | PHP 8.5 local, 2000 parses of real OCR output | 0.13 ms avg, ~20 KB memory; no DB, no IO |
| VPS cost | — | no OCR on the VPS; static assets ~5 MB/first visit (core 3.9 MB + model 1.2 MB), then browser-cached |

**Not measured:** OCR time and memory on the actual clinic tablet; accuracy on
real KTPs (security background print, hologram, lamination glare, worn cards).

## Accuracy on synthetic (fictional) KTPs — honest result

Four synthetic cards (clean, blurred, 4° rotated, downscaled phone-like):
NIK, birth date, gender and occupation correct on all four. **But** the name was
read as `SITICONTOH RAHMAWATI` (space lost) and once the address as
`RAYANO. 12` — both reported as confident `ok` reads. A confident read is not a
correct read; this is why application is opt-in per field and the operator must
confirm against the physical card. Synthetic cards are far cleaner than real
KTPs, so real accuracy will be lower.

## Tests

- `tests/Feature/RME/PatientKtpCameraOcrTest.php` — 48 (parser, endpoint
  security, upload hardening, registration flows, page rendering).
- `tests/js/ktp-camera-ocr.test.mjs` — 11 (camera constraints, crop,
  compression sizing, same-origin asset guard, no-innerHTML guard, selection
  policy, error messages).
- Mutation: 13 mutants, 13 killed.
- Real-browser E2E (headless Chrome, fake camera, DNS-blocked): pass.

## Remaining risks

1. Real-KTP / real-tablet accuracy and latency unmeasured — requires a supervised device trial with real (non-committed) cards.
2. ~~The Laravel scheduler does not fire in production, so stale temp scans are cleaned only by running `patient-documents:prune-temp`.~~ **Closed at release:** `deploy/systemd/daengtisiams-ktp-temp-prune.{service,timer}` runs `patient-documents:prune-temp --force` daily at 19:45 UTC (03:45 WITA) as `daengtisiams` on PHP 8.3 (`/usr/bin/php` on this host is another tenant's 8.5). Retention 24h ≫ 60-minute token lifetime, so a live registration image is never pruned; final documents are out of the service's reach. Pinned by two tests in `PatientKtpCameraOcrTest`. The unit is installed on the host as a one-time step (the deploy script does not install systemd units).
3. Existing `ktp_number` rule is `max:16` only (no 16-digit format check for manual entry) — pre-existing, deliberately unchanged.
4. Low-end tablets may struggle with ~190 MB OCR memory; the worker is terminated after every scan.

## Activation (separate owner decision)

Deploy is inert (flag OFF). To enable: set `FEATURE_PATIENT_KTP_CAMERA_OCR=true`,
rebuild config cache as the runtime user, trial on the clinic tablet. Rollback:
set it false — the existing scanner-agent + manual flow is untouched.
