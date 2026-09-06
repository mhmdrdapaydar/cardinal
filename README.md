# Cardinal V — PHP upload package

This is the web-only **server 5** release. It runs on ordinary static/PHP hosting: no Node.js, npm, Socket.IO process, cron worker, database migration, or daemon is required after upload.

## What is included

- responsive React/Three.js build with a cinematic, original virtual-fantasy 3D city/wild realm: textured terrain with local normal maps, lit cobblestone plazas, animated aurora ribbons, a skyborne citadel silhouette and aether-horizon spires, luminous fountain crown, citadel walls and gatehouse, themed homes, visual city residents, market/forge/shrine landmarks, street furniture, particles, class-coloured avatar energy, and optimized material assets; no external game/IP asset is copied into the package;
- a local neon gateway login/registration experience with depth layers, animated portal rings, light-grid/aurora treatment, glass panels, and responsive no-WebGL CSS effects; Android retains a real scrollable account form and reduced-motion users receive the static version;
- actual 360° free-look: drag the world on desktop, single-finger swipe the viewport on Android, use wheel zoom on desktop, and use the on-screen reset control to restore the default camera; movement remains relative to the camera direction;
- directional avatar movement: the character turns to face the actual travel direction for every input, including Back, instead of moonwalking;
- local rendered-world collision: the avatar is kept out of city walls, buildings, fountain/market/street props, wild trees, rocks, ruins, landmark footprints, water, and the map boundary; this presentation layer does not alter game/database rules;
- adaptive graphics controls: a reduced initial desktop pixel budget, bounded device-pixel ratio, high/balanced/low tiers, and instanced decoration; after three sustained sub-22-FPS samples, only a desktop high-tier renderer remounts at balanced quality. Android keeps its existing detected tier and touch controls; game state and database rules never change;
- Android-safe movement input: 48px controls, a dedicated hit-test layer, non-interactive closed sidebar, and notifications that never intercept a direction press;
- resilient 3D boot: local texture URLs carry a release marker to bypass a cached historical asset failure; the blue transition now has a readable loading card, a one-tap low-graphics retry, and an automatic low-graphics Canvas restart if a real rendered frame does not arrive;
- bot-managed Noble badge access: when a badge is inactive, the website shows the player's C_ID and directs them to the official Cardinal bot on Telegram, Rubika, or Bale; there is no Noble checkout, invoice button, or payment gateway in the website;
- `api.php`, PHP sessions, PDO/MySQL data adapter, and the existing game rules;
- all web gameplay routes used by the interface: account, inventory/equipment, shop, hunt/dungeon/boss/PvP, quests, party/social/guild, crafting, notifications, leaderboards, and the Rubika seasonal-card achievements board; Noble requests remain bot-managed and expose no web payment flow;
- only the legacy-compatible idempotent `INSERT IGNORE` registration for `servers.server_id = 5`; the package never creates, changes, or migrates tables;
- no Node, Socket.IO, source maps, database dump, or database credential.

Socket.IO presence was deliberately removed because a static/PHP host has no persistent Socket.IO process. This affects only the non-authoritative nearby-avatar display. Movement remains available locally in the 3D scene and every game command remains an authoritative PHP/API transaction.

## Release `20260906-places-1`

Two parts, and the distinction matters if you run several Cardinal servers against the same database.

### Behaviour changes — read this before deploying

Earlier releases in this series were presentation-only. **This one is not.** `lib/Game.php` was changed in two places, on request. No formula, reward, price, cooldown, drop rate or progression value was touched; nothing was made cheaper, faster or more profitable. `api.php`, `lib/Rules.php`, `lib/DemoGame.php` and `config.php` remain byte-for-byte unchanged.

1. **`getInventory()` no longer requires the city.** This is a read-only query. `equip()`, `unequip()` and `getEquipment()` never had a location guard, so gating only this read is what made the equipment panel unusable in the wild: the panel loads equipment and inventory together and rendered its error state whenever either call was refused. Equipping itself was never blocked. The equipment panel now behaves outside the city exactly as it does inside.
2. **New `drop-crafted` action.** The web build could craft and upgrade items but never destroy one, so a backpack full of crafted gear could not be cleared, and a non-tradeable crafted item had no row action at all. The new action deletes only the caller's own instance, refuses while the item is equipped, requires the safe zone like every other discard, and refunds nothing. The interface asks for an explicit confirmation because the deletion is permanent.

Discarding remains a **safe-zone action**: `dropItem()`, `dropMini()` and `dropCrafted()` all call `requireCity()`, and the backpack panel is still city-only, so the interface never offers a discard the server would refuse.

If you want any of these to stay restricted on a particular server, re-add the corresponding `$this->requireCity($player);` call — each removal is marked with a comment explaining why it went.

### Graphics

Presentation only, and unchanged in intent from the previous releases.

What changed in the rendered world:

