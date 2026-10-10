/**
 * REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — live field overlay on the
 * camera preview (DOM side).
 *
 * Loaded lazily by ktp-camera-ocr.js only once the camera is open — and the
 * camera only opens after the KTP holder's D7 "yes" (rule 180 §3). Every tick
 * re-asks `isAllowed()`; a "no", a closed camera or a cleared page ends the
 * analysis before the next frame is read.
 *
 * What it does:
 *  - reads a DOWNSCALED copy of the preview a few times a second (never two at
 *    once, slower on slower devices) and finds the card with the shipped
 *    detector — in a module worker when the browser has one, else here;
 *  - draws the card outline and the template's field boxes over the video as an
 *    SVG layer, following the card's position and perspective;
 *  - shows a RED / YELLOW / GREEN indicator with plain instructions.
 *
 * What it never does: OCR on the preview, drawing the overlay into a captured
 * image, sending anything over the network, or storing anything in the browser.
 * Pixels stay in memory and are released when the overlay is destroyed.
 */
import { guideCropRect } from './ktp-camera-ocr.js';
import { ROI_LIMITS } from './ktp-roi-ocr.js';
import {
    LIVE_LIMITS,
    analyzeFrame,
    captureCropFor,
    confirmCaptureGeometry,
    cornersToHint,
    createTrackingLoop,
    displayView,
    frameToDisplay,
    guidanceFor,
    isMirroredTransform,
    overlayGeometry,
} from './ktp-live-overlay.js';

const SVG_NS = 'http://www.w3.org/2000/svg';
// Design tokens from tailwind.config.js (danger / warning / success). Set as SVG
// attributes because Tailwind only compiles classes it finds in Blade views.
const LEVEL_COLORS = Object.freeze({ red: '#DC2626', yellow: '#D97706', green: '#059669' });
const HALO = 'rgba(0, 0, 0, 0.55)';
const HINT = 'rgba(255, 255, 255, 0.9)';
const LABEL_MIN_CARD_WIDTH = 340; // CSS px of the drawn card before field labels are worth showing
const STATS_EVERY_MS = 1000;

function svgEl(tag, attrs = {}) {
    const node = document.createElementNS(SVG_NS, tag);
    for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, String(v));

    return node;
}

function workerSupported() {
    return typeof Worker === 'function' && typeof OffscreenCanvas === 'function' && typeof createImageBitmap === 'function';
}

function pixelsOf(ctx, width, height) {
    const img = ctx.getImageData(0, 0, width, height);

    return { width: img.width, height: img.height, data: img.data, channels: 4 };
}

/**
 * @param {{ root: Element, video: HTMLVideoElement, isAllowed: () => boolean, startedAt?: number }} deps
 */
