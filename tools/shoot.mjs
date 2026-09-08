/**
 * Cardinal — LOCAL screenshot harness (development only, never uploaded).
 *
 * Drives the static build through the tools/dev-server.mjs mock API with a
 * headless Chromium + SwiftShader so the 3D world can actually be rendered and
 * compared before/after a graphics change.
 *
 *   node tools/shoot.mjs <outputDir> [baseUrl]
 */

import fs from "node:fs/promises";
import path from "node:path";
import puppeteer from "/home/user/.cache/pw/node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js";

const OUT = path.resolve(process.argv[2] || "shots");
const BASE = process.argv[3] || "http://127.0.0.1:8080/";
const CHROME = "/home/user/.cache/pw/bin/chromium";

const ALL_VIEWPORTS = {
  desktop: { width: 1440, height: 900, isMobile: false, deviceScaleFactor: 1 },
  mobile: { width: 412, height: 892, isMobile: true, deviceScaleFactor: 2, hasTouch: true },
};
// ONLY=desktop limits a run; NOFX=1 disables the cardinal-fx overlay so the
// engine-side changes can be judged on their own.
const VIEWPORTS = process.env.ONLY
  ? { [process.env.ONLY]: ALL_VIEWPORTS[process.env.ONLY] }
  : ALL_VIEWPORTS;
const NOFX = process.env.NOFX === "1";

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function shoot(browser, label, viewport) {
  const page = await browser.newPage();
  await page.setViewport(viewport);
  if (viewport.isMobile) {
    await page.setUserAgent(
      "Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36"
    );
  }

  const errors = [];
  page.on("pageerror", (e) => errors.push(String(e).slice(0, 200)));
  page.on("console", (m) => { if (m.type() === "error") errors.push(m.text().slice(0, 200)); });

  await page.goto(BASE, { waitUntil: "domcontentloaded", timeout: 90000 });
  if (NOFX) {
    await page.addStyleTag({
      content:
        ".world::before,.world::after{display:none !important}" +
        ".world-cinematic-shimmer{display:none !important}" +
        ".world canvas{filter:none !important}",
    });
  }
  await page.waitForSelector(".auth-screen, .game-shell", { timeout: 40000 });

  // The mock API always reports an active session, so the auth screen only
  // shows when a real logged-out backend is behind it.
  if (await page.$(".auth-screen")) {
    await sleep(1200);
    await page.screenshot({ path: path.join(OUT, `${label}-01-auth.png`) });
    await page.evaluate(() => {
      const link = [...document.querySelectorAll("button, a")].find(
        (n) => n.className && String(n.className).includes("demo-link")
      );
      link?.click();
    });
  }

  try {
    await page.waitForSelector(".game-shell", { timeout: 30000 });
    await page.waitForSelector(".world canvas", { timeout: 45000 });
    // let textures resolve, the boot overlay clear and the scene settle
    await page
      .waitForFunction(() => !document.querySelector(".world-boot-overlay"), { timeout: 90000 })
      .catch(() => console.log(`  ${label}: boot overlay never cleared`));
    await sleep(6000);

    await page.screenshot({ path: path.join(OUT, `${label}-02-city.png`) });

    // world only, no HUD chrome -- the cleanest before/after comparison
    const world = await page.$(".world");
    if (world) await world.screenshot({ path: path.join(OUT, `${label}-03-world-only.png`) });

    const info = await page.evaluate(() => {
      const c = document.querySelector(".world canvas");
      return {
        quality: c?.dataset?.cardinalQuality ?? null,
        frameMs: c?.dataset?.cardinalFrameMs ?? null,
        canvas: c ? `${c.width}x${c.height}` : null,
        dpr: window.devicePixelRatio,
        hiTex: window.__cardinalHiTexture ?? "n/a",
      };
    });
    console.log(
      `  ${label}: quality=${info.quality} frame=${info.frameMs}ms canvas=${info.canvas} dpr=${info.dpr} hiTex=${info.hiTex}`
    );

    // ---- wild realm (second biome) via the sidebar action
    const left = await page.evaluate(() => {
      const b = [...document.querySelectorAll("button")].find((n) =>
        String(n.className).includes("action-button--travel")
      );
      if (!b) return false;
      b.click();
      return true;
    });
    if (left) {
      await sleep(9000);
      const w = await page.$(".world");
      if (w) await w.screenshot({ path: path.join(OUT, `${label}-04-wild.png`) });
    }
  } catch (e) {
    await page.screenshot({ path: path.join(OUT, `${label}-02-world-FAILED.png`) });
    console.log(`  ${label}: world capture failed -> ${String(e).slice(0, 140)}`);
  }

  if (errors.length) console.log(`  ${label}: ${errors.length} console error(s): ${errors[0]}`);
  await page.close();
}

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: "shell",
  args: [
    "--no-sandbox",
    "--disable-setuid-sandbox",
    "--disable-dev-shm-usage",
    "--use-gl=angle",
    "--use-angle=swiftshader",
    "--enable-unsafe-swiftshader",
    "--enable-webgl",
    "--ignore-gpu-blocklist",
    "--hide-scrollbars",
    "--font-render-hinting=none",
  ],
});

await fs.mkdir(OUT, { recursive: true });
for (const [label, viewport] of Object.entries(VIEWPORTS)) {
  await shoot(browser, label, viewport);
}
await browser.close();
console.log(`screenshots -> ${OUT}`);
