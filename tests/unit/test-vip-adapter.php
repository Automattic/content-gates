<?php
/**
 * Integration against an unmodified, locally provisioned VIP platform class.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates\Tests\Unit;

use Automattic\VIP\Cache\Vary_Cache;
use ContentGates\Cache\VIP_Adapter;
use WP_UnitTestCase;

/** The real class remains outside the distributed plugin. */
final class Test_VIP_Adapter extends WP_UnitTestCase {
	/** Load the pinned source when explicitly provisioned by test tooling. */
	public function set_up(): void {
		parent::set_up();
		$source = dirname( __DIR__, 2 ) . '/artifacts/vip-test/class-vary-cache.php';
		if ( ! is_readable( $source ) ) {
			$this->markTestSkipped( 'Provision the pinned upstream VIP source; see docs/prototype-evidence.md.' );
		}
		require_once $source;
		Vary_Cache::unload();
		Vary_Cache::load();
	}

	/** Remove state and fixture cookies. */
	public function tear_down(): void {
		if ( class_exists( Vary_Cache::class ) ) {
			Vary_Cache::unload();
		}
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Test fixture for the real platform parser.
		unset( $_COOKIE['vip-go-seg'] );
		parent::tear_down();
	}

	/** Registration must precede parsing and never reset parsed membership. */
	public function test_real_cookie_parse_and_registration_idempotence(): void {
		$adapter = new VIP_Adapter();
		$this->assertTrue( $adapter->register( 'cg_1_123' ) );
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Test fixture for the real platform parser.
		$_COOKIE['vip-go-seg'] = 'vc-v1__cg_1_123_--_1---__cg_1_456_--_other';
		Vary_Cache::parse_cookies();
		$this->assertTrue( $adapter->contains( 'cg_1_123' ) );
		$this->assertFalse( $adapter->contains( 'cg_1_456' ) );
		$this->assertTrue( $adapter->register( 'cg_1_123' ) );
		$this->assertTrue( $adapter->contains( 'cg_1_123' ) );
	}

	/** Genuine mutation and removal work with another populated group. */
	public function test_real_assignment_and_final_removal_limitation(): void {
		$adapter = new VIP_Adapter();
		$adapter->register( 'cg_1_123' );
		$adapter->register( 'cg_1_456' );
		$this->assertTrue( $adapter->assign( 'cg_1_123', true ) );
		$this->assertTrue( $adapter->assign( 'cg_1_456', true ) );
		$this->assertTrue( $adapter->assign( 'cg_1_123', false ) );
		$this->assertFalse( $adapter->contains( 'cg_1_123' ) );
		$result = $adapter->assign( 'cg_1_456', false );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_final_revocation_unsupported', $result->get_error_code() );
	}
}
