<?php
/**
 * Membership REST endpoint.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\REST;

use ContentGates\Membership\Local_Cookie_Adapter;
use ContentGates\Membership\Membership;
use ContentGates\Plugin;
use ContentGates\Segments\Segment_Source;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `content-gates/v1/membership`: the uncacheable assignment surface.
 *
 * A browser script or an MCP-style agent cannot run `assign_reader_to_segment()`
 * itself, and a cacheable GET never reaches origin on a HIT. This endpoint
 * performs the same assignment on a request that always reaches origin
 * and returns the cookie on its own response, so the next page view
 * already carries the membership.
 *
 * - `GET` is public and returns the reader's membership plus the segment
 *   list. It records no reads: the response is never cached, so there is
 *   nothing to key.
 * - `POST` changes membership. The plugin never decides entitlement; the
 *   provider must grant the request through the
 *   `content_gates_rest_membership_permission` filter.
 *
 * Every response from this route, errors included, carries
 * `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and
 * `Vary: Cookie`. Both are attached on `rest_request_after_callbacks`, so
 * a 403 from the permission callback or a 400 from argument validation is
 * as uncacheable as a success.
 *
 * `send_headers` never fires for REST requests, so the controller flushes
 * the cache adapter explicitly after a successful write. After that flush
 * the request is "too late" for further writes, which is correct: one
 * response carries one `Set-Cookie`.
 *
 * @since 0.1.0
 */
final class Membership_Controller {

	public const REST_NAMESPACE = 'content-gates/v1';
	public const REST_ROUTE     = '/membership';

	/**
	 * Headers that make the route uncacheable, name => value.
	 *
	 * @var array<string, string>
	 */
	public const NO_CACHE_HEADERS = array(
		'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
		'Vary'          => 'Cookie',
	);

	/**
	 * Injected membership layer, or null to use the booted plugin's.
	 *
	 * @var Membership|null
	 */
	private ?Membership $membership;

	/**
	 * Injected segment source, or null to use the booted plugin's.
	 *
	 * @var Segment_Source|null
	 */
	private ?Segment_Source $segments;

