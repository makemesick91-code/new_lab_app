/**
 * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — operator verification UI for
 * the field-based KTP read.
 *
 * Loaded lazily (dynamic import) the first time OCR runs, so the patient form
 * does not pay for it. Pixel work and OCR orchestration live in
 * ktp-roi-ocr.js; this file only draws and listens.
 *
 * What the operator gets:
 *   - the deskewed card with one editable box per field (drag, resize, arrow
 *     keys; mouse and touch), and a corner editor when the card edge was
 *     missed;
 *   - one row per field: the crop, the suggestion, its status, BACA ULANG;
 *   - a choice — never a pre-selection — when the two reads disagree.
 *
 * Invariants (rule 179):
 *   - OCR text reaches the DOM only through .value / .textContent.
 *   - A retry never overwrites a value the operator accepted or edited; the
 *     new read is offered beside it.
 *   - Nothing is written to the patient form without "Terapkan ke Formulir",
 *     and nothing is persisted or logged here.
 */

import {
    readField,
    runFieldOcr,
    moveBox,
    resizeBox,
    boxToPixels,
    validCorners,
    orderCorners,
    ROI_FIELD_KEYS,
    FIELD_SPECS,
    ROI_LIMITS,
} from './ktp-roi-ocr.js';
import { toOcrLines, STATUS_LABELS } from './ktp-camera-ocr.js';

/**
 * Rows of the verification table. `form` rows can be applied to the patient
 * form; `component` rows feed the composed address; `info` rows have no
 * patient column and are shown for checking only.
 */
export const ROW_DEFS = Object.freeze([
    { id: 'nik', roi: 'nik', form: 'ktp_number', label: 'NIK', input: 'nik' },
    { id: 'name', roi: 'name', form: 'name', label: 'Nama', input: 'text' },
    { id: 'birth', roi: 'birth_place_date', form: 'date_of_birth', label: 'Tanggal Lahir', input: 'date', info: 'birth_place' },
    { id: 'gender', roi: 'gender', form: 'gender', label: 'Jenis Kelamin', input: 'gender' },
    { id: 'street', roi: 'address', component: 'address', label: 'Alamat (jalan)' },
    { id: 'rt_rw', roi: 'rt_rw', component: 'rt_rw', label: 'RT/RW' },
    { id: 'village', roi: 'village', component: 'village', label: 'Kel/Desa' },
    { id: 'district', roi: 'district', component: 'district', label: 'Kecamatan' },
    { id: 'address', form: 'address', label: 'Alamat lengkap', input: 'text' },
    { id: 'religion', roi: 'religion', info: 'religion', label: 'Agama' },
    { id: 'marital', roi: 'marital_status', info: 'marital_status', label: 'Status Perkawinan' },
    { id: 'occupation', roi: 'occupation', form: 'occupation', label: 'Pekerjaan', input: 'text' },
]);

export const EXTRA_STATUS_LABELS = Object.freeze({
    conflict: 'Berbeda — pilih',
});

export const AGREEMENT_LABELS = Object.freeze({
    agree: 'Dua cara baca cocok',
    field_only: 'Dari kotak isian',
    document_only: 'Dari baca utuh',
    conflict: 'Dua cara baca berbeda',
    none: '',
});

export const SOURCE_LABELS = Object.freeze({
    field: 'Kotak isian',
    document: 'Baca utuh',
});

export const GENDER_OPTIONS = Object.freeze([['', '—'], ['Male', 'Laki-laki'], ['Female', 'Perempuan']]);

export function statusText(status) {
    return STATUS_LABELS[status] ?? EXTRA_STATUS_LABELS[status] ?? status ?? '';
}

/** The suggestion for a form row, reduced to what decides re-rendering. */
function suggestionFor(def, parsed) {
    const s = parsed?.form?.[def.form];
    if (!s) return { value: null, status: 'missing', suggest: false, agreement: 'none', alternatives: [] };

    return {
        value: s.value ?? null,
        status: s.status,
        suggest: s.suggest === true,
        agreement: s.agreement ?? 'none',
        alternatives: Array.isArray(s.alternatives) ? s.alternatives.map((a) => ({ source: a.source, value: a.value, status: a.status })) : [],
    };
}

const sameSuggestion = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/**
 * Per-row state after a (re)parse.
 *
 * First read: a clean suggestion is pre-selected only for an EMPTY form field
 * (as before). Later reads (BACA ULANG, moved box, new corners): a row whose
 * suggestion did not change keeps its state untouched; a changed suggestion
 * replaces the row ONLY if the operator never touched it — and is then left
 * unselected. A row the operator accepted or edited keeps its value, and the
 * new read is offered as `pending` beside it.
 *
 * @param {object} parsed   server response
 * @param {object|null} previous  rows from the last render (by form field)
 * @param {object} formValues current form values (by form field)
 */
