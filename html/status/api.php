<?php
/**
 * html/status/api.php — System / Running-Labs backend for the PNETLab dashboard
 * (Resolute port). Authenticated with EVE's own session cookie via
 * apiAuthorization() — the same gate every /api route uses.
 *
 *   GET  ?action=system    → {data:{ram,swap,total_ram,total_swap[,cpu,disk,total_disk]}}
 *                            (cpu+disk admin-only, matching the stock engine)
 *   GET  ?action=nodes     → {data:{iol,dynamips,qemu,docker,vpcs}}        (admin)
 *   GET  ?action=info      → {data:{engine_version,qemu_version,cores,ksm,uksm,cpulimit,idle_timeout}}
 *   POST ?action=ksm|uksm|cpulimit  {state:bool}  → {ok:true}              (admin)
 *   GET  ?action=sessions  → {data:[{session,name,path,owner,pod,can_manage,nodes_total,nodes_running,hosts}]}
 *                            EVE-native open-lab model: a pod with pods.lab_id set
 *                            IS the open session (no PNETLab lab_sessions table here).
 *   GET  ?action=version   → {data:{version}}
 *   GET  ?action=history_sys&range=… → {data:{collector:'down',series:[]}}
 *                            (this engine ships no telemetry collector)
 */

chdir(dirname(__DIR__));
require_once('includes/init.php');
require_once('includes/api_authentication.php');
require_once('includes/api_status.php');

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

/* ---- system gauges -------------------------------------------------------- */
if ($action === 'system') {
	$data = array();
	$mem = apiGetMemUsage();
	$ram = is_array($mem) ? (int) $mem[0] : -1;          // [used%, free%]
	$swap = (int) apiGetSwapUsage();                    // single int: used %
	$meminfo = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$total_ram = 0; $total_swap = 0;
	if ($meminfo) {
		foreach ($meminfo as $line) {
			if (strpos($line, ':') === false) continue;
			list($k, $v) = explode(':', $line);
			$v = (int) preg_replace('/^([0-9]+).*$/', '$1', trim($v));
			if ($k === 'MemTotal') $total_ram = (int) round($v / 1024);      // kB → MB
			if ($k === 'SwapTotal') $total_swap = (int) round($v / 1024);
		}
	}
	$data['ram'] = max(0, (int) $ram);
	$data['swap'] = max(0, (int) $swap);
	$data['total_ram'] = $total_ram;
	$data['total_swap'] = $total_swap;
	if ($isAdmin) {
		$cpu = apiGetCPUUsage();
		$data['cpu'] = $cpu > 0 ? $cpu : 0;
		$disk = apiGetDiskUsage();
		$data['disk'] = $disk > 0 ? $disk : 0;
		$total_disk = 0;
		if (is_dir(BASE_LAB)) {
			$total_disk = (int) round(@disk_total_space(BASE_LAB) / 1048576); // MB
		}
		$data['total_disk'] = $total_disk;
	}
	out(['data' => $data]);
}

/* ---- running node counts --------------------------------------------------- */
if ($action === 'nodes') {
	if (!$isAdmin) fail(403, 'admin only');
	list($iol, $dynamips, $qemu, $docker, $vpcs) = apiGetRunningWrappers();
	out(['data' => array('iol' => $iol, 'dynamips' => $dynamips, 'qemu' => $qemu, 'docker' => $docker, 'vpcs' => $vpcs)]);
}

/* ---- engine info + toggles -------------------------------------------------- */
if ($action === 'info') {
	$engine_version = '';
	if (is_file(BASE_DIR . '/html/themes/adminLTE/VERSION')) {
		$engine_version = trim((string) @file_get_contents(BASE_DIR . '/html/themes/adminLTE/VERSION'));
	}
	$qemu_version = '';
	exec('qemu-system-x86_64 --version 2>/dev/null | head -1', $o, $rc);
	if ($rc === 0 && isset($o[0])) {
		preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $o[0], $m);
		if (!empty($m[1])) $qemu_version = $m[1];
	}
	$cores = (int) @count(@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES));
	$read_toggle = function ($path) {
		$v = @file_get_contents($path);
		if ($v === false) return 'unsupported';
		return trim($v) === '1' ? 'enabled' : 'disabled';
	};
	$data = array(
		'engine_version' => $engine_version,
		'qemu_version'   => $qemu_version,
		'cores'          => $cores,
		'ksm'            => $read_toggle('/sys/kernel/mm/ksm/run'),
		'uksm'           => 'unsupported',
		'cpulimit'       => 'disabled',
		'idle_timeout'   => 0,
	);
	out(['data' => $data]);
}

