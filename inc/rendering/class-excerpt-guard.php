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
 * The guard owns the whole outcome for a gated post, with two callbacks on
 * `get_the_excerpt` around core's `wp_trim_excerpt` at 10:
 *
 * - Priority 9: when the incoming text is empty and the content mentions
 *   the attribute, trim a clone of the post whose content has been through
 *   `Block_Stripper`, remember the result for this post, and return it.
 * - Priority 11: for a post remembered at 9, return the remembered value
 *   and forget it. Whatever core produced at 10 is discarded.
 *
 * The second callback matters when the stripped content trims to `''`
 * (the post is nothing but gated Groups). Core at 10 treats `''` as "no
 * excerpt yet" and regenerates one from the original post, which brings
 * the gated text back. Core's regeneration still runs in that case and its
 * output passes through the in-process `wp_trim_excerpt` filter before
 * priority 11 drops it; it never reaches the response.
 *
 * Remembered values are keyed by post ID and stacked, so a nested
 * `get_the_excerpt()` call for the same or another post during the
 * computation cannot confuse the pairing of 9 and 11.
 *
 * @since 0.1.0
 */
final class Excerpt_Guard {

	/**
	 * Excerpts computed at priority 9 and not yet returned at 11.
	 *
	 * Post ID => stack of computed excerpts, innermost call last.
	 *
	 * @var array<int, list<string>>
	 */
	private array $pending = array();

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
		add_filter( 'get_the_excerpt', array( $this, 'restore_excerpt' ), 11, 2 );
	}

	/**
	 * Hook callback for `get_the_excerpt` at priority 9.
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

		$excerpt = wp_trim_excerpt( '', $clone );

		$this->pending[ $post->ID ][] = $excerpt;

		return $excerpt;
	}

	/**
	 * Hook callback for `get_the_excerpt` at priority 11.
	 *
	 * Returns the value computed at priority 9 for this post, if any,
	 * regardless of what core did at 10.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $text The excerpt as left by earlier callbacks.
	 * @param mixed $post The post.
	 * @return mixed The guard's value for a handled post, otherwise `$text`.
	 */
	public function restore_excerpt( mixed $text, mixed $post = null ): mixed {
		if ( ! $post instanceof WP_Post || empty( $this->pending[ $post->ID ] ) ) {
			return $text;
		}

		$excerpt = array_pop( $this->pending[ $post->ID ] );

		if ( array() === $this->pending[ $post->ID ] ) {
			unset( $this->pending[ $post->ID ] );
		}

		return $excerpt;
	}
}
