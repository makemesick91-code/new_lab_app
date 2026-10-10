/**
 * REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — live preview analysis off the
 * main thread.
 *
 * Receives ONE downscaled preview frame per message (an ImageBitmap, transferred)
 * and answers with the observation only: card corners, geometry and quality
 * numbers. It never returns pixels or text, makes no network request and stores
 * nothing. The message is bounded before any pixel is read; the bitmap is
 * always released.
 */
import { analyzeFrame, validateAnalysisRequest } from './ktp-live-overlay.js';

let canvas = null;
let ctx = null;

self.onmessage = (event) => {
    const msg = event.data;
    const check = validateAnalysisRequest(msg);
    if (!check.ok) {
        msg?.bitmap?.close?.();
        self.postMessage({ id: Number.isSafeInteger(msg?.id) ? msg.id : null, observation: { found: false, reason: check.reason } });

        return;
    }
    const { id, bitmap, sourceWidth, sourceHeight } = msg;
    try {
        if (!canvas || canvas.width !== bitmap.width || canvas.height !== bitmap.height) {
            canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
            ctx = canvas.getContext('2d', { willReadFrequently: true });
        }
        ctx.drawImage(bitmap, 0, 0);
        const img = ctx.getImageData(0, 0, bitmap.width, bitmap.height);
        const observation = analyzeFrame({ width: img.width, height: img.height, data: img.data, channels: 4 }, { sourceWidth, sourceHeight });
        self.postMessage({ id, observation });
    } catch {
        self.postMessage({ id, observation: { found: false, reason: 'analysis_failed' } });
    } finally {
        bitmap.close();
    }
};
