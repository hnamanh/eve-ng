<?php
/**
 * branding/api.php — GUI-configurable appliance branding (product name, login
 * header, logo). Backs the admin dashboard view at /main/#/custom.
 *
 * SPLIT AUTH — the one deliberate pre-auth surface in the dashboard family:
 *   The login page renders BEFORE any token exists, yet must show the custom
 *   name/header/logo. So the two GET actions below are PUBLIC (no auth), while
 *   every write is ADMIN-ONLY (same guard as users/api.php: authorization() ->
 *   401, then role check -> 403). What the public actions expose is exactly and
 *   only what the login page paints: a display name, one header line, and an
 *   image. No paths, no versions, no DB, no directory listing.
 *
 *   GET  ?action=config      → {name,login_header,hide_default_creds,theme_profile,logo,token} PUBLIC
 *   GET  ?action=logo        → image bytes (custom, else the stock logo)           PUBLIC
 *   POST ?action=save        {name,login_header,hide_default_creds}                ADMIN
 *   POST ?action=upload_logo multipart file=<png|jpeg>                             ADMIN
 *   POST ?action=save_theme  {theme_profile}                                       ADMIN
 *   POST ?action=reset       → deletes config + logo, back to stock                ADMIN
 *
 * PERSISTENCE — the load-bearing constraint. /opt/unetlab/html is deb-owned and
 * is REPLACED on every package upgrade, so nothing configurable may live there.
 * State goes to /opt/unetlab/data/branding/, which the deb creates but never
 * ships files into (verified: dpkg -S owns the dir, not its contents — the same
 * reason data/pki and data/satellite survive upgrades).
 *
 * NO BROKER. /opt/unetlab/data is www-data-owned, so PHP writes directly. Unlike
 * pki/api.php (CA private keys demand root isolation) nothing here needs
 * privilege, and adding a root verb for a logo would be a gratuitous attack
 * surface on pnetlab-brokerd.
 *
 * SVG is deliberately NOT accepted: an SVG served same-origin can carry <script>
 * and is a stored-XSS vector. PNG/JPEG only, magic-byte + getimagesize verified.
 */

/* Defaults live here, in ONE place: every fallback path below (missing dir,
   unreadable file, malformed JSON, bad types) funnels through defaults() so a
   broken state degrades to stock branding instead of an error. */
define('BRAND_DIR', '/opt/unetlab/data/branding');
define('BRAND_CFG', BRAND_DIR . '/config.json');
define('STOCK_LOGO', '/opt/unetlab/html/assets-common/img/logo.png');
define('BRAND_NAME_MAX', 40);      // topbar/login wordmark — longer just overflows
define('BRAND_HEADER_MAX', 120);   // one line above the login card
define('BRAND_LOGO_MAX', 524288);  // 512 KB; the logo renders at 28-150px

function defaults() {
    return [
        'name'               => 'EVE-NG',
        'login_header'       => '',
        'hide_default_creds' => false,
        'theme_profile'      => 'dark',
    ];
}

function valid_theme_profile($value) {
    return is_string($value) && in_array($value, ['light', 'dark', 'ocean-teal', 'mint-teal', 'macos-26-dark'], true);
}

/* Locate the custom logo, if any. Exactly one may exist at a time (upload_logo
   clears the other extension first), so first hit wins. Returns [path, mime] or
   null.
   Falls back to stock when the file is absent, unreadable, zero-byte, OR does
   not actually parse as a PNG/JPEG. That last check matters because the file
   lives in a writable data dir: upload_logo validates, but anything that wrote
   there out of band (a half-finished copy, a restore of a truncated backup)
   would otherwise be streamed as a broken image on the LOGIN page. getimagesize
   reads only the header, and the response is cached, so this is cheap.
   The MIME comes from the detected type, never from the file extension. */
function custom_logo() {
    foreach (['png', 'jpg'] as $ext) {
        $p = BRAND_DIR . '/logo.' . $ext;
        if (!is_file($p) || !is_readable($p) || filesize($p) <= 0) continue;
        $info = @getimagesize($p);
        if ($info === false || !isset($info[2])) continue;
        if ($info[2] === IMAGETYPE_PNG)  return [$p, 'image/png'];
        if ($info[2] === IMAGETYPE_JPEG) return [$p, 'image/jpeg'];
    }
    return null;
}

/* Read + validate the stored config. EVERY failure mode returns defaults:
   missing dir, missing file, unreadable file, truncated/malformed JSON, JSON
   that isn't an object, or fields of the wrong type. Silent by design — this
   endpoint is unauthenticated, so it must never leak a parse error or a path. */
