#!/usr/bin/env python3
"""
REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — synthetic KTP benchmark corpus.

Renders FICTIONAL Indonesian e-KTP cards and places them into camera-like and
scanner-like scenes. Every identity below is invented; no real person, NIK or
address is used, and nothing is written into the repository — the corpus is
generated on demand into an output directory you pass on the command line.

For each scene the generator writes exactly what the browser OCR pipeline sees:

  <id>.jpg    the bytes after the browser's compressImage() step
              (fit to 1600 px wide, JPEG quality 0.85 — see ktp-camera-ocr.js)
  <id>.rgba   the same JPEG decoded to raw RGBA, so a Node harness gets the
              identical pixels a <canvas> would hand the pipeline
  <id>.json   dimensions, ground truth and the true card corners

Usage:
  python3 generate_synthetic_ktp.py <output_dir> [--seed 7]

Requires Pillow and numpy (dev machine only — never a production dependency).
"""

import argparse
import io
import json
import math
import os
import random

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

FONT_REGULAR = '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf'
FONT_BOLD = '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'

CARD_W, CARD_H = 1000, 630  # ID-1 aspect 85.6 x 53.98

# Fictional identities. Names, places and streets are invented for testing.
IDENTITIES = [
    dict(name='BUDI SANTOSO', place='MAKASSAR', dob=(1985, 3, 12), gender='LAKI-LAKI',
         address='JL. MAWAR INDAH NO. 12', rt_rw='003/005', village='BONTOALA', district='TAMALATE',
         religion='ISLAM', marital='KAWIN', occupation='KARYAWAN SWASTA'),
    dict(name='SITI NURHALIZA RAHMAN', place='GOWA', dob=(1992, 11, 2), gender='PEREMPUAN',
         address='BTN GRIYA ASRI BLOK C2 NO. 7', rt_rw='011/002', village='PACCINONGANG', district='SOMBA OPU',
         religion='ISLAM', marital='BELUM KAWIN', occupation='MAHASISWA'),
    dict(name='ANDI MUHAMMAD FADLI', place='BONE', dob=(1978, 6, 25), gender='LAKI-LAKI',
         address='JL. PERINTIS KEMERDEKAAN KM 9', rt_rw='001/004', village='TAMALANREA', district='TAMALANREA',
         religion='ISLAM', marital='KAWIN', occupation='WIRASWASTA'),
    dict(name='MARIA GRACE TAMBUNAN', place='MANADO', dob=(2000, 1, 30), gender='PEREMPUAN',
         address='JL. SAMRATULANGI NO. 88', rt_rw='002/001', village='MARIDAYA', district='UJUNG PANDANG',
         religion='KRISTEN', marital='BELUM KAWIN', occupation='PELAJAR/MAHASISWA'),
    dict(name='HASANUDDIN', place='MAROS', dob=(1965, 9, 9), gender='LAKI-LAKI',
         address='DUSUN BONTO TANGNGA', rt_rw='004/002', village='TURIKALE', district='TURIKALE',
         religion='ISLAM', marital='CERAI MATI', occupation='PETANI/PEKEBUN'),
    dict(name='NUR AISYAH PUTRI AMALIA', place='PAREPARE', dob=(1998, 4, 17), gender='PEREMPUAN',
         address='JL. ANDI MAKKASAU LR. 3 NO. 21', rt_rw='006/003', village='UJUNG BULU', district='UJUNG',
         religion='ISLAM', marital='KAWIN', occupation='MENGURUS RUMAH TANGGA'),
    dict(name='YOHANES KEVIN WIJAYA', place='TORAJA', dob=(1989, 12, 5), gender='LAKI-LAKI',
         address='KOMPLEKS PANAKKUKANG MAS B4', rt_rw='009/007', village='PANDANG', district='PANAKKUKANG',
         religion='KATOLIK', marital='KAWIN', occupation='PEGAWAI NEGERI SIPIL'),
    dict(name='DEWI LESTARI', place='SINJAI', dob=(1975, 7, 21), gender='PEREMPUAN',
         address='JL. VETERAN SELATAN NO. 140', rt_rw='005/010', village='MAMAJANG LUAR', district='MAMAJANG',
         religion='HINDU', marital='CERAI HIDUP', occupation='PEDAGANG'),
]

