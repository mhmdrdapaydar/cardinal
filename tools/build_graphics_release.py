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

# Texture cache marker. Bump this ONLY when the jpg files themselves change:
# it is appended to every texture URL, so raising it forces every client to
# re-download ~3 MB of maps.
RELEASE = "20260906-world-graphics-2"

# Package label, used for the upload archive filename and the docs. Safe to
# bump on every release; it costs nothing.
PACKAGE = "20260906-anime-1"

# --------------------------------------------------------------------------
# Cel shading.
#
# Injected into every MeshStandardMaterial through `onBeforeCompile`, which is
# a single global hook -- no mesh, material assignment or scene structure is
# touched. The base Material derives its program cache key from
# `onBeforeCompile.toString()`, so all standard materials still share one
# compiled program.
#
# Two anime staples:
#   1. The direct diffuse irradiance is divided out of the albedo, quantised
#      into bands, and multiplied back. Quantising irradiance rather than
#      final colour keeps the terminator in the same place on every object
#      regardless of how light or dark its texture is.
#   2. A Fresnel rim light along silhouettes, added to emissive so it survives
#      shadowing.
# --------------------------------------------------------------------------
CEL_BANDS = "3.0"        # number of light steps
CEL_HARDNESS = "0.88"    # 0 = smooth as before, 1 = hard steps
CEL_RIM_COLOR = "vec3( 0.46, 0.76, 1.0 )"
CEL_SHADOW_TINT = "vec3( 0.72, 0.74, 1.06 )"   # cool violet where light does not reach
CEL_LIGHT_TINT = "vec3( 1.06, 1.02, 0.95 )"    # warm bounce on the lit side
CEL_RIM_STRENGTH = "0.40"
CEL_RIM_START = "0.40"
CEL_RIM_END = "0.84"
CEL_INK_START = "0.88"      # only the last sliver before the silhouette
CEL_INK_DARKNESS = "0.24"   # how dark the drawn contour goes
CEL_SPEC_THRESHOLD = "0.05" # where the hard sheen snaps on
CEL_SPEC_GAIN = "2.3"       # how bright it gets once it does

CEL_GLSL = (
    '"#include <aomap_fragment>\\n'
    # Silhouette work must use the GEOMETRIC normal. geometryNormal has already
    # been perturbed by the normal map -- the cobblestone map alone tilts it by
    # ~55 degrees, which both broke the horizontal mask (the whole plaza lit up)
    # and made the rim crawl with surface detail instead of tracing the outline.
    '#ifdef FLAT_SHADED\\n'
    'vec3 celGeoNormal = geometryNormal;\\n'
    '#else\\n'
    'vec3 celGeoNormal = normalize( vNormal );\\n'
    '#ifdef DOUBLE_SIDED\\n'
    'celGeoNormal *= faceDirection;\\n'
    '#endif\\n'
    '#endif\\n'
    'vec3 celUpView = normalize( ( viewMatrix * vec4( 0.0, 1.0, 0.0, 0.0 ) ).xyz );\\n'
    'float celFlatness = smoothstep( 0.32, 0.78, abs( dot( celGeoNormal, celUpView ) ) );\\n'
    'vec3 celAlbedo = max( material.diffuseColor, vec3( 0.004 ) );\\n'
    'float celAlbedoMax = max( max( celAlbedo.r, celAlbedo.g ), celAlbedo.b );\\n'
    'vec3 celIrradiance = reflectedLight.directDiffuse / celAlbedo;\\n'
    'float celLum = dot( celIrradiance, vec3( 0.2126, 0.7152, 0.0722 ) );\\n'
    'if ( celLum > 0.0025 && celAlbedoMax > 0.02 ) {\\n'
    'float celT = celLum / ( celLum + 1.0 );\\n'
    f'float celQ = ( floor( celT * {CEL_BANDS} ) + 0.5 ) / {CEL_BANDS};\\n'
    f'float celHardness = mix( {CEL_HARDNESS}, {CEL_HARDNESS} * 0.28, celFlatness );\\n'
    'celQ = clamp( mix( celT, celQ, celHardness ), 0.02, 0.96 );\\n'
    'reflectedLight.directDiffuse *= clamp( ( celQ / ( 1.0 - celQ ) ) / celLum, 0.42, 1.08 );\\n'
    '}\\n'
    # Unlit areas drift toward a cool violet instead of going flat grey, and
    # lit areas pick up a faint warm bounce. This colour split is what
    # separates anime cel shading from plain posterisation.
    'float celLit = smoothstep( 0.04, 0.42, celLum );\\n'
    f'reflectedLight.indirectDiffuse *= mix( {CEL_SHADOW_TINT}, {CEL_LIGHT_TINT}, celLit );\\n'

    'float celSpecLum = dot( reflectedLight.directSpecular, vec3( 0.2126, 0.7152, 0.0722 ) );\\n'
    f'float celSpecStep = smoothstep( {CEL_SPEC_THRESHOLD}, {CEL_SPEC_THRESHOLD} * 2.4, celSpecLum );\\n'
    f'reflectedLight.directSpecular = mix( reflectedLight.directSpecular * 0.3,'
    f' reflectedLight.directSpecular * {CEL_SPEC_GAIN}, celSpecStep );\\n'
    'float celFacing = 1.0 - saturate( dot( celGeoNormal, geometryViewDir ) );\\n'
    # Ink contour. On a model built from spheres, cylinders and cones the
    # very edge of each primitive turns almost perpendicular to the eye, so
    # darkening that sliver reads as a drawn outline without needing an
    # inverted-hull pass (which would mean touching the scene graph).
    f'float celInk = smoothstep( {CEL_INK_START}, 1.0, celFacing ) * ( 1.0 - celFlatness );\\n'
    f'float celInkMul = mix( 1.0, {CEL_INK_DARKNESS}, celInk );\\n'
    'reflectedLight.directDiffuse *= celInkMul;\\n'
    'reflectedLight.indirectDiffuse *= celInkMul;\\n'
    'float celRim = celFacing;\\n'
    f'celRim = smoothstep( {CEL_RIM_START}, {CEL_RIM_END}, celRim ) * ( 1.0 - celInk );\\n'
    # A ground plane seen from a low camera is all grazing angles, so a plain
    # Fresnel term lights the entire floor. Rotate world-up into view space and
    # mask out anything roughly horizontal: the rim then lands on walls,
    # characters, trees and props -- the silhouettes anime actually inks.
    'celRim *= 1.0 - celFlatness;\\n'
    f'totalEmissiveRadiance += {CEL_RIM_COLOR} * celRim * {CEL_RIM_STRENGTH};\\n"'
)


