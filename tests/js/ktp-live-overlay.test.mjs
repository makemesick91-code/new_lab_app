// REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — live KTP field overlay (pure).
// Every pixel input below is synthesized in the test; no image fixture and no
// real KTP data is involved.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    LIVE_LIMITS,
    CAPTURE_STATUS,
    INDICATOR_LEVELS,
    GUIDANCE,
    liveTrackingAllowed,
    contentRect,
    isMirroredTransform,
    displayView,
    frameToDisplay,
    displayToFrame,
    cardHomography,
    projectBox,
    overlayGeometry,
    isOrderedConvexQuad,
    assessGeometry,
    measureQuality,
    classifyObservation,
    cornerDrift,
    smoothCorners,
    initialTrackerState,
    reduceTracker,
    guidanceFor,
    planCaptureCrop,
    captureCropFor,
    confirmCaptureGeometry,
    cornersToHint,
    hintToPixelCorners,
    createAnalysisScheduler,
    createTrackingLoop,
    analyzeFrame,
    validateAnalysisRequest,
} from '../../resources/js/ktp-live-overlay.js';
import {
    KTP_TEMPLATE,
    ROI_FIELD_KEYS,
    templateBoxes,
    computeHomography,
    applyHomography,
    runFieldOcr,
    toGray,
} from '../../resources/js/ktp-roi-ocr.js';
import { CONSENT } from '../../resources/js/ktp-camera-ocr.js';

/* ------------------------------------------------------------- helpers -- */

const close = (a, b, eps = 1e-6) => Math.abs(a - b) <= eps;
const closePt = (p, q, eps = 1e-6) => close(p[0], q[0], eps) && close(p[1], q[1], eps);

function rgba(width, height, fill) {
    const data = new Uint8ClampedArray(width * height * 4);
    for (let i = 0; i < width * height; i++) data.set(fill, i * 4);

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

/** A light card with dark "text" bars, drawn as a quad on a dark desk. */
function scene(corners, { width = 640, height = 360, desk = 60, card = 225, text = 30, blur = 0, glare = null } = {}) {
    const img = rgba(width, height, [desk, desk, desk, 255]);
    const Hinv = computeHomography(corners, [[0, 0], [1, 0], [1, 1], [0, 1]]);
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            if (!insideQuad([x, y], corners)) continue;
            const [u, v] = applyHomography(Hinv, x, y);
            const isText = u > 0.05 && u < 0.6 && (v * 12) % 1 > 0.55 && v > 0.2 && v < 0.85;
            let tone = isText ? text : card;
            if (glare && (u - glare.u) ** 2 + (v - glare.v) ** 2 < glare.r ** 2) tone = 255;
            img.data.set([tone, tone, tone, 255], (y * width + x) * 4);
        }
    }
    if (blur > 0) {
        // Box blur, `blur` passes: enough to wipe the text strokes' edges.
        for (let pass = 0; pass < blur; pass++) {
            const src = new Uint8ClampedArray(img.data);
            for (let y = 1; y < height - 1; y++) {
                for (let x = 1; x < width - 1; x++) {
                    for (let c = 0; c < 3; c++) {
                        let s = 0;
                        for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) s += src[((y + dy) * width + x + dx) * 4 + c];
                        img.data[(y * width + x) * 4 + c] = s / 9;
                    }
                }
            }
        }
    }

    return img;
}

/** Centred landscape card covering `fraction` of the frame width. */
function cardQuad(width, height, fraction = 0.8, { tilt = 0, keystone = 0, dx = 0, dy = 0 } = {}) {
    const w = width * fraction;
    const h = w / (85.6 / 53.98);
    const cx = width / 2 + dx;
    const cy = height / 2 + dy;
    const base = [[-w / 2 * (1 - keystone), -h / 2], [w / 2 * (1 - keystone), -h / 2], [w / 2, h / 2], [-w / 2, h / 2]];
    const r = (tilt * Math.PI) / 180;

    return base.map(([x, y]) => [cx + x * Math.cos(r) - y * Math.sin(r), cy + x * Math.sin(r) + y * Math.cos(r)]);
}

const greenObservation = (corners, extra = {}) => ({
    found: true,
    corners,
    geometry: assessGeometry(corners, 1920, 1080),
    quality: { luma: 180, glare: 0, sharpness: 60, samples: 1000 },
    ...extra,
});

/* --------------------------------------------------------- consent gate -- */

test('live tracking is never allowed before the D7 "yes"', () => {
    const version = 'D7-2026-10-10';
    assert.equal(liveTrackingAllowed({ ocrEnabled: true, consent: null, consentVersion: version }), false);
    assert.equal(liveTrackingAllowed({ ocrEnabled: true, consent: CONSENT.DECLINED, consentVersion: version }), false);
    assert.equal(liveTrackingAllowed({ ocrEnabled: true, consent: CONSENT.ACCEPTED, consentVersion: '' }), false);
    assert.equal(liveTrackingAllowed({ ocrEnabled: false, consent: CONSENT.ACCEPTED, consentVersion: version }), false);
    assert.equal(liveTrackingAllowed({ ocrEnabled: true, consent: CONSENT.ACCEPTED, consentVersion: version }), true);
    assert.equal(liveTrackingAllowed({}), false);
});

