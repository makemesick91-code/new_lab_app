/**
 * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — field-based KTP OCR.
 *
 * Pure, DOM-free pipeline. Every function takes and returns plain pixel
 * records ({ width, height, data, channels? }) so the browser and the Node
 * benchmark/test harness run the identical code:
 *
 *   captured image
 *     -> detectCardQuad()        four KTP corners (or an honest "not found")
 *     -> warpPerspective()       deskewed card on a fixed normalized canvas
 *     -> whole-card OCR          the preserved whole-document read
 *     -> anchorTemplate()        template ROIs snapped to the labels it found
 *     -> per-field crop + preprocess + field-specific OCR
 *     -> { lines, fields }       sent to the server, which validates and
 *                                reconciles the two reads (never this file)
 *
 * Contract (rule 179):
 *   - Every coordinate a box or template carries is NORMALIZED to the card
 *     (0..1 on both axes), never a screen or source-image pixel.
 *   - Nothing here decides a patient value. Field text goes back to the
 *     server exactly as read; validation and reconciliation live server-side.
 *   - A boundary that is not convincingly found is reported as not found and
 *     the whole image is used — the image is never cropped on a guess.
 *   - Field text is bounded and untrusted; callers write it with .value or
 *     .textContent only.
 */

import { KTP_ASPECT, toOcrLines } from './ktp-camera-ocr.js';

/** ID-1 card (KTP) aspect ratio, 85.6mm x 53.98mm. */
export const CARD_ASPECT = KTP_ASPECT;

/** Normalized card raster the OCR reads (pixels). */
export const NORMALIZED_WIDTH = 1280;
export const NORMALIZED_HEIGHT = Math.round(NORMALIZED_WIDTH / CARD_ASPECT);

export const ROI_LIMITS = Object.freeze({
    maxSourcePixels: 4096 * 4096,
    maxLineLength: 200,
    minBoxSize: 0.015,
    detectionMaxSide: 512,
});

/* ------------------------------------------------------------- template -- */

/**
 * Versioned e-KTP field template. Boxes are the VALUE regions (right of the
 * colon, left of the portrait). Real cards vary by print run, so the template
 * is only the starting point: anchorTemplate() snaps it to the labels the
 * whole-card read actually found, and the operator can move every box.
 *
 * `labelX` is where the row's label starts (used to anchor the fit); rows
 * without a field (kewarganegaraan, berlaku) are anchors only.
 */
export const KTP_TEMPLATE = Object.freeze({
    version: 'ektp-v1',
    rows: Object.freeze([
        { key: 'nik', y: 0.181, h: 0.072, x: 0.195, x2: 0.735, labelX: 0.028, label: /^N[I1L|!]K\b/ },
        { key: 'name', y: 0.257, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.028, label: /^NAMA\b/ },
        { key: 'birth_place_date', y: 0.310, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.028, label: /^(TEMPAT|TEMP|TGL)/ },
        { key: 'gender', y: 0.363, h: 0.048, x: 0.240, x2: 0.495, labelX: 0.028, label: /^JEN[I1L]S\b/ },
        { key: 'address', y: 0.417, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.028, label: /^ALAMAT\b/ },
        { key: 'rt_rw', y: 0.470, h: 0.048, x: 0.240, x2: 0.460, labelX: 0.055, label: /^RT(\b|[\/.I1L|!]|RW)/ },
        { key: 'village', y: 0.523, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.055, label: /^KEL[\/.I1L|]|^KEL\/?DESA|^DESA\b/ },
        { key: 'district', y: 0.576, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.055, label: /^KECAMATAN\b/ },
        { key: 'religion', y: 0.629, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.028, label: /^AGAMA\b/ },
        { key: 'marital_status', y: 0.683, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.028, label: /^STATUS\b/ },
        { key: 'occupation', y: 0.736, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.028, label: /^PEKERJAAN\b/ },
        { key: null, y: 0.789, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.028, label: /^KEWARGA/ },
        { key: null, y: 0.842, h: 0.048, x: 0.240, x2: 0.735, labelX: 0.028, label: /^BERLAKU\b/ },
    ]),
});

const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const DIGITS = '0123456789';

/**
 * Field-specific OCR behaviour. `whitelist` is the only alphabet tesseract may
 * emit for the field: KTP values are printed in capitals, so lower case is
 * never a legitimate read. The NIK and RT/RW are digit-only — a separator or
 * letter there is a misread, not data. Free-text fields keep spaces
 * (preserve_interword_spaces) so multi-word values are not joined.
 */
export const FIELD_SPECS = Object.freeze({
    nik: { label: 'NIK', whitelist: DIGITS, numeric: true },
    name: { label: 'Nama', whitelist: UPPER + " .,'-" },
    birth_place_date: { label: 'Tempat/Tgl Lahir', whitelist: UPPER + DIGITS + ' ,.-/' },
    gender: { label: 'Jenis Kelamin', whitelist: UPPER + '-' },
    address: { label: 'Alamat', whitelist: UPPER + DIGITS + " .,-/'" },
    rt_rw: { label: 'RT/RW', whitelist: DIGITS + '/', numeric: true },
    village: { label: 'Kel/Desa', whitelist: UPPER + DIGITS + " .-/'" },
    district: { label: 'Kecamatan', whitelist: UPPER + " .-'" },
    religion: { label: 'Agama', whitelist: UPPER + ' ' },
    marital_status: { label: 'Status Perkawinan', whitelist: UPPER + ' ' },
    occupation: { label: 'Pekerjaan', whitelist: UPPER + ' /.-' },
});

export const ROI_FIELD_KEYS = Object.freeze(Object.keys(FIELD_SPECS));

/** A fresh, deep copy of the template boxes keyed by field. */
export function templateBoxes(template = KTP_TEMPLATE) {
    const boxes = {};
    for (const row of template.rows) {
        if (!row.key) continue;
        boxes[row.key] = clampBox({ x: row.x, y: row.y - row.h / 2, w: row.x2 - row.x, h: row.h });
    }

    return boxes;
}

/* ---------------------------------------------------------- box geometry -- */

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