# --------------------------------------------------------------------------
# Extra place detail (tools/cardinal-places.js).
#
# One ES5 factory serves both bundles; each is handed its own JSX runtime,
# React and useFrame. Everything it renders sits 5+ units up or at radius 55+,
# so it can never conflict with the collision volumes the build already ships.
# --------------------------------------------------------------------------
PLACES_SOURCE = (ROOT / "tools" / "cardinal-places.js").read_text(encoding="utf-8")


def places_installer(jsx: str, react: str, use_frame: str, terrain: str) -> str:
    return (PLACES_SOURCE
            + f"\nvar cardinalPlaces=cardinalMakePlaces({jsx},{react},{use_frame},{terrain});"
            + f"\nvar cardinalPortal=cardinalMakePortal({jsx},{react},{use_frame});\n")


def cel_installer(material_class: str) -> str:
    """ES5 source that hooks cel shading onto the given material class."""
    return (
        "function cardinalInstallCel(M){"
        "if(!M||!M.prototype||M.prototype.__cardinalCel)return;"
        "M.prototype.__cardinalCel=1;"
        "M.prototype.onBeforeCompile=function(shader){"
        "shader.fragmentShader=shader.fragmentShader.replace("
        '"#include <aomap_fragment>",' + CEL_GLSL + ");"
        "};}"
        f"try{{cardinalInstallCel({material_class});}}catch(err){{}}"
    )

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


# --------------------------------------------------------------------------
# Interface fixes (client side of the equipment / discard work).
#
# These are navigation and UI wiring only. The rules they surface live in
# lib/Game.php, which was changed separately and deliberately.
# --------------------------------------------------------------------------

# Identical string literals in both the modern and the legacy entry bundle.
# Discarding is a safe-zone action, so the backpack panel stays city-only and
# the wild sidebar is left exactly as shipped. The equipment panel still works
# outside the city, because that only needed the inventory *read* to be
# permitted -- not the panel itself.
SHARED_UI_PATCHES: list[tuple[str, str, str]] = []

DROP = "\u062f\u0648\u0631 \u0627\u0646\u062f\u0627\u062e\u062a\u0646"                     # "discard"
DROP_MATERIAL = DROP + " \u0645\u0627\u062f\u0647 \u0627\u0648\u0644\u06cc\u0647"           # "discard material"
DROP_ITEM = DROP + " \u0622\u06cc\u062a\u0645"                                                   # "discard item"
DROP_CRAFTED = (DROP + " \u062f\u0627\u0626\u0645\u06cc \u0622\u06cc\u062a\u0645 "
                + "\u0633\u0627\u062e\u062a\u0647\u200c\u0634\u062f\u0647")                 # "permanently discard crafted item"
TRANSFER = "\u0627\u0646\u062a\u0642\u0627\u0644"                                              # "transfer"

MODERN_UI_PATCHES = [
    (
        "discard button on crafted items",
        'c.isTradeable&&!c.equipped&&l.jsx("div",{className:"row-actions",children:'
        'l.jsx(T,{tone:"ghost",onClick:()=>h({type:"crafted",id:c.instanceId,name:c.itemName}),'
        f'children:"{TRANSFER}"}})}})',
        '!c.equipped&&l.jsxs("div",{className:"row-actions",children:['
        'c.isTradeable&&l.jsx(T,{tone:"ghost",onClick:()=>h({type:"crafted",id:c.instanceId,name:c.itemName}),'
        f'children:"{TRANSFER}"}}),'
        'l.jsx(T,{tone:"danger",onClick:()=>y({type:"crafted",id:c.instanceId,name:c.itemName,max:1}),'
        f'children:"{DROP}"}})]}})',
    ),
    (
        "route crafted discards to drop-crafted",
        f'title:p.type==="mini"?"{DROP_MATERIAL}":"{DROP_ITEM}"',
        f'title:p.type==="crafted"?"{DROP_CRAFTED}":p.type==="mini"?"{DROP_MATERIAL}":"{DROP_ITEM}"',
    ),
    (
        "crafted discard payload",
        '_(p.type==="mini"?"drop-mini":"drop-item",p.type==="mini"?{miniItemId:p.id,quantity:c}:{itemId:p.id,quantity:c})',
        '_(p.type==="crafted"?"drop-crafted":p.type==="mini"?"drop-mini":"drop-item",'
        'p.type==="crafted"?{instanceId:p.id}:p.type==="mini"?{miniItemId:p.id,quantity:c}:{itemId:p.id,quantity:c})',
    ),
]

