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
 * need every stored variant gone. On WPVIP the default listener calls the
 * cache manager's site purge; elsewhere the action is the extension point
 * (the local edge simulator fixture listens to it).
 *
 * @since 0.1.0
 */
final class Cache_Purge {

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'content_gates_segment_changed', array( $this, 'on_segment_changed' ), 10, 2 );
		add_action( 'content_gates_crawlers_synced', array( $this, 'on_crawlers_synced' ) );
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
	 * Crawler segment removal deactivates a segment: purge.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $changed Keys that changed.
	 */
	public function on_crawlers_synced( array $changed ): void {
		$this->request( 'crawler segments synced: ' . implode( ', ', $changed ) );
	}

	/**
	 * Fire the purge action.
	 *
	 * @param string $reason Human-readable reason.
	 */
	private function request( string $reason ): void {
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
