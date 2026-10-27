<?php
/**
 * Edge Worker version skew notice.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Admin;

use Automattic\VIP\ContentGates\Segments\Crawler_Config;

/**
 * Shows an admin notice when the Edge Worker and the plugin disagree on `config/crawlers.json`.
 *
 * The worker sends its configuration version in `X-WPVIP-Content-Gates-Edge`
 * on every origin request. Admin requests reach origin through the edge too,
 * so the value seen on an admin page is the worker's. The last seen value
 * is remembered in an option so the notice survives requests the worker did
 * not touch.
 *
 * @since 0.1.0
 */
final class Edge_Notice {

	public const OPTION = 'content_gates_edge_version_seen';

	/**
	 * Constructor.
	 *
	 * @param Crawler_Config $crawlers Crawler configuration.
	 */
	public function __construct( private Crawler_Config $crawlers ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'remember_edge_version' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Store the version the worker reported, when it changed.
	 *
	 * Only admin requests write: the notice is read there, and a front-end
	 * request must not reach the database on the strength of a request header.
	 *
	 * @since 0.1.0
	 */
	public function remember_edge_version(): void {
		if ( ! is_admin() ) {
			return;
		}

		$seen = $this->crawlers->edge_version();
		if ( null === $seen ) {
			return;
		}

		if ( (int) get_option( self::OPTION, -1 ) !== $seen ) {
			update_option( self::OPTION, $seen, false );
		}
	}

	/**
	 * Print the notice on a mismatch.
	 *
	 * @since 0.1.0
	 */
	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$seen = (int) get_option( self::OPTION, -1 );
		if ( -1 === $seen || $seen === $this->crawlers->version() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: plugin crawler config version, 2: edge worker crawler config version */
					__( 'Content Gates: the plugin ships crawler configuration version %1$d but the Edge Worker reports version %2$d. Crawler classification may not match the segments editors can assign until both are released with the same version.', 'content-gates' ),
					$this->crawlers->version(),
					$seen
				)
			)
		);
	}
}