LEGACY_UI_PATCHES = [
    (
        "discard button on crafted items",
        'e.isTradeable&&!e.equipped&&ce.jsx("div",{className:"row-actions",children:'
        'ce.jsx(op,{tone:"ghost",onClick:function(){return m({type:"crafted",id:e.instanceId,name:e.itemName})},'
        f'children:"{TRANSFER}"}})}})',
        '!e.equipped&&ce.jsxs("div",{className:"row-actions",children:['
        'e.isTradeable&&ce.jsx(op,{tone:"ghost",onClick:function(){return m({type:"crafted",id:e.instanceId,name:e.itemName})},'
        f'children:"{TRANSFER}"}}),'
        'ce.jsx(op,{tone:"danger",onClick:function(){return w({type:"crafted",id:e.instanceId,name:e.itemName,max:1})},'
        f'children:"{DROP}"}})]}})',
    ),
    (
        "route crafted discards to drop-crafted",
        f'title:"mini"===y.type?"{DROP_MATERIAL}":"{DROP_ITEM}"',
        f'title:"crafted"===y.type?"{DROP_CRAFTED}":"mini"===y.type?"{DROP_MATERIAL}":"{DROP_ITEM}"',
    ),
    (
        "crafted discard payload",
        'S("mini"===y.type?"drop-mini":"drop-item","mini"===y.type?{miniItemId:y.id,quantity:n}:{itemId:y.id,quantity:n})',
        'S("crafted"===y.type?"drop-crafted":"mini"===y.type?"drop-mini":"drop-item",'
        '"crafted"===y.type?{instanceId:y.id}:"mini"===y.type?{miniItemId:y.id,quantity:n}:{itemId:y.id,quantity:n})',
    ),
]


LEGACY_PLACE_MOUNTS = [('mount place detail in the city scene', 'b.jsx(Gv,{palette:a,city:!0,quality:n})', 'b.jsx(cardinalPlaces,{palette:a,quality:n,city:!0,ground:Lv(CARDINAL_GROUND_URL,1,1,n)}),b.jsx(Gv,{palette:a,city:!0,quality:n})'), ('mount place detail in the wild scene', 'b.jsx(Gv,{palette:a,city:!1,quality:n})', 'b.jsx(cardinalPlaces,{palette:a,quality:n,city:!1,ground:Lv(CARDINAL_GROUND_URL,1,1,n)}),b.jsx(Gv,{palette:a,city:!1,quality:n})')]

# --------------------------------------------------------------------------
# Avatar detail (tools/cardinal-avatar.js).
#
# Replaces the hair and face, and layers an outfit over the existing torso.
# The limb groups the walk cycle drives, the body capsule, the ground shadow,
# the label height and the point light are all left alone, so the animation
# and the avatar's footprint are unchanged.
# --------------------------------------------------------------------------
# --------------------------------------------------------------------------
# Painted face texture.
#
# The head is a plain SphereGeometry, so its UVs are equirectangular and the
# face lands at u = 0.75 (the -Z side the character faces). tools/
# make_avatar_face.py derives every feature row from the world-space heights
# the build already used, so the painted eyes sit exactly where the geometric
# ones did. Filename carries a content hash; it is discovered rather than
# hardcoded so regenerating the texture does not need a code edit.
# --------------------------------------------------------------------------
def ground_decal_name() -> str:
    found = sorted(ASSETS.glob("cardinal-ground-*.png"))
    if len(found) != 1:
        sys.exit(f"ABORT: expected exactly one cardinal-ground-*.png in assets/, found {len(found)}."
                 " Run: python3 tools/make_ground_decal.py")
    return found[0].name


def portal_texture_name() -> str:
    found = sorted(ASSETS.glob("cardinal-portal-*.png"))
    if len(found) != 1:
        sys.exit(f"ABORT: expected exactly one cardinal-portal-*.png in assets/, found {len(found)}."
                 " Run: python3 tools/make_portal_texture.py")
    return found[0].name


def plaza_decal_name() -> str:
    found = sorted(ASSETS.glob("cardinal-plaza-*.png"))
    if len(found) != 1:
        sys.exit(f"ABORT: expected exactly one cardinal-plaza-*.png in assets/, found {len(found)}."
                 " Run: python3 tools/make_plaza_decal.py")
    return found[0].name


