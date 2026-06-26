'use strict';

/**
 * Windows WIA backend via PowerShell (EXPERIMENTAL — not a default).
 *
 * This drives scripts/windows/wia-scan.ps1, which uses the WIA COM API to grab
 * a single page to a temp JPEG. WIA automation is fragile across drivers and
 * may pop a vendor UI on some devices; treat this as a reference backend only.
 *
 * Enable with:
 *   SCANNER_AGENT_BACKEND=wia
 *   SCANNER_AGENT_WIA_SCRIPT="C:\\path\\to\\wia-scan.ps1"   (optional override)
 *
 * SECURITY: PowerShell is invoked with a fixed, well-known script path and
 * -File (not -Command), so request bodies cannot inject script content. Only a
 * validated numeric DPI and an output path we generate are passed.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { runCommand } = require('../exec');

class BackendError extends Error {
  constructor(code, message) {
    super(message);
    this.errorCode = code;
  }
}

const POWERSHELL = 'powershell.exe';

function resolveScript(config) {
  if (config.wiaScript) return config.wiaScript;
  // Default to the bundled reference script.
  return path.join(__dirname, '..', '..', 'scripts', 'windows', 'wia-scan.ps1');
}

async function health(config) {
  const script = resolveScript(config);
  const exists = fs.existsSync(script);
  return {
    ok: exists && process.platform === 'win32',
    device: exists ? 'WIA Default Scanner' : null,
    backend: 'wia',
    ready: exists && process.platform === 'win32',
    message:
      process.platform === 'win32'
        ? exists
          ? 'WIA is experimental; verify with your scanner driver.'
          : 'WIA scan script not found.'
        : 'WIA backend is only available on Windows.',
  };
}

async function listDevices() {
  return ['WIA Default Scanner'];
}

async function scan(params, config) {
  if (process.platform !== 'win32') {
    throw new BackendError(
      'WIA_UNAVAILABLE',
      'wia backend is only available on Windows.'
    );
  }
  const script = resolveScript(config);
  if (!fs.existsSync(script)) {
    throw new BackendError('WIA_SCRIPT_MISSING', 'WIA scan script not found.');
  }

  const outFile = path.join(
    os.tmpdir(),
    `daengtisia-wia-${crypto.randomBytes(8).toString('hex')}.jpg`
  );

  const args = [
    '-NoProfile',
    '-ExecutionPolicy',
    'Bypass',
    '-File',
    script,
    '-OutFile',
    outFile,
    '-Dpi',
    String(params.dpi),
  ];

  try {
    await runCommand(POWERSHELL, args, { timeoutMs: config.timeoutMs });
    if (!fs.existsSync(outFile)) {
      throw new BackendError('SCAN_FAILED', 'WIA script did not produce an output file.');
    }
    const buffer = fs.readFileSync(outFile);
    return { buffer, mimeType: 'image/jpeg', filename: 'ktp-scan.jpg' };
  } catch (err) {
    if (err.errorCode) throw err;
    if (err.code === 'ETIMEDOUT') {
      throw new BackendError('SCAN_TIMEOUT', err.message);
    }
    throw new BackendError('SCANNER_UNAVAILABLE', err.message);
  } finally {
    fs.rmSync(outFile, { force: true });
  }
}

module.exports = { health, listDevices, scan, BackendError };
