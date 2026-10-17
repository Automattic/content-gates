<?php
/**
 * Segment membership and consulted-set tracking.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

use Automattic\VIP\Cache\Vary_Cache;
use WP_Error;

/**
 * Wraps WPVIP Vary_Cache behind the public Content Gates API.
 */
final class Membership {

	public const CONTRACT_VERSION = '1';

	private const MEMBER_VALUE = '1';

	/**
	 * Consulted segment IDs for the current response.
	 *
	 * @var array<int,true>
	 */
	private static array $consulted = array();

	/**
	 * Register WordPress hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'load_local_adapter' ), 0 );
		add_action( 'init', array( self::class, 'register_segments' ), 1 );
		add_action( 'init', array( self::class, 'start_response_buffer' ), PHP_INT_MAX );
	}

	/**
	 * Load the local adapter when WPVIP Vary_Cache is unavailable.
	 */
	public static function load_local_adapter(): void {
		if ( ! class_exists( Vary_Cache::class ) && self::local_adapter_enabled() ) {
			Local_Vary_Cache::load();
		}
	}

	/**
	 * Register all persisted segment IDs as cache groups.
	 */
	public static function register_segments(): void {
		foreach ( Segment_Repository::get_all() as $segment ) {
			self::register_group( self::group_for_segment( (int) $segment->ID ) );
		}
	}

	/**
	 * Check current-reader membership and record the dependency.
	 *
	 * Inactive and invalid segment references fail closed.
	 *
	 * @param int $segment_id Segment post ID.
	 */
	public static function is_reader_in_segment( int $segment_id ): bool {
		self::$consulted[ $segment_id ] = true;

		if ( ! Segment_Repository::is_active( $segment_id ) ) {
			return false;
		}

		$group = self::group_for_segment( $segment_id );
		if ( class_exists( Vary_Cache::class ) ) {
			return Vary_Cache::is_user_in_group_segment( $group, self::MEMBER_VALUE );
		}

		if ( self::local_adapter_enabled() ) {
			return Local_Vary_Cache::is_user_in_group_segment( $group, self::MEMBER_VALUE );
		}

		return false;
	}

	/**
	 * Assign the current reader to an active segment.
	 *
	 * @param int $segment_id Segment post ID.
	 * @return true|WP_Error True on success, otherwise an API error.
	 */
	public static function assign_reader_to_segment( int $segment_id ) {
		if ( did_action( 'send_headers' ) || headers_sent() ) {
			return new WP_Error( 'content_gates_assignment_too_late', __( 'Membership must be assigned before headers are sent.', 'content-gates' ) );
		}

		if ( ! Segment_Repository::is_active( $segment_id ) ) {
			return new WP_Error( 'content_gates_inactive_segment', __( 'Membership cannot be assigned to an inactive or invalid segment.', 'content-gates' ) );
		}

		return self::set_group( self::group_for_segment( $segment_id ), self::MEMBER_VALUE );
	}

	/**
	 * Remove the current reader from a persisted segment.
	 *
	 * @param int $segment_id Segment post ID.
	 * @return true|WP_Error True on success, otherwise an API error.
	 */
	public static function remove_reader_from_segment( int $segment_id ) {
		if ( did_action( 'send_headers' ) || headers_sent() ) {
			return new WP_Error( 'content_gates_assignment_too_late', __( 'Membership must be removed before headers are sent.', 'content-gates' ) );
		}

		if ( null === Segment_Repository::get( $segment_id ) ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Segment does not exist.', 'content-gates' ) );
		}

		return self::set_group( self::group_for_segment( $segment_id ), '' );
	}

	/**
	 * Return the consulted segment IDs.
	 *
	 * @return int[] Consulted segment IDs.
	 */
	public static function get_consulted_segment_ids(): array {
		$ids = array_keys( self::$consulted );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Begin full-response buffering for post-render dependency reporting.
	 */
	public static function start_response_buffer(): void {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		ob_start( array( self::class, 'finalize_response' ) );
	}

	/**
	 * Finalize the consulted set after rendering while headers remain mutable.
	 *
	 * @param string $body Buffered response body.
	 * @return string Unchanged response body.
	 */
	public static function finalize_response( string $body ): string {
		$groups = array_map( array( self::class, 'group_for_segment' ), self::get_consulted_segment_ids() );

		/**
		 * Filters the cache groups reported after response rendering.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $groups Consulted cache-group identifiers.
		 */
		$groups = apply_filters( 'content_gate_consulted_segments', $groups ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public hook specified by the product API.
		$groups = array_values( array_unique( array_filter( array_map( 'sanitize_key', $groups ) ) ) );

		if ( array() !== $groups && ! headers_sent() ) {
			header( 'X-Content-Gates-Vary: ' . implode( ', ', $groups ) );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! headers_sent() ) {
			header( 'X-Content-Gates-Consulted: ' . implode( ', ', $groups ) );
		}

		/**
		 * Fires after the response's consulted segment set is frozen.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $groups Consulted cache-group identifiers.
		 */
		do_action( 'content_gate_finalize_variance', $groups ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public hook specified by the product API.

		return $body;
	}

	/**
	 * Reset request-scoped state for tests.
	 */
	public static function reset_request_state(): void {
		self::$consulted = array();
		Local_Vary_Cache::reset();
	}

	/**
	 * Convert a stable segment post ID into its cache-group identifier.
	 *
	 * @param int $segment_id Segment post ID.
	 */
	public static function group_for_segment( int $segment_id ): string {
		return 'cg-' . $segment_id;
	}

	/**
	 * Register one cache group with the active adapter.
	 *
	 * @param string $group Cache-group identifier.
	 */
	private static function register_group( string $group ): bool {
		if ( class_exists( Vary_Cache::class ) ) {
			return Vary_Cache::register_group( $group );
		}

		return self::local_adapter_enabled() && Local_Vary_Cache::register_group( $group );
	}

	/**
	 * Set one cache group through the active adapter.
	 *
	 * @param string $group Cache-group identifier.
	 * @param string $value Membership value.
	 * @return true|WP_Error True on success, otherwise an API error.
	 */
	private static function set_group( string $group, string $value ) {
		if ( class_exists( Vary_Cache::class ) ) {
			$result = Vary_Cache::set_group_for_user( $group, $value );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'content_gates_assignment_failed', $result->get_error_message(), array( 'cause' => $result->get_error_code() ) );
			}

			return true;
		}

		if ( self::local_adapter_enabled() ) {
			return Local_Vary_Cache::set_group_for_user( $group, $value );
		}

		return new WP_Error( 'content_gates_cache_api_unavailable', __( 'WPVIP Vary_Cache is unavailable.', 'content-gates' ) );
	}

	/**
	 * Whether the explicit local development adapter may run.
	 */
	private static function local_adapter_enabled(): bool {
		return 'local' === wp_get_environment_type() || ( defined( 'CONTENT_GATES_ENABLE_LOCAL_VARY_CACHE' ) && CONTENT_GATES_ENABLE_LOCAL_VARY_CACHE );
	}
}