def face_texture_names() -> tuple[str, str]:
    male = sorted(ASSETS.glob("cardinal-face-m-*.png"))
    female = sorted(ASSETS.glob("cardinal-face-f-*.png"))
    if len(male) != 1 or len(female) != 1:
        sys.exit("ABORT: expected exactly one cardinal-face-m-*.png and one cardinal-face-f-*.png"
                 f" in assets/, found {len(male)} and {len(female)}."
                 " Run: python3 tools/make_avatar_face.py")
    return male[0].name, female[0].name

AVATAR_SOURCE = (ROOT / "tools" / "cardinal-avatar.js").read_text(encoding="utf-8")


def avatar_installer(jsx: str, react: str, use_frame: str) -> str:
    return AVATAR_SOURCE + f"\nvar cardinalAvatar=cardinalMakeAvatar({jsx},{react},{use_frame});\n"


# The plaza sigil is laid flat on the cobblestones with additive blending (2)
# and no depth write, so it reads as light inlaid in the stone rather than a
# sticker. The city ground is perfectly flat inside radius 31 -- Nt() smoothsteps
# from 31 outward -- so 0.06 above it cannot z-fight.
SIGIL_MODERN = [
    (
        "plaza sigil: url constant",
        'CARDINAL_FACE_M=""+new URL(',
        'CARDINAL_PLAZA_URL=""+new URL("' + "{PLAZA}" + '",import.meta.url).href,CARDINAL_FACE_M=""+new URL(',
    ),
    (
        "plaza sigil: laid into the plaza",
        'f.jsx(cardinalPlaces,{palette:i,quality:e,city:!0,ground:Wt(CARDINAL_GROUND_URL,1,1,e)})',
        'f.jsx(cardinalPlaces,{palette:i,quality:e,city:!0,ground:Wt(CARDINAL_GROUND_URL,1,1,e)}),'
        'f.jsxs("mesh",{rotation:[-Math.PI/2,0,0],position:[0,.06,0],renderOrder:2,children:['
        'f.jsx("planeGeometry",{args:[34,34]}),'
        'f.jsx("meshBasicMaterial",{map:Wt(CARDINAL_PLAZA_URL,1,1,e),transparent:!0,opacity:e==="low"?.5:.78,depthWrite:!1,blending:tn,toneMapped:!1})]})',
    ),
]

SIGIL_LEGACY = [
    (
        "plaza sigil: url constant",
        'CARDINAL_FACE_M=""+new URL(',
        'CARDINAL_PLAZA_URL=""+new URL("' + "{PLAZA}" + '",v.meta.url).href,CARDINAL_FACE_M=""+new URL(',
    ),
    (
        "plaza sigil: laid into the plaza",
        'b.jsx(cardinalPlaces,{palette:a,quality:n,city:!0,ground:Lv(CARDINAL_GROUND_URL,1,1,n)})',
        'b.jsx(cardinalPlaces,{palette:a,quality:n,city:!0,ground:Lv(CARDINAL_GROUND_URL,1,1,n)}),'
        'b.jsxs("mesh",{rotation:[-Math.PI/2,0,0],position:[0,.06,0],renderOrder:2,children:['
        'b.jsx("planeGeometry",{args:[34,34]}),'
        'b.jsx("meshBasicMaterial",{map:Lv(CARDINAL_PLAZA_URL,1,1,n),transparent:!0,opacity:"low"===n?.5:.78,depthWrite:!1,blending:O,toneMapped:!1})]})',
    ),
]


# Remote players and the SAO colour cursor. `em`/`i0` is the drei Html helper
# the build already uses for the self label, and Ix/Ov is its class-visual
# table; both are passed in rather than re-created.
# The teleport shrine gains a gate arch, a swirling disc and a light column.
# Everything sits above the existing dais, inside a footprint that already has
# a collider.
GATE_MODERN = [
    (
        "portal texture: url constant",
        'CARDINAL_PLAZA_URL=""+new URL(',
        'CARDINAL_GROUND_URL=""+new URL("' + "{GROUND}" + '",import.meta.url).href,'
        'CARDINAL_PORTAL_URL=""+new URL("' + "{PORTAL}" + '",import.meta.url).href,CARDINAL_PLAZA_URL=""+new URL(',
    ),
    (
        "teleport shrine: gate, swirl and beam",
        'f.jsx("pointLight",{color:e.accentSoft,intensity:t==="low"?1.1:2.5,distance:7.5,position:[0,1.45,0]})',
        'f.jsx(cardinalPortal,{palette:e,quality:t,tex:Wt(CARDINAL_PORTAL_URL,1,1,t)}),'
        'f.jsx("pointLight",{color:e.accentSoft,intensity:t==="low"?1.1:2.5,distance:7.5,position:[0,1.45,0]})',
    ),
]

GATE_LEGACY = [
    (
        "portal texture: url constant",
        'CARDINAL_PLAZA_URL=""+new URL(',
        'CARDINAL_GROUND_URL=""+new URL("' + "{GROUND}" + '",v.meta.url).href,'
        'CARDINAL_PORTAL_URL=""+new URL("' + "{PORTAL}" + '",v.meta.url).href,CARDINAL_PLAZA_URL=""+new URL(',
    ),
    (
        "teleport shrine: gate, swirl and beam",
        'b.jsx("pointLight",{color:n.accentSoft,intensity:"low"===r?1.1:2.5,distance:7.5,position:[0,1.45,0]})',
        'b.jsx(cardinalPortal,{palette:n,quality:r,tex:Lv(CARDINAL_PORTAL_URL,1,1,r)}),'
        'b.jsx("pointLight",{color:n.accentSoft,intensity:"low"===r?1.1:2.5,distance:7.5,position:[0,1.45,0]})',
    ),
]


