#!/usr/bin/env python3
"""
Cardinal — teleport portal texture.

A swirling gate disc for the teleport shrine: an inward spiral of energy, a
rune ring and a bright core, on a transparent field so it can be blended
additively inside the gate arch.

Drawn at 2x and downsampled.

    python3 tools/make_portal_texture.py
    python3 tools/make_portal_texture.py --preview
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

S = 1024
OUT = 512
CX = CY = S / 2
R = S * 0.47


def build() -> Image.Image:
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)

    # --- spiral arms drawn as tapering point trails
    arms = 5
    for a in range(arms):
        phase = a / arms * math.tau
        for i in range(260):
            t = i / 259
            ang = phase + t * 3.4
            rad = R * (0.14 + 0.82 * t)
            x = CX + math.cos(ang) * rad
            y = CY + math.sin(ang) * rad
            w = (11 - 8.5 * t) * (S / 1024)
            alpha = int(215 * (1 - t) ** 0.6)
            tone = (
                int(150 + 90 * (1 - t)),
                int(215 + 40 * (1 - t)),
                255,
            )
            d.ellipse([x - w, y - w, x + w, y + w], fill=tone + (alpha,))

    # --- rune ring
    for i in range(28):
        ang = i / 28 * math.tau
        rr = R * 0.86
        x = CX + math.cos(ang) * rr
        y = CY + math.sin(ang) * rr
        bars = 2 + (i * 5) % 3
        for k in range(bars):
            off = (k - bars / 2) * R * 0.035
            nx = x + math.cos(ang + math.pi / 2) * off
            ny = y + math.sin(ang + math.pi / 2) * off
            L = R * (0.045 + 0.02 * (k % 2))
            d.line([nx - math.cos(ang) * L, ny - math.sin(ang) * L,
                    nx + math.cos(ang) * L, ny + math.sin(ang) * L],
                   fill=(226, 244, 255, 190), width=int(4 * S / 1024))

    # --- containment rings
    for rr, w, al in [(R * 0.93, 5, 200), (R * 0.965, 2, 120), (R * 0.24, 4, 210)]:
        pts_o, pts_i = [], []
        for i in range(400):
            ang = i / 399 * math.tau
            ca, sa = math.cos(ang), math.sin(ang)
            pts_o.append((CX + ca * (rr + w / 2), CY + sa * (rr + w / 2)))
            pts_i.append((CX + ca * (rr - w / 2), CY + sa * (rr - w / 2)))
        d.polygon(pts_o + pts_i[::-1], fill=(186, 232, 255, al))

    # --- glowing core
    core = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    cd = ImageDraw.Draw(core)
    cd.ellipse([CX - R * 0.3, CY - R * 0.3, CX + R * 0.3, CY + R * 0.3], fill=(226, 248, 255, 255))
    core = core.filter(ImageFilter.GaussianBlur(S * 0.055))
    img = Image.alpha_composite(img, core)

    # --- glow pass under the linework
    glow = img.filter(ImageFilter.GaussianBlur(S * 0.02))
    glow.putalpha(glow.getchannel("A").point(lambda v: int(v * 0.6)))
    out = Image.alpha_composite(glow, img)

    # --- feather the rim so the disc has no hard cut
    mask = Image.new("L", (S, S), 0)
    md = ImageDraw.Draw(mask)
    md.ellipse([CX - R, CY - R, CX + R, CY + R], fill=255)
    mask = mask.filter(ImageFilter.GaussianBlur(S * 0.018))
    a = out.getchannel("A")
    out.putalpha(Image.composite(a, Image.new("L", (S, S), 0), mask))
    return out.resize((OUT, OUT), Image.LANCZOS)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--preview", action="store_true")
    args = ap.parse_args()

    img = build()
    digest = hashlib.sha256(img.tobytes()).hexdigest()[:8]
    name = f"cardinal-portal-{digest}.png"
    ASSETS.mkdir(exist_ok=True)
    for stale in ASSETS.glob("cardinal-portal-*.png"):
        stale.unlink()
    out = ASSETS / name
    img.save(out, "PNG", optimize=True)
    print(f"{out.relative_to(ROOT)}  {OUT}x{OUT}  {out.stat().st_size // 1024} KB")

    if args.preview:
        pv = ROOT / "tools" / "preview"
        pv.mkdir(parents=True, exist_ok=True)
        flat = Image.new("RGB", img.size, (8, 16, 34))
        flat.paste(img, (0, 0), img)
        flat.save(pv / "portal-texture.png")
        print("  preview -> tools/preview/portal-texture.png")
    print(f"  reference this as: {name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
