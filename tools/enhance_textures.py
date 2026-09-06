#!/usr/bin/env python3
"""
Cardinal — world texture enhancer.

Rebuilds the rendered-world material assets in ./assets. Presentation layer
only: these are image files consumed by the Three.js materials. No game rule,
API route, database column or progression value is touched, so every other
Cardinal server stays in sync.

Two tiers are produced from each 512x512 original:

  <name>.jpg      512x512  -- mobile / low tier. Same resolution as before, so
                             download size barely moves, but with re-derived
                             micro-relief and stronger normals.
  <name>-hi.jpg  1024x1024 -- desktop / high tier, selected at runtime by the
                             patched texture URL helper in the world bundle.

Every filter is periodic (FFT blur, np.roll Sobel), so a texture that tiled
seamlessly before still tiles seamlessly after.

Usage:
    /tmp/venv/bin/python tools/enhance_textures.py --check
    /tmp/venv/bin/python tools/enhance_textures.py --apply
"""

from __future__ import annotations

import argparse
import pathlib
import shutil
import sys

import numpy as np
from PIL import Image

ROOT = pathlib.Path(__file__).resolve().parent.parent
ASSETS = ROOT / "assets"
BACKUP = ROOT / "tools" / "texture-originals"

# Per-material tuning: how much fractal grain, unsharp strength, how hard the
# derived normal pushes, and a touch of saturation.
MATERIALS = {
    "cobblestone-plaza-Bv05-kDx.jpg": {
        "normal": "cobblestone-plaza-normal-CqS9aCCp.jpg",
        "detail": 0.055, "sharpen": 1.35, "relief": 2.6, "sat": 1.06,
    },
    "citadel-stone-DhobfaPS.jpg": {
        "normal": "citadel-stone-normal-BPftVKXK.jpg",
        "detail": 0.050, "sharpen": 1.30, "relief": 2.4, "sat": 1.05,
    },
    "roof-shingles-DCCuhMK_.jpg": {
        "normal": "roof-shingles-normal-CG2vCK4x.jpg",
        "detail": 0.045, "sharpen": 1.25, "relief": 2.8, "sat": 1.10,
    },
    "wood-timber-CK8hbZ_e.jpg": {
        "normal": "wood-timber-normal-Dov88xT1.jpg",
        "detail": 0.040, "sharpen": 1.30, "relief": 2.2, "sat": 1.04,
    },
    "wild-terrain-QQsUXa7b.jpg": {
        "normal": "wild-terrain-normal-4tkyEOlB.jpg",
        "detail": 0.070, "sharpen": 1.20, "relief": 2.0, "sat": 1.08,
    },
}

TIERS = [
    # (size, filename suffix, jpeg quality)
    (512, "", 90),
    (1024, "-hi", 92),
]


# ---------------------------------------------------------------- tileable ops
def periodic_blur(a: np.ndarray, sigma: float) -> np.ndarray:
    """Gaussian blur with wrap-around edges, done in the frequency domain."""
    if sigma <= 0:
        return a
    h, w = a.shape
    fy = np.fft.fftfreq(h)[:, None]
    fx = np.fft.fftfreq(w)[None, :]
    kernel = np.exp(-2.0 * (np.pi ** 2) * (sigma ** 2) * (fy ** 2 + fx ** 2))
    return np.real(np.fft.ifft2(np.fft.fft2(a) * kernel))


def tileable_fbm(size: int, seed: int, octaves: int = 6) -> np.ndarray:
    """Fractal noise that wraps on both axes, roughly in [-1, 1]."""
    rng = np.random.default_rng(seed)
    out = np.zeros((size, size), dtype=np.float64)
    amplitude = 1.0
    total = 0.0
    for octave in range(octaves):
        sigma = size / (8.0 * (2 ** octave))
        layer = periodic_blur(rng.standard_normal((size, size)), sigma)
        spread = layer.std()
        if spread > 1e-9:
            layer /= spread
        out += amplitude * layer
        total += amplitude
        amplitude *= 0.55
    out /= max(total, 1e-9)
    return out / max(np.abs(out).max(), 1e-9)


def periodic_unsharp(a: np.ndarray, amount: float, sigma: float = 1.5) -> np.ndarray:
    return a + amount * (a - periodic_blur(a, sigma))


