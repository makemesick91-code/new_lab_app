'use strict';

/**
 * Linux SANE backend using `scanimage` (sane-utils).
 *
 * Install:   sudo apt install sane-utils
 * Detect:    scanimage -L
 * Manual:    scanimage --format=jpeg --resolution 200 > ktp-test.jpg
 *
 * SECURITY: the scanimage binary name and flag names are fixed in code. Only
 * validated numeric values (resolution) and a configured device name (from
 * env, not from the HTTP request) are passed as separate argv entries.
 */

const { runCommand } = require('../exec');

const SCANIMAGE = 'scanimage';

class BackendError extends Error {
  constructor(code, message) {
    super(message);
    this.errorCode = code;
  }
}

async function listDevices(config) {
  try {
    const { stdout } = await runCommand(SCANIMAGE, ['-L'], {
      timeoutMs: Math.min(config.timeoutMs, 10000),
    });
    const text = stdout.toString('utf8');
    // Lines look like: device `epson2:libusb:001:002' is a Epson ...
    const devices = [];
    for (const line of text.split(/\r?\n/)) {
      const m = line.match(/device `([^']+)'/);
      if (m) devices.push(m[1]);
    }
    return devices;
  } catch (err) {
    if (err.code === 'ENOENT') {
      throw new BackendError(
        'SCANIMAGE_NOT_INSTALLED',
        'sane backend selected but scanimage is not installed.'
      );
    }
    throw new BackendError('SCANNER_UNAVAILABLE', err.message);
  }
}

async function health(config) {
  try {
    const devices = await listDevices(config);
    if (devices.length === 0) {
      return { ok: false, device: null, backend: 'sane', ready: false };
    }
    return {
      ok: true,
      device: config.device || devices[0],
      backend: 'sane',
      ready: true,
    };
  } catch (err) {
    return {
      ok: false,
      device: null,
      backend: 'sane',
      ready: false,
      message: err.message,
    };
  }
}

/**
 * Scan a single page to JPEG bytes via scanimage.
 * @returns {{buffer: Buffer, mimeType: string, filename: string}}
 */
async function scan(params, config) {
  const args = ['--format=jpeg', '--resolution', String(params.dpi)];
  if (config.device) {
    args.push('--device-name', config.device);
  }

  try {
    const { stdout } = await runCommand(SCANIMAGE, args, {
      timeoutMs: config.timeoutMs,
    });
    if (!stdout || stdout.length === 0) {
      throw new BackendError(
        'SCAN_FAILED',
        'scanimage returned no image data.'
      );
    }
    return { buffer: stdout, mimeType: 'image/jpeg', filename: 'ktp-scan.jpg' };
  } catch (err) {
    if (err.errorCode) throw err;
    if (err.code === 'ENOENT') {
      throw new BackendError(
        'SCANIMAGE_NOT_INSTALLED',
        'sane backend selected but scanimage is not installed.'
      );
    }
    if (err.code === 'ETIMEDOUT') {
      throw new BackendError('SCAN_TIMEOUT', err.message);
    }
    throw new BackendError('SCANNER_UNAVAILABLE', err.message);
  }
}

module.exports = { health, listDevices, scan, BackendError };
