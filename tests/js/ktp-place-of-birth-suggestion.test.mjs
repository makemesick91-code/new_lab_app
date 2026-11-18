// DMS-RME-COMPREHENSIVE-CLOSURE-1 RM PR-5 (memo M4.2, rule 244 §5.9) — the
// KTP OCR place of birth is a SUGGESTION beside the "Tempat Lahir" field:
// shown only when it passes the server's single-line rule, written as text,
// applied only by the operator's click, never auto-filled, and it adds no
// request, log or storage. The server-rendered slot is pinned by
// tests/Feature/RME/PatientKtpPlaceOfBirthSuggestionTest.php. Fictional values only.
//
// RM PR-5 review (LOW-1, LOW-2, INFO-1): any OCR value applied to the form
// ("Terapkan ke Formulir" or "Gunakan") withdraws an earlier attestation tick,
// so the operator re-attests; a low-confidence place is marked "(perlu dicek)";
// the verification table points at "Gunakan" only while the offer is shown.
// Those are driven through the REAL initKtpScan() with a minimal fake DOM and
// a fake OCR session (no tesseract, no image decoding in Node).
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// The page's same-origin OCR asset directory is a build-time constant (vite);
// it must exist before the module loads, so the module is imported after it.
globalThis.__DMS_OCR_ASSET_DIR__ = 'ocr/tesseract-test';
const {
    PLACE_OF_BIRTH_MAX,
    PLACE_OF_BIRTH_PATTERN,
    placeOfBirthSuggestion,
    createPlaceOfBirthSuggestion,
    initKtpScan,
} = await import('../../resources/js/ktp-camera-ocr.js');

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
            // Every listener runs synchronously; the promise settles once async ones finish.
            fire(type, event = {}) {
                return Promise.all((listeners[type] ?? []).map((fn) => fn({ type, ...event })));
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
    const check = fakeNode({ classList: new FakeClassList(['hidden']) });
    const slot = fakeNode({
        classList: new FakeClassList(['hidden']),
        querySelector: (sel) => ({
            '[data-ktp-pob-suggestion-value]': value,
            '[data-ktp-pob-suggestion-check]': check,
            '[data-ktp-pob-suggestion-use]': use,
            '[data-ktp-pob-suggestion-replaces]': replaces,
        })[sel] ?? null,
    });
    const input = fakeNode({ value: typed });
    let applied = 0;
    const suggestion = createPlaceOfBirthSuggestion(slot, input, () => applied++);

    return { slot, value, check, use, replaces, input, suggestion, applied: () => applied };
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
    assert.match(page, /onParsed: \(parsed\) => \{[^}]*run === ocrRun[^}]*placeOfBirth\.offer\(placeOfBirthSuggestion\(parsed\), parsed\?\.fields\?\.birth_place\?\.status\)/);
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

/* ---- review LOW-2: a low-confidence place is marked "(perlu dicek)" ---- */

test('a low-confidence place is offered with "(perlu dicek)"; a clean read is not marked', () => {
    const m = mountSuggestion();

    m.suggestion.offer('PARE-PARE', 'low_confidence');
    assert.equal(shown(m.slot), true);
    assert.equal(m.check.classList.contains('hidden'), false, 'the operator is told to check it against the card');

    m.suggestion.offer('MAKASSAR', 'ok');
    assert.equal(m.check.classList.contains('hidden'), true);

    m.suggestion.offer('PARE-PARE', 'low_confidence');
    m.suggestion.clear();
    assert.equal(m.check.classList.contains('hidden'), true, 'a withdrawn offer carries no marker');

    m.suggestion.offer('PARE-PARE'); // no status given: never marked as checked-clean either way
    assert.equal(m.check.classList.contains('hidden'), true);
});

test('the place shown is the place offered: shown() follows the slot', () => {
    const m = mountSuggestion();
    assert.equal(m.suggestion.shown(), false);
    m.suggestion.offer('MAKASSAR', 'ok');
    assert.equal(m.suggestion.shown(), true);
    m.input.value = 'Makassar';
    m.input.fire('input');
    assert.equal(m.suggestion.shown(), false, 'the field already holds it');
    m.suggestion.offer('A'.repeat(101), 'ok');
    assert.equal(m.suggestion.shown(), false, 'too long for the server: nothing offered');
});

/* ---- the real page wiring: initKtpScan() with a fake OCR session ---- */

