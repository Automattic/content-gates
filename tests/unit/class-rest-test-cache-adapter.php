<?php
/**
 * REST cache adapter test double.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Cache_Adapter;

/**
 * Minimal successful cache adapter for REST tests.
 */
final class Rest_Test_Cache_Adapter implements Cache_Adapter {

	/**
	 * Last membership value.
	 *
	 * @var bool|null
	 */
	public ?bool $member = null;

	/**
	 * Whether queued membership headers were flushed.
	 *
	 * @var bool
	 */
	public bool $flushed = false;

	/**
	 * Register a group.
	 *
	 * @param string $group_key Group key.
	 * @return true
	 */
	public function register_group( string $group_key ) {
		unset( $group_key );
		return true;
	}

	/**
	 * Read membership.
	 *
	 * @param string $group_key Group key.
	 */
	public function is_reader_in_group( string $group_key ): bool {
		unset( $group_key );
		return true === $this->member;
	}

	/**
	 * Change membership.
	 *
	 * @param string $group_key Group key.
	 * @param bool   $member    Membership value.
	 * @return true
	 */
	public function set_reader_membership( string $group_key, bool $member ) {
		unset( $group_key );
		$this->member = $member;
		return true;
	}

	/**
	 * Record an explicit REST header flush.
	 *
	 * @return true
	 */
	public function flush_membership_headers() {
		$this->flushed = true;
		return true;
	}
}
