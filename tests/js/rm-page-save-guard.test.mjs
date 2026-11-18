/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.1 (rule 243 §3, review MEDIUM-3) —
 * the RM workspace canvas never saves over a stored page whose image did not
 * load, whatever was drawn.
 *
 * The guard is inline in the workspace view (the canvas script is not a
 * bundled module), between `// rm-save-guard:start` and `// rm-save-guard:end`;
 * this reads that exact source, so the test exercises what ships.
 * DoctorPatientScopePageOneRedactionTest pins that the submit handler calls it.
 * The page-1 helpers (review LOW-A: strokes clipped out of the blank biodata
 * cells, which are tinted; INFO-2: a stale page load never paints) are read the
 * same way from `// rm-page-canvas:start` / `:end`; DPOR-17 pins their wiring.
 *
 * Node's built-in runner, no new dependency:
 *     npm run test:js
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const view = readFileSync(
    fileURLToPath(new URL('../../resources/views/rme/visits/medical-record/show.blade.php', import.meta.url)),
    'utf8',
);
const start = view.indexOf('// rm-save-guard:start');
const end = view.indexOf('// rm-save-guard:end');

assert.ok(start > 0 && end > start, 'the guard markers are in the workspace view');

const rmSaveRefusal = new Function(`${view.slice(start, end)}\nreturn rmSaveRefusal;`)();

const base = { existingSrc: '/rme/handwritings/7/image', baselineLoaded: false, baselineFailed: false, userDrew: false };

test('refuses a stored page whose image failed to load, even with new strokes', () => {
    for (const userDrew of [false, true]) {
        const refusal = rmSaveRefusal({ ...base, baselineFailed: true, userDrew });

        assert.match(refusal, /gagal dimuat/);
    }
});

test('refuses a stored page whose image is still loading, even with new strokes', () => {
    for (const userDrew of [false, true]) {
        const refusal = rmSaveRefusal({ ...base, userDrew });

        assert.match(refusal, /belum dimuat/);
    }
});

test('saves a stored page once its image loaded', () => {
    assert.equal(rmSaveRefusal({ ...base, baselineLoaded: true, userDrew: true }), null);
    assert.equal(rmSaveRefusal({ ...base, baselineLoaded: true }), null);
});

test('refuses an empty new page and saves a drawn one', () => {
    assert.match(rmSaveRefusal({ ...base, existingSrc: '' }), /Belum ada tulisan tangan/);
    assert.equal(rmSaveRefusal({ ...base, existingSrc: '', userDrew: true }), null);
});

/*
 * Rule 243 §3 (review LOW-A, INFO-2) — the page-1 canvas helpers between
 * `// rm-page-canvas:start` and `// rm-page-canvas:end`.
 */
const canvasStart = view.indexOf('// rm-page-canvas:start');
const canvasEnd = view.indexOf('// rm-page-canvas:end');

assert.ok(canvasStart > 0 && canvasEnd > canvasStart, 'the page-1 canvas markers are in the workspace view');

const helpers = new Function(
    `${view.slice(canvasStart, canvasEnd)}\nreturn { RM_HIDDEN_CELL_TINT, rmHiddenCellRects, rmStrokeSegment, rmPageLoadSequence };`,
)();

// The template the view draws (Hotfix 60.5) and the grid the presenter sends.
const TEMPLATE = { headerH: 96, bioRowH: 28, bioRows: 5, bioCols: [0, 150, 470, 620, 900] };
const blank = ['', ''];
const BIO = {
    left: [['Nama', 'A'], ['TTL / Umur', 'B'], ['Pekerjaan', 'C'], blank, blank],
    right: [['Jenis Kelamin', 'D'], blank, blank, blank, ['No. RM', 'E']],
};

/** RmPageOneBiodataRedactor::BLANKED_CELLS as [row, column]. */
const REDACTED_CELLS = [[3, 0], [3, 1], [4, 0], [4, 1], [1, 2], [1, 3], [2, 2], [2, 3], [3, 2], [3, 3]];

function cellOf(rect) {
    return [(rect.y - TEMPLATE.headerH) / TEMPLATE.bioRowH, TEMPLATE.bioCols.indexOf(rect.x)];
}

/** A 2D context that records what was drawn. */
function recordingContext() {
    const calls = [];
    const ctx = {};
    for (const name of ['save', 'restore', 'beginPath', 'rect', 'clip', 'moveTo', 'lineTo', 'stroke']) {
        ctx[name] = (...args) => calls.push([name, ...args]);
    }

    return { ctx, calls };
}

test('the blank page-1 cells are exactly the ten cells the redactor blanks', () => {
    const cells = helpers.rmHiddenCellRects(BIO, TEMPLATE).map(cellOf);
    const sort = (list) => [...list].map((c) => c.join(',')).sort();

    assert.deepEqual(sort(cells), sort(REDACTED_CELLS));
    assert.equal(helpers.rmHiddenCellRects({ left: BIO.left.map(() => ['x', 'y']), right: BIO.right.map(() => ['x', 'y']) }, TEMPLATE).length, 0);
});

test('the tint of the blank cells is no ink to the redactor (luminance >= 200)', () => {
    const hex = helpers.RM_HIDDEN_CELL_TINT;
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));

    assert.ok((299 * r + 587 * g + 114 * b) / 1000 >= 200);
});

test('a page-1 stroke is clipped out of every blank cell, 1 px inside its grid lines', () => {
    const { ctx, calls } = recordingContext();
    const hidden = helpers.rmHiddenCellRects(BIO, TEMPLATE);

    helpers.rmStrokeSegment(ctx, { x: 300, y: 200 }, { x: 320, y: 225 }, hidden, 900, 1273);

    const clipAt = calls.findIndex((c) => c[0] === 'clip');
    const strokeAt = calls.findIndex((c) => c[0] === 'stroke');
    const excluded = calls.filter((c) => c[0] === 'rect').slice(1);

    assert.deepEqual(calls[clipAt], ['clip', 'evenodd']);
    assert.ok(clipAt >= 0 && clipAt < strokeAt, 'the clip is set before the segment is stroked');
    assert.deepEqual(calls.find((c) => c[0] === 'rect'), ['rect', 0, 0, 900, 1273]);
    assert.equal(excluded.length, 10);
    assert.deepEqual(excluded.find((c) => c[1] === 151 && c[2] === 209), ['rect', 151, 209, 318, 26]); // address value
    assert.equal(calls.at(-1)[0], 'restore');
});

test('a stroke on any other page is not clipped', () => {
    const { ctx, calls } = recordingContext();

    helpers.rmStrokeSegment(ctx, { x: 300, y: 200 }, { x: 320, y: 225 }, [], 900, 1273);

    assert.equal(calls.some((c) => c[0] === 'clip'), false);
    assert.deepEqual(calls.filter((c) => ['moveTo', 'lineTo'].includes(c[0])), [['moveTo', 300, 200], ['lineTo', 320, 225]]);
});

test('a page load still in flight never paints onto the page opened after it', () => {
    const begin = helpers.rmPageLoadSequence();
    const painted = [];

    const pageOne = begin();
    const pageOneLoaded = pageOne(() => painted.push('page 1'));
    const pageOneFailed = pageOne(() => painted.push('page 1 failed'));
    const newPage = begin(); // "+ Tambah Halaman RM" opens a blank page
    const newPageLoaded = newPage(() => painted.push('new page'));

    pageOneLoaded();
    pageOneFailed();
    newPageLoaded();

    assert.deepEqual(painted, ['new page']);
});
