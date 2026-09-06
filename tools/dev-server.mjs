/**
 * Cardinal — LOCAL DEVELOPMENT PREVIEW SERVER (not part of the upload package).
 *
 * The production package is PHP. This sandbox has no PHP, so this file serves
 * the static build and answers `api.php?route=...` with the same JSON envelope
 * shape as the real `api.php` demo mode, using the fixture values from
 * lib/DemoGame.php. It exists purely so the presentation layer can be rendered
 * and screenshotted while iterating on graphics.
 *
 * It implements NO game rule. Do not deploy it. Do not upload tools/ to the
 * web host.
 *
 *   node tools/dev-server.mjs [port]
 */

import http from "node:http";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const PORT = Number(process.argv[2] || 8080);
const ROOT = path.resolve(
  process.argv[3] || process.env.CARDINAL_ROOT || path.join(path.dirname(fileURLToPath(import.meta.url)), "..")
);
// Set CARDINAL_LOGGED_OUT=1 to exercise the login/registration screen instead
// of dropping straight into the world.
const LOGGED_OUT = process.env.CARDINAL_LOGGED_OUT === "1";

const MIME = {
  ".html": "text/html; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".png": "image/png",
  ".webp": "image/webp",
  ".svg": "image/svg+xml",
};

// ---------------------------------------------------------------- fixtures
const player = () => ({
  id: 500001,
  name: "قهرمان پیش‌نمایش",
  gender: "male",
  classId: 1,
  className: "Warrior",
  coins: 12500,
  level: 24,
  experience: 4820,
  nextLevelExperience: 7200,
  currentFloor: 3,
  lastFloorUnlocked: 3,
  location: state.location,
  kills: 12,
  deaths: 2,
  monstersKilled: 341,
  pkStatus: "white",
  bagLevel: 2,
  bagSlots: { used: 7, max: 20 },
  battlePower: 1860,
  noble: true,
  nobleExpiryDate: "2026-12-01",
  referralCode: "WEBDEMO5",
  referralCount: 3,
  accountSaved: true,
  cooldowns: { hunt: null, dungeon: null, cityEntry: null, boss: null, teamBoss: null },
});

const state = { location: "city" };
const session = { active: !LOGGED_OUT };

const ROUTES = {
  health: () => ({ serverId: 5, demoMode: true, timestamp: new Date().toISOString() }),
  session: () => ({ active: true }),
  me: () => ({ player: player(), demoMode: true }),
  "auth/demo": () => ({ player: player() }),
  "auth/login": () => ({ player: player() }),
  "auth/register": () => ({ player: player() }),
  "auth/logout": () => ({}),
  equipment: () => ({
    weapon: { itemId: 1, instanceId: null, name: "شمشیر عیار نوآموز", power: 50 },
    armor: { itemId: 2, instanceId: null, name: "زره چرمی کهنه‌سرباز", power: 30 },
    pet: { itemId: null, name: "بدون حیوان همراه" },
  }),
  inventory: () => ({
    player: player(),
    items: [
      { itemId: 1, itemName: "شمشیر عیار نوآموز", description: "سلاح تیز و برنده طبقه اول", typeId: 1, typeName: "سلاح", quantity: 1, priceCoins: 150, equipped: true },
      { itemId: 3, itemName: "کریستال تلپورت طبقات", description: "آیتم جادویی برای جابه‌جایی سریع", typeId: 3, typeName: "مصرفی", quantity: 2, priceCoins: 100, equipped: false },
    ],
    miniItems: [{ miniItemId: 1, name: "سنگِ مهتاب", description: "ماده اولیه کمیاب", quantity: 3 }],
    craftedItems: [],
  }),
  shop: () => ({
    coins: 12500,
    floor: 3,
    items: [
      { item_id: 1, item_name: "شمشیر عیار نوآموز", type_id: 1, description: "سلاح تیز و برنده طبقه اول", price_coins: 150 },
      { item_id: 2, item_name: "زره چرمی کهنه‌سرباز", type_id: 2, description: "پوشش مقاوم در برابر ضربات ماب‌ها", price_coins: 200 },
      { item_id: 3, item_name: "کریستال تلپورت طبقات", type_id: 3, description: "آیتم جادویی برای جابه‌جایی سریع", price_coins: 100 },
      { item_id: 5, item_name: "تخم اژدهای کوچک", type_id: 4, description: "حیوان همراه افسانه‌ای", price_coins: 500 },
    ],
    crafted: [{ craft_item_id: 1, item_type: "weapon", item_name: "تیغه تمرینی", floor: 3, base_power: 80, base_price: 500, price_type: "coins" }],
  }),
  boss: () => ({ floor: 3, name: "نگهبان سنگی طبقه", description: "غولی عظیم با ضربه‌های سنگین.", level: 400, battlePower: 4000, defeated: false }),
  quests: () => ({ claimed: false, quests: [{ id: 1, name: "شکار ۱۰ ماب", description: "ده هیولا را در منطقه ناامن شکست دهید.", progress: 4, target: 10, rewards: { coins: 500, xp: 300 } }] }),
  party: () => null,
  social: () => ({ partner: null, requests: [] }),
  guild: () => null,
  crafting: () => ({ collected: [], plans: [], queues: [], crafted: [] }),
  noble: () => ({ active: true, expiry: "2026-12-01", owned: [], offer: { id: 1, number: 1, name: "نشان اشراف فصل اول", description: "دسترسی به سیاه‌چال و امتیازهای ویژه.", price: 75000, validUntil: null } }),
  achievements: () => [{ number: 1, name: "نشان پیش‌نمایش کاردینال", description: "نمونه نمایشی دستاورد فصلی." }],
  notifications: () => [],
};

