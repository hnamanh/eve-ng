<?php
# vim: syntax=php tabstop=4 softtabstop=4 noexpandtabs laststatus=1 ruler

/**
 * html/console/token_mint.php — session-gated console token minter.
 *
 * Ported from PNETLab's web-console design (see pnetlab-network-install-latest.sh):
 * the browser never talks to a raw node port directly. It asks THIS endpoint for a
 * short-lived, single-purpose token; the loopback-only bridges (console_mux.py for
 * vnc/telnet, guacamole-lite-server.js for rdp) resolve that token to host:port and
 * relay. The only thing between a browser and a node console is the auth + lab
 * ownership check below — we reuse the engine's OWN session cookie and the user's
 * open-lab boundary (pods.lab_id), not a placeholder.
 *
 *   GET /console/token_mint.php?node=<id>&type=vnc|telnet|rdp[&second=1]
 *     -> { "token": "<32 hex>", "expires_in": 60 }
 *
 * vnc/telnet: token is a file in TOKEN_DIR, one line "<token>: <host>:<port>".
 * rdp        : token is a self-contained AES-256-CBC blob (GUAC_CRYPT_KEY) carrying
 *              the whole connection; guacamole-lite decrypts it server-side. No /dev/shm state.
 */

// Standalone endpoint: nothing has bootstrapped yet when Apache runs this file,
// so resolve paths from here. init.php expects cwd == html/ and defines BASE_DIR.
chdir(dirname(__DIR__));                                  // .../html
require_once dirname(__DIR__).'/includes/init.php';       // constants + Lab/Node classes
// init.php does not load the DB/auth helpers — pull them in explicitly.
require_once dirname(__DIR__).'/includes/functions.php';
require_once dirname(__DIR__).'/includes/api_authentication.php';

// Shared constants (TOKEN_DIR, TOKEN_TTL, GUAC_CRYPT_KEY). Kept OUTSIDE the web root
// so the secret is never web-served. Installed by install-ubuntu26.sh.
$cfg = '/etc/eve-webconsole/console_config.php';
if (is_readable($cfg)) {
    require_once $cfg;
} else {
    error_log("eve-webconsole: optional console config missing or unreadable: $cfg");
}
if (!defined('TOKEN_DIR'))   define('TOKEN_DIR', '/dev/shm/eve-tokens');
if (!defined('TOKEN_TTL'))   define('TOKEN_TTL', 60);

header('Content-Type: application/json');
header('Cache-Control: no-store');

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

/* ---- 1. AUTH — reuse the engine's real session cookie ------------------- */
$db = checkDatabase();
$cookie = isset($_COOKIE['unetlab_session']) ? $_COOKIE['unetlab_session'] : '';
list($user, $tenant, $output) = apiAuthorization($db, $cookie);
if (empty($user)) {
    fail(401, 'not authenticated');
}

/* ---- 2. Params ---------------------------------------------------------- */
$ALLOWED_TYPES = ['telnet', 'vnc', 'rdp'];
$nodeId = isset($_GET['node']) ? (string) $_GET['node'] : '';
$type   = isset($_GET['type']) ? (string) $_GET['type'] : '';
if ($nodeId === '' || !in_array($type, $ALLOWED_TYPES, true)) {
    fail(400, 'bad params');
}

/* ---- 3. Resolve node -> backend target, enforcing lab ownership --------- */
// Ownership is the engine's own boundary: pods.lab_id holds the lab file this user
// has open in their session (set by updatePodLab when they open a lab). We load ONLY
// that lab with their tenant and look the node up inside it. A node not in that lab
// simply isn't found, so a user can never reach another tenant's nodes.
$lab_file = isset($user['lab']) ? (string) $user['lab'] : '';
if ($lab_file === '') {
    fail(409, 'no lab open in this session');
}
// pods.lab_id stores the path relative to BASE_LAB with a leading slash
// (e.g. "/admin/foo.unl" — see api.php's $lab_file extraction). Normalize it.
$lab_file = ltrim($lab_file, '/');
$full = BASE_LAB.'/'.$lab_file;
if (!is_file($full)) {
    fail(409, 'open lab not found on disk');
}

try {
    $lab = new Lab($full, $tenant);
} catch (Exception $e) {
    fail(500, 'cannot open lab: '.(string)$e);
}

