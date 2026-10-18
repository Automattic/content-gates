<?php
/**
 * Membership layer.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

use ContentGates\Segments\Segment;
use ContentGates\Segments\Segment_Source;
use WP_Error;

/**
 * The one read and write API for reader membership.
 *
 * Membership lives in the segmentation cookie and is reached only through
 * the `Cache_Adapter`. Every read of a known segment is recorded in the
 * `Consulted_Set`, including reads that return false and reads of inactive
 * segments, because the edge must key the response on every segment the
 * render looked at.
 *
 * Writes return `WP_Error` with these codes, checked in this order:
 *
 * - `content_gates_too_late`: after `send_headers` (or an explicit flush).
 * - `content_gates_invalid_segment`: the reference matches no segment.
 * - `content_gates_inactive_segment`: the segment is deactivated.
 * - `content_gates_invalid_value`: value outside `[A-Za-z0-9_-]+` or
 *   containing a cookie delimiter (`_--_`, `---__`).
 * - adapter codes: `content_gates_invalid_segment_key`,
 *   `content_gates_not_registered`, `content_gates_cache_error`.
 *
 * @since 0.1.0
 */
final class Membership {

	/**
	 * Serialized cookie size that triggers a debug warning, in bytes.
	 */
	public const COOKIE_WARN_BYTES = 3072;

	/**
	 * Whether our `send_headers` callback ran.
	 *
	 * @var bool
	 */
	private bool $headers_sent = false;

	/**
	 * Constructor.
	 *
	 * @param Cache_Adapter  $cache     Cookie storage.
	 * @param Segment_Source $segments  Segment lookup.
	 * @param Consulted_Set  $consulted Read recorder.
	 */
	public function __construct(
		private Cache_Adapter $cache,
		private Segment_Source $segments,
		private Consulted_Set $consulted
	) {}

	/**
	 * Add hooks.
	 *
	 * `init` 1 registers every known segment before the adapter parses the
	 * cookie at `init` 10. `send_headers` PHP_INT_MAX - 1 marks the request
	 * too late for writes, one step before `Vary_Cache` writes the cookie.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_groups' ), 1 );
		add_action( 'send_headers', array( $this, 'mark_headers_sent' ), PHP_INT_MAX - 1 );
	}

	/**
	 * Hook callback: register every known segment key as a cache group.
	 *
	 * @since 0.1.0
	 */
	public function register_groups(): void {
		foreach ( $this->segments->all() as $segment ) {
			$this->cache->register_group( $segment->key() );
		}
	}

	/**
	 * Hook callback: writes are no longer possible for this request.
	 *
	 * @since 0.1.0
	 */
	public function mark_headers_sent(): void {
		$this->headers_sent = true;
	}

	/**
	 * Whether writes are rejected with `content_gates_too_late`.
	 *
	 * @since 0.1.0
	 */
	public function is_too_late(): bool {
		return $this->headers_sent || $this->cache->did_send_headers();
	}

	/**
	 * Put the reader into a segment.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $ref   Segment post ID or key.
	 * @param string     $value Value to store. Default '1'.
	 * @return true|WP_Error See the class docblock for codes.
	 */
	public function assign( int|string $ref, string $value = '1' ): bool|WP_Error {
		$segment = $this->resolve_for_write( $ref );
		if ( is_wp_error( $segment ) ) {
			return $segment;
		}

		if ( ! Local_Cookie_Adapter::is_valid_token( $value ) ) {
			return new WP_Error(
				'content_gates_invalid_value',
				sprintf( 'Segment value "%s" may only contain A-Z, a-z, 0-9, "_" and "-".', $value )
			);
		}

		$this->ensure_registered( $segment->key() );

		return $this->cache->set_group( $segment->key(), $value );
	}

	/**
	 * Take the reader out of a segment.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $ref Segment post ID or key.
	 * @return true|WP_Error See the class docblock for codes.
	 */
	public function remove( int|string $ref ): bool|WP_Error {
		$segment = $this->resolve_for_write( $ref );
		if ( is_wp_error( $segment ) ) {
			return $segment;
		}

		$this->ensure_registered( $segment->key() );

		return $this->cache->set_group( $segment->key(), '' );
	}

	/**
	 * Whether the reader is in a segment. Records the read.
	 *
	 * Unknown references record nothing, return false and raise
	 * `_doing_it_wrong()` under WP_DEBUG. Inactive segments fail closed.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string  $ref   Segment post ID or key.
	 * @param string|null $value When given, require this exact value.
	 */
	public function is_in( int|string $ref, ?string $value = null ): bool {
		$segment = $this->resolve_for_read( $ref, 'is_reader_in_segment' );
		if ( null === $segment ) {
			return false;
		}

		$current = $this->read( $segment );
		if ( null === $current ) {
			return false;
		}

		return null === $value || $current === $value;
	}

