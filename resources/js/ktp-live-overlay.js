/**
 * REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — live KTP field overlay (pure).
 *
 * While the camera is open (only after the KTP holder's D7 "yes"), a throttled
 * analysis finds the card in the preview and the field boxes are drawn over it,
 * following its position and perspective, so the operator can line the card up
 * BEFORE the photo is taken. OCR still runs once, after capture — never on the
 * live preview.
 *
 * This module is DOM-free and side-effect free: it runs in node tests, on the
 * main thread and inside the tracker worker. It owns no geometry of its own:
 * the field boxes come from the versioned template (KTP_TEMPLATE via
 * templateBoxes) and the card is found by the same detector the field read uses
 * (detectCardQuad), so the live overlay, the capture and the post-capture boxes
 * share one coordinate model.
 *
 * Rules: .cursor/rules/181-ktp-live-field-overlay-ocr.mdc
 */
import {
    KTP_TEMPLATE,
    FIELD_SPECS,
    ROI_FIELD_KEYS,
    ROI_LIMITS,
    CAP_RATIO,
    templateBoxes,
    computeHomography,
    applyHomography,
    validCorners,
    detectCardQuad,
    toGray,
} from './ktp-roi-ocr.js';
import { KTP_ASPECT, liveTrackingAllowed } from './ktp-camera-ocr.js';

// One definition of "tracking may run" — the camera code and this module share it.
export { liveTrackingAllowed };

/**
 * Engineering defaults. The quality thresholds were calibrated on the
 * fictional synthetic frames of tools/ktp-ocr-benchmark/generate_live_frames.py
 * and are not a statement about real cards or real cameras; tune them only from
 * pilot measurements (protocol §4), never from a single anecdote.
 */
export const LIVE_LIMITS = Object.freeze({
    analysisMaxSide: 640, // preview frame handed to the analysis (detection then works at ROI_LIMITS.detectionMaxSide)
    maxAnalysisPixels: 1024 * 1024, // an analysis frame larger than this is refused unread
    maxSourceSide: 8192, // a camera reporting a larger frame is refused
    minIntervalMs: 200, // at most 5 analyses a second
    maxIntervalMs: 1200,
    cpuBudget: 0.3, // an analysis may use ~30% of wall time; slower devices analyse less often
    workerTimeoutMs: 4000, // a worker that does not answer is dropped for the main-thread path
    // geometry
    rejectPerspectiveDeg: 25, // beyond this the card is too oblique to straighten reliably
    maxPerspectiveDeg: 10,
    maxRotationDeg: 10,
    edgeMargin: 0.01, // a corner this close to the frame edge means the card may be cut off
    minTextCapPx: 13, // name-row capital height in camera pixels (below: "move closer")
    // image quality, on the analysis frame
    darkLuma: 70,
    brightLuma: 240,
    glareLuma: 250,
    glareFraction: 0.03,
    minSharpness: 18, // synthetic calibration: clear frames 26–40, dim+blurred 11.8–13.6
    // motion and smoothing (fractions of the card diagonal)
    stableDrift: 0.012,
    smoothing: 0.55,
    resetDrift: 0.08,
    // hysteresis (consecutive analyses)
    upgradeAnalyses: 3,
    downgradeAnalyses: 2,
    // capture
    captureMargin: 0.06,
    captureMatchDrift: 0.04,
});

export const INDICATOR_LEVELS = Object.freeze(['red', 'yellow', 'green']);
const RANK = { red: 0, yellow: 1, green: 2 };

export const CAPTURE_STATUS = Object.freeze({
    CONFIRMED: 'confirmed', // found on the captured pixels where the live preview had it
    MOVED: 'moved', // found on the captured pixels, but the card had moved since the last analysis
    CAPTURE_ONLY: 'capture_only', // found on the captured pixels; the preview had no card
    UNCONFIRMED: 'unconfirmed', // not found on the captured pixels: no corners are passed on
});

