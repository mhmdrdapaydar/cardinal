#!/usr/bin/env python3
"""
Cardinal — graphics release builder.

Applies the presentation-layer upgrades to the shipped Vite build and produces
a cache-safe release:

  1. restores the pristine bundles (so the script is idempotent);
  2. patches renderer + scene *rendering* parameters in the world chunk
     (shadow map, shadow frustum, fog distances, DPR ceiling, antialias,
     tone-mapping exposure, decoration density);
  3. installs the adaptive 512/1024 texture selector;
  4. appends tools/cardinal-fx.css to the built stylesheet;
  5. rewrites index.html's startup screen;
  6. re-hashes every changed asset filename and rewires index.html plus the
     dynamic-import references, then refreshes the stable
     `cardinal-current-*` aliases the .htaccess recovery rules depend on.

NOT TOUCHED, BY DESIGN: api.php, lib/*.php, config.php, and every game rule,
formula, reward, cooldown, cost or progression value inside the bundles. This
release changes how the game looks, never what it computes -- the other
Cardinal servers stay in sync.

Every patch asserts its anchor matches exactly once; a mismatch aborts before
anything is written.

    python3 tools/build_graphics_release.py --check
    python3 tools/build_graphics_release.py --apply
"""

from __future__ import annotations

import argparse
import hashlib
import pathlib
import re
import shutil
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
ASSETS = ROOT / "assets"
PRISTINE = ROOT / "tools" / "bundle-originals"
FX_CSS = ROOT / "tools" / "cardinal-fx.css"

# Canonical build outputs (the `cardinal-current-*` files are byte copies).
WORLD = "WorldScene-Bkz3wf-d.js"
WORLD_LEGACY = "WorldScene-legacy-E5wFECM1.js"
MAIN = "index-ItmggkR1.js"
MAIN_LEGACY = "index-legacy-DfcBKWBd.js"
POLYFILLS = "polyfills-legacy-CkLuAc6e.js"
STYLES = "index-Dnm512So.css"

MANAGED = [WORLD, WORLD_LEGACY, MAIN, MAIN_LEGACY, POLYFILLS, STYLES]

# stable alias -> canonical file (kept in sync for the .htaccess bridge)
ALIASES = {
    "cardinal-current-world.js": WORLD,
    "cardinal-current-world-legacy.js": WORLD_LEGACY,
    "cardinal-current-main.js": MAIN,
    "cardinal-current-legacy.js": MAIN_LEGACY,
    "cardinal-current-polyfills.js": POLYFILLS,
    "cardinal-current.css": STYLES,
}

RELEASE = "20260906-world-graphics-2"

# --------------------------------------------------------------------------
# Adaptive texture tier.
#
# Desktop-class devices load the 1024px `-hi` variants; phones, coarse-pointer
# devices, low-memory devices and anyone asking for reduced data keep the
# original 512px files. Written in ES5 so the same source works in the legacy
# (nomodule) bundle.
# --------------------------------------------------------------------------
TEXTURE_SELECTOR = (
    'function cardinalWorldHiTexture(){'
    'if(typeof window==="undefined"||typeof navigator==="undefined")return false;'
    'if(window.__cardinalHiTexture!==undefined)return window.__cardinalHiTexture;'
    'var ok=false;'
    'try{'
    'var ua=String(navigator.userAgent||"");'
    'var mem=typeof navigator.deviceMemory==="number"?navigator.deviceMemory:8;'
    'var cpu=typeof navigator.hardwareConcurrency==="number"?navigator.hardwareConcurrency:4;'
    'var mq=typeof window.matchMedia==="function"?window.matchMedia:null;'
    'var coarse=mq?window.matchMedia("(pointer: coarse)").matches:false;'
    'var narrow=mq?window.matchMedia("(max-width: 900px)").matches:false;'
    'var saveData=mq?window.matchMedia("(prefers-reduced-data: reduce)").matches:false;'
    'var conn=navigator.connection||{};'
    'if(conn.saveData===true)saveData=true;'
    'if(["slow-2g","2g","3g"].indexOf(conn.effectiveType)!==-1)saveData=true;'
    'var phone=/Android|webOS|iPhone|iPad|iPod|Opera Mini|IEMobile|Mobile/i.test(ua);'
    'ok=!phone&&!coarse&&!narrow&&!saveData&&mem>=4&&cpu>=4;'
    '}catch(err){ok=false;}'
    'window.__cardinalHiTexture=ok;'
    'return ok;}'
)

