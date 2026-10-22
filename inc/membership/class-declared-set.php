<?php
/**
 * The declared set: which segments a response may read.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Membership;

use Automattic\VIP\ContentGates\Headers;
use Automattic\VIP\ContentGates\Segments\Segment;
use Automattic\VIP\ContentGates\Segments\Segment_Registry;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Builds the declared set for the current response and sends it as a header.
 *
 * The declared set is computed before any output, from content (the saved
 * assignments of the posts the response renders, stored in post meta at save
 * time) and from explicit declarations. The Edge Worker keys the cache on the
 * reader's values for exactly these segments. A read of any other segment
 * fails closed, so a render can never depend on a segment the key does not
 * cover.
 *
 * Two modes:
 *
 * - **Page** (anything served through `WP::main()`, including feeds and
 *   embeds): the set is built once, at the end of the `wp` action, from the
 *   main query. Reads before that point fail closed.
 * - **REST**: the set grows while the response is built. A post's stored set
 *   is merged just before its content renders, and the header is sent in
 *   `rest_pre_echo_response`, the last filter before the JSON is echoed, so
 *   `_embed`ded posts are covered. Reads of a segment not yet merged fail
 *   closed.
 *
 * Core fires `send_headers` before `wp`, so the plugin finalizes on `wp`
 * (priority `PHP_INT_MAX`) rather than on `send_headers`: declarations on
 * the `wp` action, as the public API documents, would otherwise be too late.
 * Both hooks run before any output and before the template loads.
 *
 * @since 0.1.0
 */
final class Declared_Set {

	public const HEADER_DECLARED = 'X-WPVIP-Content-Gates-Declared';
	public const HEADER_METER    = 'X-WPVIP-Content-Gates-Meter';

	/**
	 * Post meta key holding a post's declared keys (array of strings).
	 */
	public const META_KEY = '_content_gates_declared';

	/**
	 * Segment keys in the set so far, as key => true.
	 *
	 * @var array<string, true>
	 */
	private array $keys = array();

	/**
	 * Whether the set is closed: header sent, declarations refused.
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Whether this is a REST request building the set incrementally.
	 *
	 * @var bool|null Null until first checked.
	 */
	private ?bool $incremental = null;

	/**
	 * Post ID a metered view on this response counts against, 0 for none.
	 *
	 * @var int
	 */
	private int $counted_post_id = 0;

