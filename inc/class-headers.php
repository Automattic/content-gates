<?php
/**
 * Response header writer with a record of what was sent.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates;

/**
 * Thin `header()` wrapper that remembers every header the plugin sent.
 *
 * PHP's `headers_list()` is empty under the CLI, so tests read the record
 * instead. The record is per request; tests reset it between cases.
 *
 * @since 0.1.0
 */
final class Headers {

	/**
	 * Headers sent this request, in order, as `[ name, value ]` pairs.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private static array $sent = array();

	/**
	 * Send a header and record it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    Header name.
	 * @param string $value   Header value.
	 * @param bool   $replace Whether to replace an earlier header of the same name.
	 * @return bool True when PHP could still send it, false when output had started.
	 */
	public static function send( string $name, string $value, bool $replace = true ): bool {
		self::$sent[] = array( $name, $value );

		if ( headers_sent() ) {
			return false;
		}

		header( $name . ': ' . $value, $replace );

		return true;
	}

	/**
	 * Every value sent for a header name this request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name, case-insensitive.
	 * @return string[] Values in send order.
	 */
	public static function values( string $name ): array {
		$values = array();
		foreach ( self::$sent as $pair ) {
			if ( 0 === strcasecmp( $pair[0], $name ) ) {
				$values[] = $pair[1];
			}
		}

		return $values;
	}

	/**
	 * The last value sent for a header, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name, case-insensitive.
	 */
	public static function last( string $name ): ?string {
		$values = self::values( $name );

		return array() === $values ? null : end( $values );
	}

	/**
	 * Forget the record. For tests.
	 *
	 * @since 0.1.0
	 */
	public static function reset(): void {
		self::$sent = array();
	}
}
