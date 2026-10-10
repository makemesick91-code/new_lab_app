"""
REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — synthetic CAMERA FRAMES for the
live field overlay (development only; never a real card).

The corpus written by generate_synthetic_ktp.py is already cropped the way the
previous release cropped a photo. The live overlay changes what happens BEFORE
that crop, so this script writes the whole camera frame and, beside it, the
previous release's capture of the SAME frame. Every pipeline is then measured on
identical source pixels:

  <id>.frame.rgba.gz  the full camera frame, lossless (what the live tracker and
                      the new capture see)
  <id>.jpg / .rgba    the previous release's capture of that frame: static 90%
                      guide crop (guideCropRect) -> JPEG 0.95 (capture) -> fit
                      1600 px, JPEG 0.85 (compressImage) -> decoded RGBA
  <id>.json           ground truth, frame size, card corners in frame pixels

With --y4m it also writes Y4M clips for Chrome's fake camera
(--use-file-for-fake-video-capture): a steady card, and an empty desk.

Every identity is invented (see generate_synthetic_ktp.IDENTITIES). Write the
output outside the repository.

    python3 tools/ktp-ocr-benchmark/generate_live_frames.py /tmp/ktp-live --seed 11 [--limit 12] [--y4m]
"""

import argparse
import gzip
import io
import json
import math
import os
import random
import sys

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from generate_synthetic_ktp import (  # noqa: E402  (shared fictional identities + renderer)
    CARD_H,
    CARD_W,
    IDENTITIES,
    browser_compress,
    nik_for,
    perspective_coeffs,
    render_card,
)

KTP_ASPECT = 85.6 / 53.98

# condition -> (frame size, card width as a fraction of the frame width)
CONDITIONS = {
    'frame_clear': ((1920, 1080), (0.55, 0.68)),
    'frame_rotated': ((1920, 1080), (0.55, 0.65)),
    'frame_perspective': ((1920, 1080), (0.55, 0.65)),
    'frame_dim_blur': ((1920, 1080), (0.55, 0.65)),
    'frame_glare': ((1920, 1080), (0.55, 0.65)),
    'frame_720p': ((1280, 720), (0.50, 0.60)),
    # Fake-camera clip only (--y4m): a 720p webcam with the card filling the
    # dashed guide, so the indicator can reach GREEN in a browser run.
    'y4m_close': ((1280, 720), (0.78, 0.80)),
}
BENCHMARK_CONDITIONS = [c for c in CONDITIONS if not c.startswith('y4m_')]


def guide_crop_rect(fw, fh, coverage=0.9, aspect=KTP_ASPECT):
    """Mirror of guideCropRect() in resources/js/ktp-camera-ocr.js."""
    width = fw * coverage
    height = width / aspect
    if height > fh * coverage:
        height = fh * coverage
        width = height * aspect
    return (round((fw - width) / 2), round((fh - height) / 2), round(width), round(height))


def desk(fw, fh, rng):
    tone = rng.randint(55, 110)
    yy, xx = np.mgrid[0:fh, 0:fw]
    noise = np.random.default_rng(rng.randint(0, 10**6)).normal(0, 6, (fh, fw))
    d = tone + 18 * np.sin(xx / 61.0) * np.cos(yy / 47.0) + noise
    return Image.fromarray(np.stack([d, d * 0.95, d * 0.88], axis=-1).clip(0, 255).astype(np.uint8))


