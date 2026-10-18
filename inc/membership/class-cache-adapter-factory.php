<?php
/**
 * Cache adapter selection.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

/**
 * Selects the VIP adapter or the explicitly local prototype adapter.
 */
final class Cache_Adapter_Factory {

	/**
	 * Create the adapter for the current environment.
	 */
	public static function create(): Cache_Adapter {
		if ( Vary_Cache_Adapter::is_available() ) {
			return new Vary_Cache_Adapter();
		}

		if ( 'local' === wp_get_environment_type() ) {
			return new Local_Cookie_Adapter();
		}

		return new Unavailable_Cache_Adapter();
	}
}