	/**
	 * Constructor.
	 *
	 * Both dependencies default to the booted plugin's components and are
	 * resolved lazily, so the controller can be constructed inside
	 * `Plugin::boot()` before `content_gates_loaded`. Tests inject a fresh
	 * `Membership` so an explicit `flush()` in one test does not mark the
	 * shared adapter "too late" for the rest of the PHP process.
	 *
	 * @param Membership|null     $membership Membership layer. Default: `Plugin::instance()->membership()`.
	 * @param Segment_Source|null $segments   Segment source. Default: `Plugin::instance()->segments()`.
	 */
	public function __construct( ?Membership $membership = null, ?Segment_Source $segments = null ) {
		$this->membership = $membership;
		$this->segments   = $segments;
	}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'make_uncacheable' ), 10, 3 );
	}

	/**
	 * Register the `/membership` route.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_membership' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'change_membership' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'segment' => array(
							'description'       => __( 'Segment post ID (integer) or key (string). A string of digits only is treated as an ID; anything else is a key.', 'content-gates' ),
							'type'              => array( 'integer', 'string' ),
							'required'          => true,
							'minLength'         => 1,
							'sanitize_callback' => array( self::class, 'sanitize_segment_ref' ),
						),
						'action'  => array(
							'description' => __( 'Whether to put the reader into the segment or take them out.', 'content-gates' ),
							'type'        => 'string',
							'enum'        => array( 'assign', 'remove' ),
							'default'     => 'assign',
						),
						'value'   => array(
							'description'       => __( 'Value to store for an assignment. Only A-Z, a-z, 0-9, "_" and "-".', 'content-gates' ),
							'type'              => 'string',
							'default'           => '1',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * `GET /membership`: the reader's current membership and the segment list.
	 *
	 * Reads raw adapter state through `Membership::membership()`, which
	 * records nothing in the consulted set.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_membership( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response( $this->state(), 200 );
	}

	/**
	 * `POST /membership`: assign or remove, then flush the cookie onto this
	 * response.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request with `segment`, `action` and `value`.
	 * @return WP_REST_Response|WP_Error 200 with the GET shape plus `changed`
	 *                                   (and `cookie` under the local
	 *                                   simulation), or a 400 carrying the
	 *                                   membership layer's error code.
	 */
	public function change_membership( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$segment = $request->get_param( 'segment' );
		$action  = $request->get_param( 'action' );
		$value   = $request->get_param( 'value' );

		if ( ! is_int( $segment ) && ! is_string( $segment ) ) {
			return new WP_Error(
				'content_gates_invalid_segment',
				__( 'Segment must be a post ID or a key.', 'content-gates' ),
				array( 'status' => 400 )
			);
		}

		$membership = $this->membership();
		$result     = 'remove' === $action
			? $membership->remove( $segment )
			: $membership->assign( $segment, is_string( $value ) ? $value : '1' );

		if ( $result instanceof WP_Error ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// send_headers never fires on REST requests: write the cookie now so
		// Set-Cookie is on this response.
		$cache = $membership->cache();
		$cache->flush();

		$data            = $this->state();
		$data['changed'] = true;

		if ( $cache instanceof Local_Cookie_Adapter ) {
			$pending = $cache->pending_cookie();
			if ( null !== $pending ) {
				$data['cookie'] = array(
					'name'  => $pending['name'],
					'value' => $pending['value'],
				);
			}
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Permission callback for `POST`: ask the provider.
	 *
	 * The plugin has no opinion on entitlement. Without a provider granting
	 * the request, every write is refused.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error `content_gates_forbidden` (403) unless a provider
	 *                       returned `true`, or the provider's own `WP_Error`.
	 */
	public function permission_check( WP_REST_Request $request ): bool|WP_Error {
		/**
		 * Filters whether a `POST /content-gates/v1/membership` request may
		 * change the reader's membership.
		 *
		 * This is the provider authorization hook. The plugin never decides
		 * entitlement itself: the default is `false`, so without a provider
		 * every write is refused with `content_gates_forbidden` (403).
		 *
		 * Return `true` to allow the request. Return a `WP_Error` to refuse
		 * with your own code, message and `status`; it is sent as-is. Any
		 * other value refuses with `content_gates_forbidden`.
		 *
		 * @since 0.1.0
		 *
		 * @param bool|WP_Error                         $allowed Whether the request is allowed. Default false.
		 * @param WP_REST_Request $request The request, with `segment`, `action` and `value` params.
		 */
		$allowed = apply_filters( 'content_gates_rest_membership_permission', false, $request );

		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}

		if ( true === $allowed ) {
			return true;
		}

		return new WP_Error(
			'content_gates_forbidden',
			__( 'No entitlement provider authorized this membership change.', 'content-gates' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Hook callback: make every response from this route uncacheable.
	 *
	 * Runs on `rest_request_after_callbacks`, which fires for validation
	 * errors, permission errors and callback results alike. Errors are
	 * converted to responses here so they can carry headers; status and
	 * body are unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $response Result so far: a response, a WP_Error, or null.
	 * @param array<string, mixed> $handler  Matched handler.
	 * @param WP_REST_Request      $request  Request.
	 * @return mixed The response with no-cache headers for this route, else unchanged.
	 */
	public function make_uncacheable( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		unset( $handler );

		if ( ! self::is_membership_route( $request ) ) {
			return $response;
		}

		if ( $response instanceof WP_Error ) {
			$response = rest_convert_error_to_response( $response );
		}

		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		foreach ( self::NO_CACHE_HEADERS as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}

	/**
	 * Sanitize the `segment` argument: a string of digits means post ID,
	 * else a key.
	 *
	 * The digit check is strict (`ctype_digit`), not `is_numeric`: `1e3`,
	 * `12.5` and ` 12` are keys, so they fail with
	 * `content_gates_invalid_segment` instead of being coerced to an ID
	 * the caller did not name.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw argument.
	 * @return int|string
	 */
	public static function sanitize_segment_ref( mixed $value ): int|string {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (int) $value;
		}

		return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Whether a request targets this controller's route.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function is_membership_route( WP_REST_Request $request ): bool {
		return '/' . self::REST_NAMESPACE . self::REST_ROUTE === $request->get_route();
	}

	/**
	 * The response body shared by GET and a successful POST.
	 *
	 * `membership` is always a JSON object. PHP serializes an empty array
	 * as `[]`, which would change the JSON type for clients, so an empty
	 * membership is cast to an object.
	 *
	 * @return array{contract: string, membership: array<string, string>|\stdClass, segments: list<array{id: int|null, key: string, name: string, active: bool}>}
	 */
	private function state(): array {
		$segments = array();
		foreach ( $this->segments()->all() as $segment ) {
			$segments[] = array(
				'id'     => $segment->id(),
				'key'    => $segment->key(),
				'name'   => $segment->name(),
				'active' => $segment->is_active(),
			);
		}

		$membership = $this->membership()->membership();

		return array(
			'contract'   => (string) constant( 'CONTENT_GATES_CONTRACT_VERSION' ),
			'membership' => array() === $membership ? new \stdClass() : $membership,
			'segments'   => $segments,
		);
	}

	/**
	 * Membership layer: injected or the booted plugin's.
	 */
	private function membership(): Membership {
		if ( null === $this->membership ) {
			$this->membership = Plugin::instance()->membership();
		}

		return $this->membership;
	}

	/**
	 * Segment source: injected or the booted plugin's.
	 */
	private function segments(): Segment_Source {
		if ( null === $this->segments ) {
			$this->segments = Plugin::instance()->segments();
		}

		return $this->segments;
	}
}