	/**
	 * Constructor.
	 *
	 * @param Segment_Registry $segments Segment lookup.
	 */
	public function __construct( private Segment_Registry $segments ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp', array( $this, 'finalize_page' ), PHP_INT_MAX );
		add_filter( 'the_content', array( $this, 'merge_rendering_post' ), 1 );
		add_filter( 'rest_pre_serve_request', array( $this, 'finalize_rest_if_served' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_pre_echo_response', array( $this, 'finalize_rest' ), PHP_INT_MAX );
	}

	/**
	 * Declare a segment for this response.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $reference Segment key or post ID.
	 * @return true|WP_Error `content_gates_too_late`, `content_gates_invalid_segment`, or `content_gates_inactive_segment`.
	 */
	public function declare( int|string $reference ): true|WP_Error {
		if ( $this->closed ) {
			return new WP_Error(
				'content_gates_too_late',
				__( 'Segments must be declared before the declared set is sent. Use the `wp` action.', 'content-gates' )
			);
		}

		$segment = $this->segments->resolve( $reference );
		if ( null === $segment ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
		}
		if ( ! $segment->active ) {
			return new WP_Error( 'content_gates_inactive_segment', __( 'Inactive segments cannot be declared.', 'content-gates' ) );
		}

		$this->keys[ $segment->key ] = true;

		return true;
	}

	/**
	 * Merge a post's saved declared set into this response's set.
	 *
	 * No-op once the set is closed. Inactive and unknown keys are dropped.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function merge_post( int $post_id ): void {
		if ( $this->closed || $post_id <= 0 ) {
			return;
		}

		foreach ( self::stored_keys( $post_id ) as $key ) {
			$segment = $this->segments->resolve( $key );
			if ( null !== $segment && $segment->active ) {
				$this->keys[ $segment->key ] = true;
			}
		}
	}

	/**
	 * Whether a key may be read on this response.
	 *
	 * On the page path the set must be closed (header sent). On the REST path
	 * the set is incremental, so a merged key is readable at once.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Segment key.
	 */
	public function allows_read( string $key ): bool {
		if ( ! isset( $this->keys[ $key ] ) ) {
			return false;
		}

		return $this->closed || $this->is_incremental();
	}

	/**
	 * Whether the header has been sent and declarations are refused.
	 *
	 * @since 0.1.0
	 */
	public function is_closed(): bool {
		return $this->closed;
	}

	/**
	 * Declared segment keys, sorted.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function keys(): array {
		$keys = array_keys( $this->keys );
		sort( $keys, SORT_STRING );

		return $keys;
	}

	/**
	 * Declared group names, sorted. This is the header value.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function groups(): array {
		$groups = array();
		foreach ( array_keys( $this->keys ) as $key ) {
			$segment = $this->segments->resolve( $key );
			if ( null !== $segment ) {
				$groups[] = $segment->group_name;
			}
		}
		sort( $groups, SORT_STRING );

		return $groups;
	}

	/**
	 * Meter segments in the declared set.
	 *
	 * @since 0.1.0
	 *
	 * @return Segment[]
	 */
	public function meters(): array {
		$meters = array();
		foreach ( array_keys( $this->keys ) as $key ) {
			$segment = $this->segments->resolve( $key );
			if ( null !== $segment && $segment->is_meter() ) {
				$meters[] = $segment;
			}
		}

		return $meters;
	}

	/**
	 * Build and send the set for a page response. Action callback for `wp`.
	 *
	 * @since 0.1.0
	 */
	public function finalize_page(): void {
		if ( $this->closed ) {
			return;
		}

		global $wp_query;
		$query = $wp_query instanceof WP_Query ? $wp_query : new WP_Query();

		if ( $query->is_singular() ) {
			$queried = $query->get_queried_object();
			if ( $queried instanceof WP_Post ) {
				$this->merge_post( $queried->ID );
				$this->counted_post_id = $queried->ID;
			}
		} elseif ( $query->is_feed() ) {
			foreach ( is_array( $query->posts ) ? $query->posts : array() as $post ) {
				if ( $post instanceof WP_Post ) {
					$this->merge_post( $post->ID );
				}
			}
		}

		$this->apply_filter( $query );

		if ( $query->is_singular() && array() !== $this->keys ) {
			foreach ( $this->segments->crawler_keys() as $crawler_key ) {
				$this->keys[ $crawler_key ] = true;
			}
		}

		$this->close_and_send();
	}

	/**
	 * Merge the post whose content is about to render, on REST requests.
	 *
	 * Filter callback for `the_content` at priority 1, before `do_blocks()`
	 * at 9. On the page path the set is already closed, so this is a no-op.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content Post content.
	 * @return string Unchanged.
	 */
	public function merge_rendering_post( string $content ): string {
		if ( ! $this->closed && $this->is_incremental() ) {
			$post_id = get_the_ID();
			if ( is_int( $post_id ) ) {
				$this->merge_post( $post_id );
			}
		}

		return $content;
	}

	/**
	 * Finalize early when another handler serves the REST response itself.
	 *
	 * Filter callback for `rest_pre_serve_request`. When `$served` is true the
	 * echo filter never runs, so the header has to go now.
	 *
	 * @since 0.1.0
	 *
	 * @param bool  $served Whether the request was already served.
	 * @param mixed $result Response.
	 * @return bool Unchanged.
	 */
	public function finalize_rest_if_served( bool $served, mixed $result ): bool {
		unset( $result );

		if ( $served ) {
			$this->finalize_rest_now();
		}

		return $served;
	}

	/**
	 * Close the set and send the header before the JSON is echoed.
	 *
	 * Filter callback for `rest_pre_echo_response`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $result Response data.
	 * @return mixed Unchanged.
	 */
	public function finalize_rest( mixed $result ): mixed {
		$this->finalize_rest_now();

		return $result;
	}

	/**
	 * Read a post's stored declared keys.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function stored_keys( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_filter( $stored, 'is_string' ) );
	}

	/**
	 * Whether this is a REST request. Memoized.
	 */
	private function is_incremental(): bool {
		if ( null === $this->incremental ) {
			$this->incremental = ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() )
				|| ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		}

		return $this->incremental;
	}

	/**
	 * Finalize a REST response once.
	 */
	private function finalize_rest_now(): void {
		if ( $this->closed ) {
			return;
		}

		global $wp_query;
		$this->apply_filter( $wp_query instanceof WP_Query ? $wp_query : new WP_Query() );
		$this->close_and_send();

		/**
		 * Fires after the declared set was sent on a REST response.
		 *
		 * `Vary_Cache` never sees `send_headers` on a REST request, so the
		 * membership layer listens here to send `Vary` and any pending cookie.
		 *
		 * @since 0.1.0
		 */
		do_action( 'content_gates_rest_headers_sent' );
	}

	/**
	 * Run the public filter. Keys may be added but never removed.
	 *
	 * @param WP_Query $query Main query.
	 */
	private function apply_filter( WP_Query $query ): void {
		$before = $this->keys();

		/**
		 * Filters the segment keys declared for this response.
		 *
		 * Runs once per response, with the main query available. Keys from
		 * block assignments and earlier `declare_segment()` calls are restored
		 * after the filter: removing one would make its reads fail closed and
		 * withhold content with no visible cause. Unknown and inactive keys
		 * are dropped.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $segments Segment keys declared so far.
		 * @param WP_Query $query    The main query.
		 */
		$filtered = apply_filters( 'content_gates_declared_segments', $before, $query );
		if ( ! is_array( $filtered ) ) {
			$filtered = array();
		}

		foreach ( array_merge( $before, $filtered ) as $key ) {
			if ( ! is_string( $key ) && ! is_int( $key ) ) {
				continue;
			}
			$segment = $this->segments->resolve( $key );
			if ( null === $segment ) {
				_doing_it_wrong(
					'content_gates_declared_segments',
					sprintf(
						/* translators: %s: segment reference */
						esc_html__( 'Unknown segment "%s" was returned by the content_gates_declared_segments filter and was dropped.', 'content-gates' ),
						esc_html( (string) $key )
					),
					'0.1.0'
				);
				continue;
			}
			if ( ! $segment->active ) {
				continue;
			}
			$this->keys[ $segment->key ] = true;
		}
	}

	/**
	 * Close the set and send the declared and meter headers.
	 */
	private function close_and_send(): void {
		$this->closed = true;

		Headers::send( self::HEADER_DECLARED, implode( ', ', $this->groups() ) );

		if ( $this->counted_post_id > 0 ) {
			foreach ( $this->meters() as $meter ) {
				Headers::send(
					self::HEADER_METER,
					sprintf( '%s; id=%d; limit=%d; period=%d', $meter->group_name, $this->counted_post_id, $meter->limit, $meter->period ),
					false
				);
			}
		}

		/**
		 * Fires after the declared set is final and its header was sent.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $keys Declared segment keys, sorted.
		 */
		do_action( 'content_gates_declared', $this->keys() );
	}
}
