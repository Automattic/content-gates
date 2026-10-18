<?php
/**
 * In-memory segment source for tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Segments\Segment;
use ContentGates\Segments\Segment_Source;

/**
 * Resolves segments from an array so membership tests need no database.
 */
final class Array_Segment_Source implements Segment_Source {

	/**
	 * Segments keyed by key.
	 *
	 * @var array<string, Segment>
	 */
	private array $segments = array();

	/**
	 * Constructor.
	 *
	 * @param Segment[] $segments Initial segments.
	 */
	public function __construct( array $segments = array() ) {
		foreach ( $segments as $segment ) {
			$this->add( $segment );
		}
	}

	/**
	 * Add or replace a segment.
	 *
	 * @param Segment $segment Segment.
	 */
	public function add( Segment $segment ): void {
		$this->segments[ $segment->key() ] = $segment;
	}

	/**
	 * Find by ID (int) or key (string).
	 *
	 * @param int|string $ref Reference.
	 */
	public function find( int|string $ref ): ?Segment {
		if ( is_string( $ref ) ) {
			return $this->segments[ $ref ] ?? null;
		}

		foreach ( $this->segments as $segment ) {
			if ( $segment->id() === $ref ) {
				return $segment;
			}
		}

		return null;
	}

	/**
	 * All segments keyed by key.
	 *
	 * @return array<string, Segment>
	 */
	public function all(): array {
		return $this->segments;
	}
}