/** Keep a normalized box inside the card and above a minimum size. */
export function clampBox(box, min = ROI_LIMITS.minBoxSize) {
    const w = clamp(Number(box?.w) || min, min, 1);
    const h = clamp(Number(box?.h) || min, min, 1);
    const x = clamp(Number(box?.x) || 0, 0, 1 - w);
    const y = clamp(Number(box?.y) || 0, 0, 1 - h);

    return { x: round4(x), y: round4(y), w: round4(w), h: round4(h) };
}

/** Drag a whole box by a normalized delta; it stops at the card edge. */
export function moveBox(box, dx, dy) {
    return clampBox({ ...box, x: clamp(box.x + dx, 0, 1 - box.w), y: clamp(box.y + dy, 0, 1 - box.h) });
}

/**
 * Resize from a handle ('n','s','e','w','ne','nw','se','sw'). The opposite
 * edge stays put; the box never inverts, shrinks below the minimum, or leaves
 * the card.
 */
export function resizeBox(box, handle, dx, dy, min = ROI_LIMITS.minBoxSize) {
    let left = box.x;
    let top = box.y;
    let right = box.x + box.w;
    let bottom = box.y + box.h;
    if (handle.includes('w')) left = clamp(left + dx, 0, right - min);
    if (handle.includes('e')) right = clamp(right + dx, left + min, 1);
    if (handle.includes('n')) top = clamp(top + dy, 0, bottom - min);
    if (handle.includes('s')) bottom = clamp(bottom + dy, top + min, 1);

    return clampBox({ x: left, y: top, w: right - left, h: bottom - top }, min);
}

/** Normalized box -> integer pixel rect on an image of the given size. */
export function boxToPixels(box, width, height) {
    const x = Math.floor(box.x * width);
    const y = Math.floor(box.y * height);

    return {
        x,
        y,
        width: Math.max(1, Math.min(width - x, Math.ceil((box.x + box.w) * width) - x)),
        height: Math.max(1, Math.min(height - y, Math.ceil((box.y + box.h) * height) - y)),
    };
}

function round4(v) {
    return Math.round(v * 10000) / 10000;
}

/* --------------------------------------------------------- pixel basics -- */

/** RGBA -> 8-bit luminance (ITU-R BT.601). */
export function toGray(image) {
    const { width, height, data } = image;
    if ((image.channels ?? 4) === 1) return { width, height, data, channels: 1 };
    const out = new Uint8ClampedArray(width * height);
    for (let i = 0, p = 0; p < out.length; i += 4, p++) {
        out[p] = (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000;
    }

    return { width, height, data: out, channels: 1 };
}

/** Area-average downscale of a gray image so its longer side is <= maxSide. */
export function downscaleGray(gray, maxSide) {
    const scale = Math.min(1, maxSide / Math.max(gray.width, gray.height));
    if (scale === 1) return { ...gray, scale: 1 };
    const w = Math.max(1, Math.round(gray.width * scale));
    const h = Math.max(1, Math.round(gray.height * scale));
    const out = new Uint8ClampedArray(w * h);
    const sx = gray.width / w;
    const sy = gray.height / h;
    for (let y = 0; y < h; y++) {
        const y0 = Math.floor(y * sy);
        const y1 = Math.max(y0 + 1, Math.floor((y + 1) * sy));
        for (let x = 0; x < w; x++) {
            const x0 = Math.floor(x * sx);
            const x1 = Math.max(x0 + 1, Math.floor((x + 1) * sx));
            let sum = 0;
            for (let yy = y0; yy < y1; yy++) {
                const row = yy * gray.width;
                for (let xx = x0; xx < x1; xx++) sum += gray.data[row + xx];
            }
            out[y * w + x] = sum / ((y1 - y0) * (x1 - x0));
        }
    }

    return { width: w, height: h, data: out, channels: 1, scale };
}

/** Bilinear rescale of a gray image by a factor (used to upscale field crops). */
export function scaleGray(gray, factor) {
    const w = Math.max(1, Math.round(gray.width * factor));
    const h = Math.max(1, Math.round(gray.height * factor));
    const out = new Uint8ClampedArray(w * h);
    for (let y = 0; y < h; y++) {
        const fy = Math.min(gray.height - 1, Math.max(0, (y + 0.5) / factor - 0.5));
        const y0 = Math.floor(fy);
        const y1 = Math.min(gray.height - 1, y0 + 1);
        const ty = fy - y0;
        for (let x = 0; x < w; x++) {
            const fx = Math.min(gray.width - 1, Math.max(0, (x + 0.5) / factor - 0.5));
            const x0 = Math.floor(fx);
            const x1 = Math.min(gray.width - 1, x0 + 1);
            const tx = fx - x0;
            const a = gray.data[y0 * gray.width + x0];
            const b = gray.data[y0 * gray.width + x1];
            const c = gray.data[y1 * gray.width + x0];
            const d = gray.data[y1 * gray.width + x1];
            out[y * w + x] = (a * (1 - tx) + b * tx) * (1 - ty) + (c * (1 - tx) + d * tx) * ty;
        }
    }

    return { width: w, height: h, data: out, channels: 1 };
}

/* --------------------------------------------------- boundary detection -- */

function sobel(gray) {
    const { width: w, height: h, data } = gray;
    const gx = new Float32Array(w * h);
    const gy = new Float32Array(w * h);
    const mag = new Float32Array(w * h);
    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const i = y * w + x;
            const tl = data[i - w - 1], t = data[i - w], tr = data[i - w + 1];
            const l = data[i - 1], r = data[i + 1];
            const bl = data[i + w - 1], b = data[i + w], br = data[i + w + 1];
            const sx = tr + 2 * r + br - tl - 2 * l - bl;
            const sy = bl + 2 * b + br - tl - 2 * t - tr;
            gx[i] = sx;
            gy[i] = sy;
            mag[i] = Math.hypot(sx, sy);
        }
    }

    return { gx, gy, mag };
}

function percentile(values, q) {
    // Histogram percentile over a bounded range (Sobel magnitude <= ~1443).
    const bins = new Uint32Array(1500);
    let n = 0;
    for (const v of values) {
        if (v <= 0) continue;
        bins[Math.min(1499, v | 0)]++;
        n++;
    }
    if (n === 0) return 0;
    const target = n * q;
    let acc = 0;
    for (let i = 0; i < bins.length; i++) {
        acc += bins[i];
        if (acc >= target) return i;
    }

    return 1499;
}

