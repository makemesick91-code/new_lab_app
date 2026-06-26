'use strict';

/**
 * Configuration loader for the DaengtisiaMS scanner agent.
 *
 * All values come from environment variables (optionally seeded from a local
 * .env file via the tiny loader below). No secrets are required or stored.
 */

const fs = require('fs');
const path = require('path');

const VERSION = '0.1.0';

const SUPPORTED_BACKENDS = ['mock', 'sane', 'naps2', 'wia'];

/**
 * Minimal .env reader so the prototype does not pull in the `dotenv` package.
 * Only KEY=VALUE lines are honoured; existing process.env values win.
 */
function loadDotEnv(dir) {
  const envPath = path.join(dir, '.env');
  let raw;
  try {
    raw = fs.readFileSync(envPath, 'utf8');
  } catch (_) {
    return; // no .env file is perfectly fine
  }
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq === -1) continue;
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    if (key && process.env[key] === undefined) {
      process.env[key] = value;
    }
  }
}

function toInt(value, fallback) {
  const n = parseInt(value, 10);
  return Number.isFinite(n) ? n : fallback;
}

function parseOrigins(raw) {
  if (!raw) return [];
  return raw
    .split(',')
    .map((o) => o.trim().replace(/\/+$/, '')) // drop trailing slashes
    .filter(Boolean);
}

/**
 * Build the effective configuration object.
 *
 * @param {object} [overrides] - explicit overrides, used by tests.
 */
function getConfig(overrides = {}) {
  // Load .env from the agent root (one level up from src/) once.
  loadDotEnv(path.join(__dirname, '..'));

  const env = process.env;

  let backend = (overrides.backend || env.SCANNER_AGENT_BACKEND || 'mock').toLowerCase();
  if (!SUPPORTED_BACKENDS.includes(backend)) {
    backend = 'mock';
  }

  const config = {
    version: VERSION,
    host: overrides.host || env.SCANNER_AGENT_HOST || '127.0.0.1',
    port: toInt(overrides.port || env.SCANNER_AGENT_PORT, 17661),
    backend,
    allowedOrigins:
      overrides.allowedOrigins ||
      parseOrigins(env.SCANNER_AGENT_ALLOWED_ORIGINS),
    timeoutMs: toInt(overrides.timeoutMs || env.SCANNER_AGENT_TIMEOUT_MS, 30000),
    mockImage: overrides.mockImage || env.SCANNER_AGENT_MOCK_IMAGE || null,
    device: overrides.device || env.SCANNER_AGENT_DEVICE || null,
    dpi: toInt(overrides.dpi || env.SCANNER_AGENT_DPI, 200),
    quality: toInt(overrides.quality || env.SCANNER_AGENT_QUALITY, 82),
    maxWidth: toInt(overrides.maxWidth || env.SCANNER_AGENT_MAX_WIDTH, 1600),
    // Request body hard cap. The scan payload is a tiny JSON object, so 1 MiB
    // is already very generous and protects against memory-exhaustion abuse.
    maxBodyBytes: toInt(
      overrides.maxBodyBytes || env.SCANNER_AGENT_MAX_BODY_BYTES,
      1024 * 1024
    ),
    // NAPS2 console executable path / profile (Windows prototype backend).
    naps2Path: overrides.naps2Path || env.SCANNER_AGENT_NAPS2_PATH || null,
    naps2Profile: overrides.naps2Profile || env.SCANNER_AGENT_NAPS2_PROFILE || null,
    // WIA PowerShell script path (experimental Windows backend).
    wiaScript: overrides.wiaScript || env.SCANNER_AGENT_WIA_SCRIPT || null,
  };

  return config;
}

module.exports = { getConfig, VERSION, SUPPORTED_BACKENDS };
