// console-tabs.js
// One tabbed console window mixing three client types:
//   telnet/serial -> xterm.js          (ws -> telnet_ws_bridge_telnetlib3.py)
//   vnc           -> noVNC RFB          (ws -> websockify)            [Slice 2]
//   rdp           -> guacamole-common.js(ws -> guacamole-lite -> guacd)[Slice 3]
//
// Design notes baked in (the gotchas that bite everyone):
//   * Clients are created lazily on first activation, then KEPT ALIVE on hide
//     (display:none, never destroyed) so the session survives tab switches.
//   * xterm is re-fit AFTER becoming visible (a hidden term measures 0x0).
//   * Keyboard is scoped per pane (Guacamole.Keyboard bound to the focusable
//     pane, xterm/noVNC focused on show) so background tabs don't eat keys.
//
// OFFLINE APPLIANCE: nothing is pulled from a CDN. The clients are vendored
// locally and loaded as classic scripts BEFORE this module, so they appear as
// globals:
//   xterm.js     -> window.Terminal          (vendored, Slice 1)
//   addon-fit.js -> window.FitAddon          (vendored, Slice 1)
//   noVNC        -> window.RFB               (vendored in Slice 2)
//   guacamole    -> window.Guacamole         (vendored in Slice 3)

import { CISCO_RULES } from './vendor/securecrt-cisco-rules.js';

const wsUrl = (path) => {
  const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
  return `${proto}//${location.host}${path}`;
};

// ── Scrollback persistence across a browser refresh ─────────────────────────
// The console transport has no server-side session (web-console/backend's
// console_mux.py dials a fresh telnet/PTY connection per WebSocket connect —
// see docs), so a refresh normally opens a brand-new connection and gets a
// blank terminal. This is a client-side-only ILLUSION of continuity: persist
// the tail of each tab's existing (raw, pre-highlight) logBuf to
// sessionStorage, and replay it into the fresh xterm instance before the live
// WS reconnects. Not true live continuity — there's a small gap between the
// last autosave and the live stream resuming, and it's bounded by a TTL so a
// stale sessionStorage entry (e.g. a browser's "restore previous session"
// feature) can't resurrect ancient output.
//
// Keyed by lab id + node id/type/second. Node ids are allocated PER LAB
// (getFreeNodeId() in __lab.php) and reused, so "node 1" in one lab is a
// completely different device from "node 1" in the next — without the lab
// id, switching labs in the same tab within the TTL window would replay a
// PREVIOUS lab's real terminal text into a same-numbered node in the new
// one.
//
// currentLabId() is a synchronous getter over a module-level cache filled by
// a fire-and-forget GET below — NOT read off window.parent.lab / window.lab:
// that legacy global is not reliably populated on the canvas-flow lab page
// (canvas-flow/api-seed.js's normalizeLabinfo() deliberately keeps only
// {lock, editable} from the topology response and drops `id`), so it can't
// be trusted here. This module is a static import at the top of
// console-init.js, so the fetch below fires as early as this window's JS can
// run — well before any node console can be mounted (that needs at least a
// user click, and this console window's own node list first). Until it
// resolves, or if it fails, currentLabId() returns null and scrollbackKey()
// skips caching (fail closed — no persistence rather than a wrongly-scoped
// entry that could replay a different lab's real terminal output). Unlike
// the open-window state cache in pnetlab-webconsole.js, a slow/failed fetch
// here has no destructive side effect (nothing gets overwritten) — worst
// case is one missed scrollback replay, self-correcting on the next connect
// in this window once _labId has resolved — so this module doesn't need
// pnetlab-webconsole.js's bounded-wait treatment for the same race.
//
// Residual, NOT fixed here: /api/labs/session/info resolves against the
// account's server-side "current lab" pointer (users.lab_session), shared
// across every tab for that account, not pinned per browser tab — a second
// tab switching labs mid-fetch can resolve this to the wrong lab. Not a
// regression from this fix: token_mint.php's open_user_lab(), which
// authorizes the actual live console connection, reads that same mutable
// pointer freshly per request (not PHP-session-cached) at connect/reconnect
// time, so the live console has the same underlying exposure today — but
// it's not a byte-identical race window: this fetch and a token mint are
// two INDEPENDENT requests that can sample the pointer at different times,
// so the cache's chosen lab id and the live connection's actual target can
// disagree without a single shared instant of drift. Closing it properly
// means deriving the cache's lab id from the token-mint response (the same
// resolution point that gates the live
// connection) instead of a separate fetch — out of scope for this fix.
const SCROLLBACK_PREFIX = 'pnq_wc_scrollback_v1:';
const SCROLLBACK_TTL_MS = 15 * 60 * 1000;
const SCROLLBACK_PERSIST_CAP = 180000;
let _labId = null;
fetch('/api/labs/session/info', { credentials: 'same-origin', cache: 'no-store' })
  .then((r) => (r.ok ? r.json() : null))
  .then((j) => {
    if (j && j.status === 'success' && j.data && j.data.id) _labId = String(j.data.id);
  })
  .catch(() => {});
function currentLabId() {
  return _labId;
}
function scrollbackKey(node) {
  const labId = currentLabId();
  if (!labId) return null;
  return `${SCROLLBACK_PREFIX}${labId}:${node.id}:${node.type}:${node.second ? 1 : 0}`;
}
// Drop scrollback entries left behind by OTHER labs visited earlier in this
// same tab (each lab switch is a full page reload, so _labId resets — a
// naive per-lab key scheme would otherwise accumulate one ~180KB entry per
// node/type ever opened in ANY lab across the tab's lifetime; a training
// platform's normal usage pattern — many labs touched in one session — makes
// that a real growth path, not a hypothetical one). Keeps only entries for
// the current lab; TTL already handles staleness within it.
function pruneOtherLabScrollback(currentLabIdStr) {
  try {
    for (let i = sessionStorage.length - 1; i >= 0; i--) {
      const k = sessionStorage.key(i);
      if (!k || k.indexOf(SCROLLBACK_PREFIX) !== 0) continue;
      const rest = k.slice(SCROLLBACK_PREFIX.length);
      const labId = rest.slice(0, rest.indexOf(':'));
      if (labId && labId !== currentLabIdStr) sessionStorage.removeItem(k);
    }
  } catch (_) {}
}
function loadScrollback(node) {
  try {
    const key = scrollbackKey(node);
    if (!key) return null;
    const raw = sessionStorage.getItem(key);
    if (!raw) return null;
    const obj = JSON.parse(raw);
    if (!obj || typeof obj.text !== 'string' || !obj.text || !obj.ts) return null;
    if (Date.now() - obj.ts > SCROLLBACK_TTL_MS) return null;
    return obj.text;
  } catch (_) { return null; }
}
function saveScrollback(node, text) {
  try {
    const labId = currentLabId();
    if (!labId) return;
    const key = `${SCROLLBACK_PREFIX}${labId}:${node.id}:${node.type}:${node.second ? 1 : 0}`;
    if (!text) { sessionStorage.removeItem(key); return; }
    // Prune BEFORE writing, not after: if stale other-lab entries already
    // exhausted the quota, a write-then-prune order throws out of setItem
    // and the catch below swallows it before prune ever runs — freeing
    // nothing, wedging every future save behind the same stale entries.
    pruneOtherLabScrollback(labId);
    sessionStorage.setItem(key, JSON.stringify({
      text: text.slice(-SCROLLBACK_PERSIST_CAP), ts: Date.now(),
    }));
  } catch (_) { /* quota exceeded or storage disabled — best-effort only */ }
}
// One page-level flush registry instead of a listener per tab — every mounted
// telnet tab adds its flush fn on mount, removes it on dispose. Catches the
// final state even if the last debounce window (see scheduleScrollbackSave
// below) hadn't fired yet.
const scrollbackFlushers = new Set();
function flushAllScrollback() { scrollbackFlushers.forEach((fn) => { try { fn(); } catch (_) {} }); }
window.addEventListener('beforeunload', flushAllScrollback);
window.addEventListener('pagehide', flushAllScrollback);

// ── HTML5 <-> native console-mode signal ────────────────────────────────────
// The sidebar's "HTML5 Console" toggle (pnetlab-webconsole.js) persists its
// pref to localStorage['pnq_html5_console'] and, on the topology document,
// fires 'pnq:app-updatedata'. This all-nodes window is either that SAME
// document (embedded) or a popped-out window (MODE_KEY 'tab'), so both
// channels are watched: 'storage' only reaches OTHER windows/tabs, the custom
// event only reaches the SAME document. Whichever fires, every mounted telnet
// tab's WS is released while native mode is active — vpcs/netprobe-backed
// nodes accept exactly one client, and a background all-nodes socket left
// open here otherwise starves a native (SecureCRT-style) console for that
// same node forever, even across a node restart (fixes the perpetual
// "console busy" case).
const HTML5_KEY = 'pnq_html5_console';
function html5Enabled() {
  try {
    if (localStorage.getItem(HTML5_KEY) === '0') return false;
  } catch (_) { /* best-effort */ }
  return true;
}
const nativeModeListeners = new Set();
let lastHtml5Enabled = html5Enabled();
function announceHtml5Change() {
  const on = html5Enabled();
  if (on === lastHtml5Enabled) return;
  lastHtml5Enabled = on;
  nativeModeListeners.forEach((fn) => { try { fn(on); } catch (_) {} });
}
window.addEventListener('storage', (e) => { if (e.key === HTML5_KEY) announceHtml5Change(); });
document.addEventListener('pnq:app-updatedata', (e) => {
  if (e && e.detail && e.detail.update === 'html5') announceHtml5Change();
});

// SecureCRT-style keyword highlighting for the telnet/serial lane. Given one
// terminal line (no newline), paint matched spans with 24-bit SGR colours, in
// the .ini's priority order (first rule to claim a character wins). Returns the
// line unchanged when nothing matches.
const isWordChar = (ch) => {
  const c = ch.charCodeAt(0);
  return (c >= 48 && c <= 57) || (c >= 65 && c <= 90) || (c >= 97 && c <= 122) || c === 95;
};
function colorizeLine(line) {
  const n = line.length;
  if (!n || n > 1000) return line;                 // skip blanks / pathological lines
  const owner = new Array(n).fill(-1);
  for (let ri = 0; ri < CISCO_RULES.length; ri++) {
    const re = CISCO_RULES[ri].re; re.lastIndex = 0;
    let m;
    while ((m = re.exec(line)) !== null) {
      const a = m.index, b = re.lastIndex;
      if (b === a) { re.lastIndex++; continue; }    // zero-width guard
      // SecureCRT matches keywords on WORD boundaries (symbols/spaces delimit
      // words — the .ini even has "work around symbol-delimited words" notes).
      // Reject a match that starts or ends inside a word so we never colour
      // fragments like "chro" in "synchronous" or "port" in "transport".
      if (a > 0 && isWordChar(line[a - 1]) && isWordChar(line[a])) continue;
      if (b < n && isWordChar(line[b]) && isWordChar(line[b - 1])) continue;
      for (let k = a; k < b; k++) if (owner[k] === -1) owner[k] = ri;
    }
  }
  let out = '', curRule = -1;
  for (let k = 0; k < n; k++) {
    if (owner[k] !== curRule) {
      if (curRule !== -1) out += '\x1b[39m';
      curRule = owner[k];
      if (curRule !== -1) { const c = CISCO_RULES[curRule]; out += `\x1b[38;2;${c.r};${c.g};${c.b}m`; }
    }
    out += line[k];
  }
  if (curRule !== -1) out += '\x1b[39m';
  return out;
}

