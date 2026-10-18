<?php
/**
 * Local cookie simulation of Vary_Cache.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

use ContentGates\Rendering\Headers;
use WP_Error;

/**
 * Reproduces the `Vary_Cache` cookie contract when VIP's class is absent.
 *
 * Used in local wp-env sites and CI. It keeps the same cookie name, value
 * format, parse timing (`init` 10) and write timing (`send_headers`
 * PHP_INT_MAX) as `Vary_Cache`, so provider code written against VIP works
 * unchanged. It also fires `vip_vary_cache_did_send_headers` so the
 * POST-redirect-GET pattern from VIP's README works.
 *
 * Known variances from `Vary_Cache`, on purpose:
 *
 * - Never sends `Vary: X-VIP-Go-Segmentation`. The response signal is
 *   `X-Content-Gates-Vary`, computed after render.
 * - Sends `X-Content-Gates-Simulation: local` so a tester can tell which
 *   adapter produced a response.
 * - When every group is removed, the cookie is cleared (expired). Real
 *   `Vary_Cache` leaves the stale cookie in place in that case.
 * - `register_group()` keeps a value that was already parsed. Real
 *   `Vary_Cache` resets it to ''. Registration normally runs before
 *   parsing, so the two only differ for late registrations.
 * - Reads membership from the `X-VIP-Go-Segmentation` request header
 *   (`HTTP_X_VIP_GO_SEGMENTATION`) before the cookie. Real `Vary_Cache`
 *   never reads that header outside its encrypted mode. Locally any
 *   client can send it, so it is a forgery vector equal to the cookie
 *   itself: a local site must not trust membership for authorization.
 * - Passes the raw header or cookie value through `sanitize_text_field()`
 *   before parsing. Real `Vary_Cache` parses the raw value. Valid tokens
 *   contain only `[A-Za-z0-9_-]`, so the two agree on every value the
 *   parser would accept.
 *
 * @since 0.1.0
 */
final class Local_Cookie_Adapter implements Cache_Adapter {

	public const COOKIE_NAME     = 'vip-go-seg';
	public const HEADER_NAME     = 'HTTP_X_VIP_GO_SEGMENTATION';
	public const VERSION_PREFIX  = 'vc-v1__';
	public const GROUP_SEPARATOR = '---__';
	public const VALUE_SEPARATOR = '_--_';

	/**
	 * Allowed characters for group names and values, as in `Vary_Cache`.
	 */
	public const VALID_PATTERN = '/^[A-Za-z0-9_-]+$/';

	/**
	 * Group => value. '' means registered but not a member.
	 *
	 * @var array<string, string>
	 */
	private array $groups = array();

	/**
	 * Whether a value changed since parsing, so the cookie must be written.
	 *
	 * @var bool
	 */
	private bool $changed = false;

	/**
	 * Whether the write step already ran.
	 *
	 * @var bool
	 */
	private bool $did_send_headers = false;

