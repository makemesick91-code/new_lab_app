// PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — consent before KTP OCR
// (pilot decision D7) and the identifier-free pilot measurement line.
// Pure helpers are tested directly; the DOM wiring is checked in source order,
// with comments stripped so a sentence in a comment can never satisfy a check.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { CONSENT, consentAllowsOcr, consentPayload } from '../../resources/js/ktp-camera-ocr.js';
import {
    newPilotMetrics,
    recordBoxMoved,
    recordRetry,
    recordFirstRead,
    formatPilotMetrics,
} from '../../resources/js/ktp-roi-ui.js';
import { ROI_FIELD_KEYS } from '../../resources/js/ktp-roi-ocr.js';

const VERSION = 'D7-2026-10-10';

/** Source without line or block comments. */
function code(path) {
    return readFileSync(new URL(path, import.meta.url), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:'"`])\/\/.*$/gm, '$1');
}

// ------------------------------------------------------------- consent --

test('only an explicit yes to an approved wording allows OCR', () => {
    assert.equal(consentAllowsOcr(CONSENT.ACCEPTED, VERSION), true);
    assert.equal(consentAllowsOcr(null, VERSION), false, 'not asked yet is not a yes');
    assert.equal(consentAllowsOcr(undefined, VERSION), false);
    assert.equal(consentAllowsOcr(CONSENT.DECLINED, VERSION), false, 'a no is not a yes');
    assert.equal(consentAllowsOcr('yes', VERSION), false, 'no truthy shortcut');
    assert.equal(consentAllowsOcr(true, VERSION), false);
    assert.equal(consentAllowsOcr(CONSENT.ACCEPTED, ''), false, 'no wording on the page, no yes');
    assert.equal(consentAllowsOcr(CONSENT.ACCEPTED, '   '), false);
    assert.equal(consentAllowsOcr(CONSENT.ACCEPTED, undefined), false);
});

test('the attestation sent to the server exists only after a yes', () => {
    assert.deepEqual(consentPayload(CONSENT.ACCEPTED, ` ${VERSION} `), { consent: true, consent_version: VERSION });
    assert.equal(consentPayload(null, VERSION), null);
    assert.equal(consentPayload(CONSENT.DECLINED, VERSION), null);
    assert.equal(consentPayload(CONSENT.ACCEPTED, ''), null);
});

test('the consent values cannot be reassigned at runtime', () => {
    assert.ok(Object.isFrozen(CONSENT));
    assert.throws(() => {
        'use strict';
        CONSENT.ACCEPTED = 'declined';
    });
});

test('OCR on any image source checks consent before reading anything', () => {
    const src = code('../../resources/js/ktp-camera-ocr.js');
    const runOcr = src.slice(src.indexOf('const runOcr = async'), src.indexOf("$('[data-ktp-apply]')"));
    const gate = runOcr.indexOf('consentAllowsOcr(consent, consentVersion)');
    assert.ok(gate > 0, 'runOcr asks the consent question');
    assert.ok(gate < runOcr.indexOf('resetResults()'), 'before the previous session is torn down');
    assert.ok(gate < runOcr.indexOf("import('./ktp-roi-ui.js')"), 'before the reader is even loaded');
    assert.match(runOcr, /consent: consentPayload\(consent, consentVersion\)/, 'the session carries the attestation');
});

test('the camera never opens without a yes, and a retake keeps the same answer', () => {
    const src = code('../../resources/js/ktp-camera-ocr.js');
    const open = src.slice(src.indexOf("$('[data-ktp-camera-open]')"), src.indexOf("$('[data-ktp-camera-close]')"));
    assert.match(open, /if \(consentAllowsOcr\(consent, consentVersion\)\) openCamera\(\);\s*else askConsent\(\{ camera: true \}\);/);
    const retake = src.slice(src.indexOf("$('[data-ktp-retake]')"), src.indexOf("const cameraWrap = $('[data-ktp-camera]')"));
    assert.ok(retake.length > 0 && retake.length < 400, 'the retake handler was located');
    assert.match(retake, /clearAll\(\{ keepConsent: true \}\)/);
    assert.match(retake, /consentAllowsOcr\(consent, consentVersion\)\) openCamera\(\)/);
    // Clearing the photo may mean a different person: the answer is reset, and
    // a camera opened under the old answer closes with it (live overlay release).
    // The handler's first act after the busy guard is a plain clearAll() (no
    // keepConsent). AUDIT-PATIENT-KTP-ARCHIVE-PERSISTENCE-1 adds a status line
    // after it ("not attached") — the consent property pinned here is unchanged.
    assert.match(src, /clearBtn\?\.addEventListener\('click', \(\) => \{\s*if \(blockedWhileBusy\(\)\) return;\s*clearAll\(\);/);
    assert.match(src, /if \(!keepConsent\) \{\s*consent = null;\s*stopCamera\(\);\s*\}/);
});

test('a new photo cancels an unanswered consent question', () => {
    const src = code('../../resources/js/ktp-camera-ocr.js');
    const reset = src.slice(src.indexOf('const resetResults = () => {'), src.indexOf('const clearAll = '));
    assert.match(reset, /consentNext = null;/);
    assert.match(reset, /consentPanel\?\.classList\.add\('hidden'\);/);
    // runOcr reaches resetResults only AFTER its consent gate, so the reset can
    // never swallow the question it is about to ask.
    const runOcr = src.slice(src.indexOf('const runOcr = async'), src.indexOf("$('[data-ktp-apply]')"));
    const ask = runOcr.indexOf('askConsent({ blob');
    assert.ok(ask > 0, 'runOcr asks the consent question'); // -1 would pass the next line vacuously
    assert.ok(ask < runOcr.indexOf('resetResults()'));
});

test('"agree" refuses to record a yes when the page carries no wording version', () => {
    const src = code('../../resources/js/ktp-camera-ocr.js');
    const accept = src.slice(src.indexOf("$('[data-ktp-consent-accept]')"), src.indexOf("$('[data-ktp-consent-decline]')"));
    assert.ok(accept.indexOf('if (!consentVersion) return;') < accept.indexOf('consent = CONSENT.ACCEPTED'));
});

test('every parse request carries the attestation the session was given', () => {
    const src = code('../../resources/js/ktp-roi-ui.js');
    assert.match(src, /const body = \{ lines: state\.lines, \.\.\.\(deps\.consent \?\? \{\}\) \};/);
});

// ------------------------------------------------------ pilot metrics --

test('a fresh image has no measurement yet, reported as unknown — never as zero time', () => {
    assert.equal(
        formatPilotMetrics(newPilotMetrics()),
        'Catatan pilot (tanpa data pribadi): card_found=unknown; corners_adjusted=no; boxes_moved=0; retries=0; retries_by_field=none; ocr_seconds=unknown',
    );
});

test('counts presses, distinct moved boxes and per-field re-reads', () => {
    const m = newPilotMetrics();
    recordFirstRead(m, { seconds: 2.34, boundary: { found: true }, hybrid: true });
    recordBoxMoved(m, 'name');
    recordBoxMoved(m, 'name');
    recordBoxMoved(m, 'nik');
    recordRetry(m, ['nik']);
    recordRetry(m, ['nik']);
    recordRetry(m, [...ROI_FIELD_KEYS]);
    m.cornerRereads += 1;

    const line = formatPilotMetrics(m);
    assert.match(line, /card_found=yes;/);
    assert.match(line, /corners_adjusted=yes;/);
    assert.match(line, /boxes_moved=2;/, 'the same box moved twice counts once');
    assert.match(line, /retries=3;/, 'one press of "all boxes" is one press');
    assert.match(line, /retries_by_field=nik:3,name:1,/);
    assert.match(line, /ocr_seconds=2\.3$/);
});

test('later re-reads never overwrite the first read time or card detection', () => {
    const m = newPilotMetrics();
    recordFirstRead(m, { seconds: 1.9, boundary: { found: false }, hybrid: true });
    recordFirstRead(m, { seconds: 9.9, boundary: { found: true }, hybrid: true });
    assert.equal(m.firstReadSeconds, 1.9);
    assert.equal(m.cardFound, false);
});

test('the whole-card fallback reports card detection as unknown, not as a guess', () => {
    const m = recordFirstRead(newPilotMetrics(), { seconds: 3, boundary: null, hybrid: false });
    assert.equal(m.cardFound, null);
    assert.match(formatPilotMetrics(m), /card_found=unknown;/);
});

test('a nonsense duration is unknown rather than a fabricated number', () => {
    for (const seconds of [NaN, -1, Infinity]) {
        const m = recordFirstRead(newPilotMetrics(), { seconds, boundary: { found: true }, hybrid: true });
        assert.match(formatPilotMetrics(m), /ocr_seconds=unknown$/);
    }
});

test('the measurement line can only hold counts and field keys — never a KTP value', () => {
    const m = newPilotMetrics();
    // Even if a caller passed something odd, only keys from the field list are printed.
    m.retriesByField = { nik: 1, '7371015708900003': 4 };
    const line = formatPilotMetrics(m);
    assert.doesNotMatch(line, /\d{16}/);
    assert.match(line, /retries_by_field=nik:1;/);
});
