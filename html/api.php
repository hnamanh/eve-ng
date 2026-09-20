<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/api.php
 *
 * REST API router for UNetLab.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

require_once('/opt/unetlab/html/includes/init.php');
require_once(BASE_DIR.'/html/includes/Slim/Slim.php');
require_once(BASE_DIR.'/html/includes/Slim-Extras/DateTimeFileWriter.php');
require_once(BASE_DIR.'/html/includes/api_authentication.php');
require_once(BASE_DIR.'/html/includes/api_configs.php');
require_once(BASE_DIR.'/html/includes/api_folders.php');
require_once(BASE_DIR.'/html/includes/api_labs.php');
require_once(BASE_DIR.'/html/includes/api_networks.php');
require_once(BASE_DIR.'/html/includes/api_nodes.php');
require_once(BASE_DIR.'/html/includes/api_pictures.php');
require_once(BASE_DIR.'/html/includes/api_status.php');
require_once(BASE_DIR.'/html/includes/api_textobjects.php');
require_once(BASE_DIR.'/html/includes/api_topology.php');
require_once(BASE_DIR.'/html/includes/api_uusers.php');
\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim(Array(
	'mode' => 'production',
	'debug' => True,					// Change to False for production
	'log.level' => \Slim\Log::WARN,		// Change to WARN for production, DEBUG to develop
	'log.enabled' => True,
	'log.writer' => new \Slim\LogWriter(fopen('/opt/unetlab/data/Logs/api.txt', 'a'))
));

$app -> hook('slim.after.router', function () use ($app) {
	// Log all requests and responses
	$request = $app -> request;
	$response = $app -> response;

	$app -> log -> debug('Request path: ' . $request -> getPathInfo());
	$app -> log -> debug('Response status: ' . $response -> getStatus());
});

$app -> response -> headers -> set('Content-Type', 'application/json');
$app -> response -> headers -> set('X-Powered-By', 'Unified Networking Lab API');
$app -> response -> headers -> set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
$app -> response -> headers -> set('Cache-Control', 'post-check=0, pre-check=0');
$app -> response -> headers -> set('Pragma', 'no-cache');

$app -> notFound(function() use ($app) {
	$output['code'] = 404;
	$output['status'] = 'fail';
	$output['message'] = $GLOBALS['messages']['60038'];
	$app -> halt($output['code'], json_encode($output));
});

class ResourceNotFoundException extends Exception {}
class AuthenticateFailedException extends Exception {}


$db = checkDatabase();
if ($db === False) {
	// Database is not available
	$app -> map('/api/(:path+)', function() use ($app) {
		$output['code'] = 500;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['90003'];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
	}) -> via('DELETE', 'GET', 'POST');
	$app -> run();
}

$html5_db = html5_checkDatabase();
if ($html5_db === False) {
	// Guacamole DB is optional on Resolute (stateless web console). Log and continue;
	// apiLogin() skips the guacdb rows when $html5_db is False.
	error_log(date('M d H:i:s ').'WARN: guacdb unavailable — legacy Guacamole DB auth disabled (web console unaffected)');
}


if (updateDatabase($db) == False) {
	// Failed to update database
	// TODO should run una tantum
	$app -> map('/api/(:path+)', function() use ($app) {
		$output['code'] = 500;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['90006'];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
	}) -> via('DELETE', 'GET', 'POST');
	$app -> run();
}

// Define output for unprivileged requests
$forbidden = Array(
	'code' => 401,
	'status' => 'forbidden',
	'message' => $GLOBALS['messages']['90032']
);

/***************************************************************************
 * Authentication
 **************************************************************************/