# --------------------------------------------------------------------------
# Patches: (file, description, anchor, replacement)
# --------------------------------------------------------------------------

def world_patches(*, marker: str, helper: str, quality: str) -> list[tuple[str, str, str]]:
    """Patches shared by both world chunks, parameterised by minified names."""
    return []


PATCHES: dict[str, list[tuple[str, str, str]]] = {
    WORLD: [
        (
            "texture tier selector + cache marker",
            'IC="20260905-world-recovery-1";function c_(r){return"".concat(r).concat(r.includes("?")?"&":"?","cardinal-world=").concat(IC)}',
            f'IC="{RELEASE}";{TEXTURE_SELECTOR}'
            'function c_(r){var u=cardinalWorldHiTexture()?r.replace(/\\.jpg$/i,"-hi.jpg"):r;'
            'return"".concat(u).concat(u.includes("?")?"&":"?","cardinal-world=").concat(IC)}',
        ),
        (
            "shadow map resolution + real shadow frustum",
            '"shadow-mapSize":e==="high"?[768,768]:[512,512],"shadow-camera-far":100,"shadow-bias":-25e-5',
            '"shadow-mapSize":e==="high"?[2048,2048]:[1024,1024],"shadow-camera-near":2,'
            '"shadow-camera-far":120,"shadow-camera-left":-26,"shadow-camera-right":26,'
            '"shadow-camera-top":26,"shadow-camera-bottom":-26,"shadow-bias":-2e-4,'
            '"shadow-normalBias":.05',
        ),
        (
            "canvas: DPR ceiling + antialias on balanced",
            'shadows:R==="high",dpr:R==="high"?[1,1.55]:R==="balanced"?[1,1.3]:[1,1]',
            'shadows:R==="high",dpr:R==="high"?[1,2]:R==="balanced"?[1,1.5]:[1,1]',
        ),
        (
            "gl: antialias on balanced",
            'gl:{antialias:R==="high",powerPreference:R==="low"?"low-power":"high-performance",alpha:!1}',
            'gl:{antialias:R!=="low",powerPreference:R==="low"?"low-power":"high-performance",alpha:!1}',
        ),
        (
            "tone-mapping exposure",
            'K.toneMappingExposure=R==="high"?1.27:R==="balanced"?1.19:1.11',
            'K.toneMappingExposure=R==="high"?1.34:R==="balanced"?1.24:1.13',
        ),
        (
            "adaptive DPR controller ceiling",
            'h=H.useRef(r==="high"?1.42:r==="balanced"?1.14:1),d=r==="high"?[1,1.55]:r==="balanced"?[.85,1.3]:[.75,1]',
            'h=H.useRef(r==="high"?1.7:r==="balanced"?1.28:1),d=r==="high"?[1,2]:r==="balanced"?[.85,1.5]:[.75,1]',
        ),
        (
            "adaptive DPR controller reset ceiling",
            'h.current=r==="high"?1.42:r==="balanced"?1.14:1',
            'h.current=r==="high"?1.7:r==="balanced"?1.28:1',
        ),
        (
            "city: aerial perspective + decoration ring density",
            'const i=h_(r,"city"),s=e==="high"||e==="balanced"?12:8;',
            'const i=h_(r,"city"),s=e==="high"?18:e==="balanced"?12:8;',
        ),
        (
            "city: fog distances",
            'f.jsx("fog",{attach:"fog",args:[i.fog,34,124]})',
            'f.jsx("fog",{attach:"fog",args:[i.fog,29,116]})',
        ),
        (
            "wild: scatter density",
            'const i=h_(r,"wild"),s=e==="high"?78:e==="balanced"?46:20;',
            'const i=h_(r,"wild"),s=e==="high"?104:e==="balanced"?54:20;',
        ),
        (
            "wild: fog distances",
            'f.jsx("fog",{attach:"fog",args:[i.fog,18,115]})',
            'f.jsx("fog",{attach:"fog",args:[i.fog,16,104]})',
        ),
        (
            "plaza aether motes",
            'f.jsx(di,{count:26,scale:[20,7,20],position:[0,2,0],size:2,speed:.22,color:"#baffec"})',
            'f.jsx(di,{count:44,scale:[22,8,22],position:[0,2,0],size:2.4,speed:.22,color:"#baffec"})',
        ),
    ],
    WORLD_LEGACY: [
        (
            "texture tier selector + cache marker",
            'pv="20260905-world-recovery-1";function mv(e){return"".concat(e).concat(e.includes("?")?"&":"?","cardinal-world=").concat(pv)}',
            f'pv="{RELEASE}";{TEXTURE_SELECTOR}'
            'function mv(e){var u=cardinalWorldHiTexture()?e.replace(/\\.jpg$/i,"-hi.jpg"):e;'
            'return"".concat(u).concat(u.includes("?")?"&":"?","cardinal-world=").concat(pv)}',
        ),
        (
            "shadow map resolution + real shadow frustum",
            '"shadow-mapSize":"high"===n?[768,768]:[512,512],"shadow-camera-far":100,"shadow-bias":-25e-5',
            '"shadow-mapSize":"high"===n?[2048,2048]:[1024,1024],"shadow-camera-near":2,'
            '"shadow-camera-far":120,"shadow-camera-left":-26,"shadow-camera-right":26,'
            '"shadow-camera-top":26,"shadow-camera-bottom":-26,"shadow-bias":-2e-4,'
            '"shadow-normalBias":.05',
        ),
        (
            "canvas: DPR ceiling",
            'shadows:"high"===F,dpr:"high"===F?[1,1.55]:"balanced"===F?[1,1.3]:[1,1]',
            'shadows:"high"===F,dpr:"high"===F?[1,2]:"balanced"===F?[1,1.5]:[1,1]',
        ),
        (
            "gl: antialias on balanced",
            'antialias:"high"===F',
            'antialias:"low"!==F',
        ),
        (
            "tone-mapping exposure",
            't.toneMappingExposure="high"===F?1.27:"balanced"===F?1.19:1.11',
            't.toneMappingExposure="high"===F?1.34:"balanced"===F?1.24:1.13',
        ),
        (
            "adaptive DPR controller ceiling",
            'c=_.useRef("high"===t?1.42:"balanced"===t?1.14:1),h="high"===t?[1,1.55]:"balanced"===t?[.85,1.3]:[.75,1]',
            'c=_.useRef("high"===t?1.7:"balanced"===t?1.28:1),h="high"===t?[1,2]:"balanced"===t?[.85,1.5]:[.75,1]',
        ),
        (
            "adaptive DPR controller reset ceiling",
            'c.current="high"===t?1.42:"balanced"===t?1.14:1',
            'c.current="high"===t?1.7:"balanced"===t?1.28:1',
        ),
        (
            "plaza aether motes",
            'b.jsx(rv,{count:26,scale:[20,7,20],position:[0,2,0],size:2,speed:.22,color:"#baffec"})',
            'b.jsx(rv,{count:44,scale:[22,8,22],position:[0,2,0],size:2.4,speed:.22,color:"#baffec"})',
        ),
    ],
}

