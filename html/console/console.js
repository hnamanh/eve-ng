/* console.js — EVE-NG Resolute web-console viewer (ported from PNETLab).
 *
 * One node per page. URL contract (set by __node.php::getConsoleUrl):
 *   /console/?node=<id>&type=vnc|telnet|rdp&name=<label>
 *
 * Flow: mint a short-lived token from token_mint.php (session-gated, lab-ownership
 * checked server-side), then mount the lane's client against the loopback bridge:
 *   vnc    -> noVNC RFB  -> ws://host/vnc/?token=     (console_mux.py relay)
 *   telnet -> xterm.js   -> ws://host/telnet/?token=&cols=&rows=  (console_mux.py)
 *   rdp    -> guacamole-common -> ws://host/guac/ + client.connect('token=<AES blob>')
 *            (guacamole-lite-server.js decrypts the blob and dials the node via guacd)
 */
import RFB from './vendor/novnc/core/rfb.js';

const q = new URLSearchParams(location.search);
const NODE   = q.get('node') || '';
const TYPE   = (q.get('type') || 'telnet').toLowerCase();
const NAME   = q.get('name') || ('Node ' + NODE);

const pane   = document.getElementById('pane');
const statusEl = document.getElementById('status');
const titleEl  = document.getElementById('title');
const cadBtn   = document.getElementById('btn-cad');
titleEl.textContent = `${NAME} — ${TYPE}`;

const wsUrl = (path) => {
  const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
  return `${proto}//${location.host}${path}`;
};
const setStatus = (t, color) => { statusEl.textContent = t; statusEl.style.color = color || '#8fa1c0'; };

async function mint() {
  const r = await fetch(`token_mint.php?node=${encodeURIComponent(NODE)}&type=${encodeURIComponent(TYPE)}`,
                        { credentials: 'same-origin' });
  if (!r.ok) {
    let msg = `token mint failed (${r.status})`;
    try { const j = await r.json(); if (j && j.error) msg += ': ' + j.error; } catch {}
    throw new Error(msg);
  }
  return r.json();   // { token, expires_in }
}

function showError(err) {
  setStatus('error', '#ff9c9c');
  pane.innerHTML = '';
  const d = document.createElement('div');
  d.id = 'err';
  d.textContent = `Console error: ${err && err.message ? err.message : err}`;
  pane.appendChild(d);
}

let clientRef = null;   // { dispose() } for the active lane

function mountVnc(token) {
  cadBtn.style.display = '';
  const host = document.createElement('div');
  host.className = 'vnc-host';
  pane.innerHTML = '';
  pane.appendChild(host);

  const rfb = new RFB(host, wsUrl(`/vnc/?token=${encodeURIComponent(token)}`));
  rfb.scaleViewport = true;
  rfb.background = '#000';
  rfb.addEventListener('connect',    () => setStatus('connected', '#7fd6a2'));
  rfb.addEventListener('disconnect', (e) => {
    if (!e.clean) setStatus('disconnected — click Reconnect', '#ffb454');
  });

  cadBtn.onclick = () => {
    try {
      if (typeof rfb.sendCtrlAltDel === 'function') rfb.sendCtrlAltDel();
      else {
        rfb.sendKey(0xFFE3, 'ControlLeft', true);
        rfb.sendKey(0xFFE9, 'AltLeft', true);
        rfb.sendKey(0xFFFF, 'Delete', true);
        rfb.sendKey(0xFFFF, 'Delete', false);
        rfb.sendKey(0xFFE9, 'AltLeft', false);
        rfb.sendKey(0xFFE3, 'ControlLeft', false);
      }
    } catch (_) {}
  };

  clientRef = { dispose: () => { try { rfb.disconnect(); } catch (_) {} } };
}

function mountTelnet(token) {
  if (typeof window.Terminal === 'undefined') throw new Error('xterm.js not loaded');
  const FitAddon = (window.FitAddon && window.FitAddon.FitAddon) || window.FitAddon;

  pane.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.className = 'term-wrap';
  pane.appendChild(wrap);

  const term = new window.Terminal({ cursorBlink: true, fontSize: 14 });
  if (FitAddon) { const fit = new FitAddon(); term.loadAddon(fit); }
  term.open(wrap);
  try { if (FitAddon) term.fit(); else term.scrollToBottom(); } catch (_) {}

  const enc = new TextEncoder(), dec = new TextDecoder();
  let sock = null, disposed = false, reconnTimer = null, everConnected = false;
  const RECONNECT_MS = 5000;

  const doConnect = (initialTok) => {
    if (disposed) return;
    setStatus('connecting…');
    const p = initialTok ? Promise.resolve({ token: initialTok }) : mint();
    p.then(({ token }) => {
      if (disposed) return;
      const t = term;
      const query = `token=${encodeURIComponent(token)}&cols=${t.cols}&rows=${t.rows}&term=xterm-256color`;
      const ws = new WebSocket(wsUrl(`/telnet/?${query}`));
      ws.binaryType = 'arraybuffer';
      ws.onmessage = (e) => {
        const text = (typeof e.data === 'string') ? e.data : dec.decode(new Uint8Array(e.data), { stream: true });
        term.write(text);
      };
      ws.onopen = () => {
        setStatus('connected', '#7fd6a2');
        if (everConnected) term.write('\r\n\x1b[32m[Reconnected]\x1b[0m\r\n');
        everConnected = true;
        // elicit the device prompt
        ws.send(enc.encode('\r'));
      };
      ws.onclose = () => {
        if (disposed) return;
        sock = null;
        setStatus('reconnecting…', '#ffb454');
        term.write('\r\n\x1b[33m[Console disconnected — waiting for node…]\x1b[0m\r\n');
        reconnTimer = setTimeout(() => doConnect(null), RECONNECT_MS);
      };
      ws.onerror = () => { try { ws.close(); } catch (_) {} };
      sock = ws;
    }).catch((e) => {
      if (!disposed) { setStatus('mint failed — retrying', '#ffb454'); reconnTimer = setTimeout(() => doConnect(null), RECONNECT_MS); }
    });
  };

  term.onData((d) => { if (sock && sock.readyState === WebSocket.OPEN) sock.send(enc.encode(d)); });
  const sendResize = () => {
    if (sock && sock.readyState === WebSocket.OPEN)
      sock.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));
  };
  term.onResize(sendResize);
  window.addEventListener('resize', () => { try { if (FitAddon) term.fit(); sendResize(); } catch (_) {} });

  doConnect(token);   // first connect reuses the minted token

  clientRef = { dispose: () => {
    disposed = true;
    clearTimeout(reconnTimer);
    if (sock) { try { sock.onclose = null; sock.close(); } catch (_) {} }
    try { term.dispose(); } catch (_) {}
  }};
}

