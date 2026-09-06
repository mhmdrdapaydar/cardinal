#!/usr/bin/env python3
"""
Cardinal — painted avatar face texture.

Anime games paint the face rather than model it, and that is the only way to
get readable eyes on a head this size. The avatar's head is a plain
SphereGeometry, so its UVs are equirectangular and the mapping is exact:

    three.js SphereGeometry
        x = -r * cos(phiStart + u*2PI) * sin(theta)
        y =  r * cos(theta)
        z =  r * sin(phiStart + u*2PI) * sin(theta)
        uv = (u, 1 - theta/PI)

    u = 0.00 -> -X      u = 0.25 -> +Z
    u = 0.50 -> +X      u = 0.75 -> -Z

The character faces -Z (the cape sits at +Z, the eyes at -0.32Z), so the face
belongs at u = 0.75. Feature rows come straight out of the world-space
positions the build already used for the geometric eyes:

    world y -> theta = acos((y - headY) / r) -> row = (theta / PI) * H

    brows  y 2.088 -> row 203
    eyes   y 1.995 -> row 249
    mouth  y 1.868 -> row 311            (headY 1.98, r 0.34, H 512)

Everything is drawn at 4x and downsampled, so the edges are clean without
relying on PIL's aliased primitives.

    python3 tools/make_avatar_face.py            # writes assets/<name>.png
    python3 tools/make_avatar_face.py --preview  # also writes a flat preview
"""

from __future__ import annotations

import argparse
import hashlib
import math
import pathlib
import sys

try:
    from PIL import Image, ImageDraw, ImageFilter
except ImportError:  # pragma: no cover
    sys.exit("Pillow is required:  pip install pillow")

ROOT = pathlib.Path(__file__).resolve().parent.parent
ASSETS = ROOT / "assets"

W, H = 1024, 512
SS = 4                      # supersample factor
FACE_U = 0.75               # -Z
HEAD_Y, HEAD_R = 1.98, 0.34

SKIN = (247, 218, 200)
SKIN_SHADE = (226, 186, 168)
BLUSH = (240, 168, 158)
LASH = (46, 38, 52)
BROW = (58, 46, 62)
SCLERA = (252, 253, 255)
MOUTH = (176, 96, 92)


def row_for(world_y: float) -> float:
    """Texture row for a world-space height on the head sphere."""
    c = max(-1.0, min(1.0, (world_y - HEAD_Y) / HEAD_R))
    return (math.acos(c) / math.pi) * H


def col_for(x_offset: float, row: float) -> float:
    """Texture column for a horizontal offset, corrected for the sphere's taper."""
    theta = (row / H) * math.pi
    ring = max(1e-4, math.sin(theta))
    ratio = max(-1.0, min(1.0, x_offset / (HEAD_R * ring)))
    return (FACE_U + math.asin(ratio) / (2 * math.pi)) * W


def ellipse(d: ImageDraw.ImageDraw, cx, cy, rx, ry, fill, rot=0.0):
    """Rotated filled ellipse, drawn as a polygon so it can be sheared."""
    pts = []
    for i in range(48):
        a = i / 48 * math.tau
        x, y = rx * math.cos(a), ry * math.sin(a)
        pts.append((cx + x * math.cos(rot) - y * math.sin(rot),
                    cy + x * math.sin(rot) + y * math.cos(rot)))
    d.polygon(pts, fill=fill)


