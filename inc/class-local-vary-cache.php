<?php
/**
 * Local-only Vary_Cache adapter.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

use WP_Error;

/**
 * Mirrors the small Vary_Cache surface needed by the prototype locally.
 */
final class Local_Vary_Cache {

	private const COOKIE = 'vip-go-seg';

	private const LOCAL_CACHE_BYPASS_COOKIE = 'wordpress_content_gates_local_membership';

	private const PREFIX = 'vc-v1__';

	private const GROUP_SEPARATOR = '---__';

	private const VALUE_SEPARATOR = '_--_';

	/**
	 * Request membership keyed by cache group.
	 *
	 * @var array<string,string>
	 */
	private static array $groups = array();

	/**
	 * Parse the request cookie before segment registration.
	 */
	public static function load(): void {
		self::$groups = array();
		$cookie       = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : ''; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Local adapter mirrors the VIP cookie parser.
		if ( ! str_starts_with( $cookie, self::PREFIX ) ) {
			return;
		}

		$payload = substr( $cookie, strlen( self::PREFIX ) );
		foreach ( explode( self::GROUP_SEPARATOR, $payload ) as $item ) {
			$parts = explode( self::VALUE_SEPARATOR, $item, 2 );
			if ( 2 !== count( $parts ) || ! self::is_valid_token( $parts[0] ) || ! self::is_valid_token( $parts[1] ) ) {
				continue;
			}

			self::$groups[ $parts[0] ] = $parts[1];
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! headers_sent() ) {
			$memberships = array_keys( array_filter( self::$groups ) );
			header( 'X-Content-Gates-Local-Membership: ' . implode( ', ', $memberships ) );
		}
	}

	/**
	 * Register a cache group.
	 *
	 * @param string $group Group identifier.
	 * @return bool Whether the group was valid.
	 */
	public static function register_group( string $group ): bool {
		if ( ! self::is_valid_token( $group ) || did_action( 'send_headers' ) ) {
			return false;
		}

		if ( ! array_key_exists( $group, self::$groups ) ) {
			self::$groups[ $group ] = '';
		}

		return true;
	}

	/**
	 * Test one cache-group value.
	 *
	 * @param string $group Group identifier.
	 * @param string $value Membership value.
	 */
	public static function is_user_in_group_segment( string $group, string $value ): bool {
		return isset( self::$groups[ $group ] ) && $value === self::$groups[ $group ];
	}

	/**
	 * Update one cache-group value and response cookie.
	 *
	 * @param string $group Group identifier.
	 * @param string $value Membership value, or empty to remove.
	 * @return true|WP_Error True on success, otherwise an error.
	 */
	public static function set_group_for_user( string $group, string $value ) {
		if ( did_action( 'send_headers' ) || headers_sent() ) {
			return new WP_Error( 'content_gates_assignment_too_late', __( 'Membership must be assigned before headers are sent.', 'content-gates' ) );
		}

		if ( ! array_key_exists( $group, self::$groups ) ) {
			return new WP_Error( 'content_gates_segment_not_registered', __( 'Segment is not registered for this request.', 'content-gates' ) );
		}

		if ( '' !== $value && ! self::is_valid_token( $value ) ) {
			return new WP_Error( 'content_gates_invalid_membership_value', __( 'Membership value is invalid.', 'content-gates' ) );
		}

		self::$groups[ $group ] = $value;
		$cookie                 = self::serialize();
		$expires                = '' === $cookie ? time() - HOUR_IN_SECONDS : time() + MONTH_IN_SECONDS;

		setcookie( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Local-only compatibility adapter.
			self::COOKIE,
			$cookie,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::COOKIE ] = $cookie; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Keep local request state consistent after writing.

		// Batcache cannot honor the prototype's late consulted-set signal. Bypass
		// it only for local members so their next request reaches this adapter.
		setcookie( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Local-only compatibility adapter.
			self::LOCAL_CACHE_BYPASS_COOKIE,
			'' === $cookie ? '' : '1',
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		return true;
	}

	/**
	 * Reset request state for tests.
	 */
	public static function reset(): void {
		self::$groups = array();
	}

	/**
	 * Serialize non-empty memberships in Vary_Cache's wire format.
	 */
	private static function serialize(): string {
		$groups = array_filter( self::$groups, static fn ( string $value ): bool => '' !== $value );
		if ( array() === $groups ) {
			return '';
		}

		ksort( $groups );
		$items = array();
		foreach ( $groups as $group => $value ) {
			$items[] = $group . self::VALUE_SEPARATOR . $value;
		}

		return self::PREFIX . implode( self::GROUP_SEPARATOR, $items );
	}

	/**
	 * Validate a Vary_Cache token.
	 *
	 * @param string $value Token value.
	 */
	private static function is_valid_token( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $value );
	}
}
