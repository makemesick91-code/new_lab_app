# REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — Live field overlay on the KTP camera preview

**Type:** feature revision (computer vision / OCR) · **Module:** Patient · **Base:**
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `679b7bc7`
(production runtime `dfa3326c`, tag `phase-3-patient-ktp-roi-ocr-consent-readiness-go`) ·
**Rule:** `.cursor/rules/181-ktp-live-field-overlay-ocr.mdc` · **Manifest:** `.sprint/current.yml`

No migration, no permission, no route, no patient column, no pilot or environment change.
The pilot scope (operator 29 / SPN4), its period (ends 2026-10-16) and the device waiver are
untouched; deploying this release arms nothing.

## 1. Why

Operators reported fields that were not extracted although the photo looked clear. The field-based
read (rule 179) already places a box per field on the straightened card — but the operator only saw
those boxes AFTER the photo. Nothing helped them hold the card where the read expects it, and the
capture always kept a fixed 90% frame, whatever the card was doing.

## 2. What changed

| Piece | Where | What |
|---|---|---|
| Pure live module | `resources/js/ktp-live-overlay.js` | Display mapping (object-fit, mirroring, CSS px), template→quad projection through the card homography, geometry guard, quality measures, hysteresis, capture crop, capture-time confirmation, corner hint, non-overlapping adaptive scheduler, tracking loop with injected dependencies. DOM-free, node-tested. |
| Overlay runtime | `resources/js/ktp-live-camera.js` (lazy chunk) | SVG layer over the video: card outline + the template's 11 field boxes and labels; RED / YELLOW / GREEN indicator with instructions; module worker with main-thread fallback; capture. |
| Worker | `resources/js/ktp-live-tracker.worker.js` | Analyses one transferred, downscaled preview frame per message; returns numbers only. |
| Camera integration | `resources/js/ktp-camera-ocr.js` | Imports the overlay only after the camera opened (which needs D7); captures through it; keeps a lossless copy + capture-confirmed corners for the read; previous guide-crop capture kept as fallback. |
| Field read | `resources/js/ktp-roi-ui.js`, `ktp-roi-ocr.js` | Accepts the corner hint; records it as `boundary.reason = 'live'` (an operator edit stays `manual`). |
| View | `resources/views/settings/patients/_ktp-scan.blade.php` | SVG layer, indicator (pre-rendered variants), guidance line, "HIJAU bukan jaminan…" notice. |

### The capture rule (the safety core)

The live corners are frozen when **Ambil Foto** is pressed but are only *compared*. The same analysis
runs again on the captured pixels:

- found there and close to the live corners → `confirmed`; found but moved → `moved`; found with no
  live card → `capture_only` — in every case the corners come **from the photo**;
- not found → `unconfirmed`: the whole frame is kept and **no** corners are passed on. The read
  detects the edge itself, or the operator uses **Atur Sudut KTP**.

Only a `confirmed` capture, where two independent detections agree, is cropped to the card. `moved` and
`capture_only` keep the whole frame (their corners still straighten the card inside it), so a single wrong
detection can never cut part of the card out of the stored KTP document.

Stale live geometry is never used for OCR. The overlay is a separate SVG element; the capture draws
only the video frame into its canvases.

## 3. Consent (D7) and the pilot gate

The camera already opened only after the KTP holder's "yes" (rule 180). The live module is imported
inside the camera-open path, after `getUserMedia` succeeded, and every analysis tick re-asks
`liveTrackingAllowed()` (OCR on the page + accepted consent + usable wording, one definition shared by
both files). Closing the camera, a "no", clearing the photo or leaving the page stops it before the next
frame. The overlay markup is inside the camera block, which the server renders only for an operator the
pilot gate allows. Browser-measured: before consent and after a "no", **no camera stream, no live
module request, no parse request**.

## 4. Thresholds — calibrated, not invented

Quality limits were set from 48 fictional camera frames (`tools/ktp-ocr-benchmark/generate_live_frames.py`,
8 identities × 6 conditions), downscaled exactly as the live path does:

| Condition | Card found | Corner error (camera px) | Sharpness | Classified |
|---|---|---|---|---|
| clear 1080p | 8/8 | 1.1 – 7.1 | 32.8 – 36.0 | GREEN |
| rotated 4–9° | 8/8 | 1.1 – 6.8 | 30.6 – 36.2 | GREEN |
| perspective | 8/8 | 1.3 – 3.4 | 32.8 – 36.4 | GREEN |
| dim + blur | 8/8 | 1.5 – 8.6 | 11.8 – 13.6 | YELLOW · blurry |
| glare | 8/8 | 2.5 – 9.3 | 26.0 – 31.7 | YELLOW · glare (0.21–0.32) |
| 720p webcam, card 50–60% | 8/8 | 1.1 – 6.0 | 34.9 – 40.4 | YELLOW · too far (cap 10–12 px < 13) |

