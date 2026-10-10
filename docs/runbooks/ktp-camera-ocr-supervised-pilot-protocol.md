# KTP Camera OCR — Supervised Pilot Protocol

Task: `PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT` · Rule 178 · Rule 177.

Since `REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1` (rule 179) the screen reads each field in its own
box as well as the whole card, and shows **Berbeda — pilih** when the two disagree. §4 records those
outcomes too.

Since `PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1` (rule 180) **D7 is approved** and the screen
asks for the KTP holder's consent before the camera opens and before OCR reads an image from any
source; the parse endpoint refuses OCR text sent without it. §3 is the consent procedure,
Appendix A the printable form, and §4 records consent and the screen's measurement line.

This protocol is for a **supervised, consented, single-branch** trial. Nothing
in it is performed automatically. Every result is recorded by a person who saw
it happen; a cell that was not measured is written `NOT TESTED`, never guessed.

---

## 0. Decisions the owner must record first (blocking)

None of these are inferred from earlier pilots (the doctor-device pilot at
Cabang Sunu does **not** approve this one).

Filled-in record for the first pilot (SPN4, 2026-10-10..16):
`docs/operations/ktp-camera-ocr-pilot-activation-spn4-2026-10-10.md`. D7 was recorded later the
same day — wording `config/patient_ktp_ocr_consent.php`, version `D7-2026-10-10`, approved by the
owner on 2026-10-10 (`docs/sprints/phase-3-patient-ktp-roi-ocr-clinical-pilot-1.md`).

| # | Decision | Value | Recorded by / date |
|---|---|---|---|
| D1 | Pilot branch (exactly one, never MAIN) | | |
| D2 | Operator user ids (≤ 5, Admin Klinik / Front Office / Perawat at D1) | | |
| D3 | Clinic tablet(s) — `mst_doctor_devices.id`, registered at D1, active + verified (≤ 3) | | |
| D4 | Pilot period `Y-m-d .. Y-m-d` (≤ 31 days, clinical calendar) | | |
| D5 | Device requirement: keep ON (needs the front-office trusted-device lock armed for D2) **or** waive (written reason) | | |
| D6 | Acceptance thresholds (§5) accepted as written or amended | | |
| D7 | Consent form wording approved for KTP holders | | |

## 1. Arm the pilot (VPS, supervised window)

Run on the VPS as the runtime user. Never on the workstation.

1. Pre-flight — the gate must read `INERT` and the app healthy:
   `runuser -u daengtisiams -- php artisan patient:ktp-ocr-pilot-status`
2. Add to the environment file (one key per decision; ownership `root:daengtisiams 640` must survive the edit):
   ```
   PATIENT_KTP_OCR_PILOT_USER_IDS=<D2>
   PATIENT_KTP_OCR_PILOT_BRANCH_CODES=<D1>
   PATIENT_KTP_OCR_PILOT_DEVICE_IDS=<D3>
   PATIENT_KTP_OCR_PILOT_STARTS_ON=<D4 start>
   PATIENT_KTP_OCR_PILOT_ENDS_ON=<D4 end>
   # only if D5 waived the device requirement:
   # PATIENT_KTP_OCR_PILOT_REQUIRE_BOUND_DEVICE=false
   ```
3. Rebuild config as the runtime user:
   `runuser -u daengtisiams -- php artisan config:clear && runuser -u daengtisiams -- php artisan config:cache`
4. Verify the STAGED scope with the flag still off — expect `INERT_CONFIGURED`, `ERRORS=[]`:
   `runuser -u daengtisiams -- php artisan patient:ktp-ocr-pilot-status --strict`
5. Only then add `FEATURE_PATIENT_KTP_CAMERA_OCR=true`, rebuild config (step 3), and require
   `KTP_OCR_PILOT_VERDICT=ARMED` (exit 0 under `--strict`). `ARMED_UNREACHABLE` means a device is
   required but the front-office device lock is off — fix that or record D5; do not proceed.