function read_config() {
    $cfg = defaults();
    if (!is_file(BRAND_CFG) || !is_readable(BRAND_CFG)) return $cfg;
    $raw = @file_get_contents(BRAND_CFG);
    if ($raw === false || $raw === '') return $cfg;
    $j = json_decode($raw, true);
    if (!is_array($j)) return $cfg;
    if (isset($j['name']) && is_string($j['name']) && trim($j['name']) !== '') {
        $cfg['name'] = mb_substr(trim($j['name']), 0, BRAND_NAME_MAX);
    }
    if (isset($j['login_header']) && is_string($j['login_header'])) {
        $cfg['login_header'] = mb_substr(trim($j['login_header']), 0, BRAND_HEADER_MAX);
    }
    if (isset($j['hide_default_creds'])) {
        $cfg['hide_default_creds'] = (bool) $j['hide_default_creds'];
    }
    if (isset($j['theme_profile']) && is_string($j['theme_profile'])) {
        /* Retired profiles degrade to their closest surviving appearance so an
           upgrade never turns an existing light appliance dark unexpectedly. */
        $legacy = ['macos-26-light' => 'light', 'deep-teal' => 'dark'];
        $profile = isset($legacy[$j['theme_profile']]) ? $legacy[$j['theme_profile']] : $j['theme_profile'];
        if (valid_theme_profile($profile)) $cfg['theme_profile'] = $profile;
    }
    return $cfg;
}

/* Cache token: mtime+size of whichever logo is live (custom or stock) plus the
   config mtime. Pages append it as ?v=<token> so a changed logo reaches browsers
   that cached the old one, while an UNCHANGED logo keeps its cache entry. */
