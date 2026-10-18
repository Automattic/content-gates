<?php
/**
 * Test double for WPVIP's Vary_Cache.
 *
 * Mirrors the static API and ordering rules of
 * vip-go-mu-plugins/cache/class-vary-cache.php closely enough to test the
 * adapter: register sets '', parse overwrites with cookie values, writes
 * fail after send_headers(). It never calls header() or setcookie().
 *
 * Loaded only by tests/unit/test-vary-cache-adapter.php, and only when the
 * real class is absent.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Doubles VIP's class; the namespace, class and hook names are theirs.

namespace Automattic\VIP\Cache;

use WP_Error;

/**
 * Fake Vary_Cache.
 */
class Vary_Cache {

	/**
	 * Marks this as the test double so the adapter test can skip on VIP.
	 */
	public const CONTENT_GATES_FAKE = true;

	public const COOKIE_SEGMENT  = 'vip-go-seg';
	public const GROUP_SEPARATOR = '---__';
	public const VALUE_SEPARATOR = '_--_';
	public const VERSION_PREFIX  = 'vc-v1__';

	/**
	 * Group => value.
	 *
	 * @var array<string, string>
	 */
	private static array $groups = array();

	/**
	 * Whether send_headers() ran.
	 *
	 * @var bool
	 */
	private static bool $did_send_headers = false;

	/**
	 * Whether a write happened since parsing.
	 *
	 * @var bool
	 */
	private static bool $should_update_group_cookie = false;

	/**
	 * The cookie value send_headers() would have written, for assertions.
	 *
	 * @var string|null
	 */
	private static ?string $written_cookie = null;

	/**
	 * Mirror of Vary_Cache::load().
	 */
	public static function load(): void {
		self::$groups = array();
		add_action( 'init', array( self::class, 'parse_cookies' ) );
		add_action( 'send_headers', array( self::class, 'send_headers' ), PHP_INT_MAX );
	}

	/**
	 * Mirror of Vary_Cache::unload().
	 */
	public static function unload(): void {
		remove_action( 'init', array( self::class, 'parse_cookies' ) );
		remove_action( 'send_headers', array( self::class, 'send_headers' ), PHP_INT_MAX );
		self::reset();
	}

	/**
	 * Clear all static state. Test-only.
	 */
	public static function reset(): void {
		self::$groups                     = array();
		self::$did_send_headers           = false;
		self::$should_update_group_cookie = false;
		self::$written_cookie             = null;
	}

	/**
	 * Register groups. Sets each to '' even when already parsed, as VIP does.
	 *
	 * @param string[] $groups Group names.
	 */
	public static function register_groups( array $groups ): bool {
		if ( self::$did_send_headers ) {
			return false;
		}

		foreach ( $groups as $group ) {
			if ( is_wp_error( self::validate_cookie_value( $group ) ) ) {
				continue;
			}
			self::$groups[ $group ] = '';
		}

		return true;
	}

	/**
	 * Register one group.
	 *
	 * @param string $group Group name.
	 */
	public static function register_group( string $group ): bool {
		return self::register_groups( array( $group ) );
	}

	/**
	 * Set a group value. Same error codes as VIP.
	 *
	 * @param string $group Group name.
	 * @param string $value Value.
	 * @return bool|WP_Error
	 */
	public static function set_group_for_user( $group, $value ) {
		if ( self::$did_send_headers ) {
			return new WP_Error( 'did_send_headers', 'Failed to set group; cannot be called after the `send_headers` hook has fired.' );
		}

		if ( is_wp_error( self::validate_cookie_value( $group ) ) ) {
			return new WP_Error( 'invalid_vary_group_name', 'Invalid group name.' );
		}

		if ( is_wp_error( self::validate_cookie_value( $value ) ) ) {
			return new WP_Error( 'invalid_vary_group_segment', 'Invalid group segment.' );
		}

		if ( ! array_key_exists( $group, self::$groups ) ) {
			return new WP_Error( 'invalid_vary_group_notregistered', 'Must register the group first.' );
		}

		self::$groups[ $group ]           = $value;
		self::$should_update_group_cookie = true;

		return true;
	}

	/**
	 * Whether the group has any value.
	 *
	 * @param string $group Group name.
	 */
	public static function is_user_in_group( $group ): bool {
		return array_key_exists( $group, self::$groups ) && '' !== trim( self::$groups[ $group ] );
	}

	/**
	 * Whether the group has this exact value.
	 *
	 * @param string $group   Group name.
	 * @param string $segment Value.
	 */
	public static function is_user_in_group_segment( $group, $segment ): bool {
		return self::is_user_in_group( $group ) && self::$groups[ $group ] === $segment;
	}

	/**
	 * All groups.
	 *
	 * @return array<string, string>
	 */
	public static function get_groups(): array {
		return self::$groups;
	}

	/**
	 * Parse the segmentation cookie, overwriting registered values.
	 */
	public static function parse_cookies(): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput -- Test double of VIP's own cookie read.
		$cookie = $_COOKIE[ self::COOKIE_SEGMENT ] ?? '';
		$raw    = is_string( $cookie ) ? sanitize_text_field( wp_unslash( $cookie ) ) : '';
		if ( '' === $raw ) {
			return;
		}

		$raw = str_replace( self::VERSION_PREFIX, '', $raw );
		foreach ( explode( self::GROUP_SEPARATOR, $raw ) as $pair ) {
			if ( '' === $pair || ! str_contains( $pair, self::VALUE_SEPARATOR ) ) {
				continue;
			}
			list( $name, $value )  = explode( self::VALUE_SEPARATOR, $pair, 2 );
			self::$groups[ $name ] = $value;
		}
	}

	/**
	 * Mark headers sent and fire the did-send action when a write is pending.
	 */
	public static function send_headers(): void {
		if ( self::$did_send_headers ) {
			return;
		}

		self::$did_send_headers = true;

		$sent_vary   = array() !== self::$groups;
		$sent_cookie = false;

		if ( self::$should_update_group_cookie ) {
			$sent_cookie          = true;
			self::$written_cookie = self::stringify_groups();
		}

		if ( $sent_vary || $sent_cookie ) {
			do_action( 'vip_vary_cache_did_send_headers', $sent_vary, $sent_cookie );
		}
	}

	/**
	 * Whether send_headers() ran. Test-only.
	 */
	public static function did_send_headers(): bool {
		return self::$did_send_headers;
	}

	/**
	 * The cookie value the last send_headers() produced. Test-only.
	 */
	public static function written_cookie(): ?string {
		return self::$written_cookie;
	}

	/**
	 * Serialize groups in VIP's format.
	 */
	private static function stringify_groups(): string {
		ksort( self::$groups );
		$parts = array();
		foreach ( self::$groups as $key => $value ) {
			if ( '' === trim( $value ) ) {
				continue;
			}
			$parts[] = $key . self::VALUE_SEPARATOR . $value;
		}

		return array() === $parts ? '' : self::VERSION_PREFIX . implode( self::GROUP_SEPARATOR, $parts );
	}

	/**
	 * VIP's character rule.
	 *
	 * @param string $value Group or value.
	 * @return true|WP_Error
	 */
	private static function validate_cookie_value( string $value ): bool|WP_Error {
		if ( preg_match( '/[^_0-9a-zA-Z-]+/', $value ) > 0 ) {
			return new WP_Error( 'vary_cache_group_invalid_chars', 'Invalid character(s).' );
		}

		return true;
	}
}
