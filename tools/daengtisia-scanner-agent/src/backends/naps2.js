'use strict';

/**
 * Windows NAPS2 CLI backend (prototype).
 *
 * Setup:
 *  1. Install NAPS2 (https://www.naps2.com/).
 *  2. Create a scanner profile in the NAPS2 GUI, e.g. named "ktp".
 *  3. Point the agent at the console executable and the profile:
 *       SCANNER_AGENT_NAPS2_PATH="C:\\Program Files\\NAPS2\\NAPS2.Console.exe"
 *       SCANNER_AGENT_NAPS2_PROFILE="ktp"
 *
 * SECURITY: the executable path and profile name come from env config only,
 * never from the HTTP request. Flags are fixed; only validated numeric DPI is
 * forwarded. We scan to a temp file and delete it after reading.
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

function requireConfigured(config) {
  if (!config.naps2Path) {
    throw new BackendError(
      'NAPS2_NOT_CONFIGURED',
      'naps2 backend selected but SCANNER_AGENT_NAPS2_PATH is not set.'
    );
  }
  if (!config.naps2Profile) {
    throw new BackendError(
      'NAPS2_NOT_CONFIGURED',
      'naps2 backend selected but SCANNER_AGENT_NAPS2_PROFILE is not set.'
    );
  }
}

async function health(config) {
  try {
    requireConfigured(config);
  } catch (err) {
    return {
      ok: false,
      device: null,
      backend: 'naps2',
      ready: false,
      message: err.message,
    };
  }
  const installed = fs.existsSync(config.naps2Path);
  return {
    ok: installed,
    device: installed ? `NAPS2:${config.naps2Profile}` : null,
    backend: 'naps2',
    ready: installed,
    message: installed ? undefined : 'NAPS2.Console.exe not found at configured path.',
  };
}

async function listDevices(config) {
  // NAPS2 enumerates devices via its own profiles; expose the configured one.
  return config.naps2Profile ? [`NAPS2:${config.naps2Profile}`] : [];
}

async function scan(params, config) {
  requireConfigured(config);

  const outFile = path.join(
    os.tmpdir(),
    `daengtisia-naps2-${crypto.randomBytes(8).toString('hex')}.jpg`
  );

  // Fixed flags. --output writes the scanned page; --profile selects the
  // pre-created scanner profile; -n 1 limits to a single page.
  const args = [
    '-o',
    outFile,
    '-p',
    config.naps2Profile,
    '-n',
    '1',
    '--force',
  ];

  try {
    await runCommand(config.naps2Path, args, { timeoutMs: config.timeoutMs });
    if (!fs.existsSync(outFile)) {
      throw new BackendError('SCAN_FAILED', 'NAPS2 did not produce an output file.');
    }
    const buffer = fs.readFileSync(outFile);
    return { buffer, mimeType: 'image/jpeg', filename: 'ktp-scan.jpg' };
  } catch (err) {
    if (err.errorCode) throw err;
    if (err.code === 'ENOENT') {
      throw new BackendError(
        'NAPS2_NOT_INSTALLED',
        'naps2 backend selected but NAPS2.Console.exe was not found.'
      );
    }
    if (err.code === 'ETIMEDOUT') {
      throw new BackendError('SCAN_TIMEOUT', err.message);
    }
    throw new BackendError('SCANNER_UNAVAILABLE', err.message);
  } finally {
    // Never leave patient-document temp files on disk.
    fs.rmSync(outFile, { force: true });
  }
}

module.exports = { health, listDevices, scan, BackendError };