test('a tracking loop that is not allowed never grabs a frame', async () => {
    let grabs = 0;
    const ticks = [];
    const loop = createTrackingLoop({
        isAllowed: () => false,
        grabFrame: async () => { grabs++; return {}; },
        analyze: async () => ({ found: false }),
        onState: () => {},
        requestTick: (cb) => { ticks.push(cb); return ticks.length; },
        cancelTick: () => {},
        now: () => 0,
    });
    assert.equal(loop.start(), false);
    assert.equal(ticks.length, 0);
    assert.equal(grabs, 0);
});

test('revoking permission mid-session stops the loop before the next frame', async () => {
    let allowed = true;
    let grabs = 0;
    let blocked = 0;
    let t = 0;
    const ticks = [];
    const loop = createTrackingLoop({
        isAllowed: () => allowed,
        grabFrame: async () => { grabs++; return { frame: true }; },
        analyze: async () => ({ found: false, reason: 'edges_not_found' }),
        onState: () => {},
        onBlocked: () => { blocked++; },
        requestTick: (cb) => { ticks.push(cb); return ticks.length; },
        cancelTick: () => {},
        now: () => t,
    });
    assert.equal(loop.start(), true);
    ticks.shift()();
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(grabs, 1);
    allowed = false;
    t = 10_000;
    ticks.shift()();
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(grabs, 1, 'no frame is read once permission is gone');
    assert.equal(blocked, 1);
    assert.equal(loop.running(), false);
    assert.equal(ticks.length, 0, 'no further tick is requested');
});

/* --------------------------------------------------- display mapping -- */

test('content rect letterboxes, covers, fills and scales down like object-fit', () => {
    const contain = contentRect(1920, 1080, 600, 600, 'contain');
    assert.ok(close(contain.sx, 0.3125) && close(contain.sy, 0.3125));
    assert.ok(close(contain.x, 0) && close(contain.y, (600 - 337.5) / 2));

    const cover = contentRect(1920, 1080, 600, 600, 'cover');
    assert.ok(close(cover.sx, 600 / 1080));
    assert.ok(cover.x < 0 && close(cover.y, 0), 'cover crops the sides');

    const fill = contentRect(1920, 1080, 600, 600, 'fill');
    assert.ok(close(fill.sx, 600 / 1920) && close(fill.sy, 600 / 1080));

    const down = contentRect(320, 240, 640, 480, 'scale-down');
    assert.ok(close(down.sx, 1) && close(down.x, 160) && close(down.y, 120), 'never upscaled');

    assert.equal(contentRect(1920, 1080, 600, 600, 'unknown-fit').sx, contain.sx, 'unknown fit behaves like contain');
    assert.equal(contentRect(0, 1080, 600, 600), null);
    assert.equal(contentRect(1920, 1080, NaN, 600), null);
});

test('mirroring is read from the CSS transform determinant, not guessed', () => {
    assert.equal(isMirroredTransform('none'), false);
    assert.equal(isMirroredTransform('matrix(1, 0, 0, 1, 0, 0)'), false);
    assert.equal(isMirroredTransform('matrix(-1, 0, 0, 1, 0, 0)'), true);
    assert.equal(isMirroredTransform('matrix(-1, 0, 0, -1, 0, 0)'), false, '180 degree rotation is not a mirror');
    assert.equal(isMirroredTransform('matrix3d(-1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1)'), true);
    assert.equal(isMirroredTransform('matrix(a, 0, 0, 1, 0, 0)'), false);
    assert.equal(isMirroredTransform(undefined), false);
});

test('frame and display coordinates round-trip through letterbox and mirror', () => {
    for (const mirrored of [false, true]) {
        for (const fit of ['contain', 'cover', 'fill']) {
            const view = displayView({ srcW: 1280, srcH: 720, boxW: 412, boxH: 500, fit, mirrored });
            for (const p of [[0, 0], [1279, 0], [640, 360], [17.5, 701.25]]) {
                assert.ok(closePt(displayToFrame(frameToDisplay(p, view), view), p, 1e-6), `${fit}/${mirrored}`);
            }
        }
    }
    const plain = displayView({ srcW: 1280, srcH: 720, boxW: 640, boxH: 360, fit: 'contain', mirrored: false });
    const mirror = displayView({ srcW: 1280, srcH: 720, boxW: 640, boxH: 360, fit: 'contain', mirrored: true });
    assert.deepEqual(frameToDisplay([100, 50], plain), [50, 25]);
    assert.deepEqual(frameToDisplay([100, 50], mirror), [590, 25], 'a mirrored preview flips x only');
    assert.equal(displayView({ srcW: 0, srcH: 720, boxW: 640, boxH: 360 }), null);
});

test('display mapping is in CSS pixels, so device pixel ratio does not move the overlay', () => {
    // The overlay is laid out from clientWidth/clientHeight (CSS px). A 2x or
    // 3x screen renders the same CSS geometry more sharply; nothing scales.
    const view = displayView({ srcW: 1920, srcH: 1080, boxW: 576, boxH: 324, fit: 'contain', mirrored: false });
    assert.deepEqual(frameToDisplay([960, 540], view), [288, 162]);
});