export function buildRowModel(parsed, previous, formValues) {
    const rows = {};
    for (const def of ROW_DEFS) {
        if (!def.form) continue;
        const suggestion = suggestionFor(def, parsed);
        const before = previous?.[def.form];
        const current = String(formValues?.[def.form] ?? '').trim();

        if (!before) {
            rows[def.form] = {
                suggestion,
                value: suggestion.value ?? '',
                checked: suggestion.suggest && suggestion.value !== null && current === '',
                touched: false,
                pending: null,
                replaces: suggestion.value !== null && current !== '' && current !== String(suggestion.value),
            };
            continue;
        }
        if (sameSuggestion(before.suggestion, suggestion)) {
            rows[def.form] = { ...before };
            continue;
        }
        // Only the operator's own action protects a row. A tick the system set
        // on an earlier read is not an acceptance: when the read changes, the
        // row takes the new read unselected (rule 179 §9).
        if (before.touched) {
            const offered = suggestion.value ?? null;
            rows[def.form] = {
                ...before,
                suggestion,
                pending: offered !== null && offered !== before.value ? offered : null,
            };
            continue;
        }
        rows[def.form] = {
            suggestion,
            value: suggestion.value ?? '',
            checked: false,
            touched: false,
            pending: null,
            replaces: suggestion.value !== null && current !== '' && current !== String(suggestion.value),
        };
    }

    return rows;
}

/** Values the operator chose to apply: checked rows with a non-empty value. */
export function selectedValues(rows) {
    const out = {};
    for (const [field, row] of Object.entries(rows ?? {})) {
        const value = String(row.value ?? '').trim();
        if (row.checked && value !== '') out[field] = value;
    }

    return out;
}

/** Operator picked one of two disagreeing values: that is an explicit acceptance. */
export function chooseAlternative(row, value) {
    return { ...row, value: String(value ?? ''), checked: String(value ?? '').trim() !== '', touched: true, pending: null };
}

/** Operator took the value a later read offered beside an accepted one. */
export function takePending(row) {
    if (row.pending === null) return row;

    return { ...row, value: row.pending, checked: true, touched: true, pending: null };
}

/** A pointer movement in CSS pixels -> a normalized delta on an element of `rect` size. */
export function normalizedDelta(dxPx, dyPx, rect) {
    return {
        dx: rect.width > 0 ? dxPx / rect.width : 0,
        dy: rect.height > 0 ? dyPx / rect.height : 0,
    };
}

/**
 * Track one drag gesture. Move/up are followed on `window`, so a pointer that
 * leaves the element (fast drags, small boxes) is not lost; pointer capture
 * is used when the browser grants it but is never required.
 */
function trackPointer(event, onMove, onEnd) {
    try {
        event.currentTarget?.setPointerCapture?.(event.pointerId);
    } catch {
        // Capture is an optimisation; window listeners carry the gesture.
    }
    const move = (e) => onMove(e);
    const end = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', end);
        window.removeEventListener('pointercancel', end);
        onEnd?.();
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);
}

/** Keyboard nudge for a selected box: arrows move, Shift+arrows resize. */
export function keyboardBoxChange(box, key, shift, step = 0.004) {
    const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[key];
    if (!d) return null;

    return shift ? resizeBox(box, 'se', d[0], d[1]) : moveBox(box, d[0], d[1]);
}

/* ------------------------------------------------------------------ DOM -- */

/*
 * PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — pilot measurement aid.
 * Counts what the operator did with ONE captured image, so the protocol §4
 * sheet holds MEASURED values instead of a tally kept from memory. Only counts
 * and field KEYS — never a value, a field's text or a confidence. Shown on this
 * screen only: nothing here is logged, stored or sent.
 */
export function newPilotMetrics() {
    return { cardFound: null, cornerRereads: 0, boxesMoved: [], retryPresses: 0, retriesByField: {}, firstReadSeconds: null };
}

export function recordBoxMoved(metrics, key) {
    if (!metrics.boxesMoved.includes(key)) metrics.boxesMoved.push(key);

    return metrics;
}

/** One press of BACA ULANG (one box or all of them). */
export function recordRetry(metrics, keys) {
    metrics.retryPresses += 1;
    for (const key of keys) metrics.retriesByField[key] = (metrics.retriesByField[key] ?? 0) + 1;

    return metrics;
}

/** The first completed read of this image — later re-reads never overwrite it. */
export function recordFirstRead(metrics, { seconds, boundary, hybrid }) {
    if (metrics.firstReadSeconds !== null) return metrics;
    metrics.firstReadSeconds = Number.isFinite(seconds) && seconds >= 0 ? seconds : null;
    // Whole-card fallback has no card detection: unknown, never a guessed yes/no.
    metrics.cardFound = hybrid && boundary ? Boolean(boundary.found) : null;

    return metrics;
}

/** One line in the protocol §4 column vocabulary (copied by the operator). */
export function formatPilotMetrics(metrics, fieldOrder = ROI_FIELD_KEYS) {
    const yesNo = (v) => (v === null ? 'unknown' : v ? 'yes' : 'no');
    const byField = fieldOrder
        .filter((key) => (metrics.retriesByField[key] ?? 0) > 0)
        .map((key) => `${key}:${metrics.retriesByField[key]}`)
        .join(',') || 'none';
    const seconds = metrics.firstReadSeconds === null ? 'unknown' : metrics.firstReadSeconds.toFixed(1);

    return 'Catatan pilot (tanpa data pribadi): '
        + `card_found=${yesNo(metrics.cardFound)}; corners_adjusted=${yesNo(metrics.cornerRereads > 0)}; `
        + `boxes_moved=${metrics.boxesMoved.length}; retries=${metrics.retryPresses}; `
        + `retries_by_field=${byField}; ocr_seconds=${seconds}`;
}

