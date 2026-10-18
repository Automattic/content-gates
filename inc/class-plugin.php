<?php
/**
 * Plugin bootstrap.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

/**
 * Wires plugin components to WordPress hooks.
 *
 * Holds no gating logic itself. Each component (provider registry, segment
 * layer, render layer, admin UI) is registered here so the boot order is
 * visible in one place.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Return the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks. Safe to call more than once; only the first call acts.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		require_once CONTENT_GATES_PATH . 'inc/functions.php';
		Admin::register();
		Editor::register();
		Protection::register();
		Response::register();
		add_action(
			'init',
			static function (): void {
				Segments::register();
				Membership::instance()->register();
			},
			5
		);
		add_action(
			'init',
			static function (): void {
				Membership::instance()->ready();
			},
			20
		);
		add_action(
			'switch_blog',
			static function (): void {
				Membership::reset();
				// Cross-site calls after parse cannot safely re-register platform groups.
				Membership::instance()->problem( 'blog_switched' );
			}
		);

		/**
		 * Fires after Content Gates has registered its hooks.
		 *
		 * Providers should hook here to register themselves.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'content_gates_loaded', $this );
	}

	/**
	 * Whether boot() has run.
	 */
	public function is_booted(): bool {
		return $this->booted;
	}
}
