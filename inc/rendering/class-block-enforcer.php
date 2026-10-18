<?php
/**
 * Server-side block access enforcement.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

use ContentGates\Editor\Group_Block_Editor;
use ContentGates\Segments\Segment_Repository;
use WP_Post;

/**
 * Removes restricted Group blocks before their child blocks render.
 */
final class Block_Enforcer {

	/**
	 * Segment storage.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Membership callback.
	 *
	 * @var callable(int): bool
	 */
	private $membership_check;

	/**
	 * Create the block enforcer.
	 *
	 * @param Segment_Repository      $segments         Segment storage.
	 * @param callable(int):bool|null $membership_check Optional membership callback for tests.
	 */
	public function __construct( Segment_Repository $segments, ?callable $membership_check = null ) {
		$this->segments         = $segments;
		$this->membership_check = $membership_check ?? static function ( int $segment_id ): bool {
			$callback = 'ContentGates\\content_gates_is_reader_in_segment';

			return function_exists( $callback ) && $callback( $segment_id );
		};
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'pre_render_block', array( $this, 'enforce_group_access' ), 10, 2 );
		add_filter( 'excerpt_allowed_wrapper_blocks', array( $this, 'exclude_groups_from_core_excerpts' ) );
		add_filter( 'get_the_excerpt', array( $this, 'protect_automatic_excerpt' ), 99, 2 );
	}

	/**
	 * Withhold a Group before WordPress renders any nested blocks.
	 *
	 * @param string|null         $pre_render   Existing short-circuit value.
	 * @param array<string,mixed> $parsed_block Parsed block data.
	 * @return string|null
	 */
	public function enforce_group_access( ?string $pre_render, array $parsed_block ): ?string {
		if ( 'core/group' !== ( $parsed_block['blockName'] ?? null ) ) {
			return $pre_render;
		}

		$attributes = $parsed_block['attrs'] ?? array();
		if ( ! is_array( $attributes ) || ! array_key_exists( Group_Block_Editor::ATTRIBUTE, $attributes ) ) {
			return $pre_render;
		}

		$segment_id = $attributes[ Group_Block_Editor::ATTRIBUTE ];
		if ( ! is_int( $segment_id ) || 0 >= $segment_id ) {
			return '';
		}

		$segment = $this->segments->find( $segment_id );
		if ( null === $segment ) {
			return '';
		}

		$membership_check = $this->membership_check;
		$is_member        = $membership_check( $segment_id );
		if ( ! $this->segments->is_active( $segment_id ) || ! $is_member ) {
			return '';
		}

		return $pre_render;
	}

	/**
	 * Stop core from extracting text directly from Group inner blocks.
	 *
	 * The later get_the_excerpt filter rebuilds automatic excerpts through the
	 * block renderer, where access rules can inspect the Group wrapper first.
	 *
	 * @param array $allowed_blocks Wrapper block types rendered in excerpts.
	 * @return array
	 *
	 * @phpstan-param list<string> $allowed_blocks
	 * @phpstan-return list<string>
	 */
	public function exclude_groups_from_core_excerpts( array $allowed_blocks ): array {
		return array_values( array_diff( $allowed_blocks, array( 'core/group' ) ) );
	}

	/**
	 * Build an automatic excerpt from access-controlled rendered blocks.
	 *
	 * A manual excerpt is author-controlled public text and remains unchanged.
	 *
	 * @param string  $excerpt Excerpt produced by earlier filters.
	 * @param WP_Post $post    Source post.
	 */
	public function protect_automatic_excerpt( string $excerpt, WP_Post $post ): string {
		if ( '' !== trim( $post->post_excerpt ) ) {
			return $excerpt;
		}

		$content = get_the_content( '', false, $post );
		$content = strip_shortcodes( $content );
		$content = do_blocks( $content );
		$content = excerpt_remove_footnotes( $content );

		/* translators: Maximum number of words used in a post excerpt. */
		$excerpt_length = (int) _x( '55', 'excerpt_length', 'content-gates' );
		// These are the WordPress core excerpt hooks.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$excerpt_length = (int) apply_filters( 'excerpt_length', $excerpt_length );
		$excerpt_more   = (string) apply_filters( 'excerpt_more', ' [&hellip;]' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return wp_trim_words( $content, $excerpt_length, $excerpt_more );
	}
}
