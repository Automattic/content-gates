<?php
/**
 * Segment persistence.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Stores segments as stable custom-post records.
 */
final class Segment_Repository {

	public const POST_TYPE = 'content_gate_segment';

	public const STATUS_ACTIVE = 'publish';

	public const STATUS_INACTIVE = 'draft';

	/**
	 * Register WordPress hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_post_type' ), 0 );
		add_filter( 'pre_delete_post', array( self::class, 'prevent_delete' ), 10, 2 );
		add_filter( 'pre_trash_post', array( self::class, 'prevent_trash' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( self::class, 'prevent_trash_status' ) );
	}

	/**
	 * Register the private segment post type.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Content Gate Segments', 'content-gates' ),
					'singular_name' => __( 'Content Gate Segment', 'content-gates' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
				'capabilities'        => array(
					'edit_post'              => 'manage_options',
					'read_post'              => 'manage_options',
					'delete_post'            => 'do_not_allow',
					'edit_posts'             => 'manage_options',
					'edit_others_posts'      => 'manage_options',
					'publish_posts'          => 'manage_options',
					'read_private_posts'     => 'manage_options',
					'delete_posts'           => 'do_not_allow',
					'delete_private_posts'   => 'do_not_allow',
					'delete_published_posts' => 'do_not_allow',
					'delete_others_posts'    => 'do_not_allow',
					'create_posts'           => 'manage_options',
				),
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * Return every active and inactive segment.
	 *
	 * @return WP_Post[] Segment posts.
	 */
	public static function get_all(): array {
		$segments = array();
		$page     = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'              => self::POST_TYPE,
					'post_status'            => array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ),
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'orderby'                => array(
						'title' => 'ASC',
						'ID'    => 'ASC',
					),
					'no_found_rows'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( (array) $query->posts as $post ) {
				if ( $post instanceof WP_Post ) {
					$segments[] = $post;
				}
			}
			++$page;
		} while ( $page <= (int) $query->max_num_pages );

		return $segments;
	}

	/**
	 * Find a segment by stable post ID.
	 *
	 * @param int $segment_id Segment post ID.
	 * @return WP_Post|null Segment record or null.
	 */
	public static function get( int $segment_id ): ?WP_Post {
		$post = get_post( $segment_id );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		if ( ! in_array( $post->post_status, array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ), true ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Whether a segment is active.
	 *
	 * @param int $segment_id Segment post ID.
	 */
	public static function is_active( int $segment_id ): bool {
		$post = self::get( $segment_id );

		return null !== $post && self::STATUS_ACTIVE === $post->post_status;
	}

	/**
	 * Create an active segment.
	 *
	 * @param string $name Segment display name.
	 * @return int|WP_Error Segment post ID or error.
	 */
	public static function create( string $name ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'content_gates_empty_segment_name', __( 'Segment name is required.', 'content-gates' ) );
		}

		return wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $name,
				'post_status' => self::STATUS_ACTIVE,
			),
			true
		);
	}

	/**
	 * Update a segment without changing its stable ID.
	 *
	 * @param int    $segment_id Segment post ID.
	 * @param string $name       Segment display name.
	 * @param bool   $active     Whether the segment is active.
	 * @return int|WP_Error Segment post ID or error.
	 */
	public static function update( int $segment_id, string $name, bool $active ) {
		if ( null === self::get( $segment_id ) ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Segment does not exist.', 'content-gates' ) );
		}

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'content_gates_empty_segment_name', __( 'Segment name is required.', 'content-gates' ) );
		}

		return wp_update_post(
			array(
				'ID'          => $segment_id,
				'post_title'  => $name,
				'post_status' => $active ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
			),
			true
		);
	}

	/**
	 * Stop permanent deletion of segment records.
	 *
	 * @param mixed   $delete Short-circuit value.
	 * @param WP_Post $post   Post being deleted.
	 * @return mixed False for a segment, otherwise the original value.
	 */
	public static function prevent_delete( $delete, WP_Post $post ) {
		return self::POST_TYPE === $post->post_type ? false : $delete;
	}

	/**
	 * Stop trashing segment records.
	 *
	 * @param mixed   $trash Short-circuit value.
	 * @param WP_Post $post  Post being trashed.
	 * @return mixed False for a segment, otherwise the original value.
	 */
	public static function prevent_trash( $trash, WP_Post $post ) {
		return self::POST_TYPE === $post->post_type ? false : $trash;
	}

	/**
	 * Convert direct attempts to set a segment to trash into deactivation.
	 *
	 * @param array<string,mixed> $data Sanitized post data.
	 * @return array<string,mixed> Filtered post data.
	 */
	public static function prevent_trash_status( array $data ): array {
		if ( self::POST_TYPE === ( $data['post_type'] ?? '' ) && 'trash' === ( $data['post_status'] ?? '' ) ) {
			$data['post_status'] = self::STATUS_INACTIVE;
		}

		return $data;
	}
}