def periodic_sobel(a: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Wrap-around Sobel gradients; np.roll keeps the tiling intact."""
    gx = (
        -1 * np.roll(np.roll(a, 1, 0), 1, 1) + 1 * np.roll(np.roll(a, 1, 0), -1, 1)
        - 2 * np.roll(a, 1, 1) + 2 * np.roll(a, -1, 1)
        - 1 * np.roll(np.roll(a, -1, 0), 1, 1) + 1 * np.roll(np.roll(a, -1, 0), -1, 1)
    ) / 8.0
    gy = (
        -1 * np.roll(np.roll(a, 1, 0), 1, 1) - 2 * np.roll(a, 1, 0)
        - 1 * np.roll(np.roll(a, 1, 0), -1, 1)
        + 1 * np.roll(np.roll(a, -1, 0), 1, 1) + 2 * np.roll(a, -1, 0)
        + 1 * np.roll(np.roll(a, -1, 0), -1, 1)
    ) / 8.0
    return gx, gy


LUMA = np.array([0.2126, 0.7152, 0.0722])


def load(path: pathlib.Path, size: int) -> np.ndarray:
    img = Image.open(path).convert("RGB")
    if img.size != (size, size):
        img = img.resize((size, size), Image.LANCZOS)
    return np.asarray(img, dtype=np.float64) / 255.0


def save(arr: np.ndarray, path: pathlib.Path, quality: int) -> None:
    data = np.clip(arr * 255.0, 0, 255).astype(np.uint8)
    Image.fromarray(data, "RGB").save(
        path, "JPEG", quality=quality, optimize=True, subsampling=0
    )


def wrap_seam(a: np.ndarray) -> float:
    """Mean absolute colour difference across the tiling seam."""
    return float(
        (np.abs(a[0, :] - a[-1, :]).mean() + np.abs(a[:, 0] - a[:, -1]).mean()) / 2.0
    )


# ------------------------------------------------------------------- pipeline
def enhance_albedo(src: pathlib.Path, cfg: dict, size: int, seed: int) -> np.ndarray:
    rgb = load(src, size)

    detail = tileable_fbm(size, seed)
    luma = rgb @ LUMA
    # Modulate grain by local luminance: crevices stay dark, lit faces catch
    # texture. Prevents the flat "noise layer" look.
    mask = 0.35 + 0.65 * np.clip(luma * 1.4, 0.0, 1.0)
    rgb = rgb + (detail * mask * cfg["detail"])[:, :, None]

    for channel in range(3):
        rgb[:, :, channel] = periodic_unsharp(rgb[:, :, channel], cfg["sharpen"] - 1.0)

    grey = (rgb @ LUMA)[:, :, None]
    rgb = grey + (rgb - grey) * cfg["sat"]
    rgb = np.clip(rgb, 0.0, 1.0) ** 0.985
    return np.clip(rgb, 0.0, 1.0)


def enhance_normal(src: pathlib.Path, albedo: np.ndarray, cfg: dict, size: int) -> np.ndarray:
    base = load(src, size) * 2.0 - 1.0

    height = periodic_blur(albedo @ LUMA, 0.7)
    gx, gy = periodic_sobel(height)
    derived = np.stack([-gx * cfg["relief"], -gy * cfg["relief"], np.ones_like(gx)], axis=-1)
    derived /= np.linalg.norm(derived, axis=-1, keepdims=True)

    # Keep the artist's macro relief, layer the derived micro relief on top.
    blended = base * np.array([0.70, 0.70, 1.0]) + derived * np.array([0.55, 0.55, 0.0])
    blended[:, :, 2] = np.maximum(blended[:, :, 2], 0.25)
    blended /= np.linalg.norm(blended, axis=-1, keepdims=True)
    return np.clip(blended * 0.5 + 0.5, 0.0, 1.0)


def sources() -> dict[str, pathlib.Path]:
    """Always enhance from the pristine 512 originals, never from output."""
    BACKUP.mkdir(parents=True, exist_ok=True)
    resolved = {}
    for albedo, cfg in MATERIALS.items():
        for name in (albedo, cfg["normal"]):
            kept = BACKUP / name
            if not kept.exists():
                shutil.copy2(ASSETS / name, kept)
            resolved[name] = kept
    return resolved


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="write into assets/")
    parser.add_argument("--check", action="store_true", help="report only, write nothing")
    parser.add_argument("--outdir", default=None)
    args = parser.parse_args()

    if not (args.apply or args.check or args.outdir):
        parser.error("pass --apply, --check or --outdir DIR")

    src = sources()
    outdir = pathlib.Path(args.outdir) if args.outdir else ASSETS
    if not args.check:
        outdir.mkdir(parents=True, exist_ok=True)

    total = 0
    print(f"{'file':44s} {'px':>5s} {'seam':>7s} {'was':>7s} {'KB':>6s}")
    for index, (albedo_name, cfg) in enumerate(MATERIALS.items()):
        normal_name = cfg["normal"]
        for size, suffix, quality in TIERS:
            albedo = enhance_albedo(src[albedo_name], cfg, size, index * 7919 + 17)
            normal = enhance_normal(src[normal_name], albedo, cfg, size)

            for name, data in ((albedo_name, albedo), (normal_name, normal)):
                stem, dot, ext = name.rpartition(".")
                target = outdir / f"{stem}{suffix}{dot}{ext}"
                before = wrap_seam(load(src[name], size))
                after = wrap_seam(data)
                if args.check:
                    print(f"{target.name:44s} {size:5d} {after:7.4f} {before:7.4f} {'--':>6s}")
                    continue
                save(data, target, quality)
                kb = target.stat().st_size // 1024
                total += kb
                print(f"{target.name:44s} {size:5d} {after:7.4f} {before:7.4f} {kb:6d}")

    if not args.check:
        print(f"\ntotal texture payload: {total} KB   (originals kept in {BACKUP.relative_to(ROOT)}/)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