/** Operator instructions, by issue code. Never contains OCR text. */
export const GUIDANCE = Object.freeze({
    ready: 'Posisi dan gambar tampak baik. Tahan, lalu tekan Ambil Foto.',
    no_card: 'Arahkan KTP ke kamera: kartu mendatar, seluruhnya terlihat, mengisi bingkai putus-putus.',
    rotate_landscape: 'Putar KTP mendatar (posisi landscape).',
    center_card: 'Geser KTP ke tengah — ada tepi kartu yang terpotong.',
    too_close: 'Jauhkan KTP sedikit — seluruh kartu harus terlihat.',
    too_far: 'Dekatkan KTP ke kamera agar teks cukup besar.',
    perspective: 'Luruskan KTP — hadapkan kartu tegak lurus ke kamera.',
    rotated: 'Luruskan KTP agar tidak miring.',
    glare: 'Kurangi pantulan cahaya pada KTP (miringkan sedikit atau pindahkan lampu).',
    dark: 'Tambah pencahayaan.',
    bright: 'Cahaya terlalu terang — kurangi atau pindahkan lampu.',
    blurry: 'Gambar kurang tajam — tahan kamera dan tunggu fokus.',
    moving: 'Tahan KTP tetap diam.',
    quality_unknown: 'Kualitas gambar belum dapat dinilai — pastikan seluruh kartu terlihat.',
});

const ISSUE_PRIORITY = [
    'no_card', 'rotate_landscape', 'center_card', 'too_close', 'too_far', 'perspective',
    'rotated', 'glare', 'dark', 'bright', 'blurry', 'moving', 'quality_unknown',
];

const UNIT_SQUARE = [[0, 0], [1, 0], [1, 1], [0, 1]];
const NAME_ROW = KTP_TEMPLATE.rows.find((row) => row.key === 'name');
/** Capital height of a value line, as a fraction of the card width. */
const TEXT_CAP_FRACTION = (NAME_ROW.h / KTP_ASPECT) * CAP_RATIO;

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
const dist = (p, q) => Math.hypot(p[0] - q[0], p[1] - q[1]);
const round2 = (v) => Math.round(v * 100) / 100;
const now = () => (typeof performance !== 'undefined' ? performance.now() : Date.now());

/* ------------------------------------------------------ display mapping -- */

/**
 * Where a source image of srcW x srcH is drawn inside a box of boxW x boxH under
 * CSS `object-fit` (object-position centred). Unknown fits behave like `contain`,
 * the default rendering of <video>.
 */
export function contentRect(srcW, srcH, boxW, boxH, fit = 'contain') {
    if (![srcW, srcH, boxW, boxH].every((v) => Number.isFinite(v) && v > 0)) return null;
    let sx;
    let sy;
    switch (fit) {
        case 'fill':
            sx = boxW / srcW;
            sy = boxH / srcH;
            break;
        case 'cover':
            sx = sy = Math.max(boxW / srcW, boxH / srcH);
            break;
        case 'none':
            sx = sy = 1;
            break;
        case 'scale-down':
            sx = sy = Math.min(1, boxW / srcW, boxH / srcH);
            break;
        default:
            sx = sy = Math.min(boxW / srcW, boxH / srcH);
    }

    return { x: (boxW - srcW * sx) / 2, y: (boxH - srcH * sy) / 2, sx, sy, width: srcW * sx, height: srcH * sy };
}

/** A computed CSS transform mirrors the element when its 2D determinant is negative. */
export function isMirroredTransform(transform) {
    if (typeof transform !== 'string') return false;
    const m = transform.trim().match(/^matrix(3d)?\(([^)]*)\)$/);
    if (!m) return false;
    const v = m[2].split(',').map((s) => Number(s.trim()));
    if (v.some((n) => !Number.isFinite(n))) return false;
    const [a, b, c, d] = m[1] ? [v[0], v[1], v[4], v[5]] : v;

    return a * d - b * c < 0;
}

/**
 * The mapping from camera-frame pixels to the overlay, in CSS pixels of the
 * video's layout box (clientWidth/clientHeight — unaffected by CSS transforms,
 * page zoom and device pixel ratio, which the SVG renders sharply on its own).
 */
