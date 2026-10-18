<?php
/**
 * Missing cache provider adapter.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

use WP_Error;

/**
 * Fails closed when no supported cache provider is available.
 */
final class Unavailable_Cache_Adapter implements Cache_Adapter {

	/**
	 * Register a cache group for the current request.
	 *
	 * @param string $group_key Stable cache group key.
	 * @return WP_Error
	 */
	public function register_group( string $group_key ) {
		unset( $group_key );
		return $this->error();
	}

	/**
	 * Test current reader membership.
	 *
	 * @param string $group_key Stable cache group key.
	 */
	public function is_reader_in_group( string $group_key ): bool {
		unset( $group_key );
		return false;
	}

	/**
	 * Set or remove current reader membership.
	 *
	 * @param string $group_key Stable cache group key.
	 * @param bool   $member    Whether the reader is a member.
	 * @return WP_Error
	 */
	public function set_reader_membership( string $group_key, bool $member ) {
		unset( $group_key, $member );
		return $this->error();
	}

	/**
	 * Report that no provider can send a membership cookie.
	 *
	 * @return WP_Error
	 */
	public function flush_membership_headers() {
		return $this->error();
	}

	/**
	 * Build the provider availability error.
	 */
	private function error(): WP_Error {
		return new WP_Error(
			'content_gates_provider_unavailable',
			__( 'The cache personalization provider is not available.', 'content-gates' )
		);
	}
}