6. Per operator: `... patient:ktp-ocr-pilot-status --user=<id>` → `USER_DECISION=allowed`
   (or `device_not_bound` when a device is required — expected without a browser session).
7. Negative check: one non-pilot operator opens Pasien → Tambah and sees **no** "Foto KTP dengan
   Kamera" and the manual upload still works.

## 2. Tablet readiness (per device, record in §6)

Model · Android version · browser + version · HTTPS lock shown · camera permission prompt ·
rear camera selected by default · camera switch works · focus · portrait · landscape ·
preview → Ulangi → Gunakan Foto Ini · OCR completes · browser responsive after 10 scans ·
camera light turns off after capture · recovery after denying permission once.

## 3. Captures

### 3.1 Consent, for each KTP holder (D7)

1. Explain in plain words: the clinic would like to photograph the KTP so the system can suggest
   the registration data; a staff member checks every value against the card before saving; it is
   optional, and saying no changes nothing about the visit.
2. Give the holder the printed form (Appendix A) — the D7 wording, unchanged — and let them read it
   (or read it aloud). The holder signs it. **No signature → no camera, no OCR.**
3. On the screen press **Foto KTP dengan Kamera**: the same wording appears. Press
   **Pemilik KTP Setuju** only after the form is signed. The camera then opens.
4. If the holder says no: press **Tidak Setuju — Isi Manual** and register by typing (the
   scanner/manual document upload is unchanged). Record one `declined` row in §4. Do not ask again
   unless the holder raises it.
5. The answer belongs to that page and that holder. **Hapus Preview** clears it (it may be a
   different person); **Ulangi** (retake of the same card) keeps it. A new registration page starts
   with no answer.
6. Only adult KTP holders (the card is issued at 17+); never on a minor's behalf.
7. If a holder later withdraws consent, tell the owner the same day — handling a withdrawal is part
   of the clinic privacy policy (sprint doc, privacy review).
8. Keep signed forms with the clinic's paper records. Never photograph, scan or upload a signed form
   into the application, a chat or an AI service.

### 3.2 Capture

- Consent first (§3.1), from an adult KTP holder, for each capture. No consent → no capture.
- The operator supervises every capture and checks every suggestion against the physical card.
- Target ≥ 30 consented captures (a proposed threshold, not a contract), spread over:
  normal light · low light · glare · slight tilt · near / far · worn card · long name ·
  multi-line address · uncommon occupation.
- Never photograph a KTP outside the registration screen; never forward it by chat or email.

## 4. What to record per capture (no identifiers)

One row per capture. **Never** write the NIK, name, address or birth date — only outcomes.

```
capture_no,date,device_label,condition,consent,ocr_seconds,camera_ok,ocr_completed,nik,name,birth_date,gender,address,occupation,registration_completed,card_found,corners_adjusted,boxes_moved,retries,retries_by_field,notes
```

(One line. `condition` is one of `normal_light`, `low_light`, `glare`, `slight_tilt`, `near`, `far`,
`worn_card`, `long_name`, `multi_line_address`, `uncommon_occupation`, `other`.)

`consent` is `accepted` (form signed **and** "Pemilik KTP Setuju" pressed) or `declined`. A declined
row records only `capture_no`, `date`, `device_label`, `condition` and `consent`; every other cell is
`not_applicable`. When the camera failed or no OCR result appeared, the field and measurement cells
are `not_applicable` too.

**Copy the measurement line, do not count from memory.** Under the card the screen shows
`Catatan pilot (tanpa data pribadi): card_found=…; corners_adjusted=…; boxes_moved=…; retries=…;
retries_by_field=…; ocr_seconds=…`. Copy each value into the column of the same name.
`retries_by_field` (e.g. `nik:2,name:1`, or `none`) contains commas: put it in ONE spreadsheet cell
(the CSV export quotes it). `ocr_seconds` is the first read of that photo.

