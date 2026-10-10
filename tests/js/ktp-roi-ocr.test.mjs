// REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — field-based KTP OCR pipeline.
// Pure functions only: every pixel input below is synthesized in the test, no
// image fixture and no real KTP data is involved.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    KTP_TEMPLATE,
    FIELD_SPECS,
    ROI_FIELD_KEYS,
    NORMALIZED_WIDTH,
    NORMALIZED_HEIGHT,
    ROI_LIMITS,
    templateBoxes,
    clampBox,
    moveBox,
    resizeBox,
    boxToPixels,
    computeHomography,
    applyHomography,
    warpPerspective,
    orderCorners,
    validCorners,
    detectCardQuad,
    toWordLines,
    anchorTemplate,
    valueStartAfterColon,
    whitenBorder,
    cropGray,
    enhanceContrast,
    binarize,
    scaleGray,
    padGray,
    fieldVariants,
    fieldOcrParams,
    cleanFieldText,
    plausibleFieldText,
    readField,
    runFieldOcr,
} from '../../resources/js/ktp-roi-ocr.js';

/* ------------------------------------------------------------- helpers -- */

function rgba(width, height, fill) {
    const data = new Uint8ClampedArray(width * height * 4);
    for (let i = 0; i < width * height; i++) {
        data.set(fill, i * 4);
    }

    return { width, height, data, channels: 4 };
}

function insideQuad([x, y], quad) {
    let sign = 0;
    for (let i = 0; i < 4; i++) {
        const [x1, y1] = quad[i];
        const [x2, y2] = quad[(i + 1) % 4];
        const cross = (x2 - x1) * (y - y1) - (y2 - y1) * (x - x1);
        if (cross === 0) continue;
        if (sign === 0) sign = Math.sign(cross);
        else if (Math.sign(cross) !== sign) return false;
    }

    return true;
}

/** A light card (with dark "text" bars) drawn as a quad on a dark desk. */
function scene(corners, { width = 640, height = 420, desk = 60, card = 225 } = {}) {
    const img = rgba(width, height, [desk, desk, desk, 255]);
    const H = computeHomography([[0, 0], [1, 0], [1, 1], [0, 1]], corners);
    const Hinv = computeHomography(corners, [[0, 0], [1, 0], [1, 1], [0, 1]]);
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            if (!insideQuad([x, y], corners)) continue;
            const [u, v] = applyHomography(Hinv, x, y);
            const text = u > 0.05 && u < 0.6 && (v * 12) % 1 > 0.55 && v > 0.2 && v < 0.85;
            const tone = text ? 30 : card;
            img.data.set([tone, tone, tone, 255], (y * width + x) * 4);
        }
    }
    assert.ok(H);

    return img;
}

const close = (a, b, tol) => Math.abs(a - b) <= tol;

/* ------------------------------------------------------------ template -- */

test('the template has one value box per field, inside the card and left of the portrait', () => {
    const boxes = templateBoxes();
    assert.deepEqual(Object.keys(boxes).sort(), [...ROI_FIELD_KEYS].sort());
    for (const [key, b] of Object.entries(boxes)) {
        assert.ok(b.x >= 0 && b.y >= 0 && b.x + b.w <= 1 && b.y + b.h <= 1, `${key} inside the card`);
        // The portrait column starts at ~0.745: no text box may reach into it.
        assert.ok(b.x + b.w <= 0.74, `${key} stops before the portrait`);
        // Value boxes start right of the label column (no label text inside).
        assert.ok(b.x >= 0.18, `${key} starts after the labels`);
    }
    assert.equal(KTP_TEMPLATE.version, 'ektp-v1');
});

test('template rows never overlap each other', () => {
    const boxes = Object.values(templateBoxes()).sort((a, b) => a.y - b.y);
    for (let i = 1; i < boxes.length; i++) {
        assert.ok(boxes[i].y >= boxes[i - 1].y + boxes[i - 1].h - 1e-9, 'rows are stacked, not overlapping');
    }
});

