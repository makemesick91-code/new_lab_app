#!/usr/bin/env node
/**
 * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — turn two benchmark runs into the
 * aggregate summary used by the sprint doc and the benchmark charts.
 *
 *   node tools/ktp-ocr-benchmark/summarize.mjs <baseline.json> <hybrid.json> <summary.json> [live.json]
 *
 * With a fourth run (run_benchmark.mjs --mode=live on a generate_live_frames.py
 * corpus — REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1) the live-aligned method
 * is tallied with the same rules and the capture statuses are counted. Without
 * it the output is unchanged.
 *
 * Output holds counts and timings only — no OCR text, no ground truth — so it
 * is safe to keep or chart. "field_only" is the hybrid run's own field-box
 * reads parsed without the whole-card lines (run_benchmark.mjs `fieldOnly`).
 */

import { readFileSync, writeFileSync } from 'node:fs';

const [baselineFile, hybridFile, outFile, liveFile] = process.argv.slice(2);
if (!baselineFile || !hybridFile || !outFile) {
    console.error('usage: summarize.mjs <baseline.json> <hybrid.json> <summary.json>');
    process.exit(2);
}

const FORM_KEYS = ['ktp_number', 'name', 'date_of_birth', 'gender', 'address', 'occupation'];
const baseline = JSON.parse(readFileSync(baselineFile, 'utf8')).results;
const hybrid = JSON.parse(readFileSync(hybridFile, 'utf8')).results;
const live = liveFile ? JSON.parse(readFileSync(liveFile, 'utf8')).results : null;

function tally(results, pick) {
    const out = { exact: 0, wrong: 0, missing: 0, conflict: 0, wrongPreselected: 0, preselected: 0, preselectedCorrect: 0, perField: {} };
    for (const key of FORM_KEYS) out.perField[key] = { correct: 0, wrong: 0, missing: 0, conflict: 0, fhc: 0 };
    for (const r of results) {
        const scores = pick(r);
        for (const key of FORM_KEYS) {
            const s = scores[key];
            const f = out.perField[key];
            if (s.status === 'conflict') { out.conflict++; f.conflict++; continue; }
            if (s.outcome === 'correct') { out.exact++; f.correct++; }
            else if (s.outcome === 'wrong') { out.wrong++; f.wrong++; }
            else { out.missing++; f.missing++; }
            if (s.suggest) {
                out.preselected++;
                if (s.outcome === 'correct') out.preselectedCorrect++;
            }
            if (s.false_high_confidence) { out.wrongPreselected++; f.fhc++; }
        }
    }

    return out;
}

// Nearest-rank on the sorted values (upper median for an even count).
const nearestRank = (values, p) => {
    const sorted = [...values].sort((a, b) => a - b);

    return sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))];
};
const median = (values) => nearestRank(values, 0.5);
const pct = nearestRank;
const latency = (results) => {
    const ms = results.map((r) => r.ms);

    return { median: median(ms), p90: pct(ms, 0.9), max: Math.max(...ms) };
};

let conflicts = 0;
let fieldReadIsTrue = 0;
for (const r of hybrid) {
    for (const key of FORM_KEYS) {
        if (r.formScores[key].status !== 'conflict') continue;
        conflicts++;
        if (r.fieldOnly?.formScores?.[key]?.outcome === 'correct') fieldReadIsTrue++;
    }
}

const stagesMs = {};
for (const stage of ['boundary', 'normalize', 'document', 'fields']) {
    const values = hybrid.map((r) => r.detail?.timings?.[stage]).filter((v) => typeof v === 'number');
    stagesMs[stage] = { median: median(values), max: Math.max(...values) };
}

const byScene = {};
for (const [label, results] of [['baseline', baseline], ['hybrid', hybrid], ...(live ? [['live', live]] : [])]) {
    for (const r of results) {
        byScene[r.scene] ??= live ? { baseline: 0, hybrid: 0, live: 0 } : { baseline: 0, hybrid: 0 };
        byScene[r.scene][label] += FORM_KEYS.filter((k) => r.formScores[k].status !== 'conflict' && r.formScores[k].outcome === 'correct').length;
    }
}

const summary = {
    corpus: `${hybrid.length} fictional synthetic KTP scenes`,
    methods: {
        baseline: tally(baseline, (r) => r.formScores),
        field_only: tally(hybrid, (r) => r.fieldOnly.formScores),
        hybrid: tally(hybrid, (r) => r.formScores),
    },
    conflicts: { total: conflicts, fieldReadIsTrue },
    latencyMs: { baseline: latency(baseline), hybrid: latency(hybrid) },
    stagesMs,
    byScene,
};

if (live) {
    summary.methods.live = tally(live, (r) => r.formScores);
    summary.methods.live_field_only = tally(live, (r) => r.fieldOnly.formScores);
    summary.latencyMs.live = latency(live);
    summary.liveCapture = {};
    for (const r of live) summary.liveCapture[r.detail?.capture ?? 'none'] = (summary.liveCapture[r.detail?.capture ?? 'none'] ?? 0) + 1;
    summary.livePreviewLevel = {};
    for (const r of live) summary.livePreviewLevel[r.detail?.previewLevel ?? 'none'] = (summary.livePreviewLevel[r.detail?.previewLevel ?? 'none'] ?? 0) + 1;
}

writeFileSync(outFile, JSON.stringify(summary, null, 1));
console.log(`summary -> ${outFile}`);