/* ------------------------------------------------ template projection -- */

test('the overlay reuses the versioned field template — no second ROI coordinate system', () => {
    const corners = [[100, 80], [1100, 80], [1100, 710], [100, 710]];
    const geo = overlayGeometry(corners);
    assert.equal(geo.templateVersion, KTP_TEMPLATE.version);
    assert.deepEqual(geo.fields.map((f) => f.key), [...ROI_FIELD_KEYS]);
    const boxes = templateBoxes();
    for (const field of geo.fields) {
        const b = boxes[field.key];
        assert.ok(closePt(field.quad[0], [100 + b.x * 1000, 80 + b.y * 630], 1e-6), field.key);
        assert.ok(closePt(field.quad[2], [100 + (b.x + b.w) * 1000, 80 + (b.y + b.h) * 630], 1e-6), field.key);
    }
    assert.equal(geo.fields.find((f) => f.key === 'nik').label, 'NIK');
    assert.equal(geo.fields.find((f) => f.key === 'birth_place_date').label, 'TEMPAT/TGL LAHIR');
});

test('field boxes follow perspective: a box mapped to the frame maps back onto the template', () => {
    const corners = cardQuad(1920, 1080, 0.6, { tilt: 6, keystone: 0.1 });
    const H = cardHomography(corners);
    const Hinv = computeHomography(corners, [[0, 0], [1, 0], [1, 1], [0, 1]]);
    const box = templateBoxes().address;
    for (const p of projectBox(H, box)) {
        const [u, v] = applyHomography(Hinv, p[0], p[1]);
        assert.ok(u >= box.x - 1e-6 && u <= box.x + box.w + 1e-6 && v >= box.y - 1e-6 && v <= box.y + box.h + 1e-6);
    }
});

test('a degenerate or malformed quad has no homography', () => {
    assert.equal(cardHomography([[0, 0], [10, 0], [20, 0], [30, 0]]), null, 'collinear');
    assert.equal(cardHomography([[0, 0], [1, 0], [1, NaN], [0, 1]]), null);
    assert.equal(cardHomography([[0, 0], [1, 0], [1, 1]]), null);
    assert.equal(overlayGeometry(null), null);
});

/* ------------------------------------------------------ geometry guard -- */

test('only an ordered convex quad is accepted; a bow-tie is rejected as given', () => {
    assert.equal(isOrderedConvexQuad([[0, 0], [10, 0], [10, 6], [0, 6]]), true);
    assert.equal(isOrderedConvexQuad([[0, 0], [10, 6], [10, 0], [0, 6]]), false, 'self-intersecting order');
    assert.equal(isOrderedConvexQuad([[0, 0], [0, 6], [10, 6], [10, 0]]), false, 'reversed winding');
    assert.equal(isOrderedConvexQuad([[0, 0], [10, 0], [2, 2], [0, 6]]), false, 'reflex vertex');
});

test('geometry assessment rejects what a homography should never be built from', () => {
    const W = 1920;
    const H = 1080;
    assert.equal(assessGeometry(cardQuad(W, H, 0.6), W, H).valid, true);
    assert.equal(assessGeometry([[0, 0], [1, 0], [1, 1]], W, H).reason, 'malformed');
    assert.equal(assessGeometry([[0, 0], [1, 0], [1, Infinity], [0, 1]], W, H).reason, 'malformed');
    const q = cardQuad(W, H, 0.6);
    assert.equal(assessGeometry([q[0], q[2], q[1], q[3]], W, H).reason, 'self_intersecting');
    assert.equal(assessGeometry(cardQuad(W, H, 0.6, { dx: 1500 }), W, H).reason, 'out_of_frame');
    // A card held upright: the long side is vertical.
    const portrait = [[700, 100], [1215, 100], [1215, 917], [700, 917]];
    assert.equal(assessGeometry(portrait, W, H).reason, 'portrait');
    assert.equal(assessGeometry([[400, 300], [1500, 300], [1500, 450], [400, 450]], W, H).reason, 'wrong_shape');
    assert.equal(assessGeometry(cardQuad(W, H, 0.6, { keystone: 0.7 }), W, H).reason, 'extreme_perspective');
    assert.equal(assessGeometry(cardQuad(W, H, 0.6), 0, H).reason, 'bad_frame');
    assert.equal(assessGeometry(cardQuad(W, H, 0.6), 99999, H).reason, 'bad_frame');
});

test('geometry reports clipping, rotation, perspective and text size in source pixels', () => {
    const g = assessGeometry(cardQuad(1920, 1080, 0.6, { tilt: 7, keystone: 0.08 }), 1920, 1080);
    assert.equal(g.valid, true);
    assert.ok(Math.abs(g.rotationDeg - 7) < 0.5);
    assert.ok(g.perspectiveDeg > 1);
    assert.equal(g.clipped, false);
    const big = assessGeometry(cardQuad(1920, 1080, 0.6), 1920, 1080);
    const small = assessGeometry(cardQuad(1280, 720, 0.55), 1280, 720);
    assert.ok(big.textCapPx > small.textCapPx);
    assert.ok(close(big.cardWidthPx, 1152, 1e-6));
    const clipped = assessGeometry(cardQuad(1920, 1080, 0.6, { dx: 450 }), 1920, 1080);
    assert.equal(clipped.valid, true);
    assert.equal(clipped.clipped, true);
});