Field cells take exactly one of:
`correct` (suggestion matched the card, applied unchanged) ·
`corrected` (applied, then the operator fixed it) ·
`missing` (no suggestion) ·
`wrong_high_conf` (offered as confident and wrong — **always note this**) ·
`not_applied` (offered, operator chose not to use it) ·
`choice_correct` (shown as *Berbeda — pilih*, one of the two values was right and the operator picked it) ·
`choice_neither` (shown as *Berbeda — pilih*, neither value was right; operator typed it).

Per capture also record, without identifiers: `card_found` (`yes`/`no` — whether the screen found
the card edges itself), `corners_adjusted` (`yes`/`no`), `boxes_moved` (number of field boxes the
operator moved or resized), `retries` (number of *Baca Ulang* presses).

`notes` must not contain personal data.

## 5. Proposed acceptance criteria (owner reviews before clinical activation)

1. No CRITICAL/HIGH security finding open.
2. No cross-branch access; no KTP file served outside its policy.
3. No patient record corrupted, no duplicate patient caused by the OCR flow.
4. No silent overwrite of an operator-entered value.
5. Every OCR-assisted registration carries the operator confirmation.
6. No incorrect identity value saved without operator verification.
7. OCR failure never blocked manual registration.
8. Field-level accuracy, correction rate and tablet timing measured (§4).
9. Rollback demonstrated once (§7).
10. **Any confirmed wrong NIK saved, or any `wrong_high_conf` on NIK that the operator did not catch → NO-GO.**

A confidence score is not proof of correctness.

## 6. Reporting

Summarise the sheet with `node tools/ktp-ocr-pilot/summarize.mjs <sheet.csv> [summary.json]` on the
clinic computer. It refuses a sheet holding anything identifier-shaped (by row and column, without
echoing the value), computes the §4 metrics, and compares them with the targets in the activation
record: `PENDING` below 30 consented captures, `NO_GO` on any confident wrong NIK or a missed target,
`METRICS_PASS` at most. It cannot measure the security, data-integrity and rollback items of §5 —
those stay owner-verified.

Aggregate only: counts and rates per field, median and P95 OCR seconds, camera and browser
failures, registration completion. Each figure labelled `MEASURED`, `ESTIMATED` or `NOT TESTED`.
Charts use counts/rates only. No KTP image, no unmasked NIK, no name in any report, chart,
issue, PR or chat.

## 7. Rollback (any time, ≤ 5 minutes)

Set `FEATURE_PATIENT_KTP_CAMERA_OCR=false` (or remove the pilot keys), rebuild config as the
runtime user, confirm `patient:ktp-ocr-pilot-status` reads `INERT`/`INERT_CONFIGURED`. The
scanner-agent + manual KTP flow is untouched; OCR created no data, so nothing is migrated back.
Captured KTP images already attached to patients stay in the existing private document store.

---

## Appendix A — Formulir persetujuan (print as is)

The text between the lines is the owner-approved D7 wording (`D7-2026-10-10`). Print it unchanged;
only the name, date and signature lines below it are form fields.

> **PERSETUJUAN PEMINDAIAN KTP**
>
> Saya memberikan persetujuan kepada Klinik Gigi Daengtisia untuk mengambil foto KTP dan memproses
> informasi identitas saya menggunakan sistem DaengtisiaMS.
>
> Pemrosesan dilakukan untuk membantu pengisian dan verifikasi data pendaftaran pasien.
>
> Saya memahami bahwa hasil pembacaan otomatis akan diperiksa kembali oleh petugas klinik sebelum
> disimpan.
>
> Foto KTP dan informasi identitas saya akan dikelola sesuai kebijakan privasi dan perlindungan data
> pribadi yang berlaku di klinik.
>
> Persetujuan ini diberikan secara sukarela setelah saya menerima penjelasan mengenai tujuan
> penggunaan data.

```
Nama pemilik KTP : ________________________________
Tanggal          : ____ / ____ / ________
Tanda tangan     : ________________________________

Petugas          : ________________________________   (versi teks: D7-2026-10-10)
```

Do not add the NIK, address or birth date to the form. Keep the clinic privacy policy (sprint doc,
privacy review) available to the holder at the desk.

