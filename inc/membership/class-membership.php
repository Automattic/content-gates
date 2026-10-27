<?php
/**
 * Membership reads and writes.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Membership;

use Automattic\VIP\Cache\Vary_Cache;
use Automattic\VIP\ContentGates\Headers;
use Automattic\VIP\ContentGates\Platform\Platform;
use Automattic\VIP\ContentGates\Segments\Segment;
use Automattic\VIP\ContentGates\Segments\Segment_Registry;
use WP_Error;

/**
 * The one door to segment membership.
 *
 * Writes go to `Vary_Cache`, which writes the `vip-go-seg` cookie at
 * `send_headers`. Reads come from the membership `Vary_Cache` parsed at
 * `init` priority 10, filtered through the declared set: a segment that is
 * not declared for this response reads as `false`, whatever the cookie says.
 *
 * Timing (see spec/api.md):
 *
 * - `init` 5: every segment's group is registered with `Vary_Cache`. This
 *   must stay before priority 10, because `register_groups()` resets each
 *   group to an empty value and would erase parsed membership.
 * - `init` 10: `Vary_Cache` parses the cookie.
 * - `init` > 10 up to `send_headers`: providers assign or remove.
 * - `send_headers` `PHP_INT_MAX - 1`: writes close. `Vary_Cache` sends the
 *   cookie at `PHP_INT_MAX`.
 * - REST: `send_headers` never fires, so writes close and `Vary_Cache`
 *   sends `Vary` and the cookie when the declared set is sent.
 *
 * Writes are idempotent: assigning a value the reader already has, or
 * removing a group they are not in, changes nothing and sends no cookie.
 * Writes are refused on a request the Edge Worker narrowed: the cookie
 * origin holds is only this URL's recorded groups, and `Vary_Cache` would
 * write that narrowed set back to the browser, dropping the reader's other
 * groups. Providers write from POSTs, logged-in requests, or their own
 * endpoints (spec/api.md). Removing the reader's last group expires the
 * cookie, which upstream `Vary_Cache` does not do by itself.
 *
 * @since 0.1.0
 */
final class Membership {

	/**
	 * Request header the Edge Worker sets with its lookup marker; `narrowed`
	 * means the forwarded cookie holds only this URL's recorded groups.
	 */
	public const LOOKUP_HEADER = 'HTTP_X_WPVIP_CONTENT_GATES_LOOKUP';

	public const METER_OPEN = 'open';
	public const METER_LAST = 'last';
	public const METER_WALL = 'wall';

	/**
	 * Whether the write window has closed.
	 *
	 * @var bool
	 */
	private bool $writes_closed = false;

	/**
	 * Whether a write happened this request.
	 *
	 * @var bool
	 */
	private bool $dirty = false;

