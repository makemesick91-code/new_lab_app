# PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — D7 consent, privacy review, pilot readiness

Rule 180 (extends 177 / 178 / 179) · Protocol `docs/runbooks/ktp-camera-ocr-supervised-pilot-protocol.md` ·
Activation record `docs/operations/ktp-camera-ocr-pilot-activation-spn4-2026-10-10.md`.
All times WITA (UTC+8) unless marked UTC.

```
CONSENT_D7           = APPROVED (owner, 2026-10-10; version D7-2026-10-10)
PRIVACY_REVIEW       = PENDING  (clinic privacy policy referenced by D7 not available to verify)
PILOT_ACCESS         = VERIFIED (server-side; read-only on production)
REAL_CAMERA_TEST     = PENDING  (needs the operator at the SPN4 desk)
REAL_KTP_TEST        = PENDING  (needs consenting KTP holders, ≥ 30 captures)
OCR_ACCURACY_GO      = PENDING
CLINICAL_PILOT_GO    = NO
WIDER_ROLLOUT_GO     = NO
```

The pilot GO tag `phase-1-patient-ktp-camera-ocr-supervised-pilot-go` stays **reserved** for a passed
physical pilot. Nothing in this sprint was measured on a real KTP or a real clinic camera.

## 1. Owner decision D7

The owner approved this patient-facing wording verbatim:

> **PERSETUJUAN PEMINDAIAN KTP** — Saya memberikan persetujuan kepada Klinik Gigi Daengtisia untuk
> mengambil foto KTP dan memproses informasi identitas saya menggunakan sistem DaengtisiaMS. ·
> Pemrosesan dilakukan untuk membantu pengisian dan verifikasi data pendaftaran pasien. · Saya
> memahami bahwa hasil pembacaan otomatis akan diperiksa kembali oleh petugas klinik sebelum
> disimpan. · Foto KTP dan informasi identitas saya akan dikelola sesuai kebijakan privasi dan
> perlindungan data pribadi yang berlaku di klinik. · Persetujuan ini diberikan secara sukarela
> setelah saya menerima penjelasan mengenai tujuan penggunaan data.

It is held once, in `config/patient_ktp_ocr_consent.php` (version `D7-2026-10-10`, no `env()`), and
reaches the screen and the printed form (protocol Appendix A) unchanged.

## 2. Production state verified first (read-only, 2026-10-10 15:48 WITA)

| Check | Result |
|---|---|
| Production HEAD | `e1dc3a7d956d3e84482eca816665681239f180bf`, tree `fb1f55ac`, exact match on `revision-patient-ktp-ocr-field-based-roi-1-go` |
| Working tree | clean except the pre-existing untracked `storage/runtime-home/` (§7) |
| Pilot posture | `ARMED`, `errors=[]`, operators `[29]`, branch `SPN4` (id 5), `2026-10-10 .. 2026-10-16`, device requirement waived, front-office device lock off |
| Pilot dates | valid: today 2026-10-10 is day 1 of 7; end date **not** extended |
| User 29 now | `no_working_branch` — Admin Sunu had no live online context at 15:48; eligibility returns once Cabang Sunu is selected (at activation, with a context, it read `allowed`) |
| Other accounts | 7, 8, 16, 17, 30, 31, 32 (Front Office), 1 (Super Admin), 11 (Supervisor RME) → `operator_not_in_pilot` |
| Health (`https://daengtisia.online`) | `/login`, `/health/live`, `/health/ready`, `/health/lb` → 200 |
| Services / locks | php8.3-fpm, nginx, postgresql, queue worker active; deploy + rollback flocks free |
| Application log | 1,437,201 bytes / 162 `ERROR` — byte-identical to the activation baseline |

`PILOT_ACCESS = VERIFIED` means the server-side scope is exactly the approved one and denies every
other account. An authenticated session as Admin Sunu was not used (no credential was used or
created); the operator's first login confirms the camera button on screen.

## 3. Privacy review (engineering cross-check, not legal advice)