/* ----------------------------------------------------------- quality -- */

test('quality reads brightness, glare and sharpness from the card only', () => {
    const corners = cardQuad(640, 360, 0.8);
    const sharp = measureQuality(toGray(scene(corners)), corners);
    const blurred = measureQuality(toGray(scene(corners, { blur: 3 })), corners);
    const glare = measureQuality(toGray(scene(corners, { glare: { u: 0.7, v: 0.5, r: 0.25 } })), corners);
    const dark = measureQuality(toGray(scene(corners, { card: 50, text: 10 })), corners);
    assert.ok(sharp.luma > 150 && sharp.glare < 0.01);
    assert.ok(blurred.sharpness < sharp.sharpness / 2, 'blur lowers sharpness');
    assert.ok(glare.glare > LIVE_LIMITS.glareFraction);
    assert.ok(dark.luma < LIVE_LIMITS.darkLuma);
    assert.equal(measureQuality(toGray(scene(corners)), [[0, 0], [1, 0], [1, NaN], [0, 1]]), null);
});

test('classification: no card is RED, a clean steady card is GREEN, each problem is YELLOW', () => {
    const corners = cardQuad(1920, 1080, 0.6);
    assert.deepEqual(classifyObservation({ found: false, reason: 'edges_not_found' }, null), { level: 'red', issues: ['no_card'] });
    assert.deepEqual(classifyObservation({ found: false, reason: 'portrait' }, null).issues, ['rotate_landscape']);
    assert.deepEqual(classifyObservation(greenObservation(corners), 0.001), { level: 'green', issues: [] });

    const yellow = (obs, drift = 0) => classifyObservation(obs, drift);
    assert.ok(yellow(greenObservation(corners), 0.05).issues.includes('moving'));
    assert.ok(yellow(greenObservation(corners, { quality: { luma: 40, glare: 0, sharpness: 60 } })).issues.includes('dark'));
    assert.ok(yellow(greenObservation(corners, { quality: { luma: 180, glare: 0.2, sharpness: 60 } })).issues.includes('glare'));
    assert.ok(yellow(greenObservation(corners, { quality: { luma: 180, glare: 0, sharpness: 1 } })).issues.includes('blurry'));
    assert.ok(yellow(greenObservation(corners, { quality: null })).issues.includes('quality_unknown'));
    const tilted = cardQuad(1920, 1080, 0.6, { tilt: 15 });
    assert.ok(yellow(greenObservation(tilted)).issues.includes('rotated'));
    const smallCard = cardQuad(1280, 720, 0.5);
    const smallObs = { found: true, corners: smallCard, geometry: assessGeometry(smallCard, 1280, 720), quality: { luma: 180, glare: 0, sharpness: 60 } };
    assert.ok(yellow(smallObs).issues.includes('too_far'));
    const edge = cardQuad(1920, 1080, 0.6, { dx: 450 });
    assert.ok(yellow(greenObservation(edge)).issues.includes('center_card'));
    for (const level of ['red', 'yellow', 'green']) assert.ok(INDICATOR_LEVELS.includes(level));
});

test('guidance turns issue codes into operator instructions without OCR text', () => {
    assert.match(guidanceFor(['too_far']), /Dekatkan KTP/);
    assert.match(guidanceFor(['glare', 'moving']), /pantulan/);
    assert.equal(guidanceFor([]), GUIDANCE.ready);
    for (const code of Object.keys(GUIDANCE)) assert.equal(typeof GUIDANCE[code], 'string');
    assert.ok(guidanceFor(['dark', 'glare', 'moving', 'too_far']).split(' ').length < 40, 'at most two instructions');
});

/* --------------------------------------------------------- hysteresis -- */

test('GREEN needs three steady analyses in a row; one bad frame does not drop it', () => {
    const corners = cardQuad(1920, 1080, 0.6);
    let s = initialTrackerState();
    assert.equal(s.level, 'red');
    s = reduceTracker(s, greenObservation(corners));
    s = reduceTracker(s, greenObservation(corners));
    assert.equal(s.level, 'red', 'not yet');
    s = reduceTracker(s, greenObservation(corners));
    assert.equal(s.level, 'green');
    s = reduceTracker(s, { found: false, reason: 'edges_not_found' });
    assert.equal(s.level, 'green', 'a single lost frame is absorbed');
    assert.ok(s.displayCorners, 'the overlay is kept for the absorbed frame');
    s = reduceTracker(s, { found: false, reason: 'edges_not_found' });
    assert.equal(s.level, 'red', 'two lost frames drop it');
    assert.equal(s.displayCorners, null);
    assert.equal(s.rawCorners, null, 'stale corners are forgotten');
});