	/**
	 * The reader's value for a segment, or null when not a member. Records
	 * the read.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $ref Segment post ID or key.
	 */
	public function value( int|string $ref ): ?string {
		$segment = $this->resolve_for_read( $ref, 'get_reader_segment_value' );
		if ( null === $segment ) {
			return null;
		}

		return $this->read( $segment );
	}

	/**
	 * Every group the reader holds a value for.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Key => value, non-empty values only.
	 */
	public function membership(): array {
		return array_filter(
			$this->cache->groups(),
			static fn( string $value ): bool => '' !== $value
		);
	}

	/**
	 * Membership restricted to the consulted set, with 'none' for absent.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]|null $keys Keys to project onto. Default: the consulted set.
	 * @return array<string, string> Key => value or 'none', in the given order.
	 */
	public function variant_key( ?array $keys = null ): array {
		$groups = $this->cache->groups();
		$result = array();

		foreach ( $keys ?? $this->consulted() as $key ) {
			$value          = $groups[ $key ] ?? '';
			$result[ $key ] = '' === $value ? 'none' : $value;
		}

		return $result;
	}

	/**
	 * Keys read so far, sorted.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function consulted(): array {
		return $this->consulted->keys();
	}

	/**
	 * The read recorder.
	 *
	 * @since 0.1.0
	 */
	public function consulted_set(): Consulted_Set {
		return $this->consulted;
	}

	/**
	 * The adapter in use.
	 *
	 * @since 0.1.0
	 */
	public function cache(): Cache_Adapter {
		return $this->cache;
	}

	/**
	 * Warn (WP_DEBUG only) when the serialized cookie nears the 4 KB limit.
	 *
	 * @since 0.1.0
	 */
	public function check_cookie_size(): void {
		if ( ! defined( 'WP_DEBUG' ) || true !== constant( 'WP_DEBUG' ) ) {
			return;
		}

		$size = strlen( Local_Cookie_Adapter::serialize( $this->cache->groups() ) );
		if ( $size <= self::COOKIE_WARN_BYTES ) {
			return;
		}

		// wp_trigger_error() calls trigger_error( ..., E_USER_WARNING ) only under WP_DEBUG.
		wp_trigger_error(
			__METHOD__,
			sprintf(
				'The segmentation cookie is %d bytes, above the %d byte warning threshold. Browsers drop cookies near 4096 bytes.',
				(int) $size,
				(int) self::COOKIE_WARN_BYTES
			),
			E_USER_WARNING
		);
	}

	/**
	 * Resolve a reference for a write, applying the write-order checks.
	 *
	 * @param int|string $ref Segment reference.
	 * @return Segment|WP_Error
	 */
	private function resolve_for_write( int|string $ref ): Segment|WP_Error {
		if ( $this->is_too_late() ) {
			return new WP_Error(
				'content_gates_too_late',
				'Membership cannot change after the send_headers hook. Assign before send_headers, or use the membership REST endpoint.'
			);
		}

		$segment = $this->segments->find( $ref );
		if ( null === $segment ) {
			return new WP_Error(
				'content_gates_invalid_segment',
				sprintf( 'Unknown segment "%s".', (string) $ref )
			);
		}

		if ( ! $segment->is_active() ) {
			return new WP_Error(
				'content_gates_inactive_segment',
				sprintf( 'Segment "%s" is inactive.', $segment->key() )
			);
		}

		return $segment;
	}

	/**
	 * Resolve a reference for a read. Unknown refs are a caller bug.
	 *
	 * @param int|string $ref      Segment reference.
	 * @param string     $caller Public function name for the notice.
	 */
	private function resolve_for_read( int|string $ref, string $caller ): ?Segment {
		$segment = $this->segments->find( $ref );
		if ( null !== $segment ) {
			return $segment;
		}

		if ( defined( 'WP_DEBUG' ) && true === constant( 'WP_DEBUG' ) ) {
			_doing_it_wrong(
				esc_html( $caller ),
				sprintf(
					/* translators: %s: segment reference */
					esc_html__( 'Unknown segment "%s". Reads of unknown segments always return false and are not recorded.', 'content-gates' ),
					esc_html( (string) $ref )
				),
				'0.1.0'
			);
		}

		return null;
	}

	/**
	 * Record the read and return the value, or null for none or inactive.
	 *
	 * @param Segment $segment Resolved segment.
	 */
	private function read( Segment $segment ): ?string {
		$this->consulted->record( $segment->key() );

		if ( ! $segment->is_active() ) {
			return null;
		}

		$value = $this->cache->groups()[ $segment->key() ] ?? '';

		return '' === $value ? null : $value;
	}

	/**
	 * Register a group that was created after `init` 1 ran.
	 *
	 * @param string $key Segment key.
	 */
	private function ensure_registered( string $key ): void {
		if ( ! array_key_exists( $key, $this->cache->groups() ) ) {
			$this->cache->register_group( $key );
		}
	}
}
