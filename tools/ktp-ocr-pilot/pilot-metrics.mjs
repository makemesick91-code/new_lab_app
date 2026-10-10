// PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — pilot capture sheet → metrics.
//
// Reads the protocol §4 sheet (docs/runbooks/ktp-camera-ocr-supervised-pilot-protocol.md),
// one row per capture, OUTCOMES ONLY. Pure and dependency-free so the Node test
// runner covers it. It never reads a KTP image, never contacts a network, and
// refuses a sheet that looks like it carries an identifier.
//
// It measures; it does not decide clinical activation. Criteria the sheet cannot
// hold (security, cross-branch access, patient data corruption, unverified wrong
// data saved, rollback) are reported as NOT_MEASURED_BY_SHEET.

export const HEADER = Object.freeze([
    'capture_no', 'date', 'device_label', 'condition', 'consent', 'ocr_seconds', 'camera_ok', 'ocr_completed',
    'nik', 'name', 'birth_date', 'gender', 'address', 'occupation', 'registration_completed',
    'card_found', 'corners_adjusted', 'boxes_moved', 'retries', 'retries_by_field', 'notes',
]);

export const FIELDS = Object.freeze(['nik', 'name', 'birth_date', 'gender', 'address', 'occupation']);

export const OUTCOMES = Object.freeze([
    'correct', 'corrected', 'missing', 'wrong_high_conf', 'not_applied', 'choice_correct', 'choice_neither',
]);

/** Retry keys the screen prints (FIELD_SPECS in resources/js/ktp-roi-ocr.js). */
export const RETRY_KEYS = Object.freeze([
    'nik', 'name', 'birth_place_date', 'gender', 'address', 'rt_rw', 'village', 'district',
    'religion', 'marital_status', 'occupation',
]);

export const CONDITIONS = Object.freeze([
    'normal_light', 'low_light', 'glare', 'slight_tilt', 'near', 'far', 'worn_card',
    'long_name', 'multi_line_address', 'uncommon_occupation', 'other',
]);

/** Owner-approved working targets (activation record §7) plus protocol §5 item 10. */
export const TARGETS = Object.freeze({
    consentedCaptures: 30,
    cameraSuccess: 0.95,
    ocrAvailability: 0.9,
    nikExact: 0.95,
    nameExact: 0.9,
    medianOcrSeconds: 5,
});

const NA = 'not_applicable';
const YES_NO = ['yes', 'no'];

/** Minimal CSV: comma-separated, optional double quotes, "" escapes. */
export function parseCsv(text) {
    const rows = [];
    let row = [];
    let cell = '';
    let quoted = false;
    const src = String(text).replace(/^﻿/, '');
    for (let i = 0; i < src.length; i++) {
        const ch = src[i];
        if (quoted) {
            if (ch === '"' && src[i + 1] === '"') { cell += '"'; i++; } else if (ch === '"') { quoted = false; } else { cell += ch; }
        } else if (ch === '"') {
            quoted = true;
        } else if (ch === ',') {
            row.push(cell); cell = '';
        } else if (ch === '\n' || ch === '\r') {
            if (ch === '\r' && src[i + 1] === '\n') i++;
            row.push(cell); cell = '';
            if (row.some((c) => c.trim() !== '')) rows.push(row);
            row = [];
        } else {
            cell += ch;
        }
    }
    row.push(cell);
    if (row.some((c) => c.trim() !== '')) rows.push(row);

    return rows.map((r) => r.map((c) => c.trim()));
}

/**
 * Anything identifier-shaped fails the whole sheet: a run of 6+ digits (a NIK
 * fragment, a phone number), an e-mail address, or a calendar date anywhere but
 * the `date` column (a birth date). Problems name the ROW and COLUMN only —
 * never the offending text.
 */
function identifierProblem(column, value) {
    if (/\d{6,}/.test(value)) return 'digit run';
    if (/@/.test(value)) return 'e-mail address';
    if (column !== 'date' && /\b\d{1,4}[-/.]\d{1,2}[-/.]\d{1,4}\b/.test(value)) return 'date-like value';

    return null;
}

