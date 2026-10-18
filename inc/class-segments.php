<?php
/**
 * Persistent segment definitions.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates;

/** Site-local definitions; labels never become cookie identities. */
final class Segments {
	/** Register storage and supported deletion vetoes. */
	public static function register(): void {
		register_post_type(
			'content_gate_segment',
			array(
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'map_meta_cap' => true,
				'capabilities' => array(
					'delete_post'  => 'do_not_allow',
					'delete_posts' => 'do_not_allow',
				),
			)
		);
		add_filter( 'pre_delete_post', array( self::class, 'prevent_delete' ), 10, 2 );
		add_filter( 'pre_trash_post', array( self::class, 'prevent_delete' ), 10, 2 );
	}

	/**
	 * Veto destructive operations on definitions.
	 *
	 * @param bool|null $delete Existing decision.
	 * @param \WP_Post  $post Target.
	 * @return bool|null
	 */
	public static function prevent_delete( ?bool $delete, \WP_Post $post ): ?bool {
		return 'content_gate_segment' === $post->post_type ? false : $delete;
	}

	/**
	 * List every active/inactive definition on this site.
	 *
	 * @return list<\WP_Post>
	 */
	public static function all(): array {
		$segments = array();
		$page     = 1;
		do {
			$batch = get_posts(
				array(
					'post_type'        => 'content_gate_segment',
					'post_status'      => array( 'publish', 'draft' ),
					'numberposts'      => 100,
					'paged'            => $page,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			);
			foreach ( $batch as $segment ) {
				$segments[] = $segment;
			}
			++$page;
			$count = count( $batch );
		} while ( 100 === $count );
		return $segments;
	}

	/**
	 * Resolve a definition, never another post type.
	 *
	 * @param int $id Site-local ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && 'content_gate_segment' === $post->post_type && in_array( $post->post_status, array( 'publish', 'draft' ), true ) ? $post : null;
	}

	/**
	 * Stable wire identity.
	 *
	 * @param int $id Site-local ID.
	 */
	public static function group( int $id ): string {
		return 'cg_' . get_current_blog_id() . '_' . $id;
	}
}