PEERS_MODERN = [
    (
        "peers: mounted in the city scene",
        'f.jsx(cardinalPlaces,{palette:i,quality:e,city:!0,ground:Wt(CARDINAL_GROUND_URL,1,1,e)})',
        'f.jsx(cardinalPlaces,{palette:i,quality:e,city:!0,ground:Wt(CARDINAL_GROUND_URL,1,1,e)}),'
        'f.jsx(cardinalAvatar.Peers,{texM:Wt(CARDINAL_FACE_M,1,1,"high"),texF:Wt(CARDINAL_FACE_F,1,1,"high"),colours:Ix,label:em,max:e==="high"?14:e==="balanced"?8:4})',
    ),
    (
        "peers: mounted in the wild scene",
        'f.jsx(cardinalPlaces,{palette:i,quality:e,city:!1,ground:Wt(CARDINAL_GROUND_URL,1,1,e)})',
        'f.jsx(cardinalPlaces,{palette:i,quality:e,city:!1,ground:Wt(CARDINAL_GROUND_URL,1,1,e)}),'
        'f.jsx(cardinalAvatar.Peers,{texM:Wt(CARDINAL_FACE_M,1,1,"high"),texF:Wt(CARDINAL_FACE_F,1,1,"high"),colours:Ix,label:em,max:e==="high"?14:e==="balanced"?8:4})',
    ),
    (
        "own colour cursor above the head",
        'f.jsx("pointLight",{color:u.glow,intensity:2.55,distance:7.3,position:[0,1.65,0]})',
        'f.jsx(cardinalAvatar.Cursor,{pk:r.pkStatus||"white",y:2.72,phase:0}),'
        'f.jsx("pointLight",{color:u.glow,intensity:2.55,distance:7.3,position:[0,1.65,0]})',
    ),
]

PEERS_LEGACY = [
    (
        "peers: mounted in the city scene",
        'b.jsx(cardinalPlaces,{palette:a,quality:n,city:!0,ground:Lv(CARDINAL_GROUND_URL,1,1,n)})',
        'b.jsx(cardinalPlaces,{palette:a,quality:n,city:!0,ground:Lv(CARDINAL_GROUND_URL,1,1,n)}),'
        'b.jsx(cardinalAvatar.Peers,{texM:Lv(CARDINAL_FACE_M,1,1,"high"),texF:Lv(CARDINAL_FACE_F,1,1,"high"),colours:xv,label:Jm,max:"high"===n?14:"balanced"===n?8:4})',
    ),
    (
        "peers: mounted in the wild scene",
        'b.jsx(cardinalPlaces,{palette:a,quality:n,city:!1,ground:Lv(CARDINAL_GROUND_URL,1,1,n)})',
        'b.jsx(cardinalPlaces,{palette:a,quality:n,city:!1,ground:Lv(CARDINAL_GROUND_URL,1,1,n)}),'
        'b.jsx(cardinalAvatar.Peers,{texM:Lv(CARDINAL_FACE_M,1,1,"high"),texF:Lv(CARDINAL_FACE_F,1,1,"high"),colours:xv,label:Jm,max:"high"===n?14:"balanced"===n?8:4})',
    ),
    (
        "own colour cursor above the head",
        'b.jsx("pointLight",{color:c.glow,intensity:2.55,distance:7.3,position:[0,1.65,0]})',
        'b.jsx(cardinalAvatar.Cursor,{pk:n.pkStatus||"white",y:2.72,phase:0}),'
        'b.jsx("pointLight",{color:c.glow,intensity:2.55,distance:7.3,position:[0,1.65,0]})',
    ),
]


FACE_MODERN = [
    (
        "face texture: url constant",
        'tm=""+new URL("cobblestone-plaza-Bv05-kDx.jpg",import.meta.url).href',
        'CARDINAL_FACE_M=""+new URL("' + "{FACE_M}" + '",import.meta.url).href,'
        'CARDINAL_FACE_F=""+new URL("' + "{FACE_F}" + '",import.meta.url).href,'
        'tm=""+new URL("cobblestone-plaza-Bv05-kDx.jpg",import.meta.url).href',
    ),
    (
        "face texture: applied to the head",
        'f.jsxs("mesh",{castShadow:!0,position:[0,1.98,0],children:[f.jsx("sphereGeometry",{args:[.34,20,16]}),f.jsx("meshStandardMaterial",{color:h,roughness:.58})]})',
        'f.jsx(cardinalAvatar.Head,{skin:h,tex:Wt(r.gender==="female"?CARDINAL_FACE_F:CARDINAL_FACE_M,1,1,"high")})',
    ),
]

