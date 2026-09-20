/* console-init.js — EVE-NG Resolute web console (GUI ported from PNETLab).
 *
 * ES module. Wires the vendored ConsoleTabs engine (console-tabs.js, verbatim
 * from PNETLab) to EVE's session-scoped API:
 *   GET /api/labs/session/info    -> { data: { id } }        (open lab uuid)
 *   GET /api/labs/session/nodes   -> { data: { <id>: node } } (name/console/status)
 * Tokens are minted per-connection by token_mint.php (same dir), which re-checks
 * EVE's session cookie + lab ownership server-side.
 */
import { ConsoleTabs } from './console-tabs.js?v=b8f31ddb';
// noVNC RFB is an ES module (default export). Vendored locally; expose it as the
// global console-tabs.js::_mountVnc looks for. The static import resolves its
// whole dependency graph before this body runs, so window.RFB is ready well
// before any VNC tab is lazily mounted.
import RFB from './vendor/novnc/core/rfb.js';
window.RFB = RFB;

const tabs = new ConsoleTabs(document.getElementById('console'));
const list = document.getElementById('node-list');
const refreshEl = document.getElementById('nodes-refresh');

// Collapsible node sidebar: arrow collapses the list to a thin rail (the console
// fills the width); clicking the arrow re-pins. State persists across opens.
const COLLAPSE_KEY = 'eve_wc_nodes_collapsed';
const nodesEl  = document.getElementById('nodes');
const toggleEl = document.getElementById('nodes-toggle');
function applyCollapsed(c) {
  nodesEl.classList.toggle('collapsed', c);
  toggleEl.innerHTML = c ? '&#xBB;' : '&#xAB;';            // » expand : « collapse
  toggleEl.title = c ? 'Pin sidebar open' : 'Collapse sidebar';
}
let collapsed = localStorage.getItem(COLLAPSE_KEY) === '1';
applyCollapsed(collapsed);
toggleEl.addEventListener('click', (e) => {
  e.stopPropagation();
  collapsed = !collapsed;
  localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
  applyCollapsed(collapsed);
});

// Single-node / pop-out launch (/console/?node=ID): this window is dedicated to
// ONE node (a node-console click from the lab canvas), so hide the node list.
// The combined "all nodes" window is opened without ?node and keeps the list.
const q = new URLSearchParams(location.search);
const isSingleNode = !!q.get('node');
if (isSingleNode) nodesEl.style.display = 'none';
if (refreshEl) refreshEl.hidden = isSingleNode;

// EVE docker templates may serve any protocol regardless of the console field,
// so docker nodes ride the rdp -> vnc -> telnet cascade in _mountDockerFallback.
const GRAPHICAL_DOCKER = /eve-(?:chrome|firefox|desktop)-/i;

// Map the engine console type to a console lane. telnet + 'bash' are both
// telnet-lane (device.php maps both to telnet); 'vnc' is its own lane; 'rdp' +
// 'rdp-tls' both ride the rdp lane — guacd's protocol is 'rdp' either way, and
// token_mint pins security=tls for rdp-tls. An EMPTY console type means telnet:
// IOL nodes report '' but serve a telnet line (mirrors __node.php::getConsoleUrl()
// default). When the lane would default to telnet but the node is a graphical
// desktop docker, promote it to rdp (an explicit rdp/vnc console type always wins).
const laneOf = (c, image) => {
  const base =
    (!c || c === 'telnet' || c === 'bash') ? 'telnet'
    : (c === 'rdp' || c === 'rdp-tls')     ? 'rdp'
    : c;
  if (base === 'telnet' && image && GRAPHICAL_DOCKER.test(image)) return 'rdp';
  return base;
};

