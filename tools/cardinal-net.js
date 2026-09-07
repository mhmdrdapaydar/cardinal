/*
 * Cardinal — realtime client: presence sync, chat, online roster.
 * =============================================================================
 * Loaded by index.html as a plain script, deliberately OUTSIDE the game bundle:
 * nothing here can break the React tree, and the file can be edited without
 * rebuilding anything.
 *
 * It talks only to realtime.php, which has its own SQLite file and no access to
 * the game database. It reads api.php?route=me for display identity (name,
 * class, floor, location, cursor colour) and never writes to it.
 *
 * Local position comes for free: the shipped camera controller already publishes
 * it on the canvas every frame —
 *     canvas.dataset.cardinalAvatar  = "x,z"
 *     canvas.dataset.cardinalHeading = "yaw"
 * so no hook into the render loop is needed.
 *
 * Remote players are handed to the 3D layer through window.__cardinalPeers.
 * If the world chunk never picks them up, chat and the roster still work.
 */
(function () {
  "use strict";

  if (window.__cardinalNet) return;

  var SYNC_MS = 900;         // heartbeat; the server expires a player after 60s
  var CHAT_MS = 2600;        // chat poll while the window is open
  var CHAT_IDLE_MS = 9000;   // chat poll while it is collapsed
  var ME_MS = 20000;         // refresh display identity
  var MAX_CHARS = 240;

  var base = new URL("realtime.php", location.href).href;
  var apiBase = new URL("api.php", location.href).href;

  var state = {
    me: null,
    peers: [],
    lobby: 1,
    lobbies: 1,
    inRoom: 0,
    online: 0,
    lastChatId: 0,
    messages: [],
    open: false,
    unread: 0,
    available: true,
    reason: "",
  };
  window.__cardinalPeers = { list: [], at: 0, room: null };

  // ------------------------------------------------------------------ fetch
  function call(route, options) {
    var url = base + (base.indexOf("?") === -1 ? "?" : "&") + "route=" + route;
    if (options && options.query) url += "&" + options.query;
    return fetch(url, {
      method: options && options.body ? "POST" : "GET",
      credentials: "same-origin",
      headers: options && options.body ? { "Content-Type": "application/json" } : undefined,
      body: options && options.body ? JSON.stringify(options.body) : undefined,
      cache: "no-store",
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error("پاسخ سرویس قابل خواندن نبود.");
      }).then(function (payload) {
        if (!res.ok || !payload || payload.ok !== true) {
          var err = new Error((payload && payload.error) || ("خطای " + res.status));
          err.status = res.status;
          throw err;
        }
        return payload.data;
      });
    });
  }

  function readMe() {
    return fetch(apiBase + "?route=me", { credentials: "same-origin", cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (p) {
        if (!p || p.ok !== true || !p.data || !p.data.player) return null;
        var pl = p.data.player;
        return {
          id: pl.id,
          name: pl.name || "بازیکن",
          classId: Number(pl.classId) || 1,
          pkStatus: pl.pkStatus || "white",
          floor: Number(pl.currentFloor) || 1,
          location: pl.location === "wild" ? "wild" : "city",
        };
      })
      .catch(function () { return null; });
  }

  // ------------------------------------------------------- local position
  function localTransform() {
    var canvas = document.querySelector(".world canvas");
    if (!canvas) return null;
    var raw = canvas.dataset.cardinalAvatar;
    if (!raw) return null;
    var parts = raw.split(",");
    var x = parseFloat(parts[0]);
    var z = parseFloat(parts[1]);
    if (!isFinite(x) || !isFinite(z)) return null;
    var yaw = parseFloat(canvas.dataset.cardinalHeading);
    return { x: x, z: z, yaw: isFinite(yaw) ? yaw : 0 };
  }

  var lastPos = { x: 0, z: 0 };
  function isMoving(t) {
    var moved = Math.hypot(t.x - lastPos.x, t.z - lastPos.z) > 0.02;
    lastPos.x = t.x; lastPos.z = t.z;
    return moved;
  }

  // ------------------------------------------------------------------ sync
  var syncTimer = null;
  function sync() {
    if (!state.me || !state.available) return;
    var t = localTransform();
    if (!t) return;
    call("sync", {
      body: {
        name: state.me.name,
        classId: state.me.classId,
        pkStatus: state.me.pkStatus,
        floor: state.me.floor,
        location: state.me.location,
        x: t.x, z: t.z, yaw: t.yaw,
        moving: isMoving(t),
      },
    }).then(function (data) {
      state.peers = data.players || [];
      state.lobby = data.lobby;
      state.lobbies = data.lobbies;
      state.inRoom = data.inRoom;
      state.online = data.online;
      window.__cardinalPeers = { list: state.peers, at: performance.now(), room: data.room };
      renderRoster();
    }).catch(function (err) {
      if (err.status === 503) { state.available = false; state.reason = err.message; renderRoster(); }
    });
  }

  // ------------------------------------------------------------------ chat
  function pollChat() {
    if (!state.me || !state.available) return;
    call("chat", state.lastChatId ? { query: "after=" + state.lastChatId } : null)
      .then(function (data) {
        var incoming = data.messages || [];
        if (!incoming.length) return;
        if (state.lastChatId === 0) state.messages = incoming;
        else {
          state.messages = state.messages.concat(incoming);
          if (state.messages.length > 100) state.messages = state.messages.slice(-100);
          if (!state.open) {
            for (var i = 0; i < incoming.length; i++) if (!incoming[i].self) state.unread++;
          }
        }
        state.lastChatId = state.messages[state.messages.length - 1].id;
        renderChat();
      })
      .catch(function () {});
  }

  function send(body) {
    if (!state.me) return Promise.reject(new Error("no identity"));
    return call("chat/send", {
      body: { body: body, name: state.me.name, classId: state.me.classId, pkStatus: state.me.pkStatus },
    }).then(function () { pollChat(); });
  }

  // ---------------------------------------------------------------- markup
  var el = {};
  function build() {
    var root = document.createElement("div");
    root.className = "cnet";
    root.setAttribute("data-camera-ignore", "");   // keeps camera drag off the panel
    root.innerHTML =
      '<button class="cnet__tab" type="button">' +
        '<span class="cnet__tab-icon">▣</span>' +
        '<span class="cnet__tab-label">گفتگوی قلمرو</span>' +
        '<span class="cnet__badge" hidden>0</span>' +
      '</button>' +
      '<section class="cnet__panel" hidden>' +
        '<header class="cnet__head">' +
          '<span class="cnet__title">گفتگوی قلمرو</span>' +
          '<span class="cnet__meta"></span>' +
          '<button class="cnet__close" type="button" aria-label="بستن">✕</button>' +
        '</header>' +
        '<div class="cnet__log" role="log"></div>' +
        '<form class="cnet__form">' +
          '<input class="cnet__input" type="text" maxlength="' + MAX_CHARS + '" ' +
                 'placeholder="پیام به همهٔ بازیکنان…" autocomplete="off" />' +
          '<button class="cnet__send" type="submit">ارسال</button>' +
        '</form>' +
        '<small class="cnet__note"></small>' +
      '</section>';
    document.body.appendChild(root);

    el.root = root;
    el.tab = root.querySelector(".cnet__tab");
    el.badge = root.querySelector(".cnet__badge");
    el.panel = root.querySelector(".cnet__panel");
    el.meta = root.querySelector(".cnet__meta");
    el.log = root.querySelector(".cnet__log");
    el.form = root.querySelector(".cnet__form");
    el.input = root.querySelector(".cnet__input");
    el.note = root.querySelector(".cnet__note");

    el.tab.addEventListener("click", function () { toggle(true); });
    root.querySelector(".cnet__close").addEventListener("click", function () { toggle(false); });
    el.form.addEventListener("submit", function (event) {
      event.preventDefault();
      var text = el.input.value.trim();
      if (!text) return;
      el.input.value = "";
      el.note.textContent = "";
      send(text).catch(function (err) { el.note.textContent = err.message; });
    });
    // Movement keys are captured globally; do not let them leak while typing.
    el.input.addEventListener("keydown", function (e) { e.stopPropagation(); });
  }

  function toggle(open) {
    state.open = open;
    el.panel.hidden = !open;
    el.tab.hidden = open;
    if (open) {
      state.unread = 0;
      el.badge.hidden = true;
      pollChat();
      el.log.scrollTop = el.log.scrollHeight;
      el.input.focus();
    }
  }

  function cursorClass(pk) {
    if (pk === "orange") return "cnet--orange";
    if (pk === "red") return "cnet--red";
    return "cnet--green";
  }

  function renderChat() {
    if (!el.log) return;
    var stuck = el.log.scrollHeight - el.log.scrollTop - el.log.clientHeight < 40;
    var html = "";
    for (var i = 0; i < state.messages.length; i++) {
      var m = state.messages[i];
      var when = new Date(m.at * 1000);
      html +=
        '<p class="cnet__line' + (m.self ? " cnet__line--self" : "") + '">' +
          '<i class="cnet__dot ' + cursorClass(m.pkStatus) + '"></i>' +
          '<b>' + escapeHtml(m.name) + '</b>' +
          '<span>' + escapeHtml(m.body) + '</span>' +
          '<time>' + String(when.getHours()).padStart(2, "0") + ":" +
                     String(when.getMinutes()).padStart(2, "0") + '</time>' +
        '</p>';
    }
    el.log.innerHTML = html || '<p class="cnet__empty">هنوز پیامی نیست. اولین نفر باشید.</p>';
    if (stuck) el.log.scrollTop = el.log.scrollHeight;
    if (state.unread > 0) { el.badge.hidden = false; el.badge.textContent = String(state.unread); }
  }

  function renderRoster() {
    if (!el.meta) return;
    if (!state.available) {
      el.meta.textContent = "آفلاین";
      el.note.textContent = state.reason;
      return;
    }
    el.meta.textContent =
      state.inRoom + " نفر اینجا · لابی " + state.lobby + " از " + state.lobbies +
      " · " + state.online + " آنلاین";
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  // ------------------------------------------------------------------ boot
  function start() {
    build();
    renderChat();
    renderRoster();

    readMe().then(function (me) {
      state.me = me;
      if (!me) return;      // signed out: leave the panel dormant
      sync();
      pollChat();
      syncTimer = setInterval(sync, SYNC_MS);
      setInterval(function () { pollChat(); }, CHAT_MS);
      setInterval(function () {
        readMe().then(function (fresh) { if (fresh) state.me = fresh; });
      }, ME_MS);
    });

    // Stop the heartbeat while the tab is hidden; the server expires us after
    // 60s and other players stop seeing a ghost standing still.
    document.addEventListener("visibilitychange", function () {
      if (document.hidden) {
        if (syncTimer) { clearInterval(syncTimer); syncTimer = null; }
      } else if (!syncTimer && state.me) {
        sync();
        syncTimer = setInterval(sync, SYNC_MS);
      }
    });
  }

  window.__cardinalNet = { state: state, sync: sync, send: send, toggle: toggle };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
  else start();
})();
