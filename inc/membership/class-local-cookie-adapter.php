<?php
/**
 * Local-only cache membership adapter.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

use WP_Error;

/**
 * Plaintext prototype adapter for local development without VIP MU plugins.
 */
final class Local_Cookie_Adapter implements Cache_Adapter {

	private const COOKIE_NAME     = 'vip-go-seg';
	private const VERSION_PREFIX  = 'vc-v1__';
	private const GROUP_SEPARATOR = '---__';
	private const VALUE_SEPARATOR = '_--_';

	/**
	 * Registered cache groups.
	 *
	 * @var array<string, true>
	 */
	private array $registered = array();

	/**
	 * Current reader membership.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $groups = null;

	/**
	 * Register a cache group for the current request.
	 *
	 * @param string $group_key Stable cache group key.
	 * @return true|WP_Error
	 */
	public function register_group( string $group_key ) {
		if ( ! $this->is_valid_value( $group_key ) ) {
			return new WP_Error(
				'content_gates_invalid_group_key',
				__( 'The cache group key is invalid.', 'content-gates' )
			);
		}

		$this->registered[ $group_key ] = true;
		return true;
	}

	/**
	 * Test current reader membership.
	 *
	 * @param string $group_key Stable cache group key.
	 */
	public function is_reader_in_group( string $group_key ): bool {
		$this->load_cookie();
		return '1' === ( $this->groups[ $group_key ] ?? '' );
	}

	/**
	 * Set or remove current reader membership.
	 *
	 * @param string $group_key Stable cache group key.
	 * @param bool   $member    Whether the reader is a member.
	 * @return true|WP_Error
	 */
	public function set_reader_membership( string $group_key, bool $member ) {
		if ( ! isset( $this->registered[ $group_key ] ) ) {
			return new WP_Error(
				'content_gates_group_not_registered',
				__( 'The cache group must be registered before assignment.', 'content-gates' )
			);
		}

		$this->load_cookie();
		if ( $member ) {
			$this->groups[ $group_key ] = '1';
		} else {
			unset( $this->groups[ $group_key ] );
		}

		$this->write_cookie();
		return true;
	}

	/**
	 * Confirm that the local adapter already wrote its cookie.
	 *
	 * @return true
	 */
	public function flush_membership_headers() {
		return true;
	}

	/**
	 * Parse the plaintext prototype cookie once.
	 */
	private function load_cookie(): void {
		if ( null !== $this->groups ) {
			return;
		}

		$this->groups = array();
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Local prototype reads the platform-format membership cookie.
		$raw = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) : '';
		if ( 0 !== strpos( $raw, self::VERSION_PREFIX ) ) {
			return;
		}

		$payload = substr( $raw, strlen( self::VERSION_PREFIX ) );
		foreach ( explode( self::GROUP_SEPARATOR, $payload ) as $encoded_group ) {
			$parts = explode( self::VALUE_SEPARATOR, $encoded_group, 2 );
			if ( 2 !== count( $parts ) || ! $this->is_valid_value( $parts[0] ) || ! $this->is_valid_value( $parts[1] ) ) {
				continue;
			}

			$this->groups[ $parts[0] ] = $parts[1];
		}
	}

	/**
	 * Write current local membership to the response and request state.
	 */
	private function write_cookie(): void {
		$groups = $this->groups ?? array();
		ksort( $groups, SORT_STRING );
		$pairs = array();
		foreach ( $groups as $group_key => $value ) {
			$pairs[] = $group_key . self::VALUE_SEPARATOR . $value;
		}
		$this->groups = $groups;

		$value = array() === $pairs ? '' : self::VERSION_PREFIX . implode( self::GROUP_SEPARATOR, $pairs );
		if ( '' === $value ) {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Keep local request state aligned with the response cookie.
			unset( $_COOKIE[ self::COOKIE_NAME ] );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Keep local request state aligned with the response cookie.
			$_COOKIE[ self::COOKIE_NAME ] = $value;
		}

		$expiry = '' === $value ? time() - HOUR_IN_SECONDS : time() + MONTH_IN_SECONDS;
		$path   = defined( 'COOKIEPATH' ) ? (string) constant( 'COOKIEPATH' ) : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? (string) constant( 'COOKIE_DOMAIN' ) : '';
		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Local prototype must emulate Vary_Cache cookie writes.
			setrawcookie( self::COOKIE_NAME, $value, $expiry, $path, $domain );
		}
	}

	/**
	 * Match the Vary_Cache cookie character contract.
	 *
	 * @param string $value Cookie group or value.
	 */
	private function is_valid_value( string $value ): bool {
		return '' !== $value && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $value );
	}
}