test('every field has a field-specific alphabet; the NIK reads digits only', () => {
    assert.equal(FIELD_SPECS.nik.whitelist, '0123456789');
    assert.match(FIELD_SPECS.rt_rw.whitelist, /^[0-9\/]+$/);
    assert.doesNotMatch(FIELD_SPECS.name.whitelist, /[0-9]/, 'a name never contains digits');
    assert.doesNotMatch(FIELD_SPECS.address.whitelist, /[a-z]/, 'KTP values are printed in capitals');
    for (const key of ROI_FIELD_KEYS) {
        const params = fieldOcrParams(key, templateBoxes()[key]);
        assert.equal(params.tessedit_pageseg_mode, '7', `${key} is read as one line`);
        assert.equal(params.preserve_interword_spaces, '1', `${key} keeps word spacing`);
        assert.equal(params.tessedit_char_whitelist, FIELD_SPECS[key].whitelist);
    }
    assert.equal(fieldOcrParams('address', { x: 0.2, y: 0.4, w: 0.5, h: 0.1 }).tessedit_pageseg_mode, '6', 'a tall box is read as a block');
    assert.throws(() => fieldOcrParams('patient_id', null));
});

/* ---------------------------------------------------------- box geometry -- */

test('boxes are clamped inside the card and above a minimum size', () => {
    assert.deepEqual(clampBox({ x: -0.2, y: 1.3, w: 0.5, h: 0.1 }), { x: 0, y: 0.9, w: 0.5, h: 0.1 });
    const tiny = clampBox({ x: 0.5, y: 0.5, w: 0, h: -1 });
    assert.ok(tiny.w >= ROI_LIMITS.minBoxSize && tiny.h >= ROI_LIMITS.minBoxSize);
    const garbage = clampBox({ x: 'a', y: null, w: undefined, h: NaN });
    assert.ok(Object.values(garbage).every(Number.isFinite));
});

test('a dragged box stops at the card edge', () => {
    const box = { x: 0.7, y: 0.8, w: 0.2, h: 0.1 };
    assert.deepEqual(moveBox(box, 0.5, 0.5), { x: 0.8, y: 0.9, w: 0.2, h: 0.1 });
    assert.deepEqual(moveBox(box, -2, -2), { x: 0, y: 0, w: 0.2, h: 0.1 });
});

test('resizing keeps the opposite edge, never inverts and never leaves the card', () => {
    const box = { x: 0.2, y: 0.2, w: 0.3, h: 0.1 };
    const grown = resizeBox(box, 'se', 0.1, 0.05);
    assert.deepEqual([grown.x, grown.y], [0.2, 0.2]);
    assert.ok(close(grown.w, 0.4, 1e-9) && close(grown.h, 0.15, 1e-9));
    const inverted = resizeBox(box, 'w', 0.9, 0);
    assert.ok(inverted.w >= ROI_LIMITS.minBoxSize && close(inverted.x + inverted.w, 0.5, 1e-9), 'left edge stops before the right edge');
    const out = resizeBox(box, 'nw', -1, -1);
    assert.deepEqual([out.x, out.y], [0, 0]);
    assert.ok(close(out.x + out.w, 0.5, 1e-9) && close(out.y + out.h, 0.3, 1e-9));
});

test('normalized boxes map to pixels the same way at any resolution', () => {
    const box = { x: 0.25, y: 0.5, w: 0.5, h: 0.25 };
    assert.deepEqual(boxToPixels(box, 400, 200), { x: 100, y: 100, width: 200, height: 50 });
    // Partial pixels at the far edge are included (rounded outward), so a
    // field is never clipped by a rounding step.
    assert.deepEqual(boxToPixels(box, 1280, 807), { x: 320, y: 403, width: 640, height: 203 });
    const edge = boxToPixels({ x: 0.99, y: 0.99, w: 0.5, h: 0.5 }, 100, 100);
    assert.ok(edge.x + edge.width <= 100 && edge.y + edge.height <= 100, 'never past the image');
});

/* ------------------------------------------------------- perspective -- */