test('alternating observations do not make the indicator flicker', () => {
    const corners = cardQuad(1920, 1080, 0.6);
    let s = initialTrackerState();
    for (let i = 0; i < 3; i++) s = reduceTracker(s, greenObservation(corners));
    const levels = [];
    for (let i = 0; i < 12; i++) {
        const obs = i % 2 === 0 ? greenObservation(corners, { quality: { luma: 180, glare: 0.3, sharpness: 60 } }) : greenObservation(corners);
        s = reduceTracker(s, obs);
        levels.push(s.level);
    }
    assert.ok(levels.every((l) => l === 'green'), `levels: ${levels.join(',')}`);
});

test('corner smoothing damps jitter and resets on a real jump', () => {
    const a = cardQuad(1920, 1080, 0.6);
    const jitter = a.map(([x, y]) => [x + 4, y - 3]);
    const smoothed = smoothCorners(a, jitter);
    assert.ok(smoothed[0][0] > a[0][0] && smoothed[0][0] < jitter[0][0]);
    const jump = cardQuad(1920, 1080, 0.6, { dx: 300 });
    assert.deepEqual(smoothCorners(a, jump), jump);
    assert.deepEqual(smoothCorners(null, a), a);
    assert.ok(cornerDrift(a, jitter) < 0.01);
    assert.ok(cornerDrift(a, jump) > 0.1);
});

/* ------------------------------------------------------------ capture -- */

test('the capture crop keeps the whole card plus a margin, inside the frame', () => {
    const corners = cardQuad(1920, 1080, 0.6);
    const crop = planCaptureCrop(corners, 1920, 1080);
    for (const [x, y] of corners) {
        assert.ok(x >= crop.x && x <= crop.x + crop.width && y >= crop.y && y <= crop.y + crop.height);
    }
    assert.ok(crop.width < 1920 && crop.height < 1080);
    const edge = planCaptureCrop(cardQuad(1920, 1080, 0.6, { dx: 450 }), 1920, 1080);
    assert.ok(edge.x + edge.width <= 1920 && edge.x >= 0);
    assert.deepEqual(planCaptureCrop(null, 1920, 1080), { x: 0, y: 0, width: 1920, height: 1080 });
    assert.deepEqual(planCaptureCrop([[0, 0], [1, 0], [1, 1], [0, 1]], 1920, 1080), { x: 0, y: 0, width: 1920, height: 1080 }, 'a tiny crop falls back to the frame');
});

test('only a capture where two detections agree is cropped; anything else keeps the whole frame', () => {
    const corners = cardQuad(1920, 1080, 0.6);
    const full = { x: 0, y: 0, width: 1920, height: 1080 };
    const confirmed = captureCropFor({ status: CAPTURE_STATUS.CONFIRMED, corners }, 1920, 1080);
    assert.deepEqual(confirmed, planCaptureCrop(corners, 1920, 1080));
    assert.ok(confirmed.width < 1920, 'a confirmed capture is cropped to the card');
    // One detection alone (card moved, or no live card) never cuts the stored document.
    for (const status of [CAPTURE_STATUS.MOVED, CAPTURE_STATUS.CAPTURE_ONLY, CAPTURE_STATUS.UNCONFIRMED]) {
        assert.deepEqual(captureCropFor({ status, corners }, 1920, 1080), full, status);
    }
    assert.deepEqual(captureCropFor(null, 1920, 1080), full);
    // The corner hint for a whole-frame capture is relative to the whole frame.
    const hint = cornersToHint(corners, full, { status: CAPTURE_STATUS.CAPTURE_ONLY });
    const back = hintToPixelCorners(hint, 1920, 1080);
    back.forEach(([x, y], i) => assert.ok(Math.abs(x - corners[i][0]) < 1 && Math.abs(y - corners[i][1]) < 1, `corner ${i}`));
});

test('capture geometry comes from the captured pixels, never from a stale live frame', () => {
    const live = cardQuad(1920, 1080, 0.6);
    const same = { found: true, corners: live.map(([x, y]) => [x + 3, y + 2]), confidence: 0.98 };
    const moved = { found: true, corners: cardQuad(1920, 1080, 0.6, { dx: 200 }), confidence: 0.97 };

    const confirmed = confirmCaptureGeometry({ liveCorners: live, capture: same, frameW: 1920, frameH: 1080 });
    assert.equal(confirmed.status, CAPTURE_STATUS.CONFIRMED);
    assert.deepEqual(confirmed.corners, same.corners, 'the captured detection is used even when it agrees');

    const shifted = confirmCaptureGeometry({ liveCorners: live, capture: moved, frameW: 1920, frameH: 1080 });
    assert.equal(shifted.status, CAPTURE_STATUS.MOVED);
    assert.deepEqual(shifted.corners, moved.corners);

    const lost = confirmCaptureGeometry({ liveCorners: live, capture: { found: false, reason: 'edges_too_weak' }, frameW: 1920, frameH: 1080 });
    assert.equal(lost.status, CAPTURE_STATUS.UNCONFIRMED);
    assert.equal(lost.corners, null, 'never falls back to the live corners');

    const bowtie = { found: true, corners: [live[0], live[2], live[1], live[3]], confidence: 1 };
    assert.equal(confirmCaptureGeometry({ liveCorners: live, capture: bowtie, frameW: 1920, frameH: 1080 }).status, CAPTURE_STATUS.UNCONFIRMED);

    const only = confirmCaptureGeometry({ liveCorners: null, capture: same, frameW: 1920, frameH: 1080 });
    assert.equal(only.status, CAPTURE_STATUS.CAPTURE_ONLY);
});