# Legacy chunk: fog / density anchors use different minified identifiers, so
# they are matched with regexes instead of literals.
LEGACY_REGEX_PATCHES = [
    (
        "city: aerial perspective + decoration ring density",
        re.compile(r'(=\w+\(\w+,"city"\),\w+=)"high"===\w+\|\|"balanced"===(\w+)\?12:8'),
        lambda m: f'{m.group(1)}"high"==={m.group(2)}?18:"balanced"==={m.group(2)}?12:8',
    ),
    (
        "city: fog distances",
        re.compile(r'(\{attach:"fog",args:\[\w+\.fog,)34,124(\]\})'),
        lambda m: f"{m.group(1)}29,116{m.group(2)}",
    ),
    (
        "wild: scatter density",
        re.compile(r'(=\w+\(\w+,"wild"\),\w+=)"high"===(\w+)\?78:"balanced"===\w+\?46:20'),
        lambda m: f'{m.group(1)}"high"==={m.group(2)}?104:"balanced"==={m.group(2)}?54:20',
    ),
    (
        "wild: fog distances",
        re.compile(r'(\{attach:"fog",args:\[\w+\.fog,)18,115(\]\})'),
        lambda m: f"{m.group(1)}16,104{m.group(2)}",
    ),
]

STARTUP_HTML = """      <main class="startup" aria-live="polite">
        <section class="startup__card">
          <div class="startup__aura" aria-hidden="true"></div>
          <div class="startup__mark">C</div>
          <h1>کاردینال · سرور ۵</h1>
          <p>در حال آماده‌سازی دروازه‌های جهان کاردینال…</p>
          <div class="startup__bar" aria-hidden="true"><i></i></div>
          <small id="startup-status">در حال بارگذاری رابط بازی…</small>
          <a class="startup__retry" href="./?reload=1">بارگذاری دوباره</a>
        </section>
      </main>"""

