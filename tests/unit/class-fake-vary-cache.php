<?php
/**
 * WPVIP Vary_Cache test double.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use WP_Error;

/**
 * Minimal platform API double used by adapter tests.
 */
final class Fake_Vary_Cache {

	/**
	 * Current group values.
	 *
	 * @var array<string, string>
	 */
	public static array $groups = array();

	/**
	 * Number of explicit sends.
	 *
	 * @var int
	 */
	public static int $send_count = 0;

	/**
	 * Reset provider state.
	 */
	public static function reset(): void {
		self::$groups     = array();
		self::$send_count = 0;
	}

	/**
	 * Register a provider group.
	 *
	 * @param string $group Group key.
	 */
	public static function register_group( string $group ): bool {
		if ( ! array_key_exists( $group, self::$groups ) ) {
			self::$groups[ $group ] = '';
		}
		return true;
	}

	/**
	 * Read one membership.
	 *
	 * @param string $group Group key.
	 * @param string $value Membership value.
	 */
	public static function is_user_in_group_segment( string $group, string $value ): bool {
		return ( self::$groups[ $group ] ?? '' ) === $value;
	}

	/**
	 * Set one membership.
	 *
	 * @param string $group Group key.
	 * @param string $value Membership value.
	 * @return true|WP_Error
	 */
	public static function set_group_for_user( string $group, string $value ) {
		if ( ! array_key_exists( $group, self::$groups ) ) {
			return new WP_Error( 'invalid_vary_group_notregistered', 'Register the group first.' );
		}

		self::$groups[ $group ] = $value;
		return true;
	}

	/**
	 * Return current provider state.
	 *
	 * @return array<string, string>
	 */
	public static function get_groups(): array {
		return self::$groups;
	}

	/**
	 * Record the provider cookie flush.
	 */
	public static function send_headers(): void {
		++self::$send_count;
	}
}
