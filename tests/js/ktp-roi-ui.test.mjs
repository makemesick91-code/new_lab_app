// REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — operator verification rules.
// The pure row model behind the UI: what is pre-selected, what a retry may
// change, and what is applied. Fictional values only.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    ROW_DEFS,
    buildRowModel,
    selectedValues,
    chooseAlternative,
    takePending,
    normalizedDelta,
    keyboardBoxChange,
    statusText,
    createSessionGuard,
} from '../../resources/js/ktp-roi-ui.js';
import { ROI_FIELD_KEYS } from '../../resources/js/ktp-roi-ocr.js';

const empty = { ktp_number: '', name: '', date_of_birth: '', gender: '', address: '', occupation: '' };

function parsed(form) {
    const base = {};
    for (const def of ROW_DEFS) {
        if (def.form) base[def.form] = { value: null, status: 'missing', suggest: false, agreement: 'none' };
    }

    return { ok: true, form: { ...base, ...form }, fields: {} };
}

const ok = (value, agreement = 'agree') => ({ value, status: 'ok', suggest: true, agreement });

test('row ids are unique, so a row can always be addressed', () => {
    const ids = ROW_DEFS.map((d) => d.id);
    assert.equal(new Set(ids).size, ids.length);
});

test('every field region has a row, and only patient columns can be applied', () => {
    const rois = ROW_DEFS.filter((d) => d.roi).map((d) => d.roi).sort();
    assert.deepEqual(rois, [...ROI_FIELD_KEYS].sort());
    assert.deepEqual(ROW_DEFS.filter((d) => d.form).map((d) => d.form).sort(),
        ['address', 'date_of_birth', 'gender', 'ktp_number', 'name', 'occupation']);
    for (const def of ROW_DEFS.filter((d) => d.info)) {
        assert.ok(['birth_place', 'religion', 'marital_status'].includes(def.info), 'fields without a column are info only');
    }
});

test('first read pre-selects only clean reads into empty form fields', () => {
    const rows = buildRowModel(parsed({
        name: ok('SITI CONTOH'),
        ktp_number: { value: '7371015708900003', status: 'low_confidence', suggest: false, agreement: 'field_only' },
        occupation: ok('PELAJAR'),
    }), null, { ...empty, occupation: 'GURU' });

    assert.equal(rows.name.checked, true);
    assert.equal(rows.ktp_number.checked, false, 'low confidence is offered, never pre-selected');
    assert.equal(rows.ktp_number.value, '7371015708900003');
    assert.equal(rows.occupation.checked, false, 'a field the operator filled is not pre-selected');
    assert.equal(rows.occupation.replaces, true);
});

test('a disagreement shows both values and selects neither', () => {
    const rows = buildRowModel(parsed({
        occupation: {
            value: null, status: 'conflict', suggest: false, agreement: 'conflict',
            alternatives: [{ source: 'field', value: 'PELAJAR', status: 'ok' }, { source: 'document', value: 'PELAJAR 03-05-2019', status: 'ok' }],
        },
    }), null, empty);

    assert.equal(rows.occupation.value, '');
    assert.equal(rows.occupation.checked, false);
    assert.deepEqual(rows.occupation.suggestion.alternatives.map((a) => a.value), ['PELAJAR', 'PELAJAR 03-05-2019']);
    assert.deepEqual(selectedValues(rows), {});
});

test('choosing an alternative is the operator accepting that value', () => {
    const row = chooseAlternative({ value: '', checked: false, touched: false, pending: null }, 'PELAJAR');
    assert.deepEqual(row, { value: 'PELAJAR', checked: true, touched: true, pending: null });
});

test('a retry never overwrites a value the operator accepted', () => {
    const first = buildRowModel(parsed({ name: ok('SITI CONTOH') }), null, empty);
    assert.equal(first.name.checked, true);
    // The operator confirms the pre-ticked value (ticking the row marks it touched).
    const accepted = { ...first, name: { ...first.name, checked: true, touched: true } };

    const after = buildRowModel(parsed({ name: ok('SITI CONTOHH') }), accepted, empty);
    assert.equal(after.name.value, 'SITI CONTOH', 'the accepted value stays');
    assert.equal(after.name.checked, true);
    assert.equal(after.name.pending, 'SITI CONTOHH', 'the new read is offered beside it');

    const taken = takePending(after.name);
    assert.equal(taken.value, 'SITI CONTOHH');
    assert.equal(taken.pending, null);
});

test('a tick the system set is not an acceptance: a re-read conflict clears it', () => {
    const first = buildRowModel(parsed({ ktp_number: ok('7371015708900003') }), null, empty);
    assert.equal(first.ktp_number.checked, true, 'clean first read is pre-ticked');

    const after = buildRowModel(parsed({
        ktp_number: {
            value: null, status: 'conflict', suggest: false, agreement: 'conflict',
            alternatives: [{ source: 'field', value: '7371015708900003', status: 'ok' }, { source: 'document', value: '7371015708900008', status: 'ok' }],
        },
    }), first, empty);
    assert.equal(after.ktp_number.checked, false);
    assert.equal(after.ktp_number.value, '');
    assert.deepEqual(selectedValues(after), {}, 'nothing the operator did not choose is applied');
});

test('an untouched pre-ticked row takes a changed read unselected', () => {
    const first = buildRowModel(parsed({ name: ok('SITI CONTOH') }), null, empty);
    const after = buildRowModel(parsed({ name: ok('SITI KONTOH') }), first, empty);
    assert.equal(after.name.value, 'SITI KONTOH');
    assert.equal(after.name.checked, false);
});