STARTUP_CSS = """      html, body, #root { width: 100%; min-height: 100%; margin: 0; }
      body { background: #060d1b; color: #eaf3ff; font-family: Tahoma, "Segoe UI", Arial, sans-serif; }
      .startup { position: relative; min-height: 100vh; display: grid; place-items: center; padding: 24px; box-sizing: border-box; overflow: hidden; background: radial-gradient(ellipse at 50% 30%, #22437c 0, #101f39 38%, #060d1b 78%); text-align: center; }
      .startup:before { content: ""; position: absolute; inset: -20%; background: radial-gradient(1px 1px at 12% 18%, #fff 99%, transparent), radial-gradient(1.5px 1.5px at 34% 32%, #d3deff 99%, transparent), radial-gradient(1px 1px at 58% 14%, #fff 99%, transparent), radial-gradient(2px 2px at 77% 24%, #dbe7ff 99%, transparent), radial-gradient(1px 1px at 88% 58%, #fff 99%, transparent), radial-gradient(1px 1px at 22% 66%, #fff 99%, transparent); opacity: .55; animation: startup-drift 22s ease-in-out infinite alternate; }
      .startup__card { position: relative; width: min(390px, 100%); padding: 32px 24px 26px; border: 1px solid rgba(150, 205, 255, .22); border-radius: 22px; background: linear-gradient(148deg, rgba(21, 39, 70, .88), rgba(9, 19, 37, .92)); box-shadow: 0 24px 60px rgba(0, 0, 0, .45), inset 0 1px rgba(255, 255, 255, .07); overflow: hidden; -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); }
      .startup__aura { position: absolute; inset: -60% -30% auto; height: 180px; background: radial-gradient(ellipse at 50% 0, rgba(111, 214, 255, .3), transparent 62%); pointer-events: none; }
      .startup__mark { position: relative; display: grid; width: 52px; height: 52px; place-items: center; margin: 0 auto 16px; border: 1px solid #f6d171; border-radius: 14px 14px 21px 14px; background: linear-gradient(135deg, #ffe6a4, #f6c568 42%, #a66b2c); color: #162035; font: bold 33px Georgia, serif; transform: rotate(-7deg); box-shadow: 0 0 26px rgba(246, 197, 104, .3), inset 0 1px rgba(255, 255, 255, .5); }
      .startup h1 { position: relative; margin: 0 0 8px; font-size: 23px; }
      .startup p { position: relative; margin: 0; color: #bdcde5; font-size: 13px; line-height: 1.9; }
      .startup small { position: relative; display: block; margin-top: 14px; color: #8394af; font-size: 11px; }
      .startup__retry { position: relative; display: inline-block; margin-top: 14px; padding: 8px 12px; border: 1px solid rgba(246, 209, 113, .42); border-radius: 9px; color: #ffe1a0; font: 700 11px Tahoma, "Segoe UI", Arial, sans-serif; text-decoration: none; }
      .startup__retry:hover { background: rgba(246, 197, 104, .13); }
      .startup__bar { position: relative; width: 178px; height: 3px; margin: 18px auto 0; border-radius: 99px; overflow: hidden; background: rgba(150, 205, 255, .14); }
      .startup__bar i { position: absolute; top: 0; bottom: 0; width: 42%; border-radius: inherit; background: linear-gradient(90deg, transparent, #6fd6ff, #f6c568, transparent); animation: startup-scan 1.5s ease-in-out infinite; }
      .startup__spin { width: 20px; height: 20px; margin: 16px auto 0; border: 2px solid rgba(255,255,255,.23); border-top-color: #f6d171; border-radius: 50%; animation: spin .8s linear infinite; }
      @keyframes spin { to { transform: rotate(360deg); } }
      @keyframes startup-scan { 0% { left: -45%; } 100% { left: 103%; } }
      @keyframes startup-drift { to { transform: translate3d(1.5%, -1.5%, 0) scale(1.05); } }
      @media (prefers-reduced-motion: reduce) { .startup:before, .startup__bar i { animation: none; } .startup__bar i { left: 0; width: 100%; } }"""


