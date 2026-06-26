'use strict';

/**
 * Backend dispatch + scan-parameter validation.
 *
 * The validated parameter ranges keep scanner commands sane and prevent a
 * caller from requesting an absurd resolution/size.
 */

const mock = require('./backends/mock');
const sane = require('./backends/sane');
const naps2 = require('./backends/naps2');
const wia = require('./backends/wia');

const BACKENDS = { mock, sane, naps2, wia };

// Safe numeric ranges for scan parameters.
const RANGES = {
  dpi: { min: 150, max: 300 },
  quality: { min: 70, max: 90 },
  maxWidth: { min: 800, max: 2400 },
};

class ValidationError extends Error {
  constructor(code, message) {
    super(message);
    this.errorCode = code;
  }
}

function getBackend(config) {
  return BACKENDS[config.backend] || mock;
}

function clampInt(value, fallback, { min, max }) {
  const n = parseInt(value, 10);
  if (!Number.isFinite(n)) return fallback;
  return Math.min(max, Math.max(min, n));
}

/**
 * Validate and normalize the incoming scan request body.
 * Throws ValidationError for an unsupported document_type.
 */
function validateScanRequest(body, config) {
  if (!body || typeof body !== 'object') {
    throw new ValidationError('INVALID_REQUEST', 'Body permintaan tidak valid.');
  }

  const documentType = String(body.document_type || '').toLowerCase();
  if (documentType !== 'ktp') {
    throw new ValidationError(
      'UNSUPPORTED_DOCUMENT_TYPE',
      'Hanya document_type ktp yang didukung.'
    );
  }

  const mode = body.mode === 'gray' || body.mode === 'grayscale' ? 'gray' : 'color';

  return {
    documentType,
    mode,
    // Out-of-range / missing values fall back to configured defaults (clamped).
    dpi: clampInt(body.dpi, config.dpi, RANGES.dpi),
    quality: clampInt(body.quality, config.quality, RANGES.quality),
    maxWidth: clampInt(body.max_width, config.maxWidth, RANGES.maxWidth),
  };
}

async function getHealth(config) {
  const backend = getBackend(config);
  const result = await backend.health(config);
  return { version: config.version, ...result };
}

async function listDevices(config) {
  const backend = getBackend(config);
  return backend.listDevices(config);
}

async function performScan(body, config) {
  const params = validateScanRequest(body, config);
  const backend = getBackend(config);
  const { buffer, mimeType, filename } = await backend.scan(params, config);
  return {
    ok: true,
    mime_type: mimeType,
    filename,
    // base64 with no line breaks (Buffer.toString('base64') never wraps).
    base64: buffer.toString('base64'),
  };
}

module.exports = {
  validateScanRequest,
  getHealth,
  listDevices,
  performScan,
  ValidationError,
  RANGES,
};