test('corner hints are normalized to the crop and validated on the way back', () => {
    const corners = cardQuad(1920, 1080, 0.6);
    const crop = planCaptureCrop(corners, 1920, 1080);
    const hint = cornersToHint(corners, crop, { status: 'confirmed', confidence: 0.9 });
    assert.equal(hint.source, 'live');
    for (const [u, v] of hint.corners) assert.ok(u >= 0 && u <= 1 && v >= 0 && v <= 1);
    const back = hintToPixelCorners(hint, crop.width, crop.height);
    corners.forEach((c, i) => assert.ok(closePt(back[i], [c[0] - crop.x, c[1] - crop.y], 1e-6)));
    // A compressed copy of the crop scales the corners with it.
    const half = hintToPixelCorners(hint, Math.round((crop.width - 1) / 2) + 1, Math.round((crop.height - 1) / 2) + 1);
    assert.ok(Math.abs(half[1][0] - (corners[1][0] - crop.x) / 2) < 1);

    assert.equal(hintToPixelCorners(null, 100, 100), null);
    assert.equal(hintToPixelCorners({ corners: [[0, 0], [1, 0], [1, 1]] }, 100, 100), null);
    assert.equal(hintToPixelCorners({ corners: [[0, 0], [9, 0], [1, 1], [0, 1]] }, 100, 100), null, 'far outside the image');
    assert.equal(hintToPixelCorners({ corners: [[0, 0], [1, 0], ['1', 1], [0, 1]] }, 100, 100), null);
    assert.equal(hintToPixelCorners({ corners: [[0, 0], [1, 1], [1, 0], [0, 1]] }, 100, 100), null, 'bow-tie');
    assert.equal(hintToPixelCorners(hint, 0, 100), null);
});

test('the field read uses capture-confirmed corners as LIVE, not as a manual edit', async () => {
    const corners = cardQuad(640, 360, 0.8);
    const image = scene(corners);
    const out = await runFieldOcr(image, {
        corners,
        cornerSource: 'live',
        cornerConfidence: 0.91,
        recognize: async () => ({ text: '', blocks: [] }),
        fields: [],
    });
    assert.equal(out.boundary.reason, 'live');
    assert.equal(out.boundary.found, true);
    assert.equal(out.boundary.confidence, 0.91);
    const manual = await runFieldOcr(image, { corners, recognize: async () => ({ text: '', blocks: [] }), fields: [] });
    assert.equal(manual.boundary.reason, 'manual', 'an operator corner edit is still recorded as manual');
});

/* ---------------------------------------------------------- scheduling -- */

test('the scheduler never overlaps analyses and adapts its interval to their cost', () => {
    const s = createAnalysisScheduler({ ...LIVE_LIMITS, minIntervalMs: 200, maxIntervalMs: 1200, cpuBudget: 0.25 });
    assert.equal(s.ready(0), true);
    const a = s.begin(0);
    assert.equal(s.ready(5000), false, 'busy');
    assert.equal(s.begin(5000), null, 'no second analysis while one runs');
    s.end(a, 100); // 100 ms of work -> 400 ms interval at a 25% budget
    assert.equal(s.ready(399), false);
    assert.equal(s.ready(400), true);
    const b = s.begin(400);
    s.end(b, 400 + 1000); // very slow -> capped
    assert.equal(s.stats().intervalMs, 1200);
    const c = s.begin(2000);
    s.end(c, 2010);
    assert.equal(s.stats().intervalMs, 200, 'never faster than the floor');
    assert.equal(s.stats().count, 3);
    s.cancel();
    assert.equal(s.ready(0), true);
    s.end(c, 5000);
    assert.equal(s.stats().count, 3, 'a stale token is ignored');
});

test('a slow analysis is never doubled up by the loop', async () => {
    let t = 0;
    let inFlight = 0;
    let maxInFlight = 0;
    let release;
    const ticks = [];
    const loop = createTrackingLoop({
        isAllowed: () => true,
        grabFrame: async () => ({}),
        analyze: () => new Promise((r) => {
            inFlight++;
            maxInFlight = Math.max(maxInFlight, inFlight);
            release = () => { inFlight--; r({ found: false, reason: 'edges_not_found' }); };
        }),
        onState: () => {},
        requestTick: (cb) => { ticks.push(cb); return ticks.length; },
        cancelTick: () => {},
        now: () => t,
    });
    loop.start();
    for (let i = 0; i < 6; i++) {
        t += 1000;
        ticks.shift()();
        await new Promise((r) => setTimeout(r, 0));
    }
    assert.equal(maxInFlight, 1);
    release();
    await new Promise((r) => setTimeout(r, 0));
    loop.stop();
    assert.equal(loop.running(), false);
});

