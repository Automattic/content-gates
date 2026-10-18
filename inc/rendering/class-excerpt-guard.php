<?php
/**
 * Excerpt guard.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

use WP_Post;

/**
 * Keeps gated Groups out of auto-generated excerpts.
 *
 * When a post has no manual excerpt, core's `wp_trim_excerpt()` builds one
 * from the content through `excerpt_remove_blocks()`. That function treats
 * `core/group` as a wrapper: it renders the Group's inner blocks one by
 * one with `render_block()` and drops the wrapper, so `pre_render_block`
 * never sees the Group or its segment attribute. Archives, search, feeds
 * in summary mode, REST `excerpt.rendered`, and the embed template all
 * read `get_the_excerpt()`, so without this guard they leak gated text.
 *
 * This filter runs on `get_the_excerpt` at priority 9, before core's
 * `wp_trim_excerpt` at 10. When the incoming text is empty and the content
 * mentions the attribute, it trims a clone of the post whose content has
 * been through `Block_Stripper`. Core's callback then receives non-empty
 * text and returns it unchanged, so nothing is trimmed twice.
 *
 * @since 0.1.0
 */
final class Excerpt_Guard {

	/**
	 * Constructor.
	 *
	 * @param Block_Stripper $stripper Removes withheld Groups.
	 */
	public function __construct( private Block_Stripper $stripper ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 9, 2 );
	}

	/**
	 * Hook callback for `get_the_excerpt`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $text The manual excerpt, '' when there is none.
	 * @param mixed $post The post.
	 * @return mixed The manual excerpt, or a trimmed excerpt of the stripped content.
	 */
	public function filter_excerpt( mixed $text, mixed $post = null ): mixed {
		if ( ! is_string( $text ) || '' !== trim( $text ) ) {
			return $text;
		}

		if ( ! $post instanceof WP_Post || ! str_contains( $post->post_content, Block_Enforcer::ATTRIBUTE ) ) {
			return $text;
		}

		$clone               = clone $post;
		$clone->post_content = $this->stripper->strip( $post->post_content );

		return wp_trim_excerpt( '', $clone );
	}
}
