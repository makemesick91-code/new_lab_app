# KTP OCR benchmark (development only)

Measures the KTP camera OCR on **fictional, machine-made** identity cards so a
change to the reading pipeline can be compared before it reaches a clinic.
Nothing here touches a database, a real card or the network.

| File | Purpose |
|---|---|
| `generate_synthetic_ktp.py` | Renders invented e-KTP cards into six photo conditions and writes the exact pixels the browser pipeline sees, plus ground truth. |
| `run_benchmark.mjs` | Reads each scene with the shipped whole-card OCR (`--mode=baseline`) or the hybrid field-box OCR (`--mode=hybrid`), parses through the real server code, scores against ground truth. |
| `parse_bridge.php` | Runs `KtpOcrSuggestionService` (or the plain parser on an older checkout) from the local application container. |
| `summarize.mjs` | Turns a baseline run and a hybrid run into counts and timings only (no OCR text). |
| `browser_e2e.php` | Local WebDriver walk-through of the operator screen. Refuses any non-local base URL. Since PHASE-3 it answers the D7 consent panel for the fictional card (and checks that clearing the photo asks again). |
| `png.mjs` | Minimal PNG encoder for the Node harness. |

## Reproduce

```bash
python3 tools/ktp-ocr-benchmark/generate_synthetic_ktp.py /tmp/ktp-corpus --seed 7
node tools/ktp-ocr-benchmark/run_benchmark.mjs /tmp/ktp-corpus /tmp/baseline.json --mode=baseline
node tools/ktp-ocr-benchmark/run_benchmark.mjs /tmp/ktp-corpus /tmp/hybrid.json --mode=hybrid
node tools/ktp-ocr-benchmark/summarize.mjs /tmp/baseline.json /tmp/hybrid.json /tmp/summary.json
```

Write the corpus and the raw runs outside the repository. The raw runs carry the
(fictional) OCR text; only the summary is suitable for docs or charts. The
recorded summary for this release is
`docs/evidence/ktp-ocr/field-roi-benchmark-summary-2026-10-10.json`.

## What it is not

A synthetic score is not real-card accuracy. Real cards have wear, holograms,
lamination glare, other fonts and other cameras. Real-card accuracy is measured
only in the supervised pilot (`docs/runbooks/ktp-camera-ocr-supervised-pilot-protocol.md`),
with recorded consent, and reported as counts per field. Never add a real KTP
image, NIK, name or address to this directory, a test fixture or a report.
