<?php
/**
 * Response dependency finalizer.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

use ContentGates\Membership\Consulted_Segments;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Freezes and reports consulted segments after response rendering.
 */
final class Response_Finalizer {

	/**
	 * Prototype edge signal header.
	 */
	public const HEADER_NAME = 'X-Content-Gate-Vary';

	/**
	 * Consulted segment request state.
	 *
	 * @var Consulted_Segments
	 */
	private Consulted_Segments $consulted;

	/**
	 * Whether this finalizer started an output buffer.
	 *
	 * @var bool
	 */
	private bool $buffering = false;

	/**
	 * Construct the finalizer.
	 *
	 * @param Consulted_Segments $consulted Per-response dependency state.
	 */
	public function __construct( Consulted_Segments $consulted ) {
		$this->consulted = $consulted;
	}

	/**
	 * Register output-path hooks.
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'start_buffer' ), PHP_INT_MIN );
		add_filter( 'rest_post_dispatch', array( $this, 'finalize_rest_response' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Buffer public HTML and feed output until dependencies are known.
	 */
	public function start_buffer(): void {
		if ( $this->buffering || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$this->buffering = true;
		ob_start( array( $this, 'finalize_buffer' ) );
	}

	/**
	 * Finalize an HTML or feed buffer.
	 *
	 * @param string $body Rendered response body.
	 */
	public function finalize_buffer( string $body ): string {
		$this->buffering = false;
		$this->send_signal_header( $this->consulted->finalize() );
		return $body;
	}

	/**
	 * Finalize a rendered REST response.
	 *
	 * @param WP_HTTP_Response $response REST response.
	 * @param WP_REST_Server   $server   REST server.
	 * @param WP_REST_Request  $request  REST request.
	 */
	public function finalize_rest_response(
		WP_HTTP_Response $response,
		WP_REST_Server $server,
		WP_REST_Request $request
	): WP_HTTP_Response {
		unset( $server, $request );
		$consulted = $this->consulted->finalize();
		if ( array() !== $consulted ) {
			$response->header( self::HEADER_NAME, implode( ', ', $consulted ) );
		}
		return $response;
	}

	/**
	 * Send the prototype cache dependency signal.
	 *
	 * @param array<int, string> $consulted Stable cache group keys.
	 */
	private function send_signal_header( array $consulted ): void {
		if ( array() === $consulted || headers_sent() ) {
			return;
		}

		header( self::HEADER_NAME . ': ' . implode( ', ', $consulted ) );
	}
}
