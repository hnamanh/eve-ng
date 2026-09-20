<?php
/**
 * console_config.php — shared constants for the EVE-NG Resolute web console.
 *
 * Installed to /etc/eve-webconsole/console_config.php (OUTSIDE the web root) by
 * install-ubuntu26.sh, which replaces the placeholder GUAC_CRYPT_KEY with a
 * per-install 32-byte secret and writes the same value to guac.env for the
 * guacamole-lite bridge. Both sides MUST be identical or RDP tokens fail.
 */

// Where short-lived telnet/vnc token files are written (tmpfs: cleared on reboot,
// never hits disk). Must match PNET_TOKEN_DIR in eve-console-mux.service.
define('TOKEN_DIR', '/dev/shm/eve-tokens');

// Token lifetime hint (seconds) returned to the client; real expiry is enforced
// by reaping TOKEN_DIR (eve-token-janitor.timer).
define('TOKEN_TTL', 60);

// AES-256 key for guacamole-lite RDP tokens. EXACTLY 32 bytes, shared with
// GUAC_CRYPT_KEY in /etc/eve-webconsole/guac.env. Replaced at install time.
define('GUAC_CRYPT_KEY', 'REPLACE_WITH_32_BYTE_SECRET_AT_INSTALL');
