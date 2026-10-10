/**
 * REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — KTP capture + browser-side OCR.
 *
 * One pipeline for every image source (scanner agent, device camera, manual
 * file): preview -> operator confirms -> compress -> private temp upload ->
 * (when enabled) OCR in THIS browser -> server parses + validates the text ->
 * operator reviews suggestions -> operator applies -> operator confirms.
 *
 * Privacy / supply chain:
 *  - OCR runs locally with a SELF-HOSTED tesseract.js build. Worker, WASM core
 *    and language model are all served from this origin (see vite.config.js);
 *    tesseract.js' CDN defaults are always overridden. The image is never sent
 *    to any OCR service.
 *  - OCR text is untrusted. It is only ever written with .value/.textContent,
 *    never as HTML, and the server re-validates everything on submit.
 *  - Nothing is applied to the form without an explicit operator action, and
 *    a filled field is never silently overwritten.
 *
 * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — the read itself is now the
 * field-based pipeline (ktp-roi-ocr.js) with its verification UI
 * (ktp-roi-ui.js), both loaded lazily on first use. The whole-card read is
 * kept inside it and is the fallback when the field step cannot run.
 *
 * The pure helpers are exported for tests/js/ktp-camera-ocr.test.mjs.
 */

/** ID-1 card (KTP) aspect ratio, 85.6mm x 53.98mm. */
export const KTP_ASPECT = 85.6 / 53.98;

export const CLIENT_LIMITS = Object.freeze({
    maxSourceBytes: 15 * 1024 * 1024, // raw phone photos; compressed before upload
    maxWidth: 1600,
    jpegQuality: 0.85,
    allowedTypes: ['image/jpeg', 'image/png', 'image/webp'],
    maxOcrLines: 60,
    maxLineLength: 200,
});

/** Form fields OCR may populate (mirrors KtpOcrParser::FORM_FIELDS). */
export const FORM_FIELDS = ['ktp_number', 'name', 'date_of_birth', 'gender', 'address', 'occupation'];

export const FIELD_LABELS = Object.freeze({
    ktp_number: 'NIK',
    name: 'Nama',
    date_of_birth: 'Tanggal Lahir',
    gender: 'Jenis Kelamin',
    address: 'Alamat',
    occupation: 'Pekerjaan',
    birth_place: 'Tempat Lahir',
    rt_rw: 'RT/RW',
    village: 'Kel/Desa',
    district: 'Kecamatan',
    religion: 'Agama',
    marital_status: 'Status Perkawinan',
});

export const STATUS_LABELS = Object.freeze({
    ok: 'Terbaca',
    low_confidence: 'Perlu dicek',
    invalid: 'Tidak valid',
    missing: 'Tidak terbaca',
    ambiguous: 'Ambigu',
});

/** Fields the parser reads that have NO patient column — shown, never saved. */
export const INFO_ONLY_FIELDS = ['birth_place', 'religion', 'marital_status'];

/**
 * getUserMedia constraints. Prefers the rear camera on phones/tablets
 * (`ideal`, so a desktop webcam still satisfies it); an explicit device id
 * from "Ganti Kamera" wins.
 */
export function cameraConstraints(deviceId = null) {
    const video = deviceId
        ? { deviceId: { exact: deviceId } }
        : { facingMode: { ideal: 'environment' } };

    return {
        audio: false,
        video: { ...video, width: { ideal: 1920 }, height: { ideal: 1080 } },
    };
}

/** Scale down to fit maxWidth; never upscale. */
export function fitWithin(width, height, maxWidth = CLIENT_LIMITS.maxWidth) {
    if (!(width > 0) || !(height > 0)) {
        return { width: 0, height: 0 };
    }
    if (width <= maxWidth) {
        return { width: Math.round(width), height: Math.round(height) };
    }
    const scale = maxWidth / width;

    return { width: Math.round(maxWidth), height: Math.max(1, Math.round(height * scale)) };
}

/**
 * The region of the video frame inside the on-screen alignment guide: the
 * largest centered KTP-shaped rectangle covering `coverage` of the frame.
 * Cropping to it removes background the OCR would otherwise have to read.
 */