Statute text: UU No. 27 Tahun 2022 (PDP), as published at pasal.id (verified copy of
peraturan.go.id). D7 is consent-based processing (Pasal 20(2)a), so Pasal 21(1) lists what the
controller must tell the holder.

| Requirement | Where D7 / the flow meets it | Status |
|---|---|---|
| Pasal 20(2)a explicit consent for a stated purpose | ¶1 consent + ¶2 purpose | met |
| Pasal 21(1)a legality of processing | not stated; delegated to the clinic policy | **not verified** |
| Pasal 21(1)b purpose | ¶2 (help fill and verify registration data) | met |
| Pasal 21(1)c type and relevance of data | "foto KTP", "informasi identitas"; the screen shows the six fields | partly |
| Pasal 21(1)d retention period of documents | ¶4 delegates to the clinic policy | **not verified** |
| Pasal 21(1)e details of information collected | as (c) | partly |
| Pasal 21(1)f processing period | not stated | **not verified** |
| Pasal 21(1)g data-subject rights (Pasal 5–9: information, correction, access, ending/deleting, withdrawal) | ¶4 delegates to the clinic policy | **not verified** |
| Pasal 5 identity / accountability of the requester | clinic named; no privacy contact | partly — contact missing |
| Pasal 22(1)–(3) written or recorded consent | signed paper form (Appendix A) + on-screen operator attestation | met (procedure) |
| Clear, separate, plain-language request | separate one-page form, plain Indonesian | met |
| Voluntary; declining costs nothing | ¶5 voluntary; "Tidak Setuju — Isi Manual" keeps manual registration | met |
| Human review before saving | ¶3; enforced by rule 177 (`ktp_ocr_verified`) | met |
| Pasal 9 + 40 withdrawal, processing stopped ≤ 3×24 h | no clinic procedure exists | **open** |

**Why PENDING, not FAIL:** the wording is consistent and the flow enforces what it promises. But D7
deliberately points to "kebijakan privasi dan perlindungan data pribadi yang berlaku di klinik",
and **no such policy exists in the system or this repository**; a paper policy at the clinic could
not be inspected. Until it is available to the KTP holder, the retention, rights and contact
information Pasal 21(1) requires is not shown to have been given.

**Needed from the owner (not written here on the clinic's behalf):**

1. The clinic privacy notice (or confirmation it exists) stating: how long the KTP photo and identity
   data are kept and why; the processing period; the holder's rights (information, correction,
   access/copy, ending/deleting, withdrawing consent); and a privacy contact (name/role and channel).
2. That notice available at the Cabang Sunu front desk during the pilot.
3. A withdrawal procedure (who acts, within 3×24 h).
4. Facts for the notice: KTP photos attached to a patient are kept in the existing private document
   store (since Sprint 61.1); unattached temp images are pruned by `daengtisiams-ktp-temp-prune.timer`;
   OCR text is never stored (rules 177/179).

Out of D7's scope, flagged for the owner: the scanner/manual KTP document capture predates this
pilot and is used by every operator; its legal basis is a clinic-wide question, not an OCR one.

## 4. What changed

**Consent before capture and processing (rule 180).**

| Layer | Change |
|---|---|
| Wording | `config/patient_ktp_ocr_consent.php` (verbatim, versioned, no `env()`); single reader `App\Modules\Patient\Support\KtpOcrConsent` — fails closed on a missing version/title or any broken paragraph (no partial text) |
| Server | `ParseKtpOcrRequest` requires `consent` accepted + `consent_version` ∈ current version (422 otherwise). The pilot gate's 404 still runs first, so consent is never an oracle for non-pilot operators |
| Screen | `_ktp-scan.blade.php`: consent panel (title + five paragraphs + version, "Pemilik KTP Setuju" / "Tidak Setuju — Isi Manual"); no "agree" button when the wording is unusable |
| Client | `ktp-camera-ocr.js`: the camera opens only after a yes; OCR on any source (camera, scanner agent, manual file) waits for an answer; a no stores the photo as a plain document with no OCR request; Hapus Preview resets the answer, Ulangi keeps it. `ktp-roi-ui.js` sends the attestation with every parse request and shows the server's consent message |
| Status | `patient:ktp-ocr-pilot-status` reports `consent: {version, usable, paragraphs}` (never the text); `--strict` exits 2 if the flag is on and the wording is unusable |