function houghPeaks(edges, w, h, thetas) {
    const diag = Math.ceil(Math.hypot(w, h));
    const rhoCount = 2 * diag + 1;
    const acc = new Uint32Array(180 * rhoCount);
    const cos = new Float32Array(180);
    const sin = new Float32Array(180);
    for (let t = 0; t < 180; t++) {
        cos[t] = Math.cos((t * Math.PI) / 180);
        sin[t] = Math.sin((t * Math.PI) / 180);
    }
    for (const { x, y, angle } of edges) {
        for (let d = -4; d <= 4; d++) {
            const t = (((angle + d) % 180) + 180) % 180;
            if (!thetas[t]) continue;
            const rho = Math.round(x * cos[t] + y * sin[t]) + diag;
            acc[t * rhoCount + rho]++;
        }
    }

    const peaks = [];
    const minVotes = Math.max(12, Math.round(0.12 * Math.min(w, h)));
    for (let t = 0; t < 180; t++) {
        if (!thetas[t]) continue;
        for (let r = 0; r < rhoCount; r++) {
            const v = acc[t * rhoCount + r];
            if (v < minVotes) continue;
            let isMax = true;
            for (let dt = -3; dt <= 3 && isMax; dt++) {
                const tt = (((t + dt) % 180) + 180) % 180;
                for (let dr = -6; dr <= 6; dr++) {
                    const rr = r + dr;
                    if ((dt === 0 && dr === 0) || rr < 0 || rr >= rhoCount) continue;
                    const o = acc[tt * rhoCount + rr];
                    if (o > v || (o === v && (dt < 0 || (dt === 0 && dr < 0)))) {
                        isMax = false;
                        break;
                    }
                }
            }
            if (isMax) peaks.push({ theta: t, rho: r - diag, votes: v });
        }
    }

    return peaks.sort((a, b) => b.votes - a.votes);
}

/** y of a near-horizontal line at x; x of a near-vertical line at y. */
function lineY(l, x) {
    const t = (l.theta * Math.PI) / 180;

    return (l.rho - x * Math.cos(t)) / Math.sin(t);
}

function lineX(l, y) {
    const t = (l.theta * Math.PI) / 180;

    return (l.rho - y * Math.sin(t)) / Math.cos(t);
}

/**
 * The strongest lines plus the outermost ones. Rows of text can out-vote the
 * card border, but the border is always among the outermost long lines, so
 * those are always considered.
 */
function candidateLines(peaks, position, strongest = 16, outermost = 3) {
    const byPosition = [...peaks].sort((a, b) => position(a) - position(b));
    const chosen = new Set([...peaks.slice(0, strongest), ...byPosition.slice(0, outermost), ...byPosition.slice(-outermost)]);

    return [...chosen];
}

function intersect(l1, l2) {
    const t1 = (l1.theta * Math.PI) / 180;
    const t2 = (l2.theta * Math.PI) / 180;
    const a1 = Math.cos(t1), b1 = Math.sin(t1);
    const a2 = Math.cos(t2), b2 = Math.sin(t2);
    const det = a1 * b2 - a2 * b1;
    if (Math.abs(det) < 1e-6) return null;

    return [(l1.rho * b2 - l2.rho * b1) / det, (a1 * l2.rho - a2 * l1.rho) / det];
}

function polygonArea(pts) {
    let s = 0;
    for (let i = 0; i < pts.length; i++) {
        const [x1, y1] = pts[i];
        const [x2, y2] = pts[(i + 1) % pts.length];
        s += x1 * y2 - x2 * y1;
    }

    return s / 2;
}

function isConvex(pts) {
    let sign = 0;
    for (let i = 0; i < 4; i++) {
        const [x1, y1] = pts[i];
        const [x2, y2] = pts[(i + 1) % 4];
        const [x3, y3] = pts[(i + 2) % 4];
        const cross = (x2 - x1) * (y3 - y2) - (y2 - y1) * (x3 - x2);
        if (cross === 0) return false;
        if (sign === 0) sign = Math.sign(cross);
        else if (Math.sign(cross) !== sign) return false;
    }

    return true;
}

const dist = ([x1, y1], [x2, y2]) => Math.hypot(x2 - x1, y2 - y1);

/**
 * Refit one side of a candidate quad to the edge pixels along it (total least
 * squares). The Hough grid is 1 degree / 1 pixel; over a full card side that is
 * several pixels of error, enough to miss the edge it found.
 *
 * @returns {{ point: number[], dir: number[] }|null} the refined line, or null
 *          when too few edge pixels back the side to refit it
 */
function refineSide(p, q, grad, w, h, strong, reach = 4) {
    const len = dist(p, q);
    const dx = (q[0] - p[0]) / len;
    const dy = (q[1] - p[1]) / len;
    const nx = -dy;
    const ny = dx;
    const pts = [];
    for (let s = 0.05 * len; s <= 0.95 * len; s += 1) {
        let best = null;
        let bestMag = strong;
        for (let d = -reach; d <= reach; d++) {
            const xi = Math.round(p[0] + dx * s + nx * d);
            const yi = Math.round(p[1] + dy * s + ny * d);
            if (xi < 1 || yi < 1 || xi >= w - 1 || yi >= h - 1) continue;
            const i = yi * w + xi;
            const m = grad.mag[i];
            if (m < bestMag || Math.abs((grad.gx[i] * nx + grad.gy[i] * ny) / m) < 0.75) continue;
            bestMag = m;
            best = [xi, yi];
        }
        if (best) pts.push(best);
    }
    if (pts.length < Math.max(12, 0.3 * len)) return null;
    const mx = pts.reduce((a, v) => a + v[0], 0) / pts.length;
    const my = pts.reduce((a, v) => a + v[1], 0) / pts.length;
    let sxx = 0;
    let syy = 0;
    let sxy = 0;
    for (const [x, y] of pts) {
        sxx += (x - mx) ** 2;
        syy += (y - my) ** 2;
        sxy += (x - mx) * (y - my);
    }
    const angle = 0.5 * Math.atan2(2 * sxy, sxx - syy);

    return { point: [mx, my], dir: [Math.cos(angle), Math.sin(angle)] };
}

