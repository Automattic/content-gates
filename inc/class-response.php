<?php
/**
 * Conservative origin response lifecycle.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates;

/** No-store remains mandatory until a real narrow edge adapter is verified. */
final class Response {
	/** Install lifecycle hooks without altering unrelated Vary headers. */
	public static function register(): void {
		add_filter( 'wp_headers', array( self::class, 'headers' ), PHP_INT_MAX );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'send_headers', array( self::class, 'close' ), 0 );
		add_action( 'save_post', array( self::class, 'invalidate' ), 10, 2 );
		if ( 'cli' !== PHP_SAPI ) {
			self::no_store();
			ob_start( array( self::class, 'output' ) );
		}
	}

	/** Force conservative cache policy before any response bytes. */
	public static function no_store(): void {
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}

	/**
	 * Preserve all other headers.
	 *
	 * @param array<string,string> $headers Existing headers.
	 * @return array<string,string>
	 */
	public static function headers( array $headers ): array {
		$headers['Cache-Control'] = 'private, no-store, max-age=0';
		return $headers;
	}

	/** Close mutations at the normal WordPress header boundary. */
	public static function close(): void {
		Membership::instance()->close();
		self::no_store();
	}

	/**
	 * Report after rendering; flushes freeze early and later reads fail closed.
	 * This is a diagnostic signal, never a production cache key instruction.
	 *
	 * @param string $body Output bytes, unchanged.
	 * @return string
	 */
	public static function output( string $body ): string {
		$projection = Membership::instance()->finalize();
		self::no_store();
		if ( ! headers_sent() && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$report = wp_json_encode( $projection );
			if ( false !== $report && strlen( $report ) <= 6000 ) {
				header( 'X-Content-Gate-Projection: ' . $report );
			} else {
				header( 'X-Content-Gate-Report: oversized' );
			}
			header( 'X-Content-Gate-Cache: unavailable-no-store' );
		}
		return $body;
	}

	/** Register administrator/provider test boundary, never public self-grants. */
	public static function routes(): void {
		register_rest_route(
			'content-gates/v1',
			'/membership',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ); },
				'callback'            => array( self::class, 'assign' ),
				'args'                => array(
					'segment_id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'member'     => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'version'    => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);
	}

	/**
	 * Assign only for the authenticated caller, then use VIP's cookie sender.
	 *
	 * @param \WP_REST_Request $request Validated request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function assign( \WP_REST_Request $request ) {
		$result = content_gates_assign_reader( (int) $request['segment_id'], (bool) $request['member'], (int) $request['version'] );
		self::no_store();
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		Membership::instance()->send();
		return new \WP_REST_Response(
			array(
				'version'    => 1,
				'segment_id' => (int) $request['segment_id'],
				'member'     => (bool) $request['member'],
			),
			200,
			array( 'Cache-Control' => 'private, no-store, max-age=0' )
		);
	}

	/**
	 * Explicit invalidation REQUEST, not an invented platform purge.
	 *
	 * @param int      $post_id Changed resource.
	 * @param \WP_Post $post Updated post.
	 */
	public static function invalidate( int $post_id, \WP_Post $post ): void {
		/**
		 * Deployment adapter must invalidate every affected variant.
		 *
		 * @since 0.1.0
		 * @param int $post_id Changed resource.
		 * @param string $scope Global for definitions, resource otherwise.
		 */
		do_action( 'content_gates_invalidation_requested', $post_id, 'content_gate_segment' === $post->post_type ? 'global' : 'resource' );
	}
}
