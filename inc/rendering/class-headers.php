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
	 * Send a header when still possible, and record it either way.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    Header name.
	 * @param string $value   Header value.
	 * @param bool   $replace Replace an earlier header of the same name.
	 * @return bool Whether `header()` was called (false when output already started).
	 */
	public static function send( string $name, string $value, bool $replace = true ): bool {
		self::$sent[ $name ] = $value;

		if ( headers_sent() ) {
			return false;
		}

		header( $name . ': ' . $value, $replace );

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
	 * Forget recorded headers. For tests.
	 *
	 * @since 0.1.0
	 */
	public static function reset(): void {
		self::$sent = array();
	}
}
