<?php
/**
 * Response finalizer.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

use ContentGates\Membership\Membership;
use ContentGates\Segments\Segment_Registry;
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
 * - `X-Content-Gates-Key: a=1, b=none` (WP_DEBUG only; absent when empty)
 *
 * REST responses never reach `template_redirect`. `finalize_rest()` puts
 * the headers read so far on the `WP_REST_Response` at `rest_post_dispatch`,
 * but the set is not final there: core renders `_embed` sub-requests only
 * after it has sent the response object's headers. So when WordPress is
 * serving the REST request, `finalize_rest_echo()` freezes the set at
 * `rest_pre_echo_response`, the last filter before the first body byte,
 * and sends the headers again with `header()`, which replaces the earlier
 * values.
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
		add_filter( 'rest_pre_echo_response', array( $this, 'finalize_rest_echo' ), PHP_INT_MAX );
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
		$sent                            = $this->send( $consulted, $variant_key );
		$this->announce( $consulted, $variant_key, $sent );

		$this->membership->check_cookie_size();
		$this->release_buffer();
	}

	/**
	 * Put the signal read so far on a REST response object.
	 *
	 * Hooked to `rest_post_dispatch`. Nothing is frozen and no hook fires:
	 * the set may still grow (embedded sub-requests render after this
	 * point), and an internal `rest_do_request()` during a page render must
	 * not steal the page's own signal. Core sends these headers before the
	 * body, so a HEAD request or a response served by other code still
	 * carries what was known at dispatch; `finalize_rest_echo()` replaces
	 * them with the final values when WordPress serves the request.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $response Result to send, normally a WP_REST_Response.
	 * @return mixed The same response.
	 */
	public function finalize_rest( mixed $response ): mixed {
		if ( ! $response instanceof WP_REST_Response || $this->finalized ) {
			return $response;
		}

		$consulted   = $this->membership->consulted();
		$variant_key = $this->membership->variant_key( $consulted );

		if ( array() !== $consulted ) {
			$response->header( self::VARY_HEADER, self::format_vary( $consulted ) );
		}

		if ( $this->is_debug() && array() !== $variant_key ) {
			$response->header( self::KEY_HEADER, self::format_key( $variant_key ) );
		}

		return $response;
	}

	/**
	 * Freeze and send the signal for a REST request WordPress is serving.
	 *
	 * Hooked to `rest_pre_echo_response`, which core applies after
	 * `response_to_data()` has rendered every embedded resource and before
	 * it echoes the first body byte, so `header()` still works. Runs once;
	 * the filter and action fire here, not at dispatch.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $result Response data about to be echoed.
	 * @return mixed The same data.
	 */
	public function finalize_rest_echo( mixed $result ): mixed {
		if ( $this->finalized || ! ( $this->is_serving_rest )() ) {
			return $result;
		}

		$this->finalized = true;

		list( $consulted, $variant_key ) = $this->compute();
		$sent                            = $this->send( $consulted, $variant_key );
		$this->announce( $consulted, $variant_key, $sent );

		$this->membership->check_cookie_size();

		return $result;
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
	 * Freeze, filter and validate the consulted set.
	 *
	 * @return array{0: string[], 1: array<string, string>} Consulted keys and variant key.
	 */
	private function compute(): array {
		$set = $this->membership->consulted_set();
		$set->freeze();

		/**
		 * Filters the segments a response reports as consulted.
		 *
		 * Last chance to adjust the set the edge keys this response on.
		 * Adding a key is safe; removing one can serve one reader's page
		 * to another. Keys must match `[A-Za-z0-9_-]+`; others are dropped
		 * with a warning under WP_DEBUG.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $consulted Sorted segment keys read during render.
		 */
		$consulted = apply_filters( 'content_gates_consulted_segments', $set->keys() );
		$consulted = array_values( array_unique( array_map( 'strval', (array) $consulted ) ) );
		$consulted = $this->drop_invalid( $consulted );
		sort( $consulted, SORT_STRING );

		return array( $consulted, $this->membership->variant_key( $consulted ) );
	}

	/**
	 * Remove keys that cannot travel in a header value.
	 *
	 * @param string[] $consulted Filtered keys.
	 * @return string[] Valid keys only.
	 */
	private function drop_invalid( array $consulted ): array {
		$invalid = array_filter( $consulted, static fn( string $key ): bool => ! Segment_Registry::is_valid_key( $key ) );
		if ( array() === $invalid ) {
			return $consulted;
		}

		wp_trigger_error(
			__METHOD__,
			sprintf(
				'The content_gates_consulted_segments filter returned keys that are not valid segment keys and were dropped: %s. Keys must match %s.',
				implode( ', ', array_map( static fn( string $key ): string => '"' . addcslashes( $key, "\"\\\0..\37\177" ) . '"', $invalid ) ),
				Segment_Registry::KEY_PATTERN
			),
			E_USER_WARNING
		);

		return array_values( array_diff( $consulted, $invalid ) );
	}

	/**
	 * Send the signal headers with `header()`.
	 *
	 * Warns under WP_DEBUG when the consulted set is non-empty but output
	 * already started: the response then carries no signal and the edge may
	 * store it for every reader.
	 *
	 * @param string[]              $consulted   Sorted keys.
	 * @param array<string, string> $variant_key Key => value or 'none'.
	 * @return bool Whether the Vary header was sent, or true when there was nothing to send.
	 */
	private function send( array $consulted, array $variant_key ): bool {
		$sent = true;

		if ( array() !== $consulted ) {
			$sent = Headers::send( self::VARY_HEADER, self::format_vary( $consulted ) );
		}

		if ( $this->is_debug() && array() !== $variant_key ) {
			Headers::send( self::KEY_HEADER, self::format_key( $variant_key ) );
		}

		if ( ! $sent ) {
			wp_trigger_error(
				__METHOD__,
				sprintf(
					'Content Gates could not send %s because output already started (consulted: %s). The response may be cached for every reader.',
					self::VARY_HEADER,
					self::format_vary( $consulted )
				),
				E_USER_WARNING
			);
		}

		return $sent;
	}

	/**
	 * Announce the final signal.
	 *
	 * @param string[]              $consulted   Sorted keys.
	 * @param array<string, string> $variant_key Key => value or 'none'.
	 * @param bool                  $sent        Whether the Vary header reached the response.
	 */
	private function announce( array $consulted, array $variant_key, bool $sent ): void {
		/**
		 * Fires once the consulted set is final for this response.
		 *
		 * @since 0.1.0
		 *
		 * @param string[]              $consulted   Sorted segment keys.
		 * @param array<string, string> $variant_key Key => value or 'none'.
		 * @param bool                  $sent        Whether `X-Content-Gates-Vary` reached the response. False
		 *                                           when the set is non-empty but output had already started.
		 */
		do_action( 'content_gates_finalize_variance', $consulted, $variant_key, $sent );
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