- **Place detail.** New geometry in both realms, from `tools/cardinal-places.js`. `kC()` clamps the avatar to radius 33.25 in the city and 60 in the wild, so an outer district and a horizon skyline placed past that line are visible but unreachable and need no collider. The house ring is laid out by a closed-form expression in the build, so its exact transforms are recoverable: each house now carries a ridge beam, chimney, eave lamp, and — depending on the variant — a hanging shop sign, roof lantern or facade banner, all above head height on a building that already has a collider. The city also gets lantern garlands and drifting sky lanterns; the wild gets floating rock shelves. Everything is quality-tiered and the low tier renders static silhouettes only.
- **Hard-edged specular.** A PBR highlight is a soft blob; the anime convention is a sheen that snaps on. Thresholding the specular the engine already computes gives that without needing the light vector in the hook.
- **Ink contour.** The very edge of each primitive turns nearly perpendicular to the eye, so darkening that sliver reads as a drawn outline. This avoids an inverted-hull pass, which would have meant restructuring the scene graph. Floors are excluded.
- **Cel shading.** Every `MeshStandardMaterial` is hooked through `onBeforeCompile`, a single global entry point that leaves meshes, material assignments and scene structure untouched. The direct diffuse irradiance is divided out of the albedo, quantised into three bands and multiplied back, so the terminator falls in the same place on every object no matter how light or dark its texture is. Band hardness is surface-aware: crisp anime steps on characters, props and walls, much softer on the ground, where a hard step reads as a spotlight and erases the cobblestone relief.
- **Anime rim light.** A Fresnel term added to emissive traces silhouettes in cool cyan. It is computed from the *geometric* normal rather than the normal-mapped one: the cobblestone map alone tilts the shading normal by roughly 55 degrees, which previously lit the entire plaza and made the rim crawl with surface detail instead of following the outline. Horizontal surfaces are masked out entirely, so floors never glow.
- **Shadow tinting.** Unlit areas drift toward a cool violet and lit areas pick up a faint warm bounce, which is what separates anime cel shading from flat posterisation.
- **Real cast shadows.** The directional light shipped with a default ±5-unit shadow frustum, so its shadow map covered a 10×10 patch around the origin and was effectively invisible. The frustum is now ±26 units with a 2048² map on the high tier (1024² otherwise), plus a depth bias and normal bias matched to the new texel footprint.
- **Screen-space bloom.** The bundle contains no `EffectComposer`, so a post-processing pass cannot be added without rebuilding from source. Instead the world layer applies a thresholded, blurred, `screen`-blended backdrop: `contrast()` crushes mid-tones to black so only the fountain crown, lamps and emissive crystals bloom. High tier only — if the device cannot hold frame rate, the existing auto-downgrade drops to `balanced` and the layer disappears with it.
- **Two-tier textures.** Every material and normal map now ships at both 512px (`<name>.jpg`) and 1024px (`<name>-hi.jpg`). Desktop-class clients load the 1024px set; phones, coarse-pointer devices, low-memory devices, and anyone sending `Save-Data` or `prefers-reduced-data` keep the original 512px payload. The maps were regenerated as seamlessly tileable with wrap-aware normals, so tiling seams are lower than in the previous build at both sizes.
- **Sharper output.** Device-pixel-ratio ceiling raised to 2.0 (high) and 1.5 (balanced), antialiasing extended to the balanced tier, and tone-mapping exposure lifted slightly. Shadow type and tone-mapping operator were already optimal and were left alone.
- **More atmosphere.** Fog distances pulled in slightly for aerial perspective, denser plaza decoration and wild-realm scatter on the high tier, and more aether motes around the fountain.
- **Cinematic and HUD layer.** Vignette, split-tone grade, god rays, aurora ribbons and a slow specular sweep over the canvas; holographic glass, corner brackets and glow treatment for the HUD, panels, sidebar, toasts, touch controls, login screen and startup card.

Tier behaviour, the auto-downgrade thresholds, touch-control geometry and every hit area are unchanged. The low tier stays exactly as cheap as before: no bloom, no shimmer, no animation loops.

### Rebuilding the graphics release

`tools/` contains the build pipeline and is **not needed at runtime** — you do not have to upload it. If you do upload it, the `.htaccess` in this package denies access to it.

```bash
python3 tools/enhance_textures.py --check          # texture pipeline, dry run
python3 tools/enhance_textures.py --apply          # regenerate 512 + 1024 maps
python3 tools/build_graphics_release.py --check    # verify every patch anchor
python3 tools/build_graphics_release.py --apply    # write the release
```

To rebuild the upload archive after a change:

```bash
python3 tools/make_upload_zip.py
```

It writes `cardinal-web-server5-20260906-places-1.zip` containing only what the upload procedure needs, then verifies the result: every asset reference in `index.html` and every chunk-to-chunk import must resolve inside the archive, `.htaccess` must be present, each material map must ship with its `-hi` companion, no build tooling may leak in, and the PHP logic files must be byte-identical to the working tree.

