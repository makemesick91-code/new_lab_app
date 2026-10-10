# REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — Hybrid field-based KTP OCR

| | |
|---|---|
| Type | Feature revision / OCR accuracy improvement |
| Branch | `feature/patient-ktp-ocr-field-based-roi-1` |
| Base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `e4c9a8da` (runtime `87930fcd`, `phase-1-patient-ktp-camera-ocr-pilot-readiness-go`) |
| Rule | `.cursor/rules/179-ktp-field-based-roi-ocr.mdc` (extends 177, 178) |
| Migration | none |
| Permission / route / config key | none added; the parse request gains an optional `fields` payload |
| Real-card accuracy | **PENDING** — no consented physical capture yet (pilot decision D7) |

## 1. Why

The camera OCR shipped in REVISION-REGISTRATION-KTP-CAMERA-OCR-1 reads the whole
card in one pass and splits the text into lines. On the synthetic benchmark it
pre-filled a **wrong** value 39 times in 288 field reads, most of them looking
confident. A wrong pre-filled identity value is the failure that matters, because
an operator under time pressure accepts what is already ticked.

## 2. Root cause (measured on the synthetic corpus)

Categories as requested: A capture, B preprocessing, C character recognition,
D segmentation/layout, E parser/label recognition, F validation/normalization,
G operator interface.

| Category | Finding | Evidence (whole-card read, 48 cards) |
|---|---|---|
| **D — segmentation** (main cause) | The engine reads the photo-caption column ("KOTA MAKASSAR", issue date) into the same line as the value beside it, so occupation and status lines carry text from another column. | Occupation correct 11/48, wrong 37, of which 30 were pre-filled. Address wrong 12, 7 pre-filled. |
| **C — character recognition** | The bold NIK font: "71" read as "/1" or "/" inside a 16-digit run; lost spaces in names; a colon read as a glyph. | NIK correct 32/48, missing 16 (the parser correctly refused an invalid NIK rather than guess). Name wrong 5, 2 pre-filled. |
| **E — label recognition** | `RT/RW` read as `RTIRW` or `RT1RW`; the line is no longer recognised as the RT/RW field, so the composed address loses it. | Recovered by attaching the label server-side to the field-box read. |
| A — capture | Not a cause on this corpus: framed, rotated, tilted and glare scenes read as well as the flat scan. Real cameras remain untested. | Per-condition correct reads 31–42 of 48 for the whole-card read, no condition systematically worse. |
| B — preprocessing | Global contrast was adequate for the whole card but not for small bold digits. Per-field cap-height normalisation fixed the NIK (cap-height sweep: 24 px best). | NIK 47/48 with field boxes. |
| F — validation | Working as designed: invalid NIK and impossible dates were refused, never repaired. | Kept unchanged. |
| G — operator interface | No way to correct the region that was read, and no way to see two disagreeing reads side by side. | Addressed by the box editor and the *Berbeda — pilih* state. |

## 3. What changed

**Browser (pure pipeline)** `resources/js/ktp-roi-ocr.js`
- Card boundary: gradient-limited Hough lines, card-shaped quadrilateral search
  with side support scoring and least-squares side refinement. Not found →
  full image with `boundary.found = false`; the operator may set the corners.
- Perspective correction to a fixed 1280 × 807 card; border whitened.
- Template `ektp-v1` in normalized coordinates, anchored to the printed labels
  (row fit with outlier rejection); boxes always clamped.
- Per field: crop → cap height 24 px → contrast stretch → optional Sauvola
  binarization (max two passes) → single-line or block page segmentation with a
  field-specific character set → plausibility-first choice of pass.
- The whole-card read still runs on the straightened card with engine defaults.

**Browser (operator surface)** `resources/js/ktp-roi-ui.js` (lazy chunk)
- The straightened card with one editable box per field (mouse, touch,
  keyboard), field crop previews, *Baca Ulang* per field and for all boxes,
  reset to the auto-anchored boxes, and a four-corner editor.
- Twelve verification rows: crop, field, value, status, action, apply.
- Rules: first read pre-ticks only a clean value into an empty form field; a
  retry never overwrites an accepted or typed value (offered beside it); a
  disagreement shows both values and ticks neither.

**Server**
- `KtpOcrParser` split into `parseFields()` + `finalize()`; new `conflict`
  status; RT/RW label tolerant of common misreads.
- New `KtpOcrSuggestionService`: without `fields` it returns the previous
  payload byte for byte; with `fields` it parses both reads, reconciles each
  field (`agree`, `field_only`, `document_only`, `conflict`, `none`), then runs
  the NIK cross-check and form suggestions once on the result. Labels for field
  lines are chosen by the server.
- `ParseKtpOcrRequest` accepts `fields` limited to the known keys, bounded text
  and confidence 0..100. The endpoint still stores, logs and audits nothing.

Unchanged: pilot gate, feature flag, branch isolation, permissions, private
storage, NIK masking, CSRF, rate limit, explicit confirmation, duplicate check,
self-hosted tesseract.js assets.

## 4. Benchmark (synthetic, fictional)

