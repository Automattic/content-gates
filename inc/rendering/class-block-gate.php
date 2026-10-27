<?php
/**
 * Group block enforcement.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Rendering;

use Automattic\VIP\ContentGates\Membership\Declared_Set;
use Automattic\VIP\ContentGates\Membership\Membership;
use Automattic\VIP\ContentGates\Segments\Segment_Registry;
use WP_Block;
use WP_HTML_Tag_Processor;

/**
 * Withholds Group blocks whose reader is not entitled.
 *
 * `core/group` gains two attributes, registered on the server here and in
 * the editor by `src/`:
 *
 * - `contentGatesSegments`: list of segment keys. Empty means unrestricted.
 * - `contentGatesMode`: `in` shows the Group to readers in any listed
 *   segment; `not-in` shows it to readers in none of them.
 *
 * Enforcement runs on `pre_render_block`, which core applies to nested
 * blocks as well. Returning `''` skips the Group, its wrapper, its inner
 * blocks, and their render callbacks. A withheld Group leaves no markup
 * behind. When access is allowed the block renders normally, so an author's
 * core visibility settings still apply.
 *
 * On the crawler variant each rendered paywalled Group gets a marker class so
 * the paywall structured data can point at it. Only `in` Groups are
 * paywalled: a `not-in` Group is what readers outside the segment see, so
 * for the crawler it is the free part, not the gated one.
 *
 * @since 0.1.0
 */
final class Block_Gate {

	public const BLOCK         = 'core/group';
	public const ATTR_SEGMENTS = 'contentGatesSegments';
	public const ATTR_MODE     = 'contentGatesMode';
	public const ATTR_INDEX    = 'contentGatesIndex';
	public const MODE_IN       = 'in';
	public const MODE_NOT_IN   = 'not-in';
	public const MARKER_CLASS  = 'content-gates-gated';

	/**
	 * Gated Groups rendered so far, per post ID in the loop.
	 *
	 * @var array<int, int>
	 */
	private array $rendered_index = array();

	/**
	 * Memo of the crawler check for this request.
	 *
	 * @var bool|null
	 */
	private ?bool $is_crawler = null;