function mountRdp(token) {
  const Guacamole = window.Guacamole;
  if (!Guacamole) throw new Error('guacamole-common not loaded');
  cadBtn.style.display = '';

  pane.innerHTML = '';
  const screen = document.createElement('div');
  screen.className = 'rdp-screen';
  pane.appendChild(screen);

  let gen = 0, disposed = false, cur = null;
  const MAX_ATTEMPTS = 8, RETRY_MS = 2500;

  const teardown = () => {
    if (!cur) return;
    try { cur.keyboard.reset(); } catch (_) {}
    try { cur.client.disconnect(); } catch (_) {}
    if (cur.display && cur.display.parentNode) cur.display.parentNode.removeChild(cur.display);
    cur = null;
  };

  const connect = () => {
    const myGen = ++gen;
    setStatus(`connecting RDP… (${myGen})`);
    screen.innerHTML = '';
    const tunnel  = new Guacamole.WebSocketTunnel(wsUrl('/guac/'));
    const client  = new Guacamole.Client(tunnel);
    const display = client.getDisplay().getElement();
    display.style.width = '100%'; display.style.height = '100%';
    screen.appendChild(display);

    const mouse = new Guacamole.Mouse(display);
    mouse.onmousedown = mouse.onmouseup = mouse.onmousemove = (state) => client.sendMouseState(state, true);
    const keyboard = new Guacamole.Keyboard(pane);
    keyboard.onkeydown = (k) => client.sendKeyEvent(1, k);
    keyboard.onkeyup   = (k) => client.sendKeyEvent(0, k);

    const retry = (why) => {
      if (disposed || myGen !== gen) return;
      if (gen >= MAX_ATTEMPTS) { setStatus('RDP failed', '#ff9c9c'); screen.textContent = `RDP console error: ${why}`; return; }
      teardown();
      screen.textContent = `Connecting to RDP… (attempt ${gen + 1})`;
      setTimeout(() => { if (!disposed && myGen === gen) connect(); }, RETRY_MS);
    };

    client.onstatechange = (s) => {
      if (myGen !== gen) return;
      if (s === 3) setStatus('connected', '#7fd6a2');          // CONNECTED
      else if (s === 5 && !disposed) retry('disconnected');     // DISCONNECTED before settle -> warmup retry
    };
    client.onerror = (st) => { if (myGen !== gen) return; try { client.disconnect(); } catch (_) {} retry((st && st.message) || 'client error'); };
    tunnel.onerror = (st) => { if (myGen !== gen) return; retry((st && st.message) || 'tunnel error'); };

    // guacamole-lite reads the encrypted stateless connection token from ?token=
    client.connect('token=' + encodeURIComponent(token));
    cur = { client, tunnel, keyboard, mouse, display };
  };

  cadBtn.onclick = () => {
    const c = cur && cur.client; if (!c) return;
    const keys = [0xFFE3, 0xFFE9, 0xFFFF];
    try { keys.forEach((k) => c.sendKeyEvent(1, k)); keys.slice().reverse().forEach((k) => c.sendKeyEvent(0, k)); } catch (_) {}
  };

  connect();

  clientRef = { dispose: () => { disposed = true; teardown(); } };
}

async function start() {
  if (!NODE) return showError(new Error('missing ?node= parameter'));
  setStatus('minting token…');
  let tok;
  try { tok = await mint(); } catch (e) { return showError(e); }
  try {
    if (TYPE === 'vnc') mountVnc(tok.token);
    else if (TYPE === 'rdp') mountRdp(tok.token);
    else mountTelnet(tok.token);
  } catch (e) { showError(e); }
}

document.getElementById('btn-reconnect').onclick = () => {
  try { clientRef && clientRef.dispose(); } catch (_) {}
  start();
};

start();
