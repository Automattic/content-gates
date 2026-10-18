<?php
/**
 * Block enforcer.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

use ContentGates\Membership\Membership;
use ContentGates\Segments\Segment_Registry;
use ContentGates\Segments\Segment_Source;
use WP_Block;

/**
 * Withholds gated Group blocks at render time.
 *
 * A Group block (`core/group`) carries an optional integer attribute
 * `contentGatesSegmentId`: the post ID of a CPT segment. Absent or `null`
 * means "No segment". For every other value the Group is a gate and this
 * class decides, per request, whether the Group renders at all:
 *
 * - `allow`: the reader is in the segment (or an editor is previewing).
 *   Normal rendering continues.
 * - `withhold`: `pre_render_block` returns `''`, so core never renders the
 *   Group, its wrapper, its inner blocks, or their dynamic callbacks. Also
 *   used for every invalid reference (fail closed) and for inactive
 *   segments.
 * - `crawler`: the reader is not in the segment but is in the reserved
 *   `crawler` segment. The Group renders in full and `render_block` wraps
 *   it in `<div class="content-gates-paywall">`, the selector the JSON-LD
 *   paywall markup points at.
 *
 * Every decision for a resolvable segment goes through
 * `Membership::is_in()`, so the read is recorded and the response varies
 * on that segment. The `crawler` segment is consulted only after a denial,
 * so pages without a denied gate do not vary on it. Rendering never writes
 * to the saved block attributes.
 *
 * @since 0.1.0
 */
final class Block_Enforcer {

	/**
	 * Block attribute holding the segment post ID.
	 */
	public const ATTRIBUTE = 'contentGatesSegmentId';

	/**
	 * The only block type that carries the attribute.
	 */
	public const BLOCK_NAME = 'core/group';

	/**
	 * Class name wrapped around a gated Group on the crawler variant.
	 */
	public const PAYWALL_CLASS = 'content-gates-paywall';

	public const ALLOW    = 'allow';
	public const WITHHOLD = 'withhold';
	public const CRAWLER  = 'crawler';

	/**
	 * Internal result: a valid segment the reader is not in.
	 */
	private const DENIED = 'denied';

	/**
	 * Constructor.
	 *
	 * @param Membership     $membership Membership layer (records reads).
	 * @param Segment_Source $segments   Segment lookup.
	 */
	public function __construct(
		private Membership $membership,
		private Segment_Source $segments
	) {}

	/**
	 * Add hooks.
	 *
	 * `register_block_type_args` runs when core registers `core/group` on
	 * `init`, so this must be called before then (plugin load is fine).
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'register_block_type_args', array( $this, 'filter_block_type_args' ), 10, 2 );
		add_filter( 'pre_render_block', array( $this, 'filter_pre_render_block' ), 10, 3 );
		add_filter( 'render_block', array( $this, 'filter_render_block' ), 10, 2 );
	}

	/**
	 * Register the attribute on `core/group` server-side.
	 *
	 * Without this, core strips unknown attributes before `render_block_data`
	 * consumers see them, and the REST block schema omits it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args            Block type arguments.
	 * @param string               $block_type_name Block type name.
	 * @return array<string, mixed>
	 */
	public function filter_block_type_args( array $args, string $block_type_name ): array {
		if ( self::BLOCK_NAME !== $block_type_name ) {
			return $args;
		}

		$attributes = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array();

		$attributes[ self::ATTRIBUTE ] = array( 'type' => 'integer' );
		$args['attributes']            = $attributes;

		return $args;
	}

	/**
	 * Hook callback for `pre_render_block`: short-circuit withheld Groups.
	 *
	 * Returning `''` here is the enforcement point. Core skips the Group and
	 * everything inside it, so no inner block, dynamic callback, or
	 * `render_block` filter ever sees the gated content.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $pre_render   Short-circuit value from earlier filters, normally null.
	 * @param mixed $block        Parsed block array.
	 * @param mixed $parent_block Parent `WP_Block` for nested blocks, null at the top level.
	 * @return mixed `''` to withhold, otherwise the incoming value.
	 */
	public function filter_pre_render_block( mixed $pre_render, mixed $block, mixed $parent_block = null ): mixed {
		if ( ! is_array( $block ) || ! self::is_gated( $block ) ) {
			return $pre_render;
		}

		$decision = $this->decision( $block, self::current_post_id( $parent_block ) );

		if ( self::WITHHOLD === $decision ) {
			return '';
		}

		// Another filter already pre-rendered the Group, so `render_block`
		// will not fire for it; apply the crawler wrapper here instead.
		if ( self::CRAWLER === $decision && is_string( $pre_render ) && '' !== $pre_render ) {
			return self::wrap( $pre_render );
		}

		return $pre_render;
	}

	/**
	 * Hook callback for `render_block`: wrap gated Groups on the crawler
	 * variant.
	 *
	 * The decision is recomputed rather than remembered from
	 * `pre_render_block`: membership is fixed for the request, so the answer
	 * is the same, the extra read is idempotent, and no per-render state has
	 * to track nesting. It also closes the path where a `WP_Block` is
	 * rendered directly without `pre_render_block`: a withheld Group that
	 * reaches this filter still leaves as `''`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $content Rendered block HTML.
	 * @param mixed $block   Parsed block array.
	 * @return mixed
	 */
	public function filter_render_block( mixed $content, mixed $block ): mixed {
		if ( ! is_string( $content ) || '' === $content || ! is_array( $block ) || ! self::is_gated( $block ) ) {
			return $content;
		}

		$decision = $this->decision( $block );

		if ( self::WITHHOLD === $decision ) {
			return '';
		}

		if ( self::CRAWLER === $decision ) {
			return self::wrap( $content );
		}

		return $content;
	}

