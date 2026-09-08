#!/usr/bin/env python3
"""
Cardinal — ground detail decal.

The plaza floor and the wild ground are the largest surfaces on screen and the
emptiest. This paints one organic patch — damp staining, hairline cracks, moss
and grit — on a transparent field, meant to be scattered across the ground as
flat planes with varied rotation, scale and opacity. Variety comes from the
transforms, so a single texture covers both realms.

Kept deliberately neutral in hue so it reads on blue cobblestone and on dark
earth without tinting either.

    python3 tools/make_ground_decal.py
    python3 tools/make_ground_decal.py --preview
"""

from __future__ import annotations

import argparse
import hashlib
import math
import pathlib
import random
import sys

try:
    from PIL import Image, ImageDraw, ImageFilter
except ImportError:  # pragma: no cover
    sys.exit("Pillow is required:  pip install pillow")

ROOT = pathlib.Path(__file__).resolve().parent.parent
ASSETS = ROOT / "assets"

S = 1024
OUT = 512
CX = CY = S / 2


def blob(d, cx, cy, r, wobble, colour, seed):
    rng = random.Random(seed)
    pts = []
    for i in range(64):
        a = i / 64 * math.tau
        rr = r * (1 + (rng.random() - 0.5) * wobble)
        pts.append((cx + math.cos(a) * rr, cy + math.sin(a) * rr))
    d.polygon(pts, fill=colour)


def build() -> Image.Image:
    rng = random.Random(20260907)
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))

    # --- damp staining: a few overlapping soft blobs
    damp = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    dd = ImageDraw.Draw(damp)
    for i in range(5):
        cx = CX + (rng.random() - 0.5) * S * 0.3
        cy = CY + (rng.random() - 0.5) * S * 0.3
        blob(dd, cx, cy, S * (0.16 + rng.random() * 0.16), 0.45, (10, 16, 26, 120), i)
    damp = damp.filter(ImageFilter.GaussianBlur(S * 0.03))
    img = Image.alpha_composite(img, damp)

    # --- hairline cracks: branching walks outward from the centre
    cracks = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    cd = ImageDraw.Draw(cracks)

    def walk(x, y, ang, length, width, depth):
        steps = int(length / 6)
        for i in range(steps):
            nx = x + math.cos(ang) * 6
            ny = y + math.sin(ang) * 6
            w = max(1.0, width * (1 - i / max(1, steps)))
            cd.line([x, y, nx, ny], fill=(6, 9, 15, 190), width=int(w))
            x, y = nx, ny
            ang += (rng.random() - 0.5) * 0.5
            if depth > 0 and rng.random() < 0.06:
                walk(x, y, ang + (rng.random() - 0.5) * 1.6, length * 0.4, w * 0.7, depth - 1)

    for i in range(7):
        a = i / 7 * math.tau + rng.random()
        walk(CX + math.cos(a) * S * 0.05, CY + math.sin(a) * S * 0.05, a,
             S * (0.18 + rng.random() * 0.2), 7, 2)
    cracks = cracks.filter(ImageFilter.GaussianBlur(0.9))
    img = Image.alpha_composite(img, cracks)

    # --- moss creeping out of the cracks
    moss = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    md = ImageDraw.Draw(moss)
    for _ in range(160):
        a = rng.random() * math.tau
        r = S * (0.06 + rng.random() * 0.34)
        x, y = CX + math.cos(a) * r, CY + math.sin(a) * r
        rad = 3 + rng.random() * 13
        g = 70 + int(rng.random() * 55)
        md.ellipse([x - rad, y - rad, x + rad, y + rad], fill=(38, g, 52, 90))
    moss = moss.filter(ImageFilter.GaussianBlur(S * 0.006))
    img = Image.alpha_composite(img, moss)

    # --- grit: fine speckle so the patch does not read as a smooth sticker
    grit = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    gd = ImageDraw.Draw(grit)
    for _ in range(900):
        a = rng.random() * math.tau
        r = S * rng.random() * 0.44
        x, y = CX + math.cos(a) * r, CY + math.sin(a) * r
        rad = 1 + rng.random() * 3
        v = 150 + int(rng.random() * 80)
        gd.ellipse([x - rad, y - rad, x + rad, y + rad], fill=(v, v, v, 45))
    img = Image.alpha_composite(img, grit)

    # --- feather the rim so scattered copies never show a seam
    mask = Image.new("L", (S, S), 0)
    mk = ImageDraw.Draw(mask)
    mk.ellipse([CX - S * 0.46, CY - S * 0.46, CX + S * 0.46, CY + S * 0.46], fill=255)
    mask = mask.filter(ImageFilter.GaussianBlur(S * 0.05))
    a = img.getchannel("A")
    img.putalpha(Image.composite(a, Image.new("L", (S, S), 0), mask))

    return img.resize((OUT, OUT), Image.LANCZOS)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--preview", action="store_true")
    args = ap.parse_args()

    img = build()
    digest = hashlib.sha256(img.tobytes()).hexdigest()[:8]
    name = f"cardinal-ground-{digest}.png"
    ASSETS.mkdir(exist_ok=True)
    for stale in ASSETS.glob("cardinal-ground-*.png"):
        stale.unlink()
    out = ASSETS / name
    img.save(out, "PNG", optimize=True)
    print(f"{out.relative_to(ROOT)}  {OUT}x{OUT}  {out.stat().st_size // 1024} KB")

    if args.preview:
        pv = ROOT / "tools" / "preview"
        pv.mkdir(parents=True, exist_ok=True)
        flat = Image.new("RGB", img.size, (58, 72, 92))
        flat.paste(img, (0, 0), img)
        flat.save(pv / "ground-decal.png")
        print("  preview -> tools/preview/ground-decal.png")
    print(f"  reference this as: {name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