export function displayView({ srcW, srcH, boxW, boxH, fit = 'contain', mirrored = false } = {}) {
    const rect = contentRect(srcW, srcH, boxW, boxH, fit);

    return rect ? { rect, mirrored: Boolean(mirrored), boxW, boxH } : null;
}

export function frameToDisplay([x, y], view) {
    const dx = view.rect.x + x * view.rect.sx;
    const dy = view.rect.y + y * view.rect.sy;

    return [view.mirrored ? view.boxW - dx : dx, dy];
}

export function displayToFrame([dx, dy], view) {
    const x = view.mirrored ? view.boxW - dx : dx;

    return [(x - view.rect.x) / view.rect.sx, (dy - view.rect.y) / view.rect.sy];
}

/* ---------------------------------------------------- template projection -- */

function isFiniteQuad(q) {
    return Array.isArray(q) && q.length === 4
        && q.every((p) => Array.isArray(p) && p.length === 2 && p.every((n) => typeof n === 'number' && Number.isFinite(n)));
}

/**
 * Homography mapping the normalized card (0..1) onto the four corners, taken in
 * the order given ([tl, tr, br, bl]) — never silently reordered. Null when the
 * quad is malformed or the mapping is not well defined across the whole card.
 */
export function cardHomography(corners) {
    if (!isFiniteQuad(corners)) return null;
    const H = computeHomography(UNIT_SQUARE, corners);
    if (!H || !H.every(Number.isFinite)) return null;
    for (const [u, v] of [...UNIT_SQUARE, [0.5, 0.5]]) {
        const w = H[6] * u + H[7] * v + H[8];
        if (!(w > 1e-9)) return null;
    }

    return H;
}

/** The four corners of a normalized box, mapped through H. */
export function projectBox(H, box) {
    return [
        [box.x, box.y], [box.x + box.w, box.y], [box.x + box.w, box.y + box.h], [box.x, box.y + box.h],
    ].map(([u, v]) => applyHomography(H, u, v));
}

/** The card outline and one projected quad per field of the template. */
export function overlayGeometry(corners, template = KTP_TEMPLATE) {
    const H = cardHomography(corners);
    if (!H) return null;
    const boxes = templateBoxes(template);

    return {
        templateVersion: template.version,
        outline: corners.map(([x, y]) => [x, y]),
        fields: ROI_FIELD_KEYS.filter((key) => boxes[key]).map((key) => ({
            key,
            label: FIELD_SPECS[key].label.toUpperCase(),
            quad: projectBox(H, boxes[key]),
        })),
    };
}

/* --------------------------------------------------------- geometry guard -- */

/**
 * True only for a convex quad given in [tl, tr, br, bl] order (clockwise on a
 * y-down screen). A self-intersecting ("bow-tie") or reversed order is refused
 * as given rather than repaired.
 */
export function isOrderedConvexQuad(q) {
    if (!isFiniteQuad(q)) return false;
    for (let i = 0; i < 4; i++) {
        const [a, b, c] = [q[i], q[(i + 1) % 4], q[(i + 2) % 4]];
        const cross = (b[0] - a[0]) * (c[1] - b[1]) - (b[1] - a[1]) * (c[0] - b[0]);
        if (!(cross > 1e-9)) return false;
    }

    return true;
}

function interiorAngleDeviation(q) {
    let worst = 0;
    for (let i = 0; i < 4; i++) {
        const p = q[i];
        const a = q[(i + 3) % 4];
        const b = q[(i + 1) % 4];
        const v1 = [a[0] - p[0], a[1] - p[1]];
        const v2 = [b[0] - p[0], b[1] - p[1]];
        const cos = (v1[0] * v2[0] + v1[1] * v2[1]) / (Math.hypot(...v1) * Math.hypot(...v2));
        worst = Math.max(worst, Math.abs((Math.acos(clamp(cos, -1, 1)) * 180) / Math.PI - 90));
    }

    return worst;
}

