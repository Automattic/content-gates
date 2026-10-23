<?php
/**
 * REST route listing segments for the editor.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\REST;

use Automattic\VIP\ContentGates\Segments\Segment_Registry;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /content-gates/v1/segments`: the segment list for the block editor.
 *
 * Authenticated only. Anonymous callers may not list segment names or keys.
 * Inactive segments are included, flagged, so the editor can label existing
 * assignments to them; the editor offers only active ones for new
 * assignments. The plugin ships no membership route: providers write their
 * own authenticated endpoint and call the PHP functions.
 *
 * @since 0.1.0
 */
final class Segments_Controller {

	public const NAMESPACE = 'content-gates/v1';

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/segments',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_segments' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);
	}

	/**
	 * List segments.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_segments( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$items = array();
		foreach ( $this->segments->all() as $segment ) {
			$items[] = array(
				'id'      => $segment->id,
				'key'     => $segment->key,
				'name'    => $segment->name,
				'active'  => $segment->active,
				'type'    => $segment->type,
				'crawler' => $segment->crawler,
			);
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
