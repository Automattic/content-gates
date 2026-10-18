<?php
/**
 * Local integration fixture; install manually as an MU plugin ONLY in tests.
 * Loads a pinned, unmodified upstream VIP class, never a fake or alternate cookie.
 */
if ( 'local' !== wp_get_environment_type() ) {
	return;
}
$source = WP_PLUGIN_DIR . '/content-gates/artifacts/vip-test/class-vary-cache.php';
if ( is_readable( $source ) ) {
	require_once $source;
}
