/**
 * Minimal PNG encoder for the KTP OCR benchmark (Node only, no dependency).
 */

import { deflateSync } from 'node:zlib';

/** Minimal PNG encoder (RGBA or gray) so crops reach tesseract losslessly. */
export function encodePng({ width, height, data }, channels = 4) {
    const crcTable = new Int32Array(256).map((_, n) => {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        return c;
    });
    const crc = (buf) => {
        let c = -1;
        for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8);
        return (c ^ -1) >>> 0;
    };
    const chunk = (type, body) => {
        const len = Buffer.alloc(4);
        len.writeUInt32BE(body.length);
        const tb = Buffer.concat([Buffer.from(type), body]);
        const c = Buffer.alloc(4);
        c.writeUInt32BE(crc(tb));
        return Buffer.concat([len, tb, c]);
    };
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8;
    ihdr[9] = channels === 1 ? 0 : 6;
    const stride = width * channels;
    const raw = Buffer.alloc((stride + 1) * height);
    for (let y = 0; y < height; y++) {
        raw[y * (stride + 1)] = 0;
        Buffer.from(data.buffer, data.byteOffset + y * stride, stride).copy(raw, y * (stride + 1) + 1);
    }

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        chunk('IDAT', deflateSync(raw)),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