if (in_array($action, array('ksm', 'uksm', 'cpulimit'), true)) {
	if (!$isAdmin) fail(403, 'admin only');
	$body = json_decode(file_get_contents('php://input'), true);
	$state = !empty($body['state']);
	if ($action === 'ksm') {
		$rc = apiSetKsm(array('state' => $state));
	} else if ($action === 'uksm') {
		$rc = apiSetUksm(array('state' => $state));
	} else {
		$rc = apiSetCpuLimit(array('state' => $state));
	}
	if (isset($rc['code']) && $rc['code'] !== 200) fail(500, 'toggle failed');
	out(['ok' => true]);
}

/* ---- open lab sessions (EVE-native: pods.lab_id is the session pointer) ----- */
if ($action === 'sessions') {
	$rows = array();
	if ($isAdmin) {
		$statement = $db -> query('SELECT pods.id AS pod, users.username AS username, users.name AS name, pods.lab_id AS lab FROM pods LEFT JOIN users ON users.username = pods.username WHERE pods.lab_id IS NOT NULL AND pods.lab_id != \'\' ORDER BY pods.id ASC;');
	} else {
		$statement = $db -> prepare('SELECT pods.id AS pod, users.username AS username, users.name AS name, pods.lab_id AS lab FROM pods LEFT JOIN users ON users.username = pods.username WHERE pods.id = :pod AND pods.lab_id IS NOT NULL AND pods.lab_id != \'\' LIMIT 1;');
		$statement -> execute(array(':pod' => $tenant));
	}
	while ($row = $statement -> fetch(PDO::FETCH_ASSOC)) {
		if (empty($row['lab'])) continue;
		$lab_file = ltrim((string) $row['lab'], '/');
		$name = basename($lab_file, '.unl');
		$nodes_total = 0; $nodes_running = 0;
		if (is_file(BASE_LAB.'/'.$lab_file)) {
			$xml = @simplexml_load_string(@file_get_contents(BASE_LAB.'/'.$lab_file));
			if ($xml && isset($xml->topology->nodes->node)) {
				foreach ($xml->topology->nodes->node as $n) {
					$nodes_total++;
					if ((string) $n['status'] === '2') $nodes_running++;
				}
			}
		}
		$rows[] = array(
			'session'         => 'pod-' . (int) $row['pod'],
			'name'            => $name,
			'path'            => '/'.$lab_file,
			'owner'           => trim((string) ($row['username'] ?: $row['name'])) ?: ('pod ' . (int) $row['pod']),
			'pod'             => (int) $row['pod'],
			'can_manage'      => true,   // only the owner's own session is ever listed for non-admins
			'nodes_total'     => $nodes_total,
			'nodes_running'   => $nodes_running,
			'hosts'           => array(0),
		);
	}
	out(['data' => $rows]);
}

/* ---- version ---------------------------------------------------------------- */
if ($action === 'version') {
	$engine_version = '';
	if (is_file(BASE_DIR . '/html/themes/adminLTE/VERSION')) {
		$engine_version = trim((string) @file_get_contents(BASE_DIR . '/html/themes/adminLTE/VERSION'));
	}
	out(['data' => array('version' => $engine_version)]);
}

/* ---- history (no telemetry collector on this engine) ------------------------- */
if ($action === 'history_sys') {
	out(['data' => array('collector' => 'down', 'series' => array())]);
}

fail(400, 'unknown action');
?>
