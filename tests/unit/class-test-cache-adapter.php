<?php
/**
 * Controllable cache adapter test double.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Cache_Adapter;
use WP_Error;

/**
 * Controllable cache adapter for membership tests.
 */
final class Test_Cache_Adapter implements Cache_Adapter {

	/**
	 * Registered group keys.
	 *
	 * @var list<string>
	 */
	public array $registered = array();

	/**
	 * Membership changes.
	 *
	 * @var list<array{0:string,1:bool}>
	 */
	public array $changes = array();

	/**
	 * Current membership by group.
	 *
	 * @var array<string, bool>
	 */
	public array $members = array();

	/**
	 * Optional mutation error.
	 *
	 * @var WP_Error|null
	 */
	public ?WP_Error $set_error = null;

	/**
	 * Register a group.
	 *
	 * @param string $group_key Group key.
	 * @return true
	 */
	public function register_group( string $group_key ) {
		$this->registered[] = $group_key;
		return true;
	}

	/**
	 * Read membership.
	 *
	 * @param string $group_key Group key.
	 */
	public function is_reader_in_group( string $group_key ): bool {
		return $this->members[ $group_key ] ?? false;
	}

	/**
	 * Change membership.
	 *
	 * @param string $group_key Group key.
	 * @param bool   $member    Membership value.
	 * @return true|WP_Error
	 */
	public function set_reader_membership( string $group_key, bool $member ) {
		if ( null !== $this->set_error ) {
			return $this->set_error;
		}

		$this->changes[]             = array( $group_key, $member );
		$this->members[ $group_key ] = $member;
		return true;
	}

	/**
	 * Confirm a test header flush.
	 *
	 * @return true
	 */
	public function flush_membership_headers() {
		return true;
	}
}
