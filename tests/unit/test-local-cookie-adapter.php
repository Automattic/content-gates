<?php
/**
 * Local cache adapter tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Local_Cookie_Adapter;
use WP_UnitTestCase;

/**
 * Verifies the local prototype cookie matches the Vary_Cache plaintext shape.
 */
final class Test_Local_Cookie_Adapter extends WP_UnitTestCase {

	/**
	 * Original cookie value.
	 *
	 * @var string|null
	 */
	private ?string $original_cookie = null;

	/**
	 * Preserve request cookie state.
	 */
	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture state.
		$this->original_cookie = isset( $_COOKIE['vip-go-seg'] ) ? (string) $_COOKIE['vip-go-seg'] : null;
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Test fixture state.
		unset( $_COOKIE['vip-go-seg'] );
	}

	/**
	 * Restore request cookie state.
	 */
	public function tear_down(): void {
		if ( null === $this->original_cookie ) {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Test fixture state.
			unset( $_COOKIE['vip-go-seg'] );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Test fixture state.
			$_COOKIE['vip-go-seg'] = $this->original_cookie;
		}

		parent::tear_down();
	}

	/**
	 * Membership is sorted and removal clears one group without affecting others.
	 */
	public function test_serializes_sorted_membership_and_removes_groups(): void {
		$adapter = new Local_Cookie_Adapter();
		$this->assertTrue( $adapter->register_group( 'cg_1_20' ) );
		$this->assertTrue( $adapter->register_group( 'cg_1_10' ) );

		$this->assertTrue( $adapter->set_reader_membership( 'cg_1_20', true ) );
		$this->assertTrue( $adapter->set_reader_membership( 'cg_1_10', true ) );
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Assert local test fixture state.
		$this->assertSame( 'vc-v1__cg_1_10_--_1---__cg_1_20_--_1', $_COOKIE['vip-go-seg'] );
		$this->assertTrue( $adapter->is_reader_in_group( 'cg_1_10' ) );

		$this->assertTrue( $adapter->set_reader_membership( 'cg_1_10', false ) );
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Assert local test fixture state.
		$this->assertSame( 'vc-v1__cg_1_20_--_1', $_COOKIE['vip-go-seg'] );
		$this->assertFalse( $adapter->is_reader_in_group( 'cg_1_10' ) );
	}

	/**
	 * Assignment requires prior registration.
	 */
	public function test_rejects_unregistered_group(): void {
		$adapter = new Local_Cookie_Adapter();
		$result  = $adapter->set_reader_membership( 'cg_1_10', true );

		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_group_not_registered', $result->get_error_code() );
	}
}
