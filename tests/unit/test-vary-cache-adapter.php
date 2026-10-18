<?php
/**
 * Vary_Cache_Adapter tests against a fake Vary_Cache.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use Automattic\VIP\Cache\Vary_Cache;
use ContentGates\Membership\Vary_Cache_Adapter;
use WP_UnitTestCase;

if ( ! class_exists( Vary_Cache::class, false ) ) {
	require_once __DIR__ . '/class-fake-vary-cache.php';
}

/**
 * The adapter forwards to Vary_Cache and maps its error codes.
 */
final class Test_Vary_Cache_Adapter extends WP_UnitTestCase {

	/**
	 * Skip on a real VIP install and reset the fake.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! defined( Vary_Cache::class . '::CONTENT_GATES_FAKE' ) ) {
			$this->markTestSkipped( 'A real Vary_Cache is loaded; the fake-based adapter tests do not apply.' );
		}

		Vary_Cache::reset();
	}

	/**
	 * Reset the fake.
	 */
	public function tear_down(): void {
		Vary_Cache::reset();
		parent::tear_down();
	}

	/**
	 * Registration and assignment reach Vary_Cache.
	 */
	public function test_register_and_set_group_delegate(): void {
		$adapter = new Vary_Cache_Adapter();
		$adapter->register_group( 'subscriber' );

		$this->assertSame( array( 'subscriber' => '' ), Vary_Cache::get_groups() );
		$this->assertTrue( $adapter->set_group( 'subscriber', '1' ) );
		$this->assertSame( array( 'subscriber' => '1' ), $adapter->groups() );
		$this->assertTrue( Vary_Cache::is_user_in_group( 'subscriber' ) );
		$this->assertFalse( $adapter->is_local_simulation() );
	}

	/**
	 * Every Vary_Cache error code maps to a Content Gates code.
	 */
	public function test_error_code_mapping(): void {
		$adapter = new Vary_Cache_Adapter();
		$adapter->register_group( 'subscriber' );

		$result = $adapter->set_group( 'bad key', '1' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment_key', $result->get_error_code() );
		$this->assertSame( 'invalid_vary_group_name', $result->get_error_data()['vary_cache_code'] );

		$result = $adapter->set_group( 'subscriber', 'bad value' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_value', $result->get_error_code() );

		$result = $adapter->set_group( 'unregistered', '1' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_not_registered', $result->get_error_code() );

		Vary_Cache::send_headers();
		$result = $adapter->set_group( 'subscriber', '1' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_too_late', $result->get_error_code() );
	}

	/**
	 * The flush() call asks Vary_Cache to send headers and marks the adapter done.
	 */
	public function test_flush_sends_headers_once(): void {
		$adapter = new Vary_Cache_Adapter();
		$adapter->register_group( 'subscriber' );
		$adapter->set_group( 'subscriber', '1' );

		$this->assertFalse( $adapter->did_send_headers() );

		$adapter->flush();

		$this->assertTrue( $adapter->did_send_headers() );
		$this->assertTrue( Vary_Cache::did_send_headers() );
		$this->assertSame( 'vc-v1__subscriber_--_1', Vary_Cache::written_cookie() );
	}

	/**
	 * The send_headers hook callback flips the flag one priority before Vary_Cache.
	 */
	public function test_register_hooks_before_vary_cache(): void {
		$adapter = new Vary_Cache_Adapter();
		$adapter->register();

		$this->assertSame( PHP_INT_MAX - 1, has_action( 'send_headers', array( $adapter, 'mark_sent' ) ) );

		$adapter->mark_sent();
		$this->assertTrue( $adapter->did_send_headers() );
	}

	/**
	 * Registering before parse_cookies keeps the cookie value; registering
	 * after resets it to ''. This is the ordering rule behind init priority 1.
	 */
	public function test_register_then_parse_ordering(): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Simulating the incoming request.
		$_COOKIE[ Vary_Cache::COOKIE_SEGMENT ] = 'vc-v1__subscriber_--_1';

		try {
			$adapter = new Vary_Cache_Adapter();
			$adapter->register_group( 'subscriber' );
			Vary_Cache::parse_cookies();
			$this->assertSame( '1', $adapter->groups()['subscriber'] );

			$adapter->register_group( 'subscriber' );
			$this->assertSame( '', $adapter->groups()['subscriber'], 'Vary_Cache resets a group on late registration.' );
		} finally {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Restoring request state.
			unset( $_COOKIE[ Vary_Cache::COOKIE_SEGMENT ] );
		}
	}
}