	/**
	 * The cookie the last write step produced, or null when none was needed.
	 *
	 * @var array{name: string, value: string, expires: int, path: string, domain: string}|null
	 */
	private ?array $pending_cookie = null;

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'parse_request' ), 10 );
		add_action( 'send_headers', array( $this, 'flush' ), PHP_INT_MAX );
	}

	/**
	 * Register a group. Keeps any value already parsed for it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Group name.
	 */
	public function register_group( string $group ): void {
		if ( ! self::is_valid_token( $group ) ) {
			return;
		}

		if ( ! array_key_exists( $group, $this->groups ) ) {
			$this->groups[ $group ] = '';
		}
	}

	/**
	 * Set or remove ('') the reader's value for a group.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Group name.
	 * @param string $value Value, or '' to remove.
	 * @return true|WP_Error
	 */
	public function set_group( string $group, string $value ): bool|WP_Error {
		if ( $this->did_send_headers ) {
			return new WP_Error(
				'content_gates_too_late',
				sprintf( 'Cannot set group "%s": the segmentation cookie was already written for this request.', $group )
			);
		}

		if ( ! self::is_valid_token( $group ) ) {
			return new WP_Error(
				'content_gates_invalid_segment_key',
				sprintf( 'Group name "%s" may only contain A-Z, a-z, 0-9, "_" and "-".', $group )
			);
		}

		if ( '' !== $value && ! self::is_valid_token( $value ) ) {
			return new WP_Error(
				'content_gates_invalid_value',
				sprintf( 'Value "%s" may only contain A-Z, a-z, 0-9, "_" and "-".', $value )
			);
		}

		if ( ! array_key_exists( $group, $this->groups ) ) {
			return new WP_Error(
				'content_gates_not_registered',
				sprintf( 'Group "%s" must be registered before it is assigned.', $group )
			);
		}

		if ( $this->groups[ $group ] !== $value ) {
			$this->groups[ $group ] = $value;
			$this->changed          = true;
		}

		return true;
	}

	/**
	 * Registered groups with values.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return $this->groups;
	}

	/**
	 * Whether the write step already ran.
	 *
	 * @since 0.1.0
	 */
	public function did_send_headers(): bool {
		return $this->did_send_headers;
	}

	/**
	 * This adapter is the local simulation.
	 *
	 * @since 0.1.0
	 */
	public function is_local_simulation(): bool {
		return true;
	}

	/**
	 * Read membership from the request. Runs at `init` priority 10.
	 *
	 * Prefers the `X-VIP-Go-Segmentation` request header (what the VIP edge
	 * forwards) and falls back to the `vip-go-seg` cookie. Parsed values
	 * overwrite registered groups; groups registered earlier but absent
	 * from the request stay registered with ''.
	 *
	 * @since 0.1.0
	 */
	public function parse_request(): void {
		$raw = '';

		if ( isset( $_SERVER[ self::HEADER_NAME ] ) && is_string( $_SERVER[ self::HEADER_NAME ] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_SERVER[ self::HEADER_NAME ] ) );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput -- This is the segmentation cookie itself, read exactly as Vary_Cache reads it; sanitized on the next line.
			$cookie = $_COOKIE[ self::COOKIE_NAME ] ?? '';
			$raw    = is_string( $cookie ) ? sanitize_text_field( wp_unslash( $cookie ) ) : '';
		}

		if ( '' === $raw ) {
			return;
		}

		foreach ( self::parse( $raw ) as $group => $value ) {
			$this->groups[ $group ] = $value;
		}
	}

	/**
	 * Write the cookie and simulation header now. Idempotent.
	 *
	 * Runs at `send_headers` PHP_INT_MAX, or earlier when a REST handler
	 * needs the `Set-Cookie` on its own response.
	 *
	 * @since 0.1.0
	 */
	public function flush(): void {
		if ( $this->did_send_headers ) {
			return;
		}

		$this->did_send_headers = true;

		Headers::send( 'X-Content-Gates-Simulation', 'local' );

		$sent_cookie = false;
		if ( $this->changed ) {
			$sent_cookie = true;
			$this->write_cookie();
		}

		if ( $sent_cookie ) {
			/**
			 * Mirrors Vary_Cache's action so provider redirect code works
			 * against the local simulation.
			 *
			 * The first argument is always false here: the simulation never
			 * sends a `Vary` header.
			 *
			 * @since 0.1.0
			 *
			 * @param bool $sent_vary   Always false in the local simulation.
			 * @param bool $sent_cookie Whether a Set-Cookie header was sent.
			 */
			do_action( 'vip_vary_cache_did_send_headers', false, $sent_cookie ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mirrors Vary_Cache's hook on purpose so provider code is portable.
		}
	}

	/**
	 * The cookie the last `flush()` produced, or null when nothing changed.
	 *
	 * Recorded even when `headers_sent()` prevented the real write, so
	 * tests can assert on it.
	 *
	 * @since 0.1.0
	 *
	 * @return array{name: string, value: string, expires: int, path: string, domain: string}|null
	 */
	public function pending_cookie(): ?array {
		return $this->pending_cookie;
	}

	/**
	 * Serialize groups in the `Vary_Cache` cookie format.
	 *
	 * Groups are sorted by name; empty values are dropped. Returns '' when
	 * no group has a value.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $groups Group => value.
	 */
	public static function serialize( array $groups ): string {
		ksort( $groups, SORT_STRING );

		$parts = array();
		foreach ( $groups as $group => $value ) {
			if ( '' === trim( $value ) ) {
				continue;
			}
			$parts[] = $group . self::VALUE_SEPARATOR . $value;
		}

		if ( array() === $parts ) {
			return '';
		}

		return self::VERSION_PREFIX . implode( self::GROUP_SEPARATOR, $parts );
	}

	/**
	 * Parse a cookie value in the `Vary_Cache` format.
	 *
	 * Tolerant like `Vary_Cache`: the version prefix is optional, and
	 * malformed segments are skipped.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Raw cookie or header value.
	 * @return array<string, string> Group => value.
	 */
	public static function parse( string $value ): array {
		$value  = str_replace( self::VERSION_PREFIX, '', $value );
		$groups = array();

		foreach ( explode( self::GROUP_SEPARATOR, $value ) as $pair ) {
			if ( '' === $pair || ! str_contains( $pair, self::VALUE_SEPARATOR ) ) {
				continue;
			}

			list( $group, $group_value ) = explode( self::VALUE_SEPARATOR, $pair, 2 );
			if ( ! self::is_valid_token( $group ) ) {
				continue;
			}
			if ( '' !== $group_value && ! self::is_valid_token( $group_value ) ) {
				continue;
			}

			$groups[ $group ] = $group_value;
		}

		return $groups;
	}

	/**
	 * Whether a group name or value can live in the cookie.
	 *
	 * Same rule as `Vary_Cache`: only `[A-Za-z0-9_-]`, and never a string
	 * that contains one of the cookie delimiters.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token Group name or value.
	 */
	public static function is_valid_token( string $token ): bool {
		if ( 1 !== preg_match( self::VALID_PATTERN, $token ) ) {
			return false;
		}

		return ! str_contains( $token, self::VALUE_SEPARATOR ) && ! str_contains( $token, self::GROUP_SEPARATOR );
	}

	/**
	 * Record and (when possible) send the cookie.
	 */
	private function write_cookie(): void {
		$value = self::serialize( $this->groups );

		/**
		 * Filters the simulated segmentation cookie lifetime in seconds.
		 *
		 * @since 0.1.0
		 *
		 * @param int $expiry Seconds until the cookie expires. Default one month.
		 */
		$expiry = (int) apply_filters( 'content_gates_cookie_expiry', MONTH_IN_SECONDS );

		// An empty serialization means every group was removed: clear the cookie.
		$expires = '' === $value ? time() - HOUR_IN_SECONDS : time() + $expiry;

		$path   = defined( 'COOKIEPATH' ) ? (string) constant( 'COOKIEPATH' ) : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? (string) constant( 'COOKIE_DOMAIN' ) : '';

		$this->pending_cookie = array(
			'name'    => self::COOKIE_NAME,
			'value'   => $value,
			'expires' => $expires,
			'path'    => $path,
			'domain'  => $domain,
		);

		if ( ! headers_sent() ) {
			// setrawcookie() as in Vary_Cache: values only contain cookie-safe characters.
			setrawcookie( self::COOKIE_NAME, $value, $expires, $path, $domain );
		}
	}
}