function leaderboard(kind, page) {
  const titles = { players: "برترین بازیکنان (بر اساس سطح)", killers: "برترین قاتلان", guilds: "برترین گیلدها", groups: "برترین گروه‌ها" };
  const rows = {
    players: [
      { rank: 1, name: "آلفا", level: 42, experience: 54300 },
      { rank: 2, name: "سایه‌نقره‌ای", level: 35, experience: 22200 },
      { rank: 3, name: "قهرمان پیش‌نمایش", level: 24, experience: 4820 },
    ],
    killers: [{ rank: 1, name: "شکارچی‌سرخ", kills: 84, pkStatus: "black" }],
    guilds: [{ rank: 1, name: "پیمان سپیده‌دم", leader: "فرمانده سپیده", members: 18, totalLevel: 238 }],
    groups: [{ rank: 1, name: "گروه کاردینال", members: 103, totalLevel: 996 }],
  };
  return { title: titles[kind] || "برترین‌ها", page, totalPages: 1, rows: rows[kind] || [] };
}

function action(name) {
  if (name === "exit-city") { state.location = "wild"; return { kind: "warning", title: "خروج از شهر", text: "شما وارد منطقه ناامن شدید.", refresh: true }; }
  if (name === "return-city" || name === "force-return-city") { state.location = "city"; return { kind: "success", title: "ورود به منطقه امن", text: "به شهر بازگشتید.", refresh: true }; }
  return { kind: "info", title: "پیش‌نمایش", text: "این فرمان در پیش‌نمایش شبیه‌سازی می‌شود.", refresh: false };
}

// ------------------------------------------------------------------ server
const send = (res, status, body, type = "application/json; charset=utf-8") => {
  res.writeHead(status, { "Content-Type": type, "Cache-Control": "no-store" });
  res.end(body);
};

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);

  if (url.pathname.endsWith("/api.php")) {
    const route = (url.searchParams.get("route") || "").replace(/^\/+|\/+$/g, "");
    let body = "";
    for await (const chunk of req) body += chunk;

    let data;
    const lb = /^leaderboard\/(players|killers|guilds|groups)$/.exec(route);
    const act = /^action\/([a-z-]{2,48})$/.exec(route);
    if ((route === "me" || route === "session") && LOGGED_OUT && !session.active) {
      return send(res, 401, JSON.stringify({ ok: false, error: "نشست شما پایان یافته است. دوباره وارد شوید." }));
    }
    if (route.startsWith("auth/") && route !== "auth/logout") { session.active = true; state.location = "city"; }
    if (route === "auth/logout") session.active = false;

    if (ROUTES[route]) data = ROUTES[route]();
    else if (lb) data = leaderboard(lb[1], Number(url.searchParams.get("page") || 1));
    else if (act) data = action(act[1]);
    else if (route.startsWith("account/")) data = { kind: "success", title: "پیش‌نمایش", text: "شبیه‌سازی شد.", refresh: false };
    else return send(res, 404, JSON.stringify({ ok: false, error: "مسیر API پیدا نشد." }));

    return send(res, 200, JSON.stringify({ ok: true, data }));
  }

  let file = decodeURIComponent(url.pathname);
  if (file === "/" || file === "") file = "/index.html";
  const target = path.join(ROOT, path.normalize(file).replace(/^(\.\.[/\\])+/, ""));
  if (!target.startsWith(ROOT)) return send(res, 403, "forbidden", "text/plain");

  try {
    const data = await fs.readFile(target);
    res.writeHead(200, {
      "Content-Type": MIME[path.extname(target).toLowerCase()] || "application/octet-stream",
      "Cache-Control": "no-store",
    });
    res.end(data);
  } catch {
    send(res, 404, "not found", "text/plain");
  }
});

server.listen(PORT, "0.0.0.0", () => {
  console.log(`Cardinal dev preview (mock API) on http://0.0.0.0:${PORT}`);
});
