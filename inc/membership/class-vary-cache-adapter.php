<?php
/**
 * WPVIP Vary_Cache adapter.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

use ReflectionMethod;
use WP_Error;

/**
 * Delegates cache membership to the VIP platform API.
 */
final class Vary_Cache_Adapter implements Cache_Adapter {

	private const PROVIDER_CLASS = '\\Automattic\\VIP\\Cache\\Vary_Cache';

	/**
	 * Whether a successful removal needs a final cookie check.
	 *
	 * @var bool
	 */
	private bool $removal_pending = false;

	/**
	 * Whether the platform API is loaded.
	 */
	public static function is_available(): bool {
		return class_exists( self::PROVIDER_CLASS );
	}

	/**
	 * Register a cache group for the current request.
	 *
	 * @param string $group_key Stable cache group key.
	 * @return true|WP_Error
	 */
	public function register_group( string $group_key ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}

		$result = $this->call_provider( 'register_group', $group_key );
		if ( true !== $result ) {
			return new WP_Error(
				'content_gates_registration_failed',
				__( 'The cache group could not be registered.', 'content-gates' )
			);
		}

		return true;
	}

	/**
	 * Test current reader membership.
	 *
	 * @param string $group_key Stable cache group key.
	 */
	public function is_reader_in_group( string $group_key ): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		return true === $this->call_provider( 'is_user_in_group_segment', $group_key, '1' );
	}

	/**
	 * Set or remove current reader membership.
	 *
	 * @param string $group_key Stable cache group key.
	 * @param bool   $member    Whether the reader is a member.
	 * @return true|WP_Error
	 */
	public function set_reader_membership( string $group_key, bool $member ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}

		$result = $this->call_provider( 'set_group_for_user', $group_key, $member ? '1' : '' );
		if ( is_wp_error( $result ) && 'did_send_headers' === $result->get_error_code() ) {
			return new WP_Error(
				'content_gates_assignment_too_late',
				__( 'Reader membership cannot change after headers are sent.', 'content-gates' )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			return new WP_Error(
				'content_gates_assignment_failed',
				__( 'Reader membership could not be changed.', 'content-gates' )
			);
		}

		if ( $member ) {
			$this->removal_pending = false;
			remove_action( 'send_headers', array( $this, 'finalize_membership_removal' ), PHP_INT_MAX - 1 );
		} else {
			$this->removal_pending = true;
			add_action( 'send_headers', array( $this, 'finalize_membership_removal' ), PHP_INT_MAX - 1 );
		}

		return true;
	}

	/**
	 * Send a membership cookie on request paths that exit before send_headers.
	 *
	 * WordPress REST dispatch exits during parse_request. The platform's normal
	 * send_headers hook does not run on that path, so the endpoint must flush the
	 * queued Vary_Cache update before it returns.
	 *
	 * @return true|WP_Error
	 */
	public function flush_membership_headers() {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}

		$this->finalize_membership_removal();
		$this->call_provider( 'send_headers' );
		return true;
	}

	/**
	 * Expire stale cookies if a completed removal left no membership.
	 *
	 * This runs immediately before the platform's late send_headers callback,
	 * after Vary_Cache has parsed the incoming cookie on init.
	 */
	public function finalize_membership_removal(): void {
		if ( ! $this->removal_pending ) {
			return;
		}

		$this->removal_pending = false;
		remove_action( 'send_headers', array( $this, 'finalize_membership_removal' ), PHP_INT_MAX - 1 );
		if ( ! $this->has_membership() ) {
			$this->expire_membership_cookies();
		}
	}

	/**
	 * Build the provider availability error.
	 */
	private function unavailable_error(): WP_Error {
		return new WP_Error(
			'content_gates_provider_unavailable',
			__( 'The cache personalization provider is not available.', 'content-gates' )
		);
	}

	/**
	 * Check whether Vary_Cache still holds any non-empty membership.
	 */
	private function has_membership(): bool {
		$groups = $this->call_provider( 'get_groups' );
		if ( ! is_array( $groups ) ) {
			return true;
		}

		foreach ( $groups as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clear stale platform cookies after the final membership is removed.
	 *
	 * Vary_Cache currently skips its cookie writer when its serialized group
	 * value is empty. Expiring both supported cookie names prevents a removed
	 * final membership from returning on the next request.
	 */
	private function expire_membership_cookies(): void {
		// These filters are part of the WPVIP platform API.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$default_path   = defined( 'COOKIEPATH' ) ? (string) constant( 'COOKIEPATH' ) : '/';
		$default_domain = defined( 'COOKIE_DOMAIN' ) ? (string) constant( 'COOKIE_DOMAIN' ) : '';
		$path           = (string) apply_filters( 'vip_vary_cache_cookie_path', $default_path );
		$domain         = (string) apply_filters( 'vip_vary_cache_cookie_domain', $default_domain );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		foreach ( array( 'vip-go-seg', 'vip-go-auth' ) as $cookie_name ) {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Keep request state aligned with the expired platform cookie.
			unset( $_COOKIE[ $cookie_name ] );
			if ( ! headers_sent() ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Compatibility fix for the platform membership cookie.
				setrawcookie( $cookie_name, '', time() - HOUR_IN_SECONDS, $path, $domain );
			}
		}
	}

	/**
	 * Call a static platform method without requiring the VIP class locally.
	 *
	 * Availability checks establish the method before this call.
	 *
	 * @param string $method Platform method name.
	 * @param mixed  ...$arguments Platform method arguments.
	 * @return mixed
	 */
	private function call_provider( string $method, ...$arguments ) {
		$reflection = new ReflectionMethod( self::PROVIDER_CLASS, $method );
		return $reflection->invoke( null, ...$arguments );
	}
}