globalThis.window ??= { addEventListener() {} };
globalThis.CSS ??= { escape: (s) => String(s).replace(/([[\]"\\])/g, '\\$1') };

const CONSENT_VERSION = 'D7-TEST';

function mountPage({ prefix = '', selections = { name: 'SITI CONTOH' }, place = { value: 'MAKASSAR', status: 'ok' } } = {}) {
    const inputName = (f) => (prefix ? `${prefix}[${f}]` : f);
    const fields = { place_of_birth: fakeNode({ value: '' }), name: fakeNode({ value: '' }) };
    const suggestion = mountSuggestion(); // only its nodes are used: the page binds them itself
    const form = fakeNode({
        querySelector: (sel) => {
            if (sel === '[data-ktp-pob-suggestion]') return suggestion.slot;
            const named = /^\[name="(.+)"\]$/.exec(sel);
            if (!named) return null;
            const wanted = named[1].replace(/\\(.)/g, '$1');
            return Object.entries(fields).find(([f]) => inputName(f) === wanted)?.[1] ?? null;
        },
    });
    const attestBox = fakeNode({ checked: false, focus() {}, scrollIntoView() {} });
    const els = {
        '[data-ktp-status]': fakeNode({ className: '', scrollIntoView() {} }),
        '[data-ktp-token]': fakeNode({ value: '' }),
        '[data-ktp-preview-wrap]': fakeNode({ classList: new FakeClassList(['hidden']) }),
        '[data-ktp-preview]': fakeNode({ src: '', removeAttribute() {} }),
        '[data-ktp-confirm-bar]': fakeNode(),
        '[data-ktp-clear]': fakeNode({ disabled: true }),
        '[data-ktp-scan-btn]': fakeNode({ disabled: false }),
        '[data-ktp-consent]': fakeNode({ classList: new FakeClassList(['hidden']), scrollIntoView() {} }),
        '[data-ktp-consent-accept]': fakeNode(),
        '[data-ktp-ocr-applied]': fakeNode({ value: '0' }),
        '[data-ktp-ocr-verify-wrap]': fakeNode({
            classList: new FakeClassList(['hidden']),
            querySelector: (sel) => (sel === 'input[type=checkbox]' ? attestBox : null),
        }),
        '[data-ktp-apply]': fakeNode(),
        '[data-ktp-ocr-results]': fakeNode(),
        '[data-ktp-ocr-rows]': fakeNode({ replaceChildren() {} }),
    };
    const root = fakeNode({
        dataset: {
            csrf: 'csrf',
            uploadUrl: '/upload-temp',
            parseUrl: '/parse-ocr',
            scanUrl: 'http://agent/scan',
            healthUrl: 'http://agent/health',
            ocrEnabled: '1',
            ocrConsentVersion: CONSENT_VERSION,
            ocrBuildBase: '/build',
            fieldPrefix: prefix,
        },
        closest: () => form,
        querySelector: (sel) => els[sel] ?? null,
        offsetParent: {},
    });

    // A fake OCR session: the read hands the page one parse response, exactly
    // as ktp-roi-ui.js does after its parse request.
    const session = { deps: null, reads: 0, destroyed: 0, loads: 0 };
    let readDone;
    session.done = new Promise((resolve) => (readDone = resolve));
    const loadOcrSession = async () => (session.loads++, {
        createKtpOcrSession(deps) {
            session.deps = deps;
            return {
                async read() {
                    session.reads++;
                    deps.onParsed?.({
                        ok: true,
                        outcome: 'success',
                        form: {},
                        fields: { birth_place: { ...place, confidence: 90, reasons: [] } },
                    });
                    readDone();
                },
                selections: () => ({ ...selections }),
                destroy: () => session.destroyed++,
            };
        },
    });

    globalThis.fetch = async (url) => {
        if (url === 'http://agent/scan') {
            return { ok: true, json: async () => ({ ok: true, base64: 'QUJD', mime_type: 'image/jpeg', filename: 'scan.jpg' }) };
        }
        if (url === '/upload-temp') return { ok: true, json: async () => ({ ok: true, token: 'tok-1' }) };
        if (String(url).startsWith('data:')) return { blob: async () => new Blob(['x'], { type: 'image/jpeg' }) };
        throw new Error('unexpected fetch ' + url);
    };

    initKtpScan(root, { loadOcrSession });

    /** Scan a card, give the D7 "yes", and let the (fake) read finish (bounded: a wiring regression fails, never hangs). */
    const readCard = async () => {
        await els['[data-ktp-scan-btn]'].fire('click');
        await els['[data-ktp-consent-accept]'].fire('click');
        let timer;
        await Promise.race([
            session.done,
            new Promise((_, reject) => (timer = setTimeout(() => reject(new Error('the page never handed the read to the injected OCR session')), 2000))),
        ]).finally(() => clearTimeout(timer));
    };
    const submitBlocked = async () => {
        let blocked = false;
        await form.fire('submit', { preventDefault: () => (blocked = true) });
        return blocked;
    };

    return { els, fields, form, suggestion, attestBox, session, readCard, submitBlocked };
}

test('the test-only session loader is consulted only after the D7 "yes", like the real module', async () => {
    const page = mountPage();
    assert.equal(page.session.loads, 0, 'not at page set-up');

    await page.els['[data-ktp-scan-btn]'].fire('click');
    assert.equal(page.els['[data-ktp-consent]'].classList.contains('hidden'), false, 'the consent question is asked');
    assert.equal(page.session.loads, 0, 'not before the KTP holder answered');

    await page.els['[data-ktp-consent-accept]'].fire('click');
    await page.session.done;
    assert.equal(page.session.loads, 1);
    assert.equal((code('ktp-camera-ocr.js').match(/options\.loadOcrSession/g) ?? []).length, 1, 'one use, inside runOcr');
});

test('a read offers the place beside the field of either form, never filling it', async () => {
    for (const prefix of ['', 'new_patient']) {
        const page = mountPage({ prefix });
        await page.readCard();

        assert.equal(page.session.reads, 1);
        assert.equal(shown(page.suggestion.slot), true, `offered on the ${prefix || 'master'} form`);
        assert.equal(page.suggestion.value.textContent, 'MAKASSAR');
        assert.equal(page.fields.place_of_birth.value, '', 'never auto-filled');
        assert.equal(page.els['[data-ktp-ocr-applied]'].value, '0', 'nothing applied yet: no attestation asked');
    }
});

test('LOW-1: "Gunakan" after an attested "Terapkan" withdraws the tick — the operator re-attests', async () => {
    const page = mountPage();
    await page.readCard();

    await page.els['[data-ktp-apply]'].fire('click');
    assert.equal(page.fields.name.value, 'SITI CONTOH');
    assert.equal(page.els['[data-ktp-ocr-applied]'].value, '1');
    page.attestBox.checked = true; // the operator attests the applied name
    assert.equal(await page.submitBlocked(), false);

    await page.suggestion.use.fire('click');

    assert.equal(page.fields.place_of_birth.value, 'MAKASSAR');
    assert.equal(page.attestBox.checked, false, 'the earlier tick never covers a value applied after it');
    assert.equal(page.els['[data-ktp-ocr-verify-wrap]'].classList.contains('hidden'), false);
    assert.equal(await page.submitBlocked(), true, 'the client guard asks again');

    page.attestBox.checked = true;
    assert.equal(await page.submitBlocked(), false);
});

test('LOW-1: "Terapkan" after an attested "Gunakan" withdraws the tick too', async () => {
    const page = mountPage();
    await page.readCard();

    await page.suggestion.use.fire('click');
    assert.equal(page.fields.place_of_birth.value, 'MAKASSAR');
    assert.equal(page.els['[data-ktp-ocr-applied]'].value, '1', '"Gunakan" requires the attestation');
    page.attestBox.checked = true;

    await page.els['[data-ktp-apply]'].fire('click');

    assert.equal(page.fields.name.value, 'SITI CONTOH');
    assert.equal(page.attestBox.checked, false);
    assert.equal(await page.submitBlocked(), true);
});

test('LOW-1: a "Terapkan" that applies nothing leaves the attestation as it was', async () => {
    const page = mountPage({ selections: {} });
    await page.readCard();
    page.attestBox.checked = true;

    await page.els['[data-ktp-apply]'].fire('click');

    assert.equal(page.attestBox.checked, true);
    assert.equal(page.els['[data-ktp-ocr-applied]'].value, '0');
});

test('LOW-2: the page passes the place\'s own status, so a low-confidence read is marked', async () => {
    const page = mountPage({ place: { value: 'PARE-PARE', status: 'low_confidence' } });
    await page.readCard();

    assert.equal(shown(page.suggestion.slot), true);
    assert.equal(page.suggestion.check.classList.contains('hidden'), false);

    const clean = mountPage();
    await clean.readCard();
    assert.equal(clean.suggestion.check.classList.contains('hidden'), true);
});

test('INFO-1: the session learns whether the offer is actually shown', async () => {
    const page = mountPage();
    await page.readCard();
    assert.equal(page.session.deps.placeOfBirthOffered(), true);

    page.fields.place_of_birth.value = 'makassar';
    page.fields.place_of_birth.fire('input');
    assert.equal(page.session.deps.placeOfBirthOffered(), false, 'the field already holds it');

    const tooLong = mountPage({ place: { value: 'A'.repeat(101), status: 'ok' } });
    await tooLong.readCard();
    assert.equal(tooLong.session.deps.placeOfBirthOffered(), false, 'not offered: the table must not point at "Gunakan"');
});

test('INFO-1: the table names "Gunakan" only while the offer is shown', () => {
    const session = code('ktp-roi-ui.js');
    assert.match(session, /deps\.placeOfBirthOffered\?\.\(\) === true/);
    assert.match(code('ktp-camera-ocr.js'), /placeOfBirthOffered: \(\) => placeOfBirth\.shown\(\)/);
});