SCENES = ['scan_flat', 'camera_framed', 'camera_rotated', 'camera_perspective', 'camera_dim_blur', 'camera_glare']


def nik_for(identity, serial):
    year, month, day = identity['dob']
    dd = day + 40 if identity['gender'] == 'PEREMPUAN' else day
    # 73 = province code, 71 = city code, 05 = district code (fictional serial).
    return f"737105{dd:02d}{month:02d}{year % 100:02d}{serial:04d}"


def font(path, size):
    return ImageFont.truetype(path, size)


def guilloche_background(rng):
    """Light blue security background with interfering wave lines."""
    base = np.zeros((CARD_H, CARD_W, 3), dtype=np.float32)
    yy, xx = np.mgrid[0:CARD_H, 0:CARD_W]
    base[..., 0] = 196 + 12 * (xx / CARD_W)
    base[..., 1] = 222 + 8 * (yy / CARD_H)
    base[..., 2] = 238
    img = Image.fromarray(base.clip(0, 255).astype(np.uint8))
    draw = ImageDraw.Draw(img)
    phase = rng.uniform(0, math.pi)
    for k in range(0, CARD_H + 40, 9):
        points = [(x, k + 7 * math.sin(x / 37.0 + phase + k / 50.0)) for x in range(0, CARD_W + 10, 6)]
        draw.line(points, fill=(170, 205, 228), width=1)
    for r in range(40, 320, 14):
        draw.ellipse([CARD_W * 0.42 - r, CARD_H * 0.55 - r, CARD_W * 0.42 + r, CARD_H * 0.55 + r],
                     outline=(182, 212, 232), width=1)
    return img


def render_card(identity, nik, rng):
    img = guilloche_background(rng)
    draw = ImageDraw.Draw(img)
    black = (20, 20, 22)

    header = font(FONT_BOLD, 27)
    for text, y in (('PROVINSI SULAWESI SELATAN', 16), ('KOTA MAKASSAR', 50)):
        w = draw.textlength(text, font=header)
        draw.text(((CARD_W - w) / 2, y), text, font=header, fill=black)

    label_font = font(FONT_REGULAR, 22)
    value_font = font(FONT_REGULAR, 22)
    nik_font = font(FONT_BOLD, 33)

    # Print runs differ: jitter the layout so the benchmark exercises the
    # template anchoring instead of matching one fixed geometry.
    nik_y = rng.uniform(92, 106)
    nik_x = rng.uniform(190, 214)
    colon_x = rng.uniform(212, 242)
    row_start = rng.uniform(140, 160)
    row_step = rng.uniform(31.5, 35.5)

    draw.text((28, nik_y + 3), 'NIK', font=font(FONT_BOLD, 29), fill=black)
    draw.text((nik_x - 18, nik_y + 3), ':', font=font(FONT_BOLD, 29), fill=black)
    draw.text((nik_x, nik_y), nik, font=nik_font, fill=black)

    year, month, day = identity['dob']
    rows = [
        ('Nama', identity['name'], 28),
        ('Tempat/Tgl Lahir', f"{identity['place']}, {day:02d}-{month:02d}-{year}", 28),
        ('Jenis kelamin', identity['gender'], 28),
        ('Alamat', identity['address'], 28),
        ('RT/RW', identity['rt_rw'], 55),
        ('Kel/Desa', identity['village'], 55),
        ('Kecamatan', identity['district'], 55),
        ('Agama', identity['religion'], 28),
        ('Status Perkawinan', identity['marital'], 28),
        ('Pekerjaan', identity['occupation'], 28),
        ('Kewarganegaraan', 'WNI', 28),
        ('Berlaku Hingga', 'SEUMUR HIDUP', 28),
    ]
    y = row_start
    for label, value, x in rows:
        draw.text((x, y), label, font=label_font, fill=black)
        draw.text((colon_x, y), ':', font=label_font, fill=black)
        draw.text((colon_x + 18, y), value, font=value_font, fill=black)
        if label == 'Jenis kelamin':
            draw.text((520, y), 'Gol. Darah : O', font=label_font, fill=black)
        y += row_step

    # Portrait placeholder (no face — a neutral silhouette block).
    draw.rectangle([748, 110, 958, 400], fill=(205, 30, 35))
    draw.ellipse([803, 150, 903, 265], fill=(230, 210, 200))
    draw.rectangle([790, 270, 916, 400], fill=(40, 40, 60))

    small = font(FONT_REGULAR, 20)
    for text, yy in (('KOTA MAKASSAR', 418), (f"{rng.randint(1, 28):02d}-0{rng.randint(1, 9)}-2019", 444)):
        w = draw.textlength(text, font=small)
        draw.text((853 - w / 2, yy), text, font=small, fill=black)
    pts = [(790 + i * 6, 520 + 18 * math.sin(i / 2.3) + rng.uniform(-4, 4)) for i in range(22)]
    draw.line(pts, fill=(25, 25, 60), width=2)
    return img


