<?php
/**
 * Segment persistence and lifecycle rules.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Segments;

use WP_Error;
use WP_Post;

/**
 * Stores segment records and prevents their deletion.
 */
final class Segment_Repository {

	/**
	 * Segment custom post type.
	 */
	public const POST_TYPE = 'content_gate_seg';

	/**
	 * Active segment post status.
	 */
	public const STATUS_ACTIVE = 'publish';

	/**
	 * Inactive segment post status.
	 */
	public const STATUS_INACTIVE = 'cg_inactive';

	/**
	 * Capability required to administer segments.
	 */
	public const ADMIN_CAPABILITY = 'manage_options';

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_types' ), 5 );
		add_filter( 'pre_trash_post', array( $this, 'prevent_trash' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'prevent_delete' ), 10, 3 );
	}

	/**
	 * Register the segment post status and post type.
	 */
	public function register_types(): void {
		register_post_status(
			self::STATUS_INACTIVE,
			array(
				'label'                     => _x( 'Inactive', 'segment status', 'content-gates' ),
				'public'                    => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: Number of inactive segments. */
				'label_count'               => _n_noop( 'Inactive <span class="count">(%s)</span>', 'Inactive <span class="count">(%s)</span>', 'content-gates' ),
			)
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Content Gate Segments', 'content-gates' ),
					'singular_name' => __( 'Content Gate Segment', 'content-gates' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'              => self::ADMIN_CAPABILITY,
					'read_post'              => self::ADMIN_CAPABILITY,
					'delete_post'            => 'do_not_allow',
					'edit_posts'             => self::ADMIN_CAPABILITY,
					'edit_others_posts'      => self::ADMIN_CAPABILITY,
					'delete_posts'           => 'do_not_allow',
					'publish_posts'          => self::ADMIN_CAPABILITY,
					'read_private_posts'     => self::ADMIN_CAPABILITY,
					'delete_private_posts'   => 'do_not_allow',
					'delete_published_posts' => 'do_not_allow',
					'delete_others_posts'    => 'do_not_allow',
					'edit_private_posts'     => self::ADMIN_CAPABILITY,
					'edit_published_posts'   => self::ADMIN_CAPABILITY,
					'create_posts'           => self::ADMIN_CAPABILITY,
				),
			)
		);
	}

	/**
	 * Find a segment by its stable post ID.
	 *
	 * @param int $segment_id Segment post ID.
	 * @return WP_Post|null Segment record, or null when the ID is not a segment.
	 */
	public function find( int $segment_id ): ?WP_Post {
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
	 * Get all segments, ordered by display name.
	 *
	 * @return list<WP_Post> Segment records.
	 */
	public function get_all(): array {
		return $this->get_by_status( array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ) );
	}

	/**
	 * Get active segments, ordered by display name.
	 *
	 * @return list<WP_Post> Active segment records.
	 */
	public function get_active(): array {
		return $this->get_by_status( array( self::STATUS_ACTIVE ) );
	}

	/**
	 * Check whether a segment exists and is active.
	 *
	 * @param int $segment_id Segment post ID.
	 */
	public function is_active( int $segment_id ): bool {
		$segment = $this->find( $segment_id );

		return null !== $segment && self::STATUS_ACTIVE === $segment->post_status;
	}

	/**
	 * Build the stable Vary_Cache group key for a segment.
	 *
	 * @param int $segment_id Segment post ID.
	 */
	public function group_key( int $segment_id ): string {
		return sprintf( 'cg_%d_%d', get_current_blog_id(), $segment_id );
	}

	/**
	 * Create an active segment.
	 *
	 * @param string $name Segment display name.
	 * @return int|WP_Error Segment post ID on success.
	 */
	public function create( string $name ): int|WP_Error {
		$permission = $this->check_permission();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'content_gates_empty_segment_name', __( 'Enter a segment name.', 'content-gates' ) );
		}

		return wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => self::STATUS_ACTIVE,
				'post_title'  => $name,
			),
			true
		);
	}

	/**
	 * Rename a segment without changing its ID or group key.
	 *
	 * @param int    $segment_id Segment post ID.
	 * @param string $name       New display name.
	 * @return bool|WP_Error True on success.
	 */
	public function rename( int $segment_id, string $name ): bool|WP_Error {
		$permission = $this->check_permission();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		if ( null === $this->find( $segment_id ) ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'The segment does not exist.', 'content-gates' ) );
		}

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'content_gates_empty_segment_name', __( 'Enter a segment name.', 'content-gates' ) );
		}

		$result = wp_update_post(
			array(
				'ID'         => $segment_id,
				'post_title' => $name,
			),
			true
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Activate or deactivate a segment without changing its ID.
	 *
	 * @param int  $segment_id Segment post ID.
	 * @param bool $active     Whether the segment must be active.
	 * @return bool|WP_Error True on success.
	 */
	public function set_active( int $segment_id, bool $active ): bool|WP_Error {
		$permission = $this->check_permission();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		if ( null === $this->find( $segment_id ) ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'The segment does not exist.', 'content-gates' ) );
		}

		$result = wp_update_post(
			array(
				'ID'          => $segment_id,
				'post_status' => $active ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
			),
			true
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Prevent a segment from moving to the trash.
	 *
	 * @param bool|null $trash Whether to continue trashing the post.
	 * @param WP_Post   $post  Post requested for trashing.
	 * @return bool|null False for segments; the incoming value otherwise.
	 */
	public function prevent_trash( ?bool $trash, WP_Post $post ): ?bool {
		if ( self::POST_TYPE === $post->post_type ) {
			return false;
		}

		return $trash;
	}

	/**
	 * Prevent permanent deletion of a segment.
	 *
	 * @param WP_Post|false|null $delete       Whether to continue deleting the post.
	 * @param WP_Post            $post         Post requested for deletion.
	 * @param bool               $force_delete Whether deletion bypasses the trash.
	 * @return WP_Post|false|null False for segments; the incoming value otherwise.
	 */
	public function prevent_delete( WP_Post|false|null $delete, WP_Post $post, bool $force_delete ): WP_Post|false|null {
		unset( $force_delete );

		if ( self::POST_TYPE === $post->post_type ) {
			return false;
		}

		return $delete;
	}

	/**
	 * Verify that the current user can administer segments.
	 *
	 * @return bool|WP_Error True when permitted.
	 */
	private function check_permission(): bool|WP_Error {
		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			return new WP_Error( 'content_gates_forbidden', __( 'You cannot manage content gate segments.', 'content-gates' ) );
		}

		return true;
	}

	/**
	 * Get all segments with one of the requested statuses.
	 *
	 * Uses bounded queries so large registries do not create an unbounded VIP
	 * database request. The loop does not impose a segment limit.
	 *
	 * @param array<int, string> $statuses Post statuses to include.
	 * @return list<WP_Post> Segment records.
	 */
	private function get_by_status( array $statuses ): array {
		$segments   = array();
		$offset     = 0;
		$page_size  = 100;
		$page_count = 0;

		do {
			$page = get_posts(
				array(
					'post_type'              => self::POST_TYPE,
					'post_status'            => $statuses,
					'numberposts'            => $page_size,
					'offset'                 => $offset,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'suppress_filters'       => false,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$segments   = array_merge( $segments, $page );
			$offset    += $page_size;
			$page_count = count( $page );
		} while ( $page_count === $page_size );

		return array_values( $segments );
	}
}
