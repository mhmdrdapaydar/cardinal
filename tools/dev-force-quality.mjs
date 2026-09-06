/**
 * Cardinal — build a DEV-ONLY preview root with a forced quality tier.
 *
 * The sandbox renders WebGL through SwiftShader (software), so the shipped
 * adaptive-quality logic always collapses to the "low" tier and the cinematic
 * layer is hidden (`.world-cinematic-shimmer--low { display: none }`). That
 * makes it impossible to review desktop-tier graphics.
 *
 * This copies the package to a scratch directory and, IN THAT COPY ONLY,
 * lets `?q=high|balanced|low` pin the tier. The shipped assets/ are never
 * modified by this script.
 *
 *   node tools/dev-force-quality.mjs /tmp/preview-root
 */

import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const OUT = path.resolve(process.argv[2] || "/tmp/preview-root");

const OVERRIDE =
  'typeof window<"u"&&/[?&]q=(high|balanced|low)/.test(location.search)' +
  '?/[?&]q=(high|balanced|low)/.exec(location.search)[1]:';

const ANCHOR = 'R=i||h>0?"low":v&&D==="high"?"balanced":D';
const REPLACEMENT = `R=${OVERRIDE}(${ANCHOR.slice(2)})`;

// The modern world chunk is content-hashed, so discover it rather than
// hardcoding this release's filename.
const worldChunks = (await fs.readdir(path.join(REPO, "assets")))
  .filter((f) => /^WorldScene-(?!legacy)[A-Za-z0-9_-]+\.js$/.test(f))
  .concat("cardinal-current-world.js")
  .map((f) => ["assets/" + f, ANCHOR, REPLACEMENT]);

const PATCHES = worldChunks;

await fs.rm(OUT, { recursive: true, force: true });
await fs.cp(REPO, OUT, {
  recursive: true,
  filter: (src) => !/(^|\/)(\.git|tools|node_modules)($|\/)/.test(src.slice(REPO.length)),
});

for (const [file, search, replace] of PATCHES) {
  const target = path.join(OUT, file);
  let source;
  try {
    source = await fs.readFile(target, "utf8");
  } catch {
    console.log(`skip (absent): ${file}`);
    continue;
  }
  const count = source.split(search).length - 1;
  if (count !== 1) {
    console.error(`ABORT: ${file} matched the quality anchor ${count} times, expected 1`);
    process.exit(1);
  }
  await fs.writeFile(target, source.replace(search, replace));
  console.log(`forced-quality hook -> ${file}`);
}

console.log(`preview root ready at ${OUT}  (append ?q=high to the URL)`);