**Measurement aid.** The ROI screen shows one identifier-free line per photo —
`card_found`, `corners_adjusted`, `boxes_moved`, `retries`, `retries_by_field`, `ocr_seconds` (first
read) — in the protocol §4 column vocabulary. Counts and field keys only; shown on screen, never sent.

**Summariser.** `tools/ktp-ocr-pilot/summarize.mjs` (+ `pilot-metrics.mjs`): validates the §4 sheet,
refuses anything identifier-shaped by row/column without echoing it, computes the step-16 metrics
(per-field exact/corrected/missing/confident-wrong, failed captures, median/P95 seconds, card found,
corner corrections, box moves, per-field retries) and compares them with the owner targets.

**Unchanged:** pilot gate, scope keys, cohort, branch, period, device waiver, flag, private storage,
NIK masking, CSRF, rate limit, operator confirmation, duplicate check, manual registration. No
migration, no permission, no route, no patient column, no new consent table — `trx_rme_visit_consents`
(treatment consent) is deliberately not reused.

## 5. Verification

| Evidence | Result | Kind |
|---|---|---|
| `tests/Feature/RME/PatientKtpOcrConsentTest.php` | 24 passed / 109 assertions | automated |
| KTP suites incl. the new one (`PatientKtpCameraOcr*`, `PatientKtpOcrFieldRoi`, `PatientKtpScan`, `RmeVisitNewPatientKtpScan`, `PatientKtpOcrConsent`) | 180 passed / 682 assertions | automated |
| Existing parse tests | now send consent; the two payload-bounds refusals are pinned to non-consent errors (otherwise they would pass vacuously) | automated |
| Node: `tests/js/ktp-ocr-consent.test.mjs` (14), `tests/js/ktp-ocr-pilot-metrics.test.mjs` (13); whole `npm run test:js` | 158 / 158 | automated |
| Mutation check (11 mutants: consent rules removed, version check removed, partial wording, agree button without wording, strict ignoring wording, `consentAllowsOcr` ignoring the answer, runOcr gate removed, parse body without attestation, first read overwritten, consent kept after clear, a new photo not cancelling an unanswered question) | 11 killed / 0 survived; sources restored byte-identical (one mutant first failed to apply and was re-targeted, never counted) | automated |
| CI selection | the critical filter selects all 24 tests of the new suite; declared in `ci_runner.critical_gate_mandatory_suites` | automated |
| Pint (whole repo), `git diff --check`, `npm run build` | pass | automated |
| `tools/ktp-ocr-benchmark/browser_e2e.php`, headless Chrome 149, fictional card, scratch SQLite app, final bundle | 25 / 25 checks, no console errors; read 2.42 s, retry 1.42 s wall; JS heap 30 MB after one read, 43 MB after three | **browser-only, synthetic** |
| Consent paths walk-through (decline, camera) with Chrome's synthetic fake camera | 17 / 17: camera closed and no media stream before a yes; after a no, **0** parse requests reached the server (server access log) and manual upload kept its token; a later camera press asked again; after a yes the camera opened, was released after capture, and exactly **1** parse request followed | **browser-only, synthetic** |

The browser runs prove the shipped bundle's behaviour in a real browser. They are **not** device or
real-KTP evidence: no clinic camera, no real card, no KTP holder. Both walk-throughs were re-run on the
final bundle after the last client change (a new photo now cancels an unanswered question).

A first version of the consent walk-through counted parse requests with a pattern that did not
match the server's log format, so its "zero after a no" result was vacuous. It was caught because
the yes-path count also read zero, fixed, and the whole walk-through re-run.

## 5a. Shipped and deployed (2026-10-10)

