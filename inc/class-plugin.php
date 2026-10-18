<?php
/**
 * Plugin bootstrap.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

use ContentGates\Membership\Membership;
use ContentGates\Rendering\Response_Finalizer;
use ContentGates\Segments\Segment_Registry;

/**
 * Wires plugin components to WordPress hooks.
 *
 * Holds no gating logic itself. Each component (segment registry, membership
 * layer, rendering guards, admin UI, REST) is constructed here so the boot
 * order is visible in one place. Components expose a `register()` method
 * that adds their hooks; nothing runs at construction time.
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
	 * Segment registry.
	 *
	 * @var Segment_Registry|null
	 */
	private ?Segment_Registry $segments = null;

	/**
	 * Membership layer.
	 *
	 * @var Membership|null
	 */
	private ?Membership $membership = null;

	/**
	 * Response finalizer.
	 *
	 * @var Response_Finalizer|null
	 */
	private ?Response_Finalizer $finalizer = null;

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
	 * Construct components and register hooks. Only the first call acts.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		// --- Segments (builder A) ---------------------------------------
		$this->segments = new Segment_Registry();
		$this->segments->register();

		$segment_admin = new Admin\Segment_Admin( $this->segments );
		$segment_admin->register();

		// --- Membership (builder B) -------------------------------------
		$adapter = class_exists( '\\Automattic\\VIP\\Cache\\Vary_Cache' )
			? new \ContentGates\Membership\Vary_Cache_Adapter()
			: new \ContentGates\Membership\Local_Cookie_Adapter();
		$adapter->register();

		$consulted        = new \ContentGates\Membership\Consulted_Set();
		$this->membership = new Membership( $adapter, $this->segments, $consulted );
		$this->membership->register();

		$this->finalizer = new Response_Finalizer( $this->membership );
		$this->finalizer->register();

		// --- Rendering and editor (builder D) ---------------------------
		$enforcer = new Rendering\Block_Enforcer( $this->membership, $this->segments );
		$enforcer->register();

		$excerpt_guard = new Rendering\Excerpt_Guard( new Rendering\Block_Stripper( $enforcer ) );
		$excerpt_guard->register();

		$crawler_markup = new Rendering\Crawler_Markup( $this->membership );
		$crawler_markup->register();

		$editor_assets = new Editor\Editor_Assets( $this->segments );
		$editor_assets->register();

		// --- REST (builder C) -------------------------------------------
		$membership_controller = new REST\Membership_Controller( $this->membership, $this->segments );
		$membership_controller->register();

		/**
		 * Fires after Content Gates has registered its hooks.
		 *
		 * Providers should hook here to register code-defined segments and
		 * their assignment handlers.
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

	/**
	 * Segment registry.
	 *
	 * @throws \LogicException When called before boot().
	 */
	public function segments(): Segment_Registry {
		if ( null === $this->segments ) {
			throw new \LogicException( 'Content Gates is not booted.' );
		}

		return $this->segments;
	}

	/**
	 * Membership layer.
	 *
	 * @throws \LogicException When called before boot().
	 */
	public function membership(): Membership {
		if ( null === $this->membership ) {
			throw new \LogicException( 'Content Gates is not booted.' );
		}

		return $this->membership;
	}

	/**
	 * Response finalizer.
	 *
	 * @throws \LogicException When called before boot().
	 */
	public function finalizer(): Response_Finalizer {
		if ( null === $this->finalizer ) {
			throw new \LogicException( 'Content Gates is not booted.' );
		}

		return $this->finalizer;
	}
}
