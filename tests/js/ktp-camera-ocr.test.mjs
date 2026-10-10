// REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — pure helpers of the KTP capture/OCR module.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    KTP_ASPECT,
    cameraConstraints,
    fitWithin,
    guideCropRect,
    validateSourceFile,
    formInputName,
    ocrAssetConfig,
    toOcrLines,
    initialSelections,
    cameraErrorMessage,
    FORM_FIELDS,
} from '../../resources/js/ktp-camera-ocr.js';

test('prefers the rear camera without demanding it (desktop webcams still work)', () => {
    const c = cameraConstraints();
    assert.equal(c.audio, false);
    assert.deepEqual(c.video.facingMode, { ideal: 'environment' });
    assert.equal(c.video.deviceId, undefined);
});

test('an explicitly switched camera is requested exactly', () => {
    const c = cameraConstraints('cam-2');
    assert.deepEqual(c.video.deviceId, { exact: 'cam-2' });
    assert.equal(c.video.facingMode, undefined);
});

test('compression never upscales and keeps the aspect ratio', () => {
    assert.deepEqual(fitWithin(800, 500), { width: 800, height: 500 });
    assert.deepEqual(fitWithin(4000, 2522), { width: 1600, height: 1009 });
    assert.deepEqual(fitWithin(0, 100), { width: 0, height: 0 });
});

test('the guide crop is a centred ID-1 rectangle inside the frame', () => {
    for (const [w, h] of [[1920, 1080], [1080, 1920], [640, 480]]) {
        const r = guideCropRect(w, h);
        assert.ok(r.x >= 0 && r.y >= 0 && r.x + r.width <= w && r.y + r.height <= h, `${w}x${h} inside frame`);
        assert.ok(Math.abs(r.width / r.height - KTP_ASPECT) < 0.02, `${w}x${h} keeps ID-1 ratio`);
        assert.ok(Math.abs(r.x * 2 + r.width - w) <= 1, `${w}x${h} centred`);
    }
});

test('client file pre-check rejects wrong types and oversize files', () => {
    assert.match(validateSourceFile(null), /Tidak ada/);
    assert.match(validateSourceFile({ type: 'application/pdf', size: 10 }), /Format/);
    assert.match(validateSourceFile({ type: 'image/gif', size: 10 }), /Format/);
    assert.match(validateSourceFile({ type: 'image/jpeg', size: 16 * 1024 * 1024 }), /terlalu besar/);
    assert.equal(validateSourceFile({ type: 'image/jpeg', size: 2 * 1024 * 1024 }), null);
});

test('maps parser fields onto both registration forms', () => {
    assert.equal(formInputName('', 'ktp_number'), 'ktp_number');
    assert.equal(formInputName('new_patient', 'ktp_number'), 'new_patient[ktp_number]');
});

test('OCR assets are always same-origin — never the tesseract.js CDN defaults', () => {
    const c = ocrAssetConfig('/build', 'ocr/tesseract-x');
    for (const key of ['workerPath', 'corePath', 'langPath']) {
        assert.ok(c[key].startsWith('/build/ocr/tesseract-x'), `${key} is local`);
        assert.ok(!/^[a-z]+:|^\/\//i.test(c[key]), `${key} has no scheme/host`);
    }
    assert.throws(() => ocrAssetConfig('https://cdn.jsdelivr.net', 'x'));
    assert.throws(() => ocrAssetConfig('//cdn.example', 'x'));
    assert.throws(() => ocrAssetConfig('/build', ''));
});

test('the bundled module passes explicit local paths to createWorker', () => {
    // Claims about CODE: strip comments first, or the module's own explanation
    // ("never innerHTML") would trip the guard it documents.
    const code = (file) => readFileSync(new URL(`../../resources/js/${file}`, import.meta.url), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:'"`])\/\/.*$/gm, '$1');
    // REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 moved the worker into the
    // lazily loaded OCR session; the page still builds the same-origin config
    // and hands it over, and the session uses nothing else.
    const page = code('ktp-camera-ocr.js');
    const session = code('ktp-roi-ui.js');
    assert.match(page, /assetConfig: ocrAssetConfig\(ds\.ocrBuildBase, OCR_ASSET_DIR\)/);
    assert.match(session, /createWorker\('ind', 1\s*, deps\.assetConfig\)/);
    assert.equal((session.match(/createWorker\(/g) ?? []).length, 1, 'one worker construction, always with the local config');
    for (const [name, src] of [['ktp-camera-ocr.js', page], ['ktp-roi-ui.js', session], ['ktp-roi-ocr.js', code('ktp-roi-ocr.js')]]) {
        assert.doesNotMatch(src, /jsdelivr|unpkg|cdnjs/, `no CDN reference in ${name}`);
        assert.doesNotMatch(src, /innerHTML/, `OCR text is never written as HTML in ${name}`);
    }
});

test('OCR lines are flattened, trimmed, clamped and bounded', () => {
    const data = {
        blocks: [{ paragraphs: [{ lines: [
            { text: '  NIK  :  737 ', confidence: 91.4 },
            { text: '   ', confidence: 99 },
            { text: 'x'.repeat(500), confidence: 140 },
            { text: 'Nama', confidence: 'nope' },
        ] }] }],
    };
    const lines = toOcrLines(data);
    assert.equal(lines.length, 3);
    assert.deepEqual(lines[0], { text: 'NIK : 737', confidence: 91.4 });
    assert.equal(lines[1].text.length, 200);
    assert.equal(lines[1].confidence, 100);
    assert.equal(lines[2].confidence, null);

    const many = { blocks: [{ paragraphs: [{ lines: Array.from({ length: 200 }, () => ({ text: 'a', confidence: 90 })) }] }] };
    assert.equal(toOcrLines(many).length, 60);
    assert.deepEqual(toOcrLines(undefined), []);
});

test('only clean reads into EMPTY fields start ticked; filled fields are never pre-selected', () => {
    const form = {
        ktp_number: { value: '7371015708900003', status: 'ok', suggest: true },
        name: { value: 'SITI', status: 'ok', suggest: true },
        date_of_birth: { value: '1990-08-17', status: 'low_confidence', suggest: false },
        gender: { value: null, status: 'missing', suggest: false },
        address: { value: 'JL. A', status: 'ok', suggest: true },
        occupation: { value: 'GURU', status: 'ok', suggest: true },
    };
    const sel = initialSelections(form, { name: 'Siti Lama', address: '', occupation: 'GURU' });

    assert.equal(sel.ktp_number.checked, true);
    assert.equal(sel.name.checked, false, 'operator-entered name is not replaced by default');
    assert.equal(sel.name.replaces, true);
    assert.equal(sel.date_of_birth.checked, false, 'low confidence is offered, not pre-selected');
    assert.equal(sel.date_of_birth.available, true);
    assert.equal(sel.gender.available, false, 'missing value cannot be applied');
    assert.equal(sel.occupation.checked, false, 'already identical');
    assert.equal(sel.occupation.replaces, false);
    assert.deepEqual(Object.keys(sel), FORM_FIELDS);
});

test('camera failures produce actionable messages', () => {
    assert.match(cameraErrorMessage(null, false), /HTTPS/);
    assert.match(cameraErrorMessage({ name: 'NotAllowedError' }), /Izin kamera ditolak/);
    assert.match(cameraErrorMessage({ name: 'NotFoundError' }), /tidak ditemukan/);
    assert.match(cameraErrorMessage({ name: 'NotReadableError' }), /dipakai aplikasi lain/);
    assert.match(cameraErrorMessage({ name: 'Weird' }), /unggah manual/);
});
