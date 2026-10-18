<?php
/**
 * Response header helper.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

/**
 * Sends response headers and records them so tests can inspect them.
 *
 * PHP's `header()` is a no-op once output started and `headers_list()` is
 * empty under the CLI SAPI, so tests read `sent()` instead.
 *
 * @since 0.1.0
 */
final class Headers {

	/**
	 * Headers recorded this request, name => value (last write wins).
	 *
	 * @var array<string, string>
	 */
	private static array $sent = array();

	/**
	 * Test seam: pretend output has (true) or has not (false) started.
	 * Null uses the real `headers_sent()`.
	 *
	 * @var bool|null
	 */
	private static ?bool $output_started = null;

	/**
	 * Send a header when still possible, and record it either way.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    Header name.
	 * @param string $value   Header value.
	 * @param bool   $replace Replace an earlier header of the same name.
	 * @return bool Whether the header could be sent (false when output already started).
	 */
	public static function send( string $name, string $value, bool $replace = true ): bool {
		self::$sent[ $name ] = $value;

		if ( self::$output_started ?? headers_sent() ) {
			return false;
		}

		// Under simulation the header is only recorded: the CLI SAPI would warn.
		if ( null === self::$output_started ) {
			header( $name . ': ' . $value, $replace );
		}

		return true;
	}

	/**
	 * Headers recorded this request.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public static function sent(): array {
		return self::$sent;
	}

	/**
	 * Override `headers_sent()`. For tests.
	 *
	 * PHPUnit prints to stdout before any test runs, so under the CLI SAPI
	 * `headers_sent()` is always true. Pass false so `send()` reports
	 * success as it would on a web request, true to simulate output that
	 * already started, or null to use the real check again.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null $started Simulated `headers_sent()` result, or null for the real one.
	 */
	public static function simulate_output_started( ?bool $started ): void {
		self::$output_started = $started;
	}

	/**
	 * Forget recorded headers and clear the simulation. For tests.
	 *
	 * @since 0.1.0
	 */
	public static function reset(): void {
		self::$sent           = array();
		self::$output_started = null;
	}
}
