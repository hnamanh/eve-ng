<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/includes/api_authentication.php
 *
 * Users related functions for REST APIs.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

/*
 * Function to login a user.
 *
 * @param	PDO			$db				PDO object for database connection
 * @param	Array		$p				Parameters
 * @param	String		$cookie			Session cookie
 * @return	bool						True if valid
 */
function apiLogin($db, $html5_db, $p, $cookie) {
	if (!isset($p['username'])) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][90011];
		return $output;
	} else {
		$username = $p['username'];
	}

	if (!isset($p['password'])) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][90012];
		return $output;
	} else {
		$hash = hash('sha256', $p['password']);
	}
	
	if (!isset($p['html5'])) $p['html5'] = 1;

	$rc = deleteSessions($db, $username);
	if ($rc !== 0) {
		// Cannot delete old sessions
		$output['code'] = 500;
		$output['status'] = 'error';
		$output['message'] = $GLOBALS['messages'][$rc];
		return $output;
	}

	$query = 'SELECT COUNT(*) as cnt FROM users WHERE username = :username AND password = :password;';
	$statement = $db -> prepare($query);
	$statement -> bindParam(':username', $username, PDO::PARAM_STR);
	$statement -> bindParam(':password', $hash, PDO::PARAM_STR);
	$statement -> execute();
	$result = $statement -> fetch();

	if ($result['cnt'] == 1) {
		// User/Password match
		if (checkUserExpiration($db, $username) === False) {
			$output['code'] = 401;
			$output['status'] = 'unauthorized';
			$output['message'] = $GLOBALS['messages'][90018];
			return $output;
		}

		// UNetLab is running in multi-user mode
		$rc = configureUserPod($db, $username);
		if ($rc !== 0) {
			// Cannot configure a POD
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
			return $output;
		}

		$rc = updateUserCookie($db, $username, $cookie);
		if ($rc !== 0) {
			// Cannot update user cookie
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
			return $output;
		}
		if ( $p['html5'] == 1 ) {
			//enable on databse
				$query = "update users set html5 = 1 where username = '".$username."' ;";
				$statement = $db -> prepare($query);
				$statement -> execute();

				// Guacamole DB rows are optional: the Resolute web console is stateless
				// (token_mint.php mints per-connection tokens from EVE's own session),
				// so a missing guacdb must never break login.
				if ($html5_db !== False) {
					// Guacamole 1.x: username lives in guacamole_entity; user row references it by entity_id
					$query = "delete from guacamole_user where entity_id = (select entity_id from guacamole_entity where type='USER' and name = '".$username."')";
					$statement = $html5_db -> prepare($query);
					$statement -> execute();

					$query = "select id from pods where username = '".$username."';";
					$statement = $db -> prepare($query);
					$statement -> execute();
					$result = $statement -> fetch();
					$pod = $result["id"];


					// Guacamole >= 1.0 verifies: SHA256(password + HEX(salt)) == password_hash
					$salt = random_bytes(32);
					$hash = hash('sha256', 'unl' . strtoupper(bin2hex($salt)), true);

					// Guacamole 1.x schema: username lives in guacamole_entity; the user row
					// references it by entity_id. Keep EVE's pod+1000 id convention as entity_id.
					$query = "replace into guacamole_entity (entity_id, name, type) values (?, ?, 'USER')";
					$statement = $html5_db -> prepare($query);
					$statement -> execute(array($pod+1000, $username));

					$query = "replace into guacamole_user(user_id,entity_id,password_salt,password_hash,password_date) values (?,?,?,?,NOW())";
					$statement = $html5_db -> prepare($query);
					$statement -> execute(array($pod+1000, $pod+1000, $salt, $hash));

					$query="replace into guacamole_user_permission ( entity_id , affected_user_id , permission ) values ( ? , ? , 'UPDATE' )";
					$statement = $html5_db -> prepare($query);
					$statement -> execute(array($pod+1000, $pod+1000));
				} else {
					// Still need the pod id for updateUserToken below.
					$query = "select id from pods where username = '".$username."';";
					$statement = $db -> prepare($query);
					$statement -> execute();
					$result = $statement -> fetch();
					$pod = $result["id"];
				}

				$rc = updateUserToken($db,$username,$pod);
			} else { 
				$query = "update users set html5 = 0 where username = '".$username."' ;";
                $statement = $db -> prepare($query);
                $statement -> execute();

                if ($html5_db !== False) {
					$query = "delete from guacamole_user where entity_id = (select entity_id from guacamole_entity where type='USER' and name = '".$username."')";
                    $statement = $html5_db -> prepare($query);
                    $statement -> execute();
                }

			};

		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][90013];
	} else if ($result['cnt'] == 0) {
		// User/Password does not match
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][90014];
	} else {
		// Invalid result
		$output['code'] = 500;
		$output['status'] = 'error';
		$output['message'] = $GLOBALS['messages'][90015];
	}

	return $output;
}

/*
 * Function to logout a user.
 *
 * @param	PDO			$db				PDO object for database connection
 * @param	String		$cookie			Session cookie
 * @return	bool						True if valid
 */
function apiLogout($db, $cookie) {
	$query = 'UPDATE users SET cookie = NULL, session = NULL WHERE cookie = :cookie;';
	$statement = $db -> prepare($query);
	$statement -> bindParam(':cookie', $cookie, PDO::PARAM_STR);
	$statement -> execute();
	//$result = $statement -> fetch();

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][90019];
	return $output;
}

/*
 * Function to check authorization
 *
 * @param	PDO			$db				PDO object for database connection
 * @param	String		$cookie			Session cookie
 * @return	Array						Username, role, tenant if logged in; JSend data if not authorized
 */
function apiAuthorization($db, $cookie) {
	$output = Array();
	$user = getUserByCookie($db, $cookie);	// This will check session/web/pod expiration too

	if (empty($user)) {
		// Used not logged in
		$output['code'] = 412;
		$output['status'] = 'unauthorized';
		$output['message'] = $GLOBALS['messages']['90001'];
		return Array(False, False, $output);
	} else {
		// User logged in
		$rc = updateUserCookie($db, $user['username'], $cookie);
		if ($rc !== 0) {
			// Cannot update user cookie
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
			return Array(False, False, $output);
		}
	}

	return Array($user, $user['tenant'], False);
}
?>