function addButton(node) {
  const lane = laneOf(node.console, node.image);
  const row = document.createElement('div');
  row.className = 'node-row';
  row.dataset.nid = String(node.id);
  const btn = document.createElement('button');
  btn.className = 'node-open';
  // Show the effective console type (an empty IOL console is the telnet lane; a
  // graphical docker promoted off telnet shows its real rdp lane, not 'telnet').
  const badge = node.console ? (laneOf(node.console) === lane ? node.console : lane) : lane;
  const dot = node.status === 2 ? '<span class="status-dot active" title="Running"></span>' : '<span class="status-dot" title="Stopped"></span>';
  btn.innerHTML = `${dot}${node.name}<span class="badge">${badge}</span>`;
  row.append(btn);
  if (lane === 'telnet' || lane === 'vnc' || lane === 'rdp') {
    btn.addEventListener('click', () => tabs.openNode({ id: node.id, name: node.name, type: lane, isDocker: node.nodeType === 'docker' }));
  } else {
    // Any other graphical lane (spice/…) isn't wired yet — show, don't pretend.
    btn.disabled = true;
    btn.title = `${node.console} console: available in a later release`;
    btn.style.opacity = '0.5';
  }
  list.append(row);
}

// Real running topology: the session nodes API exposes each node's console type,
// name and status for the user's OPEN lab (the ownership boundary). A freshly-
// opened lab can briefly return an empty table while it materialises — retry a
// few times before treating that as final.
const NODE_LOAD_DELAYS = [250, 500, 1000];
const fetchNodes = async () => {
  let lastError = null;
  for (let attempt = 0; attempt <= NODE_LOAD_DELAYS.length; attempt += 1) {
    try {
      const r = await fetch('/api/labs/session/nodes', { credentials: 'same-origin', cache: 'no-store' });
      if (!r.ok) throw new Error(`nodes API ${r.status}`);
      const j = await r.json();
      if (!j || (j.status !== 'success' && j.code !== 200)) throw new Error((j && j.message) || 'nodes API failed');
      const data = j.data || {};
      const entries = Array.isArray(data) ? data.map((n) => [n.id, n]) : Object.entries(data);
      if (entries.length || attempt >= NODE_LOAD_DELAYS.length) return entries;
    } catch (e) {
      lastError = e;
      if (attempt >= NODE_LOAD_DELAYS.length) throw e;
    }
    await new Promise((resolve) => setTimeout(resolve, NODE_LOAD_DELAYS[attempt]));
  }
  throw lastError || new Error('nodes API failed');
};

function renderNodes(entries) {
  list.textContent = '';
  if (!entries.length) {
    list.textContent = 'No nodes in the open lab.';
    return [];
  }
  const nodes = entries
      .map(([id, n]) => ({ id: String(n.id ?? id), name: n.name || `Node ${id}`, console: n.console, image: n.image, status: n.status ?? 0, nodeType: n.type || '' }))
      .sort((a, b) => Number(a.id) - Number(b.id));
  nodes.forEach(addButton);
  return nodes;
}

// Fetch + render is deliberately reusable: the header refresh button calls the
// same path as initial load. Keep it single-flight so repeated clicks cannot
// race and let an older response overwrite a newer node list.
let nodeLoadPromise = null;
function setRefreshBusy(busy) {
  if (!refreshEl) return;
  refreshEl.disabled = busy;
  refreshEl.classList.toggle('is-loading', busy);
  refreshEl.setAttribute('aria-busy', busy ? 'true' : 'false');
  if (!busy) refreshEl.title = 'Refresh node list';
}
function loadNodes() {
  if (nodeLoadPromise) return nodeLoadPromise;
  list.setAttribute('aria-busy', 'true');
  setRefreshBusy(true);
  nodeLoadPromise = fetchNodes()
    .then((entries) => ({ entries, nodes: renderNodes(entries) }))
    .finally(() => {
      list.removeAttribute('aria-busy');
      setRefreshBusy(false);
      nodeLoadPromise = null;
    });
  return nodeLoadPromise;
}

