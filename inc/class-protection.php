<?php
/**
 * Withholding and automatic excerpt protection.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates;

/** Enforcement precedes dynamic child rendering. */
final class Protection {
	/** Register public delivery filters. */
	public static function register(): void {
		add_filter( 'pre_render_block', array( self::class, 'block' ), PHP_INT_MAX, 2 );
		// Core's automatic generator can fall back to raw block source.
		remove_filter( 'get_the_excerpt', 'wp_trim_excerpt' );
		add_filter( 'get_the_excerpt', array( self::class, 'excerpt' ), 10, 2 );
	}

	/**
	 * Short circuit denied Groups without touching their children.
	 *
	 * @param string|null         $render Previous short circuit.
	 * @param array<string,mixed> $block Parsed block.
	 * @return string|null
	 */
	public static function block( ?string $render, array $block ): ?string {
		if ( 'core/group' !== ( $block['blockName'] ?? '' ) || ! isset( $block['attrs']['contentGateSegmentId'] ) ) {
			return $render;
		}
		$id = $block['attrs']['contentGateSegmentId'];
		if ( ! is_int( $id ) || $id < 1 ) {
			Membership::instance()->problem( 'invalid_assignment' );
			return '';
		}
		return content_gates_is_reader_in_segment( $id ) ? $render : '';
	}

	/**
	 * Generate automatic excerpts from the protected render, not raw blocks.
	 * Manual excerpts remain explicitly public editorial data.
	 *
	 * @param string   $excerpt Existing excerpt.
	 * @param \WP_Post $post Source post.
	 */
	public static function excerpt( string $excerpt, \WP_Post $post ): string {
		if ( '' !== trim( $excerpt ) ) {
			return $excerpt;
		}
		$content = strip_shortcodes( do_blocks( $post->post_content ) );
		$content = wp_strip_all_tags( $content );
		return wp_trim_words( $content, 55 );
	}
}
