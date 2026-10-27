<?php
/**
 * Segment value object.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Segments;

/**
 * One segment as the rest of the plugin sees it.
 *
 * Immutable snapshot of a segment record. The key is the stored reference
 * everywhere (block attributes, post meta, the declared-set header); the
 * group name is what `Vary_Cache` and the edge see.
 *
 * @since 0.1.0
 */
final class Segment {

	public const TYPE_BOOLEAN = 'boolean';
	public const TYPE_METER   = 'meter';

	/**
	 * Pattern a key or value must match.
	 */
	public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]+$/';

	/**
	 * Maximum key length.
	 */
	public const KEY_MAX_LENGTH = 16;

	/**
	 * Constructor.
	 *
	 * @param int    $id         Post ID of the segment record.
	 * @param string $key        Immutable key.
	 * @param string $name       Display name.
	 * @param bool   $active     Whether the segment is active (`publish`).
	 * @param string $type       `boolean` or `meter`.
	 * @param int    $limit      Meter limit (articles per period); 0 for boolean segments.
	 * @param int    $period     Meter period in seconds; 0 for boolean segments.
	 * @param bool   $crawler    Whether this is a VIP-maintained crawler segment.
	 * @param string $group_name `Vary_Cache` group name.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $key,
		public readonly string $name,
		public readonly bool $active,
		public readonly string $type,
		public readonly int $limit,
		public readonly int $period,
		public readonly bool $crawler,
		public readonly string $group_name
	) {}

	/**
	 * Whether this is a meter segment.
	 *
	 * @since 0.1.0
	 */
	public function is_meter(): bool {
		return self::TYPE_METER === $this->type;
	}

	/**
	 * The type a list sorts and labels by: `boolean`, `crawler`, or `meter`.
	 *
	 * A crawler segment is stored as a boolean segment with the crawler flag,
	 * but every list shows it as its own type.
	 *
	 * @since 0.1.0
	 */
	public function sort_type(): string {
		return $this->crawler ? 'crawler' : $this->type;
	}

	/**
	 * Plain array form for REST, the editor, and WP-CLI.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'      => $this->id,
			'key'     => $this->key,
			'name'    => $this->name,
			'active'  => $this->active,
			'type'    => $this->type,
			'limit'   => $this->limit,
			'period'  => $this->period,
			'crawler' => $this->crawler,
			'group'   => $this->group_name,
		);
	}
}
