// PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — pilot capture sheet summary.
// Every row below is invented: outcomes only, no card was involved.
import test from 'node:test';
import assert from 'node:assert/strict';
import { HEADER, summarize, evaluate, percentile, parseCsv } from '../../tools/ktp-ocr-pilot/pilot-metrics.mjs';

const H = HEADER.join(',');
const NA = 'not_applicable';
const NA_TAIL = Array(15).fill(NA).join(',');

/** One consented capture that was read. */
function read(no, { seconds = '2.1', nik = 'correct', name = 'correct', retries = '0', byField = 'none', boxes = '0', corners = 'no', card = 'yes', notes = '' } = {}) {
    // A copied retries_by_field holds commas, so it is a quoted CSV cell.
    const field = byField.includes(',') && !byField.startsWith('"') ? `"${byField}"` : byField;
    return [no, '2026-10-12', 'FO-PC-1', 'normal_light', 'accepted', seconds, 'yes', 'yes',
        nik, name, 'correct', 'correct', 'corrected', 'correct', 'yes', card, corners, boxes, retries, field, notes].join(',');
}
const declined = (no) => [no, '2026-10-12', 'FO-PC-1', 'other', 'declined', NA_TAIL, ''].join(',');
const cameraFailed = (no) => [no, '2026-10-12', 'FO-PC-1', 'low_light', 'accepted', NA, 'no', 'no',
    NA, NA, NA, NA, NA, NA, 'yes', NA, NA, NA, NA, NA, ''].join(',');

function sheet(rows) {
    return [H, ...rows].join('\n');
}

test('a sheet with too few consented captures is PENDING, never a GO', () => {
    const { metrics, acceptance } = summarize(sheet([read(1), read(2), declined(3)]));
    assert.equal(metrics.consented_captures, 2);
    assert.equal(metrics.declined_captures, 1);
    assert.equal(acceptance.verdict, 'PENDING');
    assert.equal(acceptance.clinical_go_requires_owner, true);
});

test('an empty sheet (header only) is PENDING with no fabricated rates', () => {
    const { metrics, acceptance } = summarize(H);
    assert.equal(metrics.consented_captures, 0);
    assert.equal(metrics.camera_success_rate, null);
    assert.equal(metrics.fields.nik.exact_rate, null);
    assert.equal(metrics.ocr_seconds_median, null);
    assert.equal(acceptance.verdict, 'PENDING');
});

test('thirty clean consented captures meet the metric targets — still owner-decided', () => {
    const rows = Array.from({ length: 30 }, (_, i) => read(i + 1));
    const { acceptance } = summarize(sheet(rows));
    assert.equal(acceptance.verdict, 'METRICS_PASS');
    assert.ok(acceptance.not_measured_by_sheet.includes('no_cross_branch_access'));
});

test('one confident wrong NIK is NO-GO whatever the sample size', () => {
    const { acceptance } = summarize(sheet([read(1, { nik: 'wrong_high_conf' })]));
    assert.equal(acceptance.verdict, 'NO_GO');
});

test('rates below target are NO-GO once the sample is large enough', () => {
    const rows = Array.from({ length: 30 }, (_, i) => read(i + 1, { name: i < 5 ? 'corrected' : 'correct' }));
    const { metrics, acceptance } = summarize(sheet(rows));
    assert.equal(metrics.fields.name.exact_rate, 25 / 30);
    assert.equal(acceptance.verdict, 'NO_GO');
    assert.equal(acceptance.checks.find((c) => c.id === 'name_exact_rate').pass, false);
});

test('a choice the operator picked correctly counts as an exact read; neither counts as a correction', () => {
    const { metrics } = summarize(sheet([read(1, { nik: 'choice_correct', name: 'choice_neither' })]));
    assert.equal(metrics.fields.nik.exact_rate, 1);
    assert.equal(metrics.fields.name.manual_corrections, 1);
});