test('the homography maps each corner onto its target', () => {
    const from = [[10, 20], [300, 15], [320, 210], [5, 200]];
    const to = [[0, 0], [100, 0], [100, 63], [0, 63]];
    const H = computeHomography(from, to);
    for (let i = 0; i < 4; i++) {
        const [u, v] = applyHomography(H, ...from[i]);
        assert.ok(close(u, to[i][0], 1e-6) && close(v, to[i][1], 1e-6));
    }
    assert.equal(computeHomography([[0, 0], [1, 1], [2, 2], [3, 3]], to), null, 'collinear corners are refused');
});

test('warping a quad yields the fixed normalized canvas with the card filling it', () => {
    const corners = [[100, 60], [520, 40], [540, 330], [90, 350]];
    const img = scene(corners);
    const card = warpPerspective(img, corners);
    assert.equal(card.width, NORMALIZED_WIDTH);
    assert.equal(card.height, NORMALIZED_HEIGHT);
    // A pixel just inside each card corner is card-coloured, not desk.
    for (const [x, y] of [[8, 8], [NORMALIZED_WIDTH - 9, 8], [8, NORMALIZED_HEIGHT - 9], [NORMALIZED_WIDTH - 9, NORMALIZED_HEIGHT - 9]]) {
        assert.ok(card.data[(y * card.width + x) * 4] > 180, `corner pixel ${x},${y} is card`);
    }
});

test('corners are ordered top-left, top-right, bottom-right, bottom-left from any input order', () => {
    const quad = [[10, 10], [200, 12], [205, 150], [8, 148]];
    for (const shuffled of [[quad[2], quad[0], quad[3], quad[1]], [quad[3], quad[2], quad[1], quad[0]]]) {
        assert.deepEqual(orderCorners(shuffled), quad);
    }
});

test('degenerate, tiny or far-off-image corners are not usable', () => {
    assert.equal(validCorners([[0, 0], [100, 0], [100, 60], [0, 60]], 100, 60), true);
    assert.equal(validCorners([[0, 0], [10, 0], [10, 1], [0, 1]], 100, 60), false, 'too small');
    assert.equal(validCorners([[0, 0], [50, 30], [100, 60], [0, 60]], 100, 60), false, 'collinear');
    assert.equal(validCorners([[0, 0], [400, 0], [400, 60], [0, 60]], 100, 60), false, 'far outside');
    assert.equal(validCorners([[0, 0], [100, 0], [100, 60]], 100, 60), false, 'three corners');
    assert.equal(validCorners('nope', 100, 60), false);
});

/* ----------------------------------------------------- boundary detect -- */

test('finds a rotated card on a desk', () => {
    const corners = [[92, 70], [538, 38], [560, 330], [112, 368]];
    const result = detectCardQuad(scene(corners));
    assert.equal(result.found, true, result.reason);
    result.corners.forEach(([x, y], i) => {
        assert.ok(close(x, corners[i][0], 6) && close(y, corners[i][1], 6), `corner ${i}: ${x},${y} vs ${corners[i]}`);
    });
    assert.ok(result.confidence >= 0.5);
});

test('finds a keystoned card (perspective)', () => {
    const corners = [[120, 55], [530, 55], [565, 360], [85, 360]]; // top edge ~15% shorter
    const result = detectCardQuad(scene(corners));
    assert.equal(result.found, true, result.reason);
    result.corners.forEach(([x, y], i) => assert.ok(close(x, corners[i][0], 6) && close(y, corners[i][1], 6)));
});

test('an extreme keystone is reported as not found rather than guessed', () => {
    // Top edge 65% of the bottom: outside the supported range; the operator
    // places the corners by hand instead.
    const result = detectCardQuad(scene([[150, 50], [490, 50], [580, 360], [60, 360]]));
    assert.equal(result.found, false);
});

test('reports "not found" and keeps the whole image when there is no card edge', () => {
    const blank = rgba(640, 420, [200, 200, 200, 255]);
    const r = detectCardQuad(blank);
    assert.equal(r.found, false);
    assert.deepEqual(r.corners, [[0, 0], [639, 0], [639, 419], [0, 419]], 'falls back to the full image, never a guessed crop');

    // A scan whose card fills the whole frame has no border to find either.
    const filled = scene([[0, 0], [639, 0], [639, 419], [0, 419]]);
    assert.equal(detectCardQuad(filled).found, false);
});