	/**
	 * Decide what happens to a parsed block. The one place the rule lives;
	 * `Block_Stripper` uses it for excerpts.
	 *
	 * The crawler segment is consulted only when a valid segment denied
	 * the reader. An invalid reference withholds from crawlers too, and
	 * records no read.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $block   Parsed block array.
	 * @param int|null             $post_id Post being rendered, for the preview exception. Default: the current post.
	 * @return string One of ALLOW, WITHHOLD, CRAWLER.
	 */
	public function decision( array $block, ?int $post_id = null ): string {
		if ( ! self::is_gated( $block ) ) {
			return self::ALLOW;
		}

		$attrs  = is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$result = $this->evaluate( $attrs[ self::ATTRIBUTE ] ?? null, $post_id ?? self::current_post_id() );

		if ( self::DENIED !== $result ) {
			return $result;
		}

		if ( $this->membership->is_in( Segment_Registry::CRAWLER_KEY ) ) {
			return self::CRAWLER;
		}

		return self::WITHHOLD;
	}

	/**
	 * Whether a Group assigned to `$segment_id` is withheld from this reader.
	 *
	 * Fails closed: anything that is not a positive integer naming an
	 * existing CPT segment withholds, with `_doing_it_wrong()` under
	 * WP_DEBUG. Inactive segments withhold (the read is still recorded).
	 * The only bypass is an editor previewing a post they can edit.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed    $segment_id Attribute value as parsed from the block comment.
	 * @param int|null $post_id    Post being rendered, or null when unknown.
	 */
	public function should_withhold( mixed $segment_id, ?int $post_id ): bool {
		return self::ALLOW !== $this->evaluate( $segment_id, $post_id );
	}

	/**
	 * Evaluate a reference for this reader.
	 *
	 * @param mixed    $segment_id Attribute value.
	 * @param int|null $post_id    Post being rendered, or null when unknown.
	 * @return string ALLOW, WITHHOLD (invalid reference), or DENIED (valid, reader not in it).
	 */
	private function evaluate( mixed $segment_id, ?int $post_id ): string {
		if ( ! is_int( $segment_id ) || $segment_id <= 0 ) {
			$this->incorrect_usage( $segment_id );

			return self::WITHHOLD;
		}

		$segment = $this->segments->find( $segment_id );
		if ( null === $segment || $segment->is_registered_in_code() ) {
			$this->incorrect_usage( $segment_id );

			return self::WITHHOLD;
		}

		if ( $this->membership->is_in( $segment_id ) ) {
			return self::ALLOW;
		}

		if ( null !== $post_id && $post_id > 0 && isset( $GLOBALS['wp_query'] ) && is_preview() && current_user_can( 'edit_post', $post_id ) ) {
			return self::ALLOW;
		}

		return self::DENIED;
	}

	/**
	 * Whether a parsed block is a Group with a segment assignment.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $block Parsed block array.
	 */
	public static function is_gated( array $block ): bool {
		if ( self::BLOCK_NAME !== ( $block['blockName'] ?? null ) ) {
			return false;
		}

		$attrs = $block['attrs'] ?? null;

		return is_array( $attrs ) && isset( $attrs[ self::ATTRIBUTE ] );
	}

	/**
	 * Wrap rendered Group HTML for the crawler variant.
	 *
	 * @param string $content Rendered HTML.
	 */
	private static function wrap( string $content ): string {
		return '<div class="' . self::PAYWALL_CLASS . '">' . $content . '</div>';
	}

	/**
	 * The post a block is rendered for, when known.
	 *
	 * @param mixed $parent_block Parent `WP_Block`, when nested.
	 */
	private static function current_post_id( mixed $parent_block = null ): ?int {
		$id = get_the_ID();
		if ( is_int( $id ) && $id > 0 ) {
			return $id;
		}

		if ( $parent_block instanceof WP_Block ) {
			$context_id = $parent_block->context['postId'] ?? null;
			if ( is_int( $context_id ) && $context_id > 0 ) {
				return $context_id;
			}
		}

		return null;
	}

	/**
	 * Flag an invalid assignment under WP_DEBUG.
	 *
	 * @param mixed $segment_id The offending attribute value.
	 */
	private function incorrect_usage( mixed $segment_id ): void {
		if ( ! defined( 'WP_DEBUG' ) || true !== constant( 'WP_DEBUG' ) ) {
			return;
		}

		$shown = is_scalar( $segment_id ) ? (string) $segment_id : gettype( $segment_id );

		_doing_it_wrong(
			esc_html( self::ATTRIBUTE ),
			sprintf(
				/* translators: %s: the attribute value */
				esc_html__( 'A Group block references segment "%s", which is not the ID of an existing segment. The Group is withheld from every reader until the assignment is fixed.', 'content-gates' ),
				esc_html( $shown )
			),
			'0.1.0'
		);
	}
}
