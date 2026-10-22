<?php
/**
 * Smoke tests for plugin bootstrap.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Plugin;
use WP_UnitTestCase;

/**
 * Proves the plugin loads inside WordPress and fires its load hook.
 */
final class Test_Plugin extends WP_UnitTestCase {

	/**
	 * The bootstrap activates the plugin via muplugins_loaded.
	 */
	public function test_plugin_boots_once(): void {
		$plugin = Plugin::instance();

		$this->assertTrue( $plugin->is_booted() );
		$this->assertSame( $plugin, Plugin::instance() );
		$this->assertSame( 1, did_action( 'content_gates_loaded' ) );

		$plugin->boot();

		$this->assertSame( 1, did_action( 'content_gates_loaded' ), 'boot() must be idempotent.' );
	}

	/**
	 * Plugin constants are defined for consumers.
	 */
	public function test_constants_are_defined(): void {
		$this->assertSame( '0.1.0', CONTENT_GATES_VERSION );
		$this->assertStringEndsWith( '/content-gates.php', CONTENT_GATES_FILE );
	}
}