	/**
	 * Constructor.
	 *
	 * @param Membership       $membership Membership reads.
	 * @param Segment_Registry $segments   Segment lookup.
	 * @param Declared_Set     $declared   Declared set.
	 */
	public function __construct(
		private Membership $membership,
		private Segment_Registry $segments,
		private Declared_Set $declared
	) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'register_block_type_args', array( $this, 'register_attributes' ), 10, 2 );
		add_filter( 'pre_render_block', array( $this, 'withhold' ), 10, 2 );
		add_filter( 'render_block_data', array( $this, 'stamp_index' ) );
		add_filter( 'render_block_' . self::BLOCK, array( $this, 'mark_for_crawlers' ), 10, 3 );
	}

	/**
	 * Register the Group attributes on the server. Filter callback for `register_block_type_args`.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args       Block type arguments.
	 * @param string               $block_type Block name.
	 * @return array<string, mixed>
	 */
	public function register_attributes( array $args, string $block_type ): array {
		if ( self::BLOCK !== $block_type ) {
			return $args;
		}

		$attributes = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array();

		$attributes[ self::ATTR_SEGMENTS ] = array(
			'type'    => 'array',
			'items'   => array( 'type' => 'string' ),
			'default' => array(),
		);
		$attributes[ self::ATTR_MODE ]     = array(
			'type'    => 'string',
			'enum'    => array( self::MODE_IN, self::MODE_NOT_IN ),
			'default' => self::MODE_IN,
		);

		$args['attributes'] = $attributes;

		return $args;
	}

	/**
	 * Short-circuit a Group the reader may not see. Filter callback for `pre_render_block`.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null  $pre_render   Pre-rendered content, or null.
	 * @param array<mixed> $parsed_block Parsed block.
	 * @return string|null `''` to withhold, otherwise the incoming value.
	 */
	public function withhold( ?string $pre_render, array $parsed_block ): ?string {
		$keys = self::segments_of( $parsed_block );
		if ( array() === $keys ) {
			return $pre_render;
		}

		if ( $this->should_withhold( $keys, self::mode_of( $parsed_block ) ) ) {
			return '';
		}

		return $pre_render;
	}

	/**
	 * Number the paywalled Groups that render, in document order. Filter callback for `render_block_data`.
	 *
	 * Runs after `pre_render_block`, so withheld Groups are never numbered.
	 * Author-hidden Groups are skipped too, because core drops them after
	 * rendering. The index is read back in `mark_for_crawlers()`; it is never
	 * saved. Numbering is per post so a post rendered elsewhere on the page
	 * does not shift the queried post's indexes.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $parsed_block Parsed block.
	 * @return array<mixed>
	 */
	public function stamp_index( array $parsed_block ): array {
		if ( ! self::is_paywalled( $parsed_block ) ) {
			return $parsed_block;
		}

		$post_id = (int) get_the_ID();
		if ( ! isset( $this->rendered_index[ $post_id ] ) ) {
			$this->rendered_index[ $post_id ] = 0;
		}
		++$this->rendered_index[ $post_id ];
		$parsed_block['attrs'][ self::ATTR_INDEX ] = $this->rendered_index[ $post_id ];

		return $parsed_block;
	}

	/**
	 * Add the paywall marker class on the crawler variant. Filter callback for `render_block_core/group`.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $content  Rendered content.
	 * @param array<mixed> $block    Parsed block.
	 * @param WP_Block     $instance Block instance.
	 */
	public function mark_for_crawlers( string $content, array $block, WP_Block $instance ): string {
		unset( $instance );

		if ( ! self::is_paywalled( $block ) || '' === $content || ! $this->is_crawler() ) {
			return $content;
		}

		$attrs     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$index     = isset( $attrs[ self::ATTR_INDEX ] ) ? (int) $attrs[ self::ATTR_INDEX ] : 0;
		$processor = new WP_HTML_Tag_Processor( $content );
		if ( ! $processor->next_tag() ) {
			return $content;
		}
		$processor->add_class( self::MARKER_CLASS );
		if ( $index > 0 ) {
			$processor->add_class( self::MARKER_CLASS . '-' . $index );
		}

		return $processor->get_updated_html();
	}

	/**
	 * The one place the gating rule lives.
	 *
	 * `in`: withhold unless the reader is in at least one listed segment.
	 * `not-in`: withhold if the reader is in any of them. Undeclared,
	 * inactive, and unknown keys read as not-in-segment.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $keys Segment keys.
	 * @param string   $mode `in` or `not-in`.
	 */
	public function should_withhold( array $keys, string $mode ): bool {
		$in_any = false;
		foreach ( $keys as $key ) {
			if ( $this->reads_true( $key ) ) {
				$in_any = true;
				break;
			}
		}

		return self::MODE_NOT_IN === $mode ? $in_any : ! $in_any;
	}

	/**
	 * Whether a declared segment reads true, without the undeclared-read notice.
	 *
	 * The plugin's own reads run where nothing is declared by design: gated
	 * teasers on archives, posts saved under WP-CLI or through REST, and
	 * crawler checks on every response. Withholding is the intended result
	 * there, so the `_doing_it_wrong()` notice, which tells site code to
	 * declare what it reads, must not fire.
	 *
	 * @param string $key Segment key.
	 */
	private function reads_true( string $key ): bool {
		return $this->declared->allows_read( $key ) && $this->membership->is_in( $key );
	}

	/**
	 * Whether this response is a crawler variant: a declared crawler segment reads true.
	 *
	 * @since 0.1.0
	 */
	public function is_crawler(): bool {
		if ( null !== $this->is_crawler ) {
			return $this->is_crawler;
		}

		$this->is_crawler = false;
		foreach ( $this->segments->crawler_keys() as $key ) {
			if ( $this->reads_true( $key ) ) {
				$this->is_crawler = true;
				break;
			}
		}

		return $this->is_crawler;
	}

	/**
	 * Count of paywalled Groups rendered so far for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function rendered_count( int $post_id ): int {
		return $this->rendered_index[ $post_id ] ?? 0;
	}

	/**
	 * Whether a Group is paywalled: gated in `in` mode and not author-hidden.
	 *
	 * @param array<mixed> $block Parsed block.
	 */
	private static function is_paywalled( array $block ): bool {
		return array() !== self::segments_of( $block )
			&& self::MODE_IN === self::mode_of( $block )
			&& ! self::is_author_hidden( $block );
	}

	/**
	 * Segment keys assigned to a Group block, validated for shape.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $block Parsed block.
	 * @return string[] Empty for other blocks and unrestricted Groups.
	 */
	public static function segments_of( array $block ): array {
		if ( self::BLOCK !== ( $block['blockName'] ?? '' ) ) {
			return array();
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$raw   = $attrs[ self::ATTR_SEGMENTS ] ?? array();
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$keys = array();
		foreach ( $raw as $key ) {
			if ( is_string( $key ) && '' !== $key ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Whether the author hid the block through core's visibility support.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $block Parsed block.
	 */
	public static function is_author_hidden( array $block ): bool {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$meta  = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();

		return false === ( $meta['blockVisibility'] ?? null );
	}

	/**
	 * Show-to mode of a Group block.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $block Parsed block.
	 * @return string `in` or `not-in`.
	 */
	public static function mode_of( array $block ): string {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		return self::MODE_NOT_IN === ( $attrs[ self::ATTR_MODE ] ?? '' ) ? self::MODE_NOT_IN : self::MODE_IN;
	}
}
