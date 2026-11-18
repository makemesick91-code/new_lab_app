// DMS-RME-COMPREHENSIVE-CLOSURE-1 RM PR-5 (memo M4.2, rule 244 §5.9) — the
// KTP OCR place of birth is a SUGGESTION beside the "Tempat Lahir" field:
// shown only when it passes the server's single-line rule, written as text,
// applied only by the operator's click, never auto-filled, and it adds no
// request, log or storage. The server-rendered slot is pinned by
// tests/Feature/RME/PatientKtpPlaceOfBirthSuggestionTest.php. Fictional values only.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    PLACE_OF_BIRTH_MAX,
    PLACE_OF_BIRTH_PATTERN,
    placeOfBirthSuggestion,
    createPlaceOfBirthSuggestion,
} from '../../resources/js/ktp-camera-ocr.js';

const read = (value, status = 'ok') => ({ ok: true, fields: { birth_place: { value, status, confidence: 91, reasons: [] } }, form: {} });

class FakeClassList {
    constructor(initial = []) {
        this.set = new Set(initial);
    }
    add(...c) {
        c.forEach((x) => this.set.add(x));
    }
    remove(...c) {
        c.forEach((x) => this.set.delete(x));
    }
    toggle(c, force) {
        const on = force === undefined ? !this.set.has(c) : force;
        if (on) this.set.add(c);
        else this.set.delete(c);
        return on;
    }
    contains(c) {
        return this.set.has(c);
    }
}

function fakeNode(extra = {}) {
    const listeners = {};
    return Object.assign(
        {
            textContent: '',
            classList: new FakeClassList(),
            dispatched: [],
            addEventListener(type, fn) {
                (listeners[type] ??= []).push(fn);
            },
            dispatchEvent(event) {
                this.dispatched.push(event.type);
                for (const fn of listeners[event.type] ?? []) fn(event);
                return true;
            },
            fire(type) {
                for (const fn of listeners[type] ?? []) fn({ type });
            },
            // OCR text must never be parsed as HTML: any HTML sink throws.
            set innerHTML(_) {
                throw new Error('innerHTML used for OCR text');
            },
            set outerHTML(_) {
                throw new Error('outerHTML used for OCR text');
            },
            insertAdjacentHTML() {
                throw new Error('insertAdjacentHTML used for OCR text');
            },
        },
        extra,
    );
}

function mountSuggestion({ typed = '' } = {}) {
    const value = fakeNode();
    const use = fakeNode();
    const replaces = fakeNode({ classList: new FakeClassList(['hidden']) });
    const slot = fakeNode({
        classList: new FakeClassList(['hidden']),
        querySelector: (sel) => ({
            '[data-ktp-pob-suggestion-value]': value,
            '[data-ktp-pob-suggestion-use]': use,
            '[data-ktp-pob-suggestion-replaces]': replaces,
        })[sel] ?? null,
    });
    const input = fakeNode({ value: typed });
    let applied = 0;
    const suggestion = createPlaceOfBirthSuggestion(slot, input, () => applied++);

    return { slot, value, use, replaces, input, suggestion, applied: () => applied };
}

const shown = (slot) => !slot.classList.contains('hidden');

/* ---- what is offered ---- */

test('offers a clean or low-confidence read of the place of birth, trimmed', () => {
    assert.equal(placeOfBirthSuggestion(read('MAKASSAR')), 'MAKASSAR');
    assert.equal(placeOfBirthSuggestion(read('  KAB. GOWA ')), 'KAB. GOWA');
    assert.equal(placeOfBirthSuggestion(read('PARE-PARE', 'low_confidence')), 'PARE-PARE', 'offered, never applied without a click');
});

test('offers nothing for a missing, conflicting or unreadable read', () => {
    assert.equal(placeOfBirthSuggestion(null), null);
    assert.equal(placeOfBirthSuggestion({}), null);
    assert.equal(placeOfBirthSuggestion({ fields: {} }), null);
    assert.equal(placeOfBirthSuggestion(read(null, 'missing')), null);
    for (const status of ['conflict', 'invalid', 'missing', 'ambiguous', null, 'OK']) {
        assert.equal(placeOfBirthSuggestion(read('MAKASSAR', status)), null, `status ${status} is never offered`);
    }
    assert.equal(placeOfBirthSuggestion({ fields: { birth_place: { value: 'MAKASSAR' } } }), null, 'a read without a status is never offered');
    for (const value of [42, ['MAKASSAR'], { value: 'MAKASSAR' }, true]) {
        assert.equal(placeOfBirthSuggestion(read(value)), null, 'only text is offered');
    }
});

