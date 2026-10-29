<?php
/**
 * Membership API tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\Cache\Vary_Cache;
use Automattic\VIP\ContentGates\Headers;
use Automattic\VIP\ContentGates\Membership\Membership;
use WP_Error;

use function Automattic\VIP\ContentGates\assign_reader_to_segment;
use function Automattic\VIP\ContentGates\declare_segment;
use function Automattic\VIP\ContentGates\get_reader_segment_value;
use function Automattic\VIP\ContentGates\is_reader_in_segment;
use function Automattic\VIP\ContentGates\remove_reader_from_segment;

/**
 * Writes go through `Vary_Cache`; reads obey the declared set.
 */
final class Test_Membership extends Content_Gates_Test_Case {

	/**
	 * The cookie lifetime filter runs when groups are registered, so a theme
	 * can change it; values under an hour are clamped to the hour.
	 */
	public function test_cookie_lifetime_filter_applies_at_registration(): void {
		$expiry = new \ReflectionProperty( Vary_Cache::class, 'cookie_expiry' );
		add_filter( 'content_gates_cookie_lifetime', static fn(): int => 2 * HOUR_IN_SECONDS );
		$this->plugin()->membership()->register_groups();
		$this->assertSame( 2 * HOUR_IN_SECONDS, $expiry->getValue() );

		add_filter( 'content_gates_cookie_lifetime', static fn(): int => 1, 20 );
		$this->plugin()->membership()->register_groups();
		$this->assertSame( HOUR_IN_SECONDS, $expiry->getValue(), 'Clamped before Vary_Cache can warn.' );
	}

	/**
	 * Writes are refused on a request the Edge Worker narrowed; a full lookup is fine.
	 */
	public function test_writes_are_refused_on_narrowed_requests(): void {
		$this->create_segment( 'Subscriber' );
		$_SERVER[ Membership::LOOKUP_HEADER ] = 'narrowed';

		$result = assign_reader_to_segment( 'subscriber' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'content_gates_narrowed_request', $result->get_error_code() );
		$this->assertFalse( $this->plugin()->membership()->is_dirty(), 'Nothing was written.' );

		$_SERVER[ Membership::LOOKUP_HEADER ] = 'full';
		$this->assertTrue( assign_reader_to_segment( 'subscriber' ) );
		unset( $_SERVER[ Membership::LOOKUP_HEADER ] );
	}

	/**
	 * The bundled Vary_Cache copy loads in the local environment.
	 */
	public function test_platform_is_available_locally(): void {
		$this->assertTrue( $this->plugin()->platform()->is_available() );
		$this->assertTrue( $this->plugin()->platform()->is_local_copy() );
		$this->assertTrue( class_exists( Vary_Cache::class, false ) );
	}

	/**
	 * Every segment is a registered Vary_Cache group, inactive ones included.
	 */
	public function test_segments_are_registered_as_groups(): void {
		$this->create_segment( 'Subscriber' );
		$inactive = $this->create_segment( 'Old' );
		$this->plugin()->segments()->set_active( $inactive->id, false );
		$this->new_request();

		$this->assertArrayHasKey( 'subscriber', Vary_Cache::get_groups() );
		$this->assertArrayHasKey( 'old', Vary_Cache::get_groups() );
	}

	/**
	 * Assignment writes the group value and marks the cookie for writing.
	 */
	public function test_assign_and_remove(): void {
		$this->create_segment( 'Subscriber' );

		$this->assertTrue( assign_reader_to_segment( 'subscriber' ) );
		$this->assertSame( '1', Vary_Cache::get_groups()['subscriber'] );
		$this->assertTrue( $this->plugin()->membership()->is_dirty() );

		$this->assertTrue( remove_reader_from_segment( 'subscriber' ) );
		$this->assertSame( '', Vary_Cache::get_groups()['subscriber'] );
	}

