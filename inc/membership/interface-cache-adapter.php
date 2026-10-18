<?php
/**
 * Cache personalization adapter contract.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

use WP_Error;

/**
 * Isolates membership logic from the cache personalization provider.
 */
interface Cache_Adapter {

	/**
	 * Register a cache group for the current request.
	 *
	 * @param string $group_key Stable cache group key.
	 * @return true|WP_Error
	 */
	public function register_group( string $group_key );

	/**
	 * Test current reader membership.
	 *
	 * @param string $group_key Stable cache group key.
	 */
	public function is_reader_in_group( string $group_key ): bool;

	/**
	 * Set or remove current reader membership.
	 *
	 * @param string $group_key Stable cache group key.
	 * @param bool   $member    Whether the reader is a member.
	 * @return true|WP_Error
	 */
	public function set_reader_membership( string $group_key, bool $member );

	/**
	 * Send any membership cookie queued by the provider.
	 *
	 * @return true|WP_Error
	 */
	public function flush_membership_headers();
}