test('offers only what the server would accept: one line, at most 100 characters, not blank', () => {
    assert.equal(PLACE_OF_BIRTH_MAX, 100);
    for (const bad of ['', '   ', 'MAKA\nSSAR', 'MAKA\rSSAR', 'MAKA\tSSAR', 'MAKA\u0000SSAR', 'MAKA\u001bSSAR', 'MAKA\u007fSSAR', 'MAKA\u0007SSAR']) {
        assert.equal(placeOfBirthSuggestion(read(bad)), null, JSON.stringify(bad));
    }
    assert.equal(placeOfBirthSuggestion(read('A'.repeat(100))), 'A'.repeat(100));
    assert.equal(placeOfBirthSuggestion(read('A'.repeat(101))), null);
    // Characters, not UTF-16 units — the server counts with mb_strlen.
    assert.equal(placeOfBirthSuggestion(read('\u{1D538}'.repeat(100))), '\u{1D538}'.repeat(100));
    assert.equal(placeOfBirthSuggestion(read('\u{1D538}'.repeat(101))), null);
    // Not well-formed text (a lone surrogate) is never offered.
    assert.equal(placeOfBirthSuggestion(read('MAKA\uD800SSAR')), null);
    assert.ok(PLACE_OF_BIRTH_PATTERN.test('Ujung Pandang'));
    assert.ok(!PLACE_OF_BIRTH_PATTERN.test('Ujung\nPandang'));
});

/* ---- how it is shown and applied ---- */

test('nothing is shown before a read, and a read never fills the field by itself', () => {
    const m = mountSuggestion();
    assert.equal(shown(m.slot), false);

    m.suggestion.offer('MAKASSAR');

    assert.equal(shown(m.slot), true);
    assert.equal(m.value.textContent, 'MAKASSAR');
    assert.equal(m.input.value, '', 'never auto-filled');
    assert.deepEqual(m.input.dispatched, []);
    assert.equal(m.applied(), 0);
    assert.equal(m.replaces.classList.contains('hidden'), true, 'an empty field is not "replaced"');
});

test('"Gunakan" writes the suggestion into the field and asks for the KTP attestation', () => {
    const m = mountSuggestion();
    m.suggestion.offer('MAKASSAR');

    m.use.fire('click');

    assert.equal(m.input.value, 'MAKASSAR');
    assert.deepEqual(m.input.dispatched, ['input', 'change'], 'Alpine and other listeners see the change');
    assert.equal(m.applied(), 1, 'the OCR attestation is required like any applied OCR value');
    assert.equal(shown(m.slot), false, 'the field now holds it: nothing left to offer');
});

test('a typed value is never overwritten by a read — only by the operator clicking', () => {
    const m = mountSuggestion({ typed: 'Gowa' });

    m.suggestion.offer('MAKASSAR');

    assert.equal(m.input.value, 'Gowa');
    assert.equal(shown(m.slot), true);
    assert.equal(m.replaces.classList.contains('hidden'), false, 'the operator is told the click replaces the typed value');

    m.suggestion.offer('MAROS'); // a later read (BACA ULANG) only changes the offer
    assert.equal(m.input.value, 'Gowa');
    assert.equal(m.value.textContent, 'MAROS');

    m.use.fire('click');
    assert.equal(m.input.value, 'MAROS');
});

test('the offer follows what the operator types', () => {
    const m = mountSuggestion();
    m.suggestion.offer('MAKASSAR');

    m.input.value = 'makassar ';
    m.input.fire('input');
    assert.equal(shown(m.slot), false, 'the same place (case and spacing aside) is not offered again');

    m.input.value = 'Gowa';
    m.input.fire('input');
    assert.equal(shown(m.slot), true);
    assert.equal(m.replaces.classList.contains('hidden'), false);

    m.input.value = '';
    m.input.fire('input');
    assert.equal(m.replaces.classList.contains('hidden'), true);
});

