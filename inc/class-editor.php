<?php
/**
 * Group editor integration.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

/** Registers the assignment attribute, editor bundle and segment lookup. */
final class Editor {
	/** Register integration hooks. */
	public static function register(): void {
		add_filter( 'register_block_type_args', array( self::class, 'attributes' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Extend only Group blocks; null means no assignment.
	 *
	 * @param array<string, mixed> $args Block registration arguments.
	 * @param string               $name Block name.
	 * @return array<string, mixed>
	 */
	public static function attributes( array $args, string $name ): array {
		if ( 'core/group' === $name ) {
			$args['attributes']['contentGateSegmentId'] = array( 'type' => 'number' );
		}
		return $args;
	}

	/** Enqueue the distributable editor bundle. */
	public static function enqueue(): void {
		$asset_path = CONTENT_GATES_PATH . 'build/index.asset.php';
		if ( ! is_readable( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;
		wp_enqueue_script( 'content-gates-editor', plugins_url( 'build/index.js', CONTENT_GATES_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'content-gates-editor', 'content-gates' );
	}

	/** Register a permissioned metadata lookup, never a membership endpoint. */
	public static function routes(): void {
		register_rest_route(
			'content-gates/v1',
			'/segments',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
				'callback'            => array( self::class, 'listing' ),
			)
		);
	}

	/**
	 * Return names and statuses, including inactive existing references.
	 *
	 * @return \WP_REST_Response Segment metadata only.
	 */
	public static function listing(): \WP_REST_Response {
		$rows = array();
		foreach ( Segments::all() as $segment ) {
			$rows[] = array(
				'id'     => $segment->ID,
				'name'   => $segment->post_title,
				'active' => 'publish' === $segment->post_status,
			);
		}
		$response = new \WP_REST_Response( $rows );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
}
