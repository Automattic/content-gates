<?php
/**
 * View As switcher tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Admin\View_As;
use Automattic\VIP\ContentGates\Headers;

use function Automattic\VIP\ContentGates\is_reader_in_segment;

/**
 * Editors can preview a reader's view; such responses never cache.
 */
final class Test_View_As extends Content_Gates_Test_Case {

	/**
	 * The override replaces cookie membership and still obeys the declared set.
	 */
	public function test_override_replaces_membership(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$this->create_meter( 'Metered' );
		$post_id = $this->create_gated_post( array( 'subscriber', 'metered' ) );

		$this->become_reader( array( 'donor' => '1' ) );
		$this->plugin()->view_as()->apply( 'subscriber,metered=last,ghost=1' );
		$this->request_post( $post_id );

		$this->assertTrue( is_reader_in_segment( 'subscriber' ) );
		$this->assertTrue( is_reader_in_segment( 'metered', 'last' ) );
		$this->assertStringContainsString( self::SECRET, $this->render_content( $post_id ) );
		$this->assertSame( 'metered=last, subscriber', $this->plugin()->view_as()->summary() );
	}

	/**
	 * Responses under the switcher are no-store.
	 */
	public function test_switched_response_is_no_store(): void {
		$this->create_segment( 'Subscriber' );
		$this->plugin()->view_as()->apply( 'subscriber' );
		$this->request_page( home_url( '/' ) );

		$this->assertStringContainsString( 'no-store', (string) Headers::last( 'Cache-Control' ) );
		$this->assertSame( 'subscriber', Headers::last( View_As::HEADER ) );
	}

	/**
	 * Without the switcher no Cache-Control header is sent by the plugin.
	 */
	public function test_normal_response_sends_no_cache_control(): void {
		$this->create_segment( 'Subscriber' );
		$this->request_page( home_url( '/' ) );

		$this->assertNull( Headers::last( 'Cache-Control' ) );
	}

	/**
	 * The cookie only applies to logged-in users who can edit posts.
	 */
	public function test_cookie_requires_capability(): void {
		$this->create_segment( 'Subscriber' );
		$_COOKIE[ View_As::COOKIE ] = 'subscriber';

		wp_set_current_user( 0 );
		$this->plugin()->view_as()->apply_cookie();
		$this->assertFalse( $this->plugin()->membership()->has_override() );

		wp_set_current_user( $this->create_user( 'subscriber' ) );
		$this->plugin()->view_as()->apply_cookie();
		$this->assertFalse( $this->plugin()->membership()->has_override() );

		wp_set_current_user( $this->create_user( 'contributor' ) );
		$this->plugin()->view_as()->apply_cookie();
		$this->assertTrue( $this->plugin()->membership()->has_override() );

		unset( $_COOKIE[ View_As::COOKIE ] );
	}
}
