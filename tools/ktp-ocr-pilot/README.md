# KTP OCR pilot — capture-sheet summariser

Turns the supervised-pilot capture sheet (protocol §4,
`docs/runbooks/ktp-camera-ocr-supervised-pilot-protocol.md`) into counts and rates.

```bash
node tools/ktp-ocr-pilot/summarize.mjs /path/outside/repo/sheet.csv /path/outside/repo/summary.json
```

- The sheet holds **outcomes only**. The tool refuses a sheet with anything identifier-shaped
  (6+ digit runs, e-mail addresses, dates outside the `date` column) and names only the row and
  column — it never prints the offending value.
- Keep the sheet and the summary on the clinic computer, outside the repository, and off chat,
  e-mail and AI/MCP services. Only the summary (no identifiers) may go into docs or charts.
- Verdicts: `PENDING` below 30 consented captures; `NO_GO` on any confident wrong NIK or a missed
  target; `METRICS_PASS` at most. The sheet cannot show security, cross-branch, data-integrity or
  rollback results, so clinical GO is always the owner's decision on the full protocol §5 list.

Pure logic: `pilot-metrics.mjs`, tested by `tests/js/ktp-ocr-pilot-metrics.test.mjs`.
