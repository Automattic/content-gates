<?php
/**
 * Segment lookup contract.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Segments;

/**
 * Resolves segment references to Segment objects.
 *
 * The membership layer depends on this interface rather than on the
 * registry so it can be tested with an in-memory source.
 */
interface Segment_Source {

	/**
	 * Find a segment by post ID (int) or key (string).
	 *
	 * @param int|string $ref Segment reference.
	 * @return Segment|null Null when nothing matches.
	 */
	public function find( int|string $ref ): ?Segment;

	/**
	 * All known segments, active and inactive, keyed by segment key.
	 *
	 * @return array<string, Segment>
	 */
	public function all(): array;
}
