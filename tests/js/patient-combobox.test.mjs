/**
 * REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — state-machine tests for the
 * "Kunjungan Baru" patient combobox.
 *
 * These cover the behaviours a PHP feature test structurally cannot reach:
 * request ordering (a slow earlier answer must never replace a newer one),
 * "typed text is not a selection", the minimum-length floor, and the reset that
 * fires when the operator switches to "Pasien Baru".
 *
 * Uses Node's built-in test runner — no new dependency:
 *     npm run test:js
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { createPatientCombobox } from '../../resources/js/patient-combobox.js';

/** A fetch double whose responses are resolved manually, in any order. */
function deferredFetch() {
    const calls = [];

    const fetchImpl = (url) => {
        let resolve;
        const promise = new Promise((r) => {
            resolve = r;
        });

        calls.push({
            url,
            resolveWith: (results) => resolve({ ok: true, json: async () => ({ results }) }),
            reject: (error) => resolve(Promise.reject(error)),
        });

        return promise;
    };

    return { fetchImpl, calls };
}

/**
 * A fetch double that separates the two suspension points a real fetch has:
 * the response (headers) and the body (`response.json()`). The gap between them
 * is where a stale response can still slip through if only the first guard is
 * checked, so it needs its own double.
 */
function twoPhaseFetch() {
    const calls = [];

    const fetchImpl = (url) => {
        let resolveHeaders;
        let resolveBody;
        const headers = new Promise((r) => {
            resolveHeaders = r;
        });
        const body = new Promise((r) => {
            resolveBody = r;
        });

        calls.push({
            url,
            resolveHeaders: () => resolveHeaders({ ok: true, json: () => body }),
            resolveBody: (results) => resolveBody({ results }),
        });

        return headers;
    };

    return { fetchImpl, calls };
}

const tick = () => new Promise((r) => setImmediate(r));

function makeCombobox(overrides = {}) {
    const box = createPatientCombobox({
        endpoint: '/rme/visits/patient-search',
        minLength: 2,
        debounceMs: 0,
        // Timers run immediately so tests stay deterministic; the debounce
        // wiring itself is asserted separately.
        setTimeoutImpl: (fn) => {
            fn();

            return 1;
        },
        clearTimeoutImpl: () => {},
        abortControllerImpl: class {
            constructor() {
                this.signal = {};
                this.aborted = false;
            }

            abort() {
                this.aborted = true;
            }
        },
        ...overrides,
    });

    // Stand in for Alpine's $refs so the change dispatch is observable.
    const changes = [];
    box.$refs = {
        patientId: {
            value: '',
            dispatchEvent: (event) => changes.push({ type: event.type, value: box.selectedId }),
        },
    };
    box.changes = changes;
    box.init();

    return box;
}

const NURBAYA = { id: 41, name: 'Nurbaya', medical_record_number: 'DG-LDK2-2025-8445', branch_label: 'LDK2 — Cabang Landak' };
const NUR_AISYAH = { id: 42, name: 'Nur Aisyah', medical_record_number: 'DG-LDK2-2026-0128', branch_label: 'LDK2 — Cabang Landak' };

