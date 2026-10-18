<?php
/**
 * Plugin Name:       Content Gates
 * Plugin URI:        https://github.com/Automattic/content-gates
 * Description:       Cache-aware content gating for WordPress on WPVIP.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Automattic
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       content-gates
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CONTENT_GATES_VERSION', '0.1.0' );
define( 'CONTENT_GATES_API_VERSION', '1.0' );
define( 'CONTENT_GATES_FILE', __FILE__ );
define( 'CONTENT_GATES_PATH', __DIR__ . '/' );

/**
 * Autoload plugin classes from `inc/` using WordPress file naming.
 *
 * `ContentGates\Providers\Static_Provider` maps to
 * `inc/providers/class-static-provider.php`. Interfaces and traits use the
 * `interface-` and `trait-` prefixes.
 *
 * @param string $class_name Fully qualified class name.
 */
function autoload( string $class_name ): void {
	$prefix = __NAMESPACE__ . '\\';
	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$parts    = explode( '\\', $relative );
	$name     = array_pop( $parts );
	$slug     = strtolower( str_replace( '_', '-', $name ) );
	$dir      = CONTENT_GATES_PATH . 'inc/';

	if ( array() !== $parts ) {
		$dir .= strtolower( str_replace( '_', '-', implode( '/', $parts ) ) ) . '/';
	}

	foreach ( array( 'class', 'interface', 'trait' ) as $type ) {
		$file = $dir . $type . '-' . $slug . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
			return;
		}
	}
}

spl_autoload_register( __NAMESPACE__ . '\\autoload' );

require_once CONTENT_GATES_PATH . 'inc/functions.php';

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
