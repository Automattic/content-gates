<?php
/**
 * Settings page handler tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Admin\Segment_Admin;
use WPDieException;

/**
 * The admin-post handler refuses requests that lack the capability or the nonce.
 */
final class Test_Segment_Admin extends Content_Gates_Test_Case {

	/**
	 * The handler under test.
	 */
	private function admin(): Segment_Admin {
		return new Segment_Admin( $this->plugin()->segments(), $this->plugin()->crawlers() );
	}

	/**
	 * An editor cannot manage segments, nonce or not.
	 */
	public function test_handle_refuses_without_manage_options(): void {
		wp_set_current_user( $this->create_user( 'editor' ) );
		$_POST['operation'] = 'create';
		$_POST['name']      = 'Sneaky';

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'not allowed' );
		$this->admin()->handle();
	}

	/**
	 * An administrator without a valid nonce is refused before anything is written.
	 */
	public function test_handle_refuses_without_nonce(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );
		$_POST['operation'] = 'create';
		$_POST['name']      = 'Sneaky';

		try {
			$this->admin()->handle();
			$this->fail( 'check_admin_referer() should have died.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'link you followed has expired', $e->getMessage() );
		}
		$this->assertNull( $this->plugin()->segments()->resolve( 'sneaky' ) );
	}
}
