#!/usr/bin/env python3
"""
Cardinal — plaza system-circle decal.

The shipped camera sits 23 degrees below horizontal with a 46 degree vertical
FOV, which puts the horizon almost exactly at the top edge of the frame: the
sky is effectively never on screen, and anything built up there is wasted. The
plaza floor, by contrast, fills most of the view.

So this paints a Sword Art Online style system circle straight into the ground
around the fountain: concentric rules, tick marks, a rotated square lattice,
gate markers on the cardinal axes and a ring of rune blocks. Output is RGBA
with a transparent field, meant for an additively blended plane laid flat just
above the cobblestones.

Drawn at 2x and downsampled, and every stroke is built from polygons rather
than PIL's aliased arc/ellipse outlines.

    python3 tools/make_plaza_decal.py
    python3 tools/make_plaza_decal.py --preview
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

S = 2048                      # working resolution (downsampled to OUT)
OUT = 1024
CX = CY = S / 2
CYAN = (150, 226, 255)
GOLD = (250, 214, 140)
PALE = (222, 244, 255)


def ring(d: ImageDraw.ImageDraw, r: float, width: float, colour, alpha: int, segments: int = 512,
         start: float = 0.0, end: float = math.tau):
    """Anti-aliasable ring, drawn as a closed polygon band."""
    outer, inner = [], []
    n = max(8, int(segments * (end - start) / math.tau))
    for i in range(n + 1):
        a = start + (end - start) * i / n
        ca, sa = math.cos(a), math.sin(a)
        outer.append((CX + ca * (r + width / 2), CY + sa * (r + width / 2)))
        inner.append((CX + ca * (r - width / 2), CY + sa * (r - width / 2)))
    d.polygon(outer + inner[::-1], fill=colour + (alpha,))


def spoke(d, a, r0, r1, width, colour, alpha):
    ca, sa = math.cos(a), math.sin(a)
    na, nb = -sa, ca
    d.polygon([
        (CX + ca * r0 + na * width / 2, CY + sa * r0 + nb * width / 2),
        (CX + ca * r1 + na * width / 2, CY + sa * r1 + nb * width / 2),
        (CX + ca * r1 - na * width / 2, CY + sa * r1 - nb * width / 2),
        (CX + ca * r0 - na * width / 2, CY + sa * r0 - nb * width / 2),
    ], fill=colour + (alpha,))


def build() -> Image.Image:
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    R = S * 0.47

    # --- concentric rules
    for r, w, col, al in [
        (R * 1.00, 5, CYAN, 180),
        (R * 0.975, 2, CYAN, 110),
        (R * 0.80, 3, CYAN, 130),
        (R * 0.63, 7, GOLD, 150),
        (R * 0.615, 2, GOLD, 90),
        (R * 0.40, 3, CYAN, 140),
        (R * 0.235, 4, GOLD, 130),
    ]:
        ring(d, r, w, col, al)

    # --- fine tick marks between the two outer rules
    for i in range(180):
        a = i / 180 * math.tau
        long = (i % 15 == 0)
        spoke(d, a, R * 0.80, R * (0.855 if long else 0.825), 4 if long else 2, CYAN, 150 if long else 90)

    # --- four gate markers on the cardinal axes
    for k in range(4):
        a = k * math.tau / 4 + math.tau / 8
        spoke(d, a, R * 0.63, R * 1.0, 9, GOLD, 120)
        cx2, cy2 = CX + math.cos(a) * R * 0.905, CY + math.sin(a) * R * 0.905
        pts = []
        for j in range(6):
            b = j / 6 * math.tau + a
            pts.append((cx2 + math.cos(b) * R * 0.055, cy2 + math.sin(b) * R * 0.055))
        d.polygon(pts, fill=GOLD + (95,))
        d.polygon(pts, outline=PALE + (190,), width=4)

    # --- rotated square lattice inside the gold rule
    for rot in (0.0, math.tau / 8):
        pts = []
        for j in range(4):
            b = j / 4 * math.tau + rot
            pts.append((CX + math.cos(b) * R * 0.60, CY + math.sin(b) * R * 0.60))
        d.polygon(pts, outline=CYAN + (120,), width=5)

    # --- inner hexagram
    for rot in (0.0, math.tau / 6):
        pts = []
        for j in range(3):
            b = j / 3 * math.tau + rot
            pts.append((CX + math.cos(b) * R * 0.375, CY + math.sin(b) * R * 0.375))
        d.polygon(pts, outline=PALE + (110,), width=4)

    # --- rune blocks around the ring: short bar codes, deliberately abstract
    for i in range(24):
        a = i / 24 * math.tau + 0.06
        base = R * 0.665
        bars = 3 + (i * 7) % 3
        for k in range(bars):
            rr = base + k * R * 0.032
            spoke(d, a, rr, rr + R * 0.022, 10 - k * 2, PALE, 150 - k * 25)

    # --- glow pass: blur a copy and lay it underneath so the lines read as light
    glow = img.filter(ImageFilter.GaussianBlur(S * 0.012))
    glow.putalpha(glow.getchannel("A").point(lambda v: int(v * 0.55)))
    out = Image.alpha_composite(glow, img)

    # --- fade the very centre, where the fountain sits
    mask = Image.new("L", (S, S), 255)
    md = ImageDraw.Draw(mask)
    md.ellipse([CX - R * 0.2, CY - R * 0.2, CX + R * 0.2, CY + R * 0.2], fill=0)
    mask = mask.filter(ImageFilter.GaussianBlur(S * 0.02))
    a = out.getchannel("A")
    out.putalpha(Image.composite(a, Image.new("L", (S, S), 0), mask))

    return out.resize((OUT, OUT), Image.LANCZOS)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--preview", action="store_true")
    args = ap.parse_args()

    img = build()
    digest = hashlib.sha256(img.tobytes()).hexdigest()[:8]
    name = f"cardinal-plaza-{digest}.png"
    ASSETS.mkdir(exist_ok=True)
    for stale in ASSETS.glob("cardinal-plaza-*.png"):
        stale.unlink()
    out = ASSETS / name
    img.save(out, "PNG", optimize=True)
    print(f"{out.relative_to(ROOT)}  {OUT}x{OUT}  {out.stat().st_size // 1024} KB")

    if args.preview:
        pv = ROOT / "tools" / "preview"
        pv.mkdir(parents=True, exist_ok=True)
        flat = Image.new("RGB", img.size, (8, 16, 34))
        flat.paste(img, (0, 0), img)
        flat.save(pv / "plaza-decal.png")
        print("  preview -> tools/preview/plaza-decal.png")
    print(f"  reference this as: {name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
