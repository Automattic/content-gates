<?php
/**
 * Versioned membership assignment endpoint.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes provider-driven membership assignment.
 */
final class Rest_API {

	/**
	 * Register WordPress hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register contract-versioned routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'content-gates/v1',
			'/memberships/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'update_membership' ),
				'permission_callback' => array( self::class, 'can_assign' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'active' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Whether the current provider may assign membership.
	 *
	 * Providers can narrow or replace this check with the documented filter.
	 */
	public static function can_assign(): bool {
		/**
		 * Filters whether the current REST caller may assign memberships.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $allowed Whether assignment is allowed.
		 */
		return (bool) apply_filters( 'content_gates_can_assign_membership', current_user_can( 'manage_options' ) );
	}

	/**
	 * Assign or remove membership for the current reader.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error REST response or API error.
	 */
	public static function update_membership( WP_REST_Request $request ) {
		$segment_id = (int) $request['id'];
		$active     = (bool) $request['active'];
		$result     = $active ? assign_reader_to_segment( $segment_id ) : remove_reader_from_segment( $segment_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'contract_version' => Membership::CONTRACT_VERSION,
				'segment_id'       => $segment_id,
				'active'           => $active,
				'applies_on'       => 'next_request',
			)
		);
	}
}
