/**
 * REVISION-OVERSIGHT-DASHBOARD-DATE-RANGE-1 (rule 187) — the 60-second
 * refresh of /oversight/dashboard.
 *
 * Evaluates the REAL inline `oversightDashboard` component, extracted from the
 * shipped Blade view, in a sandbox with fetch / timers / document stubbed.
 * Nothing is re-implemented, so the assertions cannot drift from what ships.
 *
 * Claims under test:
 *   - only the NEWEST request may write the cards: an older response that
 *     resolves late is discarded, never shown over a newer one;
 *   - a superseded request never raises the error banner;
 *   - the period label renders the inclusive range (dd/mm/yyyy).
 *
 *     npm run test:js
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';
import { describe, it } from 'node:test';

const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const VIEW = readFileSync(resolve(REPO_ROOT, 'resources/views/oversight/dashboard/index.blade.php'), 'utf8');

const match = VIEW.match(/<script>\s*(function oversightDashboard\([\s\S]*?)\s*<\/script>/);
assert.ok(match, 'the oversightDashboard component must be present in the dashboard view');
const COMPONENT_SOURCE = match[1];

function deferred() {
    let resolveFn;
    let rejectFn;
    const promise = new Promise((res, rej) => { resolveFn = res; rejectFn = rej; });
    return { promise, resolve: resolveFn, reject: rejectFn };
}

const flush = () => new Promise((r) => setImmediate(r));

/** Build the component with a scripted fetch: each call returns the next queued response. */
function makeComponent(initial, queue) {
    const scheduled = [];
    const announced = [];
    class CustomEvent { constructor(type, init) { this.type = type; this.detail = init?.detail; } }
    const sandbox = {
        CustomEvent,
        Intl,
        Number,
        Math,
        String,
        Error,
        AbortController,
        document: { hidden: false, addEventListener() {}, dispatchEvent: (e) => { announced.push(e); return true; } },
        setTimeout: (fn, ms) => { scheduled.push(ms); return scheduled.length; },
        clearTimeout: () => {},
        fetch: () => {
            const next = queue.shift();
            assert.ok(next, 'unexpected extra fetch');
            return next;
        },
    };
    vm.createContext(sandbox);
    vm.runInContext(`${COMPONENT_SOURCE}\nthis.__make = oversightDashboard;`, sandbox);

    return { component: sandbox.__make(initial, '/oversight/dashboard/metrics?start_date=2026-10-12&end_date=2026-10-30'), scheduled, announced };
}

const base = {
    start_date: '2026-10-12', end_date: '2026-10-30', day_count: 19, is_single_day: false,
    totals: { patients: 1 }, branches: [],
};

describe('oversight dashboard refresh', () => {
    it('discards an older response that resolves after a newer one', async () => {
        const oldBody = deferred();
        const newBody = deferred();
        const queue = [
            Promise.resolve({ ok: true, json: () => oldBody.promise }),
            Promise.resolve({ ok: true, json: () => newBody.promise }),
        ];
        const { component } = makeComponent({ ...base }, queue);

        const first = component.refresh();
        await flush();                 // first request is now awaiting its body
        const second = component.refresh();
        await flush();

        newBody.resolve({ ...base, totals: { patients: 222 } });
        await second;
        oldBody.resolve({ ...base, totals: { patients: 111 } });   // late, stale
        await first;

        assert.equal(component.data.totals.patients, 222);
        assert.equal(component.error, false);
    });

    it('does not raise the error banner for a superseded request, but does for the current one', async () => {
        const oldBody = deferred();
        const queue = [
            Promise.resolve({ ok: true, json: () => oldBody.promise }),
            Promise.resolve({ ok: false, status: 500 }),
        ];
        const { component } = makeComponent({ ...base }, queue);

        const first = component.refresh();
        await flush();
        await component.refresh();           // current request fails
        assert.equal(component.error, true);

        component.error = false;
        oldBody.reject(new Error('network'));  // the old one fails late
        await first;
        assert.equal(component.error, false, 'a superseded failure must not flag an error');
        assert.equal(component.data.totals.patients, 1, 'the previous frame is kept');
    });

    it('schedules the next refresh only from the newest request', async () => {
        const oldBody = deferred();
        const queue = [
            Promise.resolve({ ok: true, json: () => oldBody.promise }),
            Promise.resolve({ ok: true, json: async () => ({ ...base }) }),
        ];
        const { component, scheduled } = makeComponent({ ...base }, queue);

        const first = component.refresh();
        await flush();
        await component.refresh();
        const afterCurrent = scheduled.length;
        oldBody.resolve({ ...base });
        await first;

        assert.equal(scheduled.length, afterCurrent, 'a superseded request must not schedule another poll');
        assert.ok(scheduled.every((ms) => ms === 60000));
    });

    it('announces a moved range (default view rolling over at WITA midnight) and stays quiet otherwise', async () => {
        const today = { ...base, start_date: '2026-10-13', end_date: '2026-10-13', is_single_day: true };
        const queue = [
            Promise.resolve({ ok: true, json: async () => ({ ...today }) }),                                          // same day
            Promise.resolve({ ok: true, json: async () => ({ ...today, start_date: '2026-10-14', end_date: '2026-10-14' }) }), // rolled over
        ];
        const { component, announced } = makeComponent({ ...today }, queue);

        await component.refresh();
        assert.equal(announced.length, 0, 'an unchanged range must not touch the pickers');

        await component.refresh();
        assert.equal(announced.length, 1);
        assert.equal(announced[0].type, 'oversight-range-moved');
        assert.deepEqual({ ...announced[0].detail }, { start_date: '2026-10-14', end_date: '2026-10-14' });
    });

    it('renders the inclusive period label', () => {
        const { component } = makeComponent({ ...base }, []);
        assert.equal(component.period(), '12/10/2026 – 30/10/2026');

        component.data = { ...base, start_date: '2026-10-13', end_date: '2026-10-13', is_single_day: true };
        assert.equal(component.period(), '13/10/2026');
    });
});