test('a new photo, a cleared photo or an unusable read withdraws the offer', () => {
    const m = mountSuggestion();
    m.suggestion.offer('MAKASSAR');

    m.suggestion.clear();
    assert.equal(shown(m.slot), false);
    assert.equal(m.value.textContent, '', 'the OCR text leaves the page');
    m.use.fire('click');
    assert.equal(m.input.value, '', 'a withdrawn offer cannot be applied');
    assert.equal(m.applied(), 0);

    m.suggestion.offer('MAKASSAR');
    m.suggestion.offer(null);
    assert.equal(shown(m.slot), false);
    m.suggestion.offer('MAKA\nSSAR'); // defence in depth: the binding re-checks the format
    assert.equal(shown(m.slot), false);
    m.use.fire('click');
    assert.equal(m.input.value, '');
});

test('OCR text is written as text, never as HTML', () => {
    const m = mountSuggestion();
    const hostile = '<img src=x onerror=alert(1)>';

    m.suggestion.offer(placeOfBirthSuggestion(read(hostile))); // the fake node throws on any HTML sink

    assert.equal(m.value.textContent, hostile, 'shown literally');
    m.use.fire('click');
    assert.equal(m.input.value, hostile, 'a form value, still re-validated by the server');
});

test('no slot or no field (an edit page, a test page) is simply inert', () => {
    const none = createPlaceOfBirthSuggestion(null, null, () => assert.fail('never applied'));
    none.offer('MAKASSAR');
    none.clear();

    const slotOnly = mountSuggestion();
    const noField = createPlaceOfBirthSuggestion(slotOnly.slot, null, () => assert.fail('never applied'));
    noField.offer('MAKASSAR');
    assert.equal(shown(slotOnly.slot), false, 'nothing to apply it to: nothing offered');
});

test('offering and applying make no request, log nothing and store nothing', () => {
    const calls = [];
    const saved = { fetch: globalThis.fetch, console: { ...console } };
    globalThis.fetch = (...args) => {
        calls.push(['fetch', args]);
        throw new Error('no request');
    };
    for (const level of ['log', 'info', 'warn', 'error', 'debug', 'trace']) {
        console[level] = (...args) => calls.push([level, args]);
    }
    try {
        const m = mountSuggestion();
        m.suggestion.offer(placeOfBirthSuggestion(read('MAKASSAR')));
        m.use.fire('click');
        m.suggestion.clear();
    } finally {
        globalThis.fetch = saved.fetch;
        Object.assign(console, saved.console);
    }
    assert.deepEqual(calls, []);
});

/* ---- wiring in the real modules (claims about CODE: comments stripped) ---- */

const code = (file) => readFileSync(new URL(`../../resources/js/${file}`, import.meta.url), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:'"`\\])\/\/.*$/gm, '$1');

test('every parse result reaches the page, and only for the photo being read', () => {
    const session = code('ktp-roi-ui.js');
    const page = code('ktp-camera-ocr.js');

    assert.match(session, /state\.parsed = parsed;[\s\S]{0,200}deps\.onParsed\?\.\(parsed\)/, 'the session hands each read (first read and BACA ULANG) to the page');
    assert.match(page, /onParsed: \(parsed\) => \{[^}]*run === ocrRun[^}]*placeOfBirth\.offer\(placeOfBirthSuggestion\(parsed\)\)/);
    assert.match(page, /const resetResults = \(\) => \{[\s\S]{0,400}placeOfBirth\.clear\(\)/, 'a new or cleared photo withdraws the offer');
    assert.match(page, /form\?\.querySelector\('\[data-ktp-pob-suggestion\]'\)/);
    assert.match(page, /field\('place_of_birth'\)/, 'the field is resolved with the form prefix (new_patient[...] on the visit form)');
    assert.doesNotMatch(session, /tidak disimpan\)`/, 'the table no longer calls the place of birth "not saved"');
});

test('the release adds no request, log or storage to the KTP modules', () => {
    const page = code('ktp-camera-ocr.js');
    const session = code('ktp-roi-ui.js');

    // The existing requests: temp upload, scanner health, scanner scan, the
    // scan's data URL; and the session's one parse request.
    assert.equal((page.match(/\bfetch\(/g) ?? []).length, 4);
    assert.equal((session.match(/\bfetch\(/g) ?? []).length, 1);
    for (const [name, src] of [['ktp-camera-ocr.js', page], ['ktp-roi-ui.js', session]]) {
        assert.doesNotMatch(src, /console\.|localStorage|sessionStorage|indexedDB|sendBeacon|XMLHttpRequest|WebSocket/, name);
        assert.doesNotMatch(src, /innerHTML|outerHTML|insertAdjacentHTML/, name);
    }
});
