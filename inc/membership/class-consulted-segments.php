<?php
/**
 * Consulted segment request state.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

/**
 * Records membership dependencies for one response.
 */
final class Consulted_Segments {

	/**
	 * Segment keys read before finalization.
	 *
	 * @var array<string, true>
	 */
	private array $consulted = array();

	/**
	 * Frozen, filtered segment keys.
	 *
	 * @var list<string>|null
	 */
	private ?array $finalized = null;

	/**
	 * Record a membership read.
	 *
	 * Reads after finalization cannot change the response dependency set.
	 *
	 * @param string $group_key Stable cache group key.
	 */
	public function record( string $group_key ): void {
		if ( null !== $this->finalized ) {
			return;
		}

		$this->consulted[ $group_key ] = true;
	}

	/**
	 * Freeze and report the consulted set.
	 *
	 * @return list<string>
	 */
	public function finalize(): array {
		if ( null !== $this->finalized ) {
			return $this->finalized;
		}

		$consulted = array_keys( $this->consulted );
		sort( $consulted, SORT_STRING );

		/**
		 * Filters the segment keys reported for the current response.
		 *
		 * @since 0.1.0
		 *
		 * @param list<string> $consulted Stable cache group keys.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public hook name is specified.
		$filtered = apply_filters( 'content_gate_consulted_segments', $consulted );
		$filtered = is_array( $filtered ) ? $filtered : $consulted;

		$normalized = array();
		foreach ( $filtered as $group_key ) {
			if ( is_string( $group_key ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $group_key ) ) {
				$normalized[ $group_key ] = true;
			}
		}

		$this->finalized = array_keys( $normalized );
		sort( $this->finalized, SORT_STRING );

		/**
		 * Fires after the response dependency set is frozen.
		 *
		 * @since 0.1.0
		 *
		 * @param list<string> $consulted Stable cache group keys.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public hook name is specified.
		do_action( 'content_gate_finalize_variance', $this->finalized );

		return $this->finalized;
	}

	/**
	 * Return the current set without finalizing it.
	 *
	 * @return list<string>
	 */
	public function current(): array {
		if ( null !== $this->finalized ) {
			return $this->finalized;
		}

		$current = array_keys( $this->consulted );
		sort( $current, SORT_STRING );
		return $current;
	}
}
