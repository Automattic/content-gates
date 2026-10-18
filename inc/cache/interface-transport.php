<?php
/**
 * Platform membership boundary.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates\Cache;

/** Cookie serialization remains the platform's responsibility. */
interface Transport {
	/** Report platform availability. */
	public function available(): bool;
	/**
	 * Register before cookie parsing.
	 *
	 * @param string $group Stable identity.
	 */
	public function register( string $group ): bool;
	/**
	 * Read exact membership token.
	 *
	 * @param string $group Stable identity.
	 */
	public function contains( string $group ): bool;
	/**
	 * Mutate the current reader.
	 *
	 * @param string $group Stable identity.
	 * @param bool   $member Grant or revoke.
	 * @return true|\WP_Error
	 */
	public function assign( string $group, bool $member );
	/** Commit using the platform cookie writer. */
	public function send(): void;
}
