<?php
/**
 * html/includes/version.php — release label for the PNETLab GUI's version line.
 * EVE-NG keeps its version in themes/adminLTE/VERSION; expose it under the
 * constant names the ported login page expects (no DB, no side effects).
 */
if (!defined('PNET_VERSION')) {
	$v = @file_get_contents(__DIR__ . '/../themes/adminLTE/VERSION');
	define('PNET_VERSION', trim((string) $v));
}
if (!defined('PNET_RELEASE')) define('PNET_RELEASE', 'resolute');
