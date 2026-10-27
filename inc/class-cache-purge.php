<?php
/**
 * Global cache purge on configuration changes.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates;

use Automattic\VIP\ContentGates\Segments\Segment;

/**
 * Requests a site-wide edge purge when segment configuration changes.
 *
 * Post saves purge their own URLs (confirmed by VIP, 2026-10-21). Segment
 * activation, deactivation, and meter reconfiguration are site-wide and
 * need every stored variant gone. Creation and rename need none: a new
 * key is in no cookie or stored set yet, and a rename changes no key.
 * Crawler sync reaches here through the same status-change path when it
 * deactivates a removed segment. On WPVIP the default listener calls the
 * cache manager's site purge; elsewhere the action is the extension point.
 *
 * One purge per request: a site purge covers every later change in the
 * same request, and one configuration change can arrive as several events
 * (a meter update writes two meta values).
 *
 * @since 0.1.0
 */
final class Cache_Purge {

	/**
	 * Whether a purge was requested this request.
	 *
	 * @var bool
	 */
	private bool $requested = false;

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'content_gates_segment_changed', array( $this, 'on_segment_changed' ), 10, 2 );
		add_action( 'content_gates_purge_all', array( $this, 'purge_site' ) );
	}

	/**
	 * Request a purge for changes that invalidate stored variants.
	 *
	 * @since 0.1.0
	 *
	 * @param Segment $segment Segment that changed.
	 * @param string  $change  Kind of change.
	 */
	public function on_segment_changed( Segment $segment, string $change ): void {
		if ( 'created' === $change ) {
			return;
		}

		$this->request( sprintf( 'segment %s %s', $segment->key, $change ) );
	}

	/**
	 * Fire the purge action.
	 *
	 * @param string $reason Human-readable reason.
	 */
	private function request( string $reason ): void {
		if ( $this->requested ) {
			return;
		}
		$this->requested = true;

		/**
		 * Fires when every stored variant must be purged.
		 *
		 * @since 0.1.0
		 *
		 * @param string $reason Why the purge was requested.
		 */
		do_action( 'content_gates_purge_all', $reason );
	}

	/**
	 * Forget this request's purge. For tests.
	 *
	 * @since 0.1.0
	 */
	public function reset(): void {
		$this->requested = false;
	}

	/**
	 * Default listener: purge the whole site on WPVIP.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Why the purge was requested.
	 */
	public function purge_site( string $reason ): void {
		unset( $reason );

		if ( ! class_exists( '\WPCOM_VIP_Cache_Manager' ) ) {
			return;
		}

		$manager = \WPCOM_VIP_Cache_Manager::instance();
		if ( is_object( $manager ) && method_exists( $manager, 'purge_site_cache' ) ) {
			$manager->purge_site_cache();
		}
	}
}