`minSharpness` started at a provisional 9, which the calibration showed missed every blurred frame; it is
18 (margin on both sides). These are synthetic values; tune only from pilot measurements.

## 5. Benchmark — three methods on identical frames (synthetic)

`run_benchmark.mjs --mode=baseline|hybrid|live` on the same 48 frames; A and B read the previous release's
capture of each frame (90% guide crop → JPEG 0.95 → fit 1600 / JPEG 0.85), C runs the shipped live path on
the full frame. 288 form reads per method. Summary:
`docs/evidence/ktp-ocr/live-overlay-benchmark-summary-2026-10-10.json`.

| Method | Exact | Wrong pre-filled | Left for operator | Pre-fill precision | Median read |
|---|---|---|---|---|---|
| A whole-card | 212 | 40 | 0 | 79.5% | 0.87 s |
| B hybrid ROI | 231 | 6 | 51 | 97.4% | 1.73 s |
| **C live-aligned** | **231** | **7** | **50** | **97.0%** | **1.84 s** |

**C shows no accuracy gain over B on synthetic frames.** The generator places a well-framed card by
construction, so B's own detection already finds it (C: 48/48 captures `confirmed`); what the overlay
improves — a human holding the card where the read expects it — is exactly what a synthetic corpus cannot
measure. Eight reads differ between B and C, in both directions; the one extra wrong pre-fill is an address
where both reads agreed on the same wrong value (the existing hybrid failure mode B shows six times). The
lossless read is kept on principle (no JPEG damage to small NIK digits; NIK 48/48 in both B and C), not on
a measured gain. **Real-card accuracy: PENDING** (pilot protocol §3.3, consented captures).

## 6. Browser evidence (Chrome 149, fake camera, fictional card — browser-only)

`tools/ktp-ocr-benchmark/live_overlay_e2e.php`; summary `docs/evidence/ktp-ocr/live-overlay-browser-e2e-2026-10-10.json`.
**193 / 193 checks** across four scenarios, 0 console errors (run after the security-review fixes in §9).

| Scenario | Checks | Notes |
|---|---|---|
| Desktop 1366×900 @1× | 61/61 | first analysis 247 ms after the camera opened (GREEN needs 3 steady analyses); worker; analysis mean 69 ms / p95 140 ms at 200 ms interval; 30 fps preview ticks |
| Tablet 800×1280 @2× | 61/61 | analysis mean 60 ms / p95 108 ms |
| Empty desk | 19/19 | stays RED, neutral hint frame, no outline claimed; capture says *tidak terkonfirmasi*, whole frame kept |
| No module workers | 52/52 | main-thread fallback, mean 55 ms / p95 106 ms; OCR unaffected |

- **On-screen alignment** (SVG vertices via the browser's own layout vs the card's ground-truth corners
  through an independent homography): outline error **0.62 px = 0.14% of the drawn card width**, field
  boxes **0.36–0.40 px = 0.08–0.09%** (worst across the measurements); unchanged with a 98 px letterbox and with a mirrored preview, at 1× and 2×.
- **Never burned in:** 0 overlay-colour pixels in every photo and 0 in an 11,592-sample band along the card
  edges; a control that draws the outline in registers 6,488–6,497 — the measure demonstrably sees a burned
  outline.
- Consent: no stream, no live module, no parse request before the "yes" or after a "no"; asked again on the
  next camera press; kept for "Ulangi" and for re-opening after a read. **"Hapus" with the camera open closes
  the camera, ends the overlay, sends nothing, and the next camera press asks again.**
- Camera open → playing 82–89 ms; capture → preview 44–174 ms; field read ~2.0 s; JS heap after
  capture/retake cycles 37/22/30 MB (desktop), 32/32/30 MB (tablet) — no sustained growth.
- On the fictional 720p clip: NIK, name, birth date, gender read correctly 3/3; occupation left for the
  operator 3/3.

**Not measured here:** Firefox (not installed on the build machine), Safari, a real tablet camera, real
cards, glare from real lamination. `REAL_DEVICE_TEST = PENDING`, `REAL_KTP_TEST = PENDING`.

## 7. Bundle

Main `app.js` 118.02 → 120.26 kB (+2.24 kB, +0.92 kB gzip). Everything else is lazy and loads only after
the camera opens: `ktp-live-camera` 7.7 kB, worker 14.6 kB, shared geometry chunk 30.3 kB (the ROI-UI chunk
shrank from 42.1 to 23.9 kB because the geometry it shared moved into that chunk).