test('failed captures, timings, corners, boxes and per-field retries are counted', () => {
    const { metrics } = summarize(sheet([
        read(1, { seconds: '1.0', retries: '3', byField: 'nik:2,name:1', boxes: '2', corners: 'yes' }),
        read(2, { seconds: '3.0', retries: '1', byField: 'nik:1', card: 'no' }),
        read(3, { seconds: '9.0' }),
        cameraFailed(4),
    ]));
    assert.equal(metrics.failed_captures, 1);
    assert.equal(metrics.camera_failures, 1);
    assert.equal(metrics.camera_success_rate, 3 / 4);
    assert.equal(metrics.ocr_seconds_median, 3.0);
    assert.equal(metrics.ocr_seconds_p95, 9.0);
    assert.equal(metrics.corners_adjusted, 1);
    assert.equal(metrics.boxes_moved_total, 2);
    assert.equal(metrics.retries_total, 4);
    assert.deepEqual(metrics.retries_by_field, { nik: 3, name: 1 });
    assert.equal(metrics.card_not_found, 1);
});

test('the measurement line copied from the screen is accepted as written', () => {
    // retries_by_field uses commas, so a copied value must be quoted in CSV.
    const row = read(1, { retries: '3', byField: '"nik:2,name:1"' });
    const { metrics } = summarize(sheet([row]));
    assert.deepEqual(metrics.retries_by_field, { nik: 2, name: 1 });
});

test('anything identifier-shaped refuses the whole sheet, naming only row and column', () => {
    const cases = [
        read(1, { notes: 'NIK 7371015708900003' }),
        read(1, { notes: 'telp 081234567' }),
        read(1, { notes: 'lahir 17-08-1990' }),
        read(1, { notes: 'mail a@b.c' }),
    ];
    for (const row of cases) {
        assert.throws(() => summarize(sheet([row])), (e) => {
            assert.match(e.message, /sheet refused/);
            assert.ok(e.problems.some((p) => /row 2 column notes: looks like personal data/.test(p)));
            assert.ok(e.problems.every((p) => !/7371015708900003|081234567|1990|a@b/.test(p)), 'the offending text is never echoed');
            return true;
        });
    }
});

test('a wrong header, an unknown outcome or a value in a declined row is refused', () => {
    assert.throws(() => summarize('capture_no,name\n1,x'), /header must be exactly/);
    assert.throws(() => summarize(sheet([read(1, { nik: 'mostly_right' })])), (e) => e.problems.some((p) => /column nik/.test(p)));
    const leaky = [9, '2026-10-12', 'FO-PC-1', 'other', 'declined', '2.0', ...Array(14).fill(NA), ''].join(',');
    assert.throws(() => summarize(sheet([leaky])), (e) => e.problems.some((p) => /must be not_applicable for a declined capture/.test(p)));
    assert.throws(() => summarize(sheet([read(1), read(1)])), (e) => e.problems.some((p) => /unique/.test(p)));
});

test('nearest-rank percentiles match the benchmark summariser rule', () => {
    assert.equal(percentile([], 50), null);
    assert.equal(percentile([5], 95), 5);
    assert.equal(percentile([1, 2, 3, 4], 50), 2);
    assert.equal(percentile([1, 2, 3, 4], 95), 4);
});

test('the CSV reader handles quotes and blank lines', () => {
    assert.deepEqual(parseCsv('a,"b,c"\n\n"d""e",f\n'), [['a', 'b,c'], ['d"e', 'f']]);
});

test('the verdict logic itself never upgrades a missing rate to a pass', () => {
    const m = {
        consented_captures: 30, camera_success_rate: null, ocr_availability_rate: 1, ocr_seconds_median: 1,
        fields: { nik: { exact_rate: 1, wrong_high_conf: 0 }, name: { exact_rate: 1 } },
    };
    assert.equal(evaluate(m).verdict, 'NO_GO');
});
