// AUDIT-PATIENT-KTP-ARCHIVE-PERSISTENCE-1 — the hidden `ktp_scan_token` is the
// only thing the server attaches to the patient on save. It must always name
// the photo the operator is looking at: a cleared or replaced preview may never
// leave an earlier photo (possibly of a DIFFERENT KTP holder) attached.
//
// These tests drive the real initKtpScan() through a minimal fake DOM, so the
// wiring is pinned, not just the helper.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createKtpTokenBinding, initKtpScan } from '../../resources/js/ktp-camera-ocr.js';

class FakeClassList {
    constructor() {
        this.set = new Set();
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

function fakeEl(extra = {}) {
    const listeners = {};
    return Object.assign(
        {
            value: '',
            textContent: '',
            className: '',
            src: '',
            disabled: false,
            dataset: {},
            classList: new FakeClassList(),
            addEventListener(type, fn) {
                (listeners[type] ??= []).push(fn);
            },
            removeAttribute(name) {
                if (name === 'src') this.src = '';
            },
            querySelector() {
                return null;
            },
            scrollIntoView() {},
            async fire(type, event = {}) {
                for (const fn of listeners[type] ?? []) await fn(event);
            },
        },
        extra,
    );
}

function mount({ token = '', carried = false, hidden = false, ocrApplied = false, attested = false } = {}) {
    const form = fakeEl();
    // GOVERNANCE-PATIENT-KTP-NEW-PATIENT-ONLY-1 — the OCR attestation box.
    const attestBox = fakeEl({
        checked: attested,
        focused: false,
        focus() {
            this.focused = true;
        },
    });
    const els = {
        '[data-ktp-status]': fakeEl(),
        '[data-ktp-token]': fakeEl({ value: token }),
        '[data-ktp-preview-wrap]': fakeEl(),
        '[data-ktp-preview]': fakeEl(),
        '[data-ktp-confirm-bar]': fakeEl(),
        '[data-ktp-clear]': fakeEl({ disabled: true }),
        '[data-ktp-scan-btn]': fakeEl(),
        '[data-ktp-manual]': fakeEl(),
        '[data-ktp-ocr-applied]': fakeEl({ value: ocrApplied ? '1' : '0' }),
        '[data-ktp-ocr-verify-wrap]': fakeEl({
            querySelector: (sel) => (sel === 'input[type=checkbox]' ? attestBox : null),
        }),
    };
    if (carried) els['[data-ktp-carried]'] = fakeEl();
    els['[data-ktp-preview-wrap]'].classList.add('hidden');

    const root = fakeEl({
        dataset: {
            csrf: 'csrf',
            uploadUrl: '/upload-temp',
            scanUrl: 'http://agent/scan',
            healthUrl: 'http://agent/health',
            ocrEnabled: '0',
            fieldPrefix: '',
            ocrConsentVersion: '',
        },
        closest: () => form,
        querySelector: (sel) => els[sel] ?? null,
        // null = display:none (the visit form's hidden "Pasien Baru" panel).
        offsetParent: hidden ? null : {},
    });

    initKtpScan(root);

    return { els, form, token: els['[data-ktp-token]'], attestBox };
}

async function submitBlocked(form) {
    let blocked = false;
    await form.fire('submit', { preventDefault: () => (blocked = true) });

    return blocked;
}

function stubFetch({ uploadOk = true, newToken = 'tok-new', uploadGate = null } = {}) {
    const calls = [];
    globalThis.fetch = async (url, init = {}) => {
        calls.push({ url, body: init.body ? JSON.parse(init.body) : null });
        if (url === 'http://agent/scan') {
            return { ok: true, json: async () => ({ ok: true, base64: 'QUJD', mime_type: 'image/jpeg', filename: 'scan.jpg' }) };
        }
        if (url === '/upload-temp') {
            if (uploadGate) await uploadGate;
            return uploadOk
                ? { ok: true, json: async () => ({ ok: true, token: newToken }) }
                : { ok: false, json: async () => ({ ok: false, message: 'Upload gagal' }) };
        }
        throw new Error('unexpected fetch ' + url);
    };

    return calls;
}

globalThis.window = { addEventListener() {} };
globalThis.FileReader = class {
    readAsDataURL(blob) {
        blob.arrayBuffer().then((buf) => {
            this.result = `data:${blob.type};base64,` + Buffer.from(buf).toString('base64');
            this.onload?.();
        });
    }
};

/* ---- the binding itself ---- */

test('detaching removes the token from the form but remembers it as superseded', () => {
    const input = { value: 'tok-A' };
    const tokens = createKtpTokenBinding(input);

    tokens.detach();
    assert.equal(input.value, '', 'a detached photo must not be submitted with the form');
    assert.equal(tokens.replaces(), 'tok-A', 'the next upload still discards the superseded temp image');

    tokens.detach(); // a second clear keeps the remembered token
    assert.equal(tokens.replaces(), 'tok-A');

    tokens.attach('tok-B');
    assert.equal(input.value, 'tok-B');
    assert.equal(tokens.replaces(), 'tok-B', 'once attached, the current token is the one a retake supersedes');
});

test('an empty form names nothing to replace', () => {
    const tokens = createKtpTokenBinding({ value: '' });
    assert.equal(tokens.replaces(), null);
});

/* ---- wiring in the real module ---- */

test('"Hapus Preview" removes the uploaded photo from the form', async () => {
    const { els, token } = mount({ token: 'tok-A', carried: true });

    assert.equal(els['[data-ktp-clear]'].disabled, false, 'a carried photo can be withdrawn');
    await els['[data-ktp-clear]'].fire('click');

    assert.equal(token.value, '', 'a cleared photo must never be attached to the patient being saved');
    assert.ok(els['[data-ktp-carried]'].classList.contains('hidden'), 'the "will be attached" notice is withdrawn too');
});

test('a new scan whose upload fails leaves no earlier photo attached', async () => {
    const { els, token } = mount({ token: 'tok-A' });
    stubFetch({ uploadOk: false });

    await els['[data-ktp-scan-btn]'].fire('click');

    assert.equal(els['[data-ktp-preview]'].src.startsWith('data:image/jpeg'), true, 'the new scan is what the operator sees');
    assert.equal(token.value, '', 'the old photo must not be saved under a preview showing a different image');
});

test('a successful new scan replaces and discards the earlier temp image', async () => {
    const { els, token } = mount({ token: 'tok-A' });
    const calls = stubFetch({ newToken: 'tok-B' });

    await els['[data-ktp-scan-btn]'].fire('click');

    const upload = calls.find((c) => c.url === '/upload-temp');
    assert.equal(upload.body.replaces_token, 'tok-A', 'the server is told to discard the superseded image');
    assert.equal(token.value, 'tok-B');
});

test('choosing a new file (not yet confirmed) detaches the earlier photo', async () => {
    const { els, token } = mount({ token: 'tok-A' });
    const file = new Blob([new Uint8Array([1, 2, 3])], { type: 'image/jpeg' });

    await els['[data-ktp-manual]'].fire('change', { target: { files: [file], value: 'x' } });

    assert.equal(els['[data-ktp-preview]'].src.startsWith('data:image/jpeg'), true);
    assert.equal(token.value, '', 'an unconfirmed photo on screen and a different photo on save is a mismatch');
});

test('after clearing, the next upload still discards the cleared temp image', async () => {
    const { els, token } = mount({ token: 'tok-A' });
    await els['[data-ktp-clear]'].fire('click');
    const calls = stubFetch({ newToken: 'tok-C' });

    await els['[data-ktp-scan-btn]'].fire('click');

    const upload = calls.find((c) => c.url === '/upload-temp');
    assert.equal(upload.body.replaces_token, 'tok-A');
    assert.equal(token.value, 'tok-C');
});

/* ---- review follow-up: late uploads and saving a photo that is not stored ---- */

test('an upload that resolves after its photo was cleared is never attached', () => {
    const input = { value: '' };
    const tokens = createKtpTokenBinding(input);
    const issuedAt = tokens.generation();

    tokens.detach(); // cleared while uploading
    assert.equal(tokens.attach('tok-late', issuedAt), false);
    assert.equal(input.value, '', 'a photo the operator removed must not come back on its own');
    assert.equal(tokens.replaces(), 'tok-late', 'the late image is discarded by the next upload');
});

test('a scan cannot be cleared while it uploads, so the attached photo is the one on screen', async () => {
    const { els, token } = mount({ token: '' });
    let release;
    stubFetch({ newToken: 'tok-scan', uploadGate: new Promise((r) => (release = r)) });

    const scanning = els['[data-ktp-scan-btn]'].fire('click');
    await new Promise((r) => setImmediate(r));
    await els['[data-ktp-clear]'].fire('click'); // refused while busy
    release();
    await scanning;

    assert.equal(token.value, 'tok-scan');
    assert.equal(els['[data-ktp-preview-wrap]'].classList.contains('hidden'), false, 'the scan that is attached is still on screen');
});

test('saving is blocked while an unconfirmed photo is on screen', async () => {
    const { els, form } = mount({ token: 'tok-A' });
    const file = new Blob([new Uint8Array([1, 2, 3])], { type: 'image/jpeg' });
    await els['[data-ktp-manual]'].fire('change', { target: { files: [file], value: 'x' } });

    assert.equal(await submitBlocked(form), true, 'a visible photo that will not be archived must not be saved silently');
    assert.match(els['[data-ktp-status]'].textContent, /belum tersimpan/);
});

test('saving is blocked while a photo is still uploading', async () => {
    const { els, form } = mount();
    let release;
    stubFetch({ uploadGate: new Promise((r) => (release = r)) });

    const scanning = els['[data-ktp-scan-btn]'].fire('click');
    await new Promise((r) => setImmediate(r));
    assert.equal(await submitBlocked(form), true);
    release();
    await scanning;
    assert.equal(await submitBlocked(form), false, 'once stored, the photo on screen is the one archived');
});

test('saving is allowed with no photo, or after "Hapus Preview"', async () => {
    const { els, form } = mount({ token: 'tok-A', carried: true });
    assert.equal(await submitBlocked(form), false, 'a carried, stored photo may be saved');

    await els['[data-ktp-clear]'].fire('click');
    assert.equal(await submitBlocked(form), false, 'no photo on screen, nothing to protect');
});

test('a hidden KTP section never blocks the save', async () => {
    const { els, form } = mount({ hidden: true });
    const file = new Blob([new Uint8Array([1, 2, 3])], { type: 'image/jpeg' });
    await els['[data-ktp-manual]'].fire('change', { target: { files: [file], value: 'x' } });

    assert.equal(await submitBlocked(form), false, 'an existing-patient visit is not held up by the new-patient panel');
});

/* ---- GOVERNANCE-PATIENT-KTP-NEW-PATIENT-ONLY-1: the OCR attestation ----
 * After OCR values are applied, the operator must attest them against the card
 * before a NEW patient is saved. It used to be a native `required` on the box,
 * which browsers enforce even when the box is display:none — so applying OCR in
 * the "Pasien Baru" panel and then switching to "Pasien Terdaftar" silently
 * refused the existing-patient visit ("not focusable", console only). The
 * attestation now lives in the submit guard, which spares a hidden section. */

test('applied but unattested OCR never blocks the save of a hidden KTP section', async () => {
    const { form } = mount({ hidden: true, ocrApplied: true, attested: false });

    assert.equal(await submitBlocked(form), false, 'an existing-patient visit is not held up by OCR left in the new-patient panel');
});

test('a visible section with applied, unattested OCR blocks the save and says why', async () => {
    const { form, els, attestBox } = mount({ ocrApplied: true, attested: false });

    assert.equal(await submitBlocked(form), true);
    assert.match(els['[data-ktp-status]'].textContent, /dicocokkan dengan KTP asli/);
    assert.equal(attestBox.focused, true, 'the operator is taken to the box to tick');
});

test('a visible section with applied and attested OCR saves', async () => {
    const { form } = mount({ ocrApplied: true, attested: true });

    assert.equal(await submitBlocked(form), false);
});

test('a visible section with no OCR applied is never asked to attest', async () => {
    const { form } = mount({ ocrApplied: false, attested: false });

    assert.equal(await submitBlocked(form), false, 'manual registration without OCR stays complete');
});

test('the attestation is never a native required constraint', () => {
    const src = readFileSync(new URL('../../resources/js/ktp-camera-ocr.js', import.meta.url), 'utf8');
    // Claims are about CODE: the module's own comments explain the old defect.
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

    assert.doesNotMatch(code, /\.required\s*=/);
    assert.doesNotMatch(code, /setAttribute\(\s*['"]required/);
    assert.doesNotMatch(code, /toggleAttribute\(\s*['"]required/);
});