| Step | Evidence |
|---|---|
| PR | #469, squash-merged as `dfa3326c80812a84f1d9c30156667834f71aea23`; merged tree `fdddcc5e` == the CI-tested tree |
| CI | run `38039774239` on head `408b01db`: Classifier, NSF-R012 Quality, NSF-R011 Critical (**5418 passed / 27858 assertions, exit 0, 0 failures**, consent suite present), Selective Module, NSF-9, NSF-10, Android gate — all success. NSF-R011 Full Suite **skipped** by the standing deferral policy (not a pass) |
| Local regression | `tests/Feature/RME` + `tests/Feature/Patient` + `tests/Feature/FrontOfficeDevice`: 1863 passed / 8 failed — all 8 are FrontOffice WebAuthn ceremony tests (`Undefined array key "challenge"`), reproduced **identically on the untouched base `e1dc3a7d`** (local PHP 8.5.4; CI runs 8.3); none touches this change |
| Deploy | `deploy-vps-runner.sh start` run on the VPS at 19:07 WITA: `exit=0`, `DEPLOY OK: 20261010-110734`, `DEPLOY_HEAD_TARGET_MATCH=YES`; no migration |
| Production | HEAD `dfa3326c` / tree `fdddcc5e`; pilot `ARMED`, `errors=[]`, operator `[29]` @ SPN4, 2026-10-10..16, device waiver unchanged, `--strict` exit 0; `consent = {version: D7-2026-10-10, usable: true, paragraphs: 5}`; user 29 `no_working_branch` (offline at 19:18), users 7 / 30 / 1 / 11 `operator_not_in_pilot`; consent and measurement strings present in the shipped JS bundles |
| Health | `/login`, `/health/live`, `/health/ready`, `/health/lb` 200 over `https://daengtisia.online`; guest parse POST 419, guest patient create 302; php-fpm, nginx, queue worker active |
| Side effects | application log **byte-identical** (1,437,201 bytes / 162 `ERROR`) across deploy and verification; one smoke warning is the known co-tenant shadow on `http://127.0.0.1/login` (the domain answers 200) |
| Tag | `phase-3-patient-ktp-roi-ocr-consent-readiness-go` (tag object `e95d3c3c`) → `dfa3326c`, exact match at production HEAD — **engineering readiness only**. `phase-1-patient-ktp-camera-ocr-supervised-pilot-go` stays reserved and does not exist |

This evidence commit is documentation only and is not deployed; production stays on the tagged
runtime `dfa3326c`.

## 6. Outstanding — needs people on site

1. **Owner:** clinic privacy notice + withdrawal procedure (§3). Until then `PRIVACY_REVIEW = PENDING`.
2. **Admin Sunu at SPN4:** log in, select Cabang Sunu, confirm the camera button and the consent panel,
   allow the webcam, preview → Ulangi → Gunakan Foto Ini, webcam light off after capture
   (`REAL_CAMERA_TEST`).
3. **Real captures:** ≥ 30 consented captures across the protocol §3.2 conditions, each with a
   signed Appendix A form, recorded in the §4 sheet (outcomes only), summarised with the tool
   (`REAL_KTP_TEST`, `OCR_ACCURACY_GO`).
4. **Owner GO review** on the full protocol §5 list; rollback demonstrated once.
5. **2026-10-17:** disarm or record a fresh owner decision (rule 178 §16). Not extended here.

**DataViz.** Charts are produced only from the summariser output of real, aggregated captures. With
zero captures there is nothing to chart, and none was made.

## 7. `storage/runtime-home/` on production (read-only audit)

12 KB, `drwxrwsr-x daengtisiams:daengtisiams`, created 2026-09-05 08:30 WITA. One file:
`.config/psysh/psysh_history`, **0 bytes**. It is the HOME of an earlier REPL session run as the
runtime user; no command history is retained. Not referenced by any systemd unit or deploy script,
not git-ignored (hence the untracked entry), outside the web root. Left in place; removing it needs a
separate authorization.