/**
 * Validates a detected quad before any homography is built from it, and
 * measures what the indicator needs. `valid: false` carries a reason code.
 */
export function assessGeometry(corners, frameW, frameH, limits = LIVE_LIMITS) {
    const fail = (reason) => ({ valid: false, reason });
    if (!(frameW > 0 && frameH > 0) || frameW > limits.maxSourceSide || frameH > limits.maxSourceSide) return fail('bad_frame');
    if (!isFiniteQuad(corners)) return fail('malformed');
    if (!isOrderedConvexQuad(corners)) return fail('self_intersecting');
    if (!validCorners(corners, frameW, frameH)) return fail('out_of_frame');
    if (!cardHomography(corners)) return fail('invalid_homography');

    const [tl, tr, br, bl] = corners;
    const top = dist(tl, tr);
    const bottom = dist(bl, br);
    const left = dist(tl, bl);
    const right = dist(tr, br);
    const perspectiveDeg = interiorAngleDeviation(corners);
    if (perspectiveDeg > limits.rejectPerspectiveDeg) return fail('extreme_perspective');
    const aspect = (top + bottom) / (left + right);
    if (aspect >= 1 / 1.95 && aspect <= 1 / 1.25) return fail('portrait');
    if (aspect < 1.25 || aspect > 1.95) return fail('wrong_shape');

    const rotationDeg = (Math.atan2((tr[1] - tl[1]) + (br[1] - bl[1]), (tr[0] - tl[0]) + (br[0] - bl[0])) * 180) / Math.PI;
    const m = limits.edgeMargin * Math.max(frameW, frameH);
    const clipped = corners.some(([x, y]) => x < m || y < m || x > frameW - 1 - m || y > frameH - 1 - m);
    const cardWidthPx = (top + bottom) / 2;
    let area = 0;
    for (let i = 0; i < 4; i++) {
        const [x1, y1] = corners[i];
        const [x2, y2] = corners[(i + 1) % 4];
        area += x1 * y2 - x2 * y1;
    }

    return {
        valid: true,
        reason: null,
        aspect: round2(aspect),
        perspectiveDeg: round2(perspectiveDeg),
        rotationDeg: round2(rotationDeg),
        clipped,
        cardWidthPx: round2(cardWidthPx),
        coversFrameWidth: round2(cardWidthPx / frameW),
        textCapPx: round2(cardWidthPx * TEXT_CAP_FRACTION),
        areaFraction: round2(Math.abs(area) / 2 / (frameW * frameH)),
    };
}

/* ----------------------------------------------------------------- quality -- */

const QUALITY_GRID = { cols: 48, rows: 30 };

/**
 * Brightness, glare and sharpness of the CARD only, from a bounded grid of
 * samples (no full-image pass). `gray` is the analysis frame in gray, `corners`
 * in its pixels. Sharpness is the mean absolute Laplacian at the samples.
 */
export function measureQuality(gray, corners, limits = LIVE_LIMITS) {
    const H = cardHomography(corners);
    if (!H || !gray || (gray.channels ?? 1) !== 1) return null;
    const { width: w, height: h, data } = gray;
    let n = 0;
    let sum = 0;
    let saturated = 0;
    let lap = 0;
    for (let r = 0; r < QUALITY_GRID.rows; r++) {
        for (let c = 0; c < QUALITY_GRID.cols; c++) {
            const u = 0.04 + (0.92 * (c + 0.5)) / QUALITY_GRID.cols;
            const v = 0.06 + (0.88 * (r + 0.5)) / QUALITY_GRID.rows;
            const [fx, fy] = applyHomography(H, u, v);
            const x = Math.round(fx);
            const y = Math.round(fy);
            if (x < 1 || y < 1 || x > w - 2 || y > h - 2) continue;
            const i = y * w + x;
            const p = data[i];
            sum += p;
            if (p >= limits.glareLuma) saturated++;
            lap += Math.abs(4 * p - data[i - 1] - data[i + 1] - data[i - w] - data[i + w]);
            n++;
        }
    }
    if (n < (QUALITY_GRID.cols * QUALITY_GRID.rows) / 2) return null;

    return { luma: round2(sum / n), glare: round2(saturated / n), sharpness: round2(lap / n), samples: n };
}

