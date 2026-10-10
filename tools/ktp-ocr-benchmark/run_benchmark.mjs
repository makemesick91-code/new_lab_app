#!/usr/bin/env node
/**
 * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — reproducible OCR benchmark.
 *
 *   node tools/ktp-ocr-benchmark/run_benchmark.mjs <corpus_dir> <out.json> [--mode=baseline|hybrid] [--limit=N]
 *
 * baseline: the shipped pipeline — whole-image tesseract.js read (OEM 1, `ind`,
 *           default page segmentation), lines flattened by toOcrLines(), parsed
 *           by the real server parser.
 * hybrid:   the field-based ROI pipeline in resources/js/ktp-roi-ocr.js run on
 *           the same pixels, then reconciled by the real server service.
 *
 * The corpus is FICTIONAL (see generate_synthetic_ktp.py). Results describe
 * synthetic cards only and are never evidence of real-KTP accuracy.
 */

import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createWorker } from 'tesseract.js';
import { toOcrLines } from '../../resources/js/ktp-camera-ocr.js';
import { encodePng } from './png.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const args = Object.fromEntries(process.argv.slice(4).map((a) => a.replace(/^--/, '').split('=')));
const corpus = process.argv[2];
const outFile = process.argv[3];
const mode = args.mode ?? 'baseline';
const limit = Number(args.limit ?? 1e9);
if (!corpus || !outFile) {
    console.error('usage: run_benchmark.mjs <corpus_dir> <out.json> [--mode=baseline|hybrid]');
    process.exit(2);
}

const LANG_PATH = join(root, 'node_modules/@tesseract.js-data/ind/4.0.0_best_int');
const cachePath = join(dirname(resolve(outFile)), '.tesscache');
mkdirSync(cachePath, { recursive: true });

const norm = (v) => (v === null || v === undefined ? null : String(v).toUpperCase().replace(/\s+/g, ' ').trim());

function composedAddress(t) {
    return `${t.street}, RT/RW ${t.rt_rw}, KEL. ${t.village}, KEC. ${t.district}`;
}

function scoreOne(meta, parsed) {
    const t = meta.truth;
    const expectForm = {
        ktp_number: t.nik,
        name: t.name,
        date_of_birth: t.date_of_birth,
        gender: t.gender,
        address: composedAddress(t),
        occupation: t.occupation,
    };
    const expectFields = {
        nik: t.nik, name: t.name, birth_place: t.birth_place, date_of_birth: t.date_of_birth,
        gender: t.gender, address: t.street, rt_rw: t.rt_rw, village: t.village, district: t.district,
        religion: t.religion, marital_status: t.marital_status, occupation: t.occupation,
    };
    const fieldScores = {};
    for (const [key, expected] of Object.entries(expectFields)) {
        const got = parsed.fields?.[key] ?? { value: null, status: 'missing' };
        const value = norm(got.value);
        fieldScores[key] = {
            outcome: value === null ? 'missing' : value === norm(expected) ? 'correct' : 'wrong',
            status: got.status,
            reasons: got.reasons ?? [],
            got: got.value ?? null,
        };
    }
    const formScores = {};
    for (const [key, expected] of Object.entries(expectForm)) {
        const got = parsed.form?.[key] ?? { value: null, status: 'missing', suggest: false };
        const value = norm(got.value);
        const outcome = value === null ? 'missing' : value === norm(expected) ? 'correct' : 'wrong';
        formScores[key] = {
            outcome,
            status: got.status,
            suggest: Boolean(got.suggest),
            false_high_confidence: outcome === 'wrong' && Boolean(got.suggest),
            got: got.value ?? null,
            agreement: got.agreement ?? null,
        };
    }

    return { fieldScores, formScores, outcome: parsed.outcome };
}

function bridge(items) {
    // KTP_BENCH_BRIDGE: an alternative bridge script, e.g. one that loads the
    // parser of an earlier commit, so a baseline is measured as it shipped.
    const res = spawnSync('php', [process.env.KTP_BENCH_BRIDGE ?? join(here, 'parse_bridge.php')], {
        input: JSON.stringify({ items }),
        cwd: root,
        maxBuffer: 64 * 1024 * 1024,
        encoding: 'utf8',
    });
    if (res.status !== 0) {
        throw new Error(`parse bridge failed: ${res.stderr}`);
    }

    return JSON.parse(res.stdout).results;
}

async function main() {
    const ids = JSON.parse(readFileSync(join(corpus, 'manifest.json'), 'utf8')).slice(0, limit);
    const worker = await createWorker('ind', 1, { langPath: LANG_PATH, gzip: true, cachePath });
    const runs = [];
    let hybrid = null;
    if (mode === 'hybrid') {
        hybrid = await import('../../resources/js/ktp-roi-ocr.js');
    }

    for (const id of ids) {
        const meta = JSON.parse(readFileSync(join(corpus, `${id}.json`), 'utf8'));
        const rgba = readFileSync(join(corpus, `${id}.rgba`));
        const image = { width: meta.width, height: meta.height, data: new Uint8ClampedArray(rgba.buffer, rgba.byteOffset, rgba.length) };
        const started = performance.now();
        let lines;
        let fields = null;
        let detail = {};
        if (mode === 'baseline') {
            const { data } = await worker.recognize(readFileSync(join(corpus, `${id}.jpg`)), {}, { blocks: true });
            lines = toOcrLines(data);
        } else {
            const out = await hybrid.runFieldOcr(image, {
                // recognize() options are applied for this call only and then
                // restored by tesseract.js, exactly as in the browser module.
                recognize: async (img, params) => {
                    const { data } = await worker.recognize(encodePng(img, img.channels ?? 4), params ?? {}, { blocks: true });
                    return data;
                },
            });
            lines = out.lines;
            fields = out.fields;
            detail = { corners: out.corners, boundary: out.boundary, timings: out.timings };
        }
        const ms = performance.now() - started;
        runs.push({ id, meta, lines, fields, ms, detail });
        process.stderr.write(`${id} ${ms.toFixed(0)}ms\n`);
    }
    await worker.terminate();

    const parsed = bridge(runs.map((r) => ({ id: r.id, lines: r.lines, fields: r.fields })));
    // Field-based read on its own: the same field reads with no whole-card
    // lines, so the three methods are compared on identical pixels.
    const fieldOnly = mode === 'hybrid'
        ? bridge(runs.map((r) => ({ id: r.id, lines: [], fields: r.fields })))
        : null;
    const results = runs.map((r) => ({
        id: r.id,
        scene: r.meta.scene,
        ms: Math.round(r.ms),
        detail: r.detail,
        lineCount: r.lines.length,
        rawLines: r.lines,
        rawFields: r.fields,
        ...scoreOne(r.meta, parsed[r.id]),
        fieldOnly: fieldOnly ? scoreOne(r.meta, fieldOnly[r.id]) : null,
    }));
    writeFileSync(outFile, JSON.stringify({ mode, generatedAt: new Date().toISOString(), results }, null, 1));
    console.log(`${results.length} scenes scored -> ${outFile}`);
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
