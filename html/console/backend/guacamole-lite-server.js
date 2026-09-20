// guacamole-lite-server.js
// Lightweight Node replacement for the Guacamole Java/Tomcat webapp.
// Browser (guacamole-common.js) --ws--> here --TCP--> guacd --RDP--> node.
//
// guacd is the proven FreeRDP-based engine PNETLab already ships for its
// HTML5 console; we just keep it and drop Tomcat.
//
//   npm install guacamole-lite
//   GUAC_CRYPT_KEY=<32-byte key, shared with token_mint.php> node guacamole-lite-server.js
//
// Env:
//   GUAC_WS_PORT   listen port for the browser WebSocket (default 8081)
//   GUAC_WS_HOST   listen host (default 127.0.0.1, fronted by Apache)
//   GUACD_HOST     guacd host (default 127.0.0.1)
//   GUACD_PORT     guacd port (default 4822)
//   GUAC_CRYPT_KEY AES-256 key, MUST be exactly 32 bytes, == PHP GUAC_CRYPT_KEY

const GuacamoleLite = require('guacamole-lite');

const key = process.env.GUAC_CRYPT_KEY || '';
if (Buffer.byteLength(key, 'utf8') !== 32) {
  console.error('GUAC_CRYPT_KEY must be exactly 32 bytes (got %d)', Buffer.byteLength(key, 'utf8'));
  process.exit(1);
}

const websocketOptions = {
  host: process.env.GUAC_WS_HOST || '127.0.0.1',
  port: Number(process.env.GUAC_WS_PORT || 8081),
};

const guacdOptions = {
  host: process.env.GUACD_HOST || '127.0.0.1',
  port: Number(process.env.GUACD_PORT || 4822),
};

const clientOptions = {
  // Decrypts the ?token= minted by token_mint.php (AES-256-CBC, {iv,value}).
  crypt: { cypher: 'AES-256-CBC', key },
  log: { level: 'NORMAL' },

  // Disable guacamole-lite's inactivity reaper (default 10 s). It closes a
  // WebSocket when no data arrives from the browser within the window — but a
  // freshly-launched eve-wireshark capture sends nothing until guacd starts
  // forwarding xrdp frames, and under load (two capture containers warming up at
  // once) that first frame can take >10 s, so the socket was being killed mid
  // startup -> guacd drops -> xrdp "Manually logged off" -> permanent black. It
  // also killed a capture whose tab was hidden (background iframe throttled, no
  // sync echoes). Real disconnects still close on WS/TCP close; 0 = disabled.
  maxInactivityTime: 0,

  // Server-side defaults the browser cannot override — keep security policy here,
  // not in the client. Lab Docker RDP (xrdp) usually needs lenient cert/security.
  //
  // Performance defaults mirror token_mint.php.  The token settings win when
  // the same key appears in both places; these cover any path that bypasses
  // the token (e.g. direct guacamole-lite connections during dev/debug).
  connectionDefaultSettings: {
    rdp: {
      'ignore-cert': true,
      security: 'any',
      'resize-method': 'display-update',
      'color-depth': 16,
      'enable-wallpaper': false,
      'enable-theming': false,
      'enable-font-smoothing': true,
      'enable-full-window-drag': false,
      'enable-desktop-composition': false,
      'enable-menu-animations': false,
    },
  },
};

const server = new GuacamoleLite(websocketOptions, guacdOptions, clientOptions);

server.on('error', (clientConnection, err) => {
  console.error('guacamole-lite error:', err && err.message);
});

console.log(
  'guacamole-lite ws://%s:%d -> guacd %s:%d',
  websocketOptions.host, websocketOptions.port, guacdOptions.host, guacdOptions.port,
);