test('refuses images that are too small or too large to process', () => {
    assert.equal(detectCardQuad(rgba(20, 20, [0, 0, 0, 255])).reason, 'image_too_small');
    assert.equal(detectCardQuad({ width: 5000, height: 5000, data: new Uint8ClampedArray(4) }).reason, 'image_too_large');
});

/* ------------------------------------------------------- anchoring -- */

function wordLine(text, y, x0 = 0.03) {
    const words = [];
    let x = x0;
    for (const t of text.split(' ')) {
        const w = t.length * 0.012;
        words.push({ text: t, x0: x, x1: x + w, y0: y - 0.012, y1: y + 0.012 });
        x += w + 0.01;
    }

    return words;
}

test('snaps the template to labels found lower on the card', () => {
    const shift = 0.03;
    const lines = [
        wordLine('NIK : 7371015708900003', 0.181 + shift),
        wordLine('NAMA : CONTOH', 0.257 + shift),
        wordLine('AGAMA : ISLAM', 0.629 + shift),
        wordLine('PEKERJAAN : PELAJAR', 0.736 + shift),
    ];
    const { boxes, anchors, fit } = anchorTemplate(lines);
    assert.equal(anchors, 4);
    assert.equal(fit.method, 'fitted');
    const base = templateBoxes();
    for (const key of ['name', 'gender', 'occupation']) {
        assert.ok(close(boxes[key].y, base[key].y + shift, 0.004), `${key} moved down with the card`);
    }
});

test('keeps the template when no label was read', () => {
    const { boxes, fit } = anchorTemplate([wordLine('XXXX YYYY', 0.5)]);
    assert.equal(fit.method, 'template');
    assert.deepEqual(boxes, templateBoxes());
});

test('ignores a card-edge sliver read in front of the label', () => {
    const line = [{ text: '—', x0: 0, x1: 0.001, y0: 0.35, y1: 0.37 }, ...wordLine('JENIS KELAMIN : LAKI-LAKI', 0.363)];
    const { anchors } = anchorTemplate([line]);
    assert.equal(anchors, 1);
});

test('rejects an outlier anchor instead of bending the fit', () => {
    const lines = [
        wordLine('NIK : 1', 0.181),
        wordLine('NAMA : A', 0.257),
        wordLine('AGAMA : B', 0.629),
        wordLine('PEKERJAAN : C', 0.736),
        wordLine('STATUS : D', 0.30), // misread position
    ];
    const { boxes } = anchorTemplate(lines);
    assert.ok(close(boxes.occupation.y, templateBoxes().occupation.y, 0.004));
});

test('finds where the value starts after the colon, however OCR split it', () => {
    const own = valueStartAfterColon([{ text: 'NAMA', x0: 0.03, x1: 0.09 }, { text: ':', x0: 0.22, x1: 0.222 }, { text: 'BUDI', x0: 0.236, x1: 0.28 }]);
    assert.equal(own.x, 0.236);
    const label = valueStartAfterColon([{ text: 'BERLAKU', x0: 0.03, x1: 0.1 }, { text: 'HINGGA:', x0: 0.11, x1: 0.22 }, { text: 'SEUMUR', x0: 0.235, x1: 0.33 }]);
    assert.equal(label.x, 0.235);
    const glued = valueStartAfterColon([{ text: 'NIK', x0: 0.03, x1: 0.07 }, { text: ':7371', x0: 0.2, x1: 0.25 }]);
    assert.ok(close(glued.x, 0.21, 1e-9), 'one character in');
    assert.equal(valueStartAfterColon([{ text: 'NAMA', x0: 0.03, x1: 0.09 }, { text: 'BUDI', x0: 0.2, x1: 0.3 }]), null);
});

test('reads word boxes into normalized coordinates', () => {
    const data = { blocks: [{ paragraphs: [{ lines: [{ words: [
        { text: 'nama', bbox: { x0: 128, y0: 80, x1: 256, y1: 100 } },
        { text: '   ', bbox: { x0: 0, y0: 0, x1: 1, y1: 1 } },
    ] }] }] }] };
    assert.deepEqual(toWordLines(data, 1280, 800), [[{ text: 'NAMA', x0: 0.1, x1: 0.2, y0: 0.1, y1: 0.125 }]]);
    assert.deepEqual(toWordLines(null, 1, 1), []);
});