function brand_token() {
    $parts = [];
    $logo = custom_logo();
    $lp = $logo ? $logo[0] : STOCK_LOGO;
    if (is_file($lp)) $parts[] = filemtime($lp) . '-' . filesize($lp);
    if (is_file(BRAND_CFG)) $parts[] = filemtime(BRAND_CFG);
    return substr(md5(implode(':', $parts)), 0, 10);
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

/* ---- PUBLIC: logo bytes ----------------------------------------------------
   Streams the custom logo, else the stock one. Deliberately NOT a redirect to a
   static path (that would leak the data-dir layout) and NOT a data: URI (CSP
   img-src allows data:, but streaming keeps the pages free of inline blobs).
   nosniff + an explicit type pins interpretation; the restrictive CSP is belt
   and braces against a hypothetical future SVG. */
if ($action === 'logo') {
    $logo = custom_logo();
    $path = $logo ? $logo[0] : STOCK_LOGO;
    $type = $logo ? $logo[1] : 'image/png';
    // Last-ditch: even the stock logo missing must not 500 the login page.
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        exit('');
    }
    $etag = '"' . filemtime($path) . '-' . filesize($path) . '"';
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    header('ETag: ' . $etag);
    // Short max-age + revalidate: the ?v= token handles the common case, this
    // bounds staleness for the static refs that cannot carry a token.
    header('Cache-Control: public, max-age=300, must-revalidate');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ---- PUBLIC: config --------------------------------------------------------
   The login page's only pre-auth read. `logo` says whether a custom image is in
   play (so the UI can show "using default" without a second request); `token`
   is the cache-buster. */
if ($action === 'config') {
    $cfg = read_config();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode([
        'name'               => $cfg['name'],
        'login_header'       => $cfg['login_header'],
        'hide_default_creds' => $cfg['hide_default_creds'],
        'theme_profile'      => $cfg['theme_profile'],
        'logo'               => custom_logo() !== null,
        'token'              => brand_token(),
    ]);
    exit;
}

/* ---- everything below is ADMIN-ONLY ---------------------------------------
   Same guard as users/api.php:113-118. init.php is pulled in HERE, not at the
   top, so the public GETs above stay DB-free and cannot be broken by a wedged
   database — the login page must render even when MySQL is down. */
chdir(dirname(__DIR__));
require_once('includes/init.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out($x) { echo json_encode($x); exit; }
function fail($code, $m) { http_response_code($code); echo json_encode(['error' => $m]); exit; }
function body() { $b = json_decode(file_get_contents('php://input'), true); return is_array($b) ? $b : []; }

require_once('includes/api_authentication.php');
$db = checkDatabase();
list($user, , $autherr) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
if ($user === false || empty($user)) fail(401, 'not authenticated');
$role = isset($user['role']) ? $user['role'] : '';
$isAdmin = (strtolower((string) $role) === 'admin');
if (!$isAdmin) fail(403, 'branding is admin-only');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST required');

/* Create the state dir on first write. The deb ships /opt/unetlab/data owned by
   www-data, so no privilege is needed. */
function ensure_dir() {
    if (!is_dir(BRAND_DIR)) {
        if (!@mkdir(BRAND_DIR, 0755, true) && !is_dir(BRAND_DIR)) {
            fail(500, 'Could not create the branding directory.');
        }
    }
}

/* Serialized atomic mutation: branding and theme controls update independent
   fields, so merge under one stable lock and publish through a unique temp. */
function mutate_config($mutator) {
    ensure_dir();
    $lock = @fopen(BRAND_DIR . '/config.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) fail(500, 'Could not lock the branding config.');
    $cfg = $mutator(read_config());
    $tmp = @tempnam(BRAND_DIR, '.config-');
    if ($tmp === false) { @flock($lock, LOCK_UN); @fclose($lock); fail(500, 'Could not prepare the branding config.'); }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $fh = @fopen($tmp, 'wb');
    $written = 0;
    $length = strlen($json);
    $ok = $fh !== false;
    while ($ok && $written < $length) {
        $n = @fwrite($fh, substr($json, $written));
        if ($n === false || $n === 0) { $ok = false; break; }
        $written += $n;
    }
    $ok = $ok && $written === $length && @fflush($fh);
    if ($fh !== false) @fclose($fh);
    if (!$ok || !@chmod($tmp, 0644) || !@rename($tmp, BRAND_CFG)) {
        @unlink($tmp); @flock($lock, LOCK_UN); @fclose($lock);
        fail(500, 'Could not save the branding config.');
    }
    @flock($lock, LOCK_UN);
    @fclose($lock);
    return $cfg;
}

/* JSON plus a non-simple same-origin header prevents a third-party form from
   riding the admin cookie. Cross-origin script cannot add this header without
   a CORS preflight, and this endpoint sends no Access-Control-Allow-Origin. */
function require_settings_request() {
    $type = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
    $marker = isset($_SERVER['HTTP_X_PNETLAB_REQUEST']) ? $_SERVER['HTTP_X_PNETLAB_REQUEST'] : '';
    if (strpos($type, 'application/json') !== 0 || $marker !== 'theme-settings') {
        fail(403, 'same-origin settings request required');
    }
}

if ($action === 'save') {
    $b = body();
    $name = isset($b['name']) ? trim((string) $b['name']) : '';
    $cfg = mutate_config(function ($cfg) use ($b, $name) {
        // Blank name = "use the stock name", not an error.
        $cfg['name'] = $name !== '' ? mb_substr($name, 0, BRAND_NAME_MAX) : defaults()['name'];
        if (isset($b['login_header'])) {
            $cfg['login_header'] = mb_substr(trim((string) $b['login_header']), 0, BRAND_HEADER_MAX);
        }
        $cfg['hide_default_creds'] = !empty($b['hide_default_creds']);
        return $cfg;
    });
    out(['status' => 'success', 'config' => $cfg, 'token' => brand_token()]);
}

if ($action === 'save_theme') {
    require_settings_request();
    $b = body();
    if (!isset($b['theme_profile']) || !valid_theme_profile($b['theme_profile'])) {
        fail(400, 'invalid theme profile');
    }
    $profile = $b['theme_profile'];
    $cfg = mutate_config(function ($cfg) use ($profile) {
        $cfg['theme_profile'] = $profile;
        return $cfg;
    });
    out(['status' => 'success', 'theme_profile' => $cfg['theme_profile'], 'token' => brand_token()]);
}

if ($action === 'upload_logo') {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) fail(400, 'No file was uploaded.');
    $f = $_FILES['file'];
    if (isset($f['error']) && $f['error'] !== UPLOAD_ERR_OK) {
        // UPLOAD_ERR_INI_SIZE/FORM_SIZE are the common ones — give a useful hint
        // rather than echoing the raw code.
        fail(400, 'Upload failed (the file may be too large).');
    }
    if (!is_uploaded_file($f['tmp_name'])) fail(400, 'Invalid upload.');
    if ($f['size'] <= 0) fail(400, 'The file is empty.');
    if ($f['size'] > BRAND_LOGO_MAX) fail(400, 'The logo must be 512 KB or smaller.');

    /* Content-based validation ONLY — the client-supplied name and MIME type are
       attacker-controlled and are never trusted. getimagesize() both confirms a
       real raster image and hands us the true type. SVG is rejected here by
       construction: it has no getimagesize() type of its own that we accept. */
    $info = @getimagesize($f['tmp_name']);
    if ($info === false || !isset($info[2])) fail(400, 'That file is not a valid PNG or JPEG image.');
    $ext = null;
    if ($info[2] === IMAGETYPE_PNG)  $ext = 'png';
    if ($info[2] === IMAGETYPE_JPEG) $ext = 'jpg';
    if ($ext === null) fail(400, 'Only PNG and JPEG logos are supported.');
    // Sanity bound on pixels too: a 20000x20000 PNG is small on disk but will
    // wedge a browser laying out a 28px logo.
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > 4000 || $info[1] > 4000) {
        fail(400, 'The image dimensions must be between 1 and 4000 pixels.');
    }

    ensure_dir();
    $dest = BRAND_DIR . '/logo.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dest)) fail(500, 'Could not save the logo.');
    @chmod($dest, 0644);
    // Exactly one logo file may exist, so custom_logo() is unambiguous.
    $other = BRAND_DIR . '/logo.' . ($ext === 'png' ? 'jpg' : 'png');
    if (is_file($other)) @unlink($other);
    out(['status' => 'success', 'token' => brand_token()]);
}

if ($action === 'reset') {
    // Remove only the files we own; never rmdir the directory itself (harmless,
    // and keeps ownership/permissions stable for the next save).
    $cfg = mutate_config(function ($current) {
        $reset = defaults();
        $reset['theme_profile'] = $current['theme_profile'];
        return $reset;
    });
    foreach (['logo.png', 'logo.jpg'] as $f) {
        $p = BRAND_DIR . '/' . $f;
        if (is_file($p)) @unlink($p);
    }
    out(['status' => 'success', 'config' => $cfg, 'token' => brand_token()]);
}

fail(400, 'unknown action');