def place_card(card, condition, rng, center_jitter=0.03):
    (fw, fh), (lo, hi) = CONDITIONS[condition]
    frame = desk(fw, fh, rng)
    w = rng.uniform(lo, hi) * fw
    h = w / (CARD_W / CARD_H)
    cx = fw / 2 + rng.uniform(-center_jitter, center_jitter) * fw
    cy = fh / 2 + rng.uniform(-center_jitter, center_jitter) * fh
    angle = rng.choice([-1, 1]) * rng.uniform(4.0, 9.0) if condition == 'frame_rotated' else rng.uniform(-1.2, 1.2)
    rad = math.radians(angle)
    base = [(-w / 2, -h / 2), (w / 2, -h / 2), (w / 2, h / 2), (-w / 2, h / 2)]
    if condition == 'frame_perspective':
        k = rng.uniform(0.06, 0.12)
        base = [(-w / 2 * (1 - k), -h / 2), (w / 2 * (1 - k), -h / 2), (w / 2, h / 2), (-w / 2, h / 2)]
    corners = [(cx + x * math.cos(rad) - y * math.sin(rad), cy + x * math.sin(rad) + y * math.cos(rad)) for x, y in base]

    src = [(0, 0), (CARD_W - 1, 0), (CARD_W - 1, CARD_H - 1), (0, CARD_H - 1)]
    coeffs = perspective_coeffs(src, corners)
    warped = card.transform((fw, fh), Image.PERSPECTIVE, coeffs, Image.BICUBIC)
    mask_src = Image.new('L', (CARD_W, CARD_H), 0)
    ImageDraw.Draw(mask_src).rounded_rectangle([0, 0, CARD_W - 1, CARD_H - 1], radius=round(CARD_W * 3.18 / 85.6), fill=255)
    frame.paste(warped, (0, 0), mask_src.transform((fw, fh), Image.PERSPECTIVE, coeffs, Image.BICUBIC))

    arr = np.asarray(frame).astype(np.float32)
    if condition == 'frame_dim_blur':
        arr = arr * rng.uniform(0.62, 0.72)
        frame = Image.fromarray(arr.clip(0, 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(1.6))
        arr = np.asarray(frame).astype(np.float32)
        arr += np.random.default_rng(rng.randint(0, 10**6)).normal(0, 7, arr.shape)
    elif condition == 'frame_glare':
        yy, xx = np.mgrid[0:fh, 0:fw]
        gx, gy = rng.uniform(0.35, 0.6) * fw, rng.uniform(0.35, 0.6) * fh
        d2 = ((xx - gx) ** 2 + (yy - gy) ** 2) / (2 * (fw * 0.09) ** 2)
        arr += (120 * np.exp(-d2))[..., None]
    else:
        arr += np.random.default_rng(rng.randint(0, 10**6)).normal(0, 3, arr.shape)
    return Image.fromarray(arr.clip(0, 255).astype(np.uint8)), [[round(x, 2), round(y, 2)] for x, y in corners]


def previous_release_capture(frame):
    """The previous release: static guide crop, JPEG 0.95, then compressImage()."""
    x, y, w, h = guide_crop_rect(*frame.size)
    crop = frame.crop((x, y, x + w, y + h))
    buf = io.BytesIO()
    crop.convert('RGB').save(buf, 'JPEG', quality=95)
    captured = Image.open(io.BytesIO(buf.getvalue()))
    return browser_compress(captured)


def write_y4m(path, frames, fps=30):
    """Planar YUV 4:2:0 (BT.601 full range) — Chrome's fake capture input."""
    w, h = frames[0].size
    with open(path, 'wb') as fh:
        fh.write(f'YUV4MPEG2 W{w} H{h} F{fps}:1 Ip A1:1 C420jpeg\n'.encode())
        for img in frames:
            rgb = np.asarray(img.convert('RGB')).astype(np.float32)
            r, g, b = rgb[..., 0], rgb[..., 1], rgb[..., 2]
            yp = 0.299 * r + 0.587 * g + 0.114 * b
            u = -0.168736 * r - 0.331264 * g + 0.5 * b + 128
            v = 0.5 * r - 0.418688 * g - 0.081312 * b + 128
            sub = lambda c: c.reshape(h // 2, 2, w // 2, 2).mean(axis=(1, 3))  # noqa: E731
            fh.write(b'FRAME\n')
            for plane in (yp, sub(u), sub(v)):
                fh.write(plane.clip(0, 255).astype(np.uint8).tobytes())


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('out')
    parser.add_argument('--seed', type=int, default=11)
    parser.add_argument('--limit', type=int, default=12)
    parser.add_argument('--y4m', action='store_true')
    args = parser.parse_args()
    os.makedirs(args.out, exist_ok=True)
    rng = random.Random(args.seed)
    manifest = []

    for i, identity in enumerate(IDENTITIES[: args.limit]):
        nik = nik_for(identity, 5000 + i * 41)
        card = render_card(identity, nik, rng)
        year, month, day = identity['dob']
        truth = {
            'nik': nik, 'name': identity['name'], 'birth_place': identity['place'],
            'date_of_birth': f'{year:04d}-{month:02d}-{day:02d}',
            'gender': 'Male' if identity['gender'] == 'LAKI-LAKI' else 'Female',
            'street': identity['address'], 'rt_rw': identity['rt_rw'], 'village': identity['village'],
            'district': identity['district'], 'religion': identity['religion'],
            'marital_status': identity['marital'], 'occupation': identity['occupation'],
        }
        for condition in BENCHMARK_CONDITIONS:
            frame, corners = place_card(card, condition, rng)
            sid = f'id{i}_{condition}'
            jpeg = previous_release_capture(frame)
            decoded = Image.open(io.BytesIO(jpeg)).convert('RGBA')
            with open(os.path.join(args.out, sid + '.jpg'), 'wb') as fh:
                fh.write(jpeg)
            with open(os.path.join(args.out, sid + '.rgba'), 'wb') as fh:
                fh.write(decoded.tobytes())
            with gzip.open(os.path.join(args.out, sid + '.frame.rgba.gz'), 'wb', compresslevel=3) as fh:
                fh.write(frame.convert('RGBA').tobytes())
            meta = {'id': sid, 'scene': condition, 'width': decoded.width, 'height': decoded.height,
                    'frameWidth': frame.size[0], 'frameHeight': frame.size[1],
                    'frameCorners': corners, 'truth': truth}
            with open(os.path.join(args.out, sid + '.json'), 'w') as fh:
                json.dump(meta, fh)
            manifest.append(sid)

    with open(os.path.join(args.out, 'manifest.json'), 'w') as fh:
        json.dump(manifest, fh)

    if args.y4m:
        card = render_card(IDENTITIES[0], nik_for(IDENTITIES[0], 9001), rng)
        steady_rng = random.Random(args.seed + 1)
        base, y4m_corners = place_card(card, 'y4m_close', steady_rng, center_jitter=0.0)
        arr = np.asarray(base).astype(np.float32)
        noise = np.random.default_rng(3)
        steady = [Image.fromarray((arr + noise.normal(0, 2, arr.shape)).clip(0, 255).astype(np.uint8)) for _ in range(30)]
        write_y4m(os.path.join(args.out, 'fake-camera-card.y4m'), steady)
        with open(os.path.join(args.out, 'fake-camera-card.json'), 'w') as fh:
            ident = IDENTITIES[0]
            json.dump({'frameWidth': base.size[0], 'frameHeight': base.size[1], 'frameCorners': y4m_corners,
                       'truth': {'nik': nik_for(ident, 9001), 'name': ident['name'],
                                 'date_of_birth': '%04d-%02d-%02d' % tuple(ident['dob']),
                                 'gender': 'Male' if ident['gender'] == 'LAKI-LAKI' else 'Female',
                                 'occupation': ident['occupation']}}, fh)
        empty = desk(1280, 720, random.Random(5))
        write_y4m(os.path.join(args.out, 'fake-camera-empty.y4m'), [empty] * 10)

    print(f'{len(manifest)} synthetic frames written to {args.out}')


if __name__ == '__main__':
    main()