function checkRow(cells, lineNo) {
    const problems = [];
    const r = Object.fromEntries(HEADER.map((h, i) => [h, cells[i] ?? '']));
    const bad = (col, why) => problems.push(`row ${lineNo} column ${col}: ${why}`);

    for (const col of HEADER) {
        const why = identifierProblem(col, r[col]);
        if (why) bad(col, `looks like personal data (${why})`);
    }
    if (!/^\d{1,4}$/.test(r.capture_no)) bad('capture_no', 'must be a capture number');
    if (!/^\d{4}-\d{2}-\d{2}$/.test(r.date)) bad('date', 'must be the capture date Y-m-d');
    if (!/^[A-Za-z0-9_-]{1,40}$/.test(r.device_label)) bad('device_label', 'must be a short device label');
    if (!CONDITIONS.includes(r.condition)) bad('condition', `must be one of ${CONDITIONS.join('|')}`);
    if (!['accepted', 'declined'].includes(r.consent)) bad('consent', 'must be accepted|declined');

    if (r.consent === 'declined') {
        // A decline is counted, nothing else about it is recorded.
        for (const col of HEADER.slice(5, -1)) if (r[col] !== NA) bad(col, `must be ${NA} for a declined capture`);
    } else if (r.consent === 'accepted') {
        if (!YES_NO.includes(r.camera_ok)) bad('camera_ok', 'must be yes|no');
        if (!YES_NO.includes(r.ocr_completed)) bad('ocr_completed', 'must be yes|no');
        if (!YES_NO.includes(r.registration_completed)) bad('registration_completed', 'must be yes|no');
        const read = r.camera_ok === 'yes' && r.ocr_completed === 'yes';
        if (read) {
            if (!/^\d+(\.\d+)?$/.test(r.ocr_seconds)) bad('ocr_seconds', 'must be seconds, e.g. 2.3');
            for (const f of FIELDS) if (!OUTCOMES.includes(r[f])) bad(f, `must be one of ${OUTCOMES.join('|')}`);
            if (!['yes', 'no', 'unknown'].includes(r.card_found)) bad('card_found', 'must be yes|no|unknown');
            if (!YES_NO.includes(r.corners_adjusted)) bad('corners_adjusted', 'must be yes|no');
            if (!/^\d{1,3}$/.test(r.boxes_moved)) bad('boxes_moved', 'must be a count');
            if (!/^\d{1,3}$/.test(r.retries)) bad('retries', 'must be a count');
            if (!retriesValid(r.retries_by_field)) bad('retries_by_field', 'must be none or key:count,…');
        } else {
            for (const col of [...FIELDS, 'ocr_seconds', 'card_found', 'corners_adjusted', 'boxes_moved', 'retries', 'retries_by_field']) {
                if (r[col] !== NA) bad(col, `must be ${NA} when no OCR result was produced`);
            }
        }
    }
    if (r.notes.length > 160) bad('notes', 'too long (160 max)');

    return { row: r, problems };
}

function retriesValid(value) {
    if (value === 'none') return true;

    return value.split(';').join(',').split(',').every((part) => {
        const [key, count] = part.split(':');
        return RETRY_KEYS.includes(key) && /^\d{1,3}$/.test(count ?? '');
    });
}

function parseRetries(value) {
    const out = {};
    if (value === 'none' || value === NA) return out;
    for (const part of value.split(';').join(',').split(',')) {
        const [key, count] = part.split(':');
        out[key] = (out[key] ?? 0) + Number(count);
    }

    return out;
}

/** Nearest-rank percentile, the same rule as tools/ktp-ocr-benchmark/summarize.mjs. */
export function percentile(values, p) {
    if (values.length === 0) return null;
    const sorted = [...values].sort((a, b) => a - b);
    const rank = Math.max(1, Math.ceil((p / 100) * sorted.length));

    return sorted[rank - 1];
}

const rate = (num, den) => (den === 0 ? null : num / den);