# --------------------------------------------------------------------------
def snapshot_pristine() -> None:
    PRISTINE.mkdir(parents=True, exist_ok=True)
    for name in MANAGED:
        keep = PRISTINE / name
        if keep.exists():
            continue
        source = ASSETS / name
        if not source.exists():
            sys.exit(f"ABORT: cannot snapshot missing {name}; run this on a clean checkout")
        shutil.copy2(source, keep)
    html = PRISTINE / "index.html"
    if not html.exists():
        shutil.copy2(ROOT / "index.html", html)


def apply_literal(text: str, name: str, patches, report) -> str:
    for label, anchor, replacement in patches:
        hits = text.count(anchor)
        if hits != 1:
            report.append(("FAIL", name, label, f"{hits} matches, expected 1"))
            continue
        text = text.replace(anchor, replacement, 1)
        report.append(("ok", name, label, ""))
    return text


def apply_regex(text: str, name: str, patches, report) -> str:
    for label, pattern, builder in patches:
        found = pattern.findall(text)
        matches = list(pattern.finditer(text))
        if len(matches) != 1:
            report.append(("FAIL", name, label, f"{len(matches)} matches, expected 1"))
            continue
        text = pattern.sub(lambda m: builder(m), text, count=1)
        report.append(("ok", name, label, ""))
    return text


