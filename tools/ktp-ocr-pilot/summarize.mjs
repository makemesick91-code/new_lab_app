#!/usr/bin/env node
// PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — summarise the pilot capture sheet.
//
//   node tools/ktp-ocr-pilot/summarize.mjs <sheet.csv> [summary.json]
//
// Keep the sheet OUTSIDE the repository and off chat, e-mail and AI services.
// Only the summary (counts and rates, no identifiers) may go into docs or charts.
// Exit 0 = summary written; 1 = sheet refused (problems listed by row/column);
// 2 = usage error.
import { readFileSync, writeFileSync } from 'node:fs';
import { summarize } from './pilot-metrics.mjs';

const [, , input, output] = process.argv;
if (!input) {
    process.stderr.write('usage: node tools/ktp-ocr-pilot/summarize.mjs <sheet.csv> [summary.json]\n');
    process.exit(2);
}

let result;
try {
    result = summarize(readFileSync(input, 'utf8'));
} catch (e) {
    process.stderr.write(`${e.message}\n`);
    for (const p of e.problems ?? []) process.stderr.write(`  - ${p}\n`);
    process.exit(1);
}

const json = JSON.stringify({ generated_from: 'protocol §4 capture sheet', ...result }, null, 2);
if (output) writeFileSync(output, `${json}\n`);
process.stdout.write(`${json}\n`);