// Line-buffered highlighter wrapping term.write. Plain CLI output (prompts,
// `show` commands) is recoloured in place via CR + clear-line; any line that
// carries a control/ESC sequence (device colours, full-screen apps like vi)
// switches to raw passthrough so we never corrupt cursor-addressed output. The
// no-newline prompt is recoloured live; toggling off reverts to raw writes.
function makeHighlighter(term, enabled) {
  let cur = '', drawn = '', ctrl = false;
  // Strip ANSI CSI escape sequences to get the visible character count of a
  // string. Used to calculate how many terminal rows `drawn` occupied so we
  // can emit the right number of cursor-up sequences before erasing.
  const visLen = (s) => s.replace(/\x1b\[[0-9;]*[A-Za-z]/g, '').length;
  const render = (commit) => {
    const desired = colorizeLine(cur);
    if (!commit && drawn && drawn === desired) return;  // already on screen
    let out = '';
    if (drawn) {
      // When a partial line wrapped past term.cols, \r alone only goes back to
      // the start of the LAST wrapped terminal row — leaving ghost copies of
      // earlier rows on screen.  Move the cursor up to the actual start first,
      // then erase from there to end of screen (\x1b[J covers all wrapped rows).
      // -1: a line of EXACTLY n*cols chars leaves the cursor in xterm's
      // deferred-wrap state on its last row, not on the next one.
      const cols = (term && term.cols) || 80;
      const vl = visLen(drawn);
      const linesUp = vl > 0 ? Math.floor((vl - 1) / cols) : 0;
      if (linesUp > 0) out += `\x1b[${linesUp}A`;   // cursor up N rows
      out += '\r\x1b[J';                              // CR + erase to end of screen
    }
    out += desired;
    if (commit) { out += '\r\n'; drawn = ''; } else { drawn = desired; }
    if (out) term.write(out);
  };
  return {
    get enabled() { return enabled; },
    set enabled(v) { enabled = !!v; cur = ''; drawn = ''; ctrl = false; },
    feed(text) {
      if (!enabled) { cur = ''; drawn = ''; ctrl = false; term.write(text); return; }
      let raw = '';                                  // passthrough run (ctrl mode)
      const flushRaw = () => { if (raw) { term.write(raw); raw = ''; } };
      for (let i = 0; i < text.length; i++) {
        const ch = text[i], code = text.charCodeAt(i);
        if (ch === '\n') {
          if (ctrl) { raw += ch; } else { flushRaw(); render(true); }
          cur = ''; drawn = ''; ctrl = false;
        }
        else if (ch === '\r') {
          if (ctrl) { raw += ch; } else { flushRaw(); render(false); term.write('\r'); }
          cur = ''; drawn = ''; ctrl = false;
        }
        else if (ctrl) { raw += ch; }
        else if (code === 0x1b || (code < 0x20 && code !== 0x09)) {
          // Control byte: this line goes raw. The colorized repaint already on
          // screen has identical VISIBLE chars (SGR codes are zero-width), so
          // the device's own cursor handling (BS-erase on history recall,
          // ESC sequences) replays correctly against it. Buffering these bytes
          // instead and re-running the cursor-up/erase repaint is what used to
          // wipe the screen: visLen() counts each \b as +1, so a few ↑/↓
          // recalls inflated `drawn` past cols and the repaint climbed to the
          // top row erasing everything (\x1b[J).
          flushRaw();
          render(false);                             // flush buffered plain tail
          ctrl = true;
          raw += ch;
        }
        else { cur += ch; }
      }
      flushRaw();
      if (!ctrl) render(false);
    },
  };
}

export class ConsoleTabs {
  /**
   * @param {HTMLElement} root  container the tab UI is built into
   * @param {object} opts        path overrides + token endpoint
   */
  constructor(root, opts = {}) {
    this.root = root;
    this.opts = Object.assign({
      telnetPath: '/telnet/',                 // -> telnet_ws_bridge_telnetlib3.py
      vncPath:    '/vnc/',                     // -> websockify          [Slice 2]
      guacPath:   '/guac/',                    // -> guacamole-lite      [Slice 3]
      shellPath:  '/shell/',                   // -> shell_ws_bridge.py (pty login) [admin]
      tokenUrl:   'token_mint.php',            // session-gated minter (relative)
    }, opts);
    this.tabs = new Map();        // nodeId -> record
    this.activeId = null;
    this._buildChrome();
  }

  _buildChrome() {
    this.root.classList.add('ctabs');
    this.bar   = document.createElement('div'); this.bar.className   = 'ctabs-bar';
    this.panes = document.createElement('div'); this.panes.className = 'ctabs-panes';
    this.root.append(this.bar, this.panes);
  }

  /** node = { id, name, type: 'telnet'|'vnc'|'rdp', isDocker?: boolean } */
  openNode(node) {
    if (this.tabs.has(node.id)) { this.activate(node.id); return; }

    const tabBtn = document.createElement('button');
    tabBtn.className = 'ctab';
    tabBtn.dataset.tabid = String(node.id);
    tabBtn.textContent = node.name || node.id;
    tabBtn.onclick = () => this.activate(node.id);

    // Drag-to-reorder: HTML5 drag-and-drop within the tab bar.
    tabBtn.draggable = true;
    tabBtn.addEventListener('dragstart', (e) => {
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(node.id));
      // Delay so the grab-ghost renders before we dim the element
      requestAnimationFrame(() => tabBtn.classList.add('ctab-dragging'));
    });
    tabBtn.addEventListener('dragend', () => {
      tabBtn.classList.remove('ctab-dragging');
      this.bar.querySelectorAll('.ctab-drag-over').forEach(el => el.classList.remove('ctab-drag-over'));
    });
    tabBtn.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      tabBtn.classList.add('ctab-drag-over');
    });
    tabBtn.addEventListener('dragleave', () => tabBtn.classList.remove('ctab-drag-over'));
    tabBtn.addEventListener('drop', (e) => {
      e.preventDefault();
      tabBtn.classList.remove('ctab-drag-over');
      const fromId = e.dataTransfer.getData('text/plain');
      if (fromId === String(node.id)) return;
      const fromBtn = this.bar.querySelector(`.ctab[data-tabid="${CSS.escape(fromId)}"]`);
      if (!fromBtn) return;
      // Insert before or after target based on cursor position within the tab
      const rect = tabBtn.getBoundingClientRect();
      if (e.clientX < rect.left + rect.width / 2) {
        this.bar.insertBefore(fromBtn, tabBtn);
      } else {
        tabBtn.after(fromBtn);
      }
    });

    const close = document.createElement('span');
    close.className = 'ctab-close';
    close.textContent = '×';
    close.onclick = (e) => { e.stopPropagation(); this.close(node.id); };
    tabBtn.append(close);

    const pane = document.createElement('div');
    pane.className = 'ctab-pane';
    pane.tabIndex = 0;            // focusable: required for Guacamole keyboard
    pane.style.display = 'none';

    this.bar.append(tabBtn);
    this.panes.append(pane);

    this.tabs.set(node.id, { node, tabBtn, pane, mounted: false, client: null });
    this.activate(node.id);
    this.onTabsChange?.(Array.from(this.tabs.keys()));
  }

  activate(id) {
    const rec = this.tabs.get(id);
    if (!rec) return;
    const prev = (this.activeId && this.activeId !== id) ? this.tabs.get(this.activeId) : null;
    // Hide EVERY other pane, not just the previously-active one. Each lane keeps its
    // chrome inside its own pane (telnet has an absolute, high z-index command-shortcut
    // bar + toolbar); if activeId ever drifts (e.g. it points at a closed tab) a second
    // pane can stay display:'' and bleed that chrome over the active graphical (VNC/RDP)
    // tab. Enforcing single-visible-pane here makes that impossible.
    this.tabs.forEach((c, k) => {
      if (k === id) return;
      if (c.pane.style.display !== 'none') c.pane.style.display = 'none';
      c.tabBtn.classList.remove('active');
    });
    if (prev) prev.onHide?.();
    this.activeId = id;
    rec.pane.style.display = '';
    rec.tabBtn.classList.add('active');
    rec.tabBtn.classList.remove('pnq-tab-unread');
    if (!rec.mounted) { rec.mounted = true; this._mount(rec); }
    rec.onShow?.();
  }

  close(id) {
    const rec = this.tabs.get(id);
    if (!rec) return;
    rec.dispose?.();
    rec.tabBtn.remove();
    rec.pane.remove();
    this.tabs.delete(id);
    if (this.activeId === id) {
      this.activeId = null;
      const next = this.tabs.keys().next().value;
      if (next) this.activate(next);
    }
    this.onTabsChange?.(Array.from(this.tabs.keys()));
  }

  // Pop this tab OUT of the combined window into its own in-lab floating window
  // (the window manager handles it; we just drop the tab here). Stateless tokens
  // mean the popped-out window re-mints/reconnects on its own.
  _popOut(node) {
    try {
      if (window.parent && window.parent !== window)
        window.parent.postMessage({ pnq: 'popout', node: node.id, name: node.name, type: node.type, isDocker: !!node.isDocker }, location.origin);
    } catch (e) {}
    this.onPopOut?.(node);
    this.close(node.id);
  }

  async _mint(node) {
    // A capture node mints against the capture container (engine wiresharks table)
    // rather than a lab node: token_mint.php?type=http&capture=1&node&iface. The
    // capture container is now pnet-capture-web (http packet UI), resolved
    // server-side to ws_ip:ws_port and reverse-proxied like a node http console, so
    // it renders via _mountHttp. The 2nd-console tab keys itself with a composite id
    // (e.g. "12::2") so it can coexist with the primary tab; mint against the REAL
    // node id (node.nodeId) and ask token_mint for the second console.
    const realId = node.nodeId != null ? node.nodeId : node.id;
    const url = node.capture
      ? `${this.opts.tokenUrl}?type=http&capture=1&node=${encodeURIComponent(node.capture.node)}&iface=${encodeURIComponent(node.capture.iface)}`
      : node.type === 'shell'
      ? `${this.opts.tokenUrl}?type=shell`            // node-less, admin-gated host shell
      : `${this.opts.tokenUrl}?node=${encodeURIComponent(realId)}&type=${encodeURIComponent(node.type)}${node.second ? '&second=1' : ''}`;
    const r = await fetch(url, { credentials: 'same-origin' });
    if (!r.ok) {
      let msg = `token mint failed (${r.status})`;
      try { const j = await r.json(); if (j && j.error) msg += `: ${j.error}`; } catch {}
      throw new Error(msg);
    }
    return r.json();                 // { token, ... }
  }

  async _mount(rec) {
    try {
      if (rec.node.type === 'http') {
        // http/https console → same-origin reverse-proxy iframe (the openHtml5
        // embed lane, inside this combined console). Bypasses the docker
        // rdp/vnc/telnet cascade below: an http node serves a web GUI, not a
        // desktop/terminal. _mint sends ?node&type=http; token_mint resolves the
        // scheme/host/port server-side and the http bridge proxies it.
        const { token } = await this._mint(rec.node);
        this._mountHttp(rec, token);
      } else if (rec.node.isDocker) {
        // For docker nodes: try RDP → VNC → telnet in order until one connects.
        // The lane computed by laneOf() is ignored — docker containers may serve
        // any protocol regardless of the console field in the engine template.
        this._mountDockerFallback(rec, ['rdp', 'vnc', 'telnet'], 0);
      } else {
        const { token, hint, prompt_credentials: promptCredentials = false } = await this._mint(rec.node);
        const fn = { telnet: this._mountTelnet, shell: this._mountTelnet, vnc: this._mountVnc, rdp: this._mountRdp }[rec.node.type]
          || (() => { rec.pane.textContent = `Unknown console type: ${rec.node.type}`; });
        (fn === this._mountRdp) ? fn.call(this, rec, token, null, hint, promptCredentials) : fn.call(this, rec, token);
      }
    } catch (e) {
      rec.pane.textContent = `Console error: ${e.message}`;
    }
  }

  // --- http / https : same-origin reverse-proxy iframe -----------------------
  // The token was minted with type=http; the http bridge proxies the node's web
  // GUI under /console/http/<token>/ (same origin, so it frames cleanly under the
  // lab CSP frame-ancestors 'self'). No xterm/noVNC/guac — just an iframe.
  _mountHttp(rec, token) {
    rec.pane.innerHTML = '';
    rec.pane.style.flexDirection = '';
    rec.pane.style.display = (this.activeId === rec.node.id) ? '' : 'none';
    const ifr = document.createElement('iframe');
    ifr.src = '/console/http/' + encodeURIComponent(token) + '/';
    ifr.title = rec.node.name + ' web console';
    ifr.setAttribute('allow', 'clipboard-read; clipboard-write; fullscreen');
    ifr.style.cssText = 'width:100%;height:100%;border:0;display:block;background:#fff';
    rec.pane.append(ifr);
    rec.httpFrame = ifr;
  }

  /**
   * Docker console cascade: attempt each lane in `seq` in order.
   * RDP uses a reduced attempt budget so fallback is fast (≈10 s per lane).
   * VNC falls back on disconnect-before-connect or an 8 s silence timeout.
   * Telnet is always the last-resort lane — it doesn't have a failure callback.
   */
  _mountDockerFallback(rec, seq, idx) {
    // Reset the pane's display WITHOUT forcing it visible: the cascade is async
    // (~8-10s per lane), so a docker node can still be probing lanes after the user
    // has switched to another tab. Forcing display:'' here would un-hide this pane
    // over the active tab — e.g. a docker that falls back to telnet would bleed its
    // command-shortcut bar + font toolbar over the active VNC desktop. Show only if
    // this tab is the active one; otherwise keep it hidden (onShow restores it).
    const showIfActive = () => { rec.pane.style.display = (this.activeId === rec.node.id) ? '' : 'none'; };
    if (idx >= seq.length) {
      rec.pane.innerHTML = '';
      showIfActive();
      rec.pane.style.flexDirection = '';
      const msg = document.createElement('div');
      msg.style.cssText = 'color:#fa8;padding:14px;font:13px/1.6 system-ui';
      msg.textContent = 'Docker console: all lanes failed (RDP → VNC → Telnet). Check that the container is running.';
      rec.pane.append(msg);
      return;
    }
    const type = seq[idx];
    const tryNext = () => {
      // Clean up any flex/display styles added by a previous VNC attempt so the
      // next lane starts with a plain block-level pane (only if still active).
      showIfActive();
      rec.pane.style.flexDirection = '';
      this._mountDockerFallback(rec, seq, idx + 1);
    };
    this._mint({ ...rec.node, type })
      .then(({ token, prompt_credentials: promptCredentials = false }) => {
        if (type === 'rdp')    this._mountRdp(rec, token, tryNext, null, promptCredentials);
        else if (type === 'vnc') this._mountVnc(rec, token, tryNext);
        else                     this._mountTelnet(rec, token);   // last-resort, always attempts
      })
      .catch(tryNext);
  }

  // Last copied text, SHARED across every console window/iframe of this origin.
  // copyText() also writes the OS clipboard, but a console iframe can't READ the
  // OS clipboard back (clipboard-read is blocked), and _lastCopied is per-window —
  // so a copy in one session wouldn't pre-fill another session's paste dialog.
  // localStorage is shared same-origin, so it carries the copy across windows.
  _sharedClip() {
    try { const v = localStorage.getItem('pnq_wc_clip'); if (v) return v; } catch (_) {}
    return this._lastCopied || '';
  }

  // --- shared paste dialog (Apple glass style) --------------------------------
  // Shared by telnet (right-click) and RDP/VNC (toolbar button).
  // anchorEl: the pane element used to centre the dialog in the viewport.
  // onConfirm(text): called with the (possibly edited) text when the user clicks Paste.
  _showPasteDialog(anchorEl, onConfirm, prefill = '') {
    const OLD = document.getElementById('pnq-paste-dlg');
    if (OLD) OLD.remove();
    const overlay = document.createElement('div');
    overlay.id = 'pnq-paste-dlg';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:200000;';
    // Centre within anchorEl's current viewport rect
    const BW = 380;
    const r = anchorEl.getBoundingClientRect();
    const left = Math.round(Math.max(8, r.left + (r.width  - BW) / 2));
    const top  = Math.round(Math.max(8, r.top  + (r.height - 210) / 2));
    const box = document.createElement('div');
    box.style.cssText =
      `position:absolute;left:${left}px;top:${top}px;width:${BW}px;` +
      'background:rgba(255,255,255,0.82);' +
      'backdrop-filter:blur(24px) saturate(180%);-webkit-backdrop-filter:blur(24px) saturate(180%);' +
      'border:1px solid rgba(0,0,0,0.13);border-radius:14px;padding:16px 18px 14px;' +
      'box-shadow:0 8px 36px rgba(0,0,0,0.22),0 1px 0 rgba(255,255,255,0.9) inset;' +
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;';
    const lbl = document.createElement('div');
    lbl.textContent = 'Paste to Terminal';
    lbl.style.cssText =
      'color:#1d1d1f;font-size:13px;font-weight:600;margin-bottom:10px;letter-spacing:-.01em;';
    const ta = document.createElement('textarea');
    ta.rows = 5;
    ta.style.cssText =
      'width:100%;box-sizing:border-box;' +
      'background:rgba(255,255,255,0.72);color:#1d1d1f;' +
      'border:1px solid rgba(0,0,0,0.18);border-radius:8px;padding:8px 10px;' +
      'font-family:ui-monospace,"SF Mono","SFMono-Regular",Menlo,monospace;font-size:12px;' +
      'resize:vertical;outline:none;line-height:1.5;' +
      'box-shadow:0 1px 4px rgba(0,0,0,0.07) inset;';
    ta.placeholder = '(clipboard empty — type or paste here)';
    const hint = document.createElement('div');
    hint.textContent = '⌘⏎ or Ctrl+Enter to paste  ·  Esc to cancel';
    hint.style.cssText = 'color:#86868b;font-size:10px;margin-top:5px;text-align:right;';
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;margin-top:12px;justify-content:flex-end;';
    const mkBtn = (txt, primary) => {
      const b = document.createElement('button');
      b.textContent = txt;
      b.style.cssText = primary
        ? 'padding:6px 20px;background:#0071e3;color:#fff;border:none;border-radius:8px;' +
          'cursor:pointer;font-size:13px;font-weight:500;font-family:inherit;' +
          'box-shadow:0 1px 3px rgba(0,113,227,.4);'
        : 'padding:6px 14px;background:rgba(0,0,0,0.06);color:#1d1d1f;' +
          'border:1px solid rgba(0,0,0,0.13);border-radius:8px;' +
          'cursor:pointer;font-size:13px;font-family:inherit;';
      return b;
    };
    const btnCancel = mkBtn('Cancel', false);
    const btnPaste  = mkBtn('Paste',  true);
    row.append(btnCancel, btnPaste);
    box.append(lbl, ta, hint, row);
    overlay.append(box);
    document.body.append(overlay);
    const close = () => { overlay.remove(); };
    const doPaste = () => { const t = ta.value; close(); if (t) onConfirm(t); };
    overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });
    btnCancel.addEventListener('click', close);
    btnPaste.addEventListener('click', doPaste);
    ta.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); doPaste(); }
    });
    // Pre-fill priority: (1) caller-supplied text (e.g. current terminal selection),
    // (2) the shared last-copied text (localStorage — carries across console
    //     windows/iframes where clipboard-read is blocked), (3) clipboard API
    //     (works in top-level / New-Tab mode). The user can always Ctrl+V into the
    //     box manually regardless.
    if (prefill) {
      ta.value = prefill; ta.select();
    } else {
      const shared = this._sharedClip();
      if (shared) { ta.value = shared; ta.select(); }
      else navigator.clipboard.readText().then((t) => { ta.value = t; ta.select(); }).catch(() => { ta.focus(); });
    }
  }

  // --- telnet / serial : xterm.js ------------------------------------------
  // Custom wiring (not AttachAddon) so we can carry a control channel alongside
  // the data, which the telnetlib3 bridge turns into NAWS:
  //   terminal data -> BINARY ws frames
  //   resize        -> TEXT  ws frame {type:'resize',cols,rows}
  // Initial size also rides on the URL so the device sees it at login.
  _mountTelnet(rec, token) {
    if (typeof window.Terminal === 'undefined' || typeof window.FitAddon === 'undefined') {
      rec.pane.textContent = 'xterm.js not loaded (vendor/xterm.js missing)'; return;
    }
    // xterm's UMD flattens its exports onto the global (window.Terminal IS the
    // class); addon-fit's UMD assigns the whole module object, so the class is
    // window.FitAddon.FitAddon. Tolerate both shapes.
    const FitAddon = (window.FitAddon && window.FitAddon.FitAddon) || window.FitAddon;
    // Persisted typography (telnet/serial lane). Size/family/weight ride xterm
    // options (reflow via fit.fit); italic is CSS on the pane (DOM renderer — no
    // canvas/webgl addon is loaded, so font-style applies to .xterm-rows).
    // [label, css-stack] — every option ends in a `monospace` fallback so a font
    // absent on the CLIENT degrades to the platform monospace (never a proportional
    // default, which renders wonky in a fixed terminal grid). No system-ui: it is
    // proportional and breaks column alignment.
    const FONT_DEFAULT = 'ui-monospace, "SF Mono", "SFMono-Regular", Menlo, Monaco, monospace';
    const FONTS = [
      ['Monospace', 'monospace'],
      ['SF Mono (Apple)', FONT_DEFAULT],
      ['Consolas', 'Consolas, monospace'],
      ['Courier New', '"Courier New", monospace'],
      ['Cascadia Mono', '"Cascadia Mono", "Cascadia Code", monospace'],
      ['Lucida Console', '"Lucida Console", monospace'],
      ['DejaVu Sans Mono', '"DejaVu Sans Mono", monospace'],
      ['Liberation Mono', '"Liberation Mono", monospace'],
    ];
    const fclamp = (n) => Math.min(28, Math.max(8, n | 0));
    let fSize = fclamp(parseInt(localStorage.getItem('pnq_wc_font_size'), 10) || 14);
    let fFamily = localStorage.getItem('pnq_wc_font_family') || FONT_DEFAULT;
    if (!FONTS.some((f) => f[1] === fFamily)) fFamily = FONT_DEFAULT;   // drop stale/bad saved value
    let fBold = localStorage.getItem('pnq_wc_font_bold') === '1';
    let fItalic = localStorage.getItem('pnq_wc_font_italic') === '1';
    let fSeethru = localStorage.getItem('pnq_wc_seethru') === '1';
    const term = new window.Terminal({
      cursorBlink: true, fontFamily: fFamily, fontSize: fSize,
      fontWeight: fBold ? 'bold' : 'normal',
    });
    const fit  = new FitAddon();
    term.loadAddon(fit);
    // Wrap xterm in a sub-div so the button bar + command panel below don't
    // overlap the terminal. The div's bottom edge is adjusted dynamically when
    // those panels open/close; FitAddon measures this container (not rec.pane).
    const termContainer = document.createElement('div');
    termContainer.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:34px;';
    let ribbonRO = null;   // ResizeObserver keeping termContainer below the ribbon
    rec.pane.appendChild(termContainer);
    term.open(termContainer);
    rec.pane.classList.toggle('pnq-italic', fItalic);
    try { fit.fit(); } catch {}
    const setOpt = (k, v) => { try { term.options[k] = v; } catch (_) {} };
    const applyFont = () => {
      setOpt('fontSize', fSize); setOpt('fontFamily', fFamily);
      setOpt('fontWeight', fBold ? 'bold' : 'normal');
      rec.pane.classList.toggle('pnq-italic', fItalic);
      try { fit.fit(); } catch {} term.focus();
    };
    // See-through mode (telnet/serial): transparent backgrounds through the whole
    // stack so the canvas shows behind, text stays fully opaque/readable. xterm's
    // own background layer goes transparent via the theme; the page chain via the
    // html.pnq-seethru CSS (console.html); the floating window + iframe via a
    // {pnq:'seethru'} message to the window manager.
    // Light-mode see-through needs a foreground/cursor override too: the
    // opaque black terminal (bg #000000) is legible with xterm's own default
    // light foreground regardless of page theme, but a TRANSPARENT terminal
    // over a light canvas (light page + see-through both on) is not — the
    // default light text disappears against the light canvas behind it.
    const isLightPage = () => document.documentElement.classList.contains('pnq-light');
    const setTermBg = (bg) => {
      try {
        const seeThroughLight = bg !== '#000000' && isLightPage();
        term.options.theme = Object.assign({}, term.options.theme, {
          background: bg,
          foreground: seeThroughLight ? '#1d1d1f' : undefined,
          cursor: seeThroughLight ? '#1d1d1f' : undefined,
          selectionBackground: seeThroughLight ? 'rgba(60,112,138,.25)' : undefined,
        });
      } catch (_) {}
    };
    const applySeethru = (on) => {
      document.documentElement.classList.toggle('pnq-seethru', on);
      setTermBg(on ? 'rgba(0,0,0,0)' : '#000000');
      try { fit.fit(); } catch {}
      try {
        if (window.parent && window.parent !== window)
          window.parent.postMessage({ pnq: 'seethru', on: !!on }, location.origin);
      } catch (e) {}
    };
    // The lab page's theme toggle can flip while this tab is already open and
    // see-through; console-theme.js dispatches this when it picks up that
    // change (via the localStorage 'storage' event), so re-derive the
    // foreground override live instead of requiring a reopen.
    window.addEventListener('pnq:theme-changed', () => { if (fSeethru) setTermBg('rgba(0,0,0,0)'); });

    // SecureCRT-style keyword highlighting: default ON for telnet/serial, OFF for
    // the host shell lane (bash isn't Cisco output). A small toolbar toggle lets
    // the user disable it live (e.g. if a quirky full-screen app misbehaves);
    // choice persists. The host shell lane omits the toolbar entirely.
    // ── Session logging (always-on, capped ring buffer of RAW device output) ──
    // We tap ws.onmessage text (below) BEFORE the highlighter adds display ANSI,
    // so the downloaded log is the device's own output. Bounded to LOG_CAP so a
    // long session can't grow memory without limit. Pure-additive — the existing
    // data path is untouched.
    let logBuf = '';
    const LOG_CAP = 2000000;
    // Debounced scrollback autosave — at most once per 2s while output is
    // arriving, so a chatty session (e.g. a ping flood) doesn't write-storm
    // sessionStorage. flushScrollback (synchronous, immediate) is what the
    // page-level beforeunload/pagehide listener and rec.dispose call instead.
    let scrollbackSaveTimer = null;
    const flushScrollback = () => saveScrollback(rec.node, logBuf);
    const scheduleScrollbackSave = () => {
      if (scrollbackSaveTimer) return;
      scrollbackSaveTimer = setTimeout(() => { scrollbackSaveTimer = null; flushScrollback(); }, 2000);
    };
    scrollbackFlushers.add(flushScrollback);
    const downloadLog = () => {
      const clean = logBuf
        .replace(/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)/g, '')   // OSC … BEL/ST
        .replace(/\x1b[@-Z\\-_]/g, '')                        // 2-char ESC
        .replace(/\x1b\[[0-9;?]*[ -\/]*[@-~]/g, '')           // CSI (colour/cursor)
        .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f]/g, '');        // stray ctrl chars (keep \t \n \r)
      const ts = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19);
      const nm = String(rec.node.name || ('node-' + rec.node.id)).replace(/[^\w.-]+/g, '_');
      const fname = `${nm}-${ts}.log`;
      // Trigger from the TOP window: an in-iframe a.click() download is blocked
      // in many browsers, but the parent topology page isn't sandboxed. In
      // new-tab console mode window.top === window, so this path is unchanged.
      let win = window, doc = document;
      try { if (window.top && window.top.document) { win = window.top; doc = win.document; } } catch (_) {}
      try {
        const url = win.URL.createObjectURL(new win.Blob([clean], { type: 'text/plain;charset=utf-8' }));
        const a = doc.createElement('a');
        a.href = url; a.download = fname; a.style.display = 'none';
        doc.body.appendChild(a); a.click();
        setTimeout(() => { try { win.URL.revokeObjectURL(url); } catch (_) {} a.remove(); }, 0);
      } catch (_) {
        // last resort: open the log in a new tab so the user can save it manually
        try {
          const w = window.open('', '_blank');
          if (w) { w.document.title = fname; w.document.body.style.whiteSpace = 'pre-wrap';
                   w.document.body.textContent = clean; }
        } catch (__) {}
      }
    };
    // Reconnect UI hooks — assigned by the toolbar + the connect loop below; safe
    // no-ops on lanes/states that don't build them (e.g. the host-shell lane).
    let setConn = () => {};
    let reconnectNow = () => {};

    const highlightable = rec.node.type !== 'shell';
    const hi = makeHighlighter(term, highlightable && localStorage.getItem('pnq_wc_highlight') !== '0');
    if (highlightable) {
      // Dedicated RIBBON bar (full-width row at the TOP of the pane, under the
      // tab strip) instead of a floating overlay — the old .rdp-toolbar overlay
      // sat over the terminal's top-right and covered output text.
      const bar = document.createElement('div'); bar.className = 'term-ribbon';
      const b = document.createElement('button'); b.className = 'rdp-tbtn'; b.innerHTML = 'Aa';
      b.title = 'Toggle SecureCRT keyword highlighting';
      b.classList.toggle('active', hi.enabled);
      b.onclick = (e) => {
        e.preventDefault(); e.stopPropagation();
        hi.enabled = !hi.enabled;
        localStorage.setItem('pnq_wc_highlight', hi.enabled ? '1' : '0');
        b.classList.toggle('active', hi.enabled);
        term.focus();
      };
      bar.append(b);

      // Typography controls (size −/+, bold, italic, font picker) — same live
      // toggle + localStorage pattern as the highlight button above.
      const mkBtn = (html, title, on, fn) => {
        const x = document.createElement('button'); x.className = 'rdp-tbtn';
        x.innerHTML = html; x.title = title; if (on) x.classList.add('active');
        x.onmousedown = (e) => e.preventDefault();        // keep terminal focus
        x.onclick = (e) => { e.preventDefault(); e.stopPropagation(); fn(x); };
        bar.append(x); return x;
      };
      mkBtn('A&#8722;', 'Smaller text', false, () => {
        fSize = fclamp(fSize - 1); localStorage.setItem('pnq_wc_font_size', fSize); applyFont();
      });
      mkBtn('A+', 'Larger text', false, () => {
        fSize = fclamp(fSize + 1); localStorage.setItem('pnq_wc_font_size', fSize); applyFont();
      });
      mkBtn('<b>B</b>', 'Bold text', fBold, (x) => {
        fBold = !fBold; localStorage.setItem('pnq_wc_font_bold', fBold ? '1' : '0');
        x.classList.toggle('active', fBold); applyFont();
      });
      mkBtn('<i>I</i>', 'Italic text', fItalic, (x) => {
        fItalic = !fItalic; localStorage.setItem('pnq_wc_font_italic', fItalic ? '1' : '0');
        x.classList.toggle('active', fItalic); applyFont();
      });
      const sel = document.createElement('select'); sel.className = 'rdp-tsel'; sel.title = 'Font family';
      FONTS.forEach(([label, val]) => {
        const o = document.createElement('option'); o.value = val;
        o.textContent = label; if (val === fFamily) o.selected = true; sel.append(o);
      });
      sel.onmousedown = (e) => e.stopPropagation();
      sel.onchange = () => { fFamily = sel.value; localStorage.setItem('pnq_wc_font_family', fFamily); applyFont(); };
      bar.append(sel);

      mkBtn('&#x25D1;', 'See-through terminal (show the canvas behind)', fSeethru, (x) => {
        fSeethru = !fSeethru; localStorage.setItem('pnq_wc_seethru', fSeethru ? '1' : '0');
        x.classList.toggle('active', fSeethru); applySeethru(fSeethru); term.focus();
      });

      // Download the captured session log (.log) for this tab.
      mkBtn('&#x2913;', 'Download session log (.log)', false, () => { downloadLog(); term.focus(); });

      // Connection-state dot + "Reconnect now". The dot mirrors the auto-reconnect
      // loop below; the button forces an immediate retry instead of waiting out
      // the 5 s backoff.
      const cdot = document.createElement('span');
      cdot.style.cssText = 'display:inline-block;width:9px;height:9px;border-radius:50%;' +
        'background:#9aa0a6;margin:0 5px;vertical-align:middle;flex:0 0 auto;';
      cdot.title = 'Connecting…';
      bar.append(cdot);
      mkBtn('&#x21bb;', 'Reconnect now', false, () => { reconnectNow(); term.focus(); });
      setConn = (s) => {
        const m = { connecting: ['#e0a52e', 'Connecting…'], connected: ['#43a047', 'Connected'],
                    reconnecting: ['#e53935', 'Reconnecting…'],
                    paused: ['#9aa0a6', 'Released — native console mode is on'],
                    bgpaused: ['#9aa0a6', 'Released — tab is in the background (single-reader console)'] }[s] || ['#9aa0a6', ''];
        cdot.style.background = m[0]; cdot.title = m[1];
      };

      // ── Send-Command panel (item 4) ──────────────────────────────────────────
      // Glass-themed "command window" that slides up from the BOTTOM of the pane.
      // The button bar (item 3) floats ABOVE it (its bottom is pushed up by
      // PANEL_H while this is open), so the stack top→bottom is:
      //   terminal → button bar → command window.
      // Contains a textarea + "Send here" (this tab's socket) + "Send to all tabs".
      const PANEL_H = 120;
      const BAR_H   = 34;
      const sendPanel = document.createElement('div');
      sendPanel.style.cssText =
        'position:absolute;bottom:0;left:0;right:0;height:0;z-index:6;overflow:hidden;' +
        'background:rgba(20,30,24,0.92);backdrop-filter:blur(18px) saturate(180%);' +
        '-webkit-backdrop-filter:blur(18px) saturate(180%);' +
        'border-top:1px solid rgba(255,255,255,0.12);display:flex;flex-direction:column;' +
        'transition:height 0.15s ease;';
      const spInner = document.createElement('div');
      spInner.style.cssText = 'padding:8px 10px 6px;display:flex;flex-direction:column;gap:6px;flex:1 1 auto;min-height:0;';
      const spTa = document.createElement('textarea');
      spTa.placeholder = 'Type or paste commands to send…';
      spTa.style.cssText =
        'flex:1 1 auto;resize:none;width:100%;box-sizing:border-box;' +
        'background:rgba(255,255,255,0.09);color:#e8f0ea;' +
        'border:1px solid rgba(255,255,255,0.16);border-radius:6px;padding:5px 8px;' +
        'font-family:ui-monospace,"SF Mono",Menlo,monospace;font-size:12px;outline:none;';
      spTa.onmousedown = (e) => e.stopPropagation();
      const spRow = document.createElement('div');
      spRow.style.cssText = 'display:flex;gap:6px;flex:0 0 auto;';
      const mkSpBtn = (txt, primary, fn) => {
        const b = document.createElement('button'); b.textContent = txt;
        b.style.cssText = primary
          ? 'padding:3px 14px;background:rgba(120,200,160,0.5);color:#ffffff;' +
            'border:1px solid rgba(120,200,160,0.3);border-radius:6px;cursor:pointer;font:700 12px system-ui,sans-serif;'
          : 'padding:3px 14px;background:rgba(255,255,255,0.08);color:#eef6f1;' +
            'border:1px solid rgba(255,255,255,0.13);border-radius:6px;cursor:pointer;font:700 12px system-ui,sans-serif;';
        b.onmousedown = (e) => e.preventDefault();   // keep terminal focus intent
        b.onclick = fn;
        return b;
      };
      const doSendHere = () => {
        const txt = spTa.value; if (!txt) return;
        if (sock && sock.readyState === WebSocket.OPEN) sock.send(enc.encode(txt + '\r'));
        term.focus();
      };
      const doSendAll = () => {
        const txt = spTa.value; if (!txt) return;
        this.tabs.forEach((trec) => {
          if ((trec.node.type === 'telnet' || trec.node.type === 'shell') &&
              trec.client && trec.client.sock && trec.client.sock.readyState === WebSocket.OPEN)
            trec.client.sock.send(enc.encode(txt + '\r'));
        });
        term.focus();
      };
      spTa.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); doSendHere(); }
      });
      spRow.append(mkSpBtn('▶ Send here', true, doSendHere), mkSpBtn('⊛ Send to all tabs', false, doSendAll));
      spInner.append(spTa, spRow);
      sendPanel.append(spInner);

      let sendPanelOpen = false;
      const toggleSendPanel = (x) => {
        sendPanelOpen = !sendPanelOpen;
        sendPanel.style.height = sendPanelOpen ? PANEL_H + 'px' : '0';
        // Lift the command button bar above the command window so the bar stays
        // visible and usable while the window is open.
        btnBar.style.bottom = sendPanelOpen ? PANEL_H + 'px' : '0';
        termContainer.style.bottom = sendPanelOpen ? (BAR_H + PANEL_H) + 'px' : BAR_H + 'px';
        x.classList.toggle('active', sendPanelOpen);
        try { fit.fit(); } catch {}
        if (sendPanelOpen) spTa.focus(); else term.focus();
      };
      mkBtn('&#x25BA;', 'Send command to terminal(s)  (Ctrl+Enter sends)', false, toggleSendPanel);

      rec.pane.append(bar);
      rec.pane.append(sendPanel);
      // Ribbon layout: the terminal starts BELOW the ribbon, never under it.
      // The ribbon wraps onto extra rows on narrow windows, so its height is
      // dynamic — mirror the measured height into the terminal container's top
      // offset and refit xterm whenever it changes (initial mount, window
      // resize/maximize, wrap/unwrap).
      const syncRibbon = () => {
        const h = (bar.offsetHeight || 0) + 'px';
        if (termContainer.style.top !== h) {
          termContainer.style.top = h;
          try { fit.fit(); } catch {}
        }
      };
      syncRibbon();
      if (window.ResizeObserver) { ribbonRO = new ResizeObserver(syncRibbon); ribbonRO.observe(bar); }
      if (fSeethru) applySeethru(true);                  // restore persisted see-through
    }

    // ── Command Button Bar (item 3) ──────────────────────────────────────────
    // Customizable shortcut buttons at the bottom of every telnet/serial pane.
    // Config lives in localStorage (pnq_wc_btnbar) as [{id,label,cmd}].
    // Clicking a button sends cmd+\r to this tab's socket.
    const BBK = 'pnq_wc_btnbar';
    const bbLoad  = () => { try { return JSON.parse(localStorage.getItem(BBK)) || []; } catch { return []; } };
    const bbSave  = (a) => localStorage.setItem(BBK, JSON.stringify(a));

    // Themed via CSS class (light-mode override lives in console.html,
    // .pnq-cmdbar / .pnq-cmdbar-btn / .pnq-cmdbar-add) — dark values stay
    // inline here as the base/default so nothing changes for existing dark
    // sessions. isLightPage() is declared earlier in this scope (see setTermBg).
    const btnBar = document.createElement('div');
    btnBar.className = 'pnq-cmdbar';
    btnBar.style.cssText =
      'position:absolute;bottom:0;left:0;right:0;height:34px;z-index:7;' +
      'display:flex;align-items:center;gap:4px;padding:0 8px;overflow-x:auto;' +
      'background:rgba(20,30,24,0.88);backdrop-filter:blur(18px) saturate(180%);' +
      '-webkit-backdrop-filter:blur(18px) saturate(180%);' +
      'border-top:1px solid rgba(255,255,255,0.10);scrollbar-width:thin;' +
      'transition:bottom 0.15s ease;';

    const bbRender = () => {
      btnBar.innerHTML = '';
      bbLoad().forEach((item, i) => {
        const b = document.createElement('button');
        b.className = 'pnq-cmdbar-btn';
        b.textContent = item.label; b.title = item.cmd;
        b.style.cssText =
          'padding:0 10px;height:24px;border:1px solid rgba(255,255,255,0.18);' +
          'border-radius:5px;background:rgba(255,255,255,0.10);color:#f4faf6;' +
          'font:700 11px/24px system-ui,sans-serif;cursor:pointer;white-space:nowrap;flex-shrink:0;';
        b.onmouseenter = () => b.style.background = isLightPage() ? 'rgba(36,41,47,.14)' : 'rgba(255,255,255,0.22)';
        b.onmouseleave = () => b.style.background = isLightPage() ? 'rgba(36,41,47,.06)' : 'rgba(255,255,255,0.10)';
        b.onclick = (e) => {
          e.preventDefault();
          if (sock && sock.readyState === WebSocket.OPEN) sock.send(enc.encode(item.cmd + '\r'));
          term.focus();
        };
        b.oncontextmenu = (e) => { e.preventDefault(); bbCtxMenu(e.clientX, e.clientY, i); };
        btnBar.append(b);
      });
      // + add-button
      const add = document.createElement('button');
      add.className = 'pnq-cmdbar-add';
      add.textContent = '+'; add.title = 'Add command shortcut';
      add.style.cssText =
        'padding:0 8px;height:24px;border:1px solid rgba(255,255,255,0.18);' +
        'border-radius:5px;background:rgba(255,255,255,0.07);color:#bfe6cf;' +
        'font:700 15px/22px monospace;cursor:pointer;flex-shrink:0;';
      add.onclick = (e) => { e.preventDefault(); bbEditor(-1); };
      btnBar.append(add);
    };

    // Glass editor dialog for add/edit
    const bbEditor = (editIdx) => {
      const OLD = document.getElementById('pnq-bb-dlg'); if (OLD) OLD.remove();
      const items = bbLoad(); const isNew = editIdx < 0;
      const cur = isNew ? { label: '', cmd: '' } : (items[editIdx] || { label: '', cmd: '' });
      const ov = document.createElement('div');
      ov.id = 'pnq-bb-dlg';
      ov.style.cssText = 'position:fixed;inset:0;z-index:200001;';
      const box = document.createElement('div');
      box.style.cssText =
        'position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:340px;' +
        'background:rgba(255,255,255,0.85);backdrop-filter:blur(24px) saturate(180%);' +
        '-webkit-backdrop-filter:blur(24px) saturate(180%);' +
        'border:1px solid rgba(0,0,0,0.13);border-radius:14px;padding:16px 18px 14px;' +
        'box-shadow:0 8px 36px rgba(0,0,0,0.22),0 1px 0 rgba(255,255,255,0.9) inset;' +
        'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;';
      const hdr = document.createElement('div');
      hdr.textContent = isNew ? 'Add Command Shortcut' : 'Edit Command Shortcut';
      hdr.style.cssText = 'color:#1d1d1f;font-size:13px;font-weight:600;margin-bottom:12px;';
      const mkFld = (lbl, val, ph) => {
        const w = document.createElement('div'); w.style.marginBottom = '10px';
        const l = document.createElement('div'); l.textContent = lbl;
        l.style.cssText = 'color:#555;font-size:11px;font-weight:600;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;';
        const inp = document.createElement('input'); inp.type = 'text'; inp.value = val; inp.placeholder = ph;
        inp.style.cssText =
          'width:100%;box-sizing:border-box;background:rgba(255,255,255,0.72);color:#1d1d1f;' +
          'border:1px solid rgba(0,0,0,0.18);border-radius:8px;padding:6px 10px;' +
          'font-family:inherit;font-size:12px;outline:none;';
        w.append(l, inp); return { w, inp };
      };
      const { w: lw, inp: lblI } = mkFld('Button Label', cur.label, 'e.g. show ip int brief');
      const { w: cw, inp: cmdI } = mkFld('Command', cur.cmd, 'e.g. show ip int brief');
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;margin-top:12px;';
      const mkDB = (txt, p) => {
        const b = document.createElement('button'); b.textContent = txt;
        b.style.cssText = p
          ? 'padding:6px 18px;background:#0071e3;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;font-family:inherit;'
          : 'padding:6px 14px;background:rgba(0,0,0,0.06);color:#1d1d1f;border:1px solid rgba(0,0,0,0.13);border-radius:8px;cursor:pointer;font-size:13px;font-family:inherit;';
        return b;
      };
      const btnC = mkDB('Cancel', false), btnS = mkDB(isNew ? 'Add' : 'Save', true);
      const close = () => ov.remove();
      const save  = () => {
        const label = lblI.value.trim(); const cmd = cmdI.value;
        if (!label) { lblI.focus(); return; }
        const arr = bbLoad();
        if (isNew) arr.push({ id: Date.now().toString(36), label, cmd });
        else arr[editIdx] = { id: arr[editIdx]?.id || Date.now().toString(36), label, cmd };
        bbSave(arr); close(); bbRender(); term.focus();
      };
      ov.addEventListener('mousedown', (e) => { if (e.target === ov) close(); });
      btnC.onclick = close; btnS.onclick = save;
      cmdI.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } });
      row.append(btnC, btnS);
      box.append(hdr, lw, cw, row);
      ov.append(box);
      document.body.append(ov);
      lblI.focus();
    };

    // Small glass context menu: Edit / Delete
    const bbCtxMenu = (x, y, idx) => {
      const OLD = document.getElementById('pnq-bb-ctx'); if (OLD) OLD.remove();
      const m = document.createElement('div');
      m.id = 'pnq-bb-ctx';
      m.style.cssText =
        'position:fixed;left:'+x+'px;top:'+y+'px;z-index:200001;min-width:120px;' +
        'background:rgba(255,255,255,0.92);backdrop-filter:blur(20px) saturate(180%);' +
        '-webkit-backdrop-filter:blur(20px) saturate(180%);' +
        'border:1px solid rgba(0,0,0,0.12);border-radius:10px;padding:4px;' +
        'box-shadow:0 8px 28px rgba(0,0,0,0.18);font:13px system-ui,sans-serif;';
      const mkMI = (txt, fn) => {
        const d = document.createElement('div'); d.textContent = txt;
        d.style.cssText = 'padding:6px 12px;border-radius:6px;cursor:pointer;color:#1d1d1f;';
        d.onmouseenter = () => d.style.background = 'rgba(0,0,0,0.07)';
        d.onmouseleave = () => d.style.background = '';
        d.onclick = () => { m.remove(); fn(); };
        return d;
      };
      m.append(
        mkMI('✏ Edit',   () => bbEditor(idx)),
        mkMI('✕ Delete', () => { const arr = bbLoad(); arr.splice(idx, 1); bbSave(arr); bbRender(); term.focus(); })
      );
      document.body.append(m);
      setTimeout(() => {
        const dismiss = (e) => { if (!m.contains(e.target)) { m.remove(); document.removeEventListener('mousedown', dismiss, true); } };
        document.addEventListener('mousedown', dismiss, true);
      }, 0);
    };

    rec.pane.append(btnBar);
    bbRender();

    // WebSocket with auto-reconnect. When a node is stopped then restarted from
    // the lab, the bridge drops the socket. We re-mint a token and reconnect
    // every RECONNECT_MS until the node is back — silently on the first connect
    // attempt (node may not be ready yet), with an on-screen notice if we had
    // an active session.
    const RECONNECT_MS  = 5000;
    const enc  = new TextEncoder();
    const dec  = new TextDecoder();
    const path = rec.node.type === 'shell' ? this.opts.shellPath : this.opts.telnetPath;

    let sock          = null;   // live WebSocket — reassigned on each reconnect
    let disposed      = false;  // rec.dispose sets this to stop the loop
    let reconnTimer   = null;
    let everConnected = false;  // true once the first onopen fires
    // Native handoff only applies to node consoles: the host shell (type
    // 'shell') is login(1)/PAM over its own bridge, not netprobe/vpcs, so an
    // open admin shell tab shouldn't drop just because someone flips the
    // HTML5/native toggle for an unrelated lab node.
    const nativeHandoff = rec.node.type !== 'shell';
    let nativePaused  = nativeHandoff && !html5Enabled();  // native console mode: socket released

    const sendResize = () => {
      if (sock && sock.readyState === WebSocket.OPEN)
        sock.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));
    };

    // initialTok: use the token already minted by _mount for the first connect.
    // On reconnect, pass null so a fresh token is minted (old ones may have expired).
    const doConnect = (initialTok) => {
      if (disposed || nativePaused) return;
      setConn('connecting');
      const p = initialTok ? Promise.resolve({ token: initialTok }) : this._mint(rec.node);
      p.then(({ token }) => {
        if (disposed) return;
        const q = `token=${encodeURIComponent(token)}&cols=${term.cols}&rows=${term.rows}&term=xterm-256color`;
        const ws = new WebSocket(wsUrl(`${path}?${q}`));
        ws.binaryType = 'arraybuffer';
        ws.onmessage = (e) => {
          const text = (typeof e.data === 'string') ? e.data
                     : dec.decode(new Uint8Array(e.data), { stream: true });
          logBuf += text; if (logBuf.length > LOG_CAP) logBuf = logBuf.slice(logBuf.length - LOG_CAP);
          scheduleScrollbackSave();
          hi.feed(text);
          if (this.activeId !== rec.node.id) rec.tabBtn.classList.add('pnq-tab-unread');
        };
        ws.onopen = () => {
          setConn('connected');
          if (everConnected) term.write('\r\n\x1b[32m[Reconnected]\x1b[0m\r\n');
          everConnected = true;
          sendResize();
          // Node consoles need a CR to elicit the device prompt; the host shell
          // (login(1)) already prints its prompt on connect, so an extra CR there
          // just inserts a blank line / spurious empty command.
          if (rec.node.type !== 'shell') ws.send(enc.encode('\r'));
        };
        ws.onclose = () => {
          if (disposed) return;
          sock = null;
          if (nativePaused) { setConn('paused'); return; }
          setConn('reconnecting');
          if (everConnected)
            term.write('\r\n\x1b[33m[Console disconnected — waiting for node…]\x1b[0m\r\n');
          reconnTimer = setTimeout(() => doConnect(null), RECONNECT_MS);
        };
        sock = ws;
      }).catch(() => {
        if (!disposed && !nativePaused) reconnTimer = setTimeout(() => doConnect(null), RECONNECT_MS);
      });
    };

    // Native handoff: release the socket the instant native mode switches on
    // (don't wait for RECONNECT_MS/onclose), and resume on our own reconnect
    // loop the instant it switches back — no page reload either direction.
    const onHtml5Change = (html5On) => {
      nativePaused = !html5On;
      if (nativePaused) {
        if (reconnTimer) { clearTimeout(reconnTimer); reconnTimer = null; }
        if (sock) { try { sock.onclose = null; sock.close(); } catch (_) {} sock = null; }
        setConn('paused');
      } else if (!disposed && !sock) {
        doConnect(null);
      }
    };
    if (nativeHandoff) nativeModeListeners.add(onHtml5Change);

    // Replay persisted scrollback (see the SCROLLBACK_* helpers near the top
    // of this module) BEFORE the first live connect, so it reads as history
    // sitting above the live stream, not interleaved with it. Routed through
    // the same highlighter (hi.feed) the live path uses so formatting matches;
    // NOT appended back into logBuf (it's already the source it came from).
    // everConnected is set here too, ahead of the real first onopen, so that
    // connect's success prints "[Reconnected]" instead of staying silent —
    // from the user's perspective this genuinely is a reconnect.
    const cachedScrollback = loadScrollback(rec.node);
    if (cachedScrollback) {
      hi.feed(cachedScrollback);
      term.write('\r\n\x1b[2m── restored scrollback — reconnecting…\x1b[0m\r\n');
      everConnected = true;
    }

    if (nativePaused) setConn('paused'); else doConnect(token);   // first connect: reuse the token minted by _mount
    reconnectNow = () => {
      if (disposed || nativePaused) return;
      clearTimeout(reconnTimer);
      if (sock) { try { sock.onclose = null; sock.close(); } catch (_) {} }
      setConn('connecting');
      doConnect(null);
    };

    const onData   = term.onData((d) => { if (sock && sock.readyState === WebSocket.OPEN) sock.send(enc.encode(d)); });
    const onResize = term.onResize(sendResize);
    const refit    = () => { if (this.activeId === rec.node.id) { try { fit.fit(); } catch {} } };
    window.addEventListener('resize', refit);

    // copyText: write text to the OS clipboard.
    // navigator.clipboard.writeText() is silently blocked when console.html
    // runs inside an iframe without a Permissions-Policy: clipboard-write grant
    // from the parent page.  Fall back to the legacy textarea+execCommand path
    // which works in any same-origin iframe without extra permissions.
    const copyText = (text) => {
      if (!text) return;
      this._lastCopied = text;   // paste-dialog pre-fill when clipboard read is blocked
      try { localStorage.setItem('pnq_wc_clip', text); } catch (_) {}   // share across console windows
      // Synchronous execCommand('copy') runs INSIDE the user gesture (the mouseup)
      // and works in a same-origin iframe with NO clipboard-write permission — the
      // reliable path for select-to-copy. The old code ran it only as an async
      // .catch() of navigator.clipboard, by which point the gesture had expired, so
      // it failed silently. Do execCommand FIRST; the async API is a best-effort
      // backup (works in New-Tab mode / with a Permissions-Policy grant).
      const active = document.activeElement;
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;border:0;padding:0;margin:0;';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        try { ta.setSelectionRange(0, ta.value.length); } catch (_) {}   // iOS/Safari
        try { document.execCommand('copy'); } catch (_) {}
        ta.remove();
      } catch (_) {}
      if (active && active.focus) { try { active.focus(); } catch (_) {} }   // restore terminal focus
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(() => {});                 // best-effort
      }
    };

    // Select-to-copy: mirrors X11 terminal behaviour — releasing the mouse after
    // a selection immediately copies the selected text to the clipboard.
    // xterm.js intercepts pointer events on its internal canvas so a mouseup on
    // term.element alone can be missed (cursor left the pane during drag, or
    // xterm stopped bubbling).  More reliable pattern: track whether a selection
    // exists via onSelectionChange, then commit the copy on document mouseup.
    let _hasSel = false;
    const onSelChange = term.onSelectionChange(() => { _hasSel = !!term.getSelection(); });
    const onSelectCopy = () => {
      if (!_hasSel) return;
      const sel = term.getSelection();
      if (sel) copyText(sel);
    };
    document.addEventListener('mouseup', onSelectCopy);

    // Right-click → shared paste dialog (centred in pane, Apple glass theme).
    // contextmenu is intentionally limited to the telnet/shell lane; RDP and VNC
    // need their native right-click, so they expose a toolbar clipboard button instead.
    const onContextMenu = (e) => {
      e.preventDefault();
      // Pre-fill with the live terminal selection if one exists (user right-clicked
      // after selecting text); otherwise the dialog falls back to this._lastCopied
      // or the clipboard API — whichever is available.
      const currentSel = term.getSelection();
      this._showPasteDialog(rec.pane, (txt) => {
        if (sock && sock.readyState === WebSocket.OPEN) term.paste(txt);
        term.focus();
      }, currentSel);
    };
    term.element.addEventListener('contextmenu', onContextMenu);

    rec.client  = { term, get sock() { return sock; }, fit };
    // VPCS/netprobe is the one console backend in the fleet that's
    // single-reader-with-evict (a new TCP connect kicks the previous one off —
    // see netprobe's accept_console/evict_active_console). Every OTHER tab type
    // is deliberately kept alive in the background on hide (this file's header
    // comment) because their backends tolerate real concurrent readers just
    // fine. For VPCS that same behavior means a backgrounded "webconsole all"
    // tab's normal reconnect-on-drop loop (RECONNECT_MS above) repeatedly
    // steals the connection back from whatever window the user is ACTUALLY
    // using for that node. So: only for isVpcs tabs, actually drop the
    // connection on hide (sock.onclose=null avoids its own reconnect firing —
    // same pattern as the native-handoff pause path below) and reconnect on
    // show, exactly like switching to any other freshly-activated tab.
    let vpcsBackgroundPaused = false;
    rec.onShow  = () => {
      try { fit.fit(); } catch {} term.focus();
      if (rec.node.isVpcs && vpcsBackgroundPaused) {
        vpcsBackgroundPaused = false;
        doConnect(null);
      }
    };
    rec.onHide  = () => {
      if (!rec.node.isVpcs) return;
      vpcsBackgroundPaused = true;
      if (reconnTimer) { clearTimeout(reconnTimer); reconnTimer = null; }
      if (sock) { try { sock.onclose = null; sock.close(); } catch (_) {} sock = null; }
      setConn('bgpaused');
    };
    rec.dispose = () => {
      disposed = true;
      if (scrollbackSaveTimer) { clearTimeout(scrollbackSaveTimer); scrollbackSaveTimer = null; }
      scrollbackFlushers.delete(flushScrollback);
      flushScrollback();
      nativeModeListeners.delete(onHtml5Change);
      if (reconnTimer) { clearTimeout(reconnTimer); reconnTimer = null; }
      if (ribbonRO) { try { ribbonRO.disconnect(); } catch (_) {} ribbonRO = null; }
      window.removeEventListener('resize', refit);
      document.removeEventListener('mouseup', onSelectCopy);
      onSelChange.dispose();
      try { term.element.removeEventListener('contextmenu', onContextMenu); } catch {}
      const dlg = document.getElementById('pnq-paste-dlg'); if (dlg) dlg.remove();
      onData.dispose(); onResize.dispose();
      if (sock) { try { sock.close(); } catch {} }
      term.dispose();
    };
    requestAnimationFrame(rec.onShow);   // fit once the pane has real size
  }

  // --- vnc : noVNC ----------------------------------------------------------
  // Vendored + wired in Slice 2; guarded so a telnet-only build never throws.
  _mountVnc(rec, token, onFailed = null) {
    if (typeof window.RFB === 'undefined') {
      if (onFailed) { onFailed(); return; }
      rec.pane.textContent = 'VNC console not available in this build (Slice 2).'; return;
    }
    // Toolbar (reuses rdp-toolbar styling) + inner VNC screen stacked via flexbox.
    // Set flex-direction always, but only un-hide the pane (display:flex) when this
    // tab is active — a docker fallback's VNC attempt can run while another tab is
    // showing, and forcing display:flex here would pop this pane over it. onShow
    // sets display:flex when the user actually switches to this tab.
    rec.pane.innerHTML = '';
    rec.pane.style.flexDirection = 'column';
    if (this.activeId === rec.node.id) rec.pane.style.display = 'flex';
    const bar = document.createElement('div'); bar.className = 'rdp-toolbar';
    // Ctrl+Alt+Del — the VNC lane previously had no way to send it (Windows /
    // graphical guests need it for the login/lock screen). Match the RDP toolbar's
    // text-label button styling (.rdp-tbtn). The vendored noVNC 1.5.0 exposes
    // rfb.sendCtrlAltDel(); guarded so a build without it never throws.
    const cadBtn = document.createElement('button'); cadBtn.className = 'rdp-tbtn';
    cadBtn.textContent = 'Ctrl+Alt+Del'; cadBtn.title = 'Send Ctrl+Alt+Del';
    cadBtn.onclick = (e) => {
      e.preventDefault(); e.stopPropagation();
      try {
        if (typeof rfb.sendCtrlAltDel === 'function') {
          rfb.sendCtrlAltDel();                          // noVNC native combo
        } else {
          // Fallback: send the three keysyms (press then reverse release) via the
          // modern sendKey(keysym, code, down) API. Delete is 0xFFFF.
          rfb.sendKey(0xFFE3, 'ControlLeft', true);
          rfb.sendKey(0xFFE9, 'AltLeft', true);
          rfb.sendKey(0xFFFF, 'Delete', true);
          rfb.sendKey(0xFFFF, 'Delete', false);
          rfb.sendKey(0xFFE9, 'AltLeft', false);
          rfb.sendKey(0xFFE3, 'ControlLeft', false);
        }
      } catch (_) {}
      try { rfb.focus(); } catch (_) {}                  // refocus canvas after sending
    };
    bar.append(cadBtn);
    const clipBtn = document.createElement('button'); clipBtn.className = 'rdp-tbtn';
    clipBtn.innerHTML = '&#128203;'; clipBtn.title = 'Paste clipboard to VNC session';
    clipBtn.onclick = (e) => {
      e.preventDefault(); e.stopPropagation();
      this._showPasteDialog(rec.pane, (txt) => {
        try { rfb.clipboardPasteFrom(txt); } catch (_) {}  // noVNC 1.5.0
        try { rfb.sendClipboard(txt); }      catch (_) {}  // noVNC alt API
        rfb.focus();
      });
    };
    bar.append(clipBtn);
    const vncEl = document.createElement('div');
    vncEl.style.cssText = 'flex:1;min-height:0;background:#000;';
    rec.pane.append(bar, vncEl);

    const rfb = new window.RFB(vncEl, wsUrl(`${this.opts.vncPath}?token=${encodeURIComponent(token)}`));
    rfb.scaleViewport = true;            // fit graphical desktop to the pane
    rfb.background = '#000';

    rec.client  = { rfb };
    // Re-fit the desktop to the pane. noVNC's scaleViewport setter re-runs autoscale
    // (contain-fit) against the current screen size. Guarded to the active tab.
    const refitVnc = () => {
      if (this.activeId !== rec.node.id) return;
      try { rfb.scaleViewport = true; } catch (_) {}
    };
    // VNC was the only lane WITHOUT a resize hook (telnet has a window-resize refit,
    // RDP a ResizeObserver) — so when the tab bar wraps to another row or the window
    // resizes, the pane shrinks but the desktop kept its old (too-tall) scale and
    // overflowed (top + taskbar clipped). Observe the pane so it always re-fits.
    let vncRO = null;
    window.addEventListener('resize', refitVnc);
    if (typeof ResizeObserver !== 'undefined') {
      vncRO = new ResizeObserver(() => refitVnc());
      vncRO.observe(vncEl);
    }
    rec.onShow  = () => {
      // ROOT CAUSE of the blank/black VNC tab on return: this pane is laid out as a
      // flex column (set via cssText at mount) so the flex:1 vncEl — and noVNC's
      // 100%×100% _screen inside it — gets a height. But activate() shows a tab with
      // `pane.style.display = ''`, which WIPES that inline display:flex; the pane
      // reverts to block, vncEl collapses to 0 height, noVNC's _screen reads 0, and
      // autoscale(0) drives the scale to 0 → black. Restore the flex layout here,
      // then re-fit once it has reflowed (scaleViewport setter re-runs autoscale).
      rec.pane.style.display = 'flex';
      rfb.focus();
      [60, 350].forEach((ms) => setTimeout(refitVnc, ms));
    };
    rec.onHide  = () => rfb.blur?.();
    rec.dispose = () => {
      window.removeEventListener('resize', refitVnc);
      if (vncRO) { try { vncRO.disconnect(); } catch {} }
      try { rfb.disconnect(); } catch {}
    };

    // When used as a docker fallback step: detect connection failure and cascade
    // to the next lane. Two triggers: 'disconnect' before 'connect' fires, or an
    // 8-second silence timeout (noVNC sometimes hangs without emitting disconnect
    // when the port isn't listening). Guard against double-fire and against a
    // normal tab-close triggering the fallback inadvertently.
    if (onFailed) {
      let connected = false, fired = false;
      const fail = () => {
        if (fired) return; fired = true;
        clearTimeout(failTimer);
        try { rfb.disconnect(); } catch {}
        onFailed();
      };
      const failTimer = setTimeout(() => { if (!connected) fail(); }, 8000);
      rfb.addEventListener('connect',    () => { connected = true; clearTimeout(failTimer); });
      rfb.addEventListener('disconnect', () => { if (!connected) fail(); });
      // Wrap dispose so closing the tab doesn't trigger the fallback.
      const prevDispose = rec.dispose;
      rec.dispose = () => { fired = true; clearTimeout(failTimer); prevDispose(); };
    }
  }

  // --- rdp : Apache Guacamole client (guacd via guacamole-lite) -------------
  // Vendored + wired in Slice 3; guarded so a telnet-only build never throws.
  // The pane gets persistent chrome (a small toolbar + an on-screen keyboard)
  // that survives reconnects; only the guac display inside `.rdp-screen` is torn
  // down/rebuilt per attempt.
  _showRequiredParamsDialog(anchorEl, parameterNames, onSubmit, onCancel) {
    const names = Array.from(new Set((Array.isArray(parameterNames) ? parameterNames : [])
      .map((name) => String(name)).filter((name) => name.length > 0)));
    if (!names.length) { if (onCancel) onCancel(); return; }

    const old = anchorEl._pnqRequiredDialog;
    if (old) old.remove();

    const overlay = document.createElement('div');
    overlay.className = 'pnq-required-dlg';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:200000;';
    const box = document.createElement('div');
    const width = 320;
    const rect = anchorEl.getBoundingClientRect();
    const left = Math.round(Math.max(8, rect.left + (rect.width - width) / 2));
    const top = Math.round(Math.max(8, rect.top + 56));
    box.style.cssText =
      `position:absolute;left:${left}px;top:${top}px;width:${width}px;box-sizing:border-box;` +
      'padding:16px 18px 14px;background:rgba(255,255,255,.94);color:#1d1d1f;' +
      'border:1px solid rgba(0,0,0,.16);border-radius:12px;box-shadow:0 8px 36px rgba(0,0,0,.25);' +
      'font:13px/1.35 -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;';

    const title = document.createElement('div');
    title.textContent = 'Sign in to remote desktop';
    title.style.cssText = 'font-weight:600;margin-bottom:10px;';
    const form = document.createElement('form');
    form.autocomplete = 'off';
    form.style.cssText = 'display:flex;flex-direction:column;gap:8px;';
    const inputs = Object.create(null);
    const labels = { username: 'Username', password: 'Password', domain: 'Domain' };

    names.forEach((name) => {
      const label = document.createElement('label');
      label.textContent = labels[name] || name.charAt(0).toUpperCase() + name.slice(1);
      label.style.cssText = 'display:flex;flex-direction:column;gap:3px;color:#515154;font-size:11px;';
      const input = document.createElement('input');
      input.type = /password/i.test(name) ? 'password' : 'text';
      input.autocomplete = 'off';
      input.style.cssText =
        'box-sizing:border-box;width:100%;padding:7px 9px;background:#fff;color:#1d1d1f;' +
        'border:1px solid rgba(0,0,0,.2);border-radius:7px;font:13px -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;';
      label.append(input);
      form.append(label);
      inputs[name] = input;
    });

    const buttons = document.createElement('div');
    buttons.style.cssText = 'display:flex;justify-content:flex-end;gap:8px;margin-top:5px;';
    const cancel = document.createElement('button');
    cancel.type = 'button'; cancel.textContent = 'Cancel';
    const submit = document.createElement('button');
    submit.type = 'submit'; submit.textContent = 'Connect';
    [cancel, submit].forEach((button) => {
      button.style.cssText = 'padding:6px 14px;border-radius:7px;cursor:pointer;font:13px -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;';
    });
    submit.style.cssText += 'background:#087be8;color:white;border:0;';
    cancel.style.cssText += 'background:#f3f3f3;color:#1d1d1f;border:1px solid #ccc;';
    buttons.append(cancel, submit);
    form.append(buttons);
    box.append(title, form);
    overlay.append(box);
    document.body.append(overlay);
    anchorEl._pnqRequiredDialog = overlay;

    let closed = false;
    const close = () => {
      if (closed) return;
      closed = true;
      names.forEach((name) => { inputs[name].value = ''; });
      overlay.remove();
      if (anchorEl._pnqRequiredDialog === overlay) delete anchorEl._pnqRequiredDialog;
    };
    const cancelDialog = () => { close(); if (onCancel) onCancel(); };
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const values = Object.create(null);
      names.forEach((name) => { values[name] = inputs[name].value; });
      close();
      try { onSubmit(values); } finally { names.forEach((name) => { values[name] = ''; }); }
    });
    cancel.addEventListener('click', cancelDialog);
    overlay.addEventListener('mousedown', (event) => { if (event.target === overlay) cancelDialog(); });
    const first = inputs[names[0]];
    if (first) first.focus();
  }

  _mountRdp(rec, token, onAllFailed = null, hint = null, promptCredentials = false) {
    if (typeof window.Guacamole === 'undefined') {
      if (onAllFailed) { onAllFailed(); return; }
      rec.pane.textContent = 'RDP console not available in this build (Slice 3).'; return;
    }
    const Guacamole = window.Guacamole;

    // Persistent pane chrome: screen container + toolbar + on-screen keyboard.
    rec.pane.innerHTML = '';
    const screen = document.createElement('div'); screen.className = 'rdp-screen';
    rec._rdpScreen = screen;
    const kbd = this._buildVKeyboard(rec);           // { el }
    kbd.el.style.display = 'none';
    const bar = document.createElement('div'); bar.className = 'rdp-toolbar';
    const mk = (html, title, fn) => {
      const b = document.createElement('button'); b.className = 'rdp-tbtn';
      b.innerHTML = html; b.title = title;
      b.onclick = (e) => { e.preventDefault(); e.stopPropagation(); fn(b); };
      bar.append(b); return b;
    };
    mk('&#x21BB;', 'Refit display to window', () => { this._sendSize(rec); this._scaleGuac(rec); });
    mk('&#x2328;', 'Toggle on-screen keyboard', (b) => {
      const show = kbd.el.style.display === 'none';
      kbd.el.style.display = show ? '' : 'none';
      b.classList.toggle('active', show);
      this._scaleGuac(rec);                          // keyboard eats height — refit
    });
    mk('Ctrl+Alt+Del', 'Send Ctrl+Alt+Del', () => this._sendCtrlAltDel(rec));
    mk('&#128203;', 'Paste clipboard to remote desktop', () => {
      this._showPasteDialog(rec.pane, (txt) => {
        // Sync text into the Guacamole clipboard stream so the remote can Ctrl+V,
        // then immediately send Ctrl+V keystrokes to trigger the paste in-app.
        if (!cur) return;
        try {
          const stream = cur.client.createClipboardStream('text/plain');
          const writer = new Guacamole.StringWriter(stream);
          writer.sendText(txt); writer.sendEnd();
        } catch (_) {}
        // Ctrl+V to the remote (key down then up)
        try {
          cur.client.sendKeyEvent(1, 0xffe3);   // Ctrl down
          cur.client.sendKeyEvent(1, 0x0076);   // v down
          cur.client.sendKeyEvent(0, 0x0076);   // v up
          cur.client.sendKeyEvent(0, 0xffe3);   // Ctrl up
        } catch (_) {}
      });
    });
    // Indeterminate loading bar (top of pane) while the RDP backend warms up —
    // capture/Wireshark containers can take 10+s to accept on 3389.
    const loading = document.createElement('div'); loading.className = 'rdp-loading';
    const setLoading = (on) => { loading.style.display = on ? '' : 'none'; };
    setLoading(false);
    rec.pane.append(bar, screen, kbd.el, loading);

    // RDP backends warm up: a freshly-launched xrdp node — and especially a
    // packet-capture container the engine just created — isn't accepting on 3389
    // for a few seconds. A one-shot connect would fail and stick. So we retry the
    // connection (reusing the stateless token) until it lands or the budget runs
    // out. Each attempt is a fresh tunnel/client; `gen` guards stale callbacks.
    // When used as a fallback step (docker cascade) use a shorter budget so the
    // user reaches VNC/telnet quickly if RDP is unavailable on this container.
    // Capture (eve-wireshark) containers warm up slower than lab nodes — and far
    // slower when two start at once under host load — so give them a bigger budget
    // (token_mint already gates on :3389 readiness; this rides out a slow session
    // start without giving up).
    const isCap = !!(rec.node && rec.node.capture);
    const MAX_ATTEMPTS = onAllFailed ? 4 : (isCap ? 16 : 8), RETRY_MS = 2500;
    // Post-connect reconnects: a capture's eve-wireshark X session is spawned on
    // the FIRST connect, and under load its MIT-SHM framebuffer isn't ready when
    // guac attaches — xrdp resets ("Can't attach to shared memory" -> "Manually
    // logged off") AFTER we reached CONNECTED. The session stays alive, so a
    // reconnect lands on the now-warm desktop. Bounded so a genuinely dead
    // session still gives up.
    const MAX_POST = isCap ? 6 : 2;
    let gen = 0, settled = false, disposed = false, cur = null, postReconnects = 0;

    const teardown = () => {
      const requiredDialog = rec.pane._pnqRequiredDialog;
      if (requiredDialog) {
        requiredDialog.querySelectorAll('input').forEach((input) => { input.value = ''; });
        requiredDialog.remove();
      }
      delete rec.pane._pnqRequiredDialog;
      if (!cur) return;
      try { cur.keyboard.reset(); } catch {}
      try { cur.client.disconnect(); } catch {}
      if (cur.display && cur.display.parentNode) cur.display.parentNode.removeChild(cur.display);
      cur = null;
    };

    const connect = () => {
      const myGen = ++gen;
      const tunnel  = new Guacamole.WebSocketTunnel(wsUrl(this.opts.guacPath));
      const client  = new Guacamole.Client(tunnel);
      const display = client.getDisplay().getElement();
      screen.innerHTML = '';
      screen.append(display);
      setLoading(true);

      const mouse = new Guacamole.Mouse(display);
      // Mouse events arrive in ON-SCREEN (CSS-scaled) coordinates. The pane is
      // fit-scaled via _scaleGuac (display.scale()) whenever it isn't 1:1, so
      // sending those coordinates verbatim lands the guest pointer off by the
      // scale factor when the pane is stretched/shrunk. sendMouseState's second
      // arg divides x/y by display.getScale() and repositions the local
      // soft-cursor to match — keeping the pointer in sync at any pane size.
      mouse.onmousedown = mouse.onmouseup = mouse.onmousemove =
        (state) => client.sendMouseState(state, true);
      // Bound to the pane (focusable) so only the active RDP tab receives keys.
      const keyboard = new Guacamole.Keyboard(rec.pane);
      keyboard.onkeydown = (k) => client.sendKeyEvent(1, k);
      keyboard.onkeyup   = (k) => client.sendKeyEvent(0, k);
      // Guacamole.Mouse preventDefault()s mousedown (to suppress text selection),
      // which ALSO suppresses the browser's focus-on-click — so the focusable pane
      // never gains focus and the per-pane keyboard above never fires (display +
      // mouse work, but typing is dead). Re-assert pane focus on every pointer-down
      // (capture phase, before guac's handler) so typing works after clicking.
      display.addEventListener('mousedown', () => { try { rec.pane.focus({ preventScroll: true }); } catch (e) {} }, true);
      client.getDisplay().onresize = () => this._scaleGuac(rec);

      const retry = (why) => {
        if (settled || disposed || myGen !== gen) return;   // ignore stale handlers
        if (gen >= MAX_ATTEMPTS) {
          setLoading(false);
          if (onAllFailed) {
            // Clean up this RDP attempt and hand off to the next fallback lane.
            teardown();
            disposed = true;
            onAllFailed();
          } else {
            // hint is a context-specific tip from token_mint.php (e.g. "no
            // credentials saved, this guest may require NLA") — shown only
            // once a real connection attempt has actually exhausted its
            // retries, never up front, since plenty of uncredentialed RDP
            // guests connect fine on the first try. .rdp-screen has no
            // white-space styling of its own (plain block, default 'normal'),
            // so set pre-wrap here or the \n\n collapses into one run-on line.
            if (hint) { screen.style.whiteSpace = 'pre-wrap'; screen.textContent = `RDP console error: ${why}\n\n${hint}`; }
            else { screen.textContent = `RDP console error: ${why}`; }
          }
          return;
        }
        teardown();
        screen.textContent = `Connecting to RDP… (attempt ${gen + 1})`;
        setTimeout(() => { if (!settled && !disposed) connect(); }, RETRY_MS);
      };

      client.onstatechange = (s) => {
        if (myGen !== gen) return;
        if (s === 3) {                                                                // 3 = CONNECTED
          settled = true; setLoading(false); this._sendSize(rec); this._scaleGuac(rec);
          // A reconnect (e.g. after a page-refresh restore) often paints BLANK
          // until the remote sends a fresh frame. Nudge the size (−1px then back)
          // to force xrdp's display-update to push a full repaint, and re-scale.
          [400, 1200].forEach((ms) => setTimeout(() => {
            if (disposed || myGen !== gen) return;
            this._forceRdpRepaint(rec);
          }, ms));
        }
        else if (s === 5) {                                                          // 5 = DISCONNECTED
          if (!settled) { retry('disconnected'); }                                   // never connected -> warmup retry
          else if (!disposed && postReconnects < MAX_POST) {
            // Dropped AFTER connecting (xrdp shm-reset while the X session warmed
            // up under load). The desktop is still alive — reconnect to it.
            postReconnects++; settled = false; setLoading(true); teardown();
            screen.textContent = 'Reconnecting…';
            setTimeout(() => { if (!disposed && !settled) connect(); }, 1500);
          }
        }
      };
      client.onerror = (st) => { if (myGen !== gen) return; try { client.disconnect(); } catch {} retry((st && st.message) || 'client error'); };
      tunnel.onerror = (st) => { if (myGen !== gen) return; retry((st && st.message) || 'tunnel error'); };

      // Guacamole 1.3+ lets guacd ask for connection parameters that were not
      // supplied at connect time (normally username/password for NLA). The
      // values are sent through the protocol's argv stream and are held only
      // by this live connection; they never enter a token, URL, or browser
      // storage. StringWriter performs the required UTF-8 stream encoding.
      let requiredDialogOpen = false;
      client.onrequired = (parameterNames) => {
        if (myGen !== gen || requiredDialogOpen) return;
        const sendValues = (values) => {
          parameterNames.forEach((name) => {
            const stream = client.createArgumentValueStream('text/plain', name);
            const writer = new Guacamole.StringWriter(stream);
            writer.sendText(values[name] || '');
            writer.sendEnd();
          });
        };
        if (!promptCredentials) {
          try { sendValues(Object.create(null)); } catch (_) {}
          return;
        }
        requiredDialogOpen = true;
        this._showRequiredParamsDialog(rec.pane, parameterNames, (values) => {
          requiredDialogOpen = false;
          if (myGen !== gen) return;
          try {
            sendValues(values);
          } catch (_) {
            // The tunnel's normal error/state handlers own reconnect behavior.
          }
        }, () => {
          requiredDialogOpen = false;
          if (myGen !== gen) return;
          settled = true;
          setLoading(false);
          try { client.disconnect(); } catch (_) {}
          screen.textContent = 'RDP credentials required.';
        });
      };

      // guacamole-lite reads the encrypted (stateless) connection token from ?token=
      client.connect('token=' + encodeURIComponent(token));
      cur = { client, tunnel, keyboard, mouse, display };
      rec.client = cur;
    };

    // Auto-resize: when the pane is stretched, tell the remote to re-render at the
    // new size (resize-method:display-update) — crisp, not just a CSS zoom. Debounced
    // so a drag doesn't spam guacd. _scaleGuac stays the fallback (servers that ignore
    // display-update keep a fit-scaled canvas).
    let sizeT = null;
    const scheduleSize = () => {
      clearTimeout(sizeT);
      sizeT = setTimeout(() => { if (!disposed) { this._sendSize(rec); this._scaleGuac(rec); } }, 300);
    };
    const onResize = () => { if (this.activeId === rec.node.id) scheduleSize(); };
    window.addEventListener('resize', onResize);
    let ro = null;
    if (typeof ResizeObserver !== 'undefined') {
      ro = new ResizeObserver(() => { if (this.activeId === rec.node.id) scheduleSize(); });
      ro.observe(screen);
    }

    rec.onShow  = () => {
      rec.pane.focus(); this._sendSize(rec); this._scaleGuac(rec);
      // Returning to a hidden RDP tab shows blank/black: the pane size is unchanged
      // so display-update pushes no frame and the canvas never repaints. Force xrdp
      // to send a fresh full frame (same nudge as the post-reconnect case). Two
      // passes covers slow layout reflow after the pane un-hides.
      [60, 350].forEach((ms) => setTimeout(() => {
        if (this.activeId === rec.node.id) this._forceRdpRepaint(rec);
      }, ms));
    };
    rec.onHide  = () => { try { cur && cur.keyboard.reset(); } catch {} };  // release held keys
    rec.dispose = () => {
      disposed = true;
      window.removeEventListener('resize', onResize);
      if (ro) { try { ro.disconnect(); } catch {} }
      clearTimeout(sizeT);
      teardown();
    };

    connect();
  }

  // Tell the remote desktop to match the current pane size (needs display-update).
  _sendSize(rec) {
    const c = rec.client && rec.client.client;
    const box = rec._rdpScreen || rec.pane;
    if (!c || !box || !box.clientWidth || !box.clientHeight) return;
    try { c.sendSize(box.clientWidth, box.clientHeight); } catch {}
  }

  // Force xrdp to push a fresh FULL frame by nudging the display size −1px then
  // back. Needed whenever the canvas is blank but the pane size hasn't changed
  // (display-update only repaints on an actual size delta) — i.e. after a
  // reconnect/restore, or on returning to a tab that was hidden. Then re-fit.
  _forceRdpRepaint(rec) {
    const c = rec.client && rec.client.client;
    const box = rec._rdpScreen || rec.pane;
    if (!c || !box || !box.clientWidth || !box.clientHeight) return;
    try { c.sendSize(box.clientWidth, box.clientHeight - 1); } catch (e) {}
    try { c.sendSize(box.clientWidth, box.clientHeight); } catch (e) {}
    this._scaleGuac(rec);
  }

  // Ctrl+Alt+Del: press the three keysyms, then release in reverse.
  _sendCtrlAltDel(rec) {
    const c = rec.client && rec.client.client;
    if (!c) return;
    const keys = [0xFFE3, 0xFFE9, 0xFFFF];           // Control_L, Alt_L, Delete
    try { keys.forEach((k) => c.sendKeyEvent(1, k)); keys.slice().reverse().forEach((k) => c.sendKeyEvent(0, k)); } catch {}
  }

  // Compact offline on-screen keyboard. Sticky modifiers (Ctrl/Alt/Shift/Super)
  // are held until the next normal key, which then releases them (one-shot), so
  // Shift→a yields 'A' and Ctrl→c yields Ctrl+C. Printable keys send their BASE
  // X11 keysym (== ASCII code); guacd applies the held modifiers.
  _buildVKeyboard(rec) {
    const c = () => (rec.client && rec.client.client) || null;
    const held = new Map();                          // keysym -> button (active one-shot mods)
    const pressNormal = (ks) => {
      const cl = c(); if (!cl) return;
      try {
        cl.sendKeyEvent(1, ks); cl.sendKeyEvent(0, ks);
        held.forEach((btn, mks) => { try { cl.sendKeyEvent(0, mks); } catch {} btn.classList.remove('active'); });
        held.clear();
      } catch {}
    };
    const toggleMod = (ks, btn) => {
      const cl = c(); if (!cl) return;
      try {
        if (held.has(ks)) { cl.sendKeyEvent(0, ks); held.delete(ks); btn.classList.remove('active'); }
        else { cl.sendKeyEvent(1, ks); held.set(ks, btn); btn.classList.add('active'); }
      } catch {}
    };

    // rows: each key = [label, keysym, classes?, modifier?]
    const KS = { Esc:0xFF1B, Tab:0xFF09, Caps:0xFFE5, Enter:0xFF0D, Bksp:0xFF08, Del:0xFFFF, Space:0x20,
                 Shift:0xFFE1, Ctrl:0xFFE3, Alt:0xFFE9, Win:0xFFEB,
                 Up:0xFF52, Down:0xFF54, Left:0xFF51, Right:0xFF53 };
    const fkeys = []; for (let i = 0; i < 12; i++) fkeys.push(['F' + (i + 1), 0xFFBE + i, 'sm']);
    const chars = (s) => s.split('').map((ch) => [ch.toUpperCase(), ch.charCodeAt(0)]);
    const rows = [
      [['Esc', KS.Esc, 'sm'], ...fkeys, ['Del', KS.Del, 'sm']],
      [...chars('`1234567890-='), ['Bksp', KS.Bksp, 'wide']],
      [['Tab', KS.Tab, 'wide'], ...chars('qwertyuiop[]\\')],
      [['Caps', KS.Caps, 'wide'], ...chars("asdfghjkl;'"), ['Enter', KS.Enter, 'wide']],
      [['Shift', KS.Shift, 'wide', true], ...chars('zxcvbnm,./'), ['↑', KS.Up, 'sm']],
      [['Ctrl', KS.Ctrl, 'wide', true], ['Win', KS.Win, 'sm', true], ['Alt', KS.Alt, 'wide', true],
       ['Space', KS.Space, 'space'], ['Alt', KS.Alt, 'wide', true], ['Ctrl', KS.Ctrl, 'wide', true],
       ['←', KS.Left, 'sm'], ['↓', KS.Down, 'sm'], ['→', KS.Right, 'sm']],
    ];

    const el = document.createElement('div'); el.className = 'vkbd';
    el.addEventListener('mousedown', (e) => e.preventDefault());   // keep guac focus
    rows.forEach((row) => {
      const r = document.createElement('div'); r.className = 'vk-row';
      row.forEach(([label, ks, cls, isMod]) => {
        const k = document.createElement('button');
        k.className = 'vk-key' + (cls ? ' ' + cls : '') + (isMod ? ' mod' : '');
        k.innerHTML = label;
        k.onclick = (e) => { e.preventDefault(); e.stopPropagation(); isMod ? toggleMod(ks, k) : pressNormal(ks); };
        r.append(k);
      });
      el.append(r);
    });
    return { el };
  }

  _scaleGuac(rec) {
    const disp = rec.client?.client?.getDisplay?.();
    if (!disp) return;
    const w = disp.getWidth(), h = disp.getHeight();
    if (!w || !h) return;
    const box = rec._rdpScreen || rec.pane;
    // Fit (contain) to the pane — allow UPSCALING too (no cap at 1) so a fixed-
    // resolution server (e.g. the Wireshark capture container's Xvnc, whose desktop
    // is a fixed 1440x900) still fills a stretched pane, like noVNC's scaleViewport.
    // Servers that re-render at the client size keep scale ~1.
    disp.scale(Math.min(box.clientWidth / w, box.clientHeight / h));
  }
}