export function guideCropRect(frameWidth, frameHeight, coverage = 0.9, aspect = KTP_ASPECT) {
    if (!(frameWidth > 0) || !(frameHeight > 0)) {
        return { x: 0, y: 0, width: 0, height: 0 };
    }
    let width = frameWidth * coverage;
    let height = width / aspect;
    if (height > frameHeight * coverage) {
        height = frameHeight * coverage;
        width = height * aspect;
    }

    return {
        x: Math.round((frameWidth - width) / 2),
        y: Math.round((frameHeight - height) / 2),
        width: Math.round(width),
        height: Math.round(height),
    };
}

/** Client-side pre-check of a chosen file. The server re-checks the bytes. */
export function validateSourceFile(file, limits = CLIENT_LIMITS) {
    if (!file) {
        return 'Tidak ada berkas yang dipilih.';
    }
    if (!limits.allowedTypes.includes(file.type)) {
        return 'Format berkas harus JPG, PNG, atau WebP.';
    }
    if (file.size > limits.maxSourceBytes) {
        return 'Berkas terlalu besar (maksimal 15 MB).';
    }

    return null;
}

/** `name` -> `name`; with prefix `new_patient` -> `new_patient[name]`. */
export function formInputName(prefix, field) {
    return prefix ? `${prefix}[${field}]` : field;
}

/**
 * Same-origin tesseract.js asset configuration. Throws rather than ever
 * falling back to tesseract.js' CDN defaults.
 */
export function ocrAssetConfig(buildBase, assetDir) {
    const base = `${String(buildBase || '').replace(/\/+$/, '')}/${String(assetDir || '').replace(/^\/+|\/+$/g, '')}`;
    if (!/^\/(?!\/)/.test(base) || !assetDir) {
        throw new Error('OCR assets must be served from this origin.');
    }

    return {
        workerPath: `${base}/worker.min.js`,
        corePath: `${base}/core`,
        langPath: `${base}/lang`,
        gzip: true,
        workerBlobURL: true,
    };
}

/** Flatten a tesseract.js result into bounded {text, confidence} lines. */
export function toOcrLines(data, limits = CLIENT_LIMITS) {
    const lines = [];
    for (const block of data?.blocks ?? []) {
        for (const paragraph of block?.paragraphs ?? []) {
            for (const line of paragraph?.lines ?? []) {
                const text = String(line?.text ?? '').replace(/\s+/g, ' ').trim();
                if (text === '') continue;
                const confidence = Number(line?.confidence);
                lines.push({
                    text: text.slice(0, limits.maxLineLength),
                    confidence: Number.isFinite(confidence) ? Math.max(0, Math.min(100, confidence)) : null,
                });
                if (lines.length >= limits.maxOcrLines) return lines;
            }
        }
    }

    return lines;
}

/**
 * Decide, per form field, whether the "apply" box starts ticked.
 * Ticked only for a clean read (`suggest`) into an EMPTY form field — a field
 * the operator already filled is never pre-selected for replacement.
 */
export function initialSelections(form, currentValues) {
    const result = {};
    for (const field of FORM_FIELDS) {
        const suggestion = form?.[field];
        const current = String(currentValues?.[field] ?? '').trim();
        const hasValue = suggestion && suggestion.value !== null && suggestion.value !== undefined;
        result[field] = {
            available: Boolean(hasValue),
            checked: Boolean(hasValue && suggestion.suggest === true && current === ''),
            replaces: Boolean(hasValue && current !== '' && current !== String(suggestion.value)),
        };
    }

    return result;
}

/** Human message for a getUserMedia failure. */
export function cameraErrorMessage(error, secure = true) {
    if (!secure) {
        return 'Kamera hanya dapat digunakan melalui koneksi HTTPS. Gunakan unggah manual.';
    }
    switch (error?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'Izin kamera ditolak. Izinkan akses kamera di browser, atau gunakan unggah manual.';
        case 'NotFoundError':
        case 'OverconstrainedError':
            return 'Kamera tidak ditemukan pada perangkat ini. Gunakan unggah manual.';
        case 'NotReadableError':
            return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi tersebut lalu coba lagi.';
        default:
            return 'Kamera tidak dapat dibuka. Gunakan unggah manual.';
    }
}

/* ------------------------------------------------------------------ DOM -- */