/** Validate and summarise a sheet. Throws with row/column-only messages. */
export function summarize(csvText) {
    const rows = parseCsv(csvText);
    if (rows.length === 0) throw new Error('empty sheet');
    const header = rows[0];
    if (header.length !== HEADER.length || header.some((h, i) => h !== HEADER[i])) {
        throw new Error(`header must be exactly: ${HEADER.join(',')}`);
    }
    const problems = [];
    const records = [];
    rows.slice(1).forEach((cells, i) => {
        if (cells.length !== HEADER.length) {
            problems.push(`row ${i + 2}: expected ${HEADER.length} columns, found ${cells.length}`);
            return;
        }
        const { row, problems: p } = checkRow(cells, i + 2);
        problems.push(...p);
        records.push(row);
    });
    const numbers = records.map((r) => r.capture_no);
    if (new Set(numbers).size !== numbers.length) problems.push('capture_no values must be unique');
    if (problems.length > 0) {
        const err = new Error(`sheet refused (${problems.length} problem${problems.length === 1 ? '' : 's'})`);
        err.problems = problems;
        throw err;
    }

    const accepted = records.filter((r) => r.consent === 'accepted');
    const declined = records.length - accepted.length;
    const cameraOk = accepted.filter((r) => r.camera_ok === 'yes');
    const read = cameraOk.filter((r) => r.ocr_completed === 'yes');

    const fields = {};
    for (const f of FIELDS) {
        const counts = Object.fromEntries(OUTCOMES.map((o) => [o, 0]));
        for (const r of read) counts[r[f]] += 1;
        const exact = counts.correct + counts.choice_correct;
        fields[f] = { ...counts, exact_rate: rate(exact, read.length), manual_corrections: counts.corrected + counts.choice_neither };
    }

    const seconds = read.map((r) => Number(r.ocr_seconds));
    const retriesByField = {};
    for (const r of read) {
        for (const [k, v] of Object.entries(parseRetries(r.retries_by_field))) retriesByField[k] = (retriesByField[k] ?? 0) + v;
    }

    const metrics = {
        captures_recorded: records.length,
        consented_captures: accepted.length,
        declined_captures: declined,
        failed_captures: accepted.length - read.length,
        camera_failures: accepted.length - cameraOk.length,
        ocr_failures: cameraOk.length - read.length,
        camera_success_rate: rate(cameraOk.length, accepted.length),
        ocr_availability_rate: rate(read.length, cameraOk.length),
        registration_completed: accepted.filter((r) => r.registration_completed === 'yes').length,
        ocr_seconds_median: percentile(seconds, 50),
        ocr_seconds_p95: percentile(seconds, 95),
        card_found: read.filter((r) => r.card_found === 'yes').length,
        card_not_found: read.filter((r) => r.card_found === 'no').length,
        corners_adjusted: read.filter((r) => r.corners_adjusted === 'yes').length,
        boxes_moved_total: read.reduce((n, r) => n + Number(r.boxes_moved), 0),
        retries_total: read.reduce((n, r) => n + Number(r.retries), 0),
        retries_by_field: retriesByField,
        fields,
    };

    return { metrics, acceptance: evaluate(metrics) };
}

/**
 * Compare with the owner-approved targets. PENDING until enough consented
 * captures exist — a rate over a handful of cards is not evidence.
 */
export function evaluate(m) {
    const nikWrongHighConf = m.fields.nik.wrong_high_conf;
    const checks = [
        { id: 'consented_captures', target: `>= ${TARGETS.consentedCaptures}`, measured: m.consented_captures, pass: m.consented_captures >= TARGETS.consentedCaptures },
        { id: 'camera_success_rate', target: `>= ${TARGETS.cameraSuccess}`, measured: m.camera_success_rate, pass: m.camera_success_rate !== null && m.camera_success_rate >= TARGETS.cameraSuccess },
        { id: 'ocr_availability_rate', target: `>= ${TARGETS.ocrAvailability}`, measured: m.ocr_availability_rate, pass: m.ocr_availability_rate !== null && m.ocr_availability_rate >= TARGETS.ocrAvailability },
        { id: 'nik_exact_rate', target: `>= ${TARGETS.nikExact}`, measured: m.fields.nik.exact_rate, pass: m.fields.nik.exact_rate !== null && m.fields.nik.exact_rate >= TARGETS.nikExact },
        { id: 'name_exact_rate', target: `>= ${TARGETS.nameExact}`, measured: m.fields.name.exact_rate, pass: m.fields.name.exact_rate !== null && m.fields.name.exact_rate >= TARGETS.nameExact },
        { id: 'ocr_seconds_median', target: `<= ${TARGETS.medianOcrSeconds}`, measured: m.ocr_seconds_median, pass: m.ocr_seconds_median !== null && m.ocr_seconds_median <= TARGETS.medianOcrSeconds },
        { id: 'nik_wrong_high_conf', target: '= 0 (any is NO-GO)', measured: nikWrongHighConf, pass: nikWrongHighConf === 0 },
    ];
    const notMeasured = [
        'no_critical_or_high_security_finding', 'no_cross_branch_access', 'no_patient_record_corrupted',
        'no_silent_overwrite', 'operator_confirmation_on_every_ocr_registration',
        'no_unverified_incorrect_identity_saved', 'manual_fallback_never_blocked', 'rollback_demonstrated',
    ];

    let verdict;
    if (nikWrongHighConf > 0) verdict = 'NO_GO';
    else if (m.consented_captures < TARGETS.consentedCaptures) verdict = 'PENDING';
    else verdict = checks.every((c) => c.pass) ? 'METRICS_PASS' : 'NO_GO';

    return { verdict, checks, not_measured_by_sheet: notMeasured, clinical_go_requires_owner: true };
}
