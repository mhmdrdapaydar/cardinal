# tools/ — Cardinal graphics pipeline (build time only)

Nothing in this directory is needed to run the game. It is not referenced by
`index.html`, `api.php` or any bundle, and the package `.htaccess` denies HTTP
access to it. You may skip it entirely when uploading to the host.

Everything here is **presentation only**. No script reads, writes or reasons
about a game rule, formula, reward, cost, cooldown or progression value.

| File | Purpose |
| --- | --- |
| `build_graphics_release.py` | Applies the graphics patches to the shipped Vite build, appends `cardinal-fx.css`, re-hashes the bundles and rewires `index.html`. |
| `cardinal-fx.css` | The cinematic/HUD layer as readable, commented CSS. Edit this, not the minified stylesheet. |
| `enhance_textures.py` | Regenerates every material and normal map as seamlessly tileable 512px + 1024px pairs. |
| `bundle-originals/` | Pristine copies of the shipped bundles. The release builder always patches from these, so it is idempotent. Do not edit. |
| `texture-originals/` | Pristine 512px source maps, so repeated texture runs never compound artefacts. |
| `dev-server.mjs` | Local static server with a mock `api.php`, for previewing without PHP. Implements no game rule and must never be deployed. |
| `dev-force-quality.mjs` | Builds a scratch preview root where `?q=high` pins the quality tier, for reviewing desktop-tier graphics on a machine that would otherwise be detected as low-end. |
| `shoot.mjs` | Headless before/after screenshot harness. |
| `make_upload_zip.py` | Packages the upload-ready ZIP (no `tools/`, no `.git`) and verifies the archive before finishing. |
| `preview/` | Before/after comparison images. |

## Usage

```bash
python3 tools/enhance_textures.py --check
python3 tools/enhance_textures.py --apply

python3 tools/build_graphics_release.py --check   # verifies all 26 patch anchors
python3 tools/build_graphics_release.py --apply

node tools/dev-server.mjs 8080                    # http://localhost:8080

python3 tools/make_upload_zip.py                   # build the upload archive
```

`enhance_textures.py` needs Pillow and numpy; the other scripts need only
Python 3 or Node with no dependencies.

## If a patch anchor ever stops matching

`build_graphics_release.py` asserts that every one of its edits matches exactly
once in the minified bundle and refuses to write anything otherwise. That is
the intended failure mode: it means the underlying build was regenerated from
source and the anchors need to be re-derived against the new output.
