<?php
/**
 * Edge version-skew notice tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Admin\Edge_Notice;
use Automattic\VIP\ContentGates\Segments\Crawler_Config;

/**
 * The worker's configuration version is remembered on admin requests and compared with the plugin's.
 */
final class Test_Edge_Notice extends Content_Gates_Test_Case {

	/**
	 * Forget the request header and screen the tests set.
	 */
	public function tear_down(): void {
		unset( $_SERVER[ Crawler_Config::EDGE_HEADER ], $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	/**
	 * The notice object under test.
	 */
	private function notice(): Edge_Notice {
		return new Edge_Notice( $this->plugin()->crawlers() );
	}

	/**
	 * A front-end request never writes the option; an admin request does, when the value changed.
	 */
	public function test_version_is_remembered_on_admin_requests_only(): void {
		$_SERVER[ Crawler_Config::EDGE_HEADER ] = '42';

		$this->notice()->remember_edge_version();
		$this->assertFalse( get_option( Edge_Notice::OPTION ), 'A front-end request must not reach the database.' );

		set_current_screen( 'dashboard' );
		$this->notice()->remember_edge_version();
		$this->assertSame( 42, (int) get_option( Edge_Notice::OPTION ) );

		$_SERVER[ Crawler_Config::EDGE_HEADER ] = 'not a number';
		$this->notice()->remember_edge_version();
		$this->assertSame( 42, (int) get_option( Edge_Notice::OPTION ), 'A malformed header changes nothing.' );
	}

	/**
	 * Administrators see the notice only while the versions differ.
	 */
	public function test_notice_prints_on_mismatch_only(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );
		$plugin_version = $this->plugin()->crawlers()->version();

		update_option( Edge_Notice::OPTION, $plugin_version + 1, false );
		ob_start();
		$this->notice()->notice();
		$mismatch = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-warning', $mismatch );
		$this->assertStringContainsString( sprintf( 'version %d but the Edge Worker reports version %d', $plugin_version, $plugin_version + 1 ), $mismatch );

		update_option( Edge_Notice::OPTION, $plugin_version, false );
		ob_start();
		$this->notice()->notice();
		$this->assertSame( '', (string) ob_get_clean(), 'Matching versions print nothing.' );

		wp_set_current_user( $this->create_user( 'editor' ) );
		update_option( Edge_Notice::OPTION, $plugin_version + 1, false );
		ob_start();
		$this->notice()->notice();
		$this->assertSame( '', (string) ob_get_clean(), 'Only administrators see it.' );
	}
}
