<?php
/**
 * Genuine VIP Vary_Cache adapter, with no fallback cookie implementation.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates\Cache;

use Automattic\VIP\Cache\Vary_Cache;

/** The adapter never loads, resets, or replaces VIP's platform class. */
final class VIP_Adapter implements Transport {
	/**
	 * Already registered identities, to avoid clearing parsed values.
	 *
	 * @var array<string, bool>
	 */
	private array $registered = array();

	/** Report platform availability. */
	public function available(): bool {
		return class_exists( Vary_Cache::class );
	}

	/**
	 * Register only once.
	 *
	 * @param string $group Stable identity.
	 */
	public function register( string $group ): bool {
		if ( isset( $this->registered[ $group ] ) ) {
			return true;
		}
		if ( ! $this->available() || ! Vary_Cache::register_group( $group ) ) {
			return false;
		}
		$this->registered[ $group ] = true;
		return true;
	}

	/**
	 * Read the exact supported boolean token.
	 *
	 * @param string $group Stable identity.
	 */
	public function contains( string $group ): bool {
		return $this->available() && Vary_Cache::is_user_in_group_segment( $group, '1' );
	}

	/**
	 * Delegate assignment, including empty-value revocation.
	 *
	 * @param string $group Stable identity.
	 * @param bool   $member Grant or revoke.
	 * @return true|\WP_Error
	 */
	public function assign( string $group, bool $member ) {
		if ( ! $this->available() ) {
			return new \WP_Error( 'content_gates_unavailable', 'VIP Vary_Cache is unavailable.' );
		}
		// The pinned platform does not clear its cookie when its final value is
		// removed. Refuse a misleading success until upstream fixes that writer.
		if ( ! $member ) {
			$values = array_filter( Vary_Cache::get_groups(), static fn ( string $value ): bool => '' !== trim( $value ) );
			if ( isset( $values[ $group ] ) && 1 === count( $values ) ) {
				return new \WP_Error( 'content_gates_final_revocation_unsupported', 'This VIP cookie writer cannot clear its final membership. An upstream fix is required.' );
			}
		}
		$result = Vary_Cache::set_group_for_user( $group, $member ? '1' : '' );
		return true === $result ? true : new \WP_Error( 'content_gates_transport_error', 'VIP rejected the membership mutation.' );
	}

	/** REST does not execute WordPress's normal send_headers action. */
	public function send(): void {
		if ( $this->available() ) {
			Vary_Cache::send_headers();
		}
	}
}