function intersectLines(a, b) {
    const det = a.dir[0] * b.dir[1] - a.dir[1] * b.dir[0];
    if (Math.abs(det) < 1e-9) return null;
    const t = ((b.point[0] - a.point[0]) * b.dir[1] - (b.point[1] - a.point[1]) * b.dir[0]) / det;

    return [a.point[0] + a.dir[0] * t, a.point[1] + a.dir[1] * t];
}

/** Refit all four sides; keep the original quad if any side cannot be refit. */
function refineQuad(quad, grad, w, h, strong) {
    const sides = [0, 1, 2, 3].map((i) => refineSide(quad[i], quad[(i + 1) % 4], grad, w, h, strong));
    if (sides.some((side) => side === null)) return quad;
    const refined = [0, 1, 2, 3].map((i) => intersectLines(sides[(i + 3) % 4], sides[i]));
    if (refined.some((pt) => pt === null || dist(pt, quad[refined.indexOf(pt)]) > 0.08 * Math.max(w, h))) return quad;

    return refined;
}

/**
 * Fraction of points along a side backed by a strong edge whose gradient is
 * perpendicular to that side. A card border scores near 1 over its full
 * length; a text baseline or the portrait edge does not.
 */
function sideSupport(p, q, grad, w, h, strong) {
    const len = dist(p, q);
    const samples = Math.max(16, Math.round(len / 4));
    const nx = -(q[1] - p[1]) / len;
    const ny = (q[0] - p[0]) / len;
    let hit = 0;
    let inside = 0;
    for (let s = 1; s < samples; s++) {
        const t = s / samples;
        const x = p[0] + (q[0] - p[0]) * t;
        const y = p[1] + (q[1] - p[1]) * t;
        if (x < 1 || y < 1 || x >= w - 1 || y >= h - 1) continue;
        inside++;
        for (let d = -2; d <= 2; d++) {
            const xi = Math.round(x + nx * d);
            const yi = Math.round(y + ny * d);
            if (xi < 1 || yi < 1 || xi >= w - 1 || yi >= h - 1) continue;
            const i = yi * w + xi;
            const m = grad.mag[i];
            if (m < strong) continue;
            if (Math.abs((grad.gx[i] * nx + grad.gy[i] * ny) / m) > 0.75) {
                hit++;
                break;
            }
        }
    }
    // A card slightly larger than the frame still shows most of each side:
    // judge the visible part. When most of a side is off-image there is not
    // enough evidence, so the missing points count against it.
    const total = Math.max(1, samples - 1);
    return inside >= 0.6 * total ? hit / Math.max(1, inside) : hit / total;
}

function quadSupport(quad, grad, w, h, strong) {
    const support = [0, 1, 2, 3].map((i) => sideSupport(quad[i], quad[(i + 1) % 4], grad, w, h, strong));

    return { weakest: Math.min(...support), mean: support.reduce((s, v) => s + v, 0) / 4 };
}

/**
 * Find the four corners of a KTP in an RGBA image.
 *
 * Gradient-restricted Hough transform for near-horizontal and near-vertical
 * lines, then the best convex, card-shaped quadrilateral whose four sides are
 * all backed by edge evidence along their full length.
 *
 * @returns {{ found: boolean, confidence: number, corners: number[][], reason: string }}
 *          corners are [tl, tr, br, bl] in SOURCE pixels; when not found they
 *          are the image corners, so the caller uses the whole image.
 */
export function detectCardQuad(image, options = {}) {
    const width = image?.width | 0;
    const height = image?.height | 0;
    const full = [[0, 0], [width - 1, 0], [width - 1, height - 1], [0, height - 1]];
    const none = (reason, confidence = 0) => ({ found: false, confidence, corners: full, reason });
    if (width < 32 || height < 32) return none('image_too_small');
    if (width * height > ROI_LIMITS.maxSourcePixels) return none('image_too_large');

    const small = downscaleGray(toGray(image), options.maxSide ?? ROI_LIMITS.detectionMaxSide);
    const { width: w, height: h } = small;
    const grad = sobel(small);
    // Printed text is usually higher-contrast than the card against the desk,
    // so a pure percentile threshold would keep only text strokes. Bound it.
    const strong = clamp(percentile(grad.mag, 0.85), 60, 240);

    const edges = [];
    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const i = y * w + x;
            if (grad.mag[i] < strong) continue;
            let angle = Math.round((Math.atan2(grad.gy[i], grad.gx[i]) * 180) / Math.PI);
            angle = ((angle % 180) + 180) % 180;
            edges.push({ x, y, angle });
        }
    }

    const horizontalThetas = new Uint8Array(180);
    const verticalThetas = new Uint8Array(180);
    for (let t = 0; t < 180; t++) {
        if (t >= 65 && t <= 115) horizontalThetas[t] = 1;
        if (t <= 25 || t >= 155) verticalThetas[t] = 1;
    }
    const hLines = candidateLines(houghPeaks(edges, w, h, horizontalThetas), (l) => lineY(l, w / 2));
    const vLines = candidateLines(houghPeaks(edges, w, h, verticalThetas), (l) => lineX(l, h / 2));
    if (hLines.length < 2 || vLines.length < 2) return none('edges_not_found');

    const imgArea = w * h;
    let best = null;
    for (let a = 0; a < hLines.length; a++) {
        for (let b = a + 1; b < hLines.length; b++) {
            for (let c = 0; c < vLines.length; c++) {
                for (let d = c + 1; d < vLines.length; d++) {
                    const pts = [
                        intersect(hLines[a], vLines[c]), intersect(hLines[a], vLines[d]),
                        intersect(hLines[b], vLines[d]), intersect(hLines[b], vLines[c]),
                    ];
                    if (pts.some((p) => p === null)) continue;
                    const quad = orderCorners(pts);
                    if (quad.some(([x, y]) => x < -0.15 * w || y < -0.15 * h || x > 1.15 * w || y > 1.15 * h)) continue;
                    if (!isConvex(quad)) continue;
                    const area = Math.abs(polygonArea(quad));
                    if (area < 0.25 * imgArea) continue;
                    const top = dist(quad[0], quad[1]);
                    const bottom = dist(quad[3], quad[2]);
                    const left = dist(quad[0], quad[3]);
                    const right = dist(quad[1], quad[2]);
                    const aspect = (top + bottom) / (left + right);
                    if (aspect < 1.25 || aspect > 1.95) continue;
                    if (top / bottom < 0.7 || top / bottom > 1.43 || left / right < 0.7 || left / right > 1.43) continue;

                    const { weakest, mean } = quadSupport(quad, grad, w, h, strong);
                    const score = mean + 0.35 * weakest + 0.15 * (area / imgArea);
                    if (!best || score > best.score) best = { quad, score, weakest, mean };
                }
            }
        }
    }

    if (!best) return none('no_card_shaped_quad');
    const refinedQuad = refineQuad(best.quad, grad, w, h, strong);
    if (refinedQuad !== best.quad) {
        const refined = quadSupport(refinedQuad, grad, w, h, strong);
        if (Math.min(refined.weakest, refined.mean) >= Math.min(best.weakest, best.mean)) {
            best = { ...best, quad: refinedQuad, ...refined };
        }
    }
    const confidence = Math.round(Math.min(best.weakest, best.mean) * 100) / 100;
    // Measured on the synthetic corpus: a real card border scores ~1.0 after
    // refinement, a quad closed by a row of TEXT ~0.56, a borderless scan
    // <= 0.38. A false crop would cut text off the card, which is worse than
    // falling back to the whole image, so the bar sits well above both.
    if (best.weakest < (options.minSideSupport ?? 0.75) || best.mean < (options.minMeanSupport ?? 0.85)) {
        return none('edges_too_weak', confidence);
    }

    const inv = 1 / small.scale;
    // Corners may lie just outside a frame that cut the card off; the warp
    // fills that area white, so they are kept as found (bounded to 15%).
    const corners = best.quad.map(([x, y]) => [round2(x * inv), round2(y * inv)]);

    return { found: true, confidence, corners, reason: 'detected' };
}

