<?php
/**
 * Segment value object.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Segments;

/**
 * An immutable description of one reader segment.
 *
 * A segment is either a `cg_segment` post (stable post ID, renamable name,
 * immutable key) or a segment registered in code (no ID, always active).
 * The key is the `Vary_Cache` group name and appears in cookies and cache
 * keys; it never changes once created.
 */
final class Segment {

	/**
	 * Constructor.
	 *
	 * @param int|null $id     Post ID, or null for a code-registered segment.
	 * @param string   $key    Cache group name. Matches /^[A-Za-z0-9_-]+$/.
	 * @param string   $name   Display name.
	 * @param bool     $active Whether the segment is active.
	 */
	public function __construct(
		private ?int $id,
		private string $key,
		private string $name,
		private bool $active
	) {}

	/**
	 * Post ID, or null for a code-registered segment.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Cache group name.
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Display name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Whether the segment accepts assignments and membership checks.
	 */
	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * Whether the segment was registered in code rather than stored as a post.
	 */
	public function is_registered_in_code(): bool {
		return null === $this->id;
	}
}