/* global __DMS_OCR_ASSET_DIR__ */
const OCR_ASSET_DIR = typeof __DMS_OCR_ASSET_DIR__ !== 'undefined' ? __DMS_OCR_ASSET_DIR__ : '';

function canvasToBlob(canvas, quality) {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('encode'))), 'image/jpeg', quality);
    });
}

function blobToDataUrl(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result || ''));
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(blob);
    });
}

async function loadBitmap(blob) {
    if (typeof createImageBitmap === 'function') {
        return createImageBitmap(blob, { imageOrientation: 'from-image' });
    }
    const url = URL.createObjectURL(blob);
    try {
        const img = new Image();
        img.src = url;
        await img.decode();
        return img;
    } finally {
        URL.revokeObjectURL(url);
    }
}

/** Re-encode any source as a size-bounded JPEG (also strips EXIF/GPS). */
async function compressImage(source) {
    const bitmap = source instanceof Blob ? await loadBitmap(source) : source;
    const w = bitmap.width ?? bitmap.videoWidth;
    const h = bitmap.height ?? bitmap.videoHeight;
    const size = fitWithin(w, h);
    if (size.width === 0) throw new Error('Gambar tidak dapat dibaca.');
    const canvas = document.createElement('canvas');
    canvas.width = size.width;
    canvas.height = size.height;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, size.width, size.height);
    ctx.drawImage(bitmap, 0, 0, size.width, size.height);
    if (typeof bitmap.close === 'function') bitmap.close();

    return canvasToBlob(canvas, CLIENT_LIMITS.jpegQuality);
}