function round2(v) {
    return Math.round(v * 100) / 100;
}

/** Order four points as [top-left, top-right, bottom-right, bottom-left]. */
export function orderCorners(points) {
    const cx = points.reduce((s, p) => s + p[0], 0) / 4;
    const cy = points.reduce((s, p) => s + p[1], 0) / 4;
    const sorted = [...points].sort((p, q) => Math.atan2(p[1] - cy, p[0] - cx) - Math.atan2(q[1] - cy, q[0] - cx));
    // atan2 sorting starts at the leftmost-back direction; rotate so the point
    // with the smallest x+y (top-left) comes first.
    let start = 0;
    for (let i = 1; i < 4; i++) {
        if (sorted[i][0] + sorted[i][1] < sorted[start][0] + sorted[start][1]) start = i;
    }

    return [0, 1, 2, 3].map((k) => sorted[(start + k) % 4]);
}

/** Corners are usable when they form a convex quad of real area inside the image. */
export function validCorners(corners, width, height) {
    if (!Array.isArray(corners) || corners.length !== 4) return false;
    if (corners.some((c) => !Array.isArray(c) || c.length !== 2 || !c.every(Number.isFinite))) return false;
    if (corners.some(([x, y]) => x < -0.15 * width || y < -0.15 * height || x > 1.15 * width || y > 1.15 * height)) return false;
    const quad = orderCorners(corners);

    return isConvex(quad) && Math.abs(polygonArea(quad)) >= 0.05 * width * height;
}

/* ---------------------------------------------------- perspective warp -- */

/**
 * Homography H (row-major 3x3, h33 = 1) mapping each `from` point onto the
 * matching `to` point. Solved by Gaussian elimination with partial pivoting.
 */
export function computeHomography(from, to) {
    const m = [];
    for (let i = 0; i < 4; i++) {
        const [x, y] = from[i];
        const [u, v] = to[i];
        m.push([x, y, 1, 0, 0, 0, -u * x, -u * y, u]);
        m.push([0, 0, 0, x, y, 1, -v * x, -v * y, v]);
    }
    for (let col = 0; col < 8; col++) {
        let pivot = col;
        for (let r = col + 1; r < 8; r++) if (Math.abs(m[r][col]) > Math.abs(m[pivot][col])) pivot = r;
        if (Math.abs(m[pivot][col]) < 1e-10) return null;
        [m[col], m[pivot]] = [m[pivot], m[col]];
        for (let r = 0; r < 8; r++) {
            if (r === col) continue;
            const f = m[r][col] / m[col][col];
            for (let c = col; c < 9; c++) m[r][c] -= f * m[col][c];
        }
    }
    const h = m.map((row, i) => row[8] / row[i]);

    return [...h, 1];
}

export function applyHomography(H, x, y) {
    const w = H[6] * x + H[7] * y + H[8];

    return [(H[0] * x + H[1] * y + H[2]) / w, (H[3] * x + H[4] * y + H[5]) / w];
}

/**
 * Warp the quad `corners` ([tl,tr,br,bl], source pixels) onto a rectangle of
 * `outWidth` x `outHeight` with bilinear sampling. Outside the source the
 * canvas is white, so a corner set slightly off-image adds no dark border
 * for the OCR to read.
 */
export function warpPerspective(image, corners, outWidth = NORMALIZED_WIDTH, outHeight = NORMALIZED_HEIGHT) {
    const dst = [[0, 0], [outWidth - 1, 0], [outWidth - 1, outHeight - 1], [0, outHeight - 1]];
    const H = computeHomography(dst, orderCorners(corners));
    if (!H) throw new Error('KTP corners are degenerate.');
    const { width: sw, height: sh, data } = image;
    const out = new Uint8ClampedArray(outWidth * outHeight * 4);
    for (let y = 0; y < outHeight; y++) {
        for (let x = 0; x < outWidth; x++) {
            const [fx, fy] = applyHomography(H, x, y);
            const o = (y * outWidth + x) * 4;
            if (fx < 0 || fy < 0 || fx > sw - 1 || fy > sh - 1) {
                out[o] = out[o + 1] = out[o + 2] = out[o + 3] = 255;
                continue;
            }
            const x0 = fx | 0;
            const y0 = fy | 0;
            const x1 = Math.min(sw - 1, x0 + 1);
            const y1 = Math.min(sh - 1, y0 + 1);
            const tx = fx - x0;
            const ty = fy - y0;
            const i00 = (y0 * sw + x0) * 4;
            const i01 = (y0 * sw + x1) * 4;
            const i10 = (y1 * sw + x0) * 4;
            const i11 = (y1 * sw + x1) * 4;
            for (let c = 0; c < 3; c++) {
                out[o + c] = (data[i00 + c] * (1 - tx) + data[i01 + c] * tx) * (1 - ty)
                    + (data[i10 + c] * (1 - tx) + data[i11 + c] * tx) * ty;
            }
            out[o + 3] = 255;
        }
    }

    return { width: outWidth, height: outHeight, data: out, channels: 4 };
}