// In single-node mode the sidebar is hidden, so a load failure must surface in
// the console pane itself (otherwise the user gets a blank page).
function reportNodeLoadError(error) {
  const message = error && error.message ? error.message : 'nodes API failed';
  if (!list.querySelector('.node-row')) list.textContent = `Could not load nodes: ${message}`;
  if (refreshEl) refreshEl.title = `Could not refresh nodes: ${message}. Click to retry.`;
  if (isSingleNode) {
    const pane = document.getElementById('console');
    pane.innerHTML = `<div style="padding:24px;color:#f88;font:14px/1.6 system-ui,sans-serif">Could not open the console.<br><span style="color:#aaa">${message}</span><br><br>Open the lab in this browser session first (the console is bound to your open lab).</div>`;
  }
}

if (refreshEl && !isSingleNode) {
  refreshEl.addEventListener('click', (event) => {
    event.stopPropagation();
    loadNodes().catch(() => reportNodeLoadError(new Error('nodes API failed')));
  });
}

loadNodes()
.then(({ entries, nodes }) => {
  // Poll every 5 s and update status dots so a node that starts while the console
  // is open transitions from grey to green without a page reload.
  const updateDots = () => {
    fetch('/api/labs/session/nodes', { credentials: 'same-origin' })
      .then((r) => r.ok ? r.json() : null)
      .then((j) => {
        if (!j || !j.data) return;
        const src = Array.isArray(j.data) ? j.data.map((n) => [n.id, n]) : Object.entries(j.data);
        src.forEach(([id, n]) => {
          const row = list.querySelector(`[data-nid="${CSS.escape(String(n.id ?? id))}"]`);
          if (!row) return;
          const dot = row.querySelector('.status-dot');
          if (!dot) return;
          const live = (n.status ?? 0) === 2;
          dot.classList.toggle('active', live);
          dot.title = live ? 'Running' : 'Stopped';
        });
      })
      .catch(() => {});
  };
  const _pollTimer = setInterval(updateDots, 5000);
  window.addEventListener('pagehide', () => clearInterval(_pollTimer), { once: true });

  // /console/?all=1 : open every console-capable node as a tab in this one window.
  if (q.get('all') === '1') {
    nodes.forEach((n) => {
      const lane = laneOf(n.console, n.image);
      if (lane === 'telnet' || lane === 'vnc' || lane === 'rdp') tabs.openNode({ id: n.id, name: n.name, type: lane, isDocker: n.nodeType === 'docker' });
    });
  }

  // /console/?nodes=<csv> : reopen exactly these tabs (session restore).
  const wantNodes = (q.get('nodes') || '').split(',').map((s) => s.trim()).filter(Boolean);
  if (wantNodes.length) {
    wantNodes.forEach((id) => {
      const m = nodes.find((n) => String(n.id) === String(id));
      if (!m) return;
      const lane = laneOf(m.console, m.image);
      if (lane === 'telnet' || lane === 'vnc' || lane === 'rdp') tabs.openNode({ id: m.id, name: m.name, type: lane, isDocker: m.nodeType === 'docker' });
    });
  }

  // Deep-link: /console/?node=<id> auto-opens that node (lab-canvas console click).
  const wantId = q.get('node');
  if (wantId) {
    const hit = entries.find(([id, n]) => String(n.id ?? id) === wantId);
    if (hit) {
      const n = hit[1];
      const nm = n.name || `Node ${wantId}`;
      const wlane = laneOf(n.console, n.image);
      if (wlane === 'telnet' || wlane === 'vnc' || wlane === 'rdp') {
        tabs.openNode({ id: wantId, name: nm, type: wlane, isDocker: (n.type || '') === 'docker' });
      } else {
        reportNodeLoadError(new Error(`node ${wantId} has no wired console lane (${wlane})`));
      }
    } else {
      reportNodeLoadError(new Error(`node ${wantId} not found in the open lab`));
    }
  }
})
.catch((error) => reportNodeLoadError(error));