def short_hash(data: bytes) -> str:
    digest = hashlib.sha256(data).digest()
    alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-"
    value = int.from_bytes(digest[:9], "big")
    out = []
    for _ in range(8):
        out.append(alphabet[value & 63])
        value >>= 6
    return "".join(out)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    if not (args.apply or args.check):
        parser.error("pass --apply or --check")

    if not FX_CSS.exists():
        sys.exit(f"ABORT: missing {FX_CSS.relative_to(ROOT)}")

    snapshot_pristine()
    report: list[tuple[str, str, str, str]] = []

    # ---- 1. patch content, always starting from the pristine bundles
    content: dict[str, bytes] = {}
    for name in MANAGED:
        content[name] = (PRISTINE / name).read_bytes()

    for name, patches in PATCHES.items():
        text = content[name].decode("utf-8")
        text = apply_literal(text, name, patches, report)
        if name == WORLD_LEGACY:
            text = apply_regex(text, name, LEGACY_REGEX_PATCHES, report)
        content[name] = text.encode("utf-8")

    # ---- 2. stylesheet
    css = content[STYLES].decode("utf-8").rstrip()
    fx = FX_CSS.read_text(encoding="utf-8")
    content[STYLES] = (css + "\n" + fx).encode("utf-8")
    report.append(("ok", STYLES, "append cardinal-fx.css", f"+{len(fx) // 1024} KB"))

    # ---- 3. index.html startup screen
    html = (PRISTINE / "index.html").read_text(encoding="utf-8")
    old_css = re.search(r"      html, body, #root \{.*?@keyframes spin \{ to \{ transform: rotate\(360deg\); \} \}", html, re.S)
    old_main = re.search(r'      <main class="startup" aria-live="polite">.*?</main>', html, re.S)
    if not old_css or not old_main:
        report.append(("FAIL", "index.html", "startup screen", "anchors not found"))
    else:
        html = html.replace(old_css.group(0), STARTUP_CSS, 1)
        html = html.replace(old_main.group(0), STARTUP_HTML, 1)
        report.append(("ok", "index.html", "startup screen", ""))

    failures = [r for r in report if r[0] == "FAIL"]
    width = max(len(r[1]) for r in report)
    for status, name, label, note in report:
        mark = "  ok  " if status == "ok" else " FAIL "
        print(f"[{mark}] {name:<{width}}  {label}{('  — ' + note) if note else ''}")

    if failures:
        print(f"\n{len(failures)} patch anchor(s) did not match. Nothing was written.")
        return 1
    if args.check:
        print("\ncheck passed; re-run with --apply to write the release")
        return 0

    # ---- 4. content-hash the changed assets and rewire references
    #
    # The entry chunk and its dynamically imported world chunk reference each
    # other by filename, so a pure content hash would be circular. Names are
    # therefore derived from the patched bytes *before* the cross-reference
    # rewrite; that is deterministic, unique per release, and enough to defeat
    # the one-year immutable cache the .htaccess sets on assets.
    renamed = {
        WORLD: f"WorldScene-{short_hash(content[WORLD])}.js",
        WORLD_LEGACY: f"WorldScene-legacy-{short_hash(content[WORLD_LEGACY])}.js",
        MAIN: f"index-{short_hash(content[MAIN])}.js",
        MAIN_LEGACY: f"index-legacy-{short_hash(content[MAIN_LEGACY])}.js",
        POLYFILLS: POLYFILLS,  # unchanged, keeps its original hash
        STYLES: f"index-{short_hash(content[STYLES])}.css",
    }

    # Rewrite every reference, in every managed chunk and in index.html.
    for name in MANAGED:
        if name == STYLES:
            continue
        text = content[name].decode("utf-8")
        for old, new in renamed.items():
            if old != new:
                text = text.replace(old, new)
        content[name] = text.encode("utf-8")

    for old, new in renamed.items():
        html = html.replace(f"assets/{old}", f"assets/{new}")

    # Nothing may still point at a filename that will not exist.
    stale_names = {old for old, new in renamed.items() if old != new}
    for name in MANAGED:
        blob = content[name].decode("utf-8", "ignore")
        for old in stale_names:
            if old in blob:
                sys.exit(f"ABORT: {renamed[name]} still references removed asset {old}")
    for old in stale_names:
        if old in html:
            sys.exit(f"ABORT: index.html still references removed asset {old}")

    # ---- 5. write everything (removing any previous release's hashed files)
    keep = set(renamed.values()) | set(ALIASES)
    for existing in ASSETS.iterdir():
        if not existing.is_file():
            continue
        name = existing.name
        if name in keep:
            continue
        if re.fullmatch(r"(index|index-legacy|polyfills-legacy|WorldScene|WorldScene-legacy)-[A-Za-z0-9_-]+\.(js|css)", name):
            existing.unlink()
    for old, new in renamed.items():
        (ASSETS / new).write_bytes(content[old])
    for alias, canonical in ALIASES.items():
        (ASSETS / alias).write_bytes(content[canonical])
    (ROOT / "index.html").write_text(html, encoding="utf-8")

    print("\nrenamed assets")
    for old, new in renamed.items():
        flag = "" if old == new else "  <- was " + old
        print(f"  {new}{flag}")
    print(f"\nrelease marker: {RELEASE}")
    print("index.html, stable aliases and dynamic-import references updated.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