`build_graphics_release.py` keeps pristine copies of the shipped bundles in `tools/bundle-originals/`, so it always patches from a clean base and can be re-run safely. Each of its 26 edits asserts that its anchor matches exactly once and aborts before writing anything if the build ever changes. It then re-hashes the changed bundles, rewrites `index.html` and the mutual chunk references, and refreshes the `cardinal-current-*` aliases.

Presentation tuning lives in `tools/cardinal-fx.css` as readable, commented CSS; it is appended to the built stylesheet by the release builder. To preview locally without PHP:

```bash
node tools/dev-server.mjs 8080     # static server + mock api.php, no game rules
```

## Requirements

- Apache/LiteSpeed or equivalent static hosting with PHP **7.4+** (PHP 8.1+ recommended);
- PHP extensions: `pdo_mysql`, `json`, `mbstring`, and `openssl`;
- MySQL/MariaDB access to the **existing** Cardinal database;
- the database user needs access to the existing tables already used by the bots.

## Upload

1. Extract this ZIP into the intended web directory, e.g. `public_html/` or `public_html/cardinal/`.
2. Open the directory URL. The landing page works in isolated preview mode before a production database is configured.
3. For production, create `/home/CPANEL_USER/cardinal-private.php` **outside** `public_html` using `private-config.example.php` as the template. Alternatively define the server environment variables:

   ```text
   CARDINAL_DB_HOST
   CARDINAL_DB_PORT
   CARDINAL_DB_NAME
   CARDINAL_DB_USER
   CARDINAL_DB_PASSWORD
   ```

4. Do **not** upload `cardinal-private.php`, do not put it into this ZIP, and do not paste it into browser-facing JavaScript.
5. Reload the website. `api.php?route=health` will report `demoMode: false` when it has opened the production connection.

### Critical: update procedure for Apache/LiteSpeed startup repair

If this package replaces an older deployment, extract it with **overwrite enabled** into the exact directory served by the site. You must replace all three of these items together:

- the hidden root file **`.htaccess`**;
- `index.html`;
- the complete `assets/` directory, including files named `cardinal-current-*.js` and `cardinal-current.css`.

Asset filenames are content-hashed and change with every graphics release, because `.htaccess` caches `.js`, `.css` and `.jpg` as `immutable` for a year. Delete the old `assets/` contents rather than merging into them, so no bundle from a previous release is left behind. The graphics releases also add `<name>-hi.jpg` companions next to each material map; those are part of the asset directory and must be uploaded with it. `tools/` is build tooling and can be skipped entirely.

In cPanel **File Manager → Settings**, enable **Show Hidden Files (dotfiles)** before extracting or uploading. Confirm the new `.htaccess` is in the site's root, beside `index.html` and `api.php`—not in a nested ZIP folder. Its compatibility rules deliberately rewrite only *missing* old hashed assets; a previous rule could redirect an existing current JavaScript asset to a deleted bundle and leave the startup card visible forever.

This release also has an independent recovery loader. If a stale HTML page asks for a removed bundle, it tries the stable current entry automatically after roughly 10 seconds. However, `?reload=1` and clearing browser cache cannot repair an old server-side `.htaccess`; replacing the hidden file and the full asset directory is required.

The adapter searches several parent directories for `/home/CPANEL_USER/cardinal-private.php`, so it also works when this site is extracted in a subdirectory. Environment variables take priority.

Example private file (replace all placeholders only on the host):

```php
<?php
return [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'YOUR_EXISTING_CARDINAL_DATABASE',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_password' => 'YOUR_DATABASE_PASSWORD',
];
```

## Safety notes

- Do not use a real credential in `config.php`, `.htaccess`, frontend files, or a ZIP archive. `.htaccess` cannot make a copied backup/archive secret safe.
- The browser never connects directly to MySQL. PHP owns the PDO connection and all mutations run inside database transactions.
- Cookies are `HttpOnly`, `SameSite=Lax`, and scoped to the extracted directory. Each account has exactly one active web session: a later successful login atomically replaces the former session, which is removed on its next heartbeat or API request.
- The one-session lease is a locked, opaque, 12-hour server-side record outside `public_html`; it does not add or modify any database column/table. A logout from the old browser can never clear the newer browser's lease.
- If the site gives HTTP 500, select a PHP version with the extensions above and inspect the hosting error log. API errors intentionally never reveal database credentials or DSNs.

## Quick checks after deployment

- `/api.php?route=health` should return JSON with `ok: true`.
- In production it should show `demoMode: false`.
- Create a test avatar or sign in with a pre-existing saved account. On Android, the four directional buttons remain above world decorations and respond to both pointer and touch events.
- Confirm the existing server 5 row is present in `servers`; `INSERT IGNORE` makes repeated requests safe.

No credentials used during development are contained in this archive.