const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
const HANDLE_POS = {
    nw: ['0%', '0%'], n: ['50%', '0%'], ne: ['100%', '0%'], e: ['100%', '50%'],
    se: ['100%', '100%'], s: ['50%', '100%'], sw: ['0%', '100%'], w: ['0%', '50%'],
};
const IDLE_WORKER_MS = 180_000;

function el(tag, attrs = {}, text = null) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (k === 'class') node.className = v;
        else if (k === 'dataset') Object.assign(node.dataset, v);
        else node.setAttribute(k, v);
    }
    if (text !== null) node.textContent = text;

    return node;
}

function imageToCanvas(img) {
    const canvas = document.createElement('canvas');
    canvas.width = img.width;
    canvas.height = img.height;
    const ctx = canvas.getContext('2d');
    const out = ctx.createImageData(img.width, img.height);
    if ((img.channels ?? 4) === 1) {
        for (let i = 0, o = 0; i < img.data.length; i++, o += 4) {
            out.data[o] = out.data[o + 1] = out.data[o + 2] = img.data[i];
            out.data[o + 3] = 255;
        }
    } else {
        out.data.set(img.data);
    }
    ctx.putImageData(out, 0, 0);

    return canvas;
}

/** Pixel dimensions from the image header, before any full decode. */
function imageDimensions(blob) {
    const url = URL.createObjectURL(blob);
    const img = new Image();

    return new Promise((resolve, reject) => {
        img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => reject(new Error('Gambar KTP tidak dapat dibuka.'));
        img.src = url;
    }).finally(() => URL.revokeObjectURL(url));
}

async function blobToImage(blob) {
    // Refuse an oversized image from its header, so it is never decoded in full.
    const { width, height } = await imageDimensions(blob);
    if (width * height > ROI_LIMITS.maxSourcePixels) throw new Error('Gambar KTP terlalu besar untuk dibaca.');
    const bitmap = await createImageBitmap(blob, { imageOrientation: 'from-image' });
    try {
        if (bitmap.width * bitmap.height > ROI_LIMITS.maxSourcePixels) throw new Error('Gambar KTP terlalu besar untuk dibaca.');
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(bitmap, 0, 0);
        const data = ctx.getImageData(0, 0, bitmap.width, bitmap.height);

        return { width: data.width, height: data.height, data: data.data, channels: 4 };
    } finally {
        bitmap.close?.();
    }
}

/**
 * The lifetime of one OCR session. `guard(promise)` settles like `promise`
 * while the session is open; once `close()` is called every guarded promise —
 * including one that will never settle, such as a recognize() whose worker was
 * terminated — rejects at once, and new guards reject immediately.
 */
export function createSessionGuard() {
    const CLOSED = new Error('ktp-ocr-session-closed');
    let closed = false;
    let rejectAll = () => {};
    const signal = new Promise((_, reject) => {
        rejectAll = reject;
    });
    signal.catch(() => {});

    return {
        get closed() {
            return closed;
        },
        guard: (promise) => (closed ? Promise.reject(CLOSED) : Promise.race([promise, signal])),
        close() {
            if (!closed) {
                closed = true;
                rejectAll(CLOSED);
            }
        },
        CLOSED,
    };
}

/**
 * One OCR session per confirmed image. `deps`:
 *   root, parseUrl, csrf, assetConfig, setStatus(msg, tone), formValue(field),
 *   consent — the D7 attestation `{ consent, consent_version }` sent with every
 *   parse request (PHASE-3); without it the server refuses the text.
 */