export function initKtpScan(root) {
    const $ = (sel) => root.querySelector(sel);
    const ds = root.dataset;
    const csrf = ds.csrf;
    const prefix = ds.fieldPrefix || '';
    const ocrEnabled = ds.ocrEnabled === '1';
    const form = root.closest('form');

    const statusEl = $('[data-ktp-status]');
    const tokenEl = $('[data-ktp-token]');
    const previewWrap = $('[data-ktp-preview-wrap]');
    const previewImg = $('[data-ktp-preview]');
    const confirmBar = $('[data-ktp-confirm-bar]');
    const clearBtn = $('[data-ktp-clear]');
    const scanBtn = $('[data-ktp-scan-btn]');

    let pendingBlob = null; // captured/selected, not yet confirmed
    let ocrSession = null; // field-based OCR session for the confirmed image
    let ocrRun = 0; // bumped on every reset, so a run that outlived its photo stops
    let stream = null;
    let videoDevices = [];
    let deviceIndex = -1;
    let busy = false;

    const setStatus = (msg, tone) => {
        statusEl.textContent = msg;
        statusEl.className = 'mt-2 text-sm ' + ({ ok: 'text-emerald-600', error: 'text-rose-600' }[tone] || 'text-gray-500');
    };

    const field = (name) => form?.querySelector(`[name="${CSS.escape(formInputName(prefix, name))}"]`) ?? null;

    const showPreview = (dataUrl, needsConfirm) => {
        previewImg.src = dataUrl;
        previewWrap.classList.remove('hidden');
        confirmBar?.classList.toggle('hidden', !needsConfirm);
        clearBtn.disabled = false;
    };

    const resetResults = () => {
        // Ends the OCR session: its worker is terminated and every pixel
        // buffer of the KTP held for box editing is released.
        ocrRun++;
        ocrSession?.destroy();
        ocrSession = null;
        $('[data-ktp-ocr-results]')?.classList.add('hidden');
        const body = $('[data-ktp-ocr-rows]');
        if (body) body.replaceChildren();
    };

    const clearAll = () => {
        // The temp image stays referenced by the token until a new upload
        // replaces it (server discards the superseded one) or it is pruned.
        previewImg.removeAttribute('src');
        previewWrap.classList.add('hidden');
        confirmBar?.classList.add('hidden');
        pendingBlob = null;
        clearBtn.disabled = true;
        resetResults();
    };

    const upload = async (base64, mime, filename) => {
        const res = await fetch(ds.uploadUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({
                document_type: 'ktp',
                image_base64: base64.includes(',') ? base64.split(',').pop() : base64,
                mime_type: mime || null,
                filename: filename || null,
                replaces_token: tokenEl.value || null,
            }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.message || 'Upload gagal');
        tokenEl.value = data.token;
        return data;
    };

    /* ---- OCR ---- */

    const runOcr = async (blob) => {
        if (!ocrEnabled) return;
        resetResults();
        setStatus('Menyiapkan pembaca KTP… (diproses di perangkat ini)', 'info');
        const run = ocrRun;
        try {
            const { createKtpOcrSession } = await import('./ktp-roi-ui.js');
            // The photo was cleared or replaced while the reader loaded.
            if (run !== ocrRun) return;
            ocrSession = createKtpOcrSession({
                root,
                parseUrl: ds.parseUrl,
                csrf,
                assetConfig: ocrAssetConfig(ds.ocrBuildBase, OCR_ASSET_DIR),
                setStatus,
                formValue: (name) => field(name)?.value ?? '',
            });
            await ocrSession.read(blob);
        } catch {
            if (run === ocrRun) setStatus('OCR gagal. Foto KTP tetap tersimpan; isi data secara manual.', 'error');
        }
    };

    $('[data-ktp-apply]')?.addEventListener('click', () => {
        let applied = 0;
        for (const [name, value] of Object.entries(ocrSession?.selections() ?? {})) {
            const input = field(name);
            if (!input) continue;
            input.value = value; // never innerHTML: OCR text is untrusted
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            applied++;
        }
        if (applied === 0) {
            setStatus('Tidak ada data yang dipilih untuk diterapkan.', 'error');
            return;
        }
        $('[data-ktp-ocr-applied]').value = '1';
        const verify = $('[data-ktp-ocr-verify-wrap]');
        verify.classList.remove('hidden');
        verify.querySelector('input[type=checkbox]').required = true;
        setStatus(`${applied} isian diterapkan. Cocokkan dengan KTP asli, lalu centang konfirmasi sebelum menyimpan.`, 'ok');
    });

    /* ---- confirm (shared by camera + manual) ---- */

    $('[data-ktp-confirm]')?.addEventListener('click', async () => {
        if (!pendingBlob || busy) return;
        busy = true;
        confirmBar.classList.add('hidden');
        setStatus('Mengompres dan mengunggah foto…', 'info');
        try {
            const compressed = await compressImage(pendingBlob);
            const dataUrl = await blobToDataUrl(compressed);
            previewImg.src = dataUrl;
            await upload(dataUrl, 'image/jpeg', 'ktp-kamera.jpg');
            pendingBlob = null;
            setStatus('Foto KTP tersimpan.', 'ok');
            await runOcr(compressed);
        } catch (e) {
            confirmBar.classList.remove('hidden');
            setStatus(e?.message && !e.message.startsWith('encode') ? e.message : 'Upload gagal. Coba lagi.', 'error');
        } finally {
            busy = false;
        }
    });

    $('[data-ktp-retake]')?.addEventListener('click', () => {
        clearAll();
        if (root.dataset.lastSource === 'camera') openCamera();
    });

    /* ---- camera ---- */

    const cameraWrap = $('[data-ktp-camera]');
    const video = $('[data-ktp-video]');
    const switchBtn = $('[data-ktp-camera-switch]');

    const stopCamera = () => {
        stream?.getTracks().forEach((t) => t.stop());
        stream = null;
        if (video) video.srcObject = null;
        cameraWrap?.classList.add('hidden');
    };

    const openCamera = async (deviceId = null) => {
        const secure = window.isSecureContext && !!navigator.mediaDevices?.getUserMedia;
        if (!secure) {
            setStatus(cameraErrorMessage(null, false), 'error');
            return;
        }
        stopCamera();
        setStatus('Membuka kamera…', 'info');
        try {
            stream = await navigator.mediaDevices.getUserMedia(cameraConstraints(deviceId));
            video.srcObject = stream;
            await video.play().catch(() => {});
            cameraWrap.classList.remove('hidden');
            videoDevices = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
            const activeId = stream.getVideoTracks()[0]?.getSettings?.().deviceId;
            deviceIndex = Math.max(0, videoDevices.findIndex((d) => d.deviceId === activeId));
            switchBtn.classList.toggle('hidden', videoDevices.length < 2);
            setStatus('Posisikan KTP di dalam bingkai, lalu tekan Ambil Foto.', 'info');
        } catch (e) {
            stopCamera();
            setStatus(cameraErrorMessage(e, true), 'error');
        }
    };

    $('[data-ktp-camera-open]')?.addEventListener('click', () => openCamera());
    $('[data-ktp-camera-close]')?.addEventListener('click', () => { stopCamera(); setStatus('Kamera ditutup.', 'info'); });
    switchBtn?.addEventListener('click', () => {
        if (videoDevices.length < 2) return;
        deviceIndex = (deviceIndex + 1) % videoDevices.length;
        openCamera(videoDevices[deviceIndex].deviceId);
    });

    $('[data-ktp-capture]')?.addEventListener('click', async () => {
        if (!stream || !video.videoWidth) return;
        const crop = guideCropRect(video.videoWidth, video.videoHeight);
        const canvas = document.createElement('canvas');
        canvas.width = crop.width;
        canvas.height = crop.height;
        canvas.getContext('2d').drawImage(video, crop.x, crop.y, crop.width, crop.height, 0, 0, crop.width, crop.height);
        stopCamera(); // release the camera as soon as the frame is taken
        try {
            pendingBlob = await canvasToBlob(canvas, 0.95);
            root.dataset.lastSource = 'camera';
            resetResults();
            showPreview(await blobToDataUrl(pendingBlob), true);
            setStatus('Periksa foto: pastikan semua teks KTP terbaca jelas.', 'info');
        } catch {
            setStatus('Gagal mengambil foto. Coba lagi.', 'error');
        }
    });

    /* ---- manual file ---- */

    $('[data-ktp-manual]')?.addEventListener('change', async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        const error = validateSourceFile(file);
        if (error) {
            setStatus(error, 'error');
            return;
        }
        pendingBlob = file;
        root.dataset.lastSource = 'file';
        resetResults();
        showPreview(await blobToDataUrl(file), true);
        setStatus('Periksa foto lalu tekan "Gunakan Foto Ini".', 'info');
    });

    /* ---- scanner agent (Sprint 61.1, unchanged behaviour) ---- */

    $('[data-ktp-check]')?.addEventListener('click', async () => {
        setStatus('Memeriksa scanner…', 'info');
        try {
            const res = await fetch(ds.healthUrl, { method: 'GET' });
            const data = await res.json().catch(() => ({}));
            scanBtn.disabled = !(res.ok && data.ok);
            setStatus(res.ok && data.ok ? 'Scanner terhubung' + (data.device ? ' — ' + data.device : '') : 'Scanner tidak ditemukan', res.ok && data.ok ? 'ok' : 'error');
        } catch {
            scanBtn.disabled = true;
            setStatus('Scanner belum terhubung. Jalankan Daengtisia Scanner Agent di komputer ini.', 'error');
        }
    });

    scanBtn?.addEventListener('click', async () => {
        setStatus('Memindai KTP…', 'info');
        try {
            const res = await fetch(ds.scanUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ document_type: 'ktp', mode: 'color', dpi: 200, max_width: 1600, quality: 82 }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok || !data.base64) {
                setStatus('Scanner tidak ditemukan', 'error');
                return;
            }
            const mime = data.mime_type || 'image/jpeg';
            const raw = data.base64.includes(',') ? data.base64.split(',').pop() : data.base64;
            const dataUrl = `data:${mime};base64,${raw}`;
            resetResults();
            showPreview(dataUrl, false);
            await upload(raw, mime, data.filename);
            setStatus('Scan berhasil', 'ok');
            if (ocrEnabled) await runOcr(await (await fetch(dataUrl)).blob());
        } catch {
            setStatus('Upload gagal', 'error');
        }
    });

    clearBtn?.addEventListener('click', clearAll);

    window.addEventListener('pagehide', () => {
        stopCamera();
        resetResults();
    });
}

export function bootKtpScan() {
    document.querySelectorAll('[data-ktp-scan]').forEach((root) => {
        if (root.dataset.ktpScanReady === '1') return;
        root.dataset.ktpScanReady = '1';
        initKtpScan(root);
    });
}
