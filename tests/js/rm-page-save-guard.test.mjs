/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.1 (rule 243 §3, review MEDIUM-3) —
 * the RM workspace canvas never saves over a stored page whose image did not
 * load, whatever was drawn.
 *
 * The guard is inline in the workspace view (the canvas script is not a
 * bundled module), between `// rm-save-guard:start` and `// rm-save-guard:end`;
 * this reads that exact source, so the test exercises what ships.
 * DoctorPatientScopePageOneRedactionTest pins that the submit handler calls it.
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