export function createKtpOcrSession(deps) {
    const { root } = deps;
    const $ = (sel) => root.querySelector(sel);
    const panel = $('[data-ktp-ocr-results]');
    const tbody = $('[data-ktp-ocr-rows]');
    const stage = $('[data-ktp-roi-stage]');
    const cardCanvas = $('[data-ktp-roi-card]');
    const overlay = $('[data-ktp-roi-overlay]');
    const boundaryMsg = $('[data-ktp-roi-boundary]');
    const metricsEl = $('[data-ktp-roi-metrics]');
    const metrics = newPilotMetrics();
    const renderMetrics = () => {
        if (metricsEl) metricsEl.textContent = formatPilotMetrics(metrics);
    };
    const cornerEditor = $('[data-ktp-roi-corner-editor]');
    const sourceCanvas = $('[data-ktp-roi-source]');
    const cornerOverlay = $('[data-ktp-roi-corner-overlay]');

    const state = {
        blob: null,
        image: null,
        card: null,
        corners: null,
        autoBoxes: null,
        boxes: null,
        lines: [],
        fields: {},
        parsed: null,
        rows: null,
        selected: null,
        busy: false,
        mode: 'hybrid',
        draftCorners: null,
    };
    let worker = null;
    let idleTimer = null;
    const listeners = [];

    // destroy() can land while a read is in flight (a new photo, clear, a new
    // scan). Every await is raced against the session's lifetime: a closed
    // session stops at its next step instead of hanging, and never touches the
    // page again.
    const life = createSessionGuard();
    const { guard } = life;
    const parseAbort = new AbortController();

    const on = (target, type, fn, opts) => {
        target?.addEventListener(type, fn, opts);
        listeners.push(() => target?.removeEventListener(type, fn, opts));
    };

    const touchWorker = () => {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
            worker?.terminate().catch(() => {});
            worker = null;
        }, IDLE_WORKER_MS);
    };

    const ensureWorker = async () => {
        if (!worker) {
            const { createWorker } = await guard(import('tesseract.js'));
            const pending = createWorker('ind', 1 /* LSTM only */, deps.assetConfig);
            // A worker that finishes starting after destroy() is shut down at once.
            pending.then((w) => { if (life.closed) w.terminate().catch(() => {}); }, () => {});
            worker = await guard(pending);
        }
        touchWorker();

        return worker;
    };

    // recognize() parameters are applied for the call only and restored by
    // tesseract.js, so a field's whitelist never leaks into the whole-card read.
    const recognize = async (img, params) => {
        const w = await ensureWorker();
        const { data } = await guard(w.recognize(imageToCanvas(img), params ?? {}, { blocks: true }));

        return data;
    };

    const yieldToUi = () => guard(new Promise((resolve) => setTimeout(resolve, 0)));

    const setBusy = (busy) => {
        state.busy = busy;
        panel?.querySelectorAll('button[data-ktp-roi-action], input, select').forEach((node) => {
            node.disabled = busy && node.dataset.ktpRoiKeep !== '1';
        });
    };

    const formValues = () => Object.fromEntries(ROW_DEFS.filter((d) => d.form).map((d) => [d.form, deps.formValue(d.form)]));

    const fieldPayload = () => Object.fromEntries(
        Object.entries(state.fields).map(([k, v]) => [k, { text: v?.text ?? null, confidence: v?.confidence ?? null }]),
    );

    const parse = async () => {
        const body = { lines: state.lines, ...(deps.consent ?? {}) };
        if (state.mode === 'hybrid') body.fields = fieldPayload();
        const res = await guard(fetch(deps.parseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': deps.csrf },
            body: JSON.stringify(body),
            signal: parseAbort.signal,
        }));
        const parsed = await guard(res.json().catch(() => ({})));
        if (res.status === 429) throw new Error('Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.');
        if (res.status === 422) {
            // D7: a missing or outdated consent is the operator's to fix — say so.
            const message = parsed?.errors?.consent?.[0] ?? parsed?.errors?.consent_version?.[0];
            if (message) throw Object.assign(new Error(message), { userFacing: true });
        }
        if (!res.ok || !parsed.ok) throw new Error('Hasil baca KTP tidak dapat diproses.');
        state.parsed = parsed;
        state.rows = buildRowModel(parsed, state.rows, formValues());

        return parsed;
    };

    /* ---- drawing ---- */

    const drawCard = () => {
        if (!state.card || !cardCanvas) return;
        cardCanvas.width = state.card.width;
        cardCanvas.height = state.card.height;
        cardCanvas.getContext('2d').drawImage(imageToCanvas(state.card), 0, 0);
    };

    const drawThumb = (key) => {
        const canvas = tbody?.querySelector(`canvas[data-ktp-roi-thumb="${key}"]`);
        const box = state.boxes?.[key];
        if (!canvas || !box || !state.card) return;
        const r = boxToPixels(box, cardCanvas.width, cardCanvas.height);
        const scale = Math.min(1, 220 / r.width, 44 / r.height);
        canvas.width = Math.max(1, Math.round(r.width * scale));
        canvas.height = Math.max(1, Math.round(r.height * scale));
        canvas.getContext('2d').drawImage(cardCanvas, r.x, r.y, r.width, r.height, 0, 0, canvas.width, canvas.height);
    };

    const placeBox = (node, box) => {
        node.style.left = `${box.x * 100}%`;
        node.style.top = `${box.y * 100}%`;
        node.style.width = `${box.w * 100}%`;
        node.style.height = `${box.h * 100}%`;
    };

    const selectBox = (key) => {
        state.selected = key;
        overlay?.querySelectorAll('[data-ktp-roi-box]').forEach((node) => {
            const active = node.dataset.ktpRoiBox === key;
            node.classList.toggle('border-amber-500', active);
            node.classList.toggle('bg-amber-200/20', active);
            node.classList.toggle('border-indigo-500', !active);
            node.querySelectorAll('[data-ktp-roi-handle], [data-ktp-roi-label]').forEach((h) => h.classList.toggle('hidden', !active));
        });
        tbody?.querySelectorAll('tr[data-ktp-roi-row]').forEach((tr) => {
            tr.classList.toggle('bg-amber-50', tr.dataset.ktpRoiField === key);
        });
    };

    let rafPending = null;
    const scheduleThumb = (key) => {
        if (rafPending) return;
        rafPending = requestAnimationFrame(() => {
            rafPending = null;
            drawThumb(key);
        });
    };

    const startDrag = (event, key, handle) => {
        if (state.busy) return;
        event.preventDefault();
        event.stopPropagation();
        selectBox(key);
        const rect = stage.getBoundingClientRect();
        const startX = event.clientX;
        const startY = event.clientY;
        const startBox = { ...state.boxes[key] };
        const boxNode = overlay.querySelector(`[data-ktp-roi-box="${key}"]`);

        trackPointer(event, (e) => {
            const { dx, dy } = normalizedDelta(e.clientX - startX, e.clientY - startY, rect);
            state.boxes[key] = handle ? resizeBox(startBox, handle, dx, dy) : moveBox(startBox, dx, dy);
            placeBox(boxNode, state.boxes[key]);
            scheduleThumb(key);
        }, () => markBoxMoved(key));
    };

    const markBoxMoved = (key) => {
        recordBoxMoved(metrics, key);
        renderMetrics();
        drawThumb(key);
        const btn = tbody?.querySelector(`button[data-ktp-roi-retry="${key}"]`);
        btn?.classList.add('ring-2', 'ring-amber-400');
    };

    const renderBoxes = () => {
        if (!overlay) return;
        overlay.replaceChildren();
        for (const key of ROI_FIELD_KEYS) {
            const box = state.boxes?.[key];
            if (!box) continue;
            const node = el('div', {
                class: 'absolute border-2 border-indigo-500 rounded-sm cursor-move touch-none focus:outline-none focus:ring-2 focus:ring-amber-400',
                tabindex: '0',
                role: 'button',
                'aria-label': `Kotak ${FIELD_SPECS[key].label}. Seret untuk memindah; panah untuk menggeser, Shift+panah untuk mengubah ukuran.`,
                dataset: { ktpRoiBox: key },
            });
            placeBox(node, box);
            // Label only on the selected box, so neighbouring rows stay readable.
            node.appendChild(el('span', {
                class: 'hidden pointer-events-none absolute -top-4 left-0 whitespace-nowrap rounded bg-amber-600 px-1 text-[10px] leading-4 text-white',
                dataset: { ktpRoiLabel: key },
            }, FIELD_SPECS[key].label));
            for (const h of HANDLES) {
                const handle = el('span', {
                    class: 'hidden absolute h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border border-white bg-amber-500 touch-none',
                    dataset: { ktpRoiHandle: h },
                    'aria-hidden': 'true',
                });
                handle.style.left = HANDLE_POS[h][0];
                handle.style.top = HANDLE_POS[h][1];
                handle.style.cursor = `${h}-resize`;
                handle.addEventListener('pointerdown', (e) => startDrag(e, key, h));
                node.appendChild(handle);
            }
            node.addEventListener('pointerdown', (e) => startDrag(e, key, null));
            node.addEventListener('keydown', (e) => {
                const next = keyboardBoxChange(state.boxes[key], e.key, e.shiftKey);
                if (!next || state.busy) return;
                e.preventDefault();
                state.boxes[key] = next;
                placeBox(node, next);
                markBoxMoved(key);
            });
            node.addEventListener('focus', () => selectBox(key));
            overlay.appendChild(node);
        }
        stage?.classList.toggle('hidden', !state.card);
    };

    const badge = (text, tone) => el('span', {
        class: `inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ${{
            ok: 'bg-emerald-50 text-emerald-700',
            warn: 'bg-amber-50 text-amber-800',
            bad: 'bg-rose-50 text-rose-700',
            info: 'bg-indigo-50 text-indigo-700',
        }[tone] ?? 'bg-gray-100 text-gray-600'}`,
    }, text);

    const toneFor = (status) => ({ ok: 'ok', low_confidence: 'warn', conflict: 'warn', ambiguous: 'warn', invalid: 'bad', missing: 'bad' }[status] ?? 'info');

    const valueInput = (def, row) => {
        let input;
        if (def.input === 'gender') {
            input = el('select', { class: 'rounded-md border-gray-300 text-sm', 'aria-label': def.label });
            for (const [value, label] of GENDER_OPTIONS) input.appendChild(el('option', { value }, label));
        } else {
            input = el('input', {
                type: def.input === 'date' ? 'date' : 'text',
                class: 'w-full min-w-[10rem] rounded-md border-gray-300 font-mono text-sm',
                'aria-label': def.label,
                maxlength: def.input === 'nik' ? '16' : def.form === 'address' ? '1000' : '150',
                autocomplete: 'off',
                spellcheck: 'false',
            });
            if (def.input === 'nik') input.setAttribute('inputmode', 'numeric');
        }
        input.value = row.value ?? '';
        input.addEventListener('input', () => {
            state.rows[def.form] = { ...state.rows[def.form], value: input.value, touched: true, pending: null };
            renderPendingFor(def.form);
        });

        return input;
    };

    const renderPendingFor = (formField) => {
        const slot = tbody?.querySelector(`[data-ktp-roi-pending="${formField}"]`);
        if (!slot) return;
        slot.replaceChildren();
        const row = state.rows[formField];
        if (!row?.pending) return;
        slot.appendChild(el('span', { class: 'text-xs text-amber-800' }, 'Hasil baca terbaru: '));
        slot.appendChild(el('span', { class: 'font-mono text-xs' }, row.pending));
        const use = el('button', { type: 'button', class: 'ml-2 text-xs font-medium text-indigo-700 underline', dataset: { ktpRoiAction: 'take-pending' } }, 'Pakai hasil ini');
        use.addEventListener('click', () => {
            state.rows[formField] = takePending(state.rows[formField]);
            renderRows();
        });
        slot.appendChild(use);
    };

    const renderRows = () => {
        if (!tbody) return;
        tbody.replaceChildren();
        const fields = state.parsed?.fields ?? {};
        for (const def of ROW_DEFS) {
            // Row ids are unique (the street and the composed address are two
            // rows); `ktpRoiField` links a row to its box, if it has one.
            const tr = el('tr', { class: 'border-t border-gray-100 align-top', dataset: { ktpRoiRow: def.id, ktpRoiField: def.roi ?? '' } });

            // Crop of the field box — the operator compares it with the card.
            const cropCell = el('td', { class: 'px-2 py-1' });
            if (def.roi && state.card) {
                const thumb = el('canvas', { class: 'block max-w-[220px] cursor-pointer rounded border border-gray-200 bg-white', dataset: { ktpRoiThumb: def.roi }, 'aria-label': `Potongan ${def.label}` });
                thumb.addEventListener('click', () => {
                    selectBox(def.roi);
                    overlay?.querySelector(`[data-ktp-roi-box="${def.roi}"]`)?.focus();
                });
                cropCell.appendChild(thumb);
            }
            tr.appendChild(cropCell);
            tr.appendChild(el('td', { class: 'px-2 py-1 text-sm font-medium text-gray-700 whitespace-nowrap' }, def.label));

            const valueCell = el('td', { class: 'px-2 py-1 text-sm' });
            const statusCell = el('td', { class: 'min-w-[9rem] px-2 py-1 space-y-1' });
            const applyCell = el('td', { class: 'px-2 py-1 text-center' });

            if (def.form) {
                const row = state.rows?.[def.form];
                const s = row?.suggestion ?? { status: 'missing', alternatives: [], agreement: 'none' };
                valueCell.appendChild(valueInput(def, row ?? { value: '' }));
                if (s.status === 'conflict' && s.alternatives.length) {
                    const group = el('div', { class: 'mt-1 space-y-0.5', role: 'radiogroup', 'aria-label': `Pilih nilai ${def.label}` });
                    s.alternatives.forEach((alt, i) => {
                        const id = `ktp-roi-alt-${def.form}-${i}-${Math.random().toString(36).slice(2, 8)}`;
                        const label = el('label', { class: 'flex items-start gap-1 text-xs', for: id });
                        const radio = el('input', { type: 'radio', id, name: `ktp-roi-alt-${def.form}`, class: 'mt-0.5' });
                        radio.checked = row?.touched && row.value === alt.value;
                        radio.addEventListener('change', () => {
                            state.rows[def.form] = chooseAlternative(state.rows[def.form], alt.value);
                            renderRows();
                        });
                        label.append(radio, el('span', { class: 'text-gray-500' }, `${SOURCE_LABELS[alt.source] ?? alt.source}:`), el('span', { class: 'font-mono' }, alt.value));
                        group.appendChild(label);
                    });
                    valueCell.appendChild(group);
                }
                valueCell.appendChild(el('div', { class: 'mt-1', dataset: { ktpRoiPending: def.form } }));
                if (def.info && fields[def.info]?.value) {
                    valueCell.appendChild(el('p', { class: 'mt-1 text-xs text-gray-500' }, `Tempat lahir: ${fields[def.info].value} (tidak disimpan)`));
                }
                statusCell.appendChild(badge(statusText(s.status), toneFor(s.status)));
                if (row?.replaces) statusCell.appendChild(badge('mengganti isian', 'warn'));
                if (AGREEMENT_LABELS[s.agreement]) statusCell.appendChild(el('p', { class: 'text-[11px] text-gray-500' }, AGREEMENT_LABELS[s.agreement]));

                const box = el('input', { type: 'checkbox', class: 'rounded border-gray-300', 'aria-label': `Terapkan ${def.label}`, dataset: { ktpApplyField: def.form } });
                box.checked = Boolean(row?.checked);
                box.addEventListener('change', () => {
                    state.rows[def.form] = { ...state.rows[def.form], checked: box.checked, touched: true };
                });
                applyCell.appendChild(box);
            } else {
                const key = def.component ?? def.info;
                const f = fields[key];
                valueCell.appendChild(el('span', { class: 'font-mono' }, f?.value ?? '—'));
                if (f?.status === 'conflict') {
                    for (const alt of f.alternatives ?? []) {
                        valueCell.appendChild(el('p', { class: 'text-xs' }, `${SOURCE_LABELS[alt.source] ?? alt.source}: ${alt.value}`));
                    }
                }
                if (def.info) valueCell.appendChild(el('p', { class: 'text-[11px] text-gray-500' }, 'Hanya untuk dicocokkan — tidak disimpan.'));
                if (def.component) valueCell.appendChild(el('p', { class: 'text-[11px] text-gray-500' }, 'Bagian dari Alamat lengkap.'));
                statusCell.appendChild(badge(statusText(f?.status ?? 'missing'), toneFor(f?.status ?? 'missing')));
                if (AGREEMENT_LABELS[f?.agreement]) statusCell.appendChild(el('p', { class: 'text-[11px] text-gray-500' }, AGREEMENT_LABELS[f.agreement]));
            }

            const actionCell = el('td', { class: 'px-2 py-1' });
            if (def.roi && state.mode === 'hybrid') {
                const retry = el('button', {
                    type: 'button',
                    class: 'whitespace-nowrap rounded-md border border-indigo-300 bg-white px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-50',
                    dataset: { ktpRoiRetry: def.roi, ktpRoiAction: 'retry' },
                }, 'BACA ULANG');
                retry.addEventListener('click', () => retryField(def.roi));
                actionCell.appendChild(retry);
            }

            tr.append(valueCell, statusCell, actionCell, applyCell);
            tbody.appendChild(tr);
            if (def.form) renderPendingFor(def.form);
        }
        for (const key of ROI_FIELD_KEYS) drawThumb(key);
        if (state.selected) selectBox(state.selected);
        panel?.classList.remove('hidden');
    };

    const describeBoundary = (boundary) => {
        if (!boundaryMsg) return;
        if (state.mode !== 'hybrid') {
            boundaryMsg.textContent = 'Mode baca utuh: kotak isian tidak tersedia untuk foto ini.';
        } else if (boundary?.reason === 'manual') {
            boundaryMsg.textContent = 'Sudut KTP diatur manual.';
        } else if (boundary?.found) {
            boundaryMsg.textContent = 'Tepi KTP terdeteksi dan diluruskan. Geser kotak bila ada isian yang terpotong, lalu BACA ULANG.';
        } else {
            boundaryMsg.textContent = 'Tepi KTP tidak terdeteksi — seluruh foto dipakai. Gunakan "Atur Sudut KTP" bila kotak tidak pas.';
        }
    };

    /* ---- reading ---- */

    const read = async (blob, options = {}) => {
        if (state.busy) return;
        setBusy(true);
        const startedAt = performance.now();
        deps.setStatus('Membaca teks KTP… (diproses di perangkat ini)', 'info');
        try {
            if (blob) {
                state.blob = blob;
                state.image = await guard(blobToImage(blob));
                state.rows = null;
            }
            state.mode = 'hybrid';
            let out;
            try {
                out = await runFieldOcr(state.image, {
                    recognize,
                    corners: options.corners,
                    yieldToUi,
                    onStage: (s) => deps.setStatus({
                        boundary: 'Mendeteksi tepi KTP…',
                        normalize: 'Meluruskan foto KTP…',
                        document: 'Membaca seluruh KTP…',
                        fields: 'Membaca tiap isian…',
                    }[s] ?? 'Membaca teks KTP…', 'info'),
                });
            } catch (e) {
                if (life.closed) throw e;
                // The field pipeline could not run on this device or image
                // (memory, decoding): fall back to the original whole-image read.
                state.mode = 'document';
                const w = await ensureWorker();
                const { data } = await guard(w.recognize(state.blob, {}, { blocks: true }));
                out = { lines: toOcrLines(data), fields: {}, boxes: null, card: null, corners: null, boundary: null };
            }
            if (life.closed) return;
            state.lines = out.lines;
            state.fields = out.fields ?? {};
            state.card = out.card;
            state.corners = out.corners;
            state.autoBoxes = out.boxes ? JSON.parse(JSON.stringify(out.boxes)) : null;
            state.boxes = out.boxes ? JSON.parse(JSON.stringify(out.boxes)) : null;

            const parsed = await parse();
            drawCard();
            renderBoxes();
            renderRows();
            describeBoundary(out.boundary);
            const elapsed = (performance.now() - startedAt) / 1000;
            const secs = elapsed.toFixed(1);
            recordFirstRead(metrics, { seconds: elapsed, boundary: out.boundary, hybrid: state.mode === 'hybrid' });
            renderMetrics();
            const msg = {
                success: `Teks KTP terbaca (${secs} dtk). Periksa tiap isian lalu terapkan ke formulir.`,
                partial: `Sebagian data terbaca (${secs} dtk). Periksa, pilih bila ada yang berbeda, lengkapi sisanya.`,
                failed: 'Teks KTP tidak dapat dibaca. Isi data secara manual atau ambil ulang foto yang lebih jelas.',
            }[parsed.outcome];
            deps.setStatus(msg, parsed.outcome === 'failed' ? 'error' : 'ok');
        } catch (e) {
            if (life.closed) return;
            deps.setStatus(e?.userFacing || e?.message?.startsWith('Terlalu') ? e.message : 'OCR gagal. Foto KTP tetap tersimpan; isi data secara manual.', 'error');
        } finally {
            if (!life.closed) setBusy(false);
        }
    };

    const retryFields = async (keys, label) => {
        if (state.busy || !state.card || !state.boxes) return;
        recordRetry(metrics, keys);
        renderMetrics();
        setBusy(true);
        deps.setStatus(`Membaca ulang ${label}…`, 'info');
        try {
            for (const key of keys) {
                const result = await readField(state.card, key, state.boxes[key], recognize);
                state.fields[key] = { text: result.text, confidence: result.confidence, variant: result.variant };
                tbody?.querySelector(`button[data-ktp-roi-retry="${key}"]`)?.classList.remove('ring-2', 'ring-amber-400');
            }
            await parse();
            renderRows();
            deps.setStatus(`${label} dibaca ulang. Nilai yang sudah Anda terima tidak diubah.`, 'ok');
        } catch (e) {
            if (life.closed) return;
            deps.setStatus(e?.userFacing || e?.message?.startsWith('Terlalu') ? e.message : `Baca ulang ${label} gagal.`, 'error');
        } finally {
            if (!life.closed) setBusy(false);
        }
    };

    const retryField = (key) => retryFields([key], FIELD_SPECS[key].label);

    /* ---- corner editor ---- */

    const placeCornerHandles = () => {
        if (!cornerOverlay || !state.draftCorners) return;
        const { width, height } = state.image;
        cornerOverlay.querySelectorAll('[data-ktp-roi-corner]').forEach((handle) => {
            const [x, y] = state.draftCorners[Number(handle.dataset.ktpRoiCorner)];
            handle.style.left = `${(x / width) * 100}%`;
            handle.style.top = `${(y / height) * 100}%`;
        });
        const poly = cornerOverlay.querySelector('polygon');
        poly?.setAttribute('points', state.draftCorners.map(([x, y]) => `${(x / width) * 100},${(y / height) * 100}`).join(' '));
    };

    const openCornerEditor = () => {
        if (!state.image || !cornerEditor || state.busy) return;
        sourceCanvas.width = state.image.width;
        sourceCanvas.height = state.image.height;
        sourceCanvas.getContext('2d').drawImage(imageToCanvas(state.image), 0, 0);
        const { width, height } = state.image;
        const clampPt = ([x, y]) => [Math.min(width, Math.max(0, x)), Math.min(height, Math.max(0, y))];
        state.draftCorners = (state.corners ?? [[0, 0], [width, 0], [width, height], [0, height]]).map(clampPt);

        cornerOverlay.replaceChildren();
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 100 100');
        svg.setAttribute('preserveAspectRatio', 'none');
        svg.setAttribute('class', 'pointer-events-none absolute inset-0 h-full w-full');
        const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        poly.setAttribute('fill', 'rgba(99,102,241,0.12)');
        poly.setAttribute('stroke', 'rgb(245,158,11)');
        poly.setAttribute('stroke-width', '0.5');
        poly.setAttribute('vector-effect', 'non-scaling-stroke');
        svg.appendChild(poly);
        cornerOverlay.appendChild(svg);

        ['Kiri atas', 'Kanan atas', 'Kanan bawah', 'Kiri bawah'].forEach((name, i) => {
            const handle = el('span', {
                class: 'absolute h-5 w-5 -translate-x-1/2 -translate-y-1/2 cursor-grab rounded-full border-2 border-white bg-amber-500 shadow touch-none focus:outline-none focus:ring-2 focus:ring-indigo-500',
                tabindex: '0',
                role: 'button',
                'aria-label': `Sudut ${name}. Seret, atau gunakan tombol panah.`,
                dataset: { ktpRoiCorner: String(i) },
            });
            handle.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                const rect = sourceCanvas.getBoundingClientRect();
                const start = [...state.draftCorners[i]];
                const sx = event.clientX;
                const sy = event.clientY;
                trackPointer(event, (e) => {
                    const { dx, dy } = normalizedDelta(e.clientX - sx, e.clientY - sy, rect);
                    state.draftCorners[i] = clampPt([start[0] + dx * width, start[1] + dy * height]);
                    placeCornerHandles();
                });
            });
            handle.addEventListener('keydown', (e) => {
                const step = (e.shiftKey ? 0.01 : 0.003) * Math.max(width, height);
                const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
                if (!d) return;
                e.preventDefault();
                state.draftCorners[i] = clampPt([state.draftCorners[i][0] + d[0], state.draftCorners[i][1] + d[1]]);
                placeCornerHandles();
            });
            cornerOverlay.appendChild(handle);
        });
        placeCornerHandles();
        cornerEditor.classList.remove('hidden');
        deps.setStatus('Seret keempat titik ke sudut KTP, lalu tekan "Terapkan Sudut".', 'info');
    };

    const applyCorners = async () => {
        if (!state.draftCorners || state.busy) return;
        if (!validCorners(state.draftCorners, state.image.width, state.image.height)) {
            deps.setStatus('Sudut tidak membentuk kartu yang valid. Atur ulang keempat titik.', 'error');
            return;
        }
        cornerEditor.classList.add('hidden');
        metrics.cornerRereads += 1;
        renderMetrics();
        // A full re-read on the operator's request: new geometry, new boxes.
        await read(null, { corners: orderCorners(state.draftCorners) });
    };

    /* ---- wiring ---- */

    on($('[data-ktp-roi-corners-open]'), 'click', openCornerEditor);
    on($('[data-ktp-roi-corners-apply]'), 'click', applyCorners);
    on($('[data-ktp-roi-corners-cancel]'), 'click', () => cornerEditor?.classList.add('hidden'));
    on($('[data-ktp-roi-reset-boxes]'), 'click', () => {
        if (!state.autoBoxes || state.busy) return;
        state.boxes = JSON.parse(JSON.stringify(state.autoBoxes));
        renderBoxes();
        for (const key of ROI_FIELD_KEYS) drawThumb(key);
        deps.setStatus('Posisi kotak dikembalikan. Tekan BACA ULANG untuk membaca ulang.', 'info');
    });
    on($('[data-ktp-roi-retry-all]'), 'click', () => retryFields([...ROI_FIELD_KEYS], 'semua isian'));

    return {
        read,
        /** Selected values to apply, by form field. */
        selections: () => selectedValues(state.rows),
        destroy() {
            life.close();
            parseAbort.abort();
            listeners.splice(0).forEach((off) => off());
            clearTimeout(idleTimer);
            if (rafPending) cancelAnimationFrame(rafPending);
            worker?.terminate().catch(() => {});
            worker = null;
            // Release pixel buffers; nothing of the KTP stays in this session.
            Object.assign(state, { blob: null, image: null, card: null, boxes: null, autoBoxes: null, lines: [], fields: {}, parsed: null, rows: null, draftCorners: null });
            tbody?.replaceChildren();
            overlay?.replaceChildren();
            cornerOverlay?.replaceChildren();
            cornerEditor?.classList.add('hidden');
            stage?.classList.add('hidden');
            for (const canvas of [cardCanvas, sourceCanvas]) {
                if (canvas) {
                    canvas.width = 0;
                    canvas.height = 0;
                }
            }
            if (boundaryMsg) boundaryMsg.textContent = '';
            if (metricsEl) metricsEl.textContent = '';
        },
    };
}