FACE_LEGACY = [
    (
        "face texture: url constant",
        'iv=""+new URL("cobblestone-plaza-Bv05-kDx.jpg",v.meta.url).href',
        'CARDINAL_FACE_M=""+new URL("' + "{FACE_M}" + '",v.meta.url).href,'
        'CARDINAL_FACE_F=""+new URL("' + "{FACE_F}" + '",v.meta.url).href,'
        'iv=""+new URL("cobblestone-plaza-Bv05-kDx.jpg",v.meta.url).href',
    ),
    (
        "face texture: applied to the head",
        'b.jsxs("mesh",{castShadow:!0,position:[0,1.98,0],children:[b.jsx("sphereGeometry",{args:[.34,20,16]}),b.jsx("meshStandardMaterial",{color:h,roughness:.58})]})',
        'b.jsx(cardinalAvatar.Head,{skin:h,tex:Lv("female"===n.gender?CARDINAL_FACE_F:CARDINAL_FACE_M,1,1,"high")})',
    ),
]


AVATAR_MODERN = [
    (
        "avatar: layered hair",
        'f.jsxs("mesh",{castShadow:!0,position:[0,2.21,.025],scale:[1.12,.52,1.09],children:[f.jsx("sphereGeometry",{args:[.33,18,12]}),f.jsx("meshStandardMaterial",{color:d,roughness:.83})]}),'
        '[-.14,0,.14].map((x,_)=>f.jsxs("mesh",{castShadow:!0,position:[x,2.22+(_===1?.05:0),-.19],rotation:[.22,0,(_-1)*.18],scale:[.14,.2,.11],children:[f.jsx("sphereGeometry",{args:[1,9,7]}),f.jsx("meshStandardMaterial",{color:d,roughness:.8})]},x))',
        'f.jsx(cardinalAvatar.Hair,{hair:d,visual:u})',
    ),
    (
        "avatar: drop geometric face (painted now)",
        'f.jsxs("mesh",{position:[-.11,1.99,-.322],children:[f.jsx("sphereGeometry",{args:[.048,8,8]}),f.jsx("meshBasicMaterial",{color:u.glow})]}),'
        'f.jsxs("mesh",{position:[.11,1.99,-.322],children:[f.jsx("sphereGeometry",{args:[.048,8,8]}),f.jsx("meshBasicMaterial",{color:u.glow})]}),'
        'f.jsxs("mesh",{position:[0,1.9,-.33],scale:[.06,.09,.05],children:[f.jsx("sphereGeometry",{args:[1,8,6]}),f.jsx("meshStandardMaterial",{color:"#bd7f6e",roughness:.7})]})',
        'null',
    ),
    (
        "avatar: slimmer torso",
        'position:[0,1.13,0],scale:[.67,1.18,.55]',
        'position:[0,1.14,0],scale:[.6,1.24,.5]',
    ),
]

AVATAR_LEGACY = [
    (
        "avatar: layered hair",
        'b.jsxs("mesh",{castShadow:!0,position:[0,2.21,.025],scale:[1.12,.52,1.09],children:[b.jsx("sphereGeometry",{args:[.33,18,12]}),b.jsx("meshStandardMaterial",{color:d,roughness:.83})]}),'
        '[-.14,0,.14].map(function(e,t){return b.jsxs("mesh",{castShadow:!0,position:[e,2.22+(1===t?.05:0),-.19],rotation:[.22,0,.18*(t-1)],scale:[.14,.2,.11],children:[b.jsx("sphereGeometry",{args:[1,9,7]}),b.jsx("meshStandardMaterial",{color:d,roughness:.8})]},e)})',
        'b.jsx(cardinalAvatar.Hair,{hair:d,visual:c})',
    ),
    (
        "avatar: drop geometric face (painted now)",
        'b.jsxs("mesh",{position:[-.11,1.99,-.322],children:[b.jsx("sphereGeometry",{args:[.048,8,8]}),b.jsx("meshBasicMaterial",{color:c.glow})]}),'
        'b.jsxs("mesh",{position:[.11,1.99,-.322],children:[b.jsx("sphereGeometry",{args:[.048,8,8]}),b.jsx("meshBasicMaterial",{color:c.glow})]}),'
        'b.jsxs("mesh",{position:[0,1.9,-.33],scale:[.06,.09,.05],children:[b.jsx("sphereGeometry",{args:[1,8,6]}),b.jsx("meshStandardMaterial",{color:"#bd7f6e",roughness:.7})]})',
        'null',
    ),
    (
        "avatar: slimmer torso",
        'position:[0,1.13,0],scale:[.67,1.18,.55]',
        'position:[0,1.14,0],scale:[.6,1.24,.5]',
    ),
]


