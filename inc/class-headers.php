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
	 * Expire a cookie and record the `Set-Cookie` that did it.
	 *
	 * Applies the path and domain filters `Vary_Cache` applies to its own
	 * cookies, so the expiry matches the cookie it removes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Cookie name.
	 * @return bool True when PHP could still send it, false when output had started.
	 */
	public static function expire_cookie( string $name ): bool {
		$default_path   = defined( 'COOKIEPATH' ) ? (string) constant( 'COOKIEPATH' ) : '/';
		$default_domain = defined( 'COOKIE_DOMAIN' ) ? (string) constant( 'COOKIE_DOMAIN' ) : '';

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Vary_Cache's own filters, applied for parity.
		$path   = (string) apply_filters( 'vip_vary_cache_cookie_path', $default_path );
		$domain = (string) apply_filters( 'vip_vary_cache_cookie_domain', $default_domain );
		// phpcs:enable

		$value = $name . '=; Max-Age=0; Path=' . ( '' === $path ? '/' : $path );
		if ( '' !== $domain ) {
			$value .= '; Domain=' . $domain;
		}
		self::$sent[] = array( 'Set-Cookie', $value );

		if ( headers_sent() ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setrawcookie -- Expires the cookie Vary_Cache set; see Membership::expire_empty_cookie().
		setrawcookie( $name, '', time() - YEAR_IN_SECONDS, $path, $domain );

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
