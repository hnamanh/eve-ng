<?php
/**
 * html/users/api.php — Users & Roles backend for the PNETLab dashboard
 * (Resolute port). Authenticated with EVE's own session cookie via
 * apiAuthorization(); admin actions additionally require role 'admin'.
 *
 *   GET  ?action=users          → {data:[{username,email,role,role_name,ext_auth,user_status,max_cpu,max_ram,...}]}
 *   GET  ?action=roles          → {data:[], admin_users:N}   (EVE has no custom roles)
 *   GET  ?action=permcatalog    → {data:[]}                  (no permission catalog on this engine)
 *   POST ?action=user_add       {username,password,email,name?,role?}
 *   POST ?action=user_edit      {pod, name?,email?,password?,role?,expiration?}
 *   POST ?action=user_delete    {pod}
 *   GET  ?action=myworkspace    → {workspace:'/path'}        (caller's last-viewed folder)
 *   POST ?action=change_password {old_pass,new_pass}         (any authenticated user, own account)
 *
 * PNETLab identifies users by pod; EVE stores pods in the `pods` table keyed by
 * username, so pod↔username translation happens here. Role mapping: PNETLab's
 * '0' = admin → EVE 'admin'; anything else → EVE 'editor'.
 */

chdir(dirname(__DIR__));
require_once('includes/init.php');
require_once('includes/api_authentication.php');
require_once('includes/api_uusers.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out($x) { echo json_encode($x); exit; }
function fail($code, $m) { http_response_code($code); echo json_encode(['error' => $m]); exit; }

$db = checkDatabase();
list($user, $tenant, $autherr) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
if ($user === False || empty($user)) {
	fail(401, 'not authenticated');
}
$isAdmin = (isset($user['role']) && strtolower((string) $user['role']) === 'admin');

$action = isset($_GET['action']) ? $_GET['action'] : '';

/* ---- list users ------------------------------------------------------------ */
if ($action === 'users') {
	if (!$isAdmin) fail(403, 'admin only');
	$result = apiGetUUsers($db);
	if (!isset($result['data'])) fail(500, isset($result['message']) ? $result['message'] : 'failed to list users');

	$rows = array();
	foreach ((array) $result['data'] as $u) {
		$is_admin = (strtolower((string) $u['role']) === 'admin');
		$rows[] = array(
			'username'    => $u['username'],
			'email'       => isset($u['email']) ? $u['email'] : '',
			'pod'         => (int) $u['pod'],
			'role'        => $is_admin ? '0' : '1',
			'role_name'   => $is_admin ? 'Admin' : ucfirst((string) ($u['role'] ?: 'editor')),
			'ext_auth'    => null,
			'user_status' => 1,
			'max_cpu'     => null,
			'max_ram'     => null,
			'access_days' => null,
			'access_from' => null,
			'access_to'   => null,
		);
	}
	out(['data' => $rows]);
}

/* ---- roles / permissions (EVE has none beyond admin/editor) ------------------ */
if ($action === 'roles') {
	if (!$isAdmin) fail(403, 'admin only');
	$statement = $db -> query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'admin';");
	$row = $statement -> fetch(PDO::FETCH_ASSOC);
	out(['data' => array(), 'admin_users' => (int) ($row['cnt'] ?? 0)]);
}

if ($action === 'permcatalog') {
	if (!$isAdmin) fail(403, 'admin only');
	out(['data' => array()]);
}

/* ---- user CRUD --------------------------------------------------------------- */
$role_from_pnetlab = function ($r) {
	return (strtolower((string) $r) === '0' || strtolower((string) $r) === 'admin') ? 'admin' : 'editor';
};
$pod_to_username = function ($db, $pod) use (&$fail) {
	$statement = $db -> prepare('SELECT username FROM pods WHERE id = :id LIMIT 1;');
	$statement -> execute(array(':id' => (int) $pod));
	$row = $statement -> fetch(PDO::FETCH_ASSOC);
	return $row ? $row['username'] : null;
};

if ($action === 'user_add') {
	if (!$isAdmin) fail(403, 'admin only');
	$body = json_decode(file_get_contents('php://input'), true);
	if (empty($body['username']) || empty($body['password'])) fail(400, 'username and password are required');

	$p = array(
		'username'   => trim((string) $body['username']),
		'password'   => (string) $body['password'],
		'email'      => isset($body['email']) ? trim((string) $body['email']) : '',
		'name'       => isset($body['name']) ? trim((string) $body['name']) : '',
		'role'       => $role_from_pnetlab(isset($body['role']) ? $body['role'] : '1'),
	);
	$result = apiAddUUser($db, $p);
	if (isset($result['code']) && $result['code'] !== 200) {
		fail(500, isset($result['message']) ? $result['message'] : 'failed to add user');
	}
	out(['ok' => true]);
}

if ($action === 'user_edit') {
	if (!$isAdmin) fail(403, 'admin only');
	$body = json_decode(file_get_contents('php://input'), true);
	$username = $pod_to_username($db, isset($body['pod']) ? $body['pod'] : -1);
	if ($username === null) fail(404, 'user not found');

	$p = array();
	if (isset($body['name']))     $p['name'] = trim((string) $body['name']);
	if (isset($body['email']))    $p['email'] = trim((string) $body['email']);
	if (!empty($body['password'])) $p['password'] = (string) $body['password'];
	if (isset($body['role']))     $p['role'] = $role_from_pnetlab($body['role']);
	if (isset($body['expiration']) && $body['expiration'] !== '') $p['expiration'] = $body['expiration'];

	$result = apiEditUUser($db, $username, $p);
	if (isset($result['code']) && $result['code'] !== 200) {
		fail(500, isset($result['message']) ? $result['message'] : 'failed to edit user');
	}
	out(['ok' => true]);
}

if ($action === 'user_delete') {
	if (!$isAdmin) fail(403, 'admin only');
	$body = json_decode(file_get_contents('php://input'), true);
	$username = $pod_to_username($db, isset($body['pod']) ? $body['pod'] : -1);
	if ($username === null) fail(404, 'user not found');

	$result = apiDeleteUUser($db, $username);
	if (isset($result['code']) && $result['code'] !== 200) {
		fail(500, isset($result['message']) ? $result['message'] : 'failed to delete user');
	}
	out(['ok' => true]);
}

/* ---- caller's own workspace (labs.js falls back here on a failed listing) ----- */
if ($action === 'myworkspace') {
	$ws = isset($user['folder']) && $user['folder'] !== '' ? $user['folder'] : '/';
	out(['workspace' => $ws]);
}

/* ---- change own password ------------------------------------------------------ */
if ($action === 'change_password') {
	$body = json_decode(file_get_contents('php://input'), true);
	if (empty($body['old_pass']) || empty($body['new_pass'])) fail(400, 'both passwords are required');

	$statement = $db -> prepare('SELECT password FROM users WHERE username = :username LIMIT 1;');
	$statement -> execute(array(':username' => $user['username']));
	$row = $statement -> fetch(PDO::FETCH_ASSOC);
	if (!$row || !hash_equals((string) $row['password'], hash('sha256', (string) $body['old_pass']))) {
		fail(401, 'current password is incorrect');
	}

	$statement = $db -> prepare('UPDATE users SET password = :password WHERE username = :username;');
	$statement -> execute(array(':password' => hash('sha256', (string) $body['new_pass']), ':username' => $user['username']));
	out(['status' => 'success']);
}

fail(400, 'unknown action');
?>