/* ------------------------------------------------- whole-card OCR words -- */

/** Lines with word boxes, in normalized card coordinates. */
export function toWordLines(data, width, height) {
    const out = [];
    for (const block of data?.blocks ?? []) {
        for (const paragraph of block?.paragraphs ?? []) {
            for (const line of paragraph?.lines ?? []) {
                const words = (line?.words ?? [])
                    .filter((w) => w?.bbox && String(w.text ?? '').trim() !== '')
                    .map((w) => ({
                        text: String(w.text).trim().toUpperCase(),
                        x0: w.bbox.x0 / width,
                        x1: w.bbox.x1 / width,
                        y0: w.bbox.y0 / height,
                        y1: w.bbox.y1 / height,
                    }));
                if (words.length) out.push(words);
            }
        }
    }

    return out;
}

/* ------------------------------------------------------ template anchor -- */

function fitLine(pairs) {
    const n = pairs.length;
    const mx = pairs.reduce((s, p) => s + p[0], 0) / n;
    const my = pairs.reduce((s, p) => s + p[1], 0) / n;
    let sxx = 0;
    let sxy = 0;
    for (const [x, y] of pairs) {
        sxx += (x - mx) ** 2;
        sxy += (x - mx) * (y - my);
    }
    const a = sxx > 1e-9 ? sxy / sxx : 1;

    return { a, b: my - a * mx };
}

function median(values) {
    const s = [...values].sort((p, q) => p - q);

    return s.length ? s[Math.floor(s.length / 2)] : 0;
}

/**
 * Snap the template to the card the whole-card OCR actually read.
 *
 * Each OCR line whose FIRST word matches a template label is an anchor. The
 * row positions are fitted (scale + offset, outliers dropped) so a template
 * that is uniformly off — different print run, imperfect corners — moves as a
 * whole; with fewer than two anchors the template is kept. Where the colon of
 * a row was read, the value box starts just after it.
 *
 * @returns {{ boxes: Object<string, object>, anchors: number, fit: object }}
 */
export function anchorTemplate(wordLines, template = KTP_TEMPLATE) {
    const anchors = [];
    for (const line of wordLines ?? []) {
        // A sliver of card edge is often read as '|', '—' or ':' in front of
        // the label; the label is the first word that has a letter or digit.
        const start = line.findIndex((w) => /[A-Z0-9]/.test(w.text));
        if (start < 0) continue;
        const words = line.slice(start);
        const first = words[0];
        const row = template.rows.find((r) => r.label.test(first.text));
        if (!row || anchors.some((a) => a.row === row)) continue;
        anchors.push({ row, cy: (first.y0 + first.y1) / 2, labelX: first.x0, valueX: valueStartAfterColon(words) });
    }

    let fit = { a: 1, b: 0, method: 'template' };
    let used = anchors;
    if (anchors.length >= 3) {
        let candidate = fitLine(anchors.map((a) => [a.row.y, a.cy]));
        used = anchors.filter((a) => Math.abs(candidate.a * a.row.y + candidate.b - a.cy) < 0.02);
        if (used.length >= 3) candidate = fitLine(used.map((a) => [a.row.y, a.cy]));
        if (used.length >= 3 && candidate.a > 0.8 && candidate.a < 1.25) fit = { ...candidate, method: 'fitted' };
    }
    if (fit.method === 'template' && anchors.length >= 1) {
        const shift = median(anchors.map((a) => a.cy - a.row.y));
        if (Math.abs(shift) < 0.06) fit = { a: 1, b: shift, method: 'shifted' };
        used = anchors;
    }

    const xShifts = used.map((a) => a.labelX - a.row.labelX).filter((d) => Math.abs(d) < 0.06);
    const xShift = xShifts.length >= 2 ? median(xShifts) : 0;

    const boxes = {};
    for (const row of template.rows) {
        if (!row.key) continue;
        const cy = fit.a * row.y + fit.b;
        const h = row.h * fit.a;
        const anchor = used.find((a) => a.row === row);
        let x = row.x + xShift;
        if (anchor?.valueX && Math.abs(anchor.valueX.x - row.x) < 0.08) {
            // Half a character of margin: room for the first glyph, not the colon.
            x = anchor.valueX.x - clamp(anchor.valueX.charWidth * 0.5, 0.004, 0.01);
        }
        boxes[row.key] = clampBox({ x, y: cy - h / 2, w: row.x2 + xShift - x, h });
    }

    return { boxes, anchors: used.length, fit: { ...fit, xShift } };
}

/* ------------------------------------------------- field preprocessing -- */

/**
 * Where the value text of a label line begins: just after the colon, whether
 * OCR read the colon as its own word (':'), at the end of the label
 * ('HINGGA:') or glued to the value (':7371…'). Null when no colon was read.
 *
 * @returns {{ x: number, charWidth: number }|null}
 */
export function valueStartAfterColon(words) {
    for (let i = 1; i < words.length; i++) {
        const w = words[i];
        const at = w.text.indexOf(':');
        if (at < 0) continue;
        const charWidth = (w.x1 - w.x0) / Math.max(1, w.text.length);
        if (at < w.text.length - 1) {
            // Colon glued to the value: the value starts one character in.
            return { x: w.x0 + (at + 1) * charWidth, charWidth };
        }
        const next = words[i + 1];
        if (!next) return null;
        return { x: next.x0, charWidth: (next.x1 - next.x0) / Math.max(1, next.text.length) };
    }

    return null;
}

/**
 * Paint a thin white frame over the card edge. A few pixels of corner error
 * leave a dark sliver of background there, which OCR reads as '|' or '—' and
 * which would otherwise sit in front of every label. No KTP text lives in the
 * outer 1% of the card.
 */
export function whitenBorder(card, fraction = 0.008) {
    const { width: w, height: h, data } = card;
    const bx = Math.max(1, Math.round(w * fraction));
    const by = Math.max(1, Math.round(h * fraction));
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (x >= bx && x < w - bx && y >= by && y < h - by) {
                x = w - bx - 1;
                continue;
            }
            const o = (y * w + x) * 4;
            data[o] = data[o + 1] = data[o + 2] = data[o + 3] = 255;
        }
    }

    return card;
}