def card_corners_after(matrix_points):
    return [[round(x, 2), round(y, 2)] for x, y in matrix_points]


def perspective_coeffs(src, dst):
    """Coefficients mapping OUTPUT (dst) coords to INPUT (src) coords for PIL."""
    a = []
    b = []
    for (xs, ys), (xd, yd) in zip(src, dst):
        a.append([xd, yd, 1, 0, 0, 0, -xs * xd, -xs * yd])
        a.append([0, 0, 0, xd, yd, 1, -ys * xd, -ys * yd])
        b.extend([xs, ys])
    return np.linalg.solve(np.array(a, dtype=np.float64), np.array(b, dtype=np.float64)).tolist()


def place_in_scene(card, scene, rng):
    """Return (scene image, card corners in scene coords)."""
    if scene == 'scan_flat':
        corners = [[0, 0], [CARD_W - 1, 0], [CARD_W - 1, CARD_H - 1], [0, CARD_H - 1]]
        return card.resize((1600, round(1600 * CARD_H / CARD_W)), Image.LANCZOS), \
            [[x * 1600 / CARD_W, y * 1600 / CARD_W] for x, y in corners]

    # Guide crop of a 1920x1080 camera frame at 90% coverage (see guideCropRect).
    fw, fh = 1541, 972
    bg_tone = rng.randint(55, 110)
    yy, xx = np.mgrid[0:fh, 0:fw]
    noise = np.random.default_rng(rng.randint(0, 10**6)).normal(0, 6, (fh, fw))
    desk = bg_tone + 18 * np.sin(xx / 61.0) * np.cos(yy / 47.0) + noise
    scene_img = Image.fromarray(np.stack([desk, desk * 0.95, desk * 0.88], axis=-1).clip(0, 255).astype(np.uint8))

    scale = rng.uniform(0.80, 0.90) * fw / CARD_W
    w, h = CARD_W * scale, CARD_H * scale
    cx = fw / 2 + rng.uniform(-0.03, 0.03) * fw
    cy = fh / 2 + rng.uniform(-0.03, 0.03) * fh
    angle = {'camera_rotated': rng.choice([-1, 1]) * rng.uniform(3.0, 7.0)}.get(scene, rng.uniform(-1.2, 1.2))
    rad = math.radians(angle)

    base = [(-w / 2, -h / 2), (w / 2, -h / 2), (w / 2, h / 2), (-w / 2, h / 2)]
    if scene == 'camera_perspective':
        k = rng.uniform(0.06, 0.10)
        base = [(-w / 2 * (1 - k), -h / 2), (w / 2 * (1 - k), -h / 2), (w / 2, h / 2), (-w / 2, h / 2)]
    corners = [(cx + x * math.cos(rad) - y * math.sin(rad), cy + x * math.sin(rad) + y * math.cos(rad)) for x, y in base]

    src = [(0, 0), (CARD_W - 1, 0), (CARD_W - 1, CARD_H - 1), (0, CARD_H - 1)]
    coeffs = perspective_coeffs(src, corners)
    warped = card.transform((fw, fh), Image.PERSPECTIVE, coeffs, Image.BICUBIC)
    # ID-1 cards have rounded corners (r = 3.18 mm of 85.6 mm).
    card_mask = Image.new('L', (CARD_W, CARD_H), 0)
    ImageDraw.Draw(card_mask).rounded_rectangle([0, 0, CARD_W - 1, CARD_H - 1], radius=round(CARD_W * 3.18 / 85.6), fill=255)
    mask = card_mask.transform((fw, fh), Image.PERSPECTIVE, coeffs, Image.BICUBIC)
    scene_img.paste(warped, (0, 0), mask)

    arr = np.asarray(scene_img).astype(np.float32)
    if scene == 'camera_dim_blur':
        arr = arr * rng.uniform(0.62, 0.72)
        scene_img = Image.fromarray(arr.clip(0, 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(1.3))
        arr = np.asarray(scene_img).astype(np.float32)
        arr += np.random.default_rng(rng.randint(0, 10**6)).normal(0, 7, arr.shape)
    elif scene == 'camera_glare':
        gx, gy = rng.uniform(0.25, 0.6) * fw, rng.uniform(0.3, 0.6) * fh
        d2 = ((xx - gx) ** 2 + (yy - gy) ** 2) / (2 * (fw * 0.12) ** 2)
        arr += (110 * np.exp(-d2))[..., None]
    else:
        arr += np.random.default_rng(rng.randint(0, 10**6)).normal(0, 3, arr.shape)
    return Image.fromarray(arr.clip(0, 255).astype(np.uint8)), [list(c) for c in corners]


def browser_compress(img):
    """Mirror compressImage(): fit to 1600 px wide, JPEG quality 0.85."""
    w, h = img.size
    if w > 1600:
        img = img.resize((1600, max(1, round(h * 1600 / w))), Image.LANCZOS)
    buf = io.BytesIO()
    img.convert('RGB').save(buf, 'JPEG', quality=85)
    return buf.getvalue()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('out')
    parser.add_argument('--seed', type=int, default=7)
    args = parser.parse_args()
    os.makedirs(args.out, exist_ok=True)
    rng = random.Random(args.seed)
    manifest = []

    for i, identity in enumerate(IDENTITIES):
        nik = nik_for(identity, 1000 + i * 37)
        card = render_card(identity, nik, rng)
        year, month, day = identity['dob']
        truth = {
            'nik': nik,
            'name': identity['name'],
            'birth_place': identity['place'],
            'date_of_birth': f'{year:04d}-{month:02d}-{day:02d}',
            'gender': 'Male' if identity['gender'] == 'LAKI-LAKI' else 'Female',
            'street': identity['address'],
            'rt_rw': identity['rt_rw'],
            'village': identity['village'],
            'district': identity['district'],
            'religion': identity['religion'],
            'marital_status': identity['marital'],
            'occupation': identity['occupation'],
        }
        for scene in SCENES:
            image, corners = place_in_scene(card, scene, rng)
            jpeg = browser_compress(image)
            decoded = Image.open(io.BytesIO(jpeg)).convert('RGBA')
            sid = f'id{i}_{scene}'
            with open(os.path.join(args.out, sid + '.jpg'), 'wb') as fh:
                fh.write(jpeg)
            with open(os.path.join(args.out, sid + '.rgba'), 'wb') as fh:
                fh.write(decoded.tobytes())
            meta = {'id': sid, 'scene': scene, 'width': decoded.width, 'height': decoded.height,
                    'corners': card_corners_after(corners), 'truth': truth}
            with open(os.path.join(args.out, sid + '.json'), 'w') as fh:
                json.dump(meta, fh)
            manifest.append(sid)

    with open(os.path.join(args.out, 'manifest.json'), 'w') as fh:
        json.dump(manifest, fh)
    print(f'{len(manifest)} synthetic scenes written to {args.out}')


if __name__ == '__main__':
    main()
