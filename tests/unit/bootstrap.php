<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the WordPress test library from WP_TESTS_DIR (set automatically in
 * the wp-env tests container) and activates the plugin before WordPress
 * boots.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

$content_gates_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $content_gates_tests_dir || '' === $content_gates_tests_dir ) {
	$content_gates_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! is_readable( $content_gates_tests_dir . '/includes/functions.php' ) ) {
	fwrite( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- STDERR, not the filesystem.
		STDERR,
		"WordPress test library not found at {$content_gates_tests_dir}.\n" .
		"Run `npm run test:php` to execute PHPUnit inside the wp-env tests container,\n" .
		"or set WP_TESTS_DIR to a checkout of wordpress-develop/tests/phpunit.\n"
	);
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $content_gates_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/content-gates.php';
	}
);

require $content_gates_tests_dir . '/includes/bootstrap.php';
