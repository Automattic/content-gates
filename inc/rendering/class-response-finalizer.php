<?php
/**
 * Response finalizer.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

use ContentGates\Membership\Membership;
use WP_REST_Response;

/**
 * Computes the variance signal after render and attaches it to the response.
 *
 * The consulted set is only known once templates and blocks have run, and
 * PHP cannot add headers after output starts. So on front-end requests the
 * finalizer buffers the whole body from `template_redirect` and releases
 * it at `shutdown`, after sending:
 *
 * - `X-Content-Gates-Vary: a, b` (sorted consulted keys; absent when empty)
 * - `X-Content-Gates-Key: a=1, b=none` (WP_DEBUG only)
 *
 * REST responses never reach `template_redirect`; `finalize_rest()` sets
 * the same headers on the `WP_REST_Response` instead.
 *
 * @since 0.1.0
 */
final class Response_Finalizer {

	public const VARY_HEADER = 'X-Content-Gates-Vary';
	public const KEY_HEADER  = 'X-Content-Gates-Key';

	/**
	 * Output buffer level of the buffer we started, 0 when none.
	 *
	 * @var int
	 */
	private int $level = 0;

	/**
	 * Whether the signal was computed and sent.
	 *
	 * @var bool
	 */
	private bool $finalized = false;

	/**
	 * Returns whether WordPress is serving a standalone REST request.
	 *
	 * @var \Closure(): bool
	 */
	private \Closure $is_serving_rest;

	/**
	 * Constructor.
	 *
	 * @param Membership            $membership      Membership layer.
	 * @param \Closure(): bool|null $is_serving_rest Override for `wp_is_serving_rest_request()`. Tests only.
	 */
	public function __construct( private Membership $membership, ?\Closure $is_serving_rest = null ) {
		$this->is_serving_rest = $is_serving_rest ?? static fn(): bool => wp_is_serving_rest_request();
	}

	/**
	 * Add hooks. Buffering is skipped in admin and admin-ajax requests.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		if ( ! is_admin() && ! wp_doing_ajax() ) {
			add_action( 'template_redirect', array( $this, 'begin' ), PHP_INT_MIN );
			add_action( 'shutdown', array( $this, 'finalize' ), 0 );
		}

		add_filter( 'rest_post_dispatch', array( $this, 'finalize_rest' ), PHP_INT_MAX );
	}

	/**
	 * Start buffering the response body.
	 *
	 * @since 0.1.0
	 */
	public function begin(): void {
		if ( 0 !== $this->level || $this->finalized ) {
			return;
		}

		if ( ob_start() ) {
			$this->level = ob_get_level();
		}
	}

	/**
	 * Send the variance signal and release the buffered body. Idempotent.
	 *
	 * @since 0.1.0
	 */
	public function finalize(): void {
		if ( $this->finalized ) {
			return;
		}

		$this->finalized = true;

		list( $consulted, $variant_key ) = $this->compute();

		if ( array() !== $consulted ) {
			Headers::send( self::VARY_HEADER, self::format_vary( $consulted ) );
		}

		if ( $this->is_debug() ) {
			Headers::send( self::KEY_HEADER, self::format_key( $variant_key ) );
		}

		$this->membership->check_cookie_size();
		$this->release_buffer();
	}

	/**
	 * Attach the variance signal to a REST response.
	 *
	 * Hooked to `rest_post_dispatch`. Marks the request finalized only when
	 * WordPress is serving this REST request, so an internal
	 * `rest_do_request()` during a page render does not steal the page's
	 * own signal.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $response Result to send, normally a WP_REST_Response.
	 * @return mixed The same response.
	 */
	public function finalize_rest( mixed $response ): mixed {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$serving = ( $this->is_serving_rest )();
		if ( $serving && $this->finalized ) {
			return $response;
		}

		list( $consulted, $variant_key ) = $this->compute( $serving );

		if ( array() !== $consulted ) {
			$response->header( self::VARY_HEADER, self::format_vary( $consulted ) );
		}

		if ( $this->is_debug() ) {
			$response->header( self::KEY_HEADER, self::format_key( $variant_key ) );
		}

		if ( $serving ) {
			$this->finalized = true;
			$this->membership->check_cookie_size();
		}

		return $response;
	}

	/**
	 * Whether the signal was sent.
	 *
	 * @since 0.1.0
	 */
	public function is_finalized(): bool {
		return $this->finalized;
	}

	/**
	 * Freeze (optionally), filter and announce the consulted set.
	 *
	 * @param bool $freeze Whether to freeze the consulted set.
	 * @return array{0: string[], 1: array<string, string>} Consulted keys and variant key.
	 */
	private function compute( bool $freeze = true ): array {
		$set = $this->membership->consulted_set();
		if ( $freeze ) {
			$set->freeze();
		}

		/**
		 * Filters the segments a response reports as consulted.
		 *
		 * Last chance to adjust the set the edge keys this response on.
		 * Adding a key is safe; removing one can serve one reader's page
		 * to another.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $consulted Sorted segment keys read during render.
		 */
		$consulted = apply_filters( 'content_gates_consulted_segments', $set->keys() );
		$consulted = array_values( array_unique( array_map( 'strval', (array) $consulted ) ) );
		sort( $consulted, SORT_STRING );

		$variant_key = $this->membership->variant_key( $consulted );

		/**
		 * Fires once the consulted set is final for this response.
		 *
		 * @since 0.1.0
		 *
		 * @param string[]              $consulted   Sorted segment keys.
		 * @param array<string, string> $variant_key Key => value or 'none'.
		 */
		do_action( 'content_gates_finalize_variance', $consulted, $variant_key );

		return array( $consulted, $variant_key );
	}

	/**
	 * Flush buffers down to and including the one we started.
	 */
	private function release_buffer(): void {
		if ( 0 === $this->level ) {
			return;
		}

		$level       = $this->level;
		$this->level = 0;

		// Bounded loop: a buffer that refuses to close must not hang shutdown.
		$guard = ob_get_level();
		while ( ob_get_level() >= $level && $guard > 0 ) {
			if ( ! ob_end_flush() ) {
				break;
			}
			--$guard;
		}
	}

	/**
	 * Header value for the consulted set.
	 *
	 * @param string[] $consulted Sorted keys.
	 */
	private static function format_vary( array $consulted ): string {
		return implode( ', ', $consulted );
	}

	/**
	 * Header value for the variant key.
	 *
	 * @param array<string, string> $variant_key Key => value.
	 */
	private static function format_key( array $variant_key ): string {
		$parts = array();
		foreach ( $variant_key as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Whether debug headers are enabled.
	 */
	private function is_debug(): bool {
		return defined( 'WP_DEBUG' ) && true === constant( 'WP_DEBUG' );
	}
}