/** Crop a normalized box from an RGBA card into a gray image. */
export function cropGray(card, box) {
    const r = boxToPixels(box, card.width, card.height);
    const out = new Uint8ClampedArray(r.width * r.height);
    const gray = card.channels === 1;
    for (let y = 0; y < r.height; y++) {
        for (let x = 0; x < r.width; x++) {
            const s = (r.y + y) * card.width + (r.x + x);
            out[y * r.width + x] = gray
                ? card.data[s]
                : (card.data[s * 4] * 299 + card.data[s * 4 + 1] * 587 + card.data[s * 4 + 2] * 114) / 1000;
        }
    }

    return { width: r.width, height: r.height, data: out, channels: 1 };
}

/** Linear contrast stretch between the 2nd and 98th percentiles. */
export function enhanceContrast(gray) {
    const hist = new Uint32Array(256);
    for (const v of gray.data) hist[v]++;
    const n = gray.data.length;
    let lo = 0;
    let hi = 255;
    for (let acc = 0, i = 0; i < 256; i++) {
        acc += hist[i];
        if (acc >= n * 0.02) { lo = i; break; }
    }
    for (let acc = 0, i = 255; i >= 0; i--) {
        acc += hist[i];
        if (acc >= n * 0.02) { hi = i; break; }
    }
    const out = new Uint8ClampedArray(n);
    const span = Math.max(1, hi - lo);
    for (let i = 0; i < n; i++) out[i] = ((gray.data[i] - lo) * 255) / span;

    return { ...gray, data: out };
}

/**
 * Sauvola adaptive threshold. Local, so uneven lighting across a field does
 * not erase the darker or lighter half of a value.
 */
export function binarize(gray, k = 0.25, r = 128) {
    const { width: w, height: h, data } = gray;
    const win = Math.max(15, (Math.round(h * 0.9) | 1));
    const half = win >> 1;
    const sum = new Float64Array((w + 1) * (h + 1));
    const sq = new Float64Array((w + 1) * (h + 1));
    for (let y = 0; y < h; y++) {
        let rs = 0;
        let rq = 0;
        for (let x = 0; x < w; x++) {
            const v = data[y * w + x];
            rs += v;
            rq += v * v;
            sum[(y + 1) * (w + 1) + x + 1] = sum[y * (w + 1) + x + 1] + rs;
            sq[(y + 1) * (w + 1) + x + 1] = sq[y * (w + 1) + x + 1] + rq;
        }
    }
    const out = new Uint8ClampedArray(w * h);
    for (let y = 0; y < h; y++) {
        const y0 = Math.max(0, y - half);
        const y1 = Math.min(h, y + half + 1);
        for (let x = 0; x < w; x++) {
            const x0 = Math.max(0, x - half);
            const x1 = Math.min(w, x + half + 1);
            const area = (y1 - y0) * (x1 - x0);
            const a = y1 * (w + 1);
            const b = y0 * (w + 1);
            const s = sum[a + x1] - sum[b + x1] - sum[a + x0] + sum[b + x0];
            const q = sq[a + x1] - sq[b + x1] - sq[a + x0] + sq[b + x0];
            const mean = s / area;
            const sd = Math.sqrt(Math.max(0, q / area - mean * mean));
            out[y * w + x] = data[y * w + x] > mean * (1 + k * (sd / r - 1)) ? 255 : 0;
        }
    }

    return { ...gray, data: out };
}

/** White margin around a crop — tesseract reads a line best with room around it. */
export function padGray(gray, pad = 12, value = 255) {
    const w = gray.width + pad * 2;
    const h = gray.height + pad * 2;
    const out = new Uint8ClampedArray(w * h).fill(value);
    for (let y = 0; y < gray.height; y++) {
        out.set(gray.data.subarray(y * gray.width, (y + 1) * gray.width), (y + pad) * w + pad);
    }

    return { width: w, height: h, data: out, channels: 1 };
}

/**
 * Build the preprocessing variants for one field box, cheapest first:
 *   'enhanced'  — grayscale, rescaled, contrast stretched
 *   'binarized' — the same, adaptively thresholded
 * The crop is rescaled so capital letters are ~24 px tall — measured on the
 * synthetic corpus as the best of 16/20/24/28 px across every field; the
 * large NIK digits in particular are misread when left at their printed size.
 * Template boxes are ~1.9x the cap height (CAP_RATIO). Scaling is bounded to
 * 0.5x..3x.
 */
export const TARGET_CAP_HEIGHT = 24;
export const CAP_RATIO = 0.52;

export function fieldVariants(card, box) {
    const crop = cropGray(card, box);
    const rowsInBox = box.h > 0.078 ? Math.max(1, Math.round(box.h / 0.05)) : 1;
    const capHeight = (crop.height * CAP_RATIO) / rowsInBox;
    const factor = clamp(TARGET_CAP_HEIGHT / Math.max(1, capHeight), 0.5, 3);
    const enhanced = enhanceContrast(scaleGray(crop, factor));

    return [
        { name: 'enhanced', image: padGray(enhanced) },
        { name: 'binarized', image: padGray(binarize(enhanced)) },
    ];
}

/** Tesseract parameters for one field read. */
export function fieldOcrParams(key, box) {
    const spec = FIELD_SPECS[key];
    if (!spec) throw new Error(`Unknown KTP field: ${key}`);
    // A box taller than ~1.6 rows holds a wrapped value: read it as a block.
    const multiline = box && box.h > 0.078;

    return {
        tessedit_pageseg_mode: multiline ? '6' : '7',
        tessedit_char_whitelist: spec.whitelist,
        preserve_interword_spaces: '1',
    };
}

