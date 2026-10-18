<?php
/**
 * Consulted segment set.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

/**
 * Records which segments a response read while rendering.
 *
 * Every membership read records its key here. After render the finalizer
 * freezes the set and reports it as the response's variance signal.
 *
 * Reads after `freeze()` are still recorded in the live set (nothing is
 * lost for diagnostics), but `keys()` keeps returning the frozen snapshot:
 * the reported set must be exactly what the response was keyed on.
 *
 * @since 0.1.0
 */
final class Consulted_Set {

	/**
	 * Live set, key => true.
	 *
	 * @var array<string, true>
	 */
	private array $keys = array();

	/**
	 * Snapshot taken by freeze(), or null while live.
	 *
	 * @var string[]|null
	 */
	private ?array $frozen = null;

	/**
	 * Record a read of a segment key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Segment key.
	 */
	public function record( string $key ): void {
		$this->keys[ $key ] = true;
	}

	/**
	 * Sorted unique keys: the frozen snapshot once frozen, else the live set.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function keys(): array {
		if ( null !== $this->frozen ) {
			return $this->frozen;
		}

		return $this->sorted();
	}

	/**
	 * Keys recorded so far, including reads after freeze().
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function live_keys(): array {
		return $this->sorted();
	}

	/**
	 * Snapshot the set. Later calls keep the first snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] The frozen keys.
	 */
	public function freeze(): array {
		if ( null === $this->frozen ) {
			$this->frozen = $this->sorted();
		}

		return $this->frozen;
	}

	/**
	 * Whether freeze() ran.
	 *
	 * @since 0.1.0
	 */
	public function is_frozen(): bool {
		return null !== $this->frozen;
	}

	/**
	 * Clear everything and unfreeze. For tests.
	 *
	 * @since 0.1.0
	 */
	public function reset(): void {
		$this->keys   = array();
		$this->frozen = null;
	}

	/**
	 * Live keys, sorted.
	 *
	 * @return string[]
	 */
	private function sorted(): array {
		$keys = array_keys( $this->keys );
		sort( $keys, SORT_STRING );

		return $keys;
	}
}
