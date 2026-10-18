<?php
/**
 * Reader membership service.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

use ContentGates\Segments\Segment_Repository;
use WP_Error;
use WP_Hook;
use WP_Post;

/**
 * Provides the versioned membership contract and dependency tracking.
 */
final class Membership_Service {

	/**
	 * Public provider contract version.
	 */
	public const API_VERSION = '1.0';

	/**
	 * Shared service used by public facade functions.
	 *
	 * @var Membership_Service|null
	 */
	private static ?Membership_Service $instance = null;

	/**
	 * Segment persistence.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Cache personalization provider.
	 *
	 * @var Cache_Adapter
	 */
	private Cache_Adapter $cache;

	/**
	 * Per-response dependency state.
	 *
	 * @var Consulted_Segments
	 */
	private Consulted_Segments $consulted;

	/**
	 * Response-header availability check.
	 *
	 * @var callable(): bool
	 */
	private $headers_sent_check;

	/**
	 * Construct the membership service.
	 *
	 * @param Segment_Repository   $segments  Segment persistence.
	 * @param Cache_Adapter        $cache     Cache personalization provider.
	 * @param Consulted_Segments   $consulted          Per-response dependency state.
	 * @param callable():bool|null $headers_sent_check Optional header-state callback for tests.
	 */
	public function __construct(
		Segment_Repository $segments,
		Cache_Adapter $cache,
		Consulted_Segments $consulted,
		?callable $headers_sent_check = null
	) {
		$this->segments           = $segments;
		$this->cache              = $cache;
		$this->consulted          = $consulted;
		$this->headers_sent_check = $headers_sent_check ?? static fn (): bool => 'cli' !== PHP_SAPI && headers_sent();
	}

	/**
	 * Return the shared service.
	 */
	public static function instance(): Membership_Service {
		if ( null === self::$instance ) {
			self::$instance = new self(
				new Segment_Repository(),
				Cache_Adapter_Factory::create(),
				new Consulted_Segments()
			);
		}

		return self::$instance;
	}

	/**
	 * Configure the shared service during plugin boot or a test.
	 *
	 * @param Membership_Service $service Configured service.
	 */
	public static function set_instance( Membership_Service $service ): void {
		self::$instance = $service;
	}

	/**
	 * Register request hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_segments' ), 5 );
	}

	/**
	 * Register all saved segments before Vary_Cache parses cookies.
	 *
	 * Inactive restrictions still consult their stable key and return false.
	 */
	public function register_segments(): void {
		foreach ( $this->segments->get_all() as $segment ) {
			if ( ! $segment instanceof WP_Post ) {
				continue;
			}

			$result = $this->cache->register_group( $this->segments->group_key( $segment->ID ) );
			if ( is_wp_error( $result ) ) {
				/**
				 * Fires when an active segment cannot register with the cache provider.
				 *
				 * @since 0.1.0
				 *
				 * @param WP_Error $error      Provider error.
				 * @param int      $segment_id Segment post ID.
				 */
				do_action( 'content_gates_cache_registration_error', $result, $segment->ID );
			}
		}
	}

	/**
	 * Check whether the current reader is in an active segment.
	 *
	 * False membership still records a response dependency.
	 *
	 * @param int $segment_id Segment post ID.
	 */
	public function is_reader_in_segment( int $segment_id ): bool {
		if ( null === $this->segments->find( $segment_id ) ) {
			return false;
		}

		$group_key = $this->segments->group_key( $segment_id );
		$this->consulted->record( $group_key );
		if ( ! $this->segments->is_active( $segment_id ) ) {
			return false;
		}

		return $this->cache->is_reader_in_group( $group_key );
	}

	/**
	 * Assign the current reader to an active segment.
	 *
	 * @param int $segment_id Segment post ID.
	 * @return true|WP_Error
	 */
	public function assign_reader_to_segment( int $segment_id ) {
		$segment = $this->segments->find( $segment_id );
		if ( null === $segment ) {
			return $this->invalid_segment_error();
		}

		if ( ! $this->segments->is_active( $segment_id ) ) {
			return new WP_Error(
				'content_gates_inactive_segment',
				__( 'Reader membership cannot be assigned to an inactive segment.', 'content-gates' )
			);
		}

		return $this->change_membership( $segment_id, true );
	}

	/**
	 * Remove the current reader from a segment.
	 *
	 * Removal remains available for inactive segments so stale membership can
	 * be cleared.
	 *
	 * @param int $segment_id Segment post ID.
	 * @return true|WP_Error
	 */
	public function remove_reader_from_segment( int $segment_id ) {
		if ( null === $this->segments->find( $segment_id ) ) {
			return $this->invalid_segment_error();
		}

		return $this->change_membership( $segment_id, false );
	}

	/**
	 * Return request dependency state for response finalization.
	 */
	public function consulted_segments(): Consulted_Segments {
		return $this->consulted;
	}

	/**
	 * Send a queued provider cookie before an early response path exits.
	 *
	 * @return true|WP_Error
	 */
	public function flush_membership_headers() {
		return $this->cache->flush_membership_headers();
	}

	/**
	 * Change membership through the configured cache provider.
	 *
	 * @param int  $segment_id Segment post ID.
	 * @param bool $member     Whether the reader is a member.
	 * @return true|WP_Error
	 */
	private function change_membership( int $segment_id, bool $member ) {
		$headers_sent_check = $this->headers_sent_check;
		if ( did_action( 'send_headers' ) > 0 || $headers_sent_check() ) {
			return new WP_Error(
				'content_gates_assignment_too_late',
				__( 'Reader membership cannot change after headers are sent.', 'content-gates' )
			);
		}

		if ( $this->is_before_membership_window() ) {
			return new WP_Error(
				'content_gates_assignment_too_early',
				__( 'Reader membership cannot change before cache cookies are parsed.', 'content-gates' )
			);
		}

		$group_key  = $this->segments->group_key( $segment_id );
		$registered = $this->cache->register_group( $group_key );
		if ( is_wp_error( $registered ) ) {
			return $registered;
		}

		$result = $this->cache->set_reader_membership( $group_key, $member );
		if ( is_wp_error( $result ) && 'did_send_headers' === $result->get_error_code() ) {
			return new WP_Error(
				'content_gates_assignment_too_late',
				__( 'Reader membership cannot change after headers are sent.', 'content-gates' )
			);
		}

		return $result;
	}

	/**
	 * Check that Vary_Cache had an opportunity to parse existing membership.
	 *
	 * Calls during init are valid from priority 10 because the platform MU
	 * plugin registers its priority-10 parser before normal plugins load.
	 */
	private function is_before_membership_window(): bool {
		if ( 0 === did_action( 'init' ) ) {
			return true;
		}

		if ( ! doing_action( 'init' ) ) {
			return false;
		}

		global $wp_filter;
		$init_hook = $wp_filter['init'] ?? null;
		if ( ! $init_hook instanceof WP_Hook ) {
			return true;
		}

		$current_priority = $init_hook->current_priority();
		return null === $current_priority || 10 > $current_priority;
	}

	/**
	 * Build the invalid segment error.
	 */
	private function invalid_segment_error(): WP_Error {
		return new WP_Error(
			'content_gates_invalid_segment',
			__( 'The requested segment does not exist.', 'content-gates' )
		);
	}
}