$app -> post('/api/auth/login', function() use ($app, $db, $html5_db) {
	// Login
	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT
	$cookie = genUuid();
	$output = apiLogin($db, $html5_db, $p, $cookie);
	if ($output['code'] == 200) {
		// User is authenticated, need to set the cookie.
		// Path '/' (not '/api/') so the session-gated console token minter under
		// /console/ also receives the cookie; the value is a random UUID, so the
		// wider path adds no exposure.
		$app -> setCookie('unetlab_session', $cookie, SESSION, '/', $_SERVER['SERVER_NAME'], False, False);
		// Expire any stale copy still scoped to the old '/api/' path: browsers keep
		// both cookies and send them together, and PHP's first-value-wins parsing
		// would then authenticate with the dead one (401 on every call). Slim's cookie
		// collection is keyed by name — a second delete for 'unetlab_session' would
		// overwrite the fresh session cookie above — so emit it as a raw appended header.
		header('Set-Cookie: unetlab_session=; domain=' . $_SERVER['SERVER_NAME'] . '; path=/api/; expires=' . gmdate('D, d-M-Y H:i:s', time() - 86400) . ' GMT', false);
	}
	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

$app -> get('/api/auth/logout', function() use ($app, $db) {
	// Logout (DELETE request does not work with cookies). Invalidate every
	// candidate value the browser may hold (legacy '/api/' path + new '/') and
	// expire both cookie paths so no stale copy keeps the session alive.
	foreach (sessionCookieCandidates('unetlab_session') as $cookie) {
		apiLogout($db, $cookie);
	}
	// Expire both cookie paths as raw appended headers (the collection is keyed by
	// name, so two deletes for 'unetlab_session' would overwrite each other).
	header('Set-Cookie: unetlab_session=; domain=' . $_SERVER['SERVER_NAME'] . '; path=/; expires=' . gmdate('D, d-M-Y H:i:s', time() - 86400) . ' GMT', false);
	header('Set-Cookie: unetlab_session=; domain=' . $_SERVER['SERVER_NAME'] . '; path=/api/; expires=' . gmdate('D, d-M-Y H:i:s', time() - 86400) . ' GMT', false);
	$output = array('code' => 200, 'status' => 'success', 'message' => $GLOBALS['messages'][90019]);
	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

$app -> get('/api/auth', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		// Set 401 not 412 for this page only -> used to refresh after a logout
		$output['code'] = 401;
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	if (checkFolder(BASE_LAB.$user['folder']) !== 0) {
		// User has an invalid last viewed folder
		$user['folder'] = '/';
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['90002'];
	$output['data'] = $user;

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

/*
 * TODO
$app -> put('/api/auth', function() use ($app, $db) {
	// Set tenant
	// TODO should be used by admin user on single-user mode only
});
 */

/***************************************************************************
 * Status
 **************************************************************************/
// Get system stats
$app -> get('/api/status', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['60001'];
	$output['data'] = Array();
	$output['data']['version'] = VERSION;
	$cmd = '/opt/qemu/bin/qemu-system-x86_64 -version | sed \'s/.* \([0-9]*\.[0-9.]*\.[0-9.]*\).*/\1/g\'';
	exec($cmd, $o, $rc);
	if ($rc != 0 || empty($o[0])) {
		error_log(date('M d H:i:s ').'ERROR: '.$GLOBALS['messages'][60044]);
		$output['data']['qemu_version'] = '';
	} else {
		$output['data']['qemu_version'] = $o[0];
	}
	$o = "" ;
	$cmd = 'cat /sys/kernel/mm/uksm/run';
	exec($cmd, $o, $rc);
	if ($rc != 0 || empty($o[0])) { 
		$output['data']['uksm'] = 'unsupported';
	} else {
		if ($o[0] == "1") {
			$output['data']['uksm'] = "enabled";
		} else {
			$output['data']['uksm'] = "disabled";
		}
	}
        $o = "" ;
        $cmd = 'cat /sys/kernel/mm/ksm/run';
        exec($cmd, $o, $rc);
        if ($rc != 0 || empty($o[0])) {
                $output['data']['ksm'] = 'unsupported';
        } else {
                if ($o[0] == "1") {
                        $output['data']['ksm'] = "enabled";
                } else {
                        $output['data']['ksm'] = "disabled";
                }
        }
        $o = "" ;
        $cmd = 'systemctl is-active cpulimit.service';
        exec($cmd, $o, $rc);
        if ($rc != 0 || empty($o[0])) {
                error_log(date('M d H:i:s ').'ERROR: '.$GLOBALS['messages'][60044]);
                $output['data']['cpulimit'] = 'disabled';
        } else {
               if ($o[0] == "active") {
                    $output['data']['cpulimit'] = 'enabled';
               } else {
                    $output['data']['cpulimit'] = 'disabled';
              }
        }
	$output['data']['cpu'] = apiGetCPUUsage();
	$output['data']['disk'] = apiGetDiskUsage();
	list($output['data']['cached'], $output['data']['mem']) = apiGetMemUsage();
	$output['data']['swap'] = apiGetSwapUsage();
	list(
		$output['data']['iol'],
		$output['data']['dynamips'],
		$output['data']['qemu'],
		$output['data']['docker'],
		$output['data']['vpcs']
	) = apiGetRunningWrappers();

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Stop all nodes and clear the system
$app -> delete('/api/status', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper -a stopall';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		error_log(date('M d H:i:s ').'ERROR: '.$GLOBALS['messages'][60044]);
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60050'];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages']['60051'];
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

/***************************************************************************
 * List Objects
 **************************************************************************/
// Node templates
$app -> get('/api/list/templates/(:template)', function($template = '') use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	if (!isset($template) || $template == '') {
		// Print all available templates
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages']['60003'];
		$output['data'] = $GLOBALS['node_templates'];
	} else if (isset($GLOBALS['node_templates'][$template]) && is_file(BASE_DIR.'/html/templates/'.$template.'.php')) {
		// Template found
		include(BASE_DIR.'/html/templates/'.$template.'.php');
		$p['template'] = $template;
		$output = apiGetLabNodeTemplate($p);
	} else {
		// Template not found (or not available)
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60031'];
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Network types
$app -> get('/api/list/networks', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['60002'];
	$output['data'] = listNetworkTypes();

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Network types
$app -> get('/api/list/roles', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['60041'];
	$output['data'] = listRoles();

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

/***************************************************************************
 * Folders
 **************************************************************************/
// Get folder content
$app -> get('/api/folders/(:path+)', function($path = array()) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$s = '/'.implode('/', $path);
	$output = apiGetFolders($s);

	if ($output['status'] === 'success') {
		// Setting folder as last viewed
		$rc = updateUserFolder($db, $app -> getCookie('unetlab_session'), $s);
		if ($rc !== 0) {
			// Cannot update user folder
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
		}
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Edit (move and rename) a folder
$app -> put('/api/folders/(:path+)', function($path = array()) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	// TODO must check before using p name and p path

	$event = json_decode($app -> request() -> getBody());
	$s = '/'.implode('/', $path);
	$p = json_decode(json_encode($event), True);
	$output = apiEditFolder($s, $p['path']);

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Add a new folder
$app -> post('/api/folders', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}


	// TODO must check before using p name and p path

	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);
	$output = apiAddFolder($p['name'], $p['path']);

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Delete an existing folder
$app -> delete('/api/folders/(:path+)', function($path = array()) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$s = '/'.implode('/', $path);
	$output = apiDeleteFolder($s);

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

/***************************************************************************
 * Labs
 **************************************************************************/
// Resolute web-console (PNETLab GUI) session endpoints. Registered BEFORE the
// generic /api/labs/(:path+) route so they win matching. They expose the user's
// OPEN lab (pods.lab_id — set when they open a lab in this session), which is
// also the ownership boundary: only nodes of that lab are ever listed/minted.
$app -> get('/api/labs/session/info', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	$lab_file = isset($user['lab']) ? ltrim((string) $user['lab'], '/') : '';
	if ($lab_file === '' || !is_file(BASE_LAB.'/'.$lab_file)) {
		$app -> response -> setStatus(409);
		$app -> response -> setBody(json_encode(array('code' => 409, 'status' => 'fail', 'message' => 'No lab open in this session')));
		return;
	}
	try {
		$lab = new Lab(BASE_LAB.'/'.$lab_file, $tenant);
	} catch (Exception $e) {
		$app -> response -> setStatus(500);
		$app -> response -> setBody(json_encode(array('code' => 500, 'status' => 'fail', 'message' => 'Cannot open lab')));
		return;
	}
	$app -> response -> setBody(json_encode(array(
		'code' => 200, 'status' => 'success',
		'data' => array('id' => $lab -> getId(), 'name' => $lab_file)
	)));
});

// Node list for the console sidebar: id/name/console/image/status/type per node.
// status uses EVE's getStatus() (2 = running), which the GUI maps to its dots.
$app -> get('/api/labs/session/nodes', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	$lab_file = isset($user['lab']) ? ltrim((string) $user['lab'], '/') : '';
	if ($lab_file === '' || !is_file(BASE_LAB.'/'.$lab_file)) {
		$app -> response -> setStatus(409);
		$app -> response -> setBody(json_encode(array('code' => 409, 'status' => 'fail', 'message' => 'No lab open in this session')));
		return;
	}
	try {
		$lab = new Lab(BASE_LAB.'/'.$lab_file, $tenant);
	} catch (Exception $e) {
		$app -> response -> setStatus(500);
		$app -> response -> setBody(json_encode(array('code' => 500, 'status' => 'fail', 'message' => 'Cannot open lab')));
		return;
	}
	$data = array();
	foreach ($lab -> getNodes() as $id => $node) {
		$data[$id] = array(
			'id'      => (int) $id,
			'name'    => $node -> getName(),
			'type'    => $node -> getNType(),
			'image'   => $node -> getImage(),
			'console' => $node -> getConsole(),
			'status'  => (int) $node -> getStatus()
		);
	}
	$app -> response -> setBody(json_encode(array('code' => 200, 'status' => 'success', 'data' => $data)));
});

// Get an object
$app -> get('/api/labs/(:path+)', function($path = array()) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$s = '/'.implode('/', $path);

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Interfere after lab_file.unl

	if (!is_file(BASE_LAB.$lab_file)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60000];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab(BASE_LAB.$lab_file, $tenant);
	} catch(Exception $e) {
		// Lab file is invalid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60056];
		$output['message'] = $e -> getMessage();
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/html$/', $s)) {
		$Parsedown = new Parsedown();
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages']['60054'];
		$output['data'] = $Parsedown -> text($lab -> getBody());
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/configs$/', $s)) {
		$output = apiGetLabConfigs($lab);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/configs\/[0-9]+$/', $s)) {
		$output = apiGetLabConfig($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks$/', $s)) {
		$output = apiGetLabNetworks($lab);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks\/[0-9]+$/', $s)) {
		$output = apiGetLabNetwork($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/links$/', $s)) {
		$output = apiGetLabLinks($lab);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes$/', $s)) {
		$output = apiGetLabNodes($lab,$user['html5'],$user['username']);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/start$/', $s)) {
		if ($tenant < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}

		// Locking to avoid "device vnet12_20 already exists; can't create bridge with the same name"
		if (!lockFile(BASE_LAB.$lab_file)) {
			// Failed to lockFile within the time
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages'][60061];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		$output = apiStartLabNodes($lab, $tenant);
		unlockFile(BASE_LAB.$lab_file);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/stop$/', $s)) {
		if ($tenant < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		$output = apiStopLabNodes($lab, $tenant);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/wipe$/', $s)) {
		if ($tenant < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		$output = apiWipeLabNodes($lab, $tenant);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+$/', $s)) {
		$output = apiGetLabNode($lab, $id, $user['html5'],$user['username'] );
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/interfaces$/', $s)) {
		$output = apiGetLabNodeInterfaces($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/start$/', $s)) {
		if ($tenant < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		$output = apiStartLabNode($lab, $id, $tenant);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/stop$/', $s)) {
		if ($tenant < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		$output = apiStopLabNode($lab, $id, $tenant);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/wipe$/', $s)) {
		if ($tenant < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		$output = apiWipeLabNode($lab, $id, $tenant);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/topology$/', $s)) {
		if ($tenant < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		// Setting lab as last viewed
		$rc = updatePodLab($db, $tenant, $lab_file);
		if ($rc !== 0) {
			// Cannot update user lab
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
		} else {
			$output = apiGetLabTopology($lab);
		}
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects$/', $s)) {
		$output = apiGetLabTextObjects($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects\/[0-9]+$/', $s)) {
		$output = apiGetLabTextObject($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures$/', $s)) {
		$output = apiGetLabPictures($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+$/', $s)) {
		$output = apiGetLabPicture($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/picturesmapped\/[0-9]+$/', $s)) {
                $output = apiGetLabPictureMapped($lab, $id,$user['html5'],$user['username']);
		//$output = apiGetLabPicture($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+\/data$/', $s)) {
		$height = 0;
		$width = 0;
		if ($app -> request() -> params('width') > 0) {
			$width = $app -> request() -> params('width');
		}
		if ($app -> request() -> params('height')) {
			$height = $app -> request() -> params('height');
		}
		$output = apiGetLabPictureData($lab, $id, $width, $height);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+\/data\/[0-9]+\/[0-9]+$/', $s)) {
		// Get Thumbnail
		$height = preg_replace('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+\/data\/\([0-9]+\)\/\([0-9]+\)$/', '$1', $s);
		$width = preg_replace('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+\/data\/\([0-9]+\)\/\([0-9]+\)$/', '$1', $s);
		$output = apiGetLabPictureData($lab, $id, $width, $height);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl$/', $s)) {
		$output = apiGetLab($lab);
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60027];
	}

	$app -> response -> setStatus($output['code']);
	if (isset($output['encoding'])) {
		// Custom encoding
		$app -> response -> headers -> set('Content-Type', $output['encoding']);
		$app -> response -> setBody($output['data']);
	} else {
		// Default encoding
		$app -> response -> setBody(json_encode($output));
	}
});

// Edit an existing object
$app -> put('/api/labs/(:path+)', function($path = array()) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT
	$s = '/'.implode('/', $path);

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Intefer after lab_file.unl

	if (!is_file(BASE_LAB.$lab_file)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60000'];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	// Locking
	if (!lockFile(BASE_LAB.$lab_file)) {
		// Failed to lockFile within the time
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60061];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab(BASE_LAB.$lab_file, $tenant);
	} catch(Exception $e) {
		// Lab file is invalid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$e -> getMessage()];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		unlockFile(BASE_LAB.$lab_file);
		return;
	}

	if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		if (isset($p['count'])) {
			// count cannot be set from API
			unset($p['count']);
		}
		$output = apiEditLabNetwork($lab, $p);
        } else if ( preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks$/', $s)) {
                $output = apiEditLabNetworks($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/configs\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		$output = apiEditLabConfig($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/export$/', $s)) {
		if (!in_array($user['role'], Array('admin', 'editor'))) {
			$app -> response -> setStatus($GLOBALS['forbidden']['code']);
			$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
			return;
		}
		if ($tenant < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		$output = apiExportLabNodes($lab, $tenant);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		$output = apiEditLabNode($lab, $p);
        } else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes$/', $s)) {
                $output = apiEditLabNodes($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/export$/', $s)) {
		if ($tenant < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app -> response -> setStatus($output['code']);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		$output = apiExportLabNode($lab, $id, $tenant);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/interfaces$/', $s)) {
		$output = apiEditLabNodeInterfaces($lab, $id, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		$output = apiEditLabTextObject($lab, $p);
        } else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects$/', $s)) {
                $output = apiEditLabTextObjects($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		$output = apiEditLabPicture($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl$/', $s)) {
		$output = apiEditLab($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/Lock$/', $s)) {
		$output = apiLockLab($lab);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/Unlock$/', $s)) {
		$output = apiUnlockLab($lab);	
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/move$/', $s)) {
		$output = apiMoveLab($lab, $p['path']);
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60027];
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
	unlockFile(BASE_LAB.$lab_file);
});

// Add new lab
$app -> post('/api/labs', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}
	
	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);;
	
	if (isset($p['source'])) {
		$output = apiCloneLab($p, $tenant);
	} else {
		$output = apiAddLab($p, $tenant);
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Add new object inside a lab
// PNETLab dashboard lab metadata: POST /api/labs/{get,edit,move,preview}
$app -> post('/api/labs/(:action)', function($action = '') use ($app, $db) {
	// Single-segment lab actions only (PNETLab dashboard). Multi-segment
	// paths like /api/labs/{lab}/start fall through to the legacy route below.
	if (!in_array($action, array('get', 'edit', 'move', 'preview'), true)) {
		$output = array('code' => 405, 'status' => 'fail', 'message' => 'Unknown lab action');
		$app -> response -> setStatus(405);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);
	$s = isset($p['path']) ? $p['path'] : '';

	if (!is_file(BASE_LAB.$s)) {
		$output = array('code' => 404, 'status' => 'fail', 'message' => $GLOBALS['messages'][60000]);
		$app -> response -> setStatus(404);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab(BASE_LAB.$s, $tenant);
	} catch(Exception $e) {
		$output = array('code' => 400, 'status' => 'fail', 'message' => $e -> getMessage());
		$app -> response -> setStatus(400);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	if ($action === 'get') {
		// PNETLab's edit dialog reads name/version/author/description/scripttimeout/countdown.
		$nodes = array();
		foreach ($lab -> getNodes() as $id => $node) {
			$interfaces = array();
			foreach ((array) $node -> getInterfaces() as $i => $ifc) {
				// Interfaces are Interfc objects (EVE), not arrays.
				$nid = ($ifc instanceof \Interfc) ? (int) $ifc -> getNetworkId() : 0;
				$interfaces[] = array('network_id' => $nid);
			}
			$nodes[$id] = array(
				'name' => $node -> getName(), 'type' => $node -> getNType(),
				'icon' => $node -> getIcon(), 'left' => (int) $node -> getLeft(), 'top' => (int) $node -> getTop(),
				'interfaces' => $interfaces,
			);
		}
		$networks = array();
		foreach ((array) $lab -> getNetworks() as $id => $net) {
			$networks[$id] = array('type' => isset($net['type']) ? $net['type'] : 'bridge',
				'left' => 0, 'top' => 0);
		}
		$output = array(
			'code' => 200, 'status' => 'success', 'message' => '',
			'data' => array(
				'name' => $lab -> getName(), 'version' => (string) $lab -> getVersion(),
				'author' => $lab -> getAuthor(), 'description' => $lab -> getDescription(),
				'scripttimeout' => (int) $lab -> getScriptTimeout(), 'countdown' => 0,
			),
		);
	} else if ($action === 'edit') {
		if (!in_array($user['role'], Array('admin', 'editor'))) {
			$output = $GLOBALS['forbidden'];
		} else {
			$data = isset($p['data']) && is_array($p['data']) ? $p['data'] : array();
			// Rename: PNETLab sends data.name for a plain rename.
			if (isset($data['name']) && !empty($data['name'])) {
				$new = rtrim(dirname($s), '/') . '/' . preg_replace('/\.unl$/', '', $data['name']) . '.unl';
				if ($new !== $s) {
					$output = apiMoveLab($lab, $new);
				} else {
					$output = array('code' => 200, 'status' => 'success', 'message' => '');
				}
			} else {
				// Metadata edit (description/author/version/scripttimeout)
				if (!empty($data)) $output = apiEditLab($lab, $data);
				else $output = array('code' => 200, 'status' => 'success', 'message' => '');
			}
		}
	} else if ($action === 'move') {
		if (!in_array($user['role'], Array('admin', 'editor'))) {
			$output = $GLOBALS['forbidden'];
		} else {
			$dest = isset($p['new_path']) ? $p['new_path'] : '';
			if ($dest === '' || !is_dir(BASE_LAB.dirname($dest))) {
				$output = array('code' => 400, 'status' => 'fail', 'message' => 'Invalid destination');
			} else {
				$target = rtrim(dirname($dest), '/') . '/' . basename($s);
				$output = apiMoveLab($lab, $target);
			}
		}
	} else if ($action === 'preview') {
		// Topology thumbnail: node positions + icons + network membership.
		$nodes = array();
		foreach ($lab -> getNodes() as $id => $node) {
			$interfaces = array();
			foreach ((array) $node -> getInterfaces() as $i => $ifc) {
				// Interfaces are Interfc objects (EVE), not arrays.
				$nid = ($ifc instanceof \Interfc) ? (int) $ifc -> getNetworkId() : 0;
				$interfaces[] = array('network_id' => $nid);
			}
			$nodes[$id] = array(
				'name' => $node -> getName(), 'icon' => $node -> getIcon(),
				'left' => (int) $node -> getLeft(), 'top' => (int) $node -> getTop(),
				'interfaces' => $interfaces,
			);
		}
		$networks = array();
		foreach ((array) $lab -> getNetworks() as $id => $net) {
			$networks[$id] = array('type' => isset($net['type']) ? $net['type'] : 'bridge', 'left' => 0, 'top' => 0);
		}
		$output = array('code' => 200, 'status' => 'success', 'message' => '',
			'data' => array('nodes' => $nodes, 'networks' => $networks));
	} else {
		$output = array('code' => 400, 'status' => 'fail', 'message' => 'Unknown lab action');
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});


$app -> post('/api/labs/(:path+)', function($path = array()) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT
	$s = '/'.implode('/', $path);
	$o = False;

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Intefer after lab_file.unl

	// Reading options from POST/PUT
	if (isset($event -> postfix) && $event -> postfix == True) $o = True;

	if (!is_file(BASE_LAB.$lab_file)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60000'];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	// Locking
	if (!lockFile(BASE_LAB.$lab_file)) {
		// Failed to lockFile within the time
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60061];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab(BASE_LAB.$lab_file, $tenant);
	} catch(Exception $e) {
		// Lab file is invalid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$e -> getMessage()];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		unlockFile(BASE_LAB.$lab_file);
		return;
	}

	if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks$/', $s)) {
		$output = apiAddLabNetwork($lab, $p, $o);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes$/', $s)) {
		if (isset($p['count'])) {
			// count cannot be set from API
			unset($p['count']);
		}
		$output = apiAddLabNode($lab, $p, $o);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects$/', $s)) {
		$output = apiAddLabTextObject($lab, $p, $o);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures$/', $s)) {
		// Cannot use $app -> request() -> getBody()
		$p = $_POST;
		if (!empty($_FILES)) {
			foreach ($_FILES as $file) {
				if (file_exists($file['tmp_name'])) {
					$fp = fopen($file['tmp_name'], 'r');
					$size = filesize($file['tmp_name']);
					if ($fp !== False) {
						$finfo = new finfo(FILEINFO_MIME);
						$p['data'] = fread($fp, $size);
						$p['type'] = $finfo -> buffer($p['data'], FILEINFO_MIME_TYPE);
					}
				}
			}
		}
		$output = apiAddLabPicture($lab, $p);
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60027];
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
	unlockFile(BASE_LAB.$lab_file);
});

// Close a lab
$app -> delete('/api/labs/close', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	if ($tenant < 0) {
		// User does not have an assigned tenant
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60052'];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$rc = updatePodLab($db, $tenant, null);
	if ($rc !== 0) {
		// Cannot update user lab
		$output['code'] = 500;
		$output['status'] = 'error';
		$output['message'] = $GLOBALS['messages'][$rc];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60053];
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Delete an object
$app -> delete('/api/labs/(:path+)', function($path = array()) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app -> request() -> getBody());
	$s = '/'.implode('/', $path);

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Intefer after lab_file.unl

	if (!is_file(BASE_LAB.$lab_file)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60000'];
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab(BASE_LAB.$lab_file, $tenant);
	} catch(Exception $e) {
		// Lab file is invalid
		if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl$/', $s)) {
			// Delete the lab
			if (unlink(BASE_LAB.$lab_file)) {
				$output['code'] = 200;
				$output['status'] = 'success';
			} else {
				$output['code'] = 400;
				$output['status'] = 'fail';
				$output['message'] = $GLOBALS['messages'][60021];
			}
		} else {
			// Cannot delete objects on non-valid lab
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages'][$e -> getMessage()];
		}
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks\/[0-9]+$/', $s)) {
		$output = apiDeleteLabNetwork($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+$/', $s)) {
		$output = apiDeleteLabNode($lab, $id,$tenant);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects\/[0-9]+$/', $s)) {
		$output = apiDeleteLabTextObject($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+$/', $s)) {
		$output = apiDeleteLabPicture($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl$/', $s)) {
		$output = apiDeleteLab($lab);
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60027];
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

/***************************************************************************
 * Users
 **************************************************************************/
// Get a user
$app -> get('/api/users/(:uuser)', function($uuser = False) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin')) && false) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	if (empty($uuser)) {
		$output = apiGetUUsers($db);
	} else {
		$output = apiGetUUser($db, $uuser);
	}
	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Edit a user
$app -> put('/api/users/(:uuser)', function($uuser = False) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT
	
	if ($user['role'] == 'editor') {
		unset($p['role']);
		unset($p['expiration']);
		unset($p['pod']);
		unset($p['pexpiration']);
	}
	$output = apiEditUUser($db, $uuser, $p);
	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Add a user
$app -> post('/api/users', function($uuser = False) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT

	$output = apiAddUUser($db, $p);
	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Delete a user
$app -> delete('/api/users/(:uuser)', function($uuser = False) use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$output = apiDeleteUUser($db, $uuser);
	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Change cpulimit

$app -> post('/api/cpulimit', function() use ($app, $db) {
        list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
        if ($user === False) {
                $app -> response -> setStatus($output['code']);
                $app -> response -> setBody(json_encode($output));
                return;
        }
        if (!in_array($user['role'], Array('admin'))) {
                $app -> response -> setStatus($GLOBALS['forbidden']['code']);
                $app -> response -> setBody(json_encode($GLOBALS['forbidden']));
                return;
        }

        $event = json_decode($app -> request() -> getBody());
        $p = json_decode(json_encode($event), True);    // Reading options from POST/PUT

        $output = apiSetCpuLimit($p);
        $app -> response -> setStatus($output['code']);
        $app -> response -> setBody(json_encode($output));
});

// Change uksm

$app -> post('/api/uksm', function() use ($app, $db) {
        list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
        if ($user === False) {
                $app -> response -> setStatus($output['code']);
                $app -> response -> setBody(json_encode($output));
                return;
        }
        if (!in_array($user['role'], Array('admin'))) {
                $app -> response -> setStatus($GLOBALS['forbidden']['code']);
                $app -> response -> setBody(json_encode($GLOBALS['forbidden']));
                return;
        }

        $event = json_decode($app -> request() -> getBody());
        $p = json_decode(json_encode($event), True);    // Reading options from POST/PUT

        $output = apiSetUksm($p);
        $app -> response -> setStatus($output['code']);
        $app -> response -> setBody(json_encode($output));
});
// Change ksm

$app -> post('/api/ksm', function() use ($app, $db) {
        list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
        if ($user === False) {
                $app -> response -> setStatus($output['code']);
                $app -> response -> setBody(json_encode($output));
                return;
        }
        if (!in_array($user['role'], Array('admin'))) {
                $app -> response -> setStatus($GLOBALS['forbidden']['code']);
                $app -> response -> setBody(json_encode($GLOBALS['forbidden']));
                return;
        }

        $event = json_decode($app -> request() -> getBody());
        $p = json_decode(json_encode($event), True);    // Reading options from POST/PUT

        $output = apiSetKsm($p);
        $app -> response -> setStatus($output['code']);
        $app -> response -> setBody(json_encode($output));
});

/***************************************************************************
 * Export/Import
 **************************************************************************/
// Export labs
$app -> post('/api/export', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}
	
	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);;
	
	$output = apiExportLabs($p);
	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// Import labs
 $app -> post('/api/import', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	// Cannot use $app -> request() -> getBody()
	$p = $_POST;
	if (!empty($_FILES)) {
		foreach ($_FILES as $file) {
			$p['name'] = $file['name'];
			$p['file'] = $file['tmp_name'];
			$p['error'] = $file['name'];
		}
	}
	$output = apiImportLabs($p);
	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
 });

/***************************************************************************
 * Update
 **************************************************************************/
$app -> get('/api/update', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper -a update';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		error_log(date('M d H:i:s ').'ERROR: '.$GLOBALS['messages'][60059]);
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60059'];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages']['60060'];
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});


/***************************************************************************
 * LOGS
 **************************************************************************/
$app -> get('/api/logs/(:file)/(:lines)/(:search)', function($file = False, $lines = 10, $search="") use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	

	$f = @file_get_contents("/opt/unetlab/data/Logs/" . $file);
	if ($f)
	{
		$arr = explode("\n", $f);
		if (!is_array($arr))
			$arr = array();
		$arr = array_reverse($arr);
		
		if ($search)
		{
			foreach($arr as $k=>$v )
			{
				if (strstr($v, $search) === false)
					unset($arr[$k]);
			}
		}
		
		$arr = array_slice($arr, 0 , $lines);
	}
	else
		$arr = array();
	
	$app -> response -> setStatus(200);
	$app -> response -> setBody(json_encode($arr));
});

/***************************************************************************
 * ICONS
 **************************************************************************/
$app -> get('/api/icons', function() use ($app, $db) {
	$arr = listNodeIcons();
	$app -> response -> setStatus(200);
	$app -> response -> setBody(json_encode($arr));
});

/***************************************************************************
 * PNETLab dashboard compatibility (Resolute GUI)
 *
 * The PNETLab frontend (/login/ + /main/) speaks a slightly different API
 * dialect than the stock EVE-NG adminLTE app. These routes adapt it to the
 * existing engine functions without touching them:
 *   - POST /api/auth                     login (PNETLab's login page posts here)
 *   - GET  /api/folders?path=            folder listing with mtime enrichment
 *   - POST /api/folders/(:action)        add | edit | delete
 *   - DELETE /api/labs                   {path}
 *   - POST /api/labs/{get,edit,move,preview}
 *   - POST /api/labs/session/factory/(:action)  create | join | stopNodes | destroy
 *     (EVE-native "open lab" model: pods.lab_id is the session pointer; there
 *      is no PNETLab lab_sessions table on this engine)
 **************************************************************************/

// PNETLab login page posts {username,password,html5} to /api/auth
$app -> post('/api/auth', function() use ($app, $db, $html5_db) {
	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);
	$cookie = genUuid();
	$output = apiLogin($db, $html5_db, $p, $cookie);
	if ($output['code'] == 200) {
		$app -> setCookie('unetlab_session', $cookie, SESSION, '/', $_SERVER['SERVER_NAME'], False, False);
		// Expire any stale copy still scoped to the legacy '/api/' path (raw appended
		// header — see /api/auth/login for why not $app->deleteCookie()).
		header('Set-Cookie: unetlab_session=; domain=' . $_SERVER['SERVER_NAME'] . '; path=/api/; expires=' . gmdate('D, d-M-Y H:i:s', time() - 86400) . ' GMT', false);
	}
	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// PNETLab dashboard lists folders via query string: GET /api/folders?path=/sub/
$app -> get('/api/folders', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$variables = $app -> request() -> get();
	$s = isset($variables['path']) ? (string) $variables['path'] : '/';
	if ($s === '' || !preg_match('#^(/|[A-Za-z0-9_+\\/ -]+)$#', $s)) {
		$output = array('code' => 400, 'status' => 'fail', 'message' => 'Invalid path');
		$app -> response -> setStatus(400);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$result = apiGetFolders($s);
	if ($result['status'] === 'success') {
		// Enrich with the fields the PNETLab file manager displays (all optional there).
		foreach ((array) $result['data']['folders'] as &$f) {
			$f['mtime'] = is_dir(BASE_LAB.$f['path']) ? (int) filemtime(BASE_LAB.$f['path']) : 0;
		}
		unset($f);
		foreach ((array) $result['data']['labs'] as &$l) {
			$l['name'] = preg_replace('/\.unl$/', '', (string) $l['file']);
			$fq = BASE_LAB.$l['path'];
			$l['mtime'] = is_file($fq) ? (int) filemtime($fq) : 0;
			$l['size'] = is_file($fq) ? (int) filesize($fq) : 0;
		}
		unset($l);

		// Remember as last-viewed folder, like the legacy route does.
		$rc = updateUserFolder($db, $app -> getCookie('unetlab_session'), $s);
		if ($rc !== 0) {
			$result['code'] = 500;
			$result['status'] = 'error';
			$result['message'] = $GLOBALS['messages'][$rc];
		}
	}

	$app -> response -> setStatus($result['code']);
	$app -> response -> setBody(json_encode($result));
});

// PNETLab dashboard folder mutations: POST /api/folders/{add,edit,delete}
$app -> post('/api/folders/(:action)', function($action = '') use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);

	if ($action === 'add') {
		$output = apiAddFolder(isset($p['name']) ? $p['name'] : '', isset($p['path']) ? $p['path'] : '/');
	} else if ($action === 'edit') {
		// PNETLab sends {path, new_path}; the engine renames via apiEditFolder(old, new)
		$output = apiEditFolder(isset($p['path']) ? $p['path'] : '', isset($p['new_path']) ? $p['new_path'] : '');
	} else if ($action === 'delete') {
		$output = apiDeleteFolder(isset($p['path']) ? $p['path'] : '');
	} else {
		$output = array('code' => 400, 'status' => 'fail', 'message' => 'Unknown folder action');
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// PNETLab dashboard deletes labs with DELETE /api/labs {path}
$app -> delete('/api/labs', function() use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], Array('admin', 'editor'))) {
		$app -> response -> setStatus($GLOBALS['forbidden']['code']);
		$app -> response -> setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);
	$s = isset($p['path']) ? $p['path'] : '';

	if (!is_file(BASE_LAB.$s)) {
		$output = array('code' => 404, 'status' => 'fail', 'message' => $GLOBALS['messages'][60000]);
	} else {
		try {
			$lab = new Lab(BASE_LAB.$s, $tenant);
			$output = apiDeleteLab($lab);
		} catch(Exception $e) {
			$output = array('code' => 400, 'status' => 'fail', 'message' => $e -> getMessage());
		}
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});

// PNETLab dashboard "open lab" session factory. EVE-NG's model: opening a lab
// sets pods.lab_id for the caller (updatePodLab) — that pointer IS the open
// session, and every other consumer (console minter, /api/labs/session/*) reads it.
$app -> post('/api/labs/session/factory/(:action)', function($action = '') use ($app, $db) {
	list($user, $tenant, $output) = apiAuthorization($db, sessionCookieCandidates('unetlab_session'));
	if ($user === False) {
		$app -> response -> setStatus($output['code']);
		$app -> response -> setBody(json_encode($output));
		return;
	}

	$event = json_decode($app -> request() -> getBody());
	$p = json_decode(json_encode($event), True);

	if ($action === 'create' || $action === 'join') {
		// create: open a lab by path (dashboard "Open"). join: re-open the caller's
		// own already-open session (Running Labs "Open") — same effect in EVE.
		$s = isset($p['path']) ? $p['path'] : '';
		if ($s === '' && $action === 'join') {
			// No path given: fall back to the lab this user already has open.
			$s = isset($user['lab']) ? ltrim((string) $user['lab'], '/') : '';
		}
		if ($s === '' || !is_file(BASE_LAB.'/'.$s)) {
			$output = array('code' => 404, 'status' => 'fail', 'message' => 'Lab not found');
			$app -> response -> setStatus(404);
			$app -> response -> setBody(json_encode($output));
			return;
		}
		$rc = updatePodLab($db, $tenant, '/'.$s);
		if ($rc !== 0) {
			$output = array('code' => 500, 'status' => 'error', 'message' => $GLOBALS['messages'][$rc]);
		} else {
			$output = array('code' => 200, 'status' => 'success', 'message' => '');
		}
	} else if ($action === 'stopNodes') {
		// Stop every node in the caller's open lab.
		$s = isset($user['lab']) ? ltrim((string) $user['lab'], '/') : '';
		if ($s === '' || !is_file(BASE_LAB.'/'.$s)) {
			$output = array('code' => 409, 'status' => 'fail', 'message' => 'No lab open in this session');
		} else {
			try {
				$lab = new Lab(BASE_LAB.'/'.$s, $tenant);
				$output = apiStopLabNodes($lab, $tenant);
			} catch(Exception $e) {
				$output = array('code' => 400, 'status' => 'fail', 'message' => $e -> getMessage());
			}
		}
	} else if ($action === 'destroy') {
		// Close the session (EVE keeps no per-session state beyond pods.lab_id).
		$rc = updatePodLab($db, $tenant, null);
		if ($rc !== 0) {
			$output = array('code' => 500, 'status' => 'error', 'message' => $GLOBALS['messages'][$rc]);
		} else {
			$output = array('code' => 200, 'status' => 'success', 'message' => '');
		}
	} else {
		$output = array('code' => 400, 'status' => 'fail', 'message' => 'Unknown session action');
	}

	$app -> response -> setStatus($output['code']);
	$app -> response -> setBody(json_encode($output));
});


/***************************************************************************
 * Run
 **************************************************************************/
$app -> run();
?>