	/**
	 * Writes fail with the documented error codes.
	 */
	public function test_write_errors(): void {
		$this->create_segment( 'Subscriber' );
		$inactive = $this->create_segment( 'Old' );
		$this->plugin()->segments()->set_active( $inactive->id, false );
		$this->create_meter( 'Metered' );
		$this->new_request();

		$this->assertErrorCode( 'content_gates_invalid_segment', assign_reader_to_segment( 'nope' ) );
		$this->assertErrorCode( 'content_gates_inactive_segment', assign_reader_to_segment( 'old' ) );
		$this->assertErrorCode( 'content_gates_invalid_value', assign_reader_to_segment( 'subscriber', 'bad value!' ) );
		$this->assertErrorCode( 'content_gates_invalid_value', assign_reader_to_segment( 'metered', 'open' ), 'Meter values are written only by the edge.' );
		$this->assertTrue( remove_reader_from_segment( 'old' ), 'Removal from an inactive segment clears stale cookies.' );
		$this->assertErrorCode( 'content_gates_invalid_segment', remove_reader_from_segment( 'nope' ) );
	}

	/**
	 * After send_headers the write window is closed.
	 */
	public function test_writes_are_too_late_after_send_headers(): void {
		$this->create_segment( 'Subscriber' );
		do_action( 'send_headers', $GLOBALS['wp'] );

		$this->assertErrorCode( 'content_gates_too_late', assign_reader_to_segment( 'subscriber' ) );
		$this->assertErrorCode( 'content_gates_too_late', remove_reader_from_segment( 'subscriber' ) );
	}

	/**
	 * A read of an undeclared segment is false and flagged.
	 */
	public function test_undeclared_read_fails_closed(): void {
		$this->setExpectedIncorrectUsage( 'is_reader_in_segment' );
		$this->create_segment( 'Subscriber' );
		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_page( home_url( '/' ) );

		$this->assertFalse( is_reader_in_segment( 'subscriber' ) );
	}

	/**
	 * A declared segment reads the cookie value.
	 */
	public function test_declared_read_reports_membership(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$this->become_reader( array( 'subscriber' => '1' ) );

		add_action(
			'wp',
			static function (): void {
				declare_segment( 'subscriber' );
				declare_segment( 'donor' );
			}
		);
		$this->request_page( home_url( '/' ) );

		$this->assertTrue( is_reader_in_segment( 'subscriber' ) );
		$this->assertTrue( is_reader_in_segment( 'subscriber', '1' ) );
		$this->assertFalse( is_reader_in_segment( 'subscriber', '2' ) );
		$this->assertFalse( is_reader_in_segment( 'donor' ) );
		$this->assertSame( '1', get_reader_segment_value( 'subscriber' ) );
		$this->assertNull( get_reader_segment_value( 'donor' ) );
	}

	/**
	 * A read before the declared set is final fails closed, even for a declared key.
	 */
	public function test_read_before_finalization_fails_closed(): void {
		$this->setExpectedIncorrectUsage( 'is_reader_in_segment' );
		$this->create_segment( 'Subscriber' );
		$this->become_reader( array( 'subscriber' => '1' ) );
		declare_segment( 'subscriber' );

		$this->assertFalse( is_reader_in_segment( 'subscriber' ) );
	}

	/**
	 * Meter states: open and last are in, wall and absent are out.
	 */
	public function test_meter_values(): void {
		$this->create_meter( 'Metered' );
		add_action(
			'wp',
			static function (): void {
				declare_segment( 'metered' );
			}
		);

		foreach ( array(
			'open' => true,
			'last' => true,
			'wall' => false,
		) as $state => $expected ) {
			$this->new_request();
			$this->become_reader( array( 'metered' => $state ) );
			$this->request_page( home_url( '/' ) );
			$this->assertSame( $expected, is_reader_in_segment( 'metered' ), "State {$state}" );
			$this->assertSame( $state, get_reader_segment_value( 'metered' ) );
		}

		$this->new_request();
		$this->become_reader( array() );
		$this->request_page( home_url( '/' ) );
		$this->assertFalse( is_reader_in_segment( 'metered' ) );
		$this->assertNull( get_reader_segment_value( 'metered' ) );
	}