test('stop() ends the loop and drops a result that lands afterwards', async () => {
    let t = 0;
    const states = [];
    let release;
    const ticks = [];
    const loop = createTrackingLoop({
        isAllowed: () => true,
        grabFrame: async () => ({}),
        analyze: () => new Promise((r) => { release = r; }),
        onState: (s) => states.push(s),
        requestTick: (cb) => { ticks.push(cb); return ticks.length; },
        cancelTick: () => { ticks.length = 0; },
        now: () => t,
    });
    loop.start();
    ticks.shift()();
    await new Promise((r) => setTimeout(r, 0));
    loop.stop();
    release(greenObservation(cardQuad(1920, 1080, 0.6)));
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(states.length, 0);
    assert.equal(ticks.length, 0);
});

test('a frame grabbed after stop() is released at once, not left for garbage collection', async () => {
    let t = 0;
    let releaseGrab;
    let closed = 0;
    const ticks = [];
    const analysed = [];
    const loop = createTrackingLoop({
        isAllowed: () => true,
        grabFrame: () => new Promise((r) => { releaseGrab = r; }),
        analyze: async (f) => { analysed.push(f); return { found: false }; },
        onState: () => {},
        requestTick: (cb) => { ticks.push(cb); return ticks.length; },
        cancelTick: () => {},
        now: () => t,
    });
    loop.start();
    ticks.shift()();
    await new Promise((r) => setTimeout(r, 0));
    loop.stop();
    releaseGrab({ bitmap: { close: () => { closed++; } } });
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(closed, 1);
    assert.equal(analysed.length, 0, 'the late frame is never analysed');
});

test('a failing frame grab or analysis does not kill the loop', async () => {
    let t = 0;
    let calls = 0;
    const errors = [];
    const ticks = [];
    const loop = createTrackingLoop({
        isAllowed: () => true,
        grabFrame: async () => { calls++; if (calls === 1) throw new Error('decode'); return {}; },
        analyze: async () => ({ found: false, reason: 'edges_not_found' }),
        onState: () => {},
        onError: (e) => errors.push(e.message),
        requestTick: (cb) => { ticks.push(cb); return ticks.length; },
        cancelTick: () => {},
        now: () => t,
    });
    loop.start();
    for (let i = 0; i < 3; i++) {
        t += 2000;
        ticks.shift()();
        await new Promise((r) => setTimeout(r, 0));
    }
    assert.deepEqual(errors, ['decode']);
    assert.ok(calls >= 2);
    assert.equal(loop.running(), true);
    loop.stop();
});

/* ----------------------------------------------------------- analysis -- */

test('a frame is analysed with the shipped card detector and mapped to source pixels', () => {
    const corners = cardQuad(640, 360, 0.82, { tilt: 3 });
    const obs = analyzeFrame(scene(corners), { sourceWidth: 1920, sourceHeight: 1080 });
    assert.equal(obs.found, true);
    corners.forEach(([x, y], i) => {
        assert.ok(Math.abs(obs.corners[i][0] - x * 3) < 12 && Math.abs(obs.corners[i][1] - y * 3) < 12, `corner ${i}`);
    });
    assert.equal(obs.geometry.valid, true);
    assert.ok(obs.quality.luma > 100);
    assert.equal(typeof obs.analysisMs, 'number');
});

test('analysis refuses malformed or oversized frames and reports an empty desk as no card', () => {
    assert.equal(analyzeFrame(rgba(640, 360, [60, 60, 60, 255]), { sourceWidth: 1920, sourceHeight: 1080 }).found, false);
    assert.equal(analyzeFrame({ width: 4000, height: 4000, data: new Uint8ClampedArray(16) }).reason, 'bad_image');
    assert.equal(analyzeFrame({ width: 640, height: 360, data: new Uint8ClampedArray(10) }).reason, 'bad_image', 'short buffer');
    assert.equal(analyzeFrame(rgba(640, 360, [60, 60, 60, 255]), { sourceWidth: 1920, sourceHeight: 1920 }).reason, 'bad_frame', 'distorted analysis frame');
    assert.equal(analyzeFrame(rgba(640, 360, [60, 60, 60, 255]), { sourceWidth: 192000, sourceHeight: 108000 }).reason, 'bad_frame');
    assert.equal(analyzeFrame(null).reason, 'bad_image');
});

test('worker requests are bounded before any pixel is touched', () => {
    const bitmap = { width: 640, height: 360 };
    assert.equal(validateAnalysisRequest({ id: 1, bitmap, sourceWidth: 1920, sourceHeight: 1080 }).ok, true);
    assert.equal(validateAnalysisRequest(null).ok, false);
    assert.equal(validateAnalysisRequest({ id: 'x', bitmap, sourceWidth: 1920, sourceHeight: 1080 }).ok, false);
    assert.equal(validateAnalysisRequest({ id: 1, bitmap: { width: 5000, height: 5000 }, sourceWidth: 1920, sourceHeight: 1080 }).ok, false);
    assert.equal(validateAnalysisRequest({ id: 1, bitmap, sourceWidth: -1, sourceHeight: 1080 }).ok, false);
    assert.equal(validateAnalysisRequest({ id: 1, bitmap, sourceWidth: 1920, sourceHeight: 1e9 }).ok, false);
});

/* ------------------------------------------------- source invariants -- */

const liveCameraSource = readFileSync(new URL('../../resources/js/ktp-live-camera.js', import.meta.url), 'utf8');
const cameraSource = readFileSync(new URL('../../resources/js/ktp-camera-ocr.js', import.meta.url), 'utf8');
const workerSource = readFileSync(new URL('../../resources/js/ktp-live-tracker.worker.js', import.meta.url), 'utf8');
const stripComments = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