/* ------------------------------------------------------ preprocessing -- */

test('white border covers the card edge only', () => {
    const card = whitenBorder(rgba(200, 100, [0, 0, 0, 255]), 0.05);
    assert.equal(card.data[0], 255);
    assert.equal(card.data[((50 * 200) + 100) * 4], 0, 'the interior is untouched');
});

test('crops, upscales, stretches and thresholds a field without changing its shape', () => {
    const card = rgba(1280, 807, [210, 225, 240, 255]);
    const box = { x: 0.25, y: 0.25, w: 0.4, h: 0.048 };
    const crop = cropGray(card, box);
    assert.deepEqual([crop.width, crop.height], [512, 40]);
    const big = scaleGray(crop, 2);
    assert.deepEqual([big.width, big.height], [1024, 80]);
    const stretched = enhanceContrast(big);
    assert.equal(stretched.data.length, big.data.length);
    const bin = binarize(stretched);
    assert.ok(bin.data.every((v) => v === 0 || v === 255));
    const padded = padGray(bin, 5);
    assert.deepEqual([padded.width, padded.height], [1034, 90]);
    const variants = fieldVariants(card, box);
    assert.deepEqual(variants.map((v) => v.name), ['enhanced', 'binarized']);
    assert.ok(variants.every((v) => v.image.channels === 1));
});

test('field text is bounded, single-line, and only separator debris is trimmed', () => {
    assert.equal(cleanFieldText(': BUDI SANTOSO |'), 'BUDI SANTOSO');
    assert.equal(cleanFieldText('JL. MAWAR\nNO. 12'), 'JL. MAWAR NO. 12');
    assert.equal(cleanFieldText('X'.repeat(500)).length, 200);
    assert.equal(cleanFieldText(null), '');
    // Nothing is ever added: the result is always a substring of the input.
    for (const raw of ['- ISLAM -', 'NO. 7.', ' 003/005 ']) {
        assert.ok(raw.replace(/\s+/g, ' ').includes(cleanFieldText(raw)));
    }
});

test('plausibility is a cheap re-read trigger, not validation', () => {
    assert.equal(plausibleFieldText('nik', '7371015708900003'), true);
    assert.equal(plausibleFieldText('nik', '737101570890000'), false);
    assert.equal(plausibleFieldText('rt_rw', '003/005'), true);
    assert.equal(plausibleFieldText('birth_place_date', 'MAKASSAR, 17-08-1990'), true);
    assert.equal(plausibleFieldText('gender', 'LAKI-LAKI'), true);
    assert.equal(plausibleFieldText('name', '.'), false);
});

/* ------------------------------------------------------ orchestration -- */

const ocrData = (text, confidence) => ({ blocks: [{ paragraphs: [{ lines: [{ text, confidence, words: [] }] }] }] });

test('a field is re-read with the second preprocessing only when the first is implausible', async () => {
    const card = rgba(1280, 807, [230, 230, 230, 255]);
    const calls = [];
    const answers = [ocrData('73710157089000', 95), ocrData('7371015708900003', 70)];
    const read = await readField(card, 'nik', templateBoxes().nik, async (img, params) => {
        calls.push(params);
        return answers[calls.length - 1];
    });
    assert.equal(calls.length, 2);
    assert.equal(read.text, '7371015708900003', 'the plausible read wins over the more confident one');
    assert.equal(read.variant, 'binarized');
    assert.ok(calls.every((p) => p.tessedit_char_whitelist === '0123456789'));
});

test('a plausible, confident first read is not read again', async () => {
    const card = rgba(1280, 807, [230, 230, 230, 255]);
    let calls = 0;
    const read = await readField(card, 'name', templateBoxes().name, async () => {
        calls++;
        return ocrData('SITI CONTOH', 93);
    });
    assert.equal(calls, 1);
    assert.equal(read.text, 'SITI CONTOH');
});