def build() -> Image.Image:
    img = Image.new("RGB", (W * SS, H * SS), SKIN)
    d = ImageDraw.Draw(img)
    s = SS

    brow_row = row_for(2.088) * s
    eye_row = row_for(1.995) * s
    mouth_row = row_for(1.868) * s
    nose_row = row_for(1.925) * s
    face_cx = FACE_U * W * s

    # --- soft forehead / jaw shading so the head is not a flat disc
    shade = Image.new("RGB", img.size, SKIN)
    sd = ImageDraw.Draw(shade)
    ellipse(sd, face_cx, eye_row + 120 * s, 190 * s, 150 * s, SKIN_SHADE)
    shade = shade.filter(ImageFilter.GaussianBlur(70 * s / 4))
    img = Image.blend(img, shade, 0.5)
    d = ImageDraw.Draw(img)

    # --- blush
    blush = Image.new("RGB", img.size, (0, 0, 0))
    bd = ImageDraw.Draw(blush)
    for side in (-1, 1):
        bx = col_for(side * 0.175, eye_row / s) * s
        ellipse(bd, bx, eye_row + 34 * s, 34 * s, 20 * s, BLUSH)
    blush = blush.filter(ImageFilter.GaussianBlur(22 * s / 2))
    img = Image.composite(Image.blend(img, Image.new("RGB", img.size, BLUSH), 0.24), img,
                          blush.convert("L").point(lambda v: min(255, int(v * 1.9))))
    d = ImageDraw.Draw(img)

    for side in (-1, 1):
        ex = col_for(side * 0.115, eye_row / s) * s
        tilt = side * 0.1

        # ---- eye: sclera, iris, pupil, catch light, heavy upper lash
        ellipse(d, ex, eye_row, 30 * s, 34 * s, SCLERA, tilt)
        # iris: a vertical gradient from the class colour into a darker rim
        for k in range(18, 0, -1):
            t = k / 18
            col = (
                int(96 + 120 * (1 - t)),
                int(170 + 70 * (1 - t)),
                int(226 + 29 * (1 - t)),
            )
            ellipse(d, ex, eye_row + 2 * s, 20 * s * t, 27 * s * t, col, tilt)
        ellipse(d, ex, eye_row + 5 * s, 9 * s, 13 * s, (28, 34, 62), tilt)
        # catch light, high and off-centre like a painted highlight
        ellipse(d, ex - side * 7 * s, eye_row - 13 * s, 7 * s, 8 * s, (255, 255, 255), tilt)
        ellipse(d, ex + side * 8 * s, eye_row + 12 * s, 4 * s, 4 * s, (210, 240, 255), tilt)
        # upper lash line, thick at the outer corner
        for i in range(26):
            t = i / 25
            lx = ex + (t - 0.5) * 62 * s * (1 if side > 0 else -1)
            thick = (5.0 + 7.0 * t) * s
            ly = eye_row - 30 * s + math.sin(t * math.pi) * -6 * s + t * 5 * s
            d.ellipse([lx - thick, ly - thick * 0.8, lx + thick, ly + thick * 0.8], fill=LASH)
        # outer lash flick
        fx = ex + side * 34 * s
        d.polygon([(fx, eye_row - 26 * s), (fx + side * 20 * s, eye_row - 40 * s),
                   (fx + side * 3 * s, eye_row - 20 * s)], fill=LASH)
        # lower lid: one continuous stroke, or it beads into a dotted line
        lid = []
        for i in range(25):
            t = i / 24
            lid.append((ex + (t - 0.5) * 50 * s * (1 if side > 0 else -1),
                        eye_row + 31 * s - math.sin(t * math.pi) * 3 * s))
        d.line(lid, fill=(126, 100, 114), width=int(3.4 * s), joint="curve")

        # ---- brow: tapered stroke, arched
        bx = col_for(side * 0.125, brow_row / s) * s
        for i in range(20):
            t = i / 19
            px = bx + (t - 0.5) * 66 * s * (1 if side > 0 else -1)
            py = brow_row - math.sin(t * math.pi) * 7 * s + t * 4 * s
            thick = (5.6 - 3.4 * t) * s
            d.ellipse([px - thick, py - thick * 0.55, px + thick, py + thick * 0.55], fill=BROW)

    # ---- nose: a soft shadow wedge, no outline
    nose = Image.new("RGB", img.size, (0, 0, 0))
    nd = ImageDraw.Draw(nose)
    ellipse(nd, face_cx + 4 * s, nose_row, 6 * s, 9 * s, (255, 255, 255))
    nose = nose.filter(ImageFilter.GaussianBlur(5 * s))
    img = Image.composite(Image.blend(img, Image.new("RGB", img.size, SKIN_SHADE), 0.75), img,
                          nose.convert("L"))
    d = ImageDraw.Draw(img)

    # ---- mouth: small, slightly smiling
    pts = []
    for i in range(21):
        t = i / 20
        pts.append((face_cx + (t - 0.5) * 34 * s, mouth_row + math.sin(t * math.pi) * 5 * s))
    for i in range(20):
        x0, y0 = pts[i]
        x1, y1 = pts[i + 1]
        d.line([x0, y0, x1, y1], fill=MOUTH, width=int(3.2 * s))
    # lower lip highlight
    ellipse(d, face_cx, mouth_row + 8 * s, 11 * s, 3.4 * s, (245, 196, 188))

    return img.resize((W, H), Image.LANCZOS)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--preview", action="store_true", help="also write tools/preview/face-texture.png")
    args = ap.parse_args()

    img = build()
    digest = hashlib.sha256(img.tobytes()).hexdigest()[:8]
    name = f"cardinal-face-{digest}.png"

    ASSETS.mkdir(exist_ok=True)
    for stale in ASSETS.glob("cardinal-face-*.png"):
        stale.unlink()
    out = ASSETS / name
    img.save(out, "PNG", optimize=True)
    print(f"{out.relative_to(ROOT)}  {W}x{H}  {out.stat().st_size // 1024} KB")

    if args.preview:
        pv = ROOT / "tools" / "preview"
        pv.mkdir(parents=True, exist_ok=True)
        crop = img.crop((int(W * 0.62), int(H * 0.22), int(W * 0.88), int(H * 0.78)))
        crop.resize((crop.width * 3, crop.height * 3), Image.LANCZOS).save(pv / "face-texture.png")
        print(f"  preview -> tools/preview/face-texture.png")

    print(f"  reference this as: {name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