/** Bounded, single-line text from a field read; separators the colon left behind are dropped. */
export function cleanFieldText(text, limits = ROI_LIMITS) {
    return String(text ?? '')
        .replace(/[\r\n\t]+/g, ' ')
        .replace(/\s+/g, ' ')
        .replace(/^[\s:;.,'|\-–—_]+/u, '')
        .replace(/[\s:;,'|\-–—_]+$/u, '')
        .trim()
        .slice(0, limits.maxLineLength);
}

/**
 * Cheap client-side plausibility check used ONLY to decide whether a second
 * preprocessing pass is worth running. The server re-validates everything.
 */
export function plausibleFieldText(key, text) {
    const t = String(text ?? '');
    switch (key) {
        case 'nik': return /^\d{16}$/.test(t.replace(/\s/g, ''));
        case 'rt_rw': return /^\d{1,3}\/\d{1,3}$/.test(t.replace(/\s/g, ''));
        case 'birth_place_date': return /\d{1,2}\s*[-\/.]\s*\d{1,2}\s*[-\/.]\s*\d{4}/.test(t);
        case 'gender': return /LAKI\s*-?\s*LAKI|PEREMPUAN/.test(t);
        default: return /[A-Z0-9]{2,}/.test(t);
    }
}

function flattenText(data) {
    const lines = [];
    for (const block of data?.blocks ?? []) {
        for (const paragraph of block?.paragraphs ?? []) {
            for (const line of paragraph?.lines ?? []) {
                const text = String(line?.text ?? '').trim();
                if (text) lines.push({ text, confidence: Number(line?.confidence) });
            }
        }
    }
    if (!lines.length && data?.text) lines.push({ text: String(data.text), confidence: Number(data.confidence) });
    const text = lines.map((l) => l.text).join(' ');
    const confs = lines.map((l) => l.confidence).filter(Number.isFinite);
    const confidence = confs.length ? confs.reduce((s, v) => s + v, 0) / confs.length : null;

    return { text, confidence };
}

/**
 * Read one field from the normalized card with at most two preprocessing
 * passes. The second pass runs only when the first is implausible or
 * uncertain; the better read wins on plausibility first, confidence second.
 */
export async function readField(card, key, box, recognize, options = {}) {
    const params = fieldOcrParams(key, box);
    const variants = fieldVariants(card, box);
    const threshold = options.threshold ?? 80;
    let best = null;
    const plausibleTexts = new Set();
    for (const variant of variants.slice(0, options.maxPasses ?? 2)) {
        const data = await recognize(variant.image, params);
        const { text, confidence } = flattenText(data);
        const cleaned = cleanFieldText(text);
        const candidate = {
            text: cleaned === '' ? null : cleaned,
            confidence: Number.isFinite(confidence) ? Math.round(Math.max(0, Math.min(100, confidence)) * 10) / 10 : null,
            variant: variant.name,
            plausible: cleaned !== '' && plausibleFieldText(key, cleaned),
        };
        if (candidate.plausible) plausibleTexts.add(cleaned.replace(/\s+/g, ' '));
        if (!best || (candidate.plausible && !best.plausible)
            || (candidate.plausible === best.plausible && (candidate.confidence ?? -1) > (best.confidence ?? -1))) {
            best = candidate;
        }
        if (best.plausible && (best.confidence ?? 0) >= threshold) break;
    }
    if (plausibleTexts.size > 1) {
        // The two passes produced different plausible text: the field read
        // disagreed with itself. Confidence must not settle that, so the score
        // is dropped — the server then treats this read as unconfirmed, and only
        // agreement with the whole-card read can make it a clean value.
        return { ...best, confidence: null, passesDisagree: true };
    }

    return best;
}

/* -------------------------------------------------------- orchestration -- */

/**
 * The complete field-based read of one captured image.
 *
 * @param {object} image  RGBA source pixels ({ width, height, data })
 * @param {object} deps
 *   recognize(img, params|null) -> tesseract.js `data` (with blocks). `null`
 *     params means "the engine's defaults" — used for the whole-card read so
 *     it stays the read the shipped pipeline made.
 *   corners?  operator-adjusted [tl,tr,br,bl]; skips detection
 *   boxes?    operator-adjusted field boxes; skips anchoring
 *   fields?   restrict the per-field pass to these keys
 *   onStage?  progress callback (stage name)
 */
export async function runFieldOcr(image, deps) {
    const timings = {};
    let t = performanceNow();
    const lap = (name) => {
        const now = performanceNow();
        timings[name] = Math.round(now - t);
        t = now;
    };
    const stage = (name) => deps.onStage?.(name);

    if (!image || image.width * image.height > ROI_LIMITS.maxSourcePixels) {
        throw new Error('Gambar KTP terlalu besar untuk dibaca.');
    }

    stage('boundary');
    let boundary;
    if (deps.corners && validCorners(deps.corners, image.width, image.height)) {
        // 'live': found on the captured pixels by the live overlay's capture
        // check (REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1). Anything else
        // supplied here is the operator's corner edit.
        const live = deps.cornerSource === 'live';
        boundary = {
            found: true,
            confidence: live && typeof deps.cornerConfidence === 'number' ? deps.cornerConfidence : 1,
            corners: orderCorners(deps.corners),
            reason: live ? 'live' : 'manual',
        };
    } else {
        boundary = detectCardQuad(image);
    }
    lap('boundary');
    await deps.yieldToUi?.();

    stage('normalize');
    const card = whitenBorder(warpPerspective(image, boundary.corners));
    lap('normalize');
    await deps.yieldToUi?.();

    stage('document');
    const docData = await deps.recognize(card, null);
    const lines = toOcrLines(docData);
    lap('document');

    let boxes = deps.boxes;
    let anchoring = { anchors: 0, fit: { method: 'operator' } };
    if (!boxes) {
        anchoring = anchorTemplate(toWordLines(docData, card.width, card.height));
        boxes = anchoring.boxes;
    }

    stage('fields');
    const fields = {};
    for (const key of deps.fields ?? ROI_FIELD_KEYS) {
        if (!boxes[key]) continue;
        const read = await readField(card, key, boxes[key], deps.recognize);
        fields[key] = { text: read.text, confidence: read.confidence, variant: read.variant };
    }
    lap('fields');

    return {
        lines,
        fields,
        boxes,
        card,
        corners: boundary.corners,
        boundary: { found: boundary.found, confidence: boundary.confidence, reason: boundary.reason },
        anchoring: { anchors: anchoring.anchors, method: anchoring.fit.method },
        templateVersion: KTP_TEMPLATE.version,
        timings,
    };
}

function performanceNow() {
    return typeof performance !== 'undefined' ? performance.now() : Date.now();
}