WALL_MODERN = [('city wall: tiers, chord width, gate opening', 'const t=Wt(Yo,1.4,1.1,e),n=Wt(Zo,1.4,1.1,e,en),i=e==="low"?5:10,s=e==="low"?3:6;', 'const t=Wt(Yo,1.4,1.1,e),n=Wt(Zo,1.4,1.1,e,en),i=e==="low"?6:e==="balanced"?8:10,s=e==="low"?4:e==="balanced"?9:12,cw=2*35.5*Math.sin(Math.PI/i)+.5,gs=Math.round(i/4-.5);'), ('city wall: tangential orientation', 'const u=l/i*Math.PI*2,h=Math.cos(u)*35.5,d=Math.sin(u)*35.5;return f.jsxs("group",{position:[h,1.65,d],rotation:[0,-u,0],', 'if(l===gs)return null;const u=(l+.5)/i*Math.PI*2,h=Math.cos(u)*35.5,d=Math.sin(u)*35.5;return f.jsxs("group",{position:[h,1.9,d],rotation:[0,-u-Math.PI/2,0],'), ('city wall: slab spans the full chord', 'f.jsx("boxGeometry",{args:[8.2,3.25,1.05]})', 'f.jsx("boxGeometry",{args:[cw,3.8,1.15]})'), ('city wall: coping course', 'f.jsxs("mesh",{position:[0,1.97,.58],children:[f.jsx("boxGeometry",{args:[7.85,.26,.15]})', 'f.jsxs("mesh",{position:[0,2.05,.62],children:[f.jsx("boxGeometry",{args:[cw*.99,.3,.22]})'), ('city wall: battlements spread over the chord', 'f.jsxs("mesh",{position:[-3.28+g*(6.56/Math.max(1,s-1)),2.05,0],children:[f.jsx("boxGeometry",{args:[.65,.85,1.25]})', 'f.jsxs("mesh",{position:[-cw*.44+g*(cw*.88/Math.max(1,s-1)),2.42,0],children:[f.jsx("boxGeometry",{args:[.72,.98,1.35]})'), ('city wall: banner height', 'l%2===0&&f.jsx(Bs,{position:[0,3.05,.1]', 'l%2===0&&f.jsx(Bs,{position:[0,3.4,.1]')]

WALL_LEGACY = [('city wall: tiers, chord width, gate opening', 'i="low"===t?5:10,a="low"===t?3:6;', 'i="low"===t?6:"balanced"===t?8:10,a="low"===t?4:"balanced"===t?9:12,cw=2*35.5*Math.sin(Math.PI/i)+.5,gs=Math.round(i/4-.5);'), ('city wall: tangential orientation', 'function(e,o){var s=o/i*Math.PI*2,l=35.5*Math.cos(s),u=35.5*Math.sin(s);return b.jsxs("group",{position:[l,1.65,u],rotation:[0,-s,0],', 'function(e,o){if(o===gs)return null;var s=(o+.5)/i*Math.PI*2,l=35.5*Math.cos(s),u=35.5*Math.sin(s);return b.jsxs("group",{position:[l,1.9,u],rotation:[0,-s-Math.PI/2,0],'), ('city wall: slab spans the full chord', 'b.jsx("boxGeometry",{args:[8.2,3.25,1.05]})', 'b.jsx("boxGeometry",{args:[cw,3.8,1.15]})'), ('city wall: coping course', 'b.jsxs("mesh",{position:[0,1.97,.58],children:[b.jsx("boxGeometry",{args:[7.85,.26,.15]})', 'b.jsxs("mesh",{position:[0,2.05,.62],children:[b.jsx("boxGeometry",{args:[cw*.99,.3,.22]})'), ('city wall: battlements spread over the chord', 'b.jsxs("mesh",{position:[t*(6.56/Math.max(1,a-1))-3.28,2.05,0],children:[b.jsx("boxGeometry",{args:[.65,.85,1.25]})', 'b.jsxs("mesh",{position:[t*(cw*.88/Math.max(1,a-1))-cw*.44,2.42,0],children:[b.jsx("boxGeometry",{args:[.72,.98,1.35]})'), ('city wall: banner height', 'o%2==0&&b.jsx(yg,{position:[0,3.05,.1]', 'o%2==0&&b.jsx(yg,{position:[0,3.4,.1]')]

MODERN_PLACE_MOUNTS = [('mount place detail in the city scene', 'f.jsx(x_,{palette:i,city:!0,quality:e})', 'f.jsx(cardinalPlaces,{palette:i,quality:e,city:!0,ground:Wt(CARDINAL_GROUND_URL,1,1,e)}),f.jsx(x_,{palette:i,city:!0,quality:e})'), ('mount place detail in the wild scene', 'f.jsx(x_,{palette:i,city:!1,quality:e})', 'f.jsx(cardinalPlaces,{palette:i,quality:e,city:!1,ground:Wt(CARDINAL_GROUND_URL,1,1,e)}),f.jsx(x_,{palette:i,city:!1,quality:e})')]


def _tex(patches):
    face_m, face_f = face_texture_names()
    plaza = plaza_decal_name()
    portal = portal_texture_name()
    ground = ground_decal_name()
    return [(label, a, b.replace("{FACE_M}", face_m).replace("{FACE_F}", face_f)
                        .replace("{PLAZA}", plaza).replace("{PORTAL}", portal)
                        .replace("{GROUND}", ground))
            for label, a, b in patches]


