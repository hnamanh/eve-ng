<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/includes/api_status.php
 *
 * Various system status commands for REST APIs.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

/*
 * Function to get CPU usage percentage.
 *
 * @return  int                         CPU usage (percentage) or -1 if not valid
 */
function apiGetCPUUsage() {
	// Sample /proc/stat twice (robust across top/locale changes)
	$read = function () {
		$line = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!$line || strpos($line[0], 'cpu ') !== 0) return null;
		$f = array_map('intval', explode(' ', substr($line[0], 4)));
		$idle = ($f[3] ?? 0) + ($f[4] ?? 0); // idle + iowait
		return [array_sum($f), $idle];
	};
	$a = $read();
	usleep(200000); // 200ms window
	$b = $read();
	if (!$a || !$b) return -1;
	$dtot = $b[0] - $a[0];
	$didle = $b[1] - $a[1];
	if ($dtot <= 0) return -1;
	return (int) round(100 * (1 - $didle / $dtot));
}

/*
 * Function to get disk usage percentage.
 *
 * @return  int                         Disk usage (percentage) or -1 if not valid
 */
function apiGetDiskUsage() {
	// Checking disk usage
	$cmd = 'df -h /';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		return (int) preg_replace('/^.+ ([0-9]+)% .+/', '$1', $o[1]);
	} else {
		return -1;
	}
}

/*
 * Function to get mem usage percentage.
 *
 * @return  Array                       RAM usage (percentage) as cache and data or -1 if not valid
 */
function apiGetOldMemUsage() {
	// Checking RAM usage
	$cmd = 'free';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		$total = (int) preg_replace('/^Mem:\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)$/', '$1', $o[1]);
		$used = (int) preg_replace('/^Mem:\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)$/', '$2', $o[1]);
		$cached = (int) preg_replace('/^Mem:\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)\ +([0-9\.]+)$/', '$6', $o[1]);
		return Array(round($cached / $total * 100), round(($used - $cached) / $total * 100));
	} else {
		return Array(-1, -1);
	}
}

function apiGetMemUsage() {
	$data = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (!$data) return Array(-1, -1);
	array_pop($data) ;
	$meminfo = array();
	foreach ($data as $line) {
		if (strpos($line, ':') === false) continue;
		list($key, $val) = explode(":", $line);
		$meminfo[$key] = (int) preg_replace('/^([0-9\.]+)\ +.*$/','$1',trim($val));
	}
	$total=$meminfo["MemTotal"] ?? 0;
	if ($total <= 0) return Array(-1, -1);
	$cached=$meminfo["Cached"] ?? 0;
	$avail=$meminfo["MemAvailable"] ?? $total;
	return Array(round(100 - ($cached / $total * 100)), round(100 - ($avail / $total * 100)));
}

/*
 * Function to running wrapper for IOL, Dynamips and QEMU.
 *
 * @return  Array                       Running IOL/Dynamips/QEMU wrappers or -1 if not valid
 */
function apiGetRunningWrappers() {
	// Checking running wrappers
	$cmd = 'pgrep -f -c -P 1 iol_wrapper';
	exec($cmd, $o_iol, $rc);
	$cmd = 'pgrep -f -c -P 1 dynamips_wrapper';
	exec($cmd, $o_dynamips, $rc);
	$cmd = 'pgrep -f -c -P 1 qemu_wrapper';
	exec($cmd, $o_qemu, $rc);
	$cmd= 'docker -H=tcp://127.0.0.1:4243 ps -q | wc -l';
	exec($cmd, $o_docker, $rc);
	$cmd = 'pgrep -f -c -P 1 vpcs';
	exec($cmd, $o_vpcs, $rc);
	return Array((int) ($o_iol[0] ?? 0), (int) ($o_dynamips[0] ?? 0), (int) ($o_qemu[0] ?? 0), (int) ($o_docker[0] ?? 0), (int) ($o_vpcs[0] ?? 0));
}

/*
 * Function to get swap usage percentage.
 *
 * @return  int                         Swap usage (percentage) or -1 if not valid
 */
function apiGetSwapUsage() {
	// Read /proc/meminfo (robust; no swap => 100% free)
	$mem = array();
	foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
		if (strpos($line, ':') === false) continue;
		list($k, $v) = explode(':', $line);
		$mem[trim($k)] = (int) preg_replace('/^([0-9]+).*/', '$1', trim($v));
	}
	$total = $mem['SwapTotal'] ?? 0;
	if ($total <= 0) return 100; // no swap configured
	$free = $mem['SwapFree'] ?? 0;
	return (int) round(100 * $free / $total);
}
/*
 * Function to set UKSM status.
 *
 * @return  Bool Success operation
 */

function apiSetUksm($p) {
     if  ( $p['state'] == true ) {
           $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a uksmon" ;
           error_log(date('M d H:i:s ').'DEBUG: uksm on' );
     } else {
           $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a uksmoff" ;
           error_log(date('M d H:i:s ').'DEBUG: uksm off' );
     }
     exec($cmd, $o, $rc);
     if ($rc == 0 ) {
                $output['code'] = 200;
                $output['status'] = 'success';
                $output['message'] = $GLOBALS['messages'][60065];
     } else {
                $output['code'] = 400;
                $output['status'] = 'fail';
                $output['message'] = $GLOBALS['messages'][60066];
     }
     return $output;
}

/*
 * Function to set KSM status.
 *
 * @return  Bool Success operation
 */

function apiSetKsm($p) {
     if  ( $p['state'] == true ) {
           $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a ksmon" ;
           error_log(date('M d H:i:s ').'DEBUG: uksm on' );
     } else {
           $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a ksmoff" ;
           error_log(date('M d H:i:s ').'DEBUG: uksm off' );
     }
     exec($cmd, $o, $rc);
     if ($rc == 0 ) {
                $output['code'] = 200;
                $output['status'] = 'success';
                $output['message'] = $GLOBALS['messages'][60065];
     } else {
                $output['code'] = 400;
                $output['status'] = 'fail';
                $output['message'] = $GLOBALS['messages'][60066];
     }
     return $output;
}

/*
 * Function to set cpulimit  status.
 *
 * @return  Bool Success operation
 */

function apiSetCpuLimit($p) {
     if  ( $p['state'] == true ) {
           $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a cpulimiton" ;
     } else {
           $cmd = "sudo /opt/unetlab/wrappers/unl_wrapper -a cpulimitoff" ;
     }
     exec($cmd, $o, $rc);
     if ($rc == 0 ) {
                $output['code'] = 200;
                $output['status'] = 'success';
                $output['message'] = $GLOBALS['messages'][60063];
     } else {
                $output['code'] = 400;
                $output['status'] = 'fail';
                $output['message'] = $GLOBALS['messages'][60064];
     }
     return $output;
}
?>
