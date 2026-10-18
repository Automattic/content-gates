<?php
/**
 * Reader membership REST controller.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\REST;

use ContentGates\Membership\Membership_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes the versioned assignment contract to authorized clients.
 */
final class Membership_Controller {

	private const NAMESPACE = 'content-gates/v1';

	/**
	 * Membership service.
	 *
	 * @var Membership_Service
	 */
	private Membership_Service $membership;

	/**
	 * Construct the controller.
	 *
	 * @param Membership_Service $membership Membership service.
	 */
	public function __construct( Membership_Service $membership ) {
		$this->membership = $membership;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'prevent_caching' ), 10, 3 );
	}

	/**
	 * Register membership routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/memberships/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_membership' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'member' => array(
						'type'              => 'boolean',
						'required'          => true,
						'validate_callback' => static function ( $value ): bool {
							return is_bool( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Check permission for a membership mutation.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function check_permission( WP_REST_Request $request ): bool {
		$allowed = current_user_can( 'manage_options' );

		/**
		 * Filters permission for the membership assignment endpoint.
		 *
		 * Entitlement adapters can authenticate their own callers and return true.
		 *
		 * @since 0.1.0
		 *
		 * @param bool            $allowed Whether the caller may assign membership.
		 * @param WP_REST_Request $request Request object.
		 */
		return (bool) apply_filters( 'content_gates_rest_membership_permission', $allowed, $request );
	}

	/**
	 * Assign or remove reader membership.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_membership( WP_REST_Request $request ) {
		$segment_id = (int) $request['id'];
		$member     = true === $request['member'];
		$result     = $member
			? $this->membership->assign_reader_to_segment( $segment_id )
			: $this->membership->remove_reader_from_segment( $segment_id );

		if ( is_wp_error( $result ) ) {
			return $this->rest_error( $result );
		}

		$flushed = $this->membership->flush_membership_headers();
		if ( is_wp_error( $flushed ) ) {
			return $this->rest_error( $flushed );
		}

		$response = new WP_REST_Response(
			array(
				'api_version' => Membership_Service::API_VERSION,
				'segment_id'  => $segment_id,
				'member'      => $member,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Prevent caches from storing any membership endpoint response.
	 *
	 * @param \WP_HTTP_Response $response REST response.
	 * @param WP_REST_Server    $server   REST server.
	 * @param WP_REST_Request   $request  REST request.
	 * @return \WP_HTTP_Response
	 */
	public function prevent_caching( $response, WP_REST_Server $server, WP_REST_Request $request ) {
		unset( $server );
		if ( 0 !== strpos( $request->get_route(), '/' . self::NAMESPACE . '/memberships/' ) ) {
			return $response;
		}

		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Add an HTTP status without changing the public error code.
	 *
	 * @param WP_Error $error Membership error.
	 */
	private function rest_error( WP_Error $error ): WP_Error {
		$status = 500;
		switch ( $error->get_error_code() ) {
			case 'content_gates_invalid_segment':
				$status = 404;
				break;
			case 'content_gates_inactive_segment':
				$status = 409;
				break;
			case 'content_gates_assignment_too_late':
			case 'content_gates_assignment_too_early':
			case 'content_gates_provider_unavailable':
				$status = 503;
				break;
		}

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array( 'status' => $status )
		);
	}
}