## 8. Decisions

1. **Overlay in SVG, not canvas** — vector stays sharp at any DPR, and as a separate layer it cannot reach
   the photo.
2. **Detection reused, not re-implemented** — `detectCardQuad` serves the preview, the capture check and the
   read. One coordinate model (`KTP_TEMPLATE`).
3. **A confirmed capture crops to the card, not a fixed frame** — more card pixels survive the 1600 px upload
   limit. Any capture that is not `confirmed` keeps the whole frame, so one wrong detection never cuts off part
   of the stored card.
4. **Hysteresis**: GREEN after 3 steady analyses, down after 2; a single bad frame never flickers the
   indicator; a card lost for 2 analyses is forgotten.
5. **GREEN never gates the photo** — the operator may capture in any colour; the read and the operator
   check decide.

## 9. Security review

An independent adversarial review of the diff found no CRITICAL or HIGH issue and no KTP data leaving the
browser. Its findings and what was done:

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | MEDIUM | The confirm handler read the shared lossless copy and corner hint *after* the upload `await`; a photo taken during the upload could make OCR read a different card than the stored one (wrong-patient risk). | The photo (upload, lossless copy, hint) is taken as one object before the first `await`; a newer photo is never cleared by an older confirm; camera, capture, file, scan, clear, retake and switch are refused while an upload and read are in flight. |
| 2 | MEDIUM | "Hapus" reset consent but left the camera open, and `capture()` did not re-check permission, so a frame could be analysed after consent was reset. | Clearing the photo closes the camera opened under the old answer; `capture()` refuses when tracking ended; the overlay tells its owner when it stops. Browser-proven (4 new checks). |
| 3 | LOW | Two quick camera opens could leave an orphaned overlay (and, pre-existing, an orphaned camera stream). | At most one overlay; a camera that arrives after it was closed or re-opened is stopped, never shown. |
| 4 | LOW | A frame grabbed after `stop()` was left for garbage collection. | Released at once. |
| 5 | LOW | One wrong detection could crop the stored document. | Only a `confirmed` capture (two agreeing detections) is cropped (§2). |
| 6 | INFO | A compiled `__pycache__` file sat in the benchmark tools. | Removed; `__pycache__/` and `*.pyc` ignored. |

Each code fix has a unit or source test that fails when the fix is removed (6 mutations, 6 killed). INFO: with
a live hint the pilot metric `card_found` means "found by the capture-time detection on the photo"; it is
still a detection on the stored pixels, never assumed.

## 10. Status

Engineering only. Real-device, real-card and clinical results are owner/pilot evidence, not this release's.

## 11. Release evidence (2026-10-10)

| Item | Value |
|---|---|
| PR | #471, squash-merged as `6c4f3608135dab694ede6dccd6defd9284579750` |
| Tree | `e68385be119669774e5d496d4ea3087fbf97d391`, identical to the CI-tested candidate `f6523958` |
| CI | run `38056384975`: classifier, NSF-R012 quality (JS 199/199), NSF-R011 critical (5,425 passed, incl. `PatientKtpLiveFieldOverlayTest`), selective module, Android, NSF-9, NSF-10 all green. Full Suite **skipped** by the standing temporary policy, not passed. |
| Deploy | `scripts/deploy-vps-runner.sh` executed ON the VPS: `exit=0`, `DEPLOY OK: 20261010-154422`, `DEPLOY_HEAD_TARGET_MATCH=YES` |
| Backup | `pre_auto_deploy_20261010-154422.sql` (32,120,983 bytes) taken before migrate; nothing to migrate |
| Production | `https://daengtisia.online`: `/login`, `/health/live`, `/health/ready`, `/health/lb` 200; `/storage/*` 403; the three live-overlay assets served (200); `app.js` loads `ktp-live-camera.js` only as a dynamic import; env pilot, debug and maintenance off; queue worker active, no failed jobs; no application error logged since the deploy |
| Pilot posture | unchanged: ARMED for operator 29 at SPN4, 2026-10-10 to 2026-10-16, no device lock, consent `D7-2026-10-10` usable |
| GO tag | `revision-patient-ktp-live-field-overlay-ocr-1-go` (annotated) on `6c4f3608` |

Real-device test, real-KTP accuracy and clinical pilot results remain **PENDING**; this tag is an engineering
release only. The deploy smoke's `http://127.0.0.1/login` 404 is the known co-tenant nginx shadow; the canonical
domain answers 200.
