<?php
/**
 * Save-time computation of a post's declared set.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Rendering;

use Automattic\VIP\ContentGates\Membership\Declared_Set;
use Automattic\VIP\ContentGates\Segments\Segment_Registry;
use WP_Error;
use WP_Post;
use WP_REST_Request;

/**
 * Stores each post's segment assignments in post meta when the post is saved.
 *
 * The declared set for a page is read from this meta, so no block parse runs
 * per request. Every segment assigned to any Group in the content counts,
 * nested or not, in either show-to mode: an inner Group's segments must be
 * in the key even for readers the outer Group denies. Synced patterns
 * (`core/block`) contribute the referenced post's stored set.
 *
 * Keys are validated on REST saves: an unknown key is rejected with a 400
 * rather than silently cleared. Inactive keys are kept in the meta so a
 * reactivated segment's assignments come back; they are dropped when the
 * declared set is built.
 *
 * @since 0.1.0
 */
final class Content_Meta {

	/**
	 * Constructor.
	 *
	 * @param Segment_Registry $segments Segment lookup.
	 */
	public function __construct( private Segment_Registry $segments ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'store' ), 10, 2 );
		add_action( 'init', array( $this, 'register_rest_validation' ), 20 );
	}

	/**
	 * Hook REST validation for every post type exposed to the editor.
	 *
	 * @since 0.1.0
	 */
	public function register_rest_validation(): void {
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			if ( post_type_supports( $post_type->name, 'editor' ) ) {
				add_filter( 'rest_pre_insert_' . $post_type->name, array( $this, 'validate_rest' ), 10, 2 );
			}
		}
	}

	/**
	 * Refuse a REST save that assigns an unknown segment. Filter callback for `rest_pre_insert_{post_type}`.
	 *
	 * @since 0.1.0
	 *
	 * @param \stdClass|WP_Error $prepared Post object about to be inserted.
	 * @param WP_REST_Request    $request  Request.
	 * @return \stdClass|WP_Error
	 */
	public function validate_rest( $prepared, WP_REST_Request $request ) {
		unset( $request );

		if ( is_wp_error( $prepared ) || ! isset( $prepared->post_content ) || ! is_string( $prepared->post_content ) ) {
			return $prepared;
		}

		$unknown = array();
		foreach ( $this->collect_keys( parse_blocks( $prepared->post_content ) ) as $key ) {
			if ( null === $this->segments->resolve( $key ) ) {
				$unknown[] = $key;
			}
		}

		if ( array() !== $unknown ) {
			return new WP_Error(
				'content_gates_invalid_segment',
				sprintf(
					/* translators: %s: comma-separated segment keys */
					__( 'Unknown segment(s) assigned to a Group block: %s', 'content-gates' ),
					implode( ', ', $unknown )
				),
				array( 'status' => 400 )
			);
		}

		return $prepared;
	}

	/**
	 * Store the post's declared set. Action callback for `save_post`.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function store( int $post_id, WP_Post $post ): void {
		if ( 'revision' === $post->post_type || wp_is_post_autosave( $post_id ) || Segment_Registry::POST_TYPE === $post->post_type ) {
			return;
		}

		$keys = $this->compute( $post );

		if ( array() === $keys ) {
			delete_post_meta( $post_id, Declared_Set::META_KEY );
			return;
		}

		update_post_meta( $post_id, Declared_Set::META_KEY, $keys );
	}

	/**
	 * Compute the sorted, unique, resolvable segment keys assigned in a post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public function compute( WP_Post $post ): array {
		if ( ! has_blocks( $post->post_content ) ) {
			return array();
		}

		$keys = array();
		foreach ( $this->collect_keys( parse_blocks( $post->post_content ) ) as $key ) {
			$segment = $this->segments->resolve( $key );
			if ( null !== $segment ) {
				$keys[ $segment->key ] = true;
			}
		}

		$sorted = array_keys( $keys );
		sort( $sorted, SORT_STRING );

		return $sorted;
	}

	/**
	 * Walk parsed blocks and collect every assigned key, including through synced patterns.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @param int          $depth  Recursion guard for pattern references.
	 * @return string[] Unsorted, may repeat.
	 */
	private function collect_keys( array $blocks, int $depth = 0 ): array {
		$keys = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			foreach ( Block_Gate::segments_of( $block ) as $key ) {
				$keys[] = $key;
			}

			if ( 'core/block' === ( $block['blockName'] ?? '' ) && $depth < 3 ) {
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$ref   = isset( $attrs['ref'] ) ? (int) $attrs['ref'] : 0;
				if ( $ref > 0 ) {
					$pattern = get_post( $ref );
					if ( $pattern instanceof WP_Post && has_blocks( $pattern->post_content ) ) {
						$keys = array_merge( $keys, $this->collect_keys( parse_blocks( $pattern->post_content ), $depth + 1 ) );
					}
				}
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && array() !== $block['innerBlocks'] ) {
				$keys = array_merge( $keys, $this->collect_keys( $block['innerBlocks'], $depth ) );
			}
		}

		return $keys;
	}
}