Corpus: 8 invented identities × 6 photo conditions = 48 cards, 6 patient form
fields each = 288 reads. Generated by `tools/ktp-ocr-benchmark/generate_synthetic_ktp.py --seed 7`.
Summary: `docs/evidence/ktp-ocr/field-roi-benchmark-summary-2026-10-10.json`.
Charts (counts and timings only): https://claude.ai/artifact/FAay1pzqWBEJY63KXwqCLm

| Method | Correct | Operator choice | Missing | Wrong | Wrong & pre-filled | Pre-filled precision |
|---|---|---|---|---|---|---|
| Whole-card read (shipped) | 216 | 0 | 18 | 54 | **39** | 81.7% (174/213) |
| Field boxes only | 276 | 0 | 1 | 11 | 5 | 98.2% (268/273) |
| **Hybrid (this release)** | 237 | 49 | 0 | 2 | **2** | **99.1%** (233/235) |

- In 40 of the 49 choices the field-box value was the correct one; the operator
  sees it beside the whole-card value.
- Field boxes alone have more exact reads than the hybrid. They were not shipped
  alone because they still pre-fill 5 wrong values; the hybrid trades exact reads
  for operator choices, which is the intended direction.
- The 2 remaining wrong pre-filled values are addresses where both methods read
  the same wrong text. Agreement is evidence, not proof (rule 177 §4).
- Under dim, blurred light the hybrid has fewer exact reads (37 vs 42); the gap
  is choices, since the hybrid is wrong only twice across the whole run.
- Flat scans fill the frame, so the boundary finder reports "not found" and the
  full image is used; that is the designed fallback.
- Read time (Node, development machine): median 0.90 s → 1.72 s, slowest
  1.19 s → 2.35 s. Stage medians: card edges 47 ms, straighten 49 ms, whole
  card 1189 ms, field boxes 433 ms.
- Browser walk-through (local WebDriver, Chrome, fictional card, after the
  review fixes): read 2.1–2.5 s as reported on screen, *Baca Ulang* 1.2–1.9 s,
  JS heap 29–30 MB after one read (51–57 MB after three reads including a
  cancelled one, no forced garbage collection), no horizontal page scroll, no
  console errors, 23/23 checks at 1366×900 and 820×1180. The walk-through
  includes clearing a photo mid-read and confirming a new one; with the
  session guard reverted, the page stalls at exactly that step.

## 4a. Independent review and what it changed

An adversarial review of the diff found 0 CRITICAL and 0 HIGH issues, 4 MEDIUM
and 4 LOW. Every MEDIUM and every real LOW was fixed before merge, each with a
test that fails when the fix is reverted (6 mutants, 6 killed):

| Finding | Fix |
|---|---|
| M1 — when the whole-card read saw a label twice with different values (`ambiguous`), the box read was taken as the only value and pre-ticked. | That value is now offered as `low_confidence` (`other_read_ambiguous`), never pre-selected. |
| M2 — a NIK in conflict had no single value, so the NIK cross-check was skipped and a contradicting birth date or gender became clean. | The cross-check runs against every candidate of a conflicting field; a value that matches none is demoted. |
| M3 — a tick the system set on an earlier read survived a re-read that produced a conflict, so a value nobody chose could be applied. | Only an operator action protects a row; an untouched row takes a changed read unselected. |
| M4 — closing the OCR session mid-read (new photo, clear) left the read waiting forever, which jammed the photo confirm until reload. | Every step of a session is raced against its lifetime and the parse request is aborted; a run that outlived its photo stops without touching the page. |
| LOW — a box could be read as another field when its text matched that field's label first ("KELAMIN DESA" → gender). | A box read is passed through unlabelled only when the parser files it under the same field. |
| LOW — between the two image passes of one field, the more confident one won. | When both passes are plausible and differ, the score is dropped, so only agreement with the whole-card read can make that value clean. |
| LOW — an oversized image was decoded in full before the size check. | The size is checked from the image header first. |
| LOW — the "no field read" path was described as byte-for-byte unchanged. | Corrected: the response shape is unchanged; the only value change is that a misread `RTIRW` label is now recognised (pinned by a test). |

The benchmark was re-run after the fixes; the figures above are from that run.

## 5. Decisions

1. Hybrid over replacement: keeping the whole-card read costs about 0.8 s but
   turns most field-box mistakes into visible choices instead of silent wrong
   values.
2. Disagreement is never settled by confidence. Confidence on this corpus was
   high on wrong values (the reason this sprint exists).
3. No new patient column. Birth place, religion and marital status remain
   display-only.
4. The template is versioned; a new card layout is a new template version.
5. The legacy request shape keeps its response shape so an old cached page
   during deploy keeps working.

## 6. Pilot impact

The pilot is armed for Admin Sunu (user 29) at SPN4 from 2026-10-10 to
2026-10-16 (rule 178 §15–16). This release does not change the pilot scope,
flag or device waiver. After deploy, that operator's next scan uses the hybrid
read. The pilot protocol records the new outcomes (`choice_correct`,
`choice_neither`, corners adjusted, boxes moved, retries). Physical captures
remain blocked on decision D7 (consent wording).

## 7. Status

| Item | Status |
|---|---|
| Real-card accuracy | PENDING (D7 consent, supervised pilot) |
| Clinical activation | NO |
| Wider rollout | NO |