/* ---------------------------------------------------------- classification -- */

function redIssue(obs) {
    switch (obs?.reason) {
        case 'portrait':
            return 'rotate_landscape';
        case 'extreme_perspective':
            return 'perspective';
        case 'out_of_frame':
            return 'center_card';
        default:
            return 'no_card';
    }
}

/**
 * RED: no card the overlay may be drawn on. YELLOW: a card, but something the
 * operator can fix. GREEN: geometry and image look suitable — never a promise
 * that the OCR will be right (rule 177 §4).
 */
export function classifyObservation(obs, drift, limits = LIVE_LIMITS) {
    if (!obs?.found || !obs.geometry?.valid) return { level: 'red', issues: [redIssue(obs)] };
    const g = obs.geometry;
    const q = obs.quality;
    const issues = [];
    if (g.clipped) issues.push(g.coversFrameWidth > 0.9 ? 'too_close' : 'center_card');
    if (g.textCapPx < limits.minTextCapPx) issues.push('too_far');
    if (g.perspectiveDeg > limits.maxPerspectiveDeg) issues.push('perspective');
    if (Math.abs(g.rotationDeg) > limits.maxRotationDeg) issues.push('rotated');
    if (!q) {
        issues.push('quality_unknown');
    } else {
        if (q.luma < limits.darkLuma) issues.push('dark');
        else if (q.luma > limits.brightLuma) issues.push('bright');
        if (q.glare > limits.glareFraction) issues.push('glare');
        if (q.sharpness < limits.minSharpness) issues.push('blurry');
    }
    if (drift !== null && drift !== undefined && drift > limits.stableDrift) issues.push('moving');

    return { level: issues.length ? 'yellow' : 'green', issues };
}

/** At most two instructions, most important first. */
export function guidanceFor(issues) {
    if (!issues?.length) return GUIDANCE.ready;
    const ordered = ISSUE_PRIORITY.filter((code) => issues.includes(code));

    return ordered.slice(0, 2).map((code) => GUIDANCE[code]).join(' ');
}

/* -------------------------------------------------- tracking + hysteresis -- */

/** Largest corner displacement, as a fraction of the card diagonal. */
export function cornerDrift(a, b) {
    if (!isFiniteQuad(a) || !isFiniteQuad(b)) return Infinity;
    const diag = (dist(b[0], b[2]) + dist(b[1], b[3])) / 2;
    if (!(diag > 0)) return Infinity;

    return Math.max(...a.map((p, i) => dist(p, b[i]))) / diag;
}

/** Damps detection jitter in the drawn overlay; a real jump is followed at once. */
export function smoothCorners(prev, next, limits = LIVE_LIMITS) {
    const copy = next.map(([x, y]) => [x, y]);
    if (!isFiniteQuad(prev) || cornerDrift(prev, next) > limits.resetDrift) return copy;
    const a = limits.smoothing;

    return prev.map(([x, y], i) => [round2(x + a * (next[i][0] - x)), round2(y + a * (next[i][1] - y))]);
}

export function initialTrackerState() {
    return {
        level: 'red',
        issues: ['no_card'],
        pending: null,
        pendingCount: 0,
        displayCorners: null, // smoothed, for drawing only
        rawCorners: null, // last accepted detection, for comparing with the capture
        geometry: null,
        lost: 0,
        analyses: 0,
    };
}

/**
 * One analysis in, the next tracker state out. The level changes only after
 * `upgradeAnalyses` better or `downgradeAnalyses` worse analyses in a row, so a
 * single noisy frame never flickers the indicator; a card lost for
 * `downgradeAnalyses` analyses is forgotten (its corners are never reused).
 */
