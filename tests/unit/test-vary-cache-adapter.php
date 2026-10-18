<?php
/**
 * WPVIP Vary_Cache adapter tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Vary_Cache_Adapter;
use WP_UnitTestCase;

require_once __DIR__ . '/class-fake-vary-cache.php';

if ( ! class_exists( '\\Automattic\\VIP\\Cache\\Vary_Cache' ) ) {
	class_alias( Fake_Vary_Cache::class, '\\Automattic\\VIP\\Cache\\Vary_Cache' );
}

/**
 * Verifies the platform-specific membership lifecycle.
 */
final class Test_Vary_Cache_Adapter extends WP_UnitTestCase {

	/**
	 * Reset provider and request cookie state.
	 */
	public function set_up(): void {
		parent::set_up();
		Fake_Vary_Cache::reset();
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Reset simulated browser request state.
		unset( $_COOKIE['vip-go-seg'], $_COOKIE['vip-go-auth'] );
	}

	/**
	 * REST callers can force the queued platform cookie to be sent.
	 */
	public function test_flush_sends_platform_headers(): void {
		$adapter = new Vary_Cache_Adapter();

		$this->assertTrue( $adapter->flush_membership_headers() );
		$this->assertSame( 1, Fake_Vary_Cache::$send_count );
	}

	/**
	 * Removing the final membership expires both platform cookie forms.
	 */
	public function test_removing_final_membership_clears_stale_cookies(): void {
		$adapter = new Vary_Cache_Adapter();
		$adapter->register_group( 'cg_1_10' );
		$adapter->set_reader_membership( 'cg_1_10', true );
		$_COOKIE['vip-go-seg']  = 'stale-plaintext'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Simulate browser request state.
		$_COOKIE['vip-go-auth'] = 'stale-encrypted'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Simulate browser request state.

		$this->assertTrue( $adapter->set_reader_membership( 'cg_1_10', false ) );
		$this->assertTrue( $adapter->flush_membership_headers() );
		$this->assertArrayNotHasKey( 'vip-go-seg', $_COOKIE ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Verify simulated browser request state.
		$this->assertArrayNotHasKey( 'vip-go-auth', $_COOKIE ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Verify simulated browser request state.
	}

	/**
	 * Removing one membership keeps cookies when another membership remains.
	 */
	public function test_removing_one_membership_keeps_other_cookie_state(): void {
		$adapter = new Vary_Cache_Adapter();
		$adapter->register_group( 'cg_1_10' );
		$adapter->register_group( 'cg_1_11' );
		$adapter->set_reader_membership( 'cg_1_10', true );
		$adapter->set_reader_membership( 'cg_1_11', true );
		$_COOKIE['vip-go-seg'] = 'existing'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Simulate browser request state.

		$this->assertTrue( $adapter->set_reader_membership( 'cg_1_10', false ) );
		$this->assertTrue( $adapter->flush_membership_headers() );
		$this->assertSame( 'existing', $_COOKIE['vip-go-seg'] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Verify controlled test state.
	}
}