describe('patient combobox — selection authority', () => {
    it('sets patient_id only when a returned result is explicitly selected', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');
        calls[0].resolveWith([NURBAYA, NUR_AISYAH]);
        await calls[0] && (await Promise.resolve());
        await new Promise((r) => setImmediate(r));

        // Typing alone never selects, no matter how exact the text is.
        assert.equal(box.selectedId, '');

        box.select(NURBAYA);

        assert.equal(box.selectedId, '41');
        assert.equal(box.query, 'Nurbaya — DG-LDK2-2025-8445');
        assert.equal(box.open, false);
        assert.deepEqual(box.changes.at(-1), { type: 'change', value: '41' });
    });

    it('typing an exact patient name does not select that patient', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('Nurbaya');
        calls[0].resolveWith([NURBAYA]);
        await new Promise((r) => setImmediate(r));

        assert.equal(box.results.length, 1);
        assert.equal(box.selectedId, '', 'text alone must never become a patient_id');
    });

    it('clears patient_id as soon as the operator edits the text again', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');
        calls[0].resolveWith([NURBAYA]);
        await new Promise((r) => setImmediate(r));
        box.select(NURBAYA);
        assert.equal(box.selectedId, '41');

        box.onInput('Nurbay');

        assert.equal(box.selectedId, '', 'a stale patient_id must not survive an edited query');
        assert.deepEqual(box.changes.at(-1), { type: 'change', value: '' });
    });

    it('replaces, never accumulates, when a second patient is selected', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');
        calls[0].resolveWith([NURBAYA, NUR_AISYAH]);
        await new Promise((r) => setImmediate(r));

        box.select(NURBAYA);
        box.select(NUR_AISYAH);

        assert.equal(box.selectedId, '42');
    });

    it('clearing the selection empties patient_id', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');
        calls[0].resolveWith([NURBAYA]);
        await new Promise((r) => setImmediate(r));
        box.select(NURBAYA);

        box.clearSelection();

        assert.equal(box.selectedId, '');
        assert.equal(box.query, '');
        assert.equal(box.results.length, 0);
    });

    it('resetSelection (switch to Pasien Baru) drops the selection and the query', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');
        calls[0].resolveWith([NURBAYA]);
        await new Promise((r) => setImmediate(r));
        box.select(NURBAYA);

        box.resetSelection();

        assert.equal(box.selectedId, '');
        assert.equal(box.query, '');
        assert.deepEqual(box.changes.at(-1), { type: 'change', value: '' });
    });
});

describe('patient combobox — stale response safety', () => {
    it('a slower earlier response cannot overwrite a newer query', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');   // request A
        box.onInput('nurb');  // request B

        assert.equal(calls.length, 2);
        assert.match(calls[0].url, /q=nur$/);
        assert.match(calls[1].url, /q=nurb$/);

        // B lands first, then the stale A arrives late.
        calls[1].resolveWith([NURBAYA]);
        await new Promise((r) => setImmediate(r));
        calls[0].resolveWith([NURBAYA, NUR_AISYAH]);
        await new Promise((r) => setImmediate(r));

        assert.equal(box.results.length, 1, 'the late A response must be discarded');
        assert.equal(box.results[0].id, 41);
        assert.equal(box.query, 'nurb');
    });

    it('aborts the in-flight request when a newer one starts', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');
        const firstController = box._controller;
        box.onInput('nurb');

        assert.equal(firstController.aborted, true);
        calls[0].resolveWith([]);
        calls[1].resolveWith([]);
        await new Promise((r) => setImmediate(r));
    });

    it('discards a response whose BODY arrives after a newer query started', async () => {
        // The headers of request A arrive while A is still the newest request,
        // so the first guard lets it through. The user then types again, and
        // A's body lands afterwards. Only a guard AFTER `response.json()`
        // catches this — a real fetch suspends twice.
        const { fetchImpl, calls } = twoPhaseFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');            // request A
        calls[0].resolveHeaders();     // A's headers arrive; A is still newest
        await tick();                  // runSearch is now awaiting A's json()

        box.onInput('nurb');           // request B — A is now stale
        calls[1].resolveHeaders();
        calls[1].resolveBody([NURBAYA]);
        await tick();
        await tick();

        calls[0].resolveBody([NURBAYA, NUR_AISYAH]); // A's body lands late
        await tick();
        await tick();

        assert.equal(box.query, 'nurb');
        assert.equal(box.results.length, 1, "A's late body must not replace B's results");
        assert.equal(box.results[0].id, 41);
    });

    it('a response that arrives after the box was cleared is discarded', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');
        box.onInput('');
        calls[0].resolveWith([NURBAYA, NUR_AISYAH]);
        await new Promise((r) => setImmediate(r));

        assert.equal(box.results.length, 0);
        assert.equal(box.searched, false);
    });
});