test('the capture draws only the video into its canvas — the overlay is never burned in', () => {
    const code = stripComments(liveCameraSource);
    const draws = code.match(/drawImage\(([^,]+),/g) ?? [];
    assert.ok(draws.length > 0);
    for (const call of draws) {
        assert.match(call, /drawImage\((video|frame|bitmap)\s*,/, call);
    }
    assert.doesNotMatch(code, /XMLSerializer|foreignObject|drawImage\(svg/);
});

test('the live module is loaded only after the camera opens, which needs the D7 "yes"', () => {
    const code = stripComments(cameraSource);
    assert.equal((code.match(/import\(['"]\.\/ktp-live-camera\.js['"]\)/g) ?? []).length, 1);
    assert.doesNotMatch(code, /^import .*ktp-live/m, 'never a static import into the main bundle');
});

test('the live code makes no network request and stores nothing in the browser', () => {
    for (const src of [liveCameraSource, workerSource]) {
        const code = stripComments(src);
        assert.doesNotMatch(code, /\bfetch\(|XMLHttpRequest|sendBeacon|WebSocket|localStorage|sessionStorage|indexedDB|caches\./);
    }
});

/* Handler bodies, cut out by their selector, for the ordering checks below. */
const handlerBody = (code, selector) => {
    const at = code.indexOf(`'${selector}'`);
    assert.ok(at > 0, selector);
    const next = code.indexOf('addEventListener(', code.indexOf('addEventListener(', at) + 1);

    return code.slice(at, next > at ? next : undefined);
};

test('the confirm handler reads ONE photo before its first await (OCR reads what was uploaded)', () => {
    const body = handlerBody(stripComments(cameraSource), '[data-ktp-confirm]');
    const snapshot = body.search(/const photo = \{ blob: pendingBlob, ocrBlob: pendingOcrBlob, hint: pendingCornerHint \}/);
    const firstAwait = body.indexOf('await ');
    assert.ok(snapshot > 0 && snapshot < firstAwait, 'the photo is taken as a whole before anything is awaited');
    const afterAwait = body.slice(firstAwait);
    assert.doesNotMatch(afterAwait, /pendingOcrBlob|pendingCornerHint/, 'no shared pending state is read after an await');
    assert.match(afterAwait, /if \(pendingBlob === photo\.blob\) discardPending\(\)/, 'a newer photo is never cleared by an older confirm');
});

test('no new photo or camera can start while an upload and read are in flight', () => {
    const code = stripComments(cameraSource);
    for (const selector of ['[data-ktp-camera-open]', '[data-ktp-capture]', '[data-ktp-manual]', '[data-ktp-retake]']) {
        assert.match(handlerBody(code, selector), /blockedWhileBusy\(\)/, selector);
    }
    assert.match(code, /switchBtn\?\.addEventListener\('click', \(\) => \{\s*if \(videoDevices\.length < 2 \|\| blockedWhileBusy\(\)\) return;/);
    assert.match(code, /scanBtn\?\.addEventListener\('click', async \(\) => \{\s*if \(blockedWhileBusy\(\)\) return;/);
    assert.match(code, /clearBtn\?\.addEventListener\('click', \(\) => \{\s*if \(blockedWhileBusy\(\)\) return;/);
});

test('clearing the photo resets consent AND closes the camera opened under it', () => {
    const code = stripComments(cameraSource);
    assert.match(code, /if \(!keepConsent\) \{\s*consent = null;\s*stopCamera\(\);\s*\}/);
});

test('a capture after tracking ended analyses nothing', () => {
    const code = stripComments(liveCameraSource);
    const capture = code.slice(code.indexOf('async capture()'));
    const guard = capture.search(/if \(destroyed \|\| !isAllowed\(\)\) throw/);
    assert.ok(guard > 0 && guard < capture.indexOf('drawImage'), 'the guard runs before any pixel is drawn');
    assert.ok(guard < capture.indexOf('analyzeFrame'), 'the guard runs before any analysis');
});

test('at most one overlay exists and it is forgotten however it stops', () => {
    const code = stripComments(cameraSource);
    const start = code.slice(code.indexOf('const startLiveOverlay'), code.indexOf('const stopCamera'));
    assert.match(start, /^const startLiveOverlay = async \(openedAt\) => \{\s*stopLiveOverlay\(\);/);
    assert.match(start, /onStopped: \(\) => \{\s*if \(liveOverlay === overlay\) liveOverlay = null;/);
    assert.match(start, /overlay\.start\(\) && attempt === liveAttempt/);
    assert.match(stripComments(liveCameraSource), /onStopped\?\.\(\)/);
});

test('a camera that arrives after it was closed or re-opened is stopped, never shown', () => {
    const code = stripComments(cameraSource);
    assert.match(code, /if \(attempt !== cameraAttempt\) \{\s*opened\.getTracks\(\)\.forEach\(\(t\) => t\.stop\(\)\);/);
    assert.match(code, /const stopCamera = \(\) => \{\s*cameraAttempt\+\+;/);
});
