'use strict';

/**
 * Mock backend — works with no scanner hardware.
 *
 * It either:
 *  - returns a configured sample image (SCANNER_AGENT_MOCK_IMAGE), or
 *  - generates a synthetic placeholder PNG entirely in-process (zlib only).
 *
 * IMPORTANT: never ship a real KTP sample in the repo. The generated image is
 * an obvious "MOCK" placeholder so it can never be mistaken for a real scan.
 */

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const PNG_SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

// --- tiny CRC32 (PNG chunk checksums) ------------------------------------
const CRC_TABLE = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) {
      c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    }
    table[n] = c;
  }
  return table;
})();

function crc32(buf) {
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i++) {
    c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  }
  return (c ^ 0xffffffff) >>> 0;
}

function pngChunk(type, data) {
  const typeBuf = Buffer.from(type, 'ascii');
  const lenBuf = Buffer.alloc(4);
  lenBuf.writeUInt32BE(data.length, 0);
  const crcBuf = Buffer.alloc(4);
  crcBuf.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])), 0);
  return Buffer.concat([lenBuf, typeBuf, data, crcBuf]);
}

/**
 * Generate a small placeholder PNG with a colored gradient and "MOCK" stripes.
 * @param {number} width
 * @param {number} height
 */
function generatePlaceholderPng(width = 400, height = 252) {
  const raw = Buffer.alloc((width * 3 + 1) * height);
  let pos = 0;
  for (let y = 0; y < height; y++) {
    raw[pos++] = 0; // filter type: none
    for (let x = 0; x < width; x++) {
      // Diagonal stripes so the image is visibly a synthetic placeholder.
      const stripe = ((x + y) % 48) < 24;
      raw[pos++] = stripe ? 0x1f : 0x4f; // R
      raw[pos++] = stripe ? 0x6f : 0x9a; // G
      raw[pos++] = stripe ? 0xb6 : 0xd8; // B
    }
  }

  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 2; // color type: truecolor RGB
  ihdr[10] = 0; // compression
  ihdr[11] = 0; // filter
  ihdr[12] = 0; // interlace

  return Buffer.concat([
    PNG_SIGNATURE,
    pngChunk('IHDR', ihdr),
    pngChunk('IDAT', zlib.deflateSync(raw)),
    pngChunk('IEND', Buffer.alloc(0)),
  ]);
}

function mimeFromExt(filePath) {
  const ext = path.extname(filePath).toLowerCase();
  if (ext === '.jpg' || ext === '.jpeg') return 'image/jpeg';
  if (ext === '.png') return 'image/png';
  return 'application/octet-stream';
}

async function health(config) {
  return {
    ok: true,
    device: config.device || 'Mock Daengtisia Scanner',
    backend: 'mock',
    ready: true,
  };
}

async function listDevices(config) {
  return [config.device || 'Mock Daengtisia Scanner'];
}

/**
 * Produce a mock scan result.
 * @returns {{buffer: Buffer, mimeType: string, filename: string}}
 */
async function scan(_params, config) {
  if (config.mockImage) {
    const buffer = fs.readFileSync(config.mockImage);
    const mimeType = mimeFromExt(config.mockImage);
    const filename = mimeType === 'image/jpeg' ? 'ktp-scan.jpg' : 'ktp-scan.png';
    return { buffer, mimeType, filename };
  }
  return {
    buffer: generatePlaceholderPng(),
    mimeType: 'image/png',
    filename: 'ktp-scan.png',
  };
}

module.exports = { health, listDevices, scan, generatePlaceholderPng };