describe('patient combobox — query floor and states', () => {
    it('never queries the server below the minimum length', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('');
        box.onInput('n');

        assert.equal(calls.length, 0, 'an empty or one-character box must not ask for patients');
        assert.equal(box.tooShort, true);
        assert.equal(box.results.length, 0);
    });

    it('shows the empty state only after a real search returned nothing', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('zzz');
        calls[0].resolveWith([]);
        await new Promise((r) => setImmediate(r));

        assert.equal(box.searched, true);
        assert.equal(box.showEmptyState, true);
        assert.equal(box.errored, false);
    });

    it('surfaces a failure without inventing results', async () => {
        const box = makeCombobox({
            fetchImpl: async () => {
                throw new Error('network down');
            },
        });

        box.onInput('nur');
        await new Promise((r) => setImmediate(r));

        assert.equal(box.errored, true);
        assert.equal(box.results.length, 0);
        assert.equal(box.selectedId, '');
    });

    it('debounces keystrokes into a single request', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const timers = [];
        const box = makeCombobox({
            fetchImpl,
            debounceMs: 300,
            setTimeoutImpl: (fn) => {
                timers.push(fn);

                return timers.length;
            },
            clearTimeoutImpl: (id) => {
                timers[id - 1] = null;
            },
        });

        box.onInput('nu');
        box.onInput('nur');
        box.onInput('nurb');

        assert.equal(calls.length, 0, 'nothing fires while the operator is still typing');

        timers.filter(Boolean).forEach((fn) => fn());

        assert.equal(calls.length, 1, 'only the settled query is requested');
        assert.match(calls[0].url, /q=nurb$/);
    });

    it('renders "Nama — Nomor RM" and falls back to the name alone', () => {
        const box = makeCombobox();

        assert.equal(box.resultLabel(NURBAYA), 'Nurbaya — DG-LDK2-2025-8445');
        assert.equal(box.resultLabel({ name: 'Pasien Legacy', medical_record_number: '' }), 'Pasien Legacy');
    });
});

describe('patient combobox — keyboard', () => {
    it('moves through results and selects with Enter', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');
        calls[0].resolveWith([NURBAYA, NUR_AISYAH]);
        await new Promise((r) => setImmediate(r));

        assert.equal(box.activeIndex, 0);

        box.onKeydown({ key: 'ArrowDown', preventDefault() {} });
        assert.equal(box.activeIndex, 1);

        box.onKeydown({ key: 'ArrowUp', preventDefault() {} });
        assert.equal(box.activeIndex, 0);

        box.onKeydown({ key: 'Enter', preventDefault() {} });
        assert.equal(box.selectedId, '41');
    });

    it('Escape closes the dropdown without selecting', async () => {
        const { fetchImpl, calls } = deferredFetch();
        const box = makeCombobox({ fetchImpl });

        box.onInput('nur');
        calls[0].resolveWith([NURBAYA]);
        await new Promise((r) => setImmediate(r));

        box.onKeydown({ key: 'Escape' });

        assert.equal(box.open, false);
        assert.equal(box.selectedId, '');
    });
});

describe('patient combobox — prefill', () => {
    it('restores an authorized server-resolved selection', () => {
        const box = createPatientCombobox({
            endpoint: '/rme/visits/patient-search',
            selected: { id: 41, label: 'Nurbaya — DG-LDK2-2025-8445' },
        });
        box.init();

        assert.equal(box.selectedId, '41');
        assert.equal(box.query, 'Nurbaya — DG-LDK2-2025-8445');
    });

    it('starts empty when the server resolved no authorized selection', () => {
        const box = createPatientCombobox({ endpoint: '/rme/visits/patient-search', selected: null });
        box.init();

        assert.equal(box.selectedId, '');
        assert.equal(box.query, '');
    });
});