export function createLiveOverlay({ root, video, isAllowed, startedAt = performance.now(), onStopped = null }) {
    const svg = root.querySelector('[data-ktp-live-overlay]');
    const panel = root.querySelector('[data-ktp-live-panel]');
    const guide = root.querySelector('[data-ktp-camera-guide]');
    const guidanceEl = root.querySelector('[data-ktp-live-guidance]');
    const levelEls = [...root.querySelectorAll('[data-ktp-live-level]')];
    if (!svg || !video) throw new Error('live overlay markup missing');

    /* ---- SVG layers (built once, only attributes change per analysis) ---- */
    const hint = svgEl('rect', { fill: 'none', stroke: HINT, 'stroke-width': 2, 'stroke-dasharray': '10 7', rx: 10, 'data-ktp-live-hint': '' });
    const outlineHalo = svgEl('polygon', { fill: 'none', stroke: HALO, 'stroke-width': 5, 'stroke-linejoin': 'round' });
    const outline = svgEl('polygon', { fill: 'none', 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'data-ktp-live-outline': '' });
    const fieldLayer = svgEl('g', { 'data-ktp-live-fields': '' });
    svg.replaceChildren(hint, outlineHalo, outline, fieldLayer);
    const fieldEls = new Map();

    const fieldElFor = (key, label) => {
        let el = fieldEls.get(key);
        if (!el) {
            const group = svgEl('g', { 'data-ktp-live-field': key });
            const halo = svgEl('polygon', { fill: 'none', stroke: HALO, 'stroke-width': 3.5 });
            const poly = svgEl('polygon', { 'fill-opacity': 0.07, 'stroke-width': 1.5 });
            const text = svgEl('text', {
                'font-size': 10, 'font-weight': 600, fill: '#FFFFFF', stroke: 'rgba(0,0,0,0.75)',
                'stroke-width': 3, 'paint-order': 'stroke', 'font-family': 'ui-sans-serif, system-ui, sans-serif',
            });
            text.textContent = label; // the template's own label — never OCR text
            group.append(halo, poly, text);
            fieldLayer.appendChild(group);
            el = { group, halo, poly, text };
            fieldEls.set(key, el);
        }

        return el;
    };

    const show = (node, visible) => node.setAttribute('visibility', visible ? 'visible' : 'hidden');

    /* ---- drawing ---- */
    let lastState = null;
    let shownLevel = null;
    let shownGuidance = null;

    const render = (state = lastState) => {
        lastState = state;
        const vw = video.videoWidth;
        const vh = video.videoHeight;
        const bw = video.clientWidth;
        const bh = video.clientHeight;
        const style = getComputedStyle(video);
        const view = displayView({ srcW: vw, srcH: vh, boxW: bw, boxH: bh, fit: style.objectFit, mirrored: isMirroredTransform(style.transform) });
        if (!view) {
            svg.classList.add('hidden');

            return;
        }
        svg.classList.remove('hidden');
        svg.setAttribute('viewBox', `0 0 ${bw} ${bh}`);
        svg.setAttribute('width', bw);
        svg.setAttribute('height', bh);
        svg.style.left = `${video.offsetLeft}px`;
        svg.style.top = `${video.offsetTop}px`;
        const points = (quad) => quad.map((p) => frameToDisplay(p, view).map((n) => n.toFixed(1)).join(',')).join(' ');

        const geo = state?.displayCorners ? overlayGeometry(state.displayCorners) : null;
        if (!geo) {
            // Neutral state: where to put the card. Nothing is claimed as found.
            const g = guideCropRect(vw, vh);
            const [ax, ay] = frameToDisplay([g.x, g.y], view);
            const [bx, by] = frameToDisplay([g.x + g.width, g.y + g.height], view);
            hint.setAttribute('x', Math.min(ax, bx).toFixed(1));
            hint.setAttribute('y', Math.min(ay, by).toFixed(1));
            hint.setAttribute('width', Math.abs(bx - ax).toFixed(1));
            hint.setAttribute('height', Math.abs(by - ay).toFixed(1));
            show(hint, true);
            show(outline, false);
            show(outlineHalo, false);
            show(fieldLayer, false);

            return;
        }
        const color = LEVEL_COLORS[state.level] ?? LEVEL_COLORS.red;
        show(hint, false);
        const outlinePts = points(geo.outline);
        outlineHalo.setAttribute('points', outlinePts);
        outline.setAttribute('points', outlinePts);
        outline.setAttribute('stroke', color);
        show(outlineHalo, true);
        show(outline, true);
        show(fieldLayer, true);
        const [tl, tr] = [frameToDisplay(geo.outline[0], view), frameToDisplay(geo.outline[1], view)];
        const labels = Math.hypot(tr[0] - tl[0], tr[1] - tl[1]) >= LABEL_MIN_CARD_WIDTH;
        for (const field of geo.fields) {
            const el = fieldElFor(field.key, field.label);
            const pts = points(field.quad);
            el.halo.setAttribute('points', pts);
            el.poly.setAttribute('points', pts);
            el.poly.setAttribute('stroke', color);
            el.poly.setAttribute('fill', color);
            const [lx, ly] = frameToDisplay(field.quad[0], view);
            el.text.setAttribute('x', (lx + 2).toFixed(1));
            el.text.setAttribute('y', (ly - 2).toFixed(1));
            show(el.text, labels);
        }
    };

    const updatePanel = (state) => {
        if (state.level !== shownLevel) {
            for (const el of levelEls) el.classList.toggle('hidden', el.dataset.ktpLiveLevel !== state.level);
            shownLevel = state.level;
            svg.dataset.liveLevel = state.level;
        }
        const text = guidanceFor(state.issues);
        if (text !== shownGuidance && guidanceEl) {
            guidanceEl.textContent = text;
            shownGuidance = text;
        }
    };

    /* ---- frame reading ---- */
    const analysisCanvas = document.createElement('canvas');
    const actx = analysisCanvas.getContext('2d', { willReadFrequently: true });

    const analysisSize = (vw, vh) => {
        if (!(vw > 0 && vh > 0) || vw > LIVE_LIMITS.maxSourceSide || vh > LIVE_LIMITS.maxSourceSide) return null;
        const s = Math.min(1, LIVE_LIMITS.analysisMaxSide / Math.max(vw, vh));

        return { w: Math.max(32, Math.round(vw * s)), h: Math.max(32, Math.round(vh * s)) };
    };

    const sizeCanvas = (w, h) => {
        if (analysisCanvas.width !== w) analysisCanvas.width = w;
        if (analysisCanvas.height !== h) analysisCanvas.height = h;
    };

    /* ---- worker (with main-thread fallback) ---- */
    let worker = null;
    let mode = 'main';
    let seq = 0;
    const waiting = new Map();

    const dropWorker = () => {
        worker?.terminate();
        worker = null;
        mode = 'main';
        for (const entry of waiting.values()) {
            clearTimeout(entry.timer);
            entry.resolve({ found: false, reason: 'worker_unavailable' });
        }
        waiting.clear();
    };

    if (workerSupported()) {
        try {
            worker = new Worker(new URL('./ktp-live-tracker.worker.js', import.meta.url), { type: 'module' });
            worker.onmessage = ({ data }) => {
                const entry = waiting.get(data?.id);
                if (!entry) return;
                waiting.delete(data.id);
                clearTimeout(entry.timer);
                entry.resolve(data.observation ?? { found: false, reason: 'worker_unavailable' });
            };
            worker.onerror = dropWorker;
            worker.onmessageerror = dropWorker;
            mode = 'worker';
        } catch {
            dropWorker();
        }
    }

    const analyzeInWorker = (frame) => new Promise((resolve) => {
        if (!worker) {
            frame.bitmap.close?.();
            resolve({ found: false, reason: 'worker_unavailable' });

            return;
        }
        const id = ++seq;
        const timer = setTimeout(() => {
            if (waiting.delete(id)) {
                dropWorker();
                resolve({ found: false, reason: 'worker_timeout' });
            }
        }, LIVE_LIMITS.workerTimeoutMs);
        waiting.set(id, { resolve, timer });
        worker.postMessage({ id, bitmap: frame.bitmap, sourceWidth: frame.sourceWidth, sourceHeight: frame.sourceHeight }, [frame.bitmap]);
    });

    const grabFrame = async () => {
        if (video.readyState < 2) return null;
        const vw = video.videoWidth;
        const vh = video.videoHeight;
        const size = analysisSize(vw, vh);
        if (!size) return null;
        if (worker) {
            try {
                const bitmap = await createImageBitmap(video, { resizeWidth: size.w, resizeHeight: size.h, resizeQuality: 'medium' });

                return { bitmap, sourceWidth: vw, sourceHeight: vh };
            } catch {
                dropWorker(); // this browser cannot hand frames to the worker
            }
        }
        sizeCanvas(size.w, size.h);
        actx.drawImage(video, 0, 0, size.w, size.h);

        return { image: pixelsOf(actx, size.w, size.h), sourceWidth: vw, sourceHeight: vh };
    };

    const analyze = (frame) => (frame.bitmap
        ? analyzeInWorker(frame)
        : Promise.resolve(analyzeFrame(frame.image, { sourceWidth: frame.sourceWidth, sourceHeight: frame.sourceHeight })));

    /* ---- loop ---- */
    const rvfc = typeof video.requestVideoFrameCallback === 'function';
    let ticks = 0;
    let detections = 0;
    let firstStateMs = null;
    let lastStatsAt = 0;
    const tickWindow = [];

    const writeStats = (force = false) => {
        const t = performance.now();
        if (!force && t - lastStatsAt < STATS_EVERY_MS) return;
        lastStatsAt = t;
        while (tickWindow.length && t - tickWindow[0] > 2000) tickWindow.shift();
        // Counts and timings only: no corners, no pixels, no text.
        svg.dataset.liveStats = JSON.stringify({
            mode,
            ticks,
            detections,
            ticksPerSecond: tickWindow.length / 2,
            firstStateMs,
            level: lastState?.level ?? null,
            ...loop.stats(),
        });
    };

    const loop = createTrackingLoop({
        isAllowed,
        grabFrame,
        analyze,
        now: () => performance.now(),
        requestTick: (cb) => {
            const wrapped = () => {
                ticks++;
                tickWindow.push(performance.now());
                cb();
            };

            return rvfc ? video.requestVideoFrameCallback(wrapped) : requestAnimationFrame(wrapped);
        },
        cancelTick: (h) => (rvfc ? video.cancelVideoFrameCallback(h) : cancelAnimationFrame(h)),
        onState: (state, observation) => {
            if (firstStateMs === null) firstStateMs = Math.round(performance.now() - startedAt);
            if (observation?.found) detections++;
            render(state);
            updatePanel(state);
            writeStats();
        },
        onBlocked: () => destroy(),
        onError: () => {},
    });

    const resizeObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(() => render()) : null;
    const onVideoResize = () => render();

    let destroyed = false;
    function destroy() {
        if (destroyed) return;
        destroyed = true;
        loop.stop();
        dropWorker();
        resizeObserver?.disconnect();
        video.removeEventListener('resize', onVideoResize);
        svg.replaceChildren();
        svg.classList.add('hidden');
        delete svg.dataset.liveLevel;
        panel?.classList.add('hidden');
        guide?.classList.remove('hidden');
        for (const el of levelEls) el.classList.add('hidden');
        if (guidanceEl) guidanceEl.textContent = '';
        analysisCanvas.width = 0;
        analysisCanvas.height = 0;
        fieldEls.clear();
        lastState = null;
        onStopped?.(); // the owner forgets this overlay, however it ended
    }

    return {
        /** Starts tracking; false when not allowed (no frame is ever read then). */
        start() {
            if (destroyed || !loop.start()) return false;
            guide?.classList.add('hidden'); // the live hint frame replaces the static guide
            panel?.classList.remove('hidden');
            updatePanel(loop.state());
            render(loop.state());
            resizeObserver?.observe(video);
            video.addEventListener('resize', onVideoResize);

            return true;
        },

        /**
         * Takes the photo from the current video frame and checks the card on
         * THOSE pixels (rule: live corners are only compared, never reused).
         * Returns the crop canvas (original pixels; the overlay is a separate
         * layer and is never drawn into it) and an optional corner hint.
         */
        async capture() {
            // A cleared page, a "no" or a closed camera ends tracking; a capture
            // must not analyse a frame after that either (D7, rule 180 §3).
            if (destroyed || !isAllowed()) throw new Error('Live capture not allowed.');
            const live = loop.state().rawCorners; // frozen at the moment of the press
            const liveCorners = live ? live.map(([x, y]) => [x, y]) : null;
            const vw = video.videoWidth;
            const vh = video.videoHeight;
            const size = analysisSize(vw, vh);
            if (!size || vw * vh > ROI_LIMITS.maxSourcePixels) throw new Error('Ukuran gambar kamera tidak didukung.');
            loop.stop();

            const frame = document.createElement('canvas');
            frame.width = vw;
            frame.height = vh;
            frame.getContext('2d').drawImage(video, 0, 0, vw, vh);

            sizeCanvas(size.w, size.h);
            actx.drawImage(frame, 0, 0, size.w, size.h);
            const observation = analyzeFrame(pixelsOf(actx, size.w, size.h), { sourceWidth: vw, sourceHeight: vh });
            const decision = confirmCaptureGeometry({ liveCorners, capture: observation, frameW: vw, frameH: vh });

            const crop = captureCropFor(decision, vw, vh);
            const out = document.createElement('canvas');
            out.width = crop.width;
            out.height = crop.height;
            out.getContext('2d').drawImage(frame, crop.x, crop.y, crop.width, crop.height, 0, 0, crop.width, crop.height);
            frame.width = 0;
            frame.height = 0;

            return {
                canvas: out,
                status: decision.status,
                hint: decision.corners ? cornersToHint(decision.corners, crop, decision) : null,
            };
        },

        stats: () => ({ mode, ticks, detections, firstStateMs, level: lastState?.level ?? null, ...loop.stats() }),
        destroy,
    };
}