test('two plausible passes that disagree drop the score, so neither wins on confidence', async () => {
    const card = rgba(1280, 807, [230, 230, 230, 255]);
    const answers = [ocrData('SITI CONTOH', 76), ocrData('SITI KONTOH', 79)];
    let calls = 0;
    const read = await readField(card, 'name', templateBoxes().name, async () => answers[calls++]);
    assert.equal(calls, 2);
    assert.equal(read.confidence, null, 'the server then treats the read as unconfirmed');
    assert.equal(read.passesDisagree, true);
});

test('two passes that agree keep their score', async () => {
    const card = rgba(1280, 807, [230, 230, 230, 255]);
    const answers = [ocrData('SITI CONTOH', 76), ocrData('SITI  CONTOH', 79)];
    let calls = 0;
    const read = await readField(card, 'name', templateBoxes().name, async () => answers[calls++]);
    assert.equal(calls, 2);
    assert.equal(read.confidence, 79);
    assert.equal(read.passesDisagree, undefined);
});

test('an empty read is null, never an invented value', async () => {
    const card = rgba(1280, 807, [230, 230, 230, 255]);
    const read = await readField(card, 'name', templateBoxes().name, async () => ocrData('  :  ', 10));
    assert.equal(read.text, null);
});

test('the whole-card read runs first with the engine defaults, then one read per field', async () => {
    const img = scene([[92, 70], [538, 38], [560, 330], [112, 368]]);
    const calls = [];
    const out = await runFieldOcr(img, {
        recognize: async (image, params) => {
            calls.push({ params, size: [image.width, image.height] });
            return params === null ? ocrData('NIK : 7371015708900003', 90) : ocrData('X', 0);
        },
    });
    assert.equal(calls[0].params, null, 'whole-card read uses no field parameters');
    assert.deepEqual(calls[0].size, [NORMALIZED_WIDTH, NORMALIZED_HEIGHT], 'on the deskewed card');
    assert.ok(calls.slice(1).every((c) => c.params && c.params.tessedit_char_whitelist), 'every field read is field-specific');
    assert.deepEqual(Object.keys(out.fields).sort(), [...ROI_FIELD_KEYS].sort());
    assert.deepEqual(out.lines, [{ text: 'NIK : 7371015708900003', confidence: 90 }]);
    assert.equal(out.boundary.found, true);
    assert.equal(out.templateVersion, 'ektp-v1');
});

test('operator corners skip detection and operator boxes skip anchoring', async () => {
    const img = rgba(640, 420, [200, 200, 200, 255]);
    const corners = [[10, 10], [600, 12], [610, 400], [12, 405]];
    const boxes = templateBoxes();
    boxes.name = { x: 0.3, y: 0.3, w: 0.3, h: 0.05 };
    const out = await runFieldOcr(img, { recognize: async () => ocrData('A', 50), corners, boxes, fields: ['name'] });
    assert.equal(out.boundary.reason, 'manual');
    assert.deepEqual(out.corners, corners);
    assert.deepEqual(Object.keys(out.fields), ['name']);
    assert.deepEqual(out.boxes.name, boxes.name);
});

test('invalid operator corners are not trusted', async () => {
    const img = rgba(640, 420, [200, 200, 200, 255]);
    const out = await runFieldOcr(img, { recognize: async () => ocrData('', 0), corners: [[0, 0], [1, 1], [2, 2], [3, 3]], fields: [] });
    assert.notEqual(out.boundary.reason, 'manual');
});

test('refuses an image too large to process instead of exhausting memory', async () => {
    await assert.rejects(runFieldOcr({ width: 5000, height: 5000, data: new Uint8ClampedArray(4) }, { recognize: async () => ({}) }));
});

/* ---------------------------------------------------------- privacy -- */

test('the pipeline has no network, storage, logging or HTML sink', () => {
    const source = readFileSync(new URL('../../resources/js/ktp-roi-ocr.js', import.meta.url), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    for (const sink of ['fetch(', 'XMLHttpRequest', 'sendBeacon', 'localStorage', 'sessionStorage', 'indexedDB', 'console.', 'innerHTML', 'document.']) {
        assert.ok(!source.includes(sink), `ktp-roi-ocr.js must not use ${sink}`);
    }
});