PATCHES: dict[str, list[tuple[str, str, str]]] = {
    WORLD: MODERN_PLACE_MOUNTS + WALL_MODERN + AVATAR_MODERN + _tex(FACE_MODERN) + _tex(SIGIL_MODERN) + _tex(PEERS_MODERN) + _tex(GATE_MODERN) + [
        (
            "texture tier + cel-shading installer",
            'IC="20260905-world-recovery-1";function c_(r){return"".concat(r).concat(r.includes("?")?"&":"?","cardinal-world=").concat(IC)}',
            f'IC="{RELEASE}";{TEXTURE_SELECTOR}{cel_installer("Np")}{places_installer("f", "H", "kt", "Nt")}{avatar_installer("f", "H", "kt")}'
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
    MAIN: SHARED_UI_PATCHES + MODERN_UI_PATCHES,
    MAIN_LEGACY: SHARED_UI_PATCHES + LEGACY_UI_PATCHES,
    WORLD_LEGACY: LEGACY_PLACE_MOUNTS + WALL_LEGACY + AVATAR_LEGACY + _tex(FACE_LEGACY) + _tex(SIGIL_LEGACY) + _tex(PEERS_LEGACY) + _tex(GATE_LEGACY) + [
        (
            "texture tier + cel-shading installer",
            'pv="20260905-world-recovery-1";function mv(e){return"".concat(e).concat(e.includes("?")?"&":"?","cardinal-world=").concat(pv)}',
            f'pv="{RELEASE}";{TEXTURE_SELECTOR}{cel_installer("hl")}{places_installer("b", "_", "Qp", "Ev")}{avatar_installer("b", "_", "Qp")}'
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
def apply_submit_fix(text: str, name: str, jsx: str, button: str, report, expected: int) -> str:
    """
    Give form submit buttons a working type.

    The shared Button hardcodes `type="button"` before spreading its props, so
    every form whose submit control is a Button and does not pass a type is
    dead: clicking it does nothing at all. Teleport, discarding an item,
    starting an attack, sending a gift and six more were all in that state.
    Cancel buttons already pass type="button" explicitly, which is what shows
    the intended default was "submit".

    Only calls with neither `type:` nor `onClick:` are touched. By construction
    those can only be a form's submit control -- an action button would carry
    an onClick.
    """
    needle = jsx + ".jsx"
    out = []
    i = 0
    fixed = 0
    while True:
        j = text.find(needle, i)
        if j < 0:
            out.append(text[i:])
            break
        k = j + len(needle)
        if text.startswith("s(", k):
            k += 2
        elif text.startswith("(", k):
            k += 1
        else:
            out.append(text[i:j + len(needle)])
            i = j + len(needle)
            continue
        if not text.startswith(button + ",{", k):
            out.append(text[i:j + len(needle)])
            i = j + len(needle)
            continue
        brace = k + len(button) + 1
        # walk the props object, honouring string literals
        depth = 0
        q = None
        m = brace
        while m < len(text):
            c = text[m]
            if q:
                if c == "\\":
                    m += 2
                    continue
                if c == q:
                    q = None
            elif c in "\"'`":
                q = c
            elif c == "{":
                depth += 1
            elif c == "}":
                depth -= 1
                if depth == 0:
                    break
            m += 1
        props = text[brace:m + 1]
        if "type:" not in props and "onClick:" not in props:
            out.append(text[i:brace + 1])
            out.append('type:"submit",')
            out.append(text[brace + 1:m + 1])
            fixed += 1
        else:
            out.append(text[i:m + 1])
        i = m + 1
    result = "".join(out)
    if fixed != expected:
        report.append(("FAIL", name, "form submit buttons", f"{fixed} fixed, expected {expected}"))
        return text
    report.append(("ok", name, "form submit buttons", f"{fixed} buttons"))
    return result


# --------------------------------------------------------------------------
# Realtime client (tools/cardinal-net.js).
#
# Shipped as its own asset and loaded by index.html, NOT merged into the game
# bundle: presence, chat and the roster cannot break the React tree, and the
# file can be edited without rebuilding anything. It reads the local position
# from the canvas dataset the camera controller already publishes.
# --------------------------------------------------------------------------
def build_net_asset(content_map) -> str:
    source = (ROOT / "tools" / "cardinal-net.js").read_bytes()
    name = f"cardinal-net-{short_hash(source)}.js"
    content_map[name] = source
    return name


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
        if name == MAIN:
            text = apply_submit_fix(text, name, "l", "T", report, 10)
        if name == MAIN_LEGACY:
            text = apply_submit_fix(text, name, "ce", "op", report, 10)
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

    # ---- 4b. realtime client asset
    extra: dict[str, bytes] = {}
    net_name = build_net_asset(extra)
    marker = '<script type="module" crossorigin src="./assets/' + renamed[MAIN] + '"></script>'
    if marker not in html:
        sys.exit("ABORT: could not find the module entry script tag in index.html")
    html = html.replace(marker, marker + '\n    <script defer src="./assets/' + net_name + '"></script>', 1)

    # ---- 5. write everything (removing any previous release's hashed files)
    keep = set(renamed.values()) | set(ALIASES) | set(extra)
    for existing in ASSETS.iterdir():
        if not existing.is_file():
            continue
        name = existing.name
        if name in keep:
            continue
        if re.fullmatch(r"(index|index-legacy|polyfills-legacy|WorldScene|WorldScene-legacy|cardinal-net)-[A-Za-z0-9_-]+\.(js|css)", name):
            existing.unlink()
    for old, new in renamed.items():
        (ASSETS / new).write_bytes(content[old])
    for name, blob in extra.items():
        (ASSETS / name).write_bytes(blob)
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