test('a retry never overwrites a value the operator typed', () => {
    const first = buildRowModel(parsed({ name: { value: 'SITI CONTOH', status: 'low_confidence', suggest: false, agreement: 'agree' } }), null, empty);
    const edited = { ...first, name: { ...first.name, value: 'SITI CONTOH R', touched: true } };

    const after = buildRowModel(parsed({ name: ok('SITI KONTOH') }), edited, empty);
    assert.equal(after.name.value, 'SITI CONTOH R');
    assert.equal(after.name.pending, 'SITI KONTOH');
});

test('an untouched row takes the new read, but unselected', () => {
    const first = buildRowModel(parsed({ name: { value: 'SITI', status: 'low_confidence', suggest: false, agreement: 'field_only' } }), null, empty);
    const after = buildRowModel(parsed({ name: ok('SITI CONTOH') }), first, empty);
    assert.equal(after.name.value, 'SITI CONTOH');
    assert.equal(after.name.checked, false, 'a retried value needs the operator to accept it');
});

test('rows whose suggestion did not change keep their state', () => {
    const first = buildRowModel(parsed({ name: ok('SITI CONTOH'), gender: ok('Female') }), null, empty);
    const unchecked = { ...first, gender: { ...first.gender, checked: false, touched: true } };
    const after = buildRowModel(parsed({ name: ok('SITI CONTOH'), gender: ok('Female') }), unchecked, empty);
    assert.equal(after.gender.checked, false);
    assert.deepEqual(after.name, first.name);
});

test('only checked rows with a value are applied, trimmed', () => {
    const rows = {
        name: { value: '  SITI CONTOH ', checked: true },
        gender: { value: 'Female', checked: false },
        address: { value: '   ', checked: true },
    };
    assert.deepEqual(selectedValues(rows), { name: 'SITI CONTOH' });
    assert.deepEqual(selectedValues(null), {});
});

test('pointer movement is converted with the element size, so zoom and DPR do not matter', () => {
    assert.deepEqual(normalizedDelta(50, 20, { width: 500, height: 200 }), { dx: 0.1, dy: 0.1 });
    assert.deepEqual(normalizedDelta(100, 40, { width: 1000, height: 400 }), { dx: 0.1, dy: 0.1 });
    assert.deepEqual(normalizedDelta(5, 5, { width: 0, height: 0 }), { dx: 0, dy: 0 });
});

test('arrow keys move a box, Shift+arrows resize it, other keys do nothing', () => {
    const box = { x: 0.3, y: 0.3, w: 0.2, h: 0.05 };
    assert.deepEqual(keyboardBoxChange(box, 'ArrowRight', false, 0.01), { x: 0.31, y: 0.3, w: 0.2, h: 0.05 });
    const grown = keyboardBoxChange(box, 'ArrowDown', true, 0.01);
    assert.deepEqual([grown.x, grown.y, grown.w], [0.3, 0.3, 0.2]);
    assert.ok(Math.abs(grown.h - 0.06) < 1e-9);
    assert.equal(keyboardBoxChange(box, 'Enter', false), null);
});

test('status labels cover every status the server can return', () => {
    for (const s of ['ok', 'low_confidence', 'invalid', 'missing', 'ambiguous', 'conflict']) {
        assert.notEqual(statusText(s), s, `${s} has an Indonesian label`);
    }
});

test('the UI writes OCR text only through value/textContent and stores nothing', () => {
    const source = readFileSync(new URL('../../resources/js/ktp-roi-ui.js', import.meta.url), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    for (const sink of ['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'localStorage', 'sessionStorage', 'indexedDB', 'sendBeacon', 'console.']) {
        assert.ok(!source.includes(sink), `ktp-roi-ui.js must not use ${sink}`);
    }
    // The only request it makes is the parse endpoint handed in by the page.
    assert.equal((source.match(/fetch\(/g) ?? []).length, 1);
    assert.ok(source.includes('fetch(deps.parseUrl'));
});

test('closing a session rejects a read that would otherwise never settle', async () => {
    const life = createSessionGuard();
    const never = new Promise(() => {}); // a recognize() whose worker was terminated
    const pending = life.guard(never);
    life.close();
    await assert.rejects(pending, (e) => e === life.CLOSED);
    await assert.rejects(life.guard(Promise.resolve(1)), (e) => e === life.CLOSED, 'nothing new starts after close');
    assert.equal(life.closed, true);
});

test('an open session passes results and errors through unchanged', async () => {
    const life = createSessionGuard();
    assert.equal(await life.guard(Promise.resolve('ok')), 'ok');
    await assert.rejects(life.guard(Promise.reject(new Error('engine'))), /engine/);
    life.close();
    life.close(); // idempotent
    assert.equal(life.closed, true);
});

test('every engine call, page request and worker start in the session is guarded', () => {
    const source = readFileSync(new URL('../../resources/js/ktp-roi-ui.js', import.meta.url), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    for (const call of ['.recognize(', 'fetch(', "import('tesseract.js')", 'blobToImage(blob)', 'res.json(']) {
        const lines = source.split('\n').filter((l) => l.includes(call) && !l.includes('function '));
        assert.ok(lines.length > 0, `${call} is used`);
        for (const line of lines) assert.ok(line.includes('guard('), `${call} must be guarded: ${line.trim()}`);
    }
    assert.ok(source.includes('signal: parseAbort.signal'), 'the parse request is aborted with the session');
});