export function reduceTracker(state, obs, limits = LIVE_LIMITS) {
    const usable = Boolean(obs?.found && obs.geometry?.valid && isFiniteQuad(obs.corners));
    const drift = usable && state.rawCorners ? cornerDrift(state.rawCorners, obs.corners) : null;
    const observed = classifyObservation(obs, drift, limits);
    const next = { ...state, analyses: state.analyses + 1 };

    if (usable) {
        next.rawCorners = obs.corners.map(([x, y]) => [x, y]);
        next.displayCorners = smoothCorners(state.displayCorners, obs.corners, limits);
        next.geometry = obs.geometry;
        next.lost = 0;
    } else {
        next.lost = state.lost + 1;
        if (next.lost >= limits.downgradeAnalyses) {
            next.rawCorners = null;
            next.displayCorners = null;
            next.geometry = null;
        }
    }

    if (observed.level === state.level) {
        next.pending = null;
        next.pendingCount = 0;
        next.issues = observed.issues;
    } else {
        const count = state.pending === observed.level ? state.pendingCount + 1 : 1;
        const needed = RANK[observed.level] > RANK[state.level] ? limits.upgradeAnalyses : limits.downgradeAnalyses;
        if (count >= needed) {
            next.level = observed.level;
            next.pending = null;
            next.pendingCount = 0;
            next.issues = observed.issues;
        } else {
            next.pending = observed.level;
            next.pendingCount = count;
            next.issues = state.issues;
        }
    }

    return next;
}

/* ----------------------------------------------------------------- capture -- */

/**
 * The part of the camera frame kept by a capture: the card's bounding box plus
 * a margin, clamped to the frame. Original pixels only — nothing is warped,
 * drawn or burned in. Without usable corners the whole frame is kept, so a card
 * the analysis did not find is never cut off.
 */
export function planCaptureCrop(corners, frameW, frameH, margin = LIVE_LIMITS.captureMargin) {
    const full = { x: 0, y: 0, width: Math.max(0, Math.floor(frameW)), height: Math.max(0, Math.floor(frameH)) };
    if (!isFiniteQuad(corners) || !(frameW > 0 && frameH > 0)) return full;
    const xs = corners.map((p) => p[0]);
    const ys = corners.map((p) => p[1]);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);
    const mx = (maxX - minX) * margin;
    const my = (maxY - minY) * margin;
    const x0 = clamp(Math.floor(minX - mx), 0, full.width);
    const y0 = clamp(Math.floor(minY - my), 0, full.height);
    const x1 = clamp(Math.ceil(maxX + mx + 1), 0, full.width);
    const y1 = clamp(Math.ceil(maxY + my + 1), 0, full.height);
    if (x1 - x0 < 32 || y1 - y0 < 32) return full;

    return { x: x0, y: y0, width: x1 - x0, height: y1 - y0 };
}

/**
 * Which part of the frame is kept as the photo. Only a capture where two
 * independent detections agree (the live card at the press and the card found
 * on the captured pixels: CONFIRMED) is cropped to the card. Any other outcome
 * keeps the whole frame, so a single wrong detection can never cut part of the
 * card out of the stored KTP document; the corner hint (if any) still lets the
 * read straighten the card inside the whole frame.
 */
export function captureCropFor(decision, frameW, frameH, margin = LIVE_LIMITS.captureMargin) {
    const corners = decision?.status === CAPTURE_STATUS.CONFIRMED ? decision.corners : null;

    return planCaptureCrop(corners, frameW, frameH, margin);
}

/**
 * Corners for the field read come from the CAPTURED pixels only. The live
 * corners (frozen when the button was pressed) are used just to say whether the
 * capture agrees with what the operator saw; a capture where the card is not
 * found passes NO corners on — the read then finds the edge itself or asks the
 * operator for the corners (rule 179 §2). Stale live geometry is never used.
 */