	/**
	 * Unknown references read as false and are flagged.
	 */
	public function test_unknown_reference_is_flagged(): void {
		$this->setExpectedIncorrectUsage( 'is_reader_in_segment' );
		$this->setExpectedIncorrectUsage( 'get_reader_segment_value' );

		$this->assertFalse( is_reader_in_segment( 'nope' ) );
		$this->assertNull( get_reader_segment_value( 'nope' ) );
	}

	/**
	 * Inactive segments read as false without a notice.
	 */
	public function test_inactive_segment_reads_false(): void {
		$segment = $this->create_segment( 'Old' );
		$this->become_reader( array( 'old' => '1' ) );
		$this->plugin()->segments()->set_active( $segment->id, false );
		$this->request_page( home_url( '/' ) );

		$this->assertFalse( is_reader_in_segment( 'old' ) );
	}

	/**
	 * Vary_Cache sends Vary once any group is registered, and the cookie after a write.
	 */
	public function test_vary_cache_fires_did_send_headers(): void {
		$this->create_segment( 'Subscriber' );
		$sent = $this->capture_did_send_headers();

		assign_reader_to_segment( 'subscriber' );
		$this->request_page( home_url( '/' ) );

		$this->assertSame( array( true, true ), $sent() );
	}

	/**
	 * Reasserting the value the reader already has writes nothing.
	 *
	 * Providers often assign on every request. If each of those wrote the
	 * cookie, every entitled response would carry `Set-Cookie` and never be
	 * stored by the edge.
	 */
	public function test_reasserting_membership_is_a_no_op(): void {
		$this->create_segment( 'Subscriber' );
		$this->become_reader( array( 'subscriber' => '1' ) );
		$sent = $this->capture_did_send_headers();

		$this->assertTrue( assign_reader_to_segment( 'subscriber' ) );
		$this->assertFalse( $this->plugin()->membership()->is_dirty() );
		$this->request_page( home_url( '/' ) );

		$this->assertSame( array( true, false ), $sent(), 'Vary was sent; no cookie was written.' );
		$this->assertSame( array(), Headers::values( 'Set-Cookie' ) );
	}

	/**
	 * Removing a group the reader is not in writes nothing.
	 */
	public function test_removing_an_absent_group_is_a_no_op(): void {
		$this->create_segment( 'Subscriber' );
		$this->become_reader( array() );
		$sent = $this->capture_did_send_headers();

		$this->assertTrue( remove_reader_from_segment( 'subscriber' ) );
		$this->assertFalse( $this->plugin()->membership()->is_dirty() );
		$this->request_page( home_url( '/' ) );

		$this->assertSame( array( true, false ), $sent() );
	}

	/**
	 * Removing the reader's last group expires the cookie, once.
	 *
	 * The bundled `Vary_Cache` is the production version, from before
	 * vip-go-mu-plugins #7454. It skips the write when every group is empty,
	 * so without the plugin's expiry the reader would send the old cookie
	 * back and still be a member on the next request.
	 */
	public function test_removing_the_last_group_expires_the_cookie(): void {
		$this->assertFalse( $this->plugin()->platform()->expires_empty_cookie(), 'The bundled Vary_Cache is the production version.' );
		$this->create_segment( 'Subscriber' );
		$this->become_reader( array( 'subscriber' => '1' ) );

		$this->assertTrue( remove_reader_from_segment( 'subscriber' ) );
		$this->assertTrue( $this->plugin()->membership()->is_dirty() );
		$this->request_page( home_url( '/' ) );

		$this->assertCount( 1, Headers::values( 'Set-Cookie' ) );
		$this->assertStringStartsWith( 'vip-go-seg=; Max-Age=0; Path=', (string) Headers::last( 'Set-Cookie' ) );
	}