	/**
	 * Debug override of membership (the admin-bar View As switcher).
	 *
	 * Group name => value. Null when not active.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $override = null;

	/**
	 * Constructor.
	 *
	 * @param Platform         $platform Platform loader.
	 * @param Segment_Registry $segments Segment lookup.
	 * @param Declared_Set     $declared Declared set for this response.
	 */
	public function __construct(
		private Platform $platform,
		private Segment_Registry $segments,
		private Declared_Set $declared
	) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_groups' ), 5 );
		add_action( 'send_headers', array( $this, 'close_writes' ), PHP_INT_MAX - 1 );
		add_action( 'content_gates_rest_headers_sent', array( $this, 'send_rest_headers' ) );
		add_action( 'vip_vary_cache_did_send_headers', array( $this, 'expire_empty_cookie' ), 10, 2 );
	}

	/**
	 * Configure `Vary_Cache` and register every segment as a group. Action callback for `init` 5.
	 *
	 * Inactive segments are registered too so a provider can clear a stale
	 * cookie value for them. Registration alone costs no variants: the edge
	 * narrows the key to the declared set.
	 *
	 * @since 0.1.0
	 */
	public function register_groups(): void {
		if ( ! $this->platform->is_available() ) {
			return;
		}

		/**
		 * Filters the segmentation cookie lifetime in seconds.
		 *
		 * Membership is set once and removed explicitly, so the lifetime is
		 * the bound on a lapsed entitlement and the interval at which a valid
		 * reader is re-set. `Vary_Cache` refuses values under one hour.
		 *
		 * @since 0.1.0
		 *
		 * @param int $lifetime Lifetime in seconds. Default one day.
		 */
		$lifetime = (int) apply_filters( 'content_gates_cookie_lifetime', DAY_IN_SECONDS );
		Vary_Cache::set_cookie_expiry( max( HOUR_IN_SECONDS, $lifetime ) );

		$groups = array();
		foreach ( $this->segments->all() as $segment ) {
			$groups[] = $segment->group_name;
		}

		if ( array() !== $groups ) {
			Vary_Cache::register_groups( $groups );
		}
	}

	/**
	 * Close the write window. Action callback for `send_headers`.
	 *
	 * @since 0.1.0
	 */
	public function close_writes(): void {
		$this->writes_closed = true;
	}

	/**
	 * Send `Vary` and any pending cookie on a REST response.
	 *
	 * `Vary_Cache::send_headers()` is idempotent and fires
	 * `vip_vary_cache_did_send_headers` when it sent something.
	 *
	 * @since 0.1.0
	 */
	public function send_rest_headers(): void {
		$this->writes_closed = true;

		if ( $this->platform->is_available() ) {
			Vary_Cache::send_headers();
		}
	}

	/**
	 * Assign the reader to a segment.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string  $reference Segment key or post ID.
	 * @param string|null $value     Value; `1` for a boolean segment.
	 * @return true|WP_Error
	 */
	public function assign( int|string $reference, ?string $value = null ): true|WP_Error {
		$gate = $this->write_gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$segment = $this->segments->resolve( $reference );
		if ( null === $segment ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
		}
		if ( $segment->is_meter() ) {
			return new WP_Error( 'content_gates_invalid_value', __( 'Meter values are written only by the Edge Worker.', 'content-gates' ) );
		}
		if ( ! $segment->active ) {
			return new WP_Error( 'content_gates_inactive_segment', __( 'The segment is inactive.', 'content-gates' ) );
		}

		$value ??= '1';
		if ( 1 !== preg_match( Segment::TOKEN_PATTERN, $value ) ) {
			return new WP_Error( 'content_gates_invalid_value', __( 'Values may contain only letters, digits, underscore, and hyphen.', 'content-gates' ) );
		}

		return $this->write( $segment, $value );
	}

	/**
	 * Remove the reader from a segment. Allowed for inactive segments.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $reference Segment key or post ID.
	 * @return true|WP_Error
	 */
	public function remove( int|string $reference ): true|WP_Error {
		$gate = $this->write_gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$segment = $this->segments->resolve( $reference );
		if ( null === $segment ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
		}

		return $this->write( $segment, '' );
	}

	/**
	 * Whether the reader is in a segment.
	 *
	 * Without a value: any value on a boolean segment, `open` or `last` on a
	 * meter. With a value: that exact value. Undeclared, unknown, and
	 * inactive segments read as `false`.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string  $reference Segment key or post ID.
	 * @param string|null $value     Exact value to test, or null.
	 */
	public function is_in( int|string $reference, ?string $value = null ): bool {
		$segment = $this->readable_segment( $reference, 'is_reader_in_segment' );
		if ( null === $segment ) {
			return false;
		}

		$current = $this->raw_value( $segment );
		if ( null === $current ) {
			return false;
		}

		if ( null !== $value ) {
			return $current === $value;
		}

		if ( $segment->is_meter() ) {
			return in_array( $current, array( self::METER_OPEN, self::METER_LAST ), true );
		}

		return true;
	}

	/**
	 * The reader's value for a segment, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $reference Segment key or post ID.
	 */
	public function value( int|string $reference ): ?string {
		$segment = $this->readable_segment( $reference, 'get_reader_segment_value' );
		if ( null === $segment ) {
			return null;
		}

		return $this->raw_value( $segment );
	}

	/**
	 * Replace membership for this request with a debug override.
	 *
	 * Used by the View As switcher for users who may edit posts. Reads are
	 * still subject to the declared set, so editors see exactly what a reader
	 * with that membership sees.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $groups Group name => value.
	 */
	public function override( array $groups ): void {
		$this->override = $groups;
	}

	/**
	 * Whether the debug override is active.
	 *
	 * @since 0.1.0
	 */
	public function has_override(): bool {
		return null !== $this->override;
	}

	/**
	 * Whether a write happened this request.
	 *
	 * @since 0.1.0
	 */
	public function is_dirty(): bool {
		return $this->dirty;
	}

	/**
	 * Common checks for assign and remove.
	 *
	 * @return true|WP_Error
	 */
	private function write_gate(): true|WP_Error {
		if ( ! $this->platform->is_available() ) {
			return new WP_Error( 'content_gates_platform_unavailable', __( 'The cache personalization API is not loaded.', 'content-gates' ) );
		}
		if ( $this->writes_closed ) {
			return new WP_Error( 'content_gates_too_late', __( 'Membership can only change after init priority 10 and before send_headers.', 'content-gates' ) );
		}
		if ( self::request_is_narrowed() ) {
			return new WP_Error( 'content_gates_narrowed_request', __( 'The edge narrowed this request\'s cookie. Change membership from a POST, a logged-in request, or a provider endpoint instead.', 'content-gates' ) );
		}

		return true;
	}

	/**
	 * Whether the Edge Worker forwarded a narrowed cookie for this request.
	 *
	 * @since 0.1.0
	 */
	public static function request_is_narrowed(): bool {
		if ( ! isset( $_SERVER[ self::LOOKUP_HEADER ] ) ) {
			return false;
		}

		return 'narrowed' === sanitize_text_field( wp_unslash( $_SERVER[ self::LOOKUP_HEADER ] ) );
	}

	/**
	 * Write a group value through `Vary_Cache`.
	 *
	 * @param Segment $segment Segment.
	 * @param string  $value   Value; empty removes.
	 * @return true|WP_Error
	 */
	private function write( Segment $segment, string $value ): true|WP_Error {
		if ( ( $this->cookie_value( $segment ) ?? '' ) === $value ) {
			return true;
		}

		$result = Vary_Cache::set_group_for_user( $segment->group_name, $value );
		if ( is_wp_error( $result ) ) {
			$code = match ( $result->get_error_code() ) {
				'did_send_headers' => 'content_gates_too_late',
				'invalid_vary_group_segment' => 'content_gates_invalid_value',
				default => 'content_gates_platform_unavailable',
			};

			return new WP_Error( $code, $result->get_error_message() );
		}

		$this->dirty = true;

		return true;
	}

	/**
	 * Expire the segmentation cookie when a removal left no group behind.
	 *
	 * Action callback for `vip_vary_cache_did_send_headers`. Upstream
	 * `Vary_Cache` skips the cookie write when every group is empty, so a
	 * reader removed from their last segment would keep sending the old
	 * cookie and stay a member. This expires it instead, with the path and
	 * domain `Vary_Cache` used to set it. It only acts after an actual
	 * removal this request, so a reader without a cookie never receives one.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $sent_vary   Whether `Vary` was sent.
	 * @param bool $sent_cookie Whether `Vary_Cache` tried to write its cookie.
	 */
	public function expire_empty_cookie( bool $sent_vary, bool $sent_cookie ): void {
		unset( $sent_vary );

		if ( ! $sent_cookie || ! $this->dirty || ! $this->platform->is_available() ) {
			return;
		}

		foreach ( Vary_Cache::get_groups() as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return;
			}
		}

		$cookie = Vary_Cache::is_encryption_enabled() ? Vary_Cache::COOKIE_AUTH : Vary_Cache::COOKIE_SEGMENT;
		if ( ! isset( $_COOKIE[ $cookie ] ) ) {
			return;
		}

		Headers::expire_cookie( $cookie );
	}

	/**
	 * Resolve a reference for a read, enforcing the declared-set rule.
	 *
	 * @param int|string $reference Segment key or post ID.
	 * @param string     $function_name  Public function name for the notice.
	 */
	private function readable_segment( int|string $reference, string $function_name ): ?Segment {
		$segment = $this->segments->resolve( $reference );
		if ( null === $segment ) {
			_doing_it_wrong(
				esc_html( $function_name ),
				sprintf(
					/* translators: %s: segment reference */
					esc_html__( 'Unknown segment "%s".', 'content-gates' ),
					esc_html( (string) $reference )
				),
				'0.1.0'
			);
			return null;
		}

		if ( ! $segment->active ) {
			return null;
		}

		if ( ! $this->declared->allows_read( $segment->key ) ) {
			$message = in_array( $segment->key, $this->declared->keys(), true )
				/* translators: %s: segment key */
				? __( 'Segment "%s" was read before the declared set was final. Read it on template_redirect or later.', 'content-gates' )
				/* translators: %s: segment key */
				: __( 'Segment "%s" was read but not declared for this request. Declare it on the `wp` action or through the content_gates_declared_segments filter.', 'content-gates' );
			_doing_it_wrong( esc_html( $function_name ), esc_html( sprintf( $message, $segment->key ) ), '0.1.0' );
			return null;
		}

		return $segment;
	}

	/**
	 * The stored value for a segment, from the override or `Vary_Cache`.
	 *
	 * @param Segment $segment Segment.
	 */
	private function raw_value( Segment $segment ): ?string {
		if ( null !== $this->override ) {
			$value = $this->override[ $segment->group_name ] ?? '';
			return '' === $value ? null : $value;
		}

		return $this->cookie_value( $segment );
	}

	/**
	 * The value `Vary_Cache` holds for a segment, or null when none.
	 *
	 * Ignores the View As override: a write compares against the cookie the
	 * reader really sent.
	 *
	 * @param Segment $segment Segment.
	 */
	private function cookie_value( Segment $segment ): ?string {
		if ( ! $this->platform->is_available() ) {
			return null;
		}

		$groups = Vary_Cache::get_groups();
		$value  = $groups[ $segment->group_name ] ?? '';
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return $value;
	}
}