$nodes = $lab->getNodes();
if (!isset($nodes[$nodeId])) {
    fail(404, 'node not found in your lab');   // also the cross-tenant denial path
}
$node = $nodes[$nodeId];

// Console type: an empty console defaults to telnet (IOL reports '' but serves a
// telnet line — mirrors __node.php::getConsoleUrl()).
$console = $node->getConsole();
if ($console === '' || $console === null) {
    $console = 'telnet';
}
$port = (int) $node->getPort();

// Lane must match the node's console family.
if ($type === 'vnc') {
    if ($console !== 'vnc') fail(409, "node console '$console' is not a vnc lane");
} elseif ($type === 'telnet') {
    if (!in_array($console, ['telnet', 'bash'], true)) fail(409, "node console '$console' is not a telnet lane");
} elseif ($type === 'rdp') {
    if (!in_array($console, ['rdp', 'rdp-tls'], true)) fail(409, "node console '$console' is not an rdp lane");
}

if ($port <= 0) {
    fail(409, 'node has no console port (is it started?)');
}

// The bridge runs on this host; EVE maps a node's console to 127.0.0.1:<host port>.
$chost = '127.0.0.1';

/* ---- 4. Mint ------------------------------------------------------------- */
if ($type === 'rdp') {
    $settings = [
        'security' => ($console === 'rdp-tls') ? 'tls' : 'any',
        'ignore-cert' => 'true',
        'resize-method' => 'display-update',
        'color-depth' => '16',
        'enable-wallpaper' => 'false',
        'enable-theming' => 'false',
        'enable-font-smoothing' => 'true',
        'enable-full-window-drag' => 'false',
        'enable-desktop-composition' => 'false',
        'enable-menu-animations' => 'false',
    ];
    // EVE stores node options as a qemu_options string (no structured creds), so
    // RDP always uses disable-auth: guacd reaches the guest's own login screen.
    $settings['disable-auth'] = 'true';
    if ($console !== 'rdp-tls') $settings['security'] = 'rdp';
    $token = mint_guac_token($chost, $port, $settings);
    echo json_encode(['token' => $token, 'expires_in' => TOKEN_TTL]);
    exit;
}

// vnc / telnet: shared tmpfs TokenFile store read by console_mux.py.
$token = bin2hex(random_bytes(16));
$line  = sprintf("%s: %s:%d\n", $token, $chost, $port);
if (!is_dir(TOKEN_DIR)) { @mkdir(TOKEN_DIR, 0750, true); }
$path  = rtrim(TOKEN_DIR, '/') . '/' . $token;
if (@file_put_contents($path, $line, LOCK_EX) === false) {
    fail(500, 'could not write token');
}
@chmod($path, 0640);
echo json_encode(['token' => $token, 'expires_in' => TOKEN_TTL]);
exit;

/* ===== helpers ========================================================== */

/**
 * Build a guacamole-lite RDP connection token: a self-contained AES-256-CBC blob
 * carrying the whole connection, decrypted server-side by guacamole-lite with the
 * shared GUAC_CRYPT_KEY. Wire format (Node crypto-compatible):
 *   base64( JSON{ iv: base64(16-byte IV), value: base64(ciphertext) } )
 */
function mint_guac_token($host, $port, $settings) {
    if (!defined('GUAC_CRYPT_KEY')) fail(500, 'rdp console not configured (GUAC_CRYPT_KEY missing)');
    $key = (string) GUAC_CRYPT_KEY;
    if (strlen($key) !== 32) fail(500, 'GUAC_CRYPT_KEY must be exactly 32 bytes');

    $conn = [
        'connection' => [
            'type'     => 'rdp',
            'settings' => array_merge(['hostname' => $host, 'port' => (string) $port], (array) $settings),
        ],
    ];
    $iv = random_bytes(16);
    // openssl_encrypt without OPENSSL_RAW_DATA returns base64 ciphertext — exactly
    // what guacamole-lite's decipher expects for `value`.
    $value = openssl_encrypt(json_encode($conn), 'AES-256-CBC', $key, 0, $iv);
    if ($value === false) fail(500, 'token encryption failed');
    return base64_encode(json_encode(['iv' => base64_encode($iv), 'value' => $value]));
}