	/**
	 * The expiry has the scope `Vary_Cache` sets the cookie with.
	 *
	 * Both apply `vip_vary_cache_cookie_path` and
	 * `vip_vary_cache_cookie_domain` to `COOKIEPATH` and `COOKIE_DOMAIN`
	 * as they are. The filters here change the scope only when they receive
	 * the constants unchanged, so a different input would show up as a
	 * different scope, and the expiry would miss the cookie.
	 */
	public function test_removing_the_last_group_expires_the_cookie_with_the_filtered_scope(): void {
		add_filter( 'vip_vary_cache_cookie_path', static fn( mixed $path ): mixed => constant( 'COOKIEPATH' ) === $path ? '/news/' : $path );
		add_filter( 'vip_vary_cache_cookie_domain', static fn( mixed $domain ): mixed => COOKIE_DOMAIN === $domain ? '.example.org' : $domain );
		$this->create_segment( 'Subscriber' );
		$this->become_reader( array( 'subscriber' => '1' ) );

		$this->assertTrue( remove_reader_from_segment( 'subscriber' ) );
		$this->request_page( home_url( '/' ) );

		$this->assertSame( 'vip-go-seg=; Max-Age=0; Path=/news/; Domain=.example.org', Headers::last( 'Set-Cookie' ) );
	}

	/**
	 * The expiry is sent before any provider callback on the same action.
	 *
	 * Providers redirect on `vip_vary_cache_did_send_headers` and exit, as
	 * the example provider does. A callback that exits before the plugin's
	 * would leave the old cookie in place. The callback here records what
	 * was sent when it ran, at a priority below the default.
	 */
	public function test_removing_the_last_group_expires_the_cookie_before_a_provider_redirect(): void {
		$this->create_segment( 'Subscriber' );
		$this->become_reader( array( 'subscriber' => '1' ) );
		$at_redirect = array();
		add_action(
			'vip_vary_cache_did_send_headers',
			static function () use ( &$at_redirect ): void {
				$at_redirect = Headers::values( 'Set-Cookie' );
			},
			1
		);

		$this->assertTrue( remove_reader_from_segment( 'subscriber' ) );
		$this->request_page( home_url( '/' ) );

		$this->assertCount( 1, $at_redirect, 'The cookie was expired before the provider could redirect.' );
	}

	/**
	 * With vip-go-mu-plugins #7454, the plugin leaves the expiry to `Vary_Cache`.
	 *
	 * That `Vary_Cache` expires the cookie itself, with the same path and
	 * domain, so a second expiry from the plugin would be redundant. PHPUnit
	 * can load only the bundled copy, so the test turns on the platform's
	 * detection and checks that the plugin sends nothing.
	 */
	public function test_removing_the_last_group_leaves_the_expiry_to_newer_vary_cache(): void {
		$platform = $this->plugin()->platform();
		( new \ReflectionProperty( $platform, 'expires_empty_cookie' ) )->setValue( $platform, true );

		try {
			$this->create_segment( 'Subscriber' );
			$this->become_reader( array( 'subscriber' => '1' ) );

			$this->assertTrue( remove_reader_from_segment( 'subscriber' ) );
			$this->assertTrue( $this->plugin()->membership()->is_dirty() );
			$this->request_page( home_url( '/' ) );

			$this->assertSame( array(), Headers::values( 'Set-Cookie' ) );
		} finally {
			$platform->load();
		}
	}

	/**
	 * Removing one of two groups rewrites the cookie; nothing is expired.
	 */
	public function test_removing_one_group_keeps_the_cookie(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$this->become_reader(
			array(
				'donor'      => '1',
				'subscriber' => '1',
			)
		);
		$sent = $this->capture_did_send_headers();

		$this->assertTrue( remove_reader_from_segment( 'donor' ) );
		$this->request_page( home_url( '/' ) );

		$this->assertSame( array( true, true ), $sent(), 'Vary_Cache rewrote the cookie.' );
		$this->assertSame( array(), Headers::values( 'Set-Cookie' ), 'No expiry.' );
	}


	/**
	 * Assert a WP_Error with a code.
	 *
	 * @param string $code    Expected code.
	 * @param mixed  $result  Value under test.
	 * @param string $message Failure message.
	 */
	private function assertErrorCode( string $code, mixed $result, string $message = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $message );
		$this->assertSame( $code, $result->get_error_code(), $message );
	}
}
