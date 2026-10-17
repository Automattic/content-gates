<?php
/**
 * Server-side Group block enforcement.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

use WP_Post;

/**
 * Registers the segment attribute and withholds denied Group blocks.
 */
final class Block_Visibility {

	public const ATTRIBUTE = 'contentGateSegmentId';

	/**
	 * Register WordPress hooks.
	 */
	public static function register_hooks(): void {
		add_filter( 'register_block_type_args', array( self::class, 'register_attribute' ), 10, 2 );
		add_filter( 'pre_render_block', array( self::class, 'filter_block' ), 10, 2 );
		add_filter( 'get_the_excerpt', array( self::class, 'filter_excerpt' ), 9, 2 );
	}

	/**
	 * Register the persisted segment ID on core/group.
	 *
	 * @param array<string,mixed> $args       Block registration arguments.
	 * @param string              $block_type Block type name.
	 * @return array<string,mixed> Filtered registration arguments.
	 */
	public static function register_attribute( array $args, string $block_type ): array {
		if ( 'core/group' !== $block_type ) {
			return $args;
		}

		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			$args['attributes'] = array();
		}

		$args['attributes'][ self::ATTRIBUTE ] = array( 'type' => 'integer' );

		return $args;
	}

	/**
	 * Short-circuit denied Group blocks before nested blocks render.
	 *
	 * @param string|null         $pre_render   Existing pre-render value.
	 * @param array<string,mixed> $parsed_block Parsed block data.
	 * @return string|null Empty when denied, otherwise the incoming value.
	 */
	public static function filter_block( ?string $pre_render, array $parsed_block ): ?string {
		if ( 'core/group' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $pre_render;
		}

		$attributes = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
		if ( ! array_key_exists( self::ATTRIBUTE, $attributes ) ) {
			return $pre_render;
		}

		$segment_id = absint( $attributes[ self::ATTRIBUTE ] );
		if ( self::is_authoring_request() ) {
			return $pre_render;
		}

		$allowed = 0 < $segment_id && is_reader_in_segment( $segment_id );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! headers_sent() ) {
			header( 'X-Content-Gates-Decision: ' . $segment_id . '=' . ( $allowed ? 'allow' : 'deny' ), false );
		}

		return $allowed ? $pre_render : '';
	}

	/**
	 * Generate excerpts from the same server-filtered blocks as the full page.
	 *
	 * @param string       $excerpt Generated excerpt.
	 * @param WP_Post|null $post    Source post.
	 * @return string Filtered excerpt.
	 */
	public static function filter_excerpt( string $excerpt, ?WP_Post $post ): string {
		if ( null === $post || false === strpos( $post->post_content, self::ATTRIBUTE ) ) {
			return $excerpt;
		}

		$rendered = do_blocks( $post->post_content );
		$text     = wp_strip_all_tags( strip_shortcodes( $rendered ) );
		$length   = (int) apply_filters( 'excerpt_length', 55 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$more     = (string) apply_filters( 'excerpt_more', ' [&hellip;]' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		return wp_trim_words( $text, $length, $more );
	}

	/**
	 * Whether this is an authenticated authoring screen.
	 */
	private static function is_authoring_request(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		$post_id = get_the_ID();

		return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
	}
}