export function confirmCaptureGeometry({ liveCorners, capture, frameW, frameH }, limits = LIVE_LIMITS) {
    const usable = Boolean(capture?.found && isFiniteQuad(capture.corners)
        && assessGeometry(capture.corners, frameW, frameH, limits).valid);
    if (!usable) return { status: CAPTURE_STATUS.UNCONFIRMED, corners: null, drift: null, confidence: 0 };
    const corners = capture.corners.map(([x, y]) => [x, y]);
    const confidence = typeof capture.confidence === 'number' ? capture.confidence : null;
    if (!isFiniteQuad(liveCorners)) return { status: CAPTURE_STATUS.CAPTURE_ONLY, corners, drift: null, confidence };
    const drift = cornerDrift(liveCorners, corners);

    return {
        status: drift <= limits.captureMatchDrift ? CAPTURE_STATUS.CONFIRMED : CAPTURE_STATUS.MOVED,
        corners,
        drift: round2(drift * 1000) / 1000,
        confidence,
    };
}

/**
 * Corners normalized to the crop (0..1, pixel-centre convention), so they stay
 * right when the crop is re-encoded at another size.
 */
export function cornersToHint(corners, crop, { status = null, confidence = null } = {}) {
    if (!isFiniteQuad(corners) || !(crop?.width > 1 && crop?.height > 1)) return null;

    return {
        source: 'live',
        status,
        confidence,
        corners: corners.map(([x, y]) => [(x - crop.x) / (crop.width - 1), (y - crop.y) / (crop.height - 1)]),
    };
}

/** Back to pixels of the image actually read; null for anything not a usable card quad. */
export function hintToPixelCorners(hint, width, height) {
    if (!(width > 1 && height > 1) || !isFiniteQuad(hint?.corners)) return null;
    if (hint.corners.some(([u, v]) => u < -0.15 || v < -0.15 || u > 1.15 || v > 1.15)) return null;
    const corners = hint.corners.map(([u, v]) => [u * (width - 1), v * (height - 1)]);
    if (!isOrderedConvexQuad(corners) || !validCorners(corners, width, height)) return null;

    return corners;
}

/* -------------------------------------------------------------- scheduling -- */

/**
 * Throttles the analysis: never two at once, and the interval adapts to what an
 * analysis costs on this device (duration / cpuBudget), within
 * [minIntervalMs, maxIntervalMs]. A token from before cancel() is ignored.
 */
export function createAnalysisScheduler(limits = LIVE_LIMITS) {
    let inFlight = null;
    let nextAt = 0;
    let interval = limits.minIntervalMs;
    const durations = [];

    return {
        ready: (t) => inFlight === null && t >= nextAt,
        begin(t) {
            if (inFlight !== null) return null;
            inFlight = { startedAt: t };

            return inFlight;
        },
        end(token, t) {
            if (!token || token !== inFlight) return;
            const duration = Math.max(0, t - token.startedAt);
            durations.push(duration);
            if (durations.length > 60) durations.shift();
            interval = clamp(duration / limits.cpuBudget, limits.minIntervalMs, limits.maxIntervalMs);
            nextAt = token.startedAt + interval;
            inFlight = null;
        },
        cancel() {
            inFlight = null;
            nextAt = 0;
        },
        stats() {
            const sorted = [...durations].sort((a, b) => a - b);
            const mean = sorted.length ? sorted.reduce((s, d) => s + d, 0) / sorted.length : null;

            return {
                count: durations.length,
                meanMs: mean === null ? null : Math.round(mean),
                p95Ms: sorted.length ? Math.round(sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * 0.95))]) : null,
                intervalMs: Math.round(interval),
            };
        },
    };
}

/**
 * The tracking loop, with every browser dependency injected (so it is tested in
 * node). Each tick first asks isAllowed() — consent, camera still open — and
 * stops for good when the answer is no. A result that lands after stop() is
 * dropped, and a failed frame never ends the loop.
 */
export function createTrackingLoop(deps) {
    const limits = deps.limits ?? LIVE_LIMITS;
    const scheduler = createAnalysisScheduler(limits);
    let running = false;
    let handle = null;
    let state = initialTrackerState();

    const run = async (t) => {
        const token = scheduler.begin(t);
        if (!token) return;
        try {
            const frame = await deps.grabFrame();
            if (!running || !frame) {
                frame?.bitmap?.close?.(); // grabbed after stop(): release it now, not at GC
                return;
            }
            const obs = await deps.analyze(frame);
            if (!running) return;
            state = reduceTracker(state, obs, limits);
            deps.onState(state, obs);
        } catch (e) {
            if (running) deps.onError?.(e);
        } finally {
            scheduler.end(token, deps.now());
        }
    };

    const stop = () => {
        running = false;
        if (handle !== null) deps.cancelTick(handle);
        handle = null;
        scheduler.cancel();
    };

    const tick = () => {
        handle = null;
        if (!running) return;
        if (!deps.isAllowed()) {
            stop();
            deps.onBlocked?.();

            return;
        }
        const t = deps.now();
        if (scheduler.ready(t)) run(t);
        handle = deps.requestTick(tick);
    };

    return {
        start() {
            if (running) return true;
            if (!deps.isAllowed()) return false;
            running = true;
            handle = deps.requestTick(tick);

            return true;
        },
        stop,
        running: () => running,
        state: () => state,
        stats: () => scheduler.stats(),
    };
}

/* ---------------------------------------------------------------- analysis -- */

/**
 * One preview frame: find the card with the shipped detector, validate it, map
 * it to camera pixels and measure its quality. `image` is the downscaled
 * analysis frame (RGBA); sourceWidth/Height is the camera frame it came from.
 */
export function analyzeFrame(image, { sourceWidth, sourceHeight } = {}, limits = LIVE_LIMITS) {
    const started = now();
    const done = (o) => ({ ...o, analysisMs: Math.round(now() - started) });
    const w = image?.width | 0;
    const h = image?.height | 0;
    const channels = image?.channels ?? 4;
    if (w < 32 || h < 32 || w * h > limits.maxAnalysisPixels || !image.data || image.data.length < w * h * channels) {
        return done({ found: false, reason: 'bad_image' });
    }
    const sw = sourceWidth ?? w;
    const sh = sourceHeight ?? h;
    if (!(sw > 0 && sh > 0) || sw > limits.maxSourceSide || sh > limits.maxSourceSide) {
        return done({ found: false, reason: 'bad_frame' });
    }
    const fx = sw / w;
    const fy = sh / h;
    if (Math.abs(fx / fy - 1) > 0.03) return done({ found: false, reason: 'bad_frame' });

    const detection = detectCardQuad(image, { maxSide: ROI_LIMITS.detectionMaxSide });
    if (!detection.found) return done({ found: false, reason: detection.reason, confidence: detection.confidence });
    const corners = detection.corners.map(([x, y]) => [round2(x * fx), round2(y * fy)]);
    const geometry = assessGeometry(corners, sw, sh, limits);
    if (!geometry.valid) return done({ found: false, reason: geometry.reason, geometry });
    const quality = measureQuality(toGray(image), detection.corners, limits);

    return done({ found: true, reason: 'detected', corners, confidence: detection.confidence, geometry, quality });
}

/** A worker message is checked before any pixel of it is touched. */
export function validateAnalysisRequest(msg, limits = LIVE_LIMITS) {
    const bad = (reason) => ({ ok: false, reason });
    if (!msg || typeof msg !== 'object') return bad('malformed');
    if (!Number.isSafeInteger(msg.id)) return bad('malformed');
    const bw = msg.bitmap?.width;
    const bh = msg.bitmap?.height;
    if (!(Number.isInteger(bw) && Number.isInteger(bh) && bw >= 32 && bh >= 32) || bw * bh > limits.maxAnalysisPixels) return bad('bad_image');
    const { sourceWidth: sw, sourceHeight: sh } = msg;
    if (!(Number.isFinite(sw) && Number.isFinite(sh) && sw > 0 && sh > 0 && sw <= limits.maxSourceSide && sh <= limits.maxSourceSide)) {
        return bad('bad_frame');
    }

    return { ok: true, reason: null };
}
